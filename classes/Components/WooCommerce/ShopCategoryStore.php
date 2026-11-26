<?php
// SPDX-License-Identifier: AGPL-3.0-only

namespace Xentral\Components\WooCommerce;

use InvalidArgumentException;
use LengthException;
use RuntimeException;
use Throwable;

final class ShopCategoryStore
{
    private const CONFIG_LIMIT = 65000;
    // Must exceed the longest article export batch, so the drain cannot run before the hash write.
    private const ARTICLE_RETRY_DELAY = 1800;
    private const CONFIG_JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES;
    private const REASON_LENGTH_LIMIT = 200;
    private const REASON_ENTRY_LIMIT = 200;
    private const EMPTY_TREE = '{"nextId":1,"nodes":{}}';
    private const ARTICLE_DEFAULTS = ['categories' => [], 'onRequest' => false];

    private string $treeKey;
    private string $deletionKey;
    private string $skipKey;
    private string $treeSyncKey;
    private string $treeSyncBudgetKey;
    private string $exportInvalidationKey;
    private string $articlePrefix;

    public function __construct(private $database, private int $shopId)
    {
        $this->treeKey = 'woocommerce_category_tree_' . $shopId;
        $this->deletionKey = 'woocommerce_category_deletions_' . $shopId;
        $this->skipKey = 'woocommerce_category_skips_' . $shopId;
        $this->treeSyncKey = 'woocommerce_category_sync_pending_' . $shopId;
        $this->treeSyncBudgetKey = 'woocommerce_category_sync_budget_' . $shopId;
        $this->exportInvalidationKey = 'woocommerce_category_export_invalidation_' . $shopId;
        $this->articlePrefix = 'woocommerce_article_' . $shopId . '_';
    }

    public function markTreeSyncPending(): void
    {
        $this->writeConfig($this->treeSyncKey, ['revision' => bin2hex(random_bytes(16))]);
    }

    public function pendingTreeSync(): ?string
    {
        return $this->readConfig($this->treeSyncKey, '{}')['revision'] ?? null;
    }

    public function acknowledgeTreeSync(string $revision): void
    {
        // Compare and clear atomically so edits made during the REST calls remain pending.
        $this->acknowledgeConfig($this->treeSyncKey, ['revision' => $revision]);
    }

    public function treeSyncBudgetExhaustions(): int
    {
        return (int) ($this->readConfig($this->treeSyncBudgetKey, '{}')['exhaustions'] ?? 0);
    }

    /** Called under the shop's tree sync advisory lock. */
    public function recordTreeSyncBudgetResult(bool $exhausted): void
    {
        $previous = $this->treeSyncBudgetExhaustions();
        if ($exhausted) {
            $this->writeConfig($this->treeSyncBudgetKey, ['exhaustions' => $previous + 1]);
        } elseif ($previous > 0) {
            $this->acknowledgeConfig($this->treeSyncBudgetKey, ['exhaustions' => $previous]);
        }
    }

    /** Enqueue in the same transaction as the tree edit. */
    public function markExportInvalidationPending(int $nodeId): void
    {
        $state = $this->lockConfig($this->exportInvalidationKey, '{}');
        if (!in_array($nodeId, $state['nodes'] ?? [], true)) {
            $state['nodes'][] = $nodeId;
        }
        $state['revision'] = bin2hex(random_bytes(16));
        $this->writeConfig($this->exportInvalidationKey, $state);
    }

    /** Commit the delayed invalidation before the export result writes the new hash. */
    public function markArticleExportInvalidationPending(int $articleId): void
    {
        $this->database->beginTransaction();
        try {
            $state = $this->lockConfig($this->exportInvalidationKey, '{}');
            $deadlines = $this->articleRetryDeadlines($state);
            $deadlines[$articleId] = time() + self::ARTICLE_RETRY_DELAY;
            $state['articles'] = $deadlines;
            $state['revision'] = bin2hex(random_bytes(16));
            $this->writeConfig($this->exportInvalidationKey, $state);
            $this->database->commit();
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }
    }

