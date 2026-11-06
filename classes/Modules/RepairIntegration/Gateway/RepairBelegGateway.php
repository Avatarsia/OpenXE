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

    public function isLinked(int $ticketId, string $belegTyp, int $belegId): bool
    {
        return (int)$this->db->fetchValue(
            'SELECT COUNT(*) FROM `repair_ticket_beleg`
             WHERE `ticket_id` = :tid AND `beleg_typ` = :typ AND `beleg_id` = :bid',
            ['tid' => $ticketId, 'typ' => $belegTyp, 'bid' => $belegId]
        ) > 0;
    }

    public function getById(int $id): ?array
    {
        $row = $this->db->fetchRow(
            'SELECT * FROM `repair_ticket_beleg` WHERE `id` = :id LIMIT 1',
            ['id' => $id]
        );
        return empty($row) ? null : $row;
    }

    public function unlink(int $id): void
    {
        $this->db->perform('DELETE FROM `repair_ticket_beleg` WHERE `id` = :id', ['id' => $id]);
    }

    /**
     * Alle Ticket-Links, die auf einen der uebergebenen Belege zeigen.
     *
     * @param array<int, array{typ: string, id: int}> $belege
     */
    public function getByBelege(array $belege): array
    {
        $conditions = [];
        $values = [];
        foreach (array_values($belege) as $i => $beleg) {
            $conditions[] = '(rtb.`beleg_typ` = :typ' . $i . ' AND rtb.`beleg_id` = :bid' . $i . ')';
            $values['typ' . $i] = (string)$beleg['typ'];
            $values['bid' . $i] = (int)$beleg['id'];
        }
        if ($conditions === []) {
            return [];
        }
        return $this->db->fetchAll(
            'SELECT rtb.*, t.`betreff`, t.`status` AS `ticket_status`
             FROM `repair_ticket_beleg` rtb
             LEFT JOIN `ticket` t ON t.`id` = rtb.`ticket_id`
             WHERE ' . implode(' OR ', $conditions) . '
             ORDER BY rtb.`created_at` DESC',
            $values
        );
    }

    /**
     * Belege eines Kundenkontos ueber alle Belegtypen, neueste zuerst.
     */
    public function findBelegeForAdresse(int $adresseId, int $limit = 100): array
    {
        if ($adresseId <= 0) {
            return [];
        }
        $limit = max(1, min(500, $limit));
        return $this->db->fetchAll(
            "SELECT * FROM (
                SELECT 'angebot' AS typ, id, belegnr, status, datum, gesamtsumme AS betrag FROM `angebot` WHERE adresse = :a1
                UNION ALL SELECT 'auftrag', id, belegnr, status, datum, gesamtsumme FROM `auftrag` WHERE adresse = :a2
                UNION ALL SELECT 'lieferschein', id, belegnr, status, datum, NULL FROM `lieferschein` WHERE adresse = :a3
                UNION ALL SELECT 'rechnung', id, belegnr, status, datum, soll FROM `rechnung` WHERE adresse = :a4
                UNION ALL SELECT 'gutschrift', id, belegnr, status, datum, soll FROM `gutschrift` WHERE adresse = :a5
             ) b
             ORDER BY b.datum DESC, b.id DESC
             LIMIT " . $limit,
            ['a1' => $adresseId, 'a2' => $adresseId, 'a3' => $adresseId, 'a4' => $adresseId, 'a5' => $adresseId]
        );
    }

    /**
     * Reparaturtickets (Tickets mit ticket_repair_details) eines Kundenkontos.
     */
    public function findRepairTicketsForAdresse(int $adresseId, int $limit = 50): array
    {
        if ($adresseId <= 0) {
            return [];
        }
        $limit = max(1, min(200, $limit));
        return $this->db->fetchAll(
            'SELECT t.`id`, t.`schluessel`, t.`betreff`, t.`status`, t.`zeit`,
                    rd.`manufacturer`, rd.`model`
             FROM `ticket` t
             INNER JOIN `ticket_repair_details` rd ON rd.`ticket_id` = t.`id`
             WHERE t.`adresse` = :a
             ORDER BY t.`zeit` DESC, t.`id` DESC
             LIMIT ' . $limit,
            ['a' => $adresseId]
        );
    }

    public function findAdresseByKundennummer(string $kundennummer): ?array
    {
        $kundennummer = trim($kundennummer);
        if ($kundennummer === '') {
            return null;
        }
        $row = $this->db->fetchRow(
            'SELECT `id`, `name`, `kundennummer` FROM `adresse`
             WHERE `kundennummer` = :nr AND `geloescht` = 0
             ORDER BY `id` LIMIT 1',
            ['nr' => $kundennummer]
        );
        return empty($row) ? null : $row;
    }

    public function getAdresse(int $adresseId): ?array
    {
        if ($adresseId <= 0) {
            return null;
        }
        $row = $this->db->fetchRow(
            'SELECT `id`, `name`, `kundennummer` FROM `adresse` WHERE `id` = :id LIMIT 1',
            ['id' => $adresseId]
        );
        return empty($row) ? null : $row;
    }

    /**
     * Zieht die Belegnummer nach: bei der Anlage aus dem Ticket ist der Beleg
     * noch ein Entwurf ohne belegnr, die Nummer entsteht erst bei Freigabe.
     */
    public function updateBelegNr(int $id, string $belegNr): void
    {
        $this->db->perform(
            'UPDATE `repair_ticket_beleg` SET `beleg_nr` = :bnr WHERE `id` = :id',
            ['bnr' => $belegNr, 'id' => $id]
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
