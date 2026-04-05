<?php
declare(strict_types=1);

namespace Xentral\Modules\RepairIntegration\Api;

use Xentral\Components\Database\Database;
use Xentral\Modules\RepairIntegration\Exception\AuthenticationException;
use Xentral\Modules\RepairIntegration\Exception\ForbiddenException;
use Xentral\Modules\RepairIntegration\Exception\ValidationException;
use Xentral\Modules\RepairIntegration\Gateway\RepairDetailsGateway;
use Xentral\Modules\RepairIntegration\Service\RepairConfigService;

final class RepairApiController
{
    private const MAX_PAYLOAD_SIZE = 65536; // @php83: add type int
    private const MAX_REQUESTS_PER_MINUTE = 60; // @php83: add type int

    public function __construct(
        private readonly Database $db,
        private readonly RepairApiAuth $auth,
        private readonly RepairConfigService $configService,
        private readonly RepairDetailsGateway $detailsGateway,
    ) {}

    public function handlePushDetails(): void
    {
        try {
            $this->validateMethod('POST');
            $this->validateContentType();
            $this->checkRateLimit();

            $rawBody = $this->readBody();
            $this->authenticate($rawBody);

            if (!json_validate($rawBody)) {
                throw new ValidationException('INVALID_JSON');
            }
            $data = json_decode($rawBody, true);
            $this->validatePushDetailsSchema($data);

            $this->processPushDetails($data);
            $this->respond(200, ['success' => true]);
        } catch (AuthenticationException $e) {
            $this->logInbound(null, $rawBody ?? '', false, $e->getMessage());
            $this->respond(401, ['success' => false, 'error' => $e->getMessage()]);
        } catch (ValidationException $e) {
            $this->respond(400, ['success' => false, 'error' => $e->getMessage()]);
        } catch (ForbiddenException $e) {
            $this->respond(403, ['success' => false, 'error' => $e->getMessage()]);
        } catch (\Exception $e) {
            $this->respond(500, ['success' => false, 'error' => 'INTERNAL_ERROR']);
        }
    }

