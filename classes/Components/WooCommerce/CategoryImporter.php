<?php
// SPDX-License-Identifier: AGPL-3.0-only

namespace Xentral\Components\WooCommerce;

use Closure;
use RuntimeException;

final class CategoryImporter
{
    private Closure $articleIdForSku;

    public function __construct(private $client, private ShopCategoryStore $store, callable $articleIdForSku)
    {
        $this->articleIdForSku = Closure::fromCallable($articleIdForSku);
    }

    /** Nodes are keyed by WooCommerce term ID and ordered with parents before children. */
    public function preview(): array
    {
        $siblings = $seenTerms = [];
        foreach ($this->pages('products/categories', ['hide_empty' => false]) as $term) {
            $id = (int) ($term['id'] ?? 0);
            $parent = (int) ($term['parent'] ?? 0);
            if ($id <= 0 || isset($seenTerms[$id]) || !isset($term['name']) || (string) ($term['slug'] ?? '') === '') {
                throw new RuntimeException('WooCommerce hat eine ungueltige Kategorie geliefert.');
            }
            $seenTerms[$id] = true;
            $siblings[$parent][$id] = [
                'name' => html_entity_decode((string) $term['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'slug' => (string) $term['slug'],
                'parent' => $parent, 'sort' => (int) ($term['menu_order'] ?? 0),
            ];
        }
        $nodes = $this->orderedNodes($siblings);
        if (count($nodes) !== count($seenTerms)) {
            throw new RuntimeException('Die WooCommerce-Kategoriehierarchie ist unvollstaendig oder zyklisch.');
        }

        $assignments = $unmatched = $matched = $ambiguous = [];
        // WooCommerce variations have no categories of their own.
        foreach ($this->pages('products', ['status' => 'any', 'context' => 'edit',
            '_fields' => 'id,name,sku,parent_id,categories']) as $product) {
            if (!empty($product['parent_id'])) {
                continue;
            }
            $sku = trim((string) ($product['sku'] ?? ''));
            $entry = [
                'id' => (int) $product['id'],
                'name' => html_entity_decode((string) $product['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'sku' => $sku,
            ];
            $article = $sku !== '' ? ($this->articleIdForSku)($sku) : null;
            $articleId = (int) (is_array($article) ? ($article['id'] ?? 0) : $article);
            if ($articleId <= 0) {
                $unmatched[] = $entry + ['reason' => $sku === '' ? 'keine SKU' : 'kein eindeutiger OpenXE-Artikel'];
                continue;
            }
            if (isset($matched[$articleId])) {
                $reason = ['reason' => 'OpenXE-Artikel mehrfach zugeordnet'];
                if (!isset($ambiguous[$articleId])) {
                    $unmatched[] = $matched[$articleId] + $reason;
                    $ambiguous[$articleId] = true;
                    unset($assignments[$articleId]);
                }
                $unmatched[] = $entry + $reason;
                continue;
            }

            $matched[$articleId] = $entry;
            $terms = [];
            foreach ($product['categories'] ?? [] as $term) {
                $termId = (int) ((array) $term)['id'];
                if (!isset($nodes[$termId])) {
                    throw new RuntimeException('WooCommerce-Produkt verweist auf unbekannte Kategorie ' . $termId . '.');
                }
                $terms[$termId] = $termId;
            }
            if ($terms !== []) {
                sort($terms, SORT_NUMERIC);
                $assignments[$articleId] = $terms;
            }
        }
        ksort($assignments, SORT_NUMERIC);
        return ['nodes' => $nodes, 'assignments' => $assignments, 'unmatched' => $unmatched];
    }

    public function apply(array $preview): void
    {
        $this->store->importCategories($preview['nodes'], $preview['assignments']);
    }

    private function pages(string $endpoint, array $filters): iterable
    {
        for ($page = 1; ; $page++) {
            $rows = $this->client->get($endpoint, $filters + [
                'page' => $page, 'per_page' => 100, 'orderby' => 'id', 'order' => 'asc',
            ]);
            if (!is_array($rows)) {
                throw new RuntimeException('Ungueltige WooCommerce-Antwort fuer ' . $endpoint . '.');
            }
            foreach ($rows as $row) {
                yield (array) $row;
            }
            if (count($rows) < 100) {
                return;
            }
        }
    }

    private function orderedNodes(array $siblings, int $parent = 0): array
    {
        $nodes = [];
        $children = $siblings[$parent] ?? [];
        ksort($children, SORT_NUMERIC);
        uasort($children, static fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);
        foreach ($children as $id => $node) {
            $nodes[$id] = $node;
            $nodes += $this->orderedNodes($siblings, $id);
        }
        return $nodes;
    }
}
