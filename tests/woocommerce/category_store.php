<?php
// SPDX-License-Identifier: AGPL-3.0-only
require __DIR__ . '/bootstrap.php';

use Xentral\Components\WooCommerce\ShopCategoryStore;

final class CategoryStoreDatabase
{
    public array $config = [];
    private ?array $snapshot = null;
    private ?string $lockedTree = null;

    public function inTransaction(): bool { return $this->snapshot !== null; }
    public function beginTransaction(): void
    {
        check(!$this->inTransaction());
        $this->snapshot = $this->config;
    }
    public function commit(): void
    {
        $this->snapshot = null;
        $this->lockedTree = null;
    }
    public function rollBack(): void
    {
        $this->config = $this->snapshot;
        $this->commit();
    }

    public function fetchValue(string $sql, array $params)
    {
        $name = $params['name'];
        if (str_ends_with($sql, ' FOR UPDATE')) {
            check($this->inTransaction());
            if (str_starts_with($name, 'woocommerce_category_tree_')) {
                check(isset($this->config[$name]), 'Lock must cover the first write.');
                $this->lockedTree = $name;
            }
        }
        return $this->config[$name] ?? false;
    }

    public function fetchAll(string $sql, array $params): array
    {
        check(str_contains($sql, "LIKE :prefix ESCAPE '!'"));
        if (str_ends_with($sql, ' FOR UPDATE')) {
            check($this->lockedTree !== null);
        }
        $prefix = str_replace('!_', '_', substr($params['prefix'], 0, -1));
        $rows = [];
        foreach ($this->config as $name => $value) {
            if (str_starts_with($name, $prefix)) {
                $rows[] = ['name' => $name, 'wert' => $value];
            }
        }
        return $rows;
    }

    public function perform(string $sql, array $params): void
    {
        check($this->inTransaction());
        check(str_starts_with($sql, 'INSERT INTO konfiguration (name, wert, adresse, firma)'));
        $name = $params['name'];
        if (str_contains($sql, 'ON DUPLICATE KEY UPDATE name = VALUES(name)')) {
            $this->config[$name] ??= $params['wert'];
            return;
        }
        check(str_contains($sql, 'ON DUPLICATE KEY UPDATE wert = VALUES(wert)'));
        $prefix = preg_replace('/^woocommerce_article_(\d+)_\d+$/D', 'woocommerce_category_tree_$1', $name);
        same($prefix, $this->lockedTree);
        $this->config[$name] = $params['wert'];
    }
}

