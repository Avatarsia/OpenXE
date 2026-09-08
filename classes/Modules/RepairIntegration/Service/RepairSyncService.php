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
        // Optional, damit der Service auch ohne $app-Kontext instanziierbar
        // bleibt; der Versand laeuft ueber $app->erp->MailSend (im Bootstrap
        // als Closure verdrahtet). Signatur: (string $to, string $subject, string $text): void
        private readonly ?\Closure $permanentFailMailer = null,
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

        $baseUrl = $this->configService->getWpApiUrl();
        if ($baseUrl === '') {
            // Ohne wp_api_url waere jeder Push ein garantierter Fehlschlag:
            // nichts einreihen, nur warnen.
            $this->logWarning(sprintf(
                'Status-Sync fuer Ticket #%d uebersprungen: wp_api_url ist nicht konfiguriert',
                $ticketId
            ));
            return;
        }

        $payload = json_encode([
            'request_number' => $details['wp_request_number'],
            'status' => $wpStatus,
        ], JSON_THROW_ON_ERROR);

        $targetUrl = $baseUrl . '/wp-json/p3d/v1/requests/status';

        // Dedup: noch ausstehende Status-Syncs desselben Tickets verwerfen,
        // damit ein veralteter Status nicht nach dem neueren zugestellt wird.
        // 'failed'-Eintraege bleiben zur Historie stehen.
        $this->syncQueueGateway->deletePendingForTicket($ticketId, 'status_change');

        $this->syncQueueGateway->enqueue(
            $ticketId,
            $details['ticket_schluessel'],
            'status_change',
            $payload,
            $targetUrl,
            $this->configService->getMaxRetries(),
        );
    }

    public function processQueue(): int
    {
        // Reaper: Eintraege, die laenger als 15 Minuten in 'processing'
        // haengen (Worker abgestuerzt/gekillt), auf 'failed' zuruecksetzen,
        // damit sie der Retry-Logik wieder zugefuehrt werden.
        $this->syncQueueGateway->reapStaleProcessing(15);

        $entries = $this->syncQueueGateway->getPendingEntries(50);
        $processed = 0;

        foreach ($entries as $entry) {
            try {
                $this->syncQueueGateway->markProcessing($entry['id']);
            } catch (\RuntimeException) {
                continue;
            }

            try {
                $result = $this->pushToWordPress($entry);
                $this->syncQueueGateway->markCompleted($entry['id']);
                $this->logSync(
                    'outbound',
                    $entry,
                    true,
                    '',
                    $result['http_code'],
                    $result['body'] !== '' ? substr($result['body'], 0, 1000) : null
                );
                $processed++;
            } catch (\Throwable $e) {
                // Nicht nur SyncFailedException: auch DB-Fehler oder TypeError
                // duerfen den Eintrag nicht unsichtbar in 'processing' haengen
                // lassen — wie ein normaler Retry-Fehler behandeln.
                $httpCode = $e instanceof SyncFailedException ? $e->httpCode : 0;
                $responseBody = $e instanceof SyncFailedException ? $e->responseBody : '';
                $retryCount = (int)$entry['retry_count'] + 1;
                $maxRetries = (int)$entry['max_retries'];

                if ($retryCount >= $maxRetries) {
                    $this->syncQueueGateway->markPermanentlyFailed(
                        $entry['id'],
                        $e->getMessage(),
                        $httpCode,
                    );
                    $this->notifyPermanentlyFailed($entry, $e->getMessage(), $httpCode);
                } else {
                    $delayIndex = min($retryCount - 1, count(self::RETRY_DELAYS) - 1);
                    $delay = self::RETRY_DELAYS[$delayIndex];
                    $nextRetry = date('Y-m-d H:i:s', time() + $delay);

                    $this->syncQueueGateway->markFailed(
                        $entry['id'],
                        $retryCount,
                        $nextRetry,
                        $e->getMessage(),
                        $httpCode,
                    );
                }
                $this->logSync(
                    'outbound',
                    $entry,
                    false,
                    $e->getMessage(),
                    $httpCode > 0 ? $httpCode : null,
                    $responseBody !== '' ? $responseBody : null
                );
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

        $ok = $result['error'] === null && $result['http_code'] === 200;
        $error = '';
        if (!$ok) {
            $error = $result['error'] ?? sprintf('WP API returned HTTP %d', (int)$result['http_code']);
        }
        $this->logSync(
            'outbound',
            ['ticket_schluessel' => null, 'action' => 'connection_test', 'payload' => $payload],
            $ok,
            $error,
            $result['http_code'],
            $result['body'] !== '' ? substr($result['body'], 0, 1000) : null
        );

        return $result;
    }

    /**
     * @return array{http_code: int|null, body: string, error: string|null}
     */
    private function pushToWordPress(array $item): array
    {
        $apiKey = $this->configService->getWpApiKey();
        if ($apiKey === '') {
            throw new SyncFailedException('WP API key not configured');
        }

        $result = $this->request((string)$item['target_url'], (string)$item['payload'], $apiKey, 15);
        $httpCode = (int)$result['http_code'];

        if ($result['error'] !== null || $httpCode < 200 || $httpCode >= 300) {
            // Bei Transportfehlern (HTTP 0) den eigentlichen Grund
            // (DNS/TLS/Timeout) aus $result['error'] anhaengen, sonst geht
            // er fuer die Diagnose verloren.
            $message = sprintf('WP API returned HTTP %d', $httpCode);
            if ($result['error'] !== null) {
                $message .= ': ' . $result['error'];
            }
            throw new SyncFailedException(
                $message,
                $httpCode,
                substr($result['body'], 0, 1000),
            );
        }

        return $result;
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

    /**
     * Schickt eine schlichte Benachrichtigung an die konfigurierte Adresse,
     * wenn ein Queue-Eintrag endgueltig fehlgeschlagen ist. Der Versand
     * laeuft ueber die im Konstruktor injizierte Closure
     * ($app->erp->MailSend), weil der Service selbst keinen Zugriff auf
     * $app hat. Ein Mail-Fehler darf die Queue-Abarbeitung nicht brechen.
     */
    private function notifyPermanentlyFailed(array $entry, string $error, int $httpCode): void
    {
        $to = $this->configService->getNotifyOnPermanentFailEmail();
        if ($to === '' || $this->permanentFailMailer === null) {
            return;
        }

        $subject = sprintf(
            'Repair-Sync endgueltig fehlgeschlagen: Ticket %s',
            $entry['ticket_schluessel']
        );
        $text = sprintf(
            "Der Outbound-Sync an WordPress ist endgueltig fehlgeschlagen.\n\n"
            . "Ticket: %s\nAktion: %s\nLetzter HTTP-Code: %s\nFehlermeldung: %s\n",
            $entry['ticket_schluessel'],
            $entry['action'],
            $httpCode > 0 ? (string)$httpCode : '-',
            $error
        );

        try {
            ($this->permanentFailMailer)($to, $subject, $text);
        } catch (\Throwable $mailError) {
            $this->logWarning(
                'Benachrichtigungsmail (permanently_failed) fehlgeschlagen: ' . $mailError->getMessage()
            );
        }
    }

    /**
     * Schreibt eine Warnung in die `logfile`-Tabelle — dieselbe Ablage wie
     * $app->erp->LogFile(), das im Service-Kontext ohne $app nicht
     * verfuegbar ist (Muster wie in AmaInvoiceService).
     */
    private function logWarning(string $message): void
    {
        $this->db->perform(
            "INSERT INTO `logfile`
             (`meldung`, `dump`, `module`, `action`, `bearbeiter`, `funktionsname`, `datum`)
             VALUES (:msg, '', 'repair_integration', 'status_sync', '', '', NOW())",
            ['msg' => $message]
        );
    }

    private function logSync(
        string $direction,
        array $entry,
        bool $success,
        string $error = '',
        ?int $httpCode = null,
        ?string $responseReceived = null,
    ): void {
        // wp_request_number steckt im Outbound-Payload als 'request_number'.
        $wpRequestNumber = null;
        $payload = json_decode((string)($entry['payload'] ?? ''), true);
        if (is_array($payload) && !empty($payload['request_number'])) {
            $wpRequestNumber = (string)$payload['request_number'];
        }

        $this->db->perform(
            "INSERT INTO `repair_sync_log`
             (`direction`, `ticket_schluessel`, `wp_request_number`, `action`, `payload_sent`,
              `response_received`, `http_code`, `success`, `error_message`)
             VALUES (:dir, :key, :wpnr, :action, :payload, :response, :code, :success, :error)",
            [
                'dir' => $direction,
                'key' => $entry['ticket_schluessel'],
                'wpnr' => $wpRequestNumber,
                'action' => $entry['action'],
                'payload' => $entry['payload'],
                'response' => $responseReceived,
                'code' => $httpCode,
                'success' => $success ? 1 : 0,
                'error' => $error !== '' ? $error : null,
            ]
        );
    }
}
