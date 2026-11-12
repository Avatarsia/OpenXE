<?php
// SPDX-License-Identifier: AGPL-3.0-only
require __DIR__ . '/bootstrap.php';

use Xentral\Components\WooCommerce\AdminView;

runTests([
    'download view escapes values and provides complete editable rows' => static function () {
        $html = AdminView::downloads(7, 'csrf"value', ['id' => 9, 'nummer' => 'SKU', 'name_de' => '<script>'],
            ['downloadable' => true, 'virtual' => false, 'downloads' => [['name' => '"><img>', 'file' => 'https://example.org/a?x="']]], [], '', true);
        check(strpos($html, '<script>') === false);
        check(strpos($html, '&lt;script&gt;') !== false);
        check(strpos($html, 'name="download_name[]"') !== false);
        check(strpos($html, 'name="download_file[]"') !== false);
        check(strpos($html, 'name="csrf_token" value="csrf&quot;value"') !== false);
        check(strpos($html, 'wc-download-add') !== false);
        check(strpos($html, 'Smarty-Vorgabe verwenden') !== false);
        check(strpos($html, 'value="clear_downloads" formnovalidate') !== false);
    },
    'empty download view teaches search and global opt in' => static function () {
        $html = AdminView::downloads(7, 'token', null, null, [], 'no result', false);
        check(strpos($html, 'Artikel suchen') !== false);
        check(strpos($html, 'Download-Export ist deaktiviert') !== false);
        check(strpos($html, 'Keine passenden Artikel') !== false);
    },
    'refund view escapes errors and distinguishes manual recovery' => static function () {
        $html = AdminView::refunds(7, 'token', ['rows' => [
            ['type' => 'queue', 'order_id' => '88', 'refund_id' => '', 'credit_note_id' => null, 'status' => 'retry', 'attempts' => 2, 'next_attempt' => 60, 'last_attempt' => 1, 'last_error' => '<img>', 'retry_available' => true],
            ['type' => 'refund', 'order_id' => '88', 'refund_id' => '99', 'credit_note_id' => null, 'status' => 'pending', 'attempts' => 0, 'next_attempt' => 0, 'last_attempt' => 0, 'last_error' => '', 'retry_available' => false],
        ], 'page' => 1, 'pages' => 2, 'total' => 26]);
        check(strpos($html, '&lt;img&gt;') !== false);
        same(1, substr_count($html, '>Erneut versuchen</button>'));
        check(strpos($html, 'Manuell pr&uuml;fen') !== false);
        check(strpos($html, 'page=2') !== false);
        check(strpos($html, 'UTC') !== false);
    },
    'refund empty state is explicit' => static function () {
        $html = AdminView::refunds(7, 'token', ['rows' => [], 'page' => 1, 'pages' => 1, 'total' => 0]);
        check(strpos($html, 'Noch keine Refund-Abgleiche') !== false);
    },
]);