    private function articleRetryDeadlines(array $state): array
    {
        $articles = $state['articles'] ?? [];
        // Older entries were queued after the hash write and can be drained immediately.
        return array_is_list($articles) ? array_fill_keys($articles, 0) : $articles;
    }

    public function hasPendingExportInvalidations(): bool
    {
        return $this->readConfig($this->exportInvalidationKey, '{}') !== [];
    }

    /** Assignment rows are updated outside the tree locks. */
    public function drainExportInvalidations(): void
    {
        $state = $this->readConfig($this->exportInvalidationKey, '{}');
        $nodeIds = $state['nodes'] ?? [];
        $deadlines = $this->articleRetryDeadlines($state);
        $now = time();
        $waiting = array_filter($deadlines, static fn (int $notBefore): bool => $notBefore > $now);
        $articleIds = array_keys(array_diff_key($deadlines, $waiting));
        if ($nodeIds === [] && $articleIds === []) {
            return;
        }
        $this->database->beginTransaction();
        try {
            if ($nodeIds !== []) {
                $articleIds = array_unique(array_merge($articleIds, $this->assignedArticlesMatching($nodeIds)));
            }
            sort($articleIds, SORT_NUMERIC);
            foreach ($articleIds as $articleId) {
                $this->invalidateArticleExport($articleId);
            }
            // Lock the current queue after the assignment rows. Roll back stale invalidations
            // if a concurrent export has already replaced their deadlines.
            if ($this->readConfig($this->exportInvalidationKey, '{}', true) !== $state) {
                $this->database->rollBack();
                return;
            }
            if ($waiting === []) {
                $this->acknowledgeConfig($this->exportInvalidationKey, $state);
            } else {
                unset($state['nodes']);
                $state['articles'] = $waiting;
                $this->writeConfig($this->exportInvalidationKey, $state);
            }
            $this->database->commit();
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }
    }

    public function invalidateArticleExport(int $articleId, bool $allShops = false): void
    {
        // The normal and matrix variant payloads are exported through their parent article.
        $this->database->perform(
            "UPDATE artikel_onlineshops SET last_article_hash = '' WHERE "
            . ($allShops ? '' : 'shop = :shop AND ')
            . '(artikel = :article OR artikel = (SELECT variante_von FROM artikel WHERE id = :source))',
            ($allShops ? [] : ['shop' => $this->shopId]) + ['article' => $articleId, 'source' => $articleId]
        );
    }

    private function acknowledgeConfig(string $key, array $state): void
    {
        $this->database->perform('DELETE FROM konfiguration WHERE name = :name AND wert = :revision', [
            'name' => $key,
            'revision' => json_encode($state, self::CONFIG_JSON_FLAGS),
        ]);
    }

    /** Each node includes its local id and a list of children ordered by sort, then id. */
    public function tree(): array
    {
        $state = $this->readConfig($this->treeKey, self::EMPTY_TREE);
        $byParent = [];
        foreach ($state['nodes'] as $id => $node) {
            $byParent[$node['parent']][] = ['id' => $id] + $node;
        }

        $children = static function (int $parent) use ($byParent, &$children): array {
            $siblings = $byParent[$parent] ?? [];
            usort($siblings, static fn (array $a, array $b): int => [$a['sort'], $a['id']] <=> [$b['sort'], $b['id']]);
            foreach ($siblings as &$node) {
                $node['children'] = $children($node['id']);
            }
            return $siblings;
        };
        return $children(0);
    }

    public function create(string $name, int $parent = 0): int
    {
        return $this->mutateTree(function (array &$state) use ($name, $parent): int {
            $nodes = $state['nodes'];
            if ($parent !== 0) {
                $this->requireNode($nodes, $parent);
            }
            $sort = -1;
            foreach ($nodes as $node) {
                if ($node['parent'] === $parent) {
                    $sort = max($sort, $node['sort']);
                }
            }

            $id = $state['nextId']++;
            $state['nodes'][$id] = [
                'name' => $name,
                'parent' => $parent,
                'sort' => $sort < PHP_INT_MAX ? $sort + 1 : $sort,
                'wcId' => 0,
                'slug' => $this->uniqueSlug($name, $nodes, $state['pendingDeletions'] ?? []),
            ];
            return $id;
        });
    }

