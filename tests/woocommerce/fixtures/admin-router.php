<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Local visual fixture only: synthetic data, no ERP bootstrap, credentials or shop connection.
$root = dirname(__DIR__, 3);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/tests/woocommerce/fixtures/admin-router.php' && is_file($root . $path)) {
    return false;
}
require_once $root . '/classes/Components/WooCommerce/AdminView.php';
use Xentral\Components\WooCommerce\AdminView;
$view = ($_GET['view'] ?? '') === 'refunds' ? 'refunds' : 'downloads';
$html = $view === 'downloads'
    ? AdminView::downloads(7, 'synthetic-csrf', ['id' => 9, 'nummer' => 'DIG-100', 'name_de' => 'Montageanleitung'],
        ['downloadable' => true, 'virtual' => true, 'downloads' => [['name' => 'Montageanleitung Deutsch', 'file' => 'https://example.org/montageanleitung.pdf']]], [], '', true)
    : AdminView::refunds(7, 'synthetic-csrf', ['rows' => [
        ['type' => 'queue', 'order_id' => '4821', 'refund_id' => null, 'credit_note_id' => null, 'status' => 'retry', 'attempts' => 2, 'next_attempt' => 1792576800, 'last_attempt' => 1792576200, 'last_error' => 'Refund order has no invoice yet', 'retry_available' => true],
        ['type' => 'refund', 'order_id' => '4817', 'refund_id' => '4920', 'credit_note_id' => null, 'status' => 'pending', 'attempts' => 0, 'next_attempt' => null, 'last_attempt' => null, 'last_error' => '', 'retry_available' => false],
        ['type' => 'refund', 'order_id' => '4812', 'refund_id' => '4919', 'credit_note_id' => 1234, 'status' => 'complete', 'attempts' => 0, 'next_attempt' => null, 'last_attempt' => null, 'last_error' => '', 'retry_available' => false],
    ], 'page' => 1, 'pages' => 2, 'total' => 26]);
?><!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>WooCommerce UI-Prüfung</title>
<link rel="stylesheet" href="/www/themes/new/css/normalize.min.css">
<link rel="stylesheet" href="/www/themes/new/css/color3.css">
<link rel="stylesheet" href="/www/themes/new/css/styles.css">
<link rel="stylesheet" href="/www/css/bootstrap.min.css">
<link rel="stylesheet" href="/classes/Modules/Onlineshop/www/css/woocommerce-admin.css">
<script src="/classes/Modules/Onlineshop/www/js/woocommerce-admin.js" defer></script>
</head><body><main class="inside_white"><p>Lokale UI-Prüfung · synthetische Daten</p><div class="wc-admin"><nav class="wc-admin-nav"><a href="?view=downloads">Downloads</a><a href="?view=refunds">Refund-Status</a></nav><?= $html ?></div></main></body></html>
