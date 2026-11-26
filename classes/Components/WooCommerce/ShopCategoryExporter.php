<?php
// SPDX-License-Identifier: AGPL-3.0-only

namespace Xentral\Components\WooCommerce;

use Automattic\WooCommerce\HttpClient\HttpClientException;
use mysqli_sql_exception;
use RuntimeException;
use Throwable;
use Xentral\Components\Database\Exception\DatabaseExceptionInterface;

final class ShopCategoryExporter
{
    private const UNSYNCED_REASON = 'Kategorie wurde im Exportlauf nicht synchronisiert.';

    private ?array $termIds = null;
    private array $deferredDeletions = [];
    private array $skippedNodes = [];
    private array $transientNodes = [];
    private bool $budgetExhausted = false;

    public function __construct(
        private $client,
        private ShopCategoryStore $store,
        private ?\Closure $logWarning = null,
        private ?\Closure $mayStartRequest = null,
        private $syncClient = null
    ) {
        $this->syncClient ??= $this->client;
    }

    private function request(string $method, string $endpoint, array ...$args): mixed
    {
        if ($this->mayStartRequest !== null && !($this->mayStartRequest)()) {
            $this->budgetExhausted = true;
            throw new RuntimeException('Zeitbudget fuer WooCommerce-Kategorieexport erreicht.');
        }
        return $this->syncClient->$method($endpoint, ...$args);
    }

    public function completed(): bool
    {
        return $this->termIds !== null && $this->deferredDeletions === [] && $this->skippedNodes === [];
    }

    public function budgetExhausted(): bool
    {
        return $this->budgetExhausted;
    }

    public static function isTransientFailure(Throwable $error): bool
    {
        for ($cause = $error; $cause !== null; $cause = $cause->getPrevious()) {
            $code = (int) $cause->getCode();
            if ($cause instanceof HttpClientException && in_array($code, [0, 408, 429, 500, 502, 503, 504], true)) {
                return true;
            }
            // MySQL connection failures, lock timeouts and deadlocks.
            if (($cause instanceof mysqli_sql_exception || $cause instanceof DatabaseExceptionInterface)
                && in_array($code, [1040, 1053, 1205, 1213, 2002, 2003, 2006, 2013, 2055], true)) {
                return true;
            }
        }
        return false;
    }

    public function hasTransientSkips(array $nodeIds): bool
    {
        return array_intersect_key($this->transientNodes, array_flip($nodeIds)) !== [];
    }

