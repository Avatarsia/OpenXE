<?php
declare(strict_types=1);

namespace Xentral\Modules\RepairIntegration\Service;

use Xentral\Components\Database\Database;
use Xentral\Modules\RepairIntegration\Exception\SyncFailedException;
use Xentral\Modules\RepairIntegration\Gateway\RepairDetailsGateway;
use Xentral\Modules\RepairIntegration\Gateway\RepairStatusConfigGateway;
use Xentral\Modules\RepairIntegration\Gateway\RepairSyncQueueGateway;

final class RepairSyncService
{
    /** @var list<int> Retry delays in seconds */
    private const RETRY_DELAYS = [120, 600, 1800, 7200, 28800]; // @php83: add type array

    public function __construct(
        private readonly Database $db,
        private readonly RepairSyncQueueGateway $syncQueueGateway,
        private readonly RepairStatusConfigGateway $statusConfigGateway,
        private readonly RepairDetailsGateway $detailsGateway,
        private readonly RepairConfigService $configService,
    ) {}

    public function checkAndQueueStatusChange(int $ticketId): void
    {
        if (!$this->configService->isEnabled()) {
            return;
        }

        $details = $this->detailsGateway->getByTicketId($ticketId);
        if ($details === null || empty($details['wp_request_number'])) {
            return;
        }

        $ticket = $this->db->fetchRow(
            'SELECT `status` FROM `ticket` WHERE `id` = :id',
            ['id' => $ticketId]
        );
        if (!$ticket) {
            return;
        }

        $wpStatus = $this->statusConfigGateway->getWpMapping($ticket['status']);
        if ($wpStatus === null) {
            return;
        }

        $payload = json_encode([
            'request_number' => $details['wp_request_number'],
            'status' => $wpStatus,
        ], JSON_THROW_ON_ERROR);

        $targetUrl = $this->configService->getWpApiUrl() . '/wp-json/p3d/v1/requests/status';

        $this->syncQueueGateway->enqueue(
            $ticketId,
            $details['ticket_schluessel'],
            'status_change',
            $payload,
            $targetUrl,
        );
    }

    public function processQueue(): int
    {
        $entries = $this->syncQueueGateway->getPendingEntries(50);
        $processed = 0;

        foreach ($entries as $entry) {
            try {
                $this->syncQueueGateway->markProcessing($entry['id']);
            } catch (\RuntimeException) {
                continue;
            }

            try {
                $this->pushToWordPress($entry);
                $this->syncQueueGateway->markCompleted($entry['id']);
                $this->logSync('outbound', $entry, true);
                $processed++;
            } catch (SyncFailedException $e) {
                $retryCount = (int)$entry['retry_count'] + 1;
                $maxRetries = (int)$entry['max_retries'];

                if ($retryCount >= $maxRetries) {
                    $this->syncQueueGateway->markPermanentlyFailed(
                        $entry['id'],
                        $e->getMessage(),
                    );
                } else {
                    $delayIndex = min($retryCount - 1, count(self::RETRY_DELAYS) - 1);
                    $delay = self::RETRY_DELAYS[$delayIndex];
                    $nextRetry = date('Y-m-d H:i:s', time() + $delay);

                    $this->syncQueueGateway->markFailed(
                        $entry['id'],
                        $retryCount,
                        $nextRetry,
                        $e->getMessage(),
                        $e->httpCode,
                    );
                }
                $this->logSync('outbound', $entry, false, $e->getMessage());
            }
        }

        return $processed;
    }

    public function getQueueStatus(): array
    {
        return $this->syncQueueGateway->getQueueStats();
    }

