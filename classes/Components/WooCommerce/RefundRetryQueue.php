<?php
// SPDX-License-Identifier: AGPL-3.0-only

namespace Xentral\Components\WooCommerce;

use RuntimeException;
use Throwable;

/** Durable source-order IDs awaiting refund reconciliation, isolated from the order feed. */
final class RefundRetryQueue
{
    private $db;
    private $shopId;
    private $clock;

    public function __construct($database, $shopId, ?callable $clock = null)
    {
        $this->db = $database;
        $this->shopId = $this->id($shopId);
        $this->clock = $clock ?? static function () { return time(); };
    }

    public function enqueue($orderId)
    {
        $orderId = $this->id($orderId);
        $this->locked(function () use ($orderId) {
            $name = $this->prefix() . $orderId;
            $value = $this->db->fetchValue('SELECT wert FROM konfiguration WHERE name = :name', ['name' => $name]);
            if ($value !== false && $value !== null) {
                return; // Replayed events must not reset a failing order's backoff.
            }
            $this->db->perform(
                'INSERT INTO konfiguration (name, wert, adresse, firma) VALUES (:name, :value, 0, 0)',
                ['name' => $name, 'value' => json_encode(['attempts' => 0, 'next_attempt' => 0])]
            );
        });
    }

    /** Schedule an existing marker; financial refund journals are never changed here. */
    public function retry($orderId): bool
    {
        $orderId = $this->id($orderId);
        return $this->locked(function () use ($orderId) {
            $name = $this->prefix() . $orderId;
            $value = $this->db->fetchValue('SELECT wert FROM konfiguration WHERE name = :name', ['name' => $name]);
            if ($value === false || $value === null) {
                return false;
            }
            $state = json_decode((string) $value, true);
            if (!is_array($state)) {
                $state = ['attempts' => 0];
            }
            $state['next_attempt'] = 0;
            $this->db->perform('UPDATE konfiguration SET wert = :value WHERE name = :name', [
                'name' => $name, 'value' => json_encode($state),
            ]);
            return true;
        });
    }

    /** Read-only scheduling hint; no marker is claimed or changed here. */
    public function hasDue(): bool
    {
        $now = (int) call_user_func($this->clock);
        foreach ($this->load() as $row) {
            if ($row['next_attempt'] <= $now) {
                return true;
            }
        }
        return false;
    }

    /** @return array{processed:array,failed:array} Callback receives only the Woo order ID. */
    public function drain(callable $process, $limit = 10)
    {
        $limit = max(0, min(10, (int) $limit));
        return $this->locked(function () use ($process, $limit) {
            $result = ['processed' => [], 'failed' => []];
            $now = (int) call_user_func($this->clock);
            $attempted = 0;
            foreach ($this->load() as $row) {
                if ($row['next_attempt'] > $now || $attempted >= $limit) {
                    continue;
                }
                $attempted++;
                $orderId = $row['order_id'];
                try {
                    $process($orderId);
                    $this->db->perform('DELETE FROM konfiguration WHERE name = :name', ['name' => $row['name']]);
                    $result['processed'][] = $orderId;
                } catch (Throwable $error) {
                    $attempts = min(30, $row['attempts'] + 1);
                    $delay = (int) min(3600, 60 * (2 ** min(6, $attempts - 1)));
                    $result['failed'][$orderId] = RefundStatusService::sanitizeError($error->getMessage());
                    try {
                        $this->db->perform(
                            'UPDATE konfiguration SET wert = :value WHERE name = :name',
                            [
                                'name' => $row['name'],
                                'value' => json_encode([
                                    'attempts' => $attempts,
                                    'next_attempt' => (int) call_user_func($this->clock) + $delay,
                                    'last_attempt' => $now,
                                    'last_error' => RefundStatusService::sanitizeError($error->getMessage()),
                                ]),
                            ]
                        );
                    } catch (Throwable $storageError) {
                        // The original durable marker remains retryable even if backoff cannot be saved.
                        $result['failed'][$orderId] = RefundStatusService::sanitizeError(
                            $result['failed'][$orderId] . '; retry journal update failed: ' . $storageError->getMessage()
                        );
                    }
                }
            }
            return $result;
        });
    }

    private function load()
    {
        $prefix = $this->prefix();
        $rows = $this->db->fetchAll(
            'SELECT name, wert FROM konfiguration WHERE name LIKE :prefix ORDER BY name',
            ['prefix' => $prefix . '%']
        );
        $entries = [];
        foreach ($rows as $row) {
            // LIKE underscores can match other names; verify the literal prefix as well.
            if (strpos($row['name'], $prefix) !== 0) {
                continue;
            }
            $orderId = $this->id(substr($row['name'], strlen($prefix)));
            $state = json_decode($row['wert'], true);
            // Damaged scheduling metadata can safely be retried: financial idempotency
            // lives in the separate refund journal and is never reset here.
            if (!is_array($state) || !isset($state['attempts'], $state['next_attempt'])
                || !is_int($state['attempts']) || $state['attempts'] < 0
                || !is_int($state['next_attempt']) || $state['next_attempt'] < 0) {
                $state = ['attempts' => 0, 'next_attempt' => 0];
            }
            $entries[] = [
                'name' => $row['name'], 'order_id' => $orderId,
                'attempts' => $state['attempts'], 'next_attempt' => $state['next_attempt'],
            ];
        }
        usort($entries, static function ($left, $right) {
            return $left['next_attempt'] <=> $right['next_attempt']
                ?: strlen($left['order_id']) <=> strlen($right['order_id'])
                ?: strcmp($left['order_id'], $right['order_id']);
        });
        return $entries;
    }

    private function locked(callable $work)
    {
        if ($this->db->inTransaction()) {
            throw new RuntimeException('Refund retry queue requires an autocommit connection');
        }
        $lock = 'wc-refund-retry:' . $this->shopId;
        if ((int) $this->db->fetchValue('SELECT GET_LOCK(:lock, 0)', ['lock' => $lock]) !== 1) {
            throw new RuntimeException('WooCommerce refund retry queue is locked by another worker');
        }
        try {
            return $work();
        } finally {
            $this->db->fetchValue('SELECT RELEASE_LOCK(:lock)', ['lock' => $lock]);
        }
    }

    private function prefix()
    {
        return 'woocommerce_refund_retry:' . $this->shopId . ':';
    }

    private function id($value)
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]{0,19}$/D', (string) $value)) {
            throw new RuntimeException('Invalid WooCommerce refund retry source ID');
        }
        return (string) $value;
    }
}
