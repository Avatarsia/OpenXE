<?php
declare(strict_types=1);

namespace Xentral\Modules\RepairIntegration\Search;

use Xentral\Components\Database\Database;

final class RepairSearchProvider
{
    public function __construct(
        private readonly Database $db,
    ) {}

    public function getIndexName(): string
    {
        return 'repairs';
    }

    public function getIndexTitle(): string
    {
        return 'Reparaturen';
    }

    public function getModuleName(): string
    {
        return 'repairintegration';
    }

    public function getItems(): iterable
    {
        $rows = $this->db->fetchAll(
            "SELECT
                t.id,
                t.schluessel,
                t.betreff,
                t.kunde,
                t.mailadresse,
                rd.manufacturer,
                rd.model,
                rd.serial_number,
                rd.service_type,
                COALESCE(sc.label_de, t.status) as status_label
             FROM `ticket` t
             INNER JOIN `ticket_repair_details` rd ON rd.ticket_id = t.id
             LEFT JOIN `ticket_status_config` sc ON sc.slug = t.status
             WHERE rd.anonymized_at IS NULL"
        );

        foreach ($rows as $row) {
            yield [
                'title' => sprintf(
                    '#%s — %s %s',
                    $row['schluessel'],
                    $row['manufacturer'] ?? '',
                    $row['model'] ?? '',
                ),
                'subtitle' => sprintf(
                    '%s | %s | %s',
                    $row['kunde'] ?? '',
                    ucfirst($row['service_type'] ?? ''),
                    $row['status_label'] ?? '',
                ),
                'link' => sprintf(
                    'index.php?module=ticket&action=edit&id=%d',
                    (int)$row['id'],
                ),
                'search_words' => implode(' ', array_filter([
                    $row['schluessel'],
                    $row['kunde'],
                    $row['mailadresse'],
                    $row['manufacturer'],
                    $row['model'],
                    $row['serial_number'] ?? '',
                    $row['betreff'],
                    $row['service_type'],
                ])),
            ];
        }
    }
}
