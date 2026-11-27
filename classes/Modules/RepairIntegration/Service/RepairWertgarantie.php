<?php
declare(strict_types=1);

namespace Xentral\Modules\RepairIntegration\Service;

use Xentral\Components\Database\Database;
use Xentral\Modules\RepairIntegration\Gateway\RepairDetailsGateway;

/** Optional WP metadata: absent keeps the value, null clears it, invalid is ignored. */
final class RepairWertgarantie
{
    public static function map(array $source): array
    {
        if (!array_key_exists('wertgarantie', $source)) return [];
        $block = $source['wertgarantie'];
        if ($block === null) return ['wertgarantie' => null];
        if (!is_array($block)
            || !is_string($block['contract_number'] ?? null) || !preg_match('/^[0-9]{1,64}$/D', $block['contract_number'])
            || !is_string($block['status'] ?? null) || !preg_match('/^[a-z_]{1,32}$/D', $block['status'])
            || !is_string($block['status_label'] ?? null) || mb_strlen($block['status_label'], 'UTF-8') > 255
            || !mb_check_encoding($block['status_label'], 'UTF-8')
            || !array_key_exists('checked_at', $block) || !is_bool($block['live'] ?? null)) {
            error_log('RepairIntegration: invalid wertgarantie block ignored');
            return [];
        }
        if ($block['checked_at'] !== null) {
            try {
                if (!is_string($block['checked_at']) || trim($block['checked_at']) === '') throw new \InvalidArgumentException();
                new \DateTimeImmutable($block['checked_at']);
                $errors = \DateTimeImmutable::getLastErrors();
                if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) throw new \InvalidArgumentException();
            } catch (\Throwable $e) {
                error_log('RepairIntegration: invalid wertgarantie checked_at ignored');
                return [];
            }
        }
        return ['wertgarantie' => json_encode(array_intersect_key($block, array_flip(
            ['contract_number', 'status', 'status_label', 'checked_at', 'live'])), JSON_THROW_ON_ERROR)];
    }

    public static function apply(Database $db, int $ticketId, array $source): void
    {
        $mapped = self::map($source);
        if ($mapped === []) return;
        $gateway = new RepairDetailsGateway($db);
        $details = $gateway->getByTicketId($ticketId);
        if ($details !== null) $gateway->update((int)$details['id'], $mapped);
    }

    public static function render(?string $json): string
    {
        $block = json_decode($json ?? '', true);
        if (!is_array($block)) return '';
        $recognized = in_array($block['status'] ?? '', ['verified', 'verified_pending'], true);
        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<p><span class="repair-wg-badge' . ($recognized ? ' repair-wg-recognized' : '')
            . '">Wertgarantie-Reparatur</span> <strong>' . $escape((string)($block['status_label'] ?? ''))
            . '</strong> &middot; Vertragsnummer: ' . $escape((string)($block['contract_number'] ?? '')) . '</p>';
    }
}