    /** @return array<int,int> OpenXE node ID to WooCommerce term ID */
    public function syncTree(): array
    {
        $this->termIds = null;
        $this->deferredDeletions = [];
        $this->skippedNodes = [];
        $this->transientNodes = [];
        $this->budgetExhausted = false;
        $tree = $this->store->tree();
        $pending = $this->store->pendingDeletions();
        if ($tree === [] && $pending === []) {
            return $this->termIds = [];
        }

        $terms = $seenSlugs = [];
        for ($page = 1; ; $page++) {
            $rows = $this->request('get', 'products/categories', [
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
                    $term = (array) $this->request('get', 'products/categories/' . $id);
                    if (($term['slug'] ?? null) !== $slug) {
                        throw new RuntimeException('WooCommerce-Slug stimmt nicht mit der vorgemerkten Kategorie ueberein.');
                    }
                    if (($term['count'] ?? null) !== 0) {
                        throw new RuntimeException('WooCommerce meldet die Kategorie nicht als leer.');
                    }
                    $this->requireEmptyCollection('products', ['category' => $id, 'status' => 'any', 'per_page' => 1], 'Produkte');
                    $this->requireEmptyCollection('products/categories', ['parent' => $id, 'per_page' => 1, 'hide_empty' => false], 'Unterkategorien');
                    // The WooCommerce term endpoint requires force for confirmed-empty categories.
                    $this->request('delete', 'products/categories/' . $id, ['force' => true]);
                    unset($terms[$id]);
                }
                $this->store->acknowledgeDeletion($id);
            } catch (Throwable $error) {
                if ($this->budgetExhausted) {
                    throw $error;
                }
                $reason = RefundStatusService::sanitizeError($error->getMessage()) ?: 'WooCommerce-Loeschung fehlgeschlagen.';
                $this->deferredDeletions[$id] = $reason;
                $blocked = ['term' => $id, 'transient' => self::isTransientFailure($error), 'reason' => $reason];
                $blockedSlugs[$slug] = $blocked;
                if (isset($terms[$id])) {
                    $blockedSlugs[$terms[$id]['slug']] = $blocked;
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
        $this->termIds = $this->syncBranch($tree, 0, $terms, $termsBySlug, $blockedSlugs);
        try {
            $this->store->updateSkipReasons(array_diff_key($this->skippedNodes, $this->transientNodes), $this->termIds);
        } catch (Throwable $error) {
            $this->warn('Gruende fuer uebersprungene WooCommerce-Kategorien konnten nicht gespeichert werden.', [
                'reason' => RefundStatusService::sanitizeError($error->getMessage()),
            ]);
        }
        return $this->termIds;
    }

    private function requireEmptyCollection(string $endpoint, array $query, string $label): void
    {
        try {
            $rows = $this->request('get', $endpoint, $query);
            $total = $this->syncClient->getLastResponse()?->getHeader('X-WP-Total');
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
                $skipped[$nodeId] = $this->skippedNodes[$nodeId] ?? self::UNSYNCED_REASON;
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

    private static function blockedSlugReason(string $slug): string
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
            $blocked = $blockedSlugs[$slug] ?? null;
            $context = ['category' => $nodeId, 'term' => $blocked['term'] ?? $wcId];
            if ($blocked !== null) {
                $reason = $blocked['transient'] ? $blocked['reason'] : self::blockedSlugReason($slug);
                $this->skipNode($node, $reason, $context, $blocked['transient']);
                continue;
            }

            try {
                $id = $this->syncNode($node, $parent, $terms[$wcId] ?? null, $termsBySlug[$slug] ?? null);
                $context['term'] = $id;
                if ($id !== $wcId && !$this->store->setWcId($nodeId, $id, $slug)) {
                    $this->skipNode($node, 'Kategorie wurde waehrend des Exports lokal entfernt.', $context);
                    continue;
                }
            } catch (Throwable $error) {
                if ($this->budgetExhausted) {
                    throw $error;
                }
                $this->skipNode($node, RefundStatusService::sanitizeError($error->getMessage()), $context,
                    self::isTransientFailure($error));
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
                $this->request('put', 'products/categories/' . $id, $changes);
            }
            return $id;
        }

        $created = (array) $this->request('post', 'products/categories', $payload + ['slug' => $slug]);
        $id = (int) ($created['id'] ?? 0);
        if ($id <= 0 || ($created['slug'] ?? null) !== $slug) {
            throw new RuntimeException('WooCommerce-Kategorie konnte nicht mit dem Slug ' . $slug . ' angelegt werden.');
        }
        return $id;
    }

    private function skipNode(array $node, string $reason, array $context, bool $transient = false): void
    {
        $reason = $reason ?: 'Kategorie konnte nicht synchronisiert werden.';
        $this->recordSkippedSubtree($node, $reason, $transient);
        $this->warn($reason, $context);
    }

    private function recordSkippedSubtree(array $node, string $reason, bool $transient): void
    {
        $this->skippedNodes[$node['id']] = $reason;
        if ($transient) {
            $this->transientNodes[$node['id']] = true;
        }
        foreach ($node['children'] as $child) {
            $this->recordSkippedSubtree($child, self::UNSYNCED_REASON, $transient);
        }
    }

    private function warn(string $message, array $context): void
    {
        if ($this->logWarning === null) {
            return;
        }
        try {
            ($this->logWarning)($message, $context);
        } catch (Throwable $error) {
            try {
                error_log($message);
            } catch (Throwable $fallbackError) {
                // A failing log must not interrupt category synchronization.
            }
        }
    }
}
