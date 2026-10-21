<?php
// SPDX-License-Identifier: AGPL-3.0-only
namespace Xentral\Components\WooCommerce;

use InvalidArgumentException;
use RuntimeException;

/** Synchronizes category identities within one source shop, preserving WooCommerce assignments by default. */
class CategorySynchronizer
{
    private $client;
    private $shopId;
    private $remote = [];

    public function __construct($client, $shopId)
    {
        if (!preg_match('/^[0-9]+$/', (string) $shopId)) {
            throw new InvalidArgumentException('A numeric source shop ID is required.');
        }
        $this->client = $client;
        $this->shopId = (string) $shopId;
    }

    /** @return int[] Assigned category IDs; ancestors are created but not additionally assigned. */
    public function sync(array $categories, array $tree = []): array
    {
        $nodes = [];
        foreach (array_merge($tree, $categories) as $category) {
            $category = (array) $category;
            $key = $this->key($category);
            $category['name'] = html_entity_decode((string) ($category['name'] ?? ''), ENT_QUOTES, 'UTF-8');
            if ($category['name'] === '') {
                throw new InvalidArgumentException('Category name must not be empty.');
            }
            // GetKategorienbaum omits the configured category_root_id itself.
            if (isset($category['lvl']) && (int) $category['lvl'] === 0) {
                $category['parent'] = 0;
            }
            $nodes[$key] = $category;
        }
        $ordered = [];
        $visiting = [];
        $visit = function ($key) use (&$visit, &$ordered, &$visiting, $nodes) {
            if (isset($ordered[$key])) {
                return;
            }
            if (isset($visiting[$key])) {
                throw new InvalidArgumentException('Cyclic category hierarchy.');
            }
            if (!isset($nodes[$key])) {
                throw new InvalidArgumentException('Missing source category parent ' . $key);
            }
            $visiting[$key] = true;
            $parent = $nodes[$key]['parent'] ?? 0;
            if ((string) $parent !== '' && (string) $parent !== '0') {
                $visit((string) $parent);
            }
            unset($visiting[$key]);
            $ordered[$key] = $nodes[$key];
        };
        foreach ($categories as $category) {
            $visit($this->key((array) $category));
        }
        if (empty($ordered)) {
            return [];
        }
        $this->remote = [];
        for ($page = 1; ; $page++) {
            $rows = $this->client->get('products/categories', ['per_page' => 100, 'page' => $page, 'hide_empty' => false]);
            if (!is_array($rows)) {
                throw new RuntimeException('Invalid WooCommerce category response.');
            }
            foreach ($rows as $row) {
                $row = (array) $row;
                $this->remote[(int) $row['id']] = $row;
            }
            if (count($rows) < 100) {
                break;
            }
        }
        $mapped = [];
        foreach ($ordered as $key => $category) {
            $parent = $mapped[(string) ($category['parent'] ?? 0)] ?? 0;
            $slug = 'openxe-' . $this->shopId . '-' . $key;
            $match = null;
            $legacy = [];
            foreach ($this->remote as $row) {
                if (($row['slug'] ?? '') === $slug) {
                    $match = $row;
                    break;
                }
                if ((int) ($row['parent'] ?? 0) === $parent && html_entity_decode((string) ($row['name'] ?? ''), ENT_QUOTES, 'UTF-8') === $category['name'] && strpos((string) ($row['slug'] ?? ''), 'openxe-') !== 0) {
                    $legacy[] = $row;
                }
            }
            if ($match === null && count($legacy) > 1) {
                throw new RuntimeException('Ambiguous existing category ' . $category['name']);
            }
            $match = $match ?? ($legacy[0] ?? null);
            $payload = ['name' => $category['name'], 'slug' => $slug, 'parent' => $parent];
            if ($match !== null) {
                $id = (int) $match['id'];
                if (($match['name'] ?? '') !== $payload['name'] || ($match['slug'] ?? '') !== $slug || (int) ($match['parent'] ?? 0) !== $parent) {
                    $this->client->put('products/categories/' . $id, $payload);
                }
            } else {
                $created = (array) $this->client->post('products/categories', $payload);
                $id = (int) ($created['id'] ?? 0);
                if ($id <= 0) {
                    throw new RuntimeException('WooCommerce category creation returned no ID.');
                }
            }
            $this->remote[$id] = array_merge($payload, ['id' => $id]);
            $mapped[$key] = $id;
        }
        $ids = [];
        foreach ($categories as $category) {
            $ids[] = $mapped[$this->key((array) $category)];
        }
        return array_values(array_unique($ids));
    }

    /** Explicit replace permits removal; additive preserves categories managed in the shop. */
    public function assign(int $productId, array $categoryIds, string $mode = 'additive'): void
    {
        if (!in_array($mode, ['additive', 'replace'], true)) {
            throw new InvalidArgumentException('Category assignment mode must be additive or replace.');
        }
        $ids = [];
        if ($mode === 'additive') {
            $product = (array) $this->client->get('products/' . $productId);
            foreach ($product['categories'] ?? [] as $category) {
                $category = (array) $category;
                $ids[] = (int) $category['id'];
            }
        }
        foreach ($categoryIds as $id) {
            if ((int) $id > 0) {
                $ids[] = (int) $id;
            }
        }
        $this->client->put('products/' . $productId, ['categories' => array_map(static function ($id) { return ['id' => $id]; }, array_values(array_unique($ids)))]);
    }

    private function key(array $category): string
    {
        if (isset($category['id']) && preg_match('/^[0-9]+$/', (string) $category['id']) && (int) $category['id'] > 0) {
            return (string) $category['id'];
        }
        // Old exports may contain only kategoriename. Scope that fallback to its parent path.
        return 'name-' . substr(sha1((string) ($category['parent'] ?? 0) . ':' . (string) ($category['name'] ?? '')), 0, 20);
    }
}
