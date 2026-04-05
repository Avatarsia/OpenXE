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
    private const array RETRY_DELAYS = [120, 600, 1800, 7200, 28800];

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

    private function pushToWordPress(array $item): void
    {
        $apiKey = $this->configService->getWpApiKey();
        if ($apiKey === '') {
            throw new SyncFailedException('WP API key not configured');
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiKey,
                    'X-Repair-Source: openxe',
                ]),
                'content' => $item['payload'],
                'timeout' => 15,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $response = @file_get_contents($item['target_url'], false, $context);
        $httpCode = $this->parseHttpCode($http_response_header ?? []);

        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            throw new SyncFailedException(
                sprintf('WP API returned HTTP %d', $httpCode),
                $httpCode,
                $response !== false ? substr($response, 0, 1000) : '',
            );
        }
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