    public function rename(int $id, string $name): void
    {
        $this->mutateTree(function (array &$state) use ($id, $name): void {
            $this->requireNode($state['nodes'], $id);
            $state['nodes'][$id]['name'] = $name;
        });
    }

    public function move(int $id, int $parent, int $sort): void
    {
        $this->mutateTree(function (array &$state) use ($id, $parent, $sort): void {
            $nodes = $state['nodes'];
            $this->requireNode($nodes, $id);
            for ($ancestor = $parent; $ancestor !== 0; $ancestor = $nodes[$ancestor]['parent']) {
                if ($ancestor === $id) {
                    throw new InvalidArgumentException('Die Kategorie kann nicht unter sich selbst verschoben werden.');
                }
                $this->requireNode($nodes, $ancestor);
            }
            $state['nodes'][$id]['parent'] = $parent;
            $state['nodes'][$id]['sort'] = $sort;
        });
    }

    public function delete(int $id): void
    {
        $this->mutateTree(function (array &$state) use ($id): void {
            $this->requireNode($state['nodes'], $id);
            if (in_array($id, array_column($state['nodes'], 'parent'), true)
                || $this->assignedArticles($id, true) !== []) {
                throw new RuntimeException("Kategorie ist nicht leer.");
            }
            $node = $state['nodes'][$id];
            if ($node['wcId'] > 0) {
                // Keep the WooCommerce identity until the exporter confirms deletion.
                $this->queueTermDeletion($state, $node['wcId'], $node['slug']);
            }
            unset($state['nodes'][$id]);
        });
    }

    /** @return array<int,string> WooCommerce term IDs and slugs awaiting deletion */
    public function pendingDeletions(): array
    {
        return $this->readConfig($this->treeKey, self::EMPTY_TREE)['pendingDeletions'] ?? [];
    }

    /** @return array<int,string> Last reason a pending deletion was deferred. */
    public function pendingDeletionReasons(): array
    {
        $state = $this->readConfig($this->treeKey, self::EMPTY_TREE);
        return array_intersect_key(
            $this->readConfig($this->deletionKey, '{}') + ($state['pendingDeletionReasons'] ?? []),
            $state['pendingDeletions'] ?? []
        );
    }

    public function updateDeletionReasons(array $deferred): void
    {
        // Serialize reason updates and pending identity changes with the same shop lock.
        $this->mutate(function (array $state) use ($deferred): void {
            $stored = $this->readConfig($this->deletionKey, '{}');
            $reasons = $stored + ($state['pendingDeletionReasons'] ?? []);
            // Put reasons observed in this run at the end, in observation order.
            $reasons = array_diff_key($reasons, $deferred) + $deferred;
            $reasons = self::boundReasons(array_intersect_key($reasons, $state['pendingDeletions'] ?? []));
            if ($reasons !== $stored) {
                $this->writeConfig($this->deletionKey, $reasons);
            }
            if (isset($state['pendingDeletionReasons'])) {
                unset($state['pendingDeletionReasons']);
                $state['nodes'] = (object) $state['nodes'];
                $this->writeConfig($this->treeKey, $state);
            }
        });
    }

    /** @return array<int,string> Last export skip reasons by local node ID. */
    public function skipReasons(): array
    {
        $state = $this->readConfig($this->treeKey, self::EMPTY_TREE);
        return array_intersect_key($this->readConfig($this->skipKey, '{}'), $state['nodes']);
    }

    public function updateSkipReasons(array $skipped, array $synced): void
    {
        $this->mutate(function (array $state) use ($skipped, $synced): void {
            $stored = $this->readConfig($this->skipKey, '{}');
            $reasons = array_diff_key($stored, $skipped) + $skipped;
            $reasons = self::boundReasons(array_intersect_key(array_diff_key($reasons, $synced), $state['nodes']));
            if ($reasons !== $stored) {
                $this->writeConfig($this->skipKey, $reasons);
            }
        });
    }

