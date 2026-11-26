<?php
// SPDX-License-Identifier: AGPL-3.0-only
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/category-store.php';
require __DIR__ . '/fixtures/category-export.php';

use Xentral\Components\WooCommerce\CategoryImporter;
use Xentral\Components\WooCommerce\ShopCategoryExporter;
use Xentral\Components\WooCommerce\ShopCategoryStore;

runTests([
    'sync_creates_missing_terms_with_stored_slug' => static function () {
        $store = new ShopCategoryStore(new CategoryStoreDatabase(), 7);
        $parent = $store->create('Replacement parts');
        $child = $store->create('Nozzles', $parent);
        $client = new CategoryExportClient();
        same([$parent => 1001, $child => 1002], (new ShopCategoryExporter($client, $store))->syncTree());
        same([
            ['POST', 'products/categories', ['name' => 'Replacement parts', 'parent' => 0, 'slug' => 'replacement-parts']],
            ['POST', 'products/categories', ['name' => 'Nozzles', 'parent' => 1001, 'slug' => 'nozzles']],
        ], $client->writes);
        same(1002, $store->tree()[0]['children'][0]['wcId']);
    },
    'rename_updates_name_not_slug' => static function () {
        $store = new ShopCategoryStore(new CategoryStoreDatabase(), 7);
        $id = $store->create('Replacement parts');
        $store->setWcId($id, 11, 'original-url');
        $store->rename($id, 'Parts');
        $client = new CategoryExportClient([categoryTerm(11, 'Replacement parts', 'original-url')]);
        same([$id => 11], (new ShopCategoryExporter($client, $store))->syncTree());
        same([['PUT', 'products/categories/11', ['name' => 'Parts']]], $client->writes);
    },
    'move_updates_parent' => static function () {
        $store = new ShopCategoryStore(new CategoryStoreDatabase(), 7);
        $child = $store->create('Nozzles');
        $parent = $store->create('Parts');
        $store->setWcId($child, 11, 'nozzles');
        $store->setWcId($parent, 12, 'parts');
        $store->move($child, $parent, 0);
        $client = new CategoryExportClient([categoryTerm(11, 'Nozzles', 'nozzles'), categoryTerm(12, 'Parts', 'parts')]);
        same([$parent => 12, $child => 11], (new ShopCategoryExporter($client, $store))->syncTree());
        same([['PUT', 'products/categories/11', ['parent' => 12]]], $client->writes);
    },
    'assign_uses_store_when_enabled' => static function () {
        $store = new ShopCategoryStore(new CategoryStoreDatabase(), 7);
        $node = $store->create('Parts');
        $store->setWcId($node, 11, 'parts');
        $store->assign(9, [$node]);
        $client = new CategoryExportClient([categoryTerm(11, 'Parts', 'parts')], [
            ['id' => 40, 'sku' => 'SHOP-9', 'categories' => [['id' => 12]]],
        ]);
        $exporter = new ShopCategoryExporter($client, $store);
        $exporter->assign(40, 9, 'additive');
        $exporter->assign(40, 9, 'replace');
        $exporter->assign(40, 22, 'replace');
        same([
            ['PUT', 'products/40', ['categories' => [['id' => 12], ['id' => 11]]]],
            ['PUT', 'products/40', ['categories' => [['id' => 11]]]],
            ['PUT', 'products/40', ['categories' => []]],
        ], $client->writes);
    },
    'importer_preserves_term_ids_and_slugs' => static function () {
        [$importer, $store, $client] = categoryImportFixture();
        $store->setOnRequest(9, true);
        $preview = $importer->preview();
        same(11, count($preview['nodes']));
        same([9 => [6, 10]], $preview['assignments']);
        same([], $store->tree());
        $importer->apply($preview);
        $roots = $store->tree();
        same([1, 2, 3, 4, 5], array_column($roots, 'wcId'));
        same([6, 7], array_column($roots[0]['children'], 'wcId'));
        same('v6-nozzles-original', $roots[0]['children'][0]['slug']);
        same([$roots[0]['children'][0]['id'], $roots[2]['children'][0]['id']], $store->categoriesOf(9));
        same(true, $store->isOnRequest(9));
        same([], $client->writes);
    },
    'importer_refuses_non_empty_tree' => static function () {
        [$importer, $store] = categoryImportFixture();
        $preview = $importer->preview();
        $store->create('New local category');
        try {
            $importer->apply($preview);
            check(false, 'Import must refuse a non-empty tree.');
        } catch (RuntimeException $error) {
            check(str_contains($error->getMessage(), 'leer'));
        }
        same(['New local category'], array_column($store->tree(), 'name'));
        same([], $store->categoriesOf(9));
    },
    'importer_lists_unmatched_products' => static function () {
        [$importer] = categoryImportFixture();
        $products = $importer->preview()['unmatched'];
        same([41, 42], array_column($products, 'id'));
        same(['UNKNOWN', ''], array_column($products, 'sku'));
    },
    'no_duplicate_terms_after_import_then_sync' => static function () {
        [$importer, $store, $client] = categoryImportFixture();
        $importer->apply($importer->preview());
        $ids = array_values((new ShopCategoryExporter($client, $store))->syncTree());
        sort($ids);
        same(range(1, 11), $ids);
        same([], $client->writes);
    },
    'deleted_category_is_retried_until_woocommerce_count_is_zero' => static function () {
        $store = new ShopCategoryStore(new CategoryStoreDatabase(), 7);
        $id = $store->create('Parts');
        $store->setWcId($id, 11, 'parts');
        $store->delete($id);
        $client = new CategoryExportClient([categoryTerm(11, 'Parts', 'parts', 0, 2)]);
        $exporter = new ShopCategoryExporter($client, $store);
        same([], $exporter->syncTree());
        same([11], array_keys($exporter->deferredDeletions()));
        same([], $client->writes);
        $client->terms[11]['count'] = 0;
        (new ShopCategoryExporter($client, $store))->syncTree();
        same([['DELETE', 'products/categories/11', ['force' => true]]], $client->writes);
        same([], $store->pendingDeletions());
    },
    'import_pages_through_categories_and_product_assignments' => static function () {
        $terms = $products = [];
        for ($id = 1; $id <= 101; $id++) {
            $terms[] = categoryTerm($id, 'Category ' . $id, 'category-' . $id, $id === 101 ? 1 : 0);
            $products[] = ['id' => $id, 'name' => 'Product ' . $id, 'sku' => 'SKU-' . $id, 'categories' => [['id' => $id]]];
        }
        $client = new CategoryExportClient($terms, $products);
        $store = new ShopCategoryStore(new CategoryStoreDatabase(), 7);
        $importer = new CategoryImporter($client, $store, static fn (string $sku): int => (int) substr($sku, 4));
        $preview = $importer->preview();
        same(101, count($preview['nodes']));
        same([101], $preview['assignments'][101]);
        $importer->apply($preview);
        $ids = (new ShopCategoryExporter($client, $store))->syncTree();
        same(101, count($ids));
        same(101, $ids[$store->categoriesOf(101)[0]]);
        same([], $client->writes);
    },
    'sync_recovers_remote_creation_before_the_local_binding_was_saved' => static function () {
        $store = new ShopCategoryStore(new CategoryStoreDatabase(), 7);
        $id = $store->create('Parts');
        $client = new CategoryExportClient([categoryTerm(11, 'Parts', 'parts')]);
        same([$id => 11], (new ShopCategoryExporter($client, $store))->syncTree());
        same(11, $store->tree()[0]['wcId']);
        same([], $client->writes);
    },
    'failed_import_rolls_back_tree_and_all_assignments' => static function () {
        [$importer, $store] = categoryImportFixture();
        $preview = $importer->preview();
        $preview['assignments'][13] = [99999];
        try {
            $importer->apply($preview);
            check(false, 'An unknown term must reject the entire import.');
        } catch (InvalidArgumentException $error) {
            check(str_contains($error->getMessage(), '99999'));
        }
        same([], $store->tree());
        same([], $store->categoriesOf(9));
    },
]);
