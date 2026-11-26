<?php
// SPDX-License-Identifier: AGPL-3.0-only

namespace Xentral\Components\WooCommerce;

use RuntimeException;
use Throwable;

final class ShopCategoryExporter
{
    private ?array $termIds = null;
    private array $deferredDeletions = [];

    public function __construct(private $client, private ShopCategoryStore $store)
    {
    }

    /** @return array<int,int> OpenXE node ID to WooCommerce term ID */
    public function syncTree(): array
    {
        $this->termIds = null;
        $this->deferredDeletions = [];
        $tree = $this->store->tree();
        $pending = $this->store->pendingDeletions();
        if ($tree === [] && $pending === []) {
            return $this->termIds = [];
        }

        $terms = $termsBySlug = [];
        for ($page = 1; ; $page++) {
            $rows = $this->client->get('products/categories', [
                'per_page' => 100, 'page' => $page, 'hide_empty' => false, 'orderby' => 'id',
            ]);
            if (!is_array($rows)) {
                throw new RuntimeException('Ungueltige WooCommerce-Kategorienliste.');
            }
            foreach ($rows as $row) {
                $term = (array) $row;
                $id = (int) ($term['id'] ?? 0);
                if ($id <= 0 || empty($term['slug']) || isset($terms[$id]) || isset($termsBySlug[$term['slug']])) {
                    throw new RuntimeException('Ungueltige oder mehrdeutige WooCommerce-Kategorie.');
                }
                $terms[$id] = $term;
                $termsBySlug[$term['slug']] = $term;
            }
            if (count($rows) < 100) {
                break;
            }
        }

        $ids = $this->syncBranch($tree, 0, $terms, $termsBySlug);
        foreach ($pending as $id => $slug) {
            try {
                if (isset($terms[$id])) {
                    $term = (array) $this->client->get('products/categories/' . $id);
                    if (($term['slug'] ?? null) !== $slug) {
                        throw new RuntimeException('WooCommerce-Slug stimmt nicht mit der vorgemerkten Kategorie ueberein.');
                    }
                    if (($term['count'] ?? null) !== 0) {
                        throw new RuntimeException('WooCommerce meldet die Kategorie nicht als leer.');
                    }
                    $this->client->delete('products/categories/' . $id, ['force' => true]);
                }
                $this->store->acknowledgeDeletion($id);
            } catch (Throwable $error) {
                $this->deferredDeletions[$id] = RefundStatusService::sanitizeError($error->getMessage());
            }
        }
        return $this->termIds = $ids;
    }

    /** @return array<int,string> WooCommerce term ID to reason for deferral */
    public function deferredDeletions(): array
    {
        return $this->deferredDeletions;
    }

    public function assign(int $productId, int $articleId, string $mode): void
    {
        $ids = $this->termIds ?? $this->syncTree();
        $categories = [];
        foreach ($this->store->categoriesOf($articleId) as $nodeId) {
            if (!isset($ids[$nodeId])) {
                throw new RuntimeException('Shop-Kategorie ' . $nodeId . ' wurde im Exportlauf nicht synchronisiert.');
            }
            $categories[] = $ids[$nodeId];
        }
        if ($categories !== [] || $mode !== 'additive') {
            // Both category sources use the same WooCommerce assignment modes.
            (new CategorySynchronizer($this->client, 0))->assign($productId, $categories, $mode);
        }
    }

    private function syncBranch(array $nodes, int $parent, array $terms, array $termsBySlug): array
    {
        $ids = [];
        foreach ($nodes as $node) {
            $wcId = $node['wcId'];
            $slug = $node['slug'];
            $match = $terms[$wcId] ?? null;
            $payload = ['name' => $node['name'], 'parent' => $parent];
            if ($match === null && isset($termsBySlug[$slug])) {
                // Recover a term created in WooCommerce before its ID could be saved locally.
                $match = $termsBySlug[$slug];
                if ($wcId !== 0 || ($match['name'] ?? null) !== $node['name']
                    || (int) ($match['parent'] ?? -1) !== $parent) {
                    throw new RuntimeException('WooCommerce-Slug bereits belegt: ' . $slug);
                }
            }
            if ($match !== null) {
                $id = (int) $match['id'];
                $changes = array_diff_assoc($payload, [
                    'name' => (string) ($match['name'] ?? ''), 'parent' => (int) ($match['parent'] ?? -1),
                ]);
                if ($changes !== []) {
                    $this->client->put('products/categories/' . $id, $changes);
                }
            } else {
                $created = (array) $this->client->post('products/categories', $payload + ['slug' => $slug]);
                $id = (int) ($created['id'] ?? 0);
                if ($id <= 0 || ($created['slug'] ?? null) !== $slug) {
                    throw new RuntimeException('WooCommerce-Kategorie konnte nicht mit dem Slug ' . $slug . ' angelegt werden.');
                }
            }

            if ($id !== $wcId) {
                $this->store->setWcId($node['id'], $id, $slug);
            }
            $ids[$node['id']] = $id;
            $ids += $this->syncBranch($node['children'], $id, $terms, $termsBySlug);
        }
        return $ids;
    }
}
