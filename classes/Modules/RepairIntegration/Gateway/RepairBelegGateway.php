<?php
declare(strict_types=1);

namespace Xentral\Modules\RepairIntegration\Gateway;

use Xentral\Components\Database\Database;

final class RepairBelegGateway
{
    public function __construct(
        private readonly Database $db,
    ) {}

    public function link(
        int $ticketId,
        string $schluessel,
        string $belegTyp,
        int $belegId,
        ?string $belegNr,
        bool $erstelltAusTicket,
        string $createdBy,
    ): int {
        $this->db->perform(
            "INSERT INTO `repair_ticket_beleg`
             (`ticket_id`, `ticket_schluessel`, `beleg_typ`, `beleg_id`,
              `beleg_nr`, `erstellt_aus_ticket`, `created_by`)
             VALUES (:tid, :key, :typ, :bid, :bnr, :created, :by)",
            [
                'tid' => $ticketId,
                'key' => $schluessel,
                'typ' => $belegTyp,
                'bid' => $belegId,
                'bnr' => $belegNr,
                'created' => $erstelltAusTicket ? 1 : 0,
                'by' => $createdBy,
            ]
        );
        return (int)$this->db->fetchValue('SELECT LAST_INSERT_ID()');
    }

    public function getByTicketId(int $ticketId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM `repair_ticket_beleg`
             WHERE `ticket_id` = :tid
             ORDER BY `created_at` DESC',
            ['tid' => $ticketId]
        );
    }

    public function getByBeleg(string $belegTyp, int $belegId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM `repair_ticket_beleg`
             WHERE `beleg_typ` = :typ AND `beleg_id` = :bid',
            ['typ' => $belegTyp, 'bid' => $belegId]
        );
    }

    public function moveToTicket(int $sourceTicketId, int $targetTicketId, string $targetSchluessel): void
    {
        $this->db->perform(
            'UPDATE `repair_ticket_beleg`
             SET `ticket_id` = :target_id, `ticket_schluessel` = :target_key
             WHERE `ticket_id` = :source_id',
            [
                'target_id' => $targetTicketId,
                'target_key' => $targetSchluessel,
                'source_id' => $sourceTicketId,
            ]
        );
    }
}
