<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Local visual fixture only: synthetic data, no ERP bootstrap, credentials or shop connection.
$root = dirname(__DIR__, 3);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (!in_array($path, ['/index.php', '/tests/woocommerce/fixtures/admin-router.php'], true) && is_file($root . $path)) {
    return false;
}
require_once $root . '/classes/Components/WooCommerce/AdminView.php';
require_once $root . '/classes/Components/WooCommerce/BulkPull.php';
use Xentral\Components\WooCommerce\AdminView;
$view = ($_GET['action'] ?? '') === 'woocommerce' ? 'assignment' : ($_GET['view'] ?? 'downloads');
$html = $view === 'downloads'
    ? AdminView::downloads(7, 'synthetic-csrf', ['id' => 9, 'nummer' => 'DIG-100', 'name_de' => 'Montageanleitung'],
        ['downloadable' => true, 'virtual' => true, 'downloads' => [['name' => 'Montageanleitung Deutsch', 'file' => 'https://example.org/montageanleitung.pdf']]], [], '', true)
    : AdminView::refunds(7, 'synthetic-csrf', ['rows' => [
        ['type' => 'queue', 'order_id' => '4821', 'refund_id' => null, 'credit_note_id' => null, 'status' => 'retry', 'attempts' => 2, 'next_attempt' => 1792576800, 'last_attempt' => 1792576200, 'last_error' => 'Refund order has no invoice yet', 'retry_available' => true],
        ['type' => 'refund', 'order_id' => '4817', 'refund_id' => '4920', 'credit_note_id' => null, 'status' => 'pending', 'attempts' => 0, 'next_attempt' => null, 'last_attempt' => null, 'last_error' => '', 'retry_available' => false],
        ['type' => 'refund', 'order_id' => '4812', 'refund_id' => '4919', 'credit_note_id' => 1234, 'status' => 'complete', 'attempts' => 0, 'next_attempt' => null, 'last_attempt' => null, 'last_error' => '', 'retry_available' => false],
    ], 'page' => 1, 'pages' => 2, 'total' => 26]);
if ($view === 'pull') {
    $html = AdminView::pull(7, 'synthetic-csrf', 'synthetic-preview', 2, [
        'articles' => [9 => ['nummer' => 'DIG-100', 'name' => 'Montageanleitung', 'rows' => [
            ['key' => 'seo_title', 'label' => 'SEO-Titel', 'openxe' => '', 'woocommerce' => 'Montageanleitung Deutsch', 'pullable' => true],
            ['key' => 'description', 'label' => 'Shop-Beschreibung', 'openxe' => '<p>Kurzanleitung</p>',
                'woocommerce' => '<p>Montageanleitung mit ' . str_repeat('Befestigungsinformationen', 16) . '</p>', 'pullable' => true],
            ['key' => 'price_net', 'label' => 'Verkaufspreis netto', 'openxe' => '28.50', 'woocommerce' => '32.50',
                'pullable' => false, 'reason' => 'Shop nutzt eine Preisgruppe - Preis bitte unter Verkaufspreise pflegen'],
        ]]],
        'unmatched' => [['id' => 202, 'name' => 'Produkt ohne SKU', 'reason' => 'keine SKU']],
        'more' => true,
    ], ['Texte', 'SEO', 'Preis', 'Status', 'Lieferung', 'Logistik', 'Zoll', 'Hersteller'],
        ['Texte', 'SEO', 'Preis', 'Status', 'Lieferung', 'Logistik', 'Zoll', 'Hersteller']);
}
if ($view === 'categories') {
    require_once __DIR__ . '/category-export.php';
    $html = AdminView::categories(7, 'synthetic-csrf', 'synthetic-preview', [
        'nodes' => categoryImportClient()->terms,
        'assignments' => [9 => [6, 10]],
        'unmatched' => [['id' => 41, 'name' => 'Kein passender Artikel', 'sku' => 'UNKNOWN', 'reason' => 'kein eindeutiger OpenXE-Artikel']],
    ]);
}
if ($view === 'assignment') {
    require_once __DIR__ . '/assignment.php';
    $db = new AssignmentFixtureDatabase();
    $db->assignment = ['aktiv' => 1];
    $store = new \Xentral\Components\WooCommerce\ShopCategoryStore($db, 7);
    $parent = $store->create('Ersatzteile');
    $category = $store->create('Hotends', $parent);
    $store->create('Zubehoer');
    $store->assign(9, [$category]);
    $page = new \Xentral\Components\WooCommerce\AssignmentPage(assignmentFixtureApp($db, $_GET + ['category' => $category]));
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($page->handle((string) ($_GET['cmd'] ?? ''), $_POST), JSON_THROW_ON_ERROR);
        return;
    }
    $html = $page->render();
}
?><!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>WooCommerce UI-Prüfung</title>
<link rel="stylesheet" href="/www/themes/new/css/normalize.min.css">
<link rel="stylesheet" href="/www/themes/new/css/color3.css">
<link rel="stylesheet" href="/www/themes/new/css/styles.css">
<link rel="stylesheet" href="/www/css/bootstrap.min.css">
<link rel="stylesheet" href="/classes/Modules/Onlineshop/www/css/woocommerce-admin.css">
<script src="/classes/Modules/Onlineshop/www/js/woocommerce-admin.js" defer></script>
<?php if ($view === 'assignment'): ?>
<script src="/www/js/jquery/jquery-3.5.0.min.js" defer></script>
<script src="/classes/Modules/Onlineshop/www/js/woocommerce-assignment.js" defer></script>
<?php endif; ?>
</head><body><main class="inside_white"><p>Lokale UI-Prüfung · synthetische Daten</p><div class="wc-admin"><nav class="wc-admin-nav"><a href="?view=downloads">Downloads</a><a href="?view=refunds">Refund-Status</a><a href="?view=pull">Artikeldaten abgleichen</a><a href="?view=categories">Shop-Kategorien</a><a href="?view=assignment">Shop-Zuordnung</a></nav><?php if ($view === 'assignment'): ?></div><?= $html ?><?php else: ?><?= $html ?></div><?php endif; ?></main></body></html>
