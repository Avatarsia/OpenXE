<?php
declare(strict_types=1);

namespace Xentral\Modules\RepairIntegration\Gateway;

use Xentral\Components\Database\Database;

final class RepairDetailsGateway
{
    public function __construct(
        private readonly Database $db,
    ) {}

    public function getByTicketId(int $ticketId): ?array
    {
        $row = $this->db->fetchRow(
            'SELECT * FROM `ticket_repair_details` WHERE `ticket_id` = :ticket_id',
            ['ticket_id' => $ticketId]
        );
        return $row ?: null;
    }

    public function getByTicketSchluessel(string $schluessel): ?array
    {
        $row = $this->db->fetchRow(
            'SELECT * FROM `ticket_repair_details` WHERE `ticket_schluessel` = :key',
            ['key' => $schluessel]
        );
        return $row ?: null;
    }

    public function getByWpRequestNumber(string $wpNumber): ?array
    {
        $row = $this->db->fetchRow(
            'SELECT * FROM `ticket_repair_details` WHERE `wp_request_number` = :nr',
            ['nr' => $wpNumber]
        );
        return $row ?: null;
    }

    public function create(array $data): int
    {
        $columns = [
            'ticket_id', 'ticket_schluessel', 'wp_request_number',
            'service_type', 'service_delivery_type', 'customer_type',
            'company_name', 'vat_id', 'manufacturer', 'model', 'serial_number',
            'mods_present', 'mods_text', 'issue_category', 'issue_description',
            'wartung_paket', 'wartung_notes', 'has_original_part', 'has_templates',
            're_tolerance', 're_output_format', 'has_3d_file', 'material_preference',
            'color_preference', 'functional_requirements', 'warranty_status',
            'invoice_number', 'purchase_date', 'seller', 'cost_limit',
            'is_express', 'express_price', 'travel_distance_km', 'travel_fee',
        ];

        $filteredData = array_intersect_key($data, array_flip($columns));
        if (!array_key_exists('ticket_id', $filteredData)) {
            throw new \InvalidArgumentException('ticket_id is required');
        }

        $cols = implode('`, `', array_keys($filteredData));
        $placeholders = implode(', ', array_map(
            static fn(string $k): string => ':' . $k,
            array_keys($filteredData)
        ));

        $this->db->perform(
            "INSERT INTO `ticket_repair_details` (`{$cols}`) VALUES ({$placeholders})",
            $filteredData
        );

        return (int)$this->db->fetchValue('SELECT LAST_INSERT_ID()');
    }

    public function update(int $id, array $data): void
    {
        $allowed = [
            'service_type', 'service_delivery_type', 'manufacturer', 'model',
            'serial_number', 'mods_present', 'mods_text', 'issue_category',
            'issue_description', 'wartung_paket', 'wartung_notes',
            'has_original_part', 'has_templates', 're_tolerance', 're_output_format',
            'has_3d_file', 'material_preference', 'color_preference',
            'functional_requirements', 'warranty_status', 'invoice_number',
            'purchase_date', 'seller', 'cost_limit', 'is_express', 'express_price',
            'travel_distance_km', 'travel_fee', 'wp_request_number',
        ];

        $filteredData = array_intersect_key($data, array_flip($allowed));
        if (empty($filteredData)) {
            return;
        }

        $setParts = array_map(
            static fn(string $k): string => "`{$k}` = :{$k}",
            array_keys($filteredData)
        );
        $filteredData['id'] = $id;

        $this->db->perform(
            'UPDATE `ticket_repair_details` SET ' . implode(', ', $setParts) . ' WHERE `id` = :id',
            $filteredData
        );
    }

    public function updateDiagnosisFields(
        int $ticketId,
        ?string $diagnosisResult,
        ?string $quoteAmount,
        ?string $actualCost,
        ?string $repairNotes,
    ): void {
        $this->db->perform(
            'UPDATE `ticket_repair_details` SET
                `diagnosis_result` = :diagnosis,
                `quote_amount` = :quote,
                `actual_cost` = :cost,
                `repair_notes` = :notes
             WHERE `ticket_id` = :ticket_id',
            [
                'diagnosis' => $diagnosisResult,
                'quote' => $quoteAmount,
                'cost' => $actualCost,
                'notes' => $repairNotes,
                'ticket_id' => $ticketId,
            ]
        );
    }

    public function markAnonymized(int $id): void
    {
        $this->db->perform(
            'UPDATE `ticket_repair_details` SET `anonymized_at` = NOW() WHERE `id` = :id',
            ['id' => $id]
        );
    }

    public function getUnanonymizedExpired(string $cutoffDate, int $limit = 100): array
    {
        return $this->db->fetchAll(
            'SELECT rd.id, rd.ticket_id, rd.ticket_schluessel
             FROM `ticket_repair_details` rd
             INNER JOIN `ticket` t ON t.id = rd.ticket_id
             INNER JOIN `ticket_status_config` sc ON sc.slug = t.status
             WHERE sc.is_terminal = 1
               AND t.zeit < :cutoff
               AND rd.anonymized_at IS NULL
             LIMIT ' . (int)$limit,
            ['cutoff' => $cutoffDate]
        );
    }

    public function moveToTicket(int $sourceTicketId, int $targetTicketId, string $targetSchluessel): void
    {
        $this->db->perform(
            'UPDATE `ticket_repair_details`
             SET `ticket_id` = :target_id, `ticket_schluessel` = :target_key
             WHERE `ticket_id` = :source_id',
            [
                'target_id' => $targetTicketId,
                'target_key' => $targetSchluessel,
                'source_id' => $sourceTicketId,
            ]
        );
    }

    public function deleteByTicketId(int $ticketId): void
    {
        $this->db->perform(
            'DELETE FROM `ticket_repair_details` WHERE `ticket_id` = :ticket_id',
            ['ticket_id' => $ticketId]
        );
    }
}
