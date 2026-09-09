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

    /** HTTP-Timeout (s) fuer den Cron-Worker */
    private const WORKER_TIMEOUT = 15;

    /** HTTP-Timeout (s) fuer den synchronen Push im Speicher-Request; Rest macht der Cron */
    private const IMMEDIATE_TIMEOUT = 8;

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

    public function checkAndQueueStatusChange(int $ticketId): bool
    {
        if (!$this->syncQueueGateway->lockTicket($ticketId)) {
            throw new \RuntimeException('Repair status lock timeout for ticket #' . $ticketId);
        }
        try {
            return $this->queueCurrentStatus($ticketId) !== null;
        } finally {
            $this->syncQueueGateway->unlockTicket($ticketId);
        }
    }

    /**
     * Wie checkAndQueueStatusChange(), stellt den neuen Eintrag aber noch im
     * Speicher-Request synchron an WordPress zu (kurzer Timeout). Schlaegt die
     * Zustellung fehl, bleibt der Eintrag als 'failed' in der Queue und der
     * Cron uebernimmt die Retries — der Aufrufer bekommt keine Exception.
     *
     * @return bool true, wenn ein Status eingereiht wurde (unabhaengig vom Zustellerfolg)
     */
    public function queueAndPushStatusChange(int $ticketId): bool
    {
        if (!$this->syncQueueGateway->lockTicket($ticketId)) {
            throw new \RuntimeException('Repair status lock timeout for ticket #' . $ticketId);
        }
        try {
            $queueId = $this->queueCurrentStatus($ticketId);
            if ($queueId === null) {
                return false;
            }
            $entry = $this->syncQueueGateway->getEntry($queueId);
            if ($entry !== null) {
                $this->deliverEntry($entry, self::IMMEDIATE_TIMEOUT);
            }
            return true;
        } finally {
            $this->syncQueueGateway->unlockTicket($ticketId);
        }
    }

    /** @return int|null Queue-ID des neuen Eintrags, null wenn nichts eingereiht wurde */
    private function queueCurrentStatus(int $ticketId): ?int
    {
        if (!$this->configService->isEnabled()) {
            return null;
        }

        $details = $this->detailsGateway->getByTicketId($ticketId);
        if ($details === null || empty($details['wp_request_number'])) {
            return null;
        }

        $ticket = $this->db->fetchRow(
            'SELECT `status` FROM `ticket` WHERE `id` = :id',
            ['id' => $ticketId]
        );
        if (!$ticket) {
            return null;
        }

        $wpStatus = $this->statusConfigGateway->getWpMapping($ticket['status']);
        if ($wpStatus === null) {
            return null;
        }

        $baseUrl = $this->configService->getWpApiUrl();
        if ($baseUrl === '') {
            // Ohne wp_api_url waere jeder Push ein garantierter Fehlschlag:
            // nichts einreihen, nur warnen.
            $this->logWarning(sprintf(
                'Status-Sync fuer Ticket #%d uebersprungen: wp_api_url ist nicht konfiguriert',
                $ticketId
            ));
            return null;
        }

        $payloadData = [
            'request_number' => $details['wp_request_number'],
            'status' => $wpStatus,
        ];
        // KVA-Betrag aus OpenXE mitsenden, sobald er gesetzt ist. Das Plugin
        // nimmt `quote_amount` im Status-Endpoint entgegen und speichert ihn
        // als customer_quote_amount der Anfrage.
        $quoteAmount = self::formatQuoteAmount($details['quote_amount'] ?? null);
        if ($quoteAmount !== null) {
            $payloadData['quote_amount'] = $quoteAmount;
        }
        $payload = json_encode($payloadData, JSON_THROW_ON_ERROR);

        $targetUrl = $baseUrl . '/wp-json/p3d/v1/requests/status';

        // Insert first: a failed insert must not lose the previous delivery.
        $latestId = $this->syncQueueGateway->enqueue(
            $ticketId,
            $details['ticket_schluessel'],
            'status_change',
            $payload,
            $targetUrl,
            $this->configService->getMaxRetries(),
        );
        $this->syncQueueGateway->supersedeOlderStatuses($ticketId, $latestId);
        return $latestId;
    }

    /**
     * Formatiert den DB-Wert von ticket_repair_details.quote_amount fuer den
     * Payload. DECIMAL kommt vom Treiber als String; leer, nicht numerisch
     * oder <= 0 wird nicht gesendet.
     */
    public static function formatQuoteAmount(mixed $raw): ?string
    {
        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            return null;
        }
        $value = (float)$raw;
        if ($value <= 0) {
            return null;
        }
        return number_format($value, 2, '.', '');
    }

    public static function inferWpRequestNumber(string $ticketSchluessel, int $matchingTickets): ?string
    {
        if ($matchingTickets !== 1 || preg_match('/^[0-9]{12}$/', $ticketSchluessel) !== 1) {
            return null;
        }

        return $ticketSchluessel;
    }

    /** @return array{recovered: int, queued: int, skipped: int} */
    public function backfillAndQueueCurrentStatuses(): array
    {
        // Ticket IDs are positive; zero serializes the persistent cursor.
        if (!$this->syncQueueGateway->lockTicket(0, 0)) {
            return ['recovered' => 0, 'queued' => 0, 'skipped' => 0];
        }
        try {
            return $this->resumeStatusBackfill();
        } finally {
            $this->syncQueueGateway->unlockTicket(0);
        }
    }

    private function resumeStatusBackfill(): array
    {
        $stats = ['recovered' => 0, 'queued' => 0, 'skipped' => 0];
        if ($this->configService->get('status_backfill_state') === 'completed'
            || version_compare($this->configService->get('schema_version', '0'), '1.4.0', '<')
            || !$this->configService->isEnabled()
            || $this->configService->getWpApiUrl() === ''
            || $this->configService->getWpApiKey() === '') {
            return $stats;
        }
        $cursor = (int)$this->configService->get('status_backfill_cursor', '0');
        $rows = $this->db->fetchAll(
            "SELECT rd.id, rd.ticket_id, rd.ticket_schluessel, rd.wp_request_number,
                    t.schluessel actual_key, t.status actual_status,
                    (SELECT COUNT(*) FROM ticket tx WHERE tx.schluessel = t.schluessel) matching_tickets,
                    (SELECT COUNT(*) FROM ticket_repair_details other
                     WHERE other.id <> rd.id AND (other.wp_request_number = COALESCE(NULLIF(rd.wp_request_number, ''), t.schluessel)
                         OR other.ticket_schluessel = COALESCE(NULLIF(rd.wp_request_number, ''), t.schluessel))) duplicate_requests
             FROM ticket_repair_details rd LEFT JOIN ticket t ON t.id = rd.ticket_id
             WHERE rd.id > :cursor ORDER BY rd.id",
            ['cursor' => $cursor]
        );

        foreach ($rows as $row) {
            if ($row['actual_key'] === null || $row['actual_key'] !== $row['ticket_schluessel']
                || (int)$row['matching_tickets'] !== 1 || (int)$row['duplicate_requests'] > 0) {
                $this->logWarning('Backfill skipped inconsistent/ambiguous ticket #' . $row['ticket_id']);
                $stats['skipped']++;
                $this->configService->set('status_backfill_cursor', (string)$row['id']);
                continue;
            }
            $wpRequestNumber = trim((string)($row['wp_request_number'] ?? ''));
            if ($wpRequestNumber === '') {
                $wpRequestNumber = self::inferWpRequestNumber(
                    (string)$row['actual_key'],
                    (int)$row['matching_tickets'],
                ) ?? '';
                if ($wpRequestNumber === '') {
                    $this->logWarning('Backfill skipped invalid request number for ticket #' . $row['ticket_id']);
                    $stats['skipped']++;
                    $this->configService->set('status_backfill_cursor', (string)$row['id']);
                    continue;
                }
                $this->detailsGateway->update((int)$row['id'], ['wp_request_number' => $wpRequestNumber]);
                $stats['recovered']++;
            }

            if ($this->statusConfigGateway->getWpMapping((string)$row['actual_status']) === null) {
                $this->logWarning('Backfill skipped unmapped status for ticket #' . $row['ticket_id']);
                $stats['skipped']++;
            } elseif ($this->checkAndQueueStatusChange((int)$row['ticket_id'])) {
                $stats['queued']++;
            } else {
                $this->logWarning('Backfill deferred unmapped/unconfigured ticket #' . $row['ticket_id']);
                $stats['skipped']++;
                return $stats;
            }
            $this->configService->set('status_backfill_cursor', (string)$row['id']);
        }

        $this->configService->set('status_backfill_state', 'completed');
        return $stats;
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
            // Same lock as enqueue, held through HTTP and retry persistence.
            // A worker selected before supersession must still claim afresh.
            $ticketId = (int)$entry['ticket_id'];
            if (!$this->syncQueueGateway->lockTicket($ticketId, 0)) {
                continue;
            }
            try {
                if ($this->deliverEntry($entry, self::WORKER_TIMEOUT)) {
                    $processed++;
                }
            } finally {
                $this->syncQueueGateway->unlockTicket($ticketId);
            }
        }

        return $processed;
    }

    /**
     * Stellt einen einzelnen Queue-Eintrag zu und persistiert das Ergebnis
     * (completed / failed mit Retry / permanently_failed). Der Aufrufer muss
     * den Ticket-Lock halten. Wirft nie — Fehler landen in Queue und Log.
     *
     * @return bool true bei erfolgreicher Zustellung
     */
    private function deliverEntry(array $entry, int $timeout): bool
    {
        try {
            $this->syncQueueGateway->markProcessing((int)$entry['id']);
        } catch (\RuntimeException) {
            return false;
        }

        try {
            $result = $this->pushToWordPress($entry, $timeout);
            $this->syncQueueGateway->markCompleted((int)$entry['id']);
            $this->logSync(
                'outbound',
                $entry,
                true,
                '',
                $result['http_code'],
                $result['body'] !== '' ? substr($result['body'], 0, 1000) : null
            );
            return true;
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
                    (int)$entry['id'],
                    $e->getMessage(),
                    $httpCode,
                );
                $this->notifyPermanentlyFailed($entry, $e->getMessage(), $httpCode);
            } else {
                $delayIndex = min($retryCount - 1, count(self::RETRY_DELAYS) - 1);
                $delay = self::RETRY_DELAYS[$delayIndex];
                $nextRetry = date('Y-m-d H:i:s', time() + $delay);

                $this->syncQueueGateway->markFailed(
                    (int)$entry['id'],
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
            return false;
        }
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
    private function pushToWordPress(array $item, int $timeout = self::WORKER_TIMEOUT): array
    {
        $apiKey = $this->configService->getWpApiKey();
        if ($apiKey === '') {
            throw new SyncFailedException('WP API key not configured');
        }

        $result = $this->request((string)$item['target_url'], (string)$item['payload'], $apiKey, $timeout);
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
        // Bei verfolgten Redirects enthaelt $http_response_header die
        // Statuszeilen aller Hops; massgeblich ist die letzte Antwort.
        $code = 0;
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/[\d.]+ (\d{3})/', $header, $matches) === 1) {
                $code = (int)$matches[1];
            }
        }
        return $code;
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
