<?php
// SPDX-License-Identifier: AGPL-3.0-only

namespace Xentral\Components\WooCommerce;

use RuntimeException;

/** Shop-scoped administrative view; retry only changes scheduling, never financial journals. */
final class RefundStatusService
{
    private $db;
    private $shopId;
    private $clock;

    public function __construct($database, $shopId, ?callable $clock = null)
    {
        if ((!is_int($shopId) && !is_string($shopId))
            || !preg_match('/^[1-9][0-9]{0,19}$/D', (string) $shopId)) {
            throw new RuntimeException('Invalid WooCommerce refund status shop ID');
        }
        $this->db = $database;
        $this->shopId = (string) $shopId;
        $this->clock = $clock ?? static function () { return time(); };
    }

    public function page($page = 1, $perPage = 25): array
    {
        $perPage = max(1, min(100, (int) $perPage));
        $page = max(1, (int) $page);
        $params = [
            'journal' => 'woocommerce!_refund:' . $this->shopId . ':%',
            'queue' => 'woocommerce!_refund!_retry:' . $this->shopId . ':%',
        ];
        $where = " WHERE name LIKE :journal ESCAPE '!' OR name LIKE :queue ESCAPE '!'";
        $total = (int) $this->db->fetchValue('SELECT COUNT(*) FROM konfiguration' . $where, $params);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $params['limit'] = $perPage;
        $params['offset'] = ($page - 1) * $perPage;
        $entries = $this->db->fetchAll(
            'SELECT name, wert FROM konfiguration' . $where . ' ORDER BY name LIMIT :limit OFFSET :offset',
            $params
        );
        $rows = [];
        foreach ($entries as $entry) {
            $rows[] = $this->row($entry);
        }
        return ['rows' => $rows, 'page' => $page, 'per_page' => $perPage, 'total' => $total, 'pages' => $pages];
    }

    public function retry($orderId): bool
    {
        return (new RefundRetryQueue($this->db, $this->shopId, $this->clock))->retry($orderId);
    }

    /** Reduce transport errors to bounded plain text without URLs, headers or credential values. */
    public static function sanitizeError($message): string
    {
        $message = (string) $message;
        if (!preg_match('//u', $message)) {
            return 'Fehlerdetails nicht verfuegbar.';
        }
        $message = preg_replace('~https?://[^\s<>"\']+~iu', '[URL entfernt]', $message);
        $message = preg_replace('/\b(?:authorization|proxy-authorization|cookie|set-cookie)[\"\']?\s*[:=][^\r\n]*/iu', '[Header entfernt]', $message);
        $message = preg_replace('/\b(?:consumer_key|consumer_secret|api[_-]?key|access[_-]?token|refresh[_-]?token|token|password|passwd|secret)\b[\"\']?\s*[:=]\s*(?:"[^"]*"|\'[^\']*\'|[^\s,;]+)/iu', '[Zugangsdaten entfernt]', $message);
        $message = preg_replace('/\b(?:Bearer|Basic)\s+\S+/iu', '[Zugangsdaten entfernt]', $message);
        $message = preg_replace('/\b(?:ck|cs)_[a-z0-9]+\b/i', '[Zugangsdaten entfernt]', $message);
        $message = preg_replace('/\b[a-z0-9_+\/=.-]{32,}\b/i', '[Wert entfernt]', $message);
        $message = trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', strip_tags($message)));
        preg_match('/^.{0,500}/us', $message, $match);
        return $match[0] ?? '';
    }

    private function row(array $entry): array
    {
        $row = [
            'type' => 'refund', 'order_id' => '', 'refund_id' => null, 'credit_note_id' => null,
            'status' => 'invalid', 'attempts' => 0, 'next_attempt' => null, 'last_attempt' => null,
            'last_error' => '', 'retry_available' => false, 'due' => false,
        ];
        $state = json_decode((string) $entry['wert'], true);
        $journalPrefix = 'woocommerce_refund:' . $this->shopId . ':';
        $queuePrefix = 'woocommerce_refund_retry:' . $this->shopId . ':';
        if (strpos($entry['name'], $queuePrefix) === 0) {
            $row['type'] = 'queue';
            $orderId = substr($entry['name'], strlen($queuePrefix));
            if (!preg_match('/^[1-9][0-9]{0,19}$/D', $orderId)) {
                return $row;
            }
            $row['order_id'] = $orderId;
            if (!is_array($state) || !isset($state['attempts'], $state['next_attempt'])
                || !is_int($state['attempts']) || $state['attempts'] < 0
                || !is_int($state['next_attempt']) || $state['next_attempt'] < 0) {
                return $row;
            }
            $row['status'] = $state['attempts'] > 0 ? 'retry' : 'queued';
            $row['attempts'] = $state['attempts'];
            $row['next_attempt'] = $state['next_attempt'];
            $row['last_attempt'] = isset($state['last_attempt']) && is_int($state['last_attempt']) && $state['last_attempt'] >= 0
                ? $state['last_attempt'] : null;
            $row['last_error'] = self::sanitizeError(is_string($state['last_error'] ?? null) ? $state['last_error'] : '');
            $row['retry_available'] = true;
            $row['due'] = $row['next_attempt'] <= (int) call_user_func($this->clock);
        } elseif (strpos($entry['name'], $journalPrefix) === 0) {
            if (!preg_match('/^([1-9][0-9]{0,19}):([1-9][0-9]{0,19})$/D', substr($entry['name'], strlen($journalPrefix)), $ids)) {
                return $row;
            }
            $row['order_id'] = $ids[1];
            $row['refund_id'] = $ids[2];
            if (!is_array($state) || !isset($state['status'])) {
                return $row;
            }
            if ($state['status'] === 'pending') {
                $row['status'] = 'pending';
            } elseif ($state['status'] === 'complete' && isset($state['credit_note_id'])
                && is_int($state['credit_note_id']) && $state['credit_note_id'] > 0) {
                $row['status'] = 'complete';
                $row['credit_note_id'] = $state['credit_note_id'];
            }
        }
        return $row;
    }
}
