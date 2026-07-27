<?php
declare(strict_types=1);

namespace Xentral\Modules\RepairIntegration\Api;

use Xentral\Components\Database\Database;
use Xentral\Modules\RepairIntegration\Enum\ServiceType;
use Xentral\Modules\RepairIntegration\Exception\AuthenticationException;
use Xentral\Modules\RepairIntegration\Exception\ForbiddenException;
use Xentral\Modules\RepairIntegration\Exception\ValidationException;
use Xentral\Modules\RepairIntegration\Gateway\RepairDetailsGateway;
use Xentral\Modules\RepairIntegration\Gateway\RepairStatusConfigGateway;
use Xentral\Modules\RepairIntegration\Service\RepairConfigService;

final class RepairApiController
{
    private const MAX_PAYLOAD_SIZE = 65536; // @php83: add type int
    private const MAX_REQUESTS_PER_MINUTE = 60; // @php83: add type int
    private const MAX_STATUS_LENGTH = 30; // @php83: add type int
    private const DEFAULT_STATUS = 'neu'; // @php83: add type string

    public function __construct(
        private readonly Database $db,
        private readonly RepairApiAuth $auth,
        private readonly RepairConfigService $configService,
        private readonly RepairDetailsGateway $detailsGateway,
        private readonly RepairStatusConfigGateway $statusConfigGateway,
    ) {}

