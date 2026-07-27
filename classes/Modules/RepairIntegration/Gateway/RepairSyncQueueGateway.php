<?php
declare(strict_types=1);

namespace Xentral\Modules\RepairIntegration\Gateway;

use Xentral\Components\Database\Database;

final class RepairSyncQueueGateway
{
    public function __construct(
        private readonly Database $db,
    ) {}

    public function enqueue(
        int $ticketId,
        string $schluessel,
        string $action,
        string $payload,
        string $targetUrl,
        int $maxRetries = 5,
    ): int {
        $this->db->perform(
            "INSERT INTO `repair_sync_queue`
             (`ticket_id`, `ticket_schluessel`, `action`, `payload`, `target_url`, `max_retries`, `next_retry_at`)
             VALUES (:tid, :key, :action, :payload, :url, :max_retries, NOW())",
            [
                'tid' => $ticketId,
                'key' => $schluessel,
                'action' => $action,
                'payload' => $payload,
                'url' => $targetUrl,
                'max_retries' => $maxRetries,
            ]
        );
        return (int)$this->db->fetchValue('SELECT LAST_INSERT_ID()');
    }

    public function getPendingEntries(int $limit = 50): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM `repair_sync_queue`
             WHERE `status` IN ('pending', 'failed')
               AND (`next_retry_at` IS NULL OR `next_retry_at` <= NOW())
             ORDER BY `created_at` ASC
             LIMIT " . (int)$limit
        );
    }

    public function markProcessing(int $id): void
    {
        // Die Tabelle hat keinen eigenen Claim-Zeitstempel; `processed_at`
        // dient hier als Claim-Markierung fuer reapStaleProcessing() und wird
        // bei markCompleted()/markPermanentlyFailed() mit dem Endzeitpunkt
        // ueberschrieben.
        $affected = $this->db->fetchAffected(
            "UPDATE `repair_sync_queue`
             SET `status` = 'processing', `processed_at` = NOW()
             WHERE `id` = :id AND `status` IN ('pending', 'failed')",
            ['id' => $id]
        );
        if ($affected === 0) {
            throw new \RuntimeException("Queue entry {$id} already claimed or not found");
        }
    }

    /**
     * Reaper fuer Queue-Leichen: Eintraege, die laenger als $minutes Minuten
     * in 'processing' stehen (Worker abgestuerzt/gekillt), auf 'failed'
     * zuruecksetzen, damit die Retry-Logik sie wieder aufnimmt.
     * retry_count wird bewusst nicht erhoeht — der Eintrag hat keinen
     * echten Zustellversuch hinter sich.
     */
    public function reapStaleProcessing(int $minutes = 15): int
    {
        return $this->db->fetchAffected(
            "UPDATE `repair_sync_queue`
             SET `status` = 'failed',
                 `next_retry_at` = NOW(),
                 `last_error` = 'Reaped: Eintrag hing in processing (Worker-Abbruch)'
             WHERE `status` = 'processing'
               AND `processed_at` < (NOW() - INTERVAL " . (int)$minutes . " MINUTE)"
        );
    }

    public function markCompleted(int $id): void
    {
        $this->db->perform(
            "UPDATE `repair_sync_queue`
             SET `status` = 'completed', `processed_at` = NOW()
             WHERE `id` = :id",
            ['id' => $id]
        );
    }

    public function markFailed(
        int $id,
        int $retryCount,
        string $nextRetryAt,
        string $error,
        int $httpCode = 0,
    ): void {
        $this->db->perform(
            "UPDATE `repair_sync_queue`
             SET `status` = 'failed',
                 `retry_count` = :count,
                 `next_retry_at` = :next,
                 `last_error` = :error,
                 `last_http_code` = :code
             WHERE `id` = :id",
            [
                'count' => $retryCount,
                'next' => $nextRetryAt,
                'error' => $error,
                'code' => $httpCode,
                'id' => $id,
            ]
        );
    }

    public function markPermanentlyFailed(int $id, string $error, int $httpCode = 0): void
    {
        $this->db->perform(
            "UPDATE `repair_sync_queue`
             SET `status` = 'permanently_failed',
                 `last_error` = :error,
                 `last_http_code` = :code,
                 `processed_at` = NOW()
             WHERE `id` = :id",
            ['error' => $error, 'code' => $httpCode, 'id' => $id]
        );
    }

    /**
     * Verwirft ausstehende Queue-Eintraege eines Tickets, optional nur einer
     * Aktion (Dedup vor dem erneuten Einreihen). 'failed'-Eintraege bleiben
     * zur Historie stehen.
     */
    public function deletePendingForTicket(int $ticketId, ?string $action = null): void
    {
        $sql = "DELETE FROM `repair_sync_queue`
                WHERE `ticket_id` = :tid AND `status` = 'pending'";
        $params = ['tid' => $ticketId];
        if ($action !== null) {
            $sql .= " AND `action` = :action";
            $params['action'] = $action;
        }
        $this->db->perform($sql, $params);
    }

    public function moveToTicket(int $sourceTicketId, int $targetTicketId, string $targetSchluessel): void
    {
        $this->db->perform(
            'UPDATE `repair_sync_queue`
             SET `ticket_id` = :target_id, `ticket_schluessel` = :target_key
             WHERE `ticket_id` = :source_id',
            [
                'target_id' => $targetTicketId,
                'target_key' => $targetSchluessel,
                'source_id' => $sourceTicketId,
            ]
        );
    }

    public function getQueueStats(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT `status`, COUNT(*) as `count`
             FROM `repair_sync_queue`
             GROUP BY `status`"
        );
        $stats = [
            'pending' => 0,
            'processing' => 0,
            'completed' => 0,
            'failed' => 0,
            'permanently_failed' => 0,
        ];
        foreach ($rows as $row) {
            $stats[$row['status']] = (int)$row['count'];
        }

        $lastSync = $this->db->fetchValue(
            "SELECT MAX(`processed_at`) FROM `repair_sync_queue` WHERE `status` = 'completed'"
        );
        $stats['last_successful_sync'] = $lastSync ?: null;

        return $stats;
    }
}
