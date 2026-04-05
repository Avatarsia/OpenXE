<?php
declare(strict_types=1);

namespace Xentral\Modules\RepairIntegration\Gateway;

use Xentral\Components\Database\Database;

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
        return ($value !== false && $value !== null && $value !== '') ? (string)$value : null;
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