    private static function boundReasons(array $reasons): array
    {
        $reasons = array_slice($reasons, -self::REASON_ENTRY_LIMIT, null, true);
        foreach ($reasons as &$entry) {
            preg_match('/^.{0,' . self::REASON_LENGTH_LIMIT . '}/us', $entry, $match);
            $entry = $match[0] ?? 'Fehlerdetails nicht verfuegbar.';
        }
        unset($entry);
        // Unicode escaping can exceed the TEXT byte limit even with bounded character counts.
        while (strlen(json_encode($reasons, self::CONFIG_JSON_FLAGS)) >= self::CONFIG_LIMIT) {
            unset($reasons[array_key_first($reasons)]);
        }
        return $reasons;
    }

    private function queueTermDeletion(array &$state, int $wcId, string $slug): void
    {
        $state['pendingDeletions'][$wcId] = $slug;
    }

    public function acknowledgeDeletion(int $wcId): void
    {
        $this->mutateTree(static function (array &$state) use ($wcId): void {
            unset($state['pendingDeletions'][$wcId], $state['pendingDeletionReasons'][$wcId]);
        });
    }

    /** Import the remote hierarchy and article assignments together under the tree lock. */
    public function importCategories(array $terms, array $assignments): void
    {
        $this->mutateTree(function (array &$state) use ($terms, $assignments): void {
            if ($state['nodes'] !== [] || !empty($state['pendingDeletions'])) {
                throw new RuntimeException('Die Erstuebernahme erfordert einen leeren Shop-Kategorienbaum ohne offene Loeschungen.');
            }
            $ids = [0 => 0];
            $slugs = [];
            foreach ($terms as $termId => $term) {
                if ($termId <= 0 || !isset($ids[$term['parent']]) || $term['slug'] === '' || isset($slugs[$term['slug']])) {
                    throw new InvalidArgumentException('Ungueltige Import-Kategorie ' . $termId . '.');
                }
                $id = $state['nextId']++;
                $ids[$termId] = $id;
                $slugs[$term['slug']] = true;
                $state['nodes'][$id] = ['name' => $term['name'], 'parent' => $ids[$term['parent']],
                    'sort' => $term['sort'], 'wcId' => $termId, 'slug' => $term['slug']];
            }
            foreach ($assignments as $articleId => $termIds) {
                $localIds = [];
                foreach ($termIds as $termId) {
                    if (empty($ids[$termId])) {
                        throw new InvalidArgumentException('Ungueltige Kategorie ' . $termId . ' in der Import-Zuordnung.');
                    }
                    $localIds[$ids[$termId]] = $ids[$termId];
                }
                $this->writeArticleField($articleId, 'categories', array_values($localIds));
            }
        });
    }

    /** Bind the remote term or queue it for deletion if the local node disappeared. */
    public function setWcId(int $id, int $wcId, string $slug): bool
    {
        return $this->mutateTree(function (array &$state) use ($id, $wcId, $slug): bool {
            $nodes = $state['nodes'];
            if (!isset($nodes[$id])) {
                // An initial import could have adopted the term since the export read the tree.
                if (!in_array($wcId, array_column($nodes, 'wcId'), true)) {
                    $this->queueTermDeletion($state, $wcId, $slug);
                }
                return false;
            }
            // Once bound to a WooCommerce term, the slug is the category's permanent identity.
            if (!empty($nodes[$id]['wcId']) && $slug !== $nodes[$id]['slug']) {
                throw new InvalidArgumentException('Der Slug einer bereits verknuepften Kategorie kann nicht geaendert werden.');
            }
            unset($nodes[$id]);
            $pending = $state['pendingDeletions'] ?? [];
            if ($slug === '' || isset($pending[$wcId]) || in_array($slug, $pending, true)
                || in_array($slug, array_column($nodes, 'slug'), true)) {
                throw new InvalidArgumentException('Der Kategorie-Slug muss im Shop eindeutig und darf nicht leer sein.');
            }
            $state['nodes'][$id]['wcId'] = $wcId;
            $state['nodes'][$id]['slug'] = $slug;
            return true;
        });
    }

