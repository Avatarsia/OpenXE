<?php
declare(strict_types=1);

namespace Xentral\Modules\RepairIntegration\Service;

use Xentral\Components\Database\Database;
use Xentral\Modules\RepairIntegration\Gateway\RepairDetailsGateway;

/** Optional WP metadata: absent keeps the value, null clears it, invalid is ignored. */
final class RepairShippingFeedback
{
    public static function map(array $source): array
    {
        if (!array_key_exists('shipping_feedback', $source)) return [];
        $block = $source['shipping_feedback'];
        if ($block === null) return ['shipping_feedback' => null];
        if (!is_array($block)
            || !in_array($block['choice'] ?? null, ['shipped', 'later', 'cancelled'], true)
            || !self::validLabel($block['choice_label'] ?? null)
            || !array_key_exists('responded_at', $block)
            || !array_key_exists('tracking_number', $block)
            || !array_key_exists('carrier', $block)
            || !array_key_exists('carrier_label', $block)) {
            error_log('RepairIntegration: invalid shipping_feedback block ignored');
            return [];
        }
        if ($block['choice'] === 'shipped') {
            // Tracking number and carrier are optional for the customer (WP sends null when empty).
            if (($block['tracking_number'] !== null
                    && (!is_string($block['tracking_number']) || !preg_match('/^[A-Z0-9 -]{0,64}$/D', $block['tracking_number'])))
                || ($block['carrier'] === null && $block['carrier_label'] !== null)
                || ($block['carrier'] !== null
                    && (!in_array($block['carrier'], ['dhl', 'dpd', 'hermes', 'gls', 'ups', 'post', 'other'], true)
                        || !self::validLabel($block['carrier_label'])))) {
                error_log('RepairIntegration: invalid shipping_feedback shipping fields ignored');
                return [];
            }
        } elseif ($block['tracking_number'] !== null || $block['carrier'] !== null || $block['carrier_label'] !== null) {
            error_log('RepairIntegration: invalid shipping_feedback shipping fields ignored');
            return [];
        }
        if ($block['responded_at'] !== null) {
            try {
                if (!is_string($block['responded_at'])
                    || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}[Tt][0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]+)?(?:[Zz]|[+-](?:[01][0-9]|2[0-3]):[0-5][0-9])$/D', $block['responded_at'])) throw new \InvalidArgumentException();
                new \DateTimeImmutable($block['responded_at']);
                $errors = \DateTimeImmutable::getLastErrors();
                if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) throw new \InvalidArgumentException();
            } catch (\Throwable $e) {
                error_log('RepairIntegration: invalid shipping_feedback responded_at ignored');
                return [];
            }
        }
        return ['shipping_feedback' => json_encode(array_intersect_key($block, array_flip(
            ['choice', 'choice_label', 'responded_at', 'tracking_number', 'carrier', 'carrier_label'])), JSON_THROW_ON_ERROR)];
    }

    private static function validLabel($value): bool
    {
        return is_string($value) && mb_check_encoding($value, 'UTF-8') && mb_strlen($value, 'UTF-8') <= 255;
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
        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<p><strong>Versand-Rückmeldung: ' . $escape((string)($block['choice_label'] ?? ''))
            . '</strong> &middot; Datum: ' . $escape((string)($block['responded_at'] ?? ''));
        if (($block['choice'] ?? '') === 'shipped') {
            if ((string)($block['carrier_label'] ?? '') !== '') {
                $html .= ' &middot; Versanddienstleister: ' . $escape((string)$block['carrier_label']);
            }
            if ((string)($block['tracking_number'] ?? '') !== '') {
                $html .= ' &middot; Sendungsnummer: ' . $escape((string)$block['tracking_number']);
            }
        }
        return $html . '</p>';
    }
}