    /**
     * Prueft die Erreichbarkeit der WordPress-REST-API ueber den Ping-Endpoint.
     *
     * Idempotent: es werden keine Daten uebertragen, der Aufruf kann beliebig
     * oft wiederholt werden. Fehler werden bewusst nicht geworfen, sondern als
     * Rohergebnis zurueckgegeben, damit die UI HTTP-Code und Antwort-Body
     * unveraendert anzeigen kann.
     *
     * @return array{http_code: int|null, body: string, error: string|null}
     */
    public function testConnection(): array
    {
        $baseUrl = $this->configService->getWpApiUrl();
        if ($baseUrl === '') {
            return ['http_code' => null, 'body' => '', 'error' => 'WP API-URL ist nicht konfiguriert'];
        }

        $apiKey = $this->configService->getWpApiKey();
        if ($apiKey === '') {
            return ['http_code' => null, 'body' => '', 'error' => 'WP API-Key ist nicht konfiguriert'];
        }

        $payload = json_encode(
            ['source' => 'openxe', 'action' => 'connection_test'],
            JSON_THROW_ON_ERROR
        );

        $result = $this->request($baseUrl . '/wp-json/p3d/v1/ping', $payload, $apiKey, 10);

        $error = '';
        if ($result['http_code'] !== 200) {
            $error = $result['error'] ?? sprintf('WP API returned HTTP %d', (int)$result['http_code']);
        }
        $this->logSync(
            'outbound',
            ['ticket_schluessel' => null, 'action' => 'connection_test', 'payload' => $payload],
            $result['http_code'] === 200,
            $error
        );

        return $result;
    }

    private function pushToWordPress(array $item): void
    {
        $apiKey = $this->configService->getWpApiKey();
        if ($apiKey === '') {
            throw new SyncFailedException('WP API key not configured');
        }

        $result = $this->request((string)$item['target_url'], (string)$item['payload'], $apiKey, 15);
        $httpCode = (int)$result['http_code'];

        if ($result['error'] !== null || $httpCode < 200 || $httpCode >= 300) {
            throw new SyncFailedException(
                sprintf('WP API returned HTTP %d', $httpCode),
                $httpCode,
                substr($result['body'], 0, 1000),
            );
        }
    }

    /**
     * Fuehrt einen POST-Request gegen die WordPress-REST-API aus.
     *
     * `http_code` ist null, wenn kein HTTP-Status gelesen werden konnte
     * (Transportfehler: DNS, Connect, Timeout, TLS).
     *
     * @return array{http_code: int|null, body: string, error: string|null}
     */
    private function request(string $url, string $payload, string $apiKey, int $timeout): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiKey,
                    'X-Repair-Source: openxe',
                ]),
                'content' => $payload,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        // error_clear_last(), damit error_get_last() unten garantiert die
        // Warnung dieses Requests liefert und keinen aelteren Rest.
        error_clear_last();
        $response = @file_get_contents($url, false, $context);
        $httpCode = $this->parseHttpCode($http_response_header ?? []);

        if ($response === false) {
            $lastError = error_get_last();
            return [
                'http_code' => $httpCode > 0 ? $httpCode : null,
                'body' => '',
                'error' => $lastError['message'] ?? 'Request failed',
            ];
        }

        return [
            'http_code' => $httpCode > 0 ? $httpCode : null,
            'body' => $response,
            'error' => null,
        ];
    }

    private function parseHttpCode(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/[\d.]+ (\d{3})/', $header, $matches) === 1) {
                return (int)$matches[1];
            }
        }
        return 0;
    }

    private function logSync(string $direction, array $entry, bool $success, string $error = ''): void
    {
        $this->db->perform(
            "INSERT INTO `repair_sync_log`
             (`direction`, `ticket_schluessel`, `action`, `payload_sent`, `success`, `error_message`)
             VALUES (:dir, :key, :action, :payload, :success, :error)",
            [
                'dir' => $direction,
                'key' => $entry['ticket_schluessel'],
                'action' => $entry['action'],
                'payload' => $entry['payload'],
                'success' => $success ? 1 : 0,
                'error' => $error !== '' ? $error : null,
            ]
        );
    }
}