    public function assign(int $articleId, array $categoryIds): void
    {
        $this->mutate(function (array $state) use ($articleId, $categoryIds): void {
            $categories = [];
            foreach ($categoryIds as $categoryId) {
                $id = is_int($categoryId) || is_string($categoryId)
                    ? filter_var($categoryId, FILTER_VALIDATE_INT) : false;
                if ($id === false) {
                    throw new InvalidArgumentException('Ungueltige Kategorie-ID.');
                }
                $this->requireNode($state['nodes'], $id);
                $categories[$id] = $id;
            }
            $this->writeArticleField($articleId, 'categories', array_values($categories));
        });
    }

    public function categoriesOf(int $articleId): array
    {
        return $this->readArticle($articleId)['categories'];
    }

    public function articlesIn(int $categoryId): array
    {
        return $this->assignedArticles($categoryId);
    }

    public function articleConfigPrefix(): string
    {
        return $this->articlePrefix;
    }

    /** @return array<int,array{categories:list<int>,onRequest:bool}> */
    public function articleStates(array $articleIds): array
    {
        if ($articleIds === []) {
            return [];
        }
        $states = array_fill_keys($articleIds, self::ARTICLE_DEFAULTS);
        $params = [];
        foreach (array_keys($states) as $id) {
            $params['article_' . $id] = $this->articlePrefix . $id;
        }
        $placeholders = ':' . implode(', :', array_keys($params));
        $rows = $this->database->fetchAll('SELECT name, wert FROM konfiguration WHERE name IN (' . $placeholders . ')', $params);
        foreach ($rows as $row) {
            $id = (int) substr($row['name'], strlen($this->articlePrefix));
            $states[$id] = json_decode($row['wert'], true, 512, JSON_THROW_ON_ERROR) + self::ARTICLE_DEFAULTS;
        }
        return $states;
    }

    public function articlesWithoutCategory(array $articleIds): array
    {
        if ($articleIds === []) {
            return [];
        }
        $assigned = array_fill_keys($this->assignedArticles(), true);
        return array_values(array_filter($articleIds, static fn (int $id): bool => !isset($assigned[$id])));
    }

    public function setOnRequest(int $articleId, bool $on): void
    {
        $this->mutate(function () use ($articleId, $on): void {
            $this->writeArticleField($articleId, 'onRequest', $on);
        });
    }

    /** A caller comparing and writing in one transaction can lock the current value for its audit. */
    public function isOnRequest(int $articleId, bool $forUpdate = false): bool
    {
        if ($forUpdate) {
            if (!$this->database->inTransaction()) {
                throw new RuntimeException('Eine Transaktion ist fuer den Sperrzugriff erforderlich.');
            }
            $this->lockConfig($this->treeKey, self::EMPTY_TREE);
            return $this->lockArticle($articleId)['onRequest'] === true;
        }
        return $this->readArticle($articleId)['onRequest'] === true;
    }

    private function requireNode(array $nodes, int $id): void
    {
        if (!isset($nodes[$id])) {
            throw new InvalidArgumentException('Kategorie ' . $id . ' wurde nicht gefunden.');
        }
    }

