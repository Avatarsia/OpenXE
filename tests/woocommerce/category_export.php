<?php
// SPDX-License-Identifier: AGPL-3.0-only
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/category-store.php';
require __DIR__ . '/fixtures/category-export.php';

use Xentral\Components\WooCommerce\CategoryImporter;
use Xentral\Components\WooCommerce\ShopCategoryExporter;
use Xentral\Components\WooCommerce\ShopCategoryStore;

function pendingCategoryStore(): ShopCategoryStore
{
    $store = new ShopCategoryStore(new CategoryStoreDatabase(), 7);
    $id = $store->create('Parts');
    $store->setWcId($id, 11, 'parts');
    $store->delete($id);
    return $store;
}

runTests([
    'tree_export_stops_before_starting_a_request_outside_its_budget' => static function () {
        $store = new ShopCategoryStore(new CategoryStoreDatabase(), 7);
        $store->create('Parts');
        $store->create('Tools');
        $client = new CategoryExportClient();
        $remaining = 2;
        $exporter = new ShopCategoryExporter($client, $store, null, static function () use (&$remaining): bool {
            return $remaining-- > 0;
        });
        try {
            $exporter->syncTree();
        } catch (RuntimeException $error) {
            check(str_contains($error->getMessage(), 'Zeitbudget'));
        }
        same(['parts'], array_column($client->terms, 'slug'));
        same(false, $exporter->completed());
    },
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
        $exporter->assign(40, $store->categoriesOf(9), 'additive');
        $exporter->assign(40, $store->categoriesOf(9), 'replace');
        $exporter->assign(40, $store->categoriesOf(22), 'replace');
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
        $store = pendingCategoryStore();
        $client = new CategoryExportClient([categoryTerm(11, 'Parts', 'parts', 0, 2)]);
        $exporter = new ShopCategoryExporter($client, $store);
        same([], $exporter->syncTree());
        same([11], array_keys($exporter->deferredDeletions()));
        same([], $client->writes);
        $client->terms[11]['count'] = 0;
        (new ShopCategoryExporter($client, $store))->syncTree();
        same([['DELETE', 'products/categories/11', ['force' => true]]], $client->writes);
        same([
            ['products', ['category' => 11, 'status' => 'any', 'per_page' => 1]],
            ['products/categories', ['parent' => 11, 'per_page' => 1, 'hide_empty' => false]],
        ], array_slice($client->reads, -2));
        same([], $store->pendingDeletions());
        same([], $store->pendingDeletionReasons());
    },
    'zero_count_does_not_hide_a_private_product_from_the_deletion_check' => static function () {
        $store = pendingCategoryStore();
        $client = new CategoryExportClient([categoryTerm(11, 'Parts', 'parts')], [
            ['id' => 21, 'status' => 'private', 'categories' => [['id' => 11]]],
        ]);
        $exporter = new ShopCategoryExporter($client, $store);
        $exporter->syncTree();
        same([], $client->writes);
        same([11 => 'parts'], $store->pendingDeletions());
        check(str_contains($exporter->deferredDeletions()[11], 'Produkte'));
    },
    'zero_count_does_not_hide_a_remote_child_category_from_the_deletion_check' => static function () {
        $store = pendingCategoryStore();
        $client = new CategoryExportClient([
            categoryTerm(11, 'Parts', 'parts'),
            categoryTerm(12, 'Nozzles', 'nozzles', 11),
        ]);
        $exporter = new ShopCategoryExporter($client, $store);
        $exporter->syncTree();
        same([], $client->writes);
        same([11 => 'parts'], $store->pendingDeletions());
        check(str_contains($exporter->deferredDeletions()[11], 'Unterkategorien'));
    },
    'a_failed_or_unverifiable_lookup_defers_deletion' => static function () {
        foreach (['products', 'products/categories', null] as $failedLookup) {
            $store = pendingCategoryStore();
            $client = new CategoryExportClient([categoryTerm(11, 'Parts', 'parts')]);
            $client->failedLookup = $failedLookup;
            $client->omitTotal = $failedLookup === null;
            $exporter = new ShopCategoryExporter($client, $store);
            $exporter->syncTree();
            same([], $client->writes);
            same([11 => 'parts'], $store->pendingDeletions());
            same($exporter->deferredDeletions(), $store->pendingDeletionReasons());
            check($store->pendingDeletionReasons()[11] !== '');
        }
    },
    'a_node_deleted_during_remote_creation_queues_the_remote_term_for_the_next_sync' => static function () {
        $store = new ShopCategoryStore(new CategoryStoreDatabase(), 7);
        $id = $store->create('Parts');
        $client = new CategoryExportClient();
        $client->duringPost = static fn () => $store->delete($id);
        $exporter = new ShopCategoryExporter($client, $store);
        same([], $exporter->syncTree());
        same([1001 => 'parts'], $store->pendingDeletions());
        same(['POST'], array_column($client->writes, 0));
        same([], $exporter->syncTree());
        same(['POST', 'DELETE'], array_column($client->writes, 0));
        same([], $store->pendingDeletions());
    },
    'a_recreated_local_node_is_not_bound_to_the_term_queued_by_the_creation_race' => static function () {
        $store = new ShopCategoryStore(new CategoryStoreDatabase(), 7);
        $id = $store->create('Parts');
        $client = new CategoryExportClient();
        $client->duringPost = static function () use ($store, $id): void {
            $store->delete($id);
            $store->create('Parts');
        };
        $exporter = new ShopCategoryExporter($client, $store);
        same([], $exporter->syncTree());
        same([1001 => 'parts'], $store->pendingDeletions());
        $client->duringPost = null;
        same([2 => 1002], $exporter->syncTree());
        same(['POST', 'DELETE', 'POST'], array_column($client->writes, 0));
        same([], $store->pendingDeletions());
    },
    'a_blocked_pending_term_is_never_rebound_to_a_recreated_node' => static function () {
        $store = new ShopCategoryStore(new CategoryStoreDatabase(), 7);
        $id = $store->create('Parts');
        $client = new CategoryExportClient();
        $client->duringPost = static function () use ($store, $id): void {
            $store->delete($id);
            $store->create('Parts');
        };
        $exporter = new ShopCategoryExporter($client, $store);
        same([], $exporter->syncTree());

        $client->duringPost = null;
        $client->terms[1001]['count'] = 1;
        $other = $store->create('Tools');
        same([$other => 1002], $exporter->syncTree());
        same([1001 => 'parts'], $store->pendingDeletions());
        same([0, 1002], array_column($store->tree(), 'wcId'));
        same(['parts', 'tools'], array_column(array_column($client->writes, 2), 'slug'));
    },
    'many_long_deletion_reasons_are_stored_separately_and_bounded' => static function () {
        $db = new CategoryStoreDatabase();
        $store = new ShopCategoryStore($db, 7);
        $treeKey = 'woocommerce_category_tree_7';
        $reasonKey = 'woocommerce_category_deletions_7';
        $active = $store->create('Tools');
        $state = json_decode($db->config[$treeKey], true);
        $terms = [];
        for ($id = 1; $id <= 150; $id++) {
            $state['pendingDeletions'][$id] = 'parts-' . $id;
            $terms[] = categoryTerm($id, 'Parts ' . $id, 'parts-' . $id);
        }
        $state['pendingDeletionReasons'] = [1 => 'Legacy error'];
        $db->config[$treeKey] = json_encode($state);
        same([1 => 'Legacy error'], $store->pendingDeletionReasons());
        $client = new CategoryExportClient($terms);
        $client->failedLookup = 'products';
        $client->lookupError = str_repeat('Long REST error. ', 40);
        same([$active => 1001], (new ShopCategoryExporter($client, $store))->syncTree());
        $reasons = $store->pendingDeletionReasons();
        same(150, count($reasons));
        same(200, max(array_map('strlen', $reasons)));
        same($reasons, json_decode($db->config[$reasonKey], true));
        check(!isset(json_decode($db->config[$treeKey], true)['pendingDeletionReasons']));
        same($state['pendingDeletions'], $store->pendingDeletions());

        for ($id = 151; $id <= 201; $id++) {
            $state['pendingDeletions'][$id] = 'parts-' . $id;
            $client->terms[$id] = categoryTerm($id, 'Parts ' . $id, 'parts-' . $id);
        }
        $state['nodes'][$active]['wcId'] = 1001;
        unset($state['pendingDeletionReasons']);
        $db->config[$treeKey] = json_encode($state);
        same([$active => 1001], (new ShopCategoryExporter($client, $store))->syncTree());
        same(range(2, 201), array_keys($store->pendingDeletionReasons()));
    },
    'a_reason_write_failure_is_logged_and_does_not_stop_the_tree' => static function () {
        $db = new CategoryStoreDatabase();
        $store = new ShopCategoryStore($db, 7);
        $parts = $store->create('Parts');
        $store->setWcId($parts, 11, 'parts');
        $store->delete($parts);
        $other = $store->create('Tools');
        $client = new CategoryExportClient([categoryTerm(11, 'Parts', 'parts', 0, 1)]);
        $db->failDeletionReasonWrites = true;
        $warnings = [];
        $exporter = new ShopCategoryExporter($client, $store, static function ($message, $context) use (&$warnings): void {
            $warnings[] = [$message, $context];
        });
        same([$other => 1001], $exporter->syncTree());
        same([11 => 'parts'], $store->pendingDeletions());
        check(str_contains(json_encode($warnings), 'Deletion reasons unavailable'));
    },
    'a_failed_node_skips_its_subtree_and_does_not_stop_a_sibling_when_logging_fails' => static function () {
        $store = new ShopCategoryStore(new CategoryStoreDatabase(), 7);
        $parent = $store->create('Broken');
        $store->create('Child', $parent);
        $other = $store->create('Tools');
        $client = new CategoryExportClient([categoryTerm(11, 'Unrelated name', 'broken')]);
        $exporter = new ShopCategoryExporter($client, $store, static function (): void {
            throw new Error('Log insert failed');
        });
        same([$other => 1001], $exporter->syncTree());
        same(['tools'], array_column(array_column($client->writes, 2), 'slug'));
        same([$parent => 'WooCommerce-Slug bereits belegt: broken'], $exporter->assign(40, [$parent], 'additive'));
    },
    'skip_reasons_are_bounded_outside_the_tree' => static function () {
        $db = new CategoryStoreDatabase();
        $store = new ShopCategoryStore($db, 7);
        for ($id = 1; $id <= 201; $id++) {
            $store->create('Category ' . $id);
        }
        $treeBeforeSync = $db->config['woocommerce_category_tree_7'];
        $client = new CategoryExportClient();
        $client->duringPost = static function (): void {
            throw new RuntimeException(str_repeat("\u{1F527}", 250));
        };
        same([], (new ShopCategoryExporter($client, $store))->syncTree());

        $json = $db->config['woocommerce_category_skips_7'] ?? '';
        check($json !== '', 'Skip reasons must use their own configuration entry.');
        check(strlen($json) < 65000);
        $reasons = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        same(range(176, 201), array_keys($reasons));
        same(str_repeat("\u{1F527}", 200), $reasons[201]);
        same($treeBeforeSync, $db->config['woocommerce_category_tree_7']);
    },
    'a_skip_reason_write_failure_preserves_the_sync_and_assignment_results' => static function () {
        $db = new CategoryStoreDatabase();
        $store = new ShopCategoryStore($db, 7);
        $broken = $store->create('Broken');
        $other = $store->create('Tools');
        $db->failSkipReasonWrites = true;
        $client = new CategoryExportClient([categoryTerm(11, 'Unrelated name', 'broken')]);
        $warnings = [];
        $exporter = new ShopCategoryExporter($client, $store, static function ($message, $context) use (&$warnings): void {
            $warnings[] = [$message, $context];
        });

        same([$other => 1001], $exporter->syncTree());
        same([$broken => 'WooCommerce-Slug bereits belegt: broken'], $exporter->assign(40, [$broken], 'additive'));
        check(str_contains(json_encode($warnings), 'Skip reasons unavailable'));
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