    private function validateMethod(string $expected): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== $expected) {
            throw new ValidationException('METHOD_NOT_ALLOWED');
        }
    }

    private function validateContentType(): void
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (strpos($contentType, 'application/json') === false) {
            throw new ValidationException('UNSUPPORTED_MEDIA_TYPE');
        }
    }

    private function readBody(): string
    {
        $body = file_get_contents('php://input');
        if ($body === false || strlen($body) > self::MAX_PAYLOAD_SIZE) {
            throw new ValidationException('PAYLOAD_TOO_LARGE');
        }
        return $body;
    }

    private function authenticate(string $rawBody): void
    {
        $signature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
        $timestamp = $_SERVER['HTTP_X_TIMESTAMP'] ?? '';
        $secret = $this->configService->getInboundSharedSecret();

        $this->auth->validateRequest($rawBody, $signature, $timestamp, $secret);
    }

    private function checkRateLimit(): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $key = md5($ip);
        $window = date('YmdHi');

        $count = (int)$this->db->fetchValue(
            'SELECT `request_count` FROM `repair_api_ratelimit`
             WHERE `identifier_hash` = :key AND `window_key` = :window',
            ['key' => $key, 'window' => $window]
        );

        if ($count >= self::MAX_REQUESTS_PER_MINUTE) {
            throw new ForbiddenException('RATE_LIMIT_EXCEEDED');
        }

        $this->db->perform(
            'INSERT INTO `repair_api_ratelimit` (`identifier_hash`, `window_key`, `request_count`)
             VALUES (:key, :window, 1)
             ON DUPLICATE KEY UPDATE `request_count` = `request_count` + 1',
            ['key' => $key, 'window' => $window]
        );
    }

    private function validatePushDetailsSchema(array $data): void
    {
        $required = ['request_number', 'service_type'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new ValidationException("Missing required field: {$field}");
            }
        }

        $validServiceTypes = ['reparatur', 'wartung', 'reverse_engineering', 'individualisierung'];
        if (!in_array($data['service_type'], $validServiceTypes, true)) {
            throw new ValidationException('Invalid service_type');
        }

        if (strlen($data['request_number']) > 20) {
            throw new ValidationException('request_number too long');
        }
    }

    private function processPushDetails(array $data): void
    {
        $ticketSchluessel = $data['request_number'];

        // Find or wait for ticket (may not exist yet if email hasn't been imported)
        $ticket = $this->db->fetchRow(
            'SELECT `id`, `schluessel` FROM `ticket` WHERE `schluessel` = :key',
            ['key' => $ticketSchluessel]
        );

        if (!$ticket) {
            // Ticket not found — could arrive later via email import
            $this->logInbound($ticketSchluessel, json_encode($data), false, 'TICKET_NOT_FOUND');
            throw new ValidationException('TICKET_NOT_FOUND');
        }

        $existing = $this->detailsGateway->getByTicketId((int)$ticket['id']);
        if ($existing !== null) {
            // Update existing details
            $this->detailsGateway->update((int)$existing['id'], $this->mapInboundData($data));
        } else {
            // Create new details
            $details = array_merge(
                ['ticket_id' => (int)$ticket['id'], 'ticket_schluessel' => $ticket['schluessel']],
                $this->mapInboundData($data)
            );
            $this->detailsGateway->create($details);
        }

        $this->logInbound($ticketSchluessel, json_encode($data), true);
    }

    private function mapInboundData(array $data): array
    {
        $mapped = [
            'wp_request_number' => $data['request_number'] ?? null,
            'service_type' => $data['service_type'] ?? null,
            'service_delivery_type' => $data['service_delivery_type'] ?? 'einsendung',
        ];

        if (isset($data['customer']) && is_array($data['customer'])) {
            $c = $data['customer'];
            $mapped['customer_type'] = isset($c['company']) && $c['company'] !== null ? 'business' : 'private';
            $mapped['company_name'] = $c['company'] ?? null;
            $mapped['vat_id'] = $c['vat_id'] ?? null;
        }

        if (isset($data['device']) && is_array($data['device'])) {
            $d = $data['device'];
            $mapped['manufacturer'] = $d['manufacturer'] ?? null;
            $mapped['model'] = $d['model'] ?? null;
            $mapped['serial_number'] = $d['serial_number'] ?? null;
            $mapped['mods_present'] = !empty($d['mods_present']) ? 1 : 0;
            $mapped['mods_text'] = $d['mods_text'] ?? null;
        }

        if (isset($data['service_details']) && is_array($data['service_details'])) {
            $s = $data['service_details'];
            $fields = [
                'issue_category', 'issue_description', 'warranty_status',
                'cost_limit', 'wartung_paket', 'wartung_notes',
                'has_original_part', 'has_templates', 're_tolerance', 're_output_format',
                'has_3d_file', 'material_preference', 'color_preference',
                'functional_requirements', 'travel_distance_km', 'travel_fee',
            ];
            foreach ($fields as $field) {
                if (isset($s[$field])) {
                    $mapped[$field] = (string)$s[$field];
                }
            }
            $mapped['is_express'] = !empty($s['is_express']) ? 1 : 0;
            if (isset($s['express_price'])) {
                $mapped['express_price'] = (string)$s['express_price'];
            }
        }

        return array_filter($mapped, static fn($v): bool => $v !== null);
    }

    private function logInbound(?string $ticketSchluessel, string $payload, bool $success, string $error = ''): void
    {
        $this->db->perform(
            "INSERT INTO `repair_sync_log`
             (`direction`, `ticket_schluessel`, `action`, `payload_sent`, `success`, `error_message`, `ip_address`)
             VALUES ('inbound', :key, 'push_details', :payload, :success, :error, :ip)",
            [
                'key' => $ticketSchluessel,
                'payload' => substr($payload, 0, 65000),
                'success' => $success ? 1 : 0,
                'error' => $error !== '' ? $error : null,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ]
        );
    }

    private function respond(int $httpCode, array $body): void
    {
        http_response_code($httpCode);
        header('Content-Type: application/json');
        echo json_encode($body);
    }
}
