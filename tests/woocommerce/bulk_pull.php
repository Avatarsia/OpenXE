<?php
// SPDX-License-Identifier: AGPL-3.0-only
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/bulk-pull.php';

use Xentral\Components\WooCommerce\ArticleDataRepository;
use Xentral\Components\WooCommerce\BulkPull;
use Xentral\Components\WooCommerce\DeliveryTimeMap;
use Xentral\Components\WooCommerce\WooProductReader;

function bulkFixture(): array
{
    $db = new BulkPullDatabase();
    $client = new BulkPullClient();
    $app = (object) ['Container' => new class ($db) {
        public function __construct(private BulkPullDatabase $db) {}
        public function get(string $service): BulkPullDatabase { return $this->db; }
    }];
    $reader = new WooProductReader($client, new DeliveryTimeMap(static fn () => [], ''));
    $pull = new BulkPull(new ArticleDataRepository($app, 7), $reader, static fn (string $sku): ?int =>
        ['SHOP-9' => 9, 'LOCAL-10' => 10][$sku] ?? null
    );
    return [$pull, $db, $client];
}

runTests([
    'compare_lists_only_differences' => static function () {
        [$pull, $db, $client] = bulkFixture();
        $preview = $pull->compare(1);
        same([9], array_keys($preview['articles']));
        $rows = array_column($preview['articles'][9]['rows'], null, 'key');
        same(['shop_name', 'seo_title', 'price_net'], array_keys($rows));
        same('Alter Name', $rows['shop_name']['openxe']);
        same('Vorschau', $rows['shop_name']['woocommerce']);
        same(false, $rows['price_net']['pullable']);
        same('leerer Shop-Preis wird nicht uebernommen', $rows['price_net']['reason']);
        same([], $db->writes);
        same([['products', 1, 100]], $client->requests);
    },
    'unmatched_products_listed' => static function () {
        [$pull, $db, $client] = bulkFixture();
        $client->pages[2] = [
            ['id' => 201, 'name' => 'Ohne SKU'],
            ['id' => 202, 'name' => 'Ohne Artikel', 'sku' => 'MISSING'],
            ['id' => 203, 'name' => 'Doppelt 1', 'sku' => 'SHOP-9'],
            ['id' => 204, 'name' => 'Doppelt 2', 'sku' => 'SHOP-9'],
        ];
        $preview = $pull->compare(2);
        same([], $preview['articles']);
        $unmatched = array_column($preview['unmatched'], 'reason', 'id');
        ksort($unmatched);
        same([201 => 'keine SKU', 202 => 'kein OpenXE-Artikel', 203 => 'SKU mehrfach', 204 => 'SKU mehrfach'], $unmatched);
        same([['products', 2, 100]], $client->requests);
        same([], $db->writes);
    },
    'duplicate SKU on different pages cannot be previewed or applied on either page' => static function () {
        [$pull, $db, $client] = bulkFixture();
        $client->pages[2] = [['id' => 201, 'name' => 'Zweites Produkt', 'sku' => 'SHOP-9']];
        foreach ([1 => 101, 2 => 201] as $page => $id) {
            $preview = $pull->compare($page);
            same([$id => 'SKU mehrfach'], array_column($preview['unmatched'], 'reason', 'id'));
            same([], $preview['articles']);
            same('SKU mehrfach', $pull->apply([9 => ['shop_name']], 'bulk_pull', $page)[9]['error']);
        }
        same([], $db->writes);
        same([['products', 1, 100], ['products', 1, 100], ['products', 2, 100], ['products', 2, 100]], $client->requests);
        same(array_fill(0, 4, 'SHOP-9'), $client->lookupRequests);
    },
    'shop-wide lookup sees variants on other pages' => static function () {
        [$pull, $db, $client] = bulkFixture();
        $client->pages[2] = [['id' => 200, 'type' => 'variable', 'sku' => 'OTHER']];
        $client->variations['products/200/variations'] = [['id' => 201, 'parent_id' => 200, 'sku' => 'SHOP-9',
            'meta_data' => [['key' => 'rank_math_title', 'value' => 'Varianten-SEO']]]];
        same([101 => 'SKU mehrfach'], array_column($pull->compare(1)['unmatched'], 'reason', 'id'));
        same([200 => 'kein OpenXE-Artikel', 201 => 'SKU mehrfach'],
            array_column($pull->compare(2)['unmatched'], 'reason', 'id'));
        same([], $db->writes);
    },
    'duplicate appearing on the same page after preview reports the SKU problem' => static function () {
        [$pull, $db, $client] = bulkFixture();
        $preview = $pull->compare(1);
        same([9], array_keys($preview['articles']));
        $client->pages[1][] = ['id' => 102, 'name' => 'Neu doppelt', 'sku' => 'SHOP-9'];
        $identity = ['id' => 101, 'parent_id' => 0, 'sku' => 'SHOP-9'];
        same('SKU mehrfach', $pull->apply([9 => ['shop_name']], 'bulk_pull', 1, [9 => $identity])[9]['error']);
        same([], $db->writes);
    },
    'incomplete SKU lookup cannot authorize an ERP write' => static function () {
        [$pull, $db, $client] = bulkFixture();
        $client->incompleteLookup = true;
        same([101 => 'SKU-Pruefung fehlgeschlagen'], array_column($pull->compare(1)['unmatched'], 'reason', 'id'));
        same('SKU-Pruefung fehlgeschlagen', $pull->apply([9 => ['shop_name']], 'bulk_pull')[9]['error']);
        same([], $db->writes);
    },
    'apply_respects_group_filter_and_uses_current_woo_values' => static function () {
        [$pull, $db, $client] = bulkFixture();
        $client->pages[2] = $client->pages[1];
        unset($client->pages[1]);
        $pull->compare(2);
        $client->pages[2][0]['meta_data'][0]['value'] = 'SEO aktuell';
        same([9 => ['seo_title' => ['old' => '', 'new' => 'SEO aktuell']]], $pull->apply([9 => ['seo_title']], 'bulk_pull', 2));
        same('SEO aktuell', $db->articles[9]['metatitle_de']);
        same('Alter Name', $db->articles[9]['shop_name_de']);
        $audit = json_decode($db->audit['woocommerce_pull_log_7'], true);
        same(['bulk_pull'], array_column($audit, 'origin'));
        same([['products', 2, 100], ['products', 2, 100]], $client->requests);
    },
    'invalid_article_does_not_prevent_other_selected_articles_from_saving' => static function () {
        [$pull, $db, $client] = bulkFixture();
        $client->pages[1][0]['name'] = str_repeat('x', 256);
        $client->pages[1][] = ['id' => 102, 'name' => 'Neuer Name', 'sku' => 'LOCAL-10'];
        $results = $pull->apply([9 => ['shop_name', 'seo_title'], 10 => ['shop_name'], 11 => ['shop_name']], 'bulk_pull');
        check(isset($results[9]['error'], $results[11]['error']));
        same('Alter Name', $db->articles[9]['shop_name_de']);
        check(!isset($db->articles[9]['metatitle_de']));
        same('Neuer Name', $db->articles[10]['shop_name_de']);
        same(false, $db->inTransaction());
    },
    'apply_does_not_write_blocked_fields' => static function () {
        [$pull, $db] = bulkFixture();
        $result = $pull->apply([9 => ['price_net']], 'bulk_pull');
        check(isset($result[9]['error']));
        same([], $db->writes);
    },
]);
