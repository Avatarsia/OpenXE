<?php
// SPDX-License-Identifier: AGPL-3.0-only

namespace Xentral\Components\WooCommerce;

use RuntimeException;

/** Read-only ERP checks before the irreversible refund callback can start. */
final class RefundPreflight
{
    private $db;
    private $shopId;

    public function __construct($database, $shopId)
    {
        if (!preg_match('/^[1-9][0-9]*$/D', (string) $shopId)) {
            throw new RuntimeException('Invalid WooCommerce refund shop ID');
        }
        $this->db = $database;
        $this->shopId = (string) $shopId;
    }

    public function validate($data)
    {
        $order = $this->db->fetchRow(
            'SELECT a.id FROM auftrag AS a INNER JOIN shopexport AS s ON s.id = a.shop '
            . 'WHERE a.shop = :shop AND a.shopextid = :extid AND a.shopextid <> :empty '
            . 'AND a.status <> :cancelled AND s.aktiv = 1 LIMIT 1',
            ['shop' => $this->shopId, 'extid' => (string) $data->extid, 'empty' => '', 'cancelled' => 'storniert']
        );
        if (empty($order)) {
            throw new RuntimeException('Refund order not found or shop is inactive');
        }
        // Match the existing Shopimport::Refund invoice selection and validation.
        $invoice = $this->db->fetchRow(
            'SELECT id, status, waehrung FROM rechnung WHERE auftragid = :order_id '
            . 'ORDER BY status = :cancelled LIMIT 1',
            ['order_id' => (int) $order['id'], 'cancelled' => 'storniert']
        );
        if (empty($invoice)) {
            throw new RuntimeException('Refund order has no invoice yet');
        }
        if ($invoice['status'] === 'storniert') {
            throw new RuntimeException('Refund invoice is cancelled');
        }
        if (empty($data->currency) || $data->currency !== $invoice['waehrung']) {
            throw new RuntimeException('Refund currency does not match invoice currency');
        }
        if (!empty($data->positions)) {
            $positions = $this->db->fetchAll(
                'SELECT op.webid FROM rechnung_position AS ip '
                . 'INNER JOIN auftrag_position AS op ON ip.auftrag_position_id = op.id '
                . 'WHERE ip.rechnung = :invoice_id',
                ['invoice_id' => (int) $invoice['id']]
            );
            $webIds = [];
            foreach ($positions as $position) {
                $webIds[] = (string) $position['webid'];
            }
            foreach ($data->positions as $position) {
                if (!in_array((string) $position->webid, $webIds, true)) {
                    throw new RuntimeException('Refund source webid is not on the invoice: ' . $position->webid);
                }
            }
        }
    }
}
