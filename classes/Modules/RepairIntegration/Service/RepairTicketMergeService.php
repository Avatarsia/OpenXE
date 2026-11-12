<?php
declare(strict_types=1);

namespace Xentral\Modules\RepairIntegration\Service;

use Xentral\Components\Database\Database;
use Xentral\Modules\RepairIntegration\Gateway\RepairBelegGateway;
use Xentral\Modules\RepairIntegration\Gateway\RepairDetailsGateway;
use Xentral\Modules\RepairIntegration\Gateway\RepairSyncQueueGateway;

final class RepairTicketMergeService
{
    public function __construct(
        private readonly Database $db,
        private readonly RepairDetailsGateway $detailsGateway,
        private readonly RepairSyncQueueGateway $syncQueueGateway,
        private readonly RepairBelegGateway $belegGateway,
    ) {}

    /**
     * MCP-compatible: returns structured array.
     * Merges source ticket into target ticket within a transaction.
     */
    public function mergeTickets(int $sourceTicketId, int $targetTicketId): array
    {
        if ($sourceTicketId === $targetTicketId) {
            throw new \InvalidArgumentException('Quell- und Ziel-Ticket sind identisch');
        }

        $source = $this->db->fetchRow(
            'SELECT `id`, `schluessel` FROM `ticket` WHERE `id` = :id',
            ['id' => $sourceTicketId]
        );
        $target = $this->db->fetchRow(
            'SELECT `id`, `schluessel` FROM `ticket` WHERE `id` = :id',
            ['id' => $targetTicketId]
        );

        if (!$source || !$target) {
            throw new \InvalidArgumentException('Ticket nicht gefunden');
        }

        $this->db->beginTransaction();
        try {
            // 1. Move messages (ticket_nachricht.ticket = schluessel VARCHAR)
            $this->db->perform(
                'UPDATE `ticket_nachricht` SET `ticket` = :target_key WHERE `ticket` = :source_key',
                ['target_key' => $target['schluessel'], 'source_key' => $source['schluessel']]
            );

            // 2. Move protocol entries (ticket_protokoll.ticket = ticket.id INT)
            $this->db->perform(
                'UPDATE `ticket_protokoll` SET `ticket` = :target_id WHERE `ticket` = :source_id',
                ['target_id' => $targetTicketId, 'source_id' => $sourceTicketId]
            );

            // 3. Move header files (datei_stichwoerter with objekt='ticket_header')
            $this->db->perform(
                "UPDATE `datei_stichwoerter` SET `parameter` = :target_id
                 WHERE `objekt` = 'ticket_header' AND `parameter` = :source_id",
                ['target_id' => (string)$targetTicketId, 'source_id' => (string)$sourceTicketId]
            );

            // 4. Handle repair_details
            $sourceDetails = $this->detailsGateway->getByTicketId($sourceTicketId);
            $targetDetails = $this->detailsGateway->getByTicketId($targetTicketId);
            if ($sourceDetails !== null && $targetDetails === null) {
                $this->detailsGateway->moveToTicket(
                    $sourceTicketId, $targetTicketId, $target['schluessel']
                );
            } elseif ($sourceDetails !== null && $targetDetails !== null) {
                $this->detailsGateway->deleteByTicketId($sourceTicketId);
            }

            // 5. Move beleg links
            $this->belegGateway->moveToTicket(
                $sourceTicketId, $targetTicketId, $target['schluessel']
            );

            // 6. Delete pending sync entries for source
            $this->syncQueueGateway->deletePendingForTicket($sourceTicketId);

            // 7. Update message counts
            $this->updateMessageCount($target['schluessel']);
            $this->updateMessageCount($source['schluessel']);

            // 8. Close source ticket
            $this->db->perform(
                "UPDATE `ticket` SET
                    `status` = 'abgeschlossen',
                    `notiz` = CONCAT('Zusammengefuehrt mit Ticket #', :target_key, ' am ', NOW(), '\n', COALESCE(`notiz`, ''))
                 WHERE `id` = :source_id",
                ['target_key' => $target['schluessel'], 'source_id' => $sourceTicketId]
            );

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }

        // Count what was moved
        $messageCount = (int)$this->db->fetchValue(
            'SELECT `nachrichten_anz` FROM `ticket` WHERE `id` = :id',
            ['id' => $targetTicketId]
        );

        return [
            'success' => true,
            'source_ticket' => $source['schluessel'],
            'target_ticket' => $target['schluessel'],
            'messages_in_target' => $messageCount,
        ];
    }

    private function updateMessageCount(string $schluessel): void
    {
        $this->db->perform(
            'UPDATE `ticket` SET `nachrichten_anz` = (
                SELECT COUNT(`id`) FROM `ticket_nachricht` WHERE `ticket` = :key
             ) WHERE `schluessel` = :key2',
            ['key' => $schluessel, 'key2' => $schluessel]
        );
    }
}
