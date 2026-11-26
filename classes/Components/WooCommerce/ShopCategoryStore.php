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
    private const EMPTY_TREE = '{"nextId":1,"nodes":{}}';
    private const ARTICLE_DEFAULTS = ['categories' => [], 'onRequest' => false];

    private string $treeKey;
    private string $articlePrefix;

    public function __construct(private $database, int $shopId)
    {
        $this->treeKey = 'woocommerce_category_tree_' . $shopId;
        $this->articlePrefix = 'woocommerce_article_' . $shopId . '_';
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
                'slug' => $this->uniqueSlug($name, $nodes),
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
            unset($state['nodes'][$id]);
        });
    }

    /** Store an existing WooCommerce term's identity, including its original slug. */
    public function setWcId(int $id, int $wcId, string $slug): void
    {
        $this->mutateTree(function (array &$state) use ($id, $wcId, $slug): void {
            $nodes = $state['nodes'];
            $this->requireNode($nodes, $id);
            // Once bound to a WooCommerce term, the slug is the category's permanent identity.
            if (!empty($nodes[$id]['wcId']) && $slug !== $nodes[$id]['slug']) {
                throw new InvalidArgumentException('Der Slug einer bereits verknuepften Kategorie kann nicht geaendert werden.');
            }
            unset($nodes[$id]);
            if ($slug === '' || in_array($slug, array_column($nodes, 'slug'), true)) {
                throw new InvalidArgumentException('Der Kategorie-Slug muss im Shop eindeutig und darf nicht leer sein.');
            }
            $state['nodes'][$id]['wcId'] = $wcId;
            $state['nodes'][$id]['slug'] = $slug;
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

    private function uniqueSlug(string $name, array $nodes): string
    {
        $ascii = strtr($name, [
            "\u{00c4}" => 'ae', "\u{00e4}" => 'ae', "\u{00d6}" => 'oe', "\u{00f6}" => 'oe',
            "\u{00dc}" => 'ue', "\u{00fc}" => 'ue', "\u{00df}" => 'ss', "\u{1e9e}" => 'ss',
        ]);
        $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)), '-');
        $base = $base === '' ? 'kategorie' : $base;
        $taken = array_fill_keys(array_column($nodes, 'slug'), true);
        $slug = $base;
        for ($suffix = 2; isset($taken[$slug]); $suffix++) {
            $slug = $base . '-' . $suffix;
        }
        return $slug;
    }

    private function assignedArticles(?int $categoryId = null, bool $forUpdate = false): array
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
            if ($categoryId === null ? $categories !== [] : in_array($categoryId, $categories, true)) {
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
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
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
