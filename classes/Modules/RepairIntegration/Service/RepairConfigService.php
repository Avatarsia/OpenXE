<?php
declare(strict_types=1);

namespace Xentral\Modules\RepairIntegration\Service;

use Xentral\Components\Database\Database;

final class RepairConfigService
{
    private const CONFIG_NAMESPACE = 'repair_integration'; // @php83: add type string

    public function __construct(
        private readonly Database $db,
    ) {}

    public function get(string $key, string $default = ''): string
    {
        $value = $this->db->fetchValue(
            "SELECT `value` FROM `systemconfig` WHERE `namespace` = :ns AND `key` = :key",
            ['ns' => self::CONFIG_NAMESPACE, 'key' => $key]
        );
        return ($value !== false && $value !== null) ? (string)$value : $default;
    }

    public function set(string $key, string $value): void
    {
        $this->db->perform(
            "INSERT INTO `systemconfig` (`namespace`, `key`, `value`)
             VALUES (:ns, :key, :val)
             ON DUPLICATE KEY UPDATE `value` = :val2",
            ['ns' => self::CONFIG_NAMESPACE, 'key' => $key, 'val' => $value, 'val2' => $value]
        );
    }

    public function isEnabled(): bool
    {
        return $this->get('enabled', '0') === '1';
    }

    public function getWpApiUrl(): string
    {
        return rtrim($this->get('wp_api_url'), '/');
    }

    public function getWpApiKey(): string
    {
        return $this->get('wp_api_key');
    }

    public function getInboundSharedSecret(): string
    {
        return $this->get('inbound_shared_secret');
    }

    public function getMaxRetries(): int
    {
        return (int)$this->get('max_retries', '5');
    }

    public function getRetentionAnonymizeYears(): int
    {
        return (int)$this->get('retention_anonymize_years', '8');
    }

    public function getNotifyOnPermanentFailEmail(): string
    {
        return $this->get('notify_on_permanent_fail');
    }

    public function getAllowedIps(): array
    {
        $ips = $this->get('allowed_ips');
        if ($ips === '') {
            return [];
        }
        return array_map('trim', explode(',', $ips));
    }
}