    public function handlePushDetails(): void
    {
        try {
            $this->validateMethod('POST');
            $this->validateContentType();
            $this->checkRateLimit();

            $rawBody = $this->readBody();
            $this->authenticate($rawBody);

            $data = json_decode($rawBody, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new ValidationException('INVALID_JSON');
            }
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
        } catch (\Throwable $e) {
            error_log(
                'RepairIntegration push failed: ' . get_class($e) . ': ' . $e->getMessage()
                . ' @ ' . $e->getFile() . ':' . $e->getLine()
            );
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

        // Support both HMAC-SHA256 (preferred) and Bearer token (WP plugin compat)
        if ($signature !== '' && $timestamp !== '') {
            // HMAC authentication
            $this->auth->validateRequest($rawBody, $signature, $timestamp, $secret);
        } else {
            // Bearer token fallback
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            if (strpos($authHeader, 'Bearer ') !== 0) {
                throw new AuthenticationException('MISSING_AUTH');
            }
            $token = substr($authHeader, 7);
            if (!hash_equals($secret, $token)) {
                throw new AuthenticationException('INVALID_BEARER_TOKEN');
            }
        }
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

        // `status` wird hier bewusst NICHT validiert. Das Feld ist optional und
        // rein beratend: normalizeWpStatus() liefert fuer Nicht-Strings, leere,
        // ueberlange oder anders geformte Werte null, die Ticket-Anlage faellt
        // dann auf DEFAULT_STATUS zurueck. Ein kaputtes Beiwerk-Feld darf die
        // Anlage des Tickets nicht mit 400 scheitern lassen.
    }

    private function processPushDetails(array $data): void
    {
        $ticketSchluessel = $data['request_number'];

        // Find existing ticket by schluessel
        $ticket = $this->db->fetchRow(
            'SELECT `id`, `schluessel` FROM `ticket` WHERE `schluessel` = :key',
            ['key' => $ticketSchluessel]
        );

        if (!$ticket) {
            // No ticket found — create one from the WP payload.
            // Der WP-Status wird nur hier ausgewertet: nach der Anlage besitzt
            // OpenXE den Workflow, bestehende Tickets werden nie ueberschrieben.
            $wpStatus = self::normalizeWpStatus($data['status'] ?? null);
            $mappedStatus = $this->resolveOpenXeStatus($wpStatus, (string)$data['service_type']);
            $ticket = $this->createTicketFromPayload($data, $mappedStatus ?? self::DEFAULT_STATUS);
            $this->createInitialTicketMessage($data, $ticket['schluessel']);

            $note = 'TICKET_CREATED';
            if ($wpStatus !== null && $mappedStatus === null) {
                $note .= sprintf(
                    ' (WP-Status "%s" ohne Mapping, Fallback "%s")',
                    $wpStatus,
                    self::DEFAULT_STATUS
                );
            }
            $this->logInbound($ticketSchluessel, (string)json_encode($data), true, $note);
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

        $this->logInbound($ticketSchluessel, (string)json_encode($data), true);
    }

    /**
     * Normalisiert den optionalen WP-Status aus dem Payload.
     *
     * Reine Funktion ohne DB-Zugriff (bewusst statisch, damit sie ohne
     * Container getestet werden kann). Liefert null, wenn der Wert nicht als
     * Status-Slug verwertbar ist — der Aufrufer faellt dann auf DEFAULT_STATUS
     * zurueck, ein unbekannter Wert ist kein Request-Fehler.
     *
     * @param mixed $raw Rohwert aus dem JSON-Payload
     */
    public static function normalizeWpStatus(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }

        $value = strtolower(trim($raw));
        if ($value === '' || strlen($value) > self::MAX_STATUS_LENGTH) {
            return null;
        }
        if (preg_match('/^[a-z0-9_]+$/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    /**
     * Loest einen WP-Status-Slug ueber die Kategorie des Service-Typs in einen
     * OpenXE-Status auf. Null = kein Mapping vorhanden.
     */
    private function resolveOpenXeStatus(?string $wpStatus, string $serviceType): ?string
    {
        if ($wpStatus === null) {
            return null;
        }

        $type = ServiceType::tryFrom($serviceType);
        if ($type === null) {
            return null;
        }

        $row = $this->statusConfigGateway->getByWpMapping($wpStatus, $type->statusCategory());
        if ($row === null || empty($row['slug'])) {
            return null;
        }

        return (string)$row['slug'];
    }

    /**
     * Creates a new ticket from the WP API push payload.
     *
     * @param array<string, mixed> $data Validated payload from WP
     * @param string $status OpenXE-Status-Slug (aufgeloest aus dem WP-Status)
     * @return array{id: int, schluessel: string} The created ticket row
     */
    private function createTicketFromPayload(array $data, string $status = self::DEFAULT_STATUS): array
    {
        $ticketSchluessel = $data['request_number'];
        $serviceType = $data['service_type'];
        $manufacturer = $data['device']['manufacturer'] ?? '';
        $model = $data['device']['model'] ?? '';
        $betreff = $this->buildSubjectLine($serviceType, $ticketSchluessel, $manufacturer, $model);

        $customerName = $data['customer']['name'] ?? '';
        $customerEmail = $data['customer']['email'] ?? '';
        $verfasser = $customerName !== '' && $customerEmail !== ''
            ? "{$customerName} <{$customerEmail}>"
            : ($customerName !== '' ? $customerName : $customerEmail);

        $companyName = (string)($data['customer']['company'] ?? '');
        $notiz = "Automatisch erstellt via WP API Push ({$serviceType})";
        if ($companyName !== '') {
            $notiz .= " | Firma: {$companyName}";
        }

        $this->db->perform(
            "INSERT INTO `ticket` (
                `schluessel`, `zeit`, `projekt`, `quelle`, `status`, `kunde`,
                `mailadresse`, `prio`, `betreff`, `firma`, `notiz`
            ) VALUES (
                :schluessel, NOW(), 0, :quelle, :status, :kunde,
                :mailadresse, :prio, :betreff, :firma, :notiz
            )",
            [
                'schluessel' => $ticketSchluessel,
                'quelle' => 'api',
                'status' => $status,
                'kunde' => $verfasser,
                'mailadresse' => $customerEmail,
                'prio' => 3,
                'betreff' => $betreff,
                'firma' => 1,
                'notiz' => $notiz,
            ]
        );
        $ticketId = (int)$this->db->lastInsertId();

        return ['id' => $ticketId, 'schluessel' => $ticketSchluessel];
    }

    /**
     * Builds a subject line with the appropriate service-type tag prefix.
     */
    private function buildSubjectLine(
        string $serviceType,
        string $requestNumber,
        string $manufacturer,
        string $model,
    ): string {
        $tagMap = [
            'reparatur' => '[REP] Reparaturanfrage',
            'wartung' => '[WRT] Wartungsanfrage',
            'reverse_engineering' => '[REV] RE-Anfrage',
            'individualisierung' => '[IND] Individualisierung',
        ];

        $prefix = $tagMap[$serviceType] ?? "[{$serviceType}]";
        $devicePart = trim("{$manufacturer} {$model}");
        $subject = "{$prefix} Ticket #{$requestNumber}";

        if ($devicePart !== '') {
            $subject .= " - {$devicePart}";
        }

        return $subject;
    }

    /**
     * Creates the first ticket_nachricht entry with the issue description.
     *
     * @param array<string, mixed> $data Validated payload from WP
     * @param string $ticketSchluessel The ticket schluessel (NOT ticket.id)
     */
    private function createInitialTicketMessage(array $data, string $ticketSchluessel): void
    {
        $customerName = $data['customer']['name'] ?? '';
        $customerEmail = $data['customer']['email'] ?? '';
        $verfasser = $customerName !== '' && $customerEmail !== ''
            ? "{$customerName} <{$customerEmail}>"
            : ($customerName !== '' ? $customerName : $customerEmail);

        $issueDescription = $data['service_details']['issue_description'] ?? '';
        if ($issueDescription === '') {
            $issueDescription = '(Keine Fehlerbeschreibung uebermittelt)';
        }

        $serviceType = $data['service_type'];
        $manufacturer = $data['device']['manufacturer'] ?? '';
        $model = $data['device']['model'] ?? '';
        $betreff = $this->buildSubjectLine($serviceType, $ticketSchluessel, $manufacturer, $model);

        $this->db->perform(
            "INSERT INTO `ticket_nachricht` (
                `ticket`, `zeit`, `text`, `betreff`, `medium`,
                `verfasser`, `mail`, `status`
            ) VALUES (
                :ticket, NOW(), :text, :betreff, :medium,
                :verfasser, :mail, :status
            )",
            [
                'ticket' => $ticketSchluessel,
                'text' => $issueDescription,
                'betreff' => $betreff,
                'medium' => 'api',
                'verfasser' => $verfasser,
                'mail' => $customerEmail,
                'status' => 'neu',
            ]
        );

        // Update ticket message count
        $this->db->perform(
            "UPDATE `ticket` AS t
             INNER JOIN (
                 SELECT COUNT(`id`) AS co, `ticket`
                 FROM `ticket_nachricht`
                 GROUP BY `ticket`
             ) AS tn ON t.`schluessel` = tn.`ticket`
             SET t.`nachrichten_anz` = tn.co
             WHERE t.`schluessel` = :schluessel",
            ['schluessel' => $ticketSchluessel]
        );
    }

    private function mapInboundData(array $data): array
    {
        $mapped = [
            'wp_request_number' => $data['request_number'] ?? null,
            'service_type' => $data['service_type'] ?? null,
            // Feldnamen-Kompatibilitaet: das WP-Plugin sendet je nach Version
            // `service_delivery_type` oder das kuerzere `service_delivery`.
            'service_delivery_type' => $data['service_delivery_type'] ?? $data['service_delivery'] ?? 'einsendung',
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
            // Feldnamen-Kompatibilitaet: `serial_number` (aktuell) bzw. `serial` (aeltere Plugin-Version).
            $mapped['serial_number'] = $d['serial_number'] ?? $d['serial'] ?? null;
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