    private function uniqueSlug(string $name, array $nodes, array $pending): string
    {
        $ascii = strtr($name, [
            "\u{00c4}" => 'ae', "\u{00e4}" => 'ae', "\u{00d6}" => 'oe', "\u{00f6}" => 'oe',
            "\u{00dc}" => 'ue', "\u{00fc}" => 'ue', "\u{00df}" => 'ss', "\u{1e9e}" => 'ss',
        ]);
        $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)), '-');
        $base = $base === '' ? 'kategorie' : $base;
        $taken = array_fill_keys(array_merge(array_column($nodes, 'slug'), $pending), true);
        $slug = $base;
        for ($suffix = 2; isset($taken[$slug]); $suffix++) {
            $slug = $base . '-' . $suffix;
        }
        return $slug;
    }

    private function assignedArticles(?int $categoryId = null, bool $forUpdate = false): array
    {
        return $this->assignedArticlesMatching($categoryId === null ? [] : [$categoryId], $forUpdate);
    }

    private function assignedArticlesMatching(array $categoryIds, bool $forUpdate = false): array
    {
        $rows = $this->database->fetchAll(
            "SELECT name, wert FROM konfiguration WHERE name LIKE :prefix ESCAPE '!'"
            . ($forUpdate ? ' FOR UPDATE' : ''),
            ['prefix' => str_replace('_', '!_', $this->articlePrefix) . '%']
        );
        $ids = [];
        foreach ($rows as $row) {
            $suffix = substr($row['name'], strlen($this->articlePrefix));
            if (!preg_match('/^[1-9][0-9]*$/D', $suffix)) {
                continue;
            }
            $categories = json_decode($row['wert'], true, 512, JSON_THROW_ON_ERROR)['categories'] ?? [];
            if ($categoryIds === [] ? $categories !== [] : array_intersect($categoryIds, $categories) !== []) {
                $ids[] = (int) $suffix;
            }
        }
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    private function readArticle(int $articleId): array
    {
        return $this->readConfig($this->articlePrefix . $articleId, '{}') + self::ARTICLE_DEFAULTS;
    }

    private function lockArticle(int $articleId): array
    {
        return $this->lockConfig($this->articlePrefix . $articleId, '{"categories":[],"onRequest":false}')
            + self::ARTICLE_DEFAULTS;
    }

    private function writeArticleField(int $articleId, string $field, array|bool $value): void
    {
        $article = $this->lockArticle($articleId);
        $article[$field] = $value;
        $this->writeConfig($this->articlePrefix . $articleId, $article);
    }

    private function mutateTree(callable $write): mixed
    {
        return $this->mutate(function (array $state) use ($write): mixed {
            $result = $write($state);
            // Numeric keys must remain a JSON object, including when the tree is empty.
            $state['nodes'] = (object) $state['nodes'];
            $this->writeConfig($this->treeKey, $state);
            return $result;
        });
    }

    private function mutate(callable $write): mixed
    {
        $ownTransaction = !$this->database->inTransaction();
        if ($ownTransaction) {
            $this->database->beginTransaction();
        }
        try {
            $result = $write($this->lockConfig($this->treeKey, self::EMPTY_TREE));
            if ($ownTransaction) {
                $this->database->commit();
            }
            return $result;
        } catch (Throwable $error) {
            if ($ownTransaction && $this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }
    }

    private function lockConfig(string $name, string $default): array
    {
        // Materialize an absent row before the locking read to avoid locking its index gap.
        $this->database->perform(
            'INSERT INTO konfiguration (name, wert, adresse, firma) VALUES (:name, :wert, 0, 0)'
            . ' ON DUPLICATE KEY UPDATE name = VALUES(name)',
            ['name' => $name, 'wert' => $default]
        );
        return $this->readConfig($name, $default, true);
    }

    private function readConfig(string $name, string $default, bool $forUpdate = false): array
    {
        $json = $this->database->fetchValue(
            'SELECT wert FROM konfiguration WHERE name = :name' . ($forUpdate ? ' FOR UPDATE' : ''),
            ['name' => $name]
        );
        return json_decode($json === false || $json === null ? $default : $json, true, 512, JSON_THROW_ON_ERROR);
    }

    private function writeConfig(string $name, array $value): void
    {
        $json = json_encode($value, self::CONFIG_JSON_FLAGS);
        if (strlen($json) >= self::CONFIG_LIMIT) {
            throw new LengthException('WooCommerce-Konfiguration ' . $name . ' ist zu gross (muss unter 65000 Bytes bleiben).');
        }
        $this->database->perform(
            'INSERT INTO konfiguration (name, wert, adresse, firma) VALUES (:name, :wert, 0, 0)'
            . ' ON DUPLICATE KEY UPDATE wert = VALUES(wert)',
            ['name' => $name, 'wert' => $json]
        );
    }
}
