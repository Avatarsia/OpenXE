<?php
// SPDX-License-Identifier: AGPL-3.0-only

namespace Xentral\Components\WooCommerce;

use RuntimeException;

/** Durable, fail-closed refund journal in the existing configuration table. */
final class RefundStateStore
{
    private $db;

    public function __construct($database)
    {
        $this->db = $database;
    }

    public function withOrderLock($shopId, $orderId, callable $work)
    {
        if ($this->db->inTransaction()) {
            throw new RuntimeException('Refund journal requires an autocommit connection');
        }
        $lock = 'wc-refund:' . hash('sha256', $shopId . ':' . $orderId);
        // MySQL lock names are limited to 64 characters.
        $lock = substr($lock, 0, 64);
        if ((int) $this->db->fetchValue('SELECT GET_LOCK(:lock, 0)', ['lock' => $lock]) !== 1) {
            throw new RuntimeException('WooCommerce refund order is locked by another worker');
        }
        try {
            return $work();
        } finally {
            $this->db->fetchValue('SELECT RELEASE_LOCK(:lock)', ['lock' => $lock]);
        }
    }

    public function find($shopId, $orderId, $refundId)
    {
        $value = $this->db->fetchValue(
            'SELECT wert FROM konfiguration WHERE name = :name',
            ['name' => $this->key($shopId, $orderId, $refundId)]
        );
        if ($value === false || $value === null) {
            return null;
        }
        $state = json_decode((string) $value, true);
        if (!is_array($state) || !isset($state['status'])
            || !in_array($state['status'], ['pending', 'complete'], true)
            || ($state['status'] === 'complete'
                && (!isset($state['credit_note_id']) || !is_int($state['credit_note_id']) || $state['credit_note_id'] <= 0))) {
            throw new RuntimeException('Invalid WooCommerce refund journal; manual recovery required');
        }
        return $state;
    }

    public function start($shopId, $orderId, $refundId)
    {
        $this->db->perform(
            'INSERT INTO konfiguration (name, wert, adresse, firma) VALUES (:name, :value, 0, 0)',
            [
                'name' => $this->key($shopId, $orderId, $refundId),
                'value' => json_encode(['status' => 'pending']),
            ]
        );
    }

    public function complete($shopId, $orderId, $refundId, $creditNoteId)
    {
        $this->db->perform(
            'UPDATE konfiguration SET wert = :value WHERE name = :name',
            [
                'name' => $this->key($shopId, $orderId, $refundId),
                'value' => json_encode(['status' => 'complete', 'credit_note_id' => $creditNoteId]),
            ]
        );
        $state = $this->find($shopId, $orderId, $refundId);
        if ($state === null || $state['status'] !== 'complete' || (int) $state['credit_note_id'] !== $creditNoteId) {
            throw new RuntimeException('WooCommerce refund completion was not persisted');
        }
    }

    private function key($shopId, $orderId, $refundId)
    {
        return 'woocommerce_refund:' . $shopId . ':' . $orderId . ':' . $refundId;
    }
}
