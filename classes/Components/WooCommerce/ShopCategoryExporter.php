<?php
// SPDX-License-Identifier: AGPL-3.0-only

namespace Xentral\Components\WooCommerce;

use RuntimeException;
use Throwable;

final class ShopCategoryExporter
{
    private ?array $termIds = null;
    private array $deferredDeletions = [];
    private array $skippedNodes = [];

    public function __construct(private $client, private ShopCategoryStore $store, private ?\Closure $logWarning = null)
    {
    }

    /** @return array<int,int> OpenXE node ID to WooCommerce term ID */
    public function syncTree(): array
    {
        $this->termIds = null;
        $this->deferredDeletions = [];
        $this->skippedNodes = [];
        $tree = $this->store->tree();
        $pending = $this->store->pendingDeletions();
        if ($tree === [] && $pending === []) {
            return $this->termIds = [];
        }

        $terms = $seenSlugs = [];
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
                if ($id <= 0 || empty($term['slug']) || isset($terms[$id]) || isset($seenSlugs[$term['slug']])) {
                    throw new RuntimeException('Ungueltige oder mehrdeutige WooCommerce-Kategorie.');
                }
                $terms[$id] = $term;
                $seenSlugs[$term['slug']] = true;
            }
            if (count($rows) < 100) {
                break;
            }
        }

        // Resolve queued terms before matching a local node by slug.
        $blockedSlugs = [];
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
                    $this->requireEmptyCollection('products', ['category' => $id, 'status' => 'any', 'per_page' => 1], 'Produkte');
                    $this->requireEmptyCollection('products/categories', ['parent' => $id, 'per_page' => 1, 'hide_empty' => false], 'Unterkategorien');
                    // The WooCommerce term endpoint requires force for confirmed-empty categories.
                    $this->client->delete('products/categories/' . $id, ['force' => true]);
                    unset($terms[$id]);
                }
                $this->store->acknowledgeDeletion($id);
            } catch (Throwable $error) {
                $reason = RefundStatusService::sanitizeError($error->getMessage()) ?: 'WooCommerce-Loeschung fehlgeschlagen.';
                $this->deferredDeletions[$id] = $reason;
                $blockedSlugs[$slug] = $id;
                if (isset($terms[$id])) {
                    $blockedSlugs[$terms[$id]['slug']] = $id;
                    unset($terms[$id]);
                }
                $this->warn('Vorgemerkte WooCommerce-Kategorie wurde nicht geloescht.', ['term' => $id, 'reason' => $reason]);
            }
        }
        if ($pending !== []) {
            try {
                $this->store->updateDeletionReasons($this->deferredDeletions);
            } catch (Throwable $error) {
                $this->warn('Gruende fuer WooCommerce-Kategorieloeschungen konnten nicht gespeichert werden.', [
                    'terms' => array_keys($pending), 'reason' => RefundStatusService::sanitizeError($error->getMessage()),
                ]);
            }
        }
        $termsBySlug = array_column($terms, null, 'slug');
        return $this->termIds = $this->syncBranch($tree, 0, $terms, $termsBySlug, $blockedSlugs);
    }

    private function requireEmptyCollection(string $endpoint, array $query, string $label): void
    {
        try {
            $rows = $this->client->get($endpoint, $query);
            $total = $this->client->getLastResponse()?->getHeader('X-WP-Total');
        } catch (Throwable $error) {
            throw new RuntimeException('WooCommerce-Abfrage fehlgeschlagen (' . $label . '): ' . $error->getMessage(), 0, $error);
        }
        if ($total === null || !is_array($rows)) {
            throw new RuntimeException('WooCommerce konnte die Kategorie nicht auf ' . $label . ' pruefen (ungueltige REST-Antwort).');
        }
        if (($total !== 0 && $total !== '0') || $rows !== []) {
            throw new RuntimeException('WooCommerce meldet vorhandene ' . $label . '.');
        }
    }

    /** @return array<int,string> WooCommerce term ID to reason for deferral */
    public function deferredDeletions(): array
    {
        return $this->deferredDeletions;
    }

    /** @return array<int,string> Skipped local category IDs and reasons */
    public function assign(int $productId, array $nodeIds, string $mode): array
    {
        $ids = $this->termIds ?? $this->syncTree();
        $categories = $skipped = [];
        foreach ($nodeIds as $nodeId) {
            if (!isset($ids[$nodeId])) {
                $skipped[$nodeId] = $this->skippedNodes[$nodeId] ?? 'Kategorie wurde im Exportlauf nicht synchronisiert.';
                continue;
            }
            $categories[] = $ids[$nodeId];
        }
        if ($categories !== [] || $mode !== 'additive') {
            // Both category sources use the same WooCommerce assignment modes.
            (new CategorySynchronizer($this->client, 0))->assign($productId, $categories, $mode);
        }
        return $skipped;
    }

    public static function blockedSlugReason(string $slug): string
    {
        return 'Slug ' . $slug . ' ist noch durch eine nicht loeschbare WooCommerce-Kategorie belegt';
    }

    private function syncBranch(array $nodes, int $parent, array $terms, array $termsBySlug, array $blockedSlugs): array
    {
        $ids = [];
        foreach ($nodes as $node) {
            $nodeId = $node['id'];
            $wcId = $node['wcId'];
            $slug = $node['slug'];
            $context = ['category' => $nodeId, 'term' => $blockedSlugs[$slug] ?? $wcId];
            if (isset($blockedSlugs[$slug])) {
                $this->skipNode($nodeId, self::blockedSlugReason($slug), $context);
                continue;
            }

            try {
                $id = $this->syncNode($node, $parent, $terms[$wcId] ?? null, $termsBySlug[$slug] ?? null);
                $context['term'] = $id;
                if ($id !== $wcId && !$this->store->setWcId($nodeId, $id, $slug)) {
                    $this->skipNode($nodeId, 'Kategorie wurde waehrend des Exports lokal entfernt.', $context);
                    continue;
                }
            } catch (Throwable $error) {
                $this->skipNode($nodeId, RefundStatusService::sanitizeError($error->getMessage()), $context);
                continue;
            }
            $ids[$nodeId] = $id;
            $ids += $this->syncBranch($node['children'], $id, $terms, $termsBySlug, $blockedSlugs);
        }
        return $ids;
    }

    private function syncNode(array $node, int $parent, ?array $match, ?array $slugMatch): int
    {
        $slug = $node['slug'];
        $payload = ['name' => $node['name'], 'parent' => $parent];
        if ($match === null && $slugMatch !== null) {
            // Recover a term created in WooCommerce before its ID could be saved locally.
            $match = $slugMatch;
            if ($node['wcId'] !== 0 || ($match['name'] ?? null) !== $node['name']
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
            return $id;
        }

        $created = (array) $this->client->post('products/categories', $payload + ['slug' => $slug]);
        $id = (int) ($created['id'] ?? 0);
        if ($id <= 0 || ($created['slug'] ?? null) !== $slug) {
            throw new RuntimeException('WooCommerce-Kategorie konnte nicht mit dem Slug ' . $slug . ' angelegt werden.');
        }
        return $id;
    }

    private function skipNode(int $nodeId, string $reason, array $context): void
    {
        $this->skippedNodes[$nodeId] = $reason ?: 'Kategorie konnte nicht synchronisiert werden.';
        $this->warn($this->skippedNodes[$nodeId], $context);
    }

    private function warn(string $message, array $context): void
    {
        if ($this->logWarning !== null) {
            ($this->logWarning)($message, $context);
        }
    }
}
