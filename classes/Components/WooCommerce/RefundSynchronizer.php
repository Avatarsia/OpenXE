<?php
// SPDX-License-Identifier: AGPL-3.0-only

namespace Xentral\Components\WooCommerce;

use RuntimeException;

/** Read Woo refunds and book credit notes through Shopimport::Refund. */
final class RefundSynchronizer
{
    private $client;
    private $shopId;
    private $store;
    private $refund;
    private $preflight;

    public function __construct($client, $shopId, RefundStateStore $store, callable $refund, ?callable $preflight = null)
    {
        $this->client = $client;
        $this->shopId = $this->id($shopId, 'shop');
        $this->store = $store;
        $this->refund = $refund;
        $this->preflight = $preflight;
    }

    /**
     * @param object $order Complete Woo order, including its original line IDs.
     * @return array Source refund ID => ERP credit note ID, including replays.
     */
    public function sync($order)
    {
        $orderId = $this->id($order->id ?? null, 'order');
        return $this->store->withOrderLock($this->shopId, $orderId, function () use ($order, $orderId) {
            $refunds = $this->fetchRefunds($orderId);
            $result = [];
            foreach ($refunds as $refundId => $refund) {
                $state = $this->store->find($this->shopId, $orderId, $refundId);
                if ($state !== null) {
                    if ($state['status'] !== 'complete') {
                        throw new RuntimeException(
                            'WooCommerce refund ' . $refundId . ' is pending; inspect credit notes with source '
                            . $this->source($orderId, $refundId) . ' before manual recovery'
                        );
                    }
                    $result[$refundId] = (int) $state['credit_note_id'];
                    continue;
                }
                // Mapping failures are safe to retry because no ERP call has started.
                $data = $this->mapRefund($order, $orderId, $refundId, $refund);
                if ($this->preflight !== null) {
                    call_user_func($this->preflight, $data);
                }
                // Autocommit this intent BEFORE calling the non-transactional legacy ERP.
                // Exceptions and process death leave pending; never blindly rebook them.
                $this->store->start($this->shopId, $orderId, $refundId);
                $creditNoteId = call_user_func($this->refund, $data);
                if (!is_int($creditNoteId) || $creditNoteId <= 0) {
                    throw new RuntimeException('Refund callback did not return a valid credit note ID; journal remains pending');
                }
                $this->store->complete($this->shopId, $orderId, $refundId, $creditNoteId);
                $result[$refundId] = $creditNoteId;
            }
            return $result;
        });
    }

    private function fetchRefunds($orderId)
    {
        $refunds = [];
        for ($page = 1; ; $page++) {
            $rows = $this->client->get('orders/' . $orderId . '/refunds', [
                'per_page' => 100, 'page' => $page, 'orderby' => 'id', 'order' => 'asc',
            ]);
            if (!is_array($rows)) {
                throw new RuntimeException('Invalid WooCommerce refunds response');
            }
            $newIds = 0;
            foreach ($rows as $row) {
                $id = $this->id($row->id ?? null, 'refund');
                if (!isset($refunds[$id])) {
                    $newIds++;
                    $refunds[$id] = $row;
                }
            }
            if (count($rows) < 100) {
                break;
            }
            if ($newIds === 0) {
                throw new RuntimeException('WooCommerce refund pagination repeated a page');
            }
        }
        uksort($refunds, static function ($left, $right) {
            $left = (string) $left;
            $right = (string) $right;
            return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
        });
        return $refunds;
    }

    private function mapRefund($order, $orderId, $refundId, $refund)
    {
        $amount = $this->number($refund->amount ?? null, 'amount');
        if ($amount <= 0) {
            throw new RuntimeException('Refund amount must be positive');
        }
        if (empty($refund->line_items) || !is_array($refund->line_items)) {
            throw new RuntimeException('Refund has no supported positions; manual allocation required');
        }
        $sourceIds = [];
        foreach (['line_items', 'shipping_lines', 'fee_lines'] as $field) {
            foreach ($order->$field ?? [] as $line) {
                $sourceIds[$this->id($line->id ?? null, 'source line')] = true;
            }
        }
        $positions = [];
        $gross = 0.0;
        foreach ($refund->line_items as $line) {
            $sourceId = $line->id ?? null;
            foreach ($line->meta_data ?? [] as $meta) {
                if (($meta->key ?? '') === '_refunded_item_id') {
                    $sourceId = $meta->value;
                    break;
                }
            }
            $sourceId = $this->id($sourceId, 'source line');
            if (!isset($sourceIds[$sourceId]) || isset($positions[$sourceId])) {
                throw new RuntimeException('Refund source line is unknown or duplicated: ' . $sourceId);
            }
            $quantity = $this->number($line->quantity ?? null, 'quantity');
            if ($quantity >= 0) {
                throw new RuntimeException('Refund quantity must be negative; zero-quantity rows require manual allocation');
            }
            $net = $this->number($line->total ?? null, 'total');
            $tax = $this->number($line->total_tax ?? null, 'total_tax');
            if ($net > 0 || $tax > 0) {
                throw new RuntimeException('Refund totals must not be positive');
            }
            $lineGross = -($net + $tax);
            $gross += $lineGross;
            $positions[$sourceId] = (object) [
                'webid' => $sourceId, 'quantity' => -$quantity, 'price' => $lineGross / -$quantity,
            ];
        }
        if (abs(round($gross, 2) - round($amount, 2)) > 0.00001) {
            throw new RuntimeException('Refund amount differs from supported positions; manual allocation required');
        }
        return (object) [
            'extid' => $orderId, 'amount' => $amount, 'positions' => array_values($positions),
            'currency' => $order->currency ?? '', 'preserveinvoice' => true,
            'source' => $this->source($orderId, $refundId),
        ];
    }

    private function source($orderId, $refundId)
    {
        return 'woocommerce-refund:' . $this->shopId . ':' . $orderId . ':' . $refundId;
    }

    private function id($value, $field)
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]*$/D', (string) $value)) {
            throw new RuntimeException('Invalid WooCommerce ' . $field . ' ID');
        }
        return (string) $value;
    }

    private function number($value, $field)
    {
        if ((!is_string($value) && !is_int($value) && !is_float($value))
            || !preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D', (string) $value) || !is_finite((float) $value)) {
            throw new RuntimeException('Invalid WooCommerce refund ' . $field);
        }
        return (float) $value;
    }
}
