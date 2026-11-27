<?php
declare(strict_types=1);

namespace Xentral\Modules\RepairIntegration\Gateway;

use Xentral\Components\Database\Database;
use Xentral\Modules\RepairIntegration\Service\RepairStatusModel;

final class RepairStatusConfigGateway
{
    public function __construct(
        private readonly Database $db,
    ) {}

    /**
     * Gibt alle aktiven Status zurueck: general + optional service-type-spezifische.
     * Sortiert nach sort_order.
     */
    public function getActiveStatuses(?string $serviceTypeCategory = null): array
    {
        if ($serviceTypeCategory !== null) {
            return $this->db->fetchAll(
                "SELECT * FROM `ticket_status_config`
                 WHERE `is_active` = 1
                   AND (`category` = 'general' OR `category` = :cat)
                 ORDER BY `sort_order` ASC",
                ['cat' => $serviceTypeCategory]
            );
        }

        return $this->db->fetchAll(
            "SELECT * FROM `ticket_status_config`
             WHERE `is_active` = 1
             ORDER BY `sort_order` ASC"
        );
    }

    public function getBySlug(string $slug): ?array
    {
        $row = $this->db->fetchRow(
            'SELECT * FROM `ticket_status_config` WHERE `slug` = :slug',
            ['slug' => $slug]
        );
        return $row ?: null;
    }

    public function getWpMapping(string $oxeStatus): ?string
    {
        $value = $this->db->fetchValue(
            'SELECT `wp_status_mapping` FROM `ticket_status_config` WHERE `slug` = :slug',
            ['slug' => $oxeStatus]
        );
        return ($value !== false && $value !== null && $value !== '') ? RepairStatusModel::normalize((string)$value) : null;
    }

    /**
     * Rueckwaerts-Lookup: WP-Status-Slug -> OpenXE-Status-Zeile.
     *
     * Ein WP-Slug ist mehrdeutig (z.B. 'in_progress' existiert fuer repair,
     * maintenance, reverse_engineering und individualization), daher wird
     * ueber die Kategorie des Service-Typs aufgeloest
     * (siehe ServiceType::statusCategory()). Kategorie-spezifische Zeilen
     * gewinnen gegen 'general' (z.B. 'new' -> 'neu', 'closed' -> 'abgeschlossen').
     *
     * @return array<string, mixed>|null
     */
    public function getByWpMapping(string $wpStatus, string $category, ?string $currentStatus = null): ?array
    {
        $wpStatus = RepairStatusModel::normalize($wpStatus);
        if ($currentStatus !== null && $this->getWpMapping($currentStatus) === $wpStatus) {
            return $this->getBySlug($currentStatus);
        }
        $defaults = [
            'new' => 'neu', 'received' => 'eingegangen', 'cancelled' => 'storniert', 'closed' => 'abgeschlossen',
            'in_progress' => ['repair'=>'in_diagnose', 'maintenance'=>'wartung_laeuft',
                'reverse_engineering'=>'re_analyse', 'individualization'=>'ind_planung'],
            'completed' => ['repair'=>'repariert', 'maintenance'=>'wartung_fertig',
                'reverse_engineering'=>'re_fertig', 'individualization'=>'ind_fertig'],
            'quote_sent' => ['repair'=>'kv_gesendet', 'reverse_engineering'=>'re_angebot', 'individualization'=>'ind_angebot'],
        ];
        $default = $defaults[$wpStatus] ?? null;
        if (is_array($default)) $default = $default[$category] ?? null;
        if ($default !== null) {
            $row = $this->getBySlug($default);
            if ($row !== null && (int)($row['is_active'] ?? 0) === 1 && ($row['wp_status_mapping'] ?? null) === $wpStatus) return $row;
        }
        $row = $this->db->fetchRow(
            "SELECT * FROM `ticket_status_config`
             WHERE `wp_status_mapping` = :wp
               AND `is_active` = 1
               AND (`category` = :cat OR `category` = 'general')
             ORDER BY (`category` = 'general') ASC, `sort_order` ASC
             LIMIT 1",
            ['wp' => $wpStatus, 'cat' => $category]
        );
        return $row ?: null;
    }

    public function getNextStatus(string $currentSlug): ?string
    {
        $value = $this->db->fetchValue(
            'SELECT `next_status_slug` FROM `ticket_status_config` WHERE `slug` = :slug',
            ['slug' => $currentSlug]
        );
        return ($value !== false && $value !== null && $value !== '') ? (string)$value : null;
    }

    public function getAllStatuses(): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM `ticket_status_config` ORDER BY `sort_order` ASC'
        );
    }

    public function isTerminal(string $slug): bool
    {
        $value = $this->db->fetchValue(
            'SELECT `is_terminal` FROM `ticket_status_config` WHERE `slug` = :slug',
            ['slug' => $slug]
        );
        return $value !== false && (int)$value === 1;
    }

    public function shouldNotifyCustomer(string $slug): bool
    {
        $value = $this->db->fetchValue(
            'SELECT `notify_customer` FROM `ticket_status_config` WHERE `slug` = :slug',
            ['slug' => $slug]
        );
        return $value !== false && (int)$value === 1;
    }

    public function getEmailTemplate(string $slug): ?string
    {
        $value = $this->db->fetchValue(
            'SELECT `email_template` FROM `ticket_status_config` WHERE `slug` = :slug',
            ['slug' => $slug]
        );
        return ($value !== false && $value !== null && $value !== '') ? (string)$value : null;
    }
}