runTests([
    'create_rename_keeps_slug' => static function () {
        $db = new CategoryStoreDatabase();
        $store = new ShopCategoryStore($db, 5);
        $name = "  \u{00c4} \u{00d6}-\u{00dc} Stra\u{00df}e!! \u{1f4e6}";
        same([], $store->tree());
        same(1, $store->create($name));
        same(2, $store->create($name));
        same(3, $store->create('ae oe ue strasse'));
        $store->rename(1, 'New name');
        $tree = $store->tree();
        same(['ae-oe-ue-strasse', 'ae-oe-ue-strasse-2', 'ae-oe-ue-strasse-3'], array_column($tree, 'slug'));
        same(['New name', $name, 'ae oe ue strasse'], array_column($tree, 'name'));
        $store->setWcId(1, 72, 'existing-woo-slug');
        $store->rename(1, 'Imported');
        same(72, $store->tree()[0]['wcId']);
        same('existing-woo-slug', $store->tree()[0]['slug']);
        check(str_contains($db->config['woocommerce_category_tree_5'], '\ud83d\udce6'));
        same([], (new ShopCategoryStore($db, 50))->tree());
    },
    'move_rejects_cycle' => static function () {
        $db = new CategoryStoreDatabase();
        $store = new ShopCategoryStore($db, 5);
        $root = $store->create('Root');
        $child = $store->create('Child', $root);
        $grandchild = $store->create('Grandchild', $child);
        $before = $db->config;
        foreach ([$root, $grandchild] as $parent) {
            try {
                $store->move($root, $parent, 0);
                throw new LogicException('Cycle accepted.');
            } catch (InvalidArgumentException $error) {
                same($before, $db->config);
            }
        }
        $store->move($grandchild, $root, -1);
        same([$grandchild, $child], array_column($store->tree()[0]['children'], 'id'));
        $store->move($child, 0, -1);
        same([$child, $root], array_column($store->tree(), 'id'));
    },
    'delete_only_empty' => static function () {
        $db = new CategoryStoreDatabase();
        $store = new ShopCategoryStore($db, 5);
        $root = $store->create('Root');
        $child = $store->create('Child', $root);
        $store->assign(12, [$child]);
        foreach ([$root, $child] as $id) {
            try {
                $store->delete($id);
                throw new LogicException('Non-empty category deleted.');
            } catch (RuntimeException $error) {
                same("Kategorie ist nicht leer.", $error->getMessage());
            }
        }
        $store->assign(12, []);
        $store->delete($child);
        $store->delete($root);
        same([], $store->tree());
        same('{"nextId":3,"nodes":{}}', $db->config['woocommerce_category_tree_5']);
        same(3, $store->create('Recreated'));
    },
    'assign_multiple_categories' => static function () {
        $db = new CategoryStoreDatabase();
        $store = new ShopCategoryStore($db, 5);
        $a = $store->create('First');
        $b = $store->create('Second');
        $other = new ShopCategoryStore($db, 50);
        $other->assign(12, [$other->create('Other shop')]);
        $store->assign(12, [(string) $a, $b, $a]);
        $store->assign(10, [$a]);
        $store->setOnRequest(13, true);
        same([$a, $b], $store->categoriesOf(12));
        same([10, 12], $store->articlesIn($a));
        same([12], $store->articlesIn($b));
        same([13, 9], $store->articlesWithoutCategory([13, 12, 9, 10]));
        try {
            $store->assign(12, [99]);
            throw new LogicException('Unknown category assigned.');
        } catch (InvalidArgumentException $error) {
            same([$a, $b], $store->categoriesOf(12));
        }
        $store->assign(12, []);
        same([], $store->articlesIn($b));
        same([$a], $other->categoriesOf(12));
    },
    'on_request_flag_roundtrip' => static function () {
        $db = new CategoryStoreDatabase();
        $store = new ShopCategoryStore($db, 5);
        same(false, $store->isOnRequest(9));
        same([], $db->config);
        $db->config['woocommerce_article_5_9'] = '{"onRequest":true}';
        same(true, $store->isOnRequest(9));
        same([], $store->categoriesOf(9));
        $category = $store->create('First');
        $store->assign(9, [$category]);
        same(true, $store->isOnRequest(9));
        $store->setOnRequest(9, false);
        same(false, $store->isOnRequest(9));
        same([$category], $store->categoriesOf(9));
        $store->setOnRequest(10, true);
        same(['categories' => [], 'onRequest' => true], json_decode($db->config['woocommerce_article_5_10'], true));
    },
    'bound_slug_cannot_change' => static function () {
        $db = new CategoryStoreDatabase();
        $store = new ShopCategoryStore($db, 5);
        $id = $store->create('Original');
        $store->setWcId($id, 72, 'imported');
        $store->setWcId($id, 72, 'imported');
        try {
            $store->setWcId($id, 72, 'changed');
            check(false, 'changing a bound slug must fail');
        } catch (InvalidArgumentException $error) {
            same('imported', $store->tree()[0]['slug']);
        }
    },
    'config_limit_is_exclusive' => static function () {
        $db = new CategoryStoreDatabase();
        $store = new ShopCategoryStore($db, 5);
        $store->create('Lock');
        $db->beginTransaction();
        $db->fetchValue('SELECT wert FROM konfiguration WHERE name = :name FOR UPDATE', ['name' => 'woocommerce_category_tree_5']);
        $write = (new ReflectionClass($store))->getMethod('writeConfig');
        $write->setAccessible(true);
        $payload = static fn (int $bytes): array => ['x' => str_repeat('a', $bytes - strlen('{"x":""}'))];
        $write->invoke($store, 'woocommerce_category_tree_5', $payload(64999));
        try {
            $write->invoke($store, 'woocommerce_category_tree_5', $payload(65000));
            check(false, '65000 bytes must be rejected');
        } catch (LengthException $error) {
            check(true);
        }
    },
    'tree_json_stays_below_limit' => static function () {
        $db = new CategoryStoreDatabase();
        $store = new ShopCategoryStore($db, 5);
        for ($i = 1; $i <= 500; $i++) {
            same($i, $store->create('Category ' . $i));
        }
        $json = $db->config['woocommerce_category_tree_5'];
        check(strlen($json) < 65000);
        same(500, count($store->tree()));
        try {
            $store->create(str_repeat("\u{1f4e6}", 6000));
            throw new LogicException('Oversize tree written.');
        } catch (LengthException $error) {
            same($json, $db->config['woocommerce_category_tree_5']);
        }
        same(false, $db->inTransaction());
        same(501, $store->create('After rejection'));
    },
]);
