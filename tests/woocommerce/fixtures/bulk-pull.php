<?php
// SPDX-License-Identifier: AGPL-3.0-only

final class BulkPullDatabase
{
    public array $articles = [
        9 => ['id' => 9, 'nummer' => 'LOCAL-9', 'name_de' => 'Artikel 9', 'shop_name_de' => 'Alter Name', 'projekt' => 0],
        10 => ['id' => 10, 'nummer' => 'LOCAL-10', 'name_de' => 'Artikel 10', 'projekt' => 0],
    ];
    public array $assignments = [9 => true, 10 => true];
    public array $foreignNumbers = [9 => 'SHOP-9'];
    public array $audit = [];
    public array $writes = [];
    private ?array $snapshot = null;

    public function fetchRow(string $sql, array $params)
    {
        if (str_contains($sql, 'FROM shopexport')) {
            return ['id' => 7, 'modulename' => 'shopimporter_woocommerce', 'projekt' => 0, 'einstellungen_json' => '{}'];
        }
        if (str_contains($sql, 'FROM artikel WHERE')) {
            return $this->articles[$params['article']] ?? false;
        }
        if (str_contains($sql, 'FROM artikel_onlineshops')) {
            return false;
        }
        throw new LogicException($sql);
    }

    public function fetchAll(string $sql, array $params): array
    {
        if (str_contains($sql, 'FROM verkaufspreise')) {
            return [['preis' => '19.99']];
        }
        if (str_contains($sql, 'FROM artikel AS a')) {
            $rows = [];
            foreach ($this->articles as $id => $article) {
                if (($this->assignments[$id] ?? false) && ($this->foreignNumbers[$id] ?? $article['nummer']) === $params['sku']) {
                    $rows[] = $article;
                }
            }
            return $rows;
        }
        throw new LogicException($sql);
    }

    public function fetchValue(string $sql, array $params)
    {
        if (str_contains($sql, '_LOCK(')) {
            return 1;
        }
        if (str_contains($sql, 'SELECT id FROM exportvorlage') || str_contains($sql, 'SELECT id FROM importvorlage')) {
            return 25;
        }
        if (($params['name'] ?? '') === 'importvorlage_update_only') {
            return '25';
        }
        if (str_contains($sql, 'FROM artikelnummer_fremdnummern')) {
            return $this->foreignNumbers[$params['article']] ?? false;
        }
        if (str_contains($sql, 'FROM konfiguration')) {
            return $this->audit[$params['name']] ?? false;
        }
        if (str_contains($sql, 'FROM shopexport')) {
            return 0;
        }
        throw new LogicException($sql);
    }

    public function perform(string $sql, array $params): void
    {
        $this->writes[] = [$sql, $params];
        if (str_starts_with($sql, 'UPDATE artikel_onlineshops SET last_article_hash')) {
            return;
        }
        if (preg_match('/^UPDATE artikel SET `([a-z_]+)`/', $sql, $matches)) {
            $this->articles[$params['article']][$matches[1]] = $params['value'];
        } elseif (str_starts_with($sql, 'INSERT INTO konfiguration')) {
            $this->audit[$params['name']] = $params['wert'] ?? ($this->audit[$params['name']] ?? '[]');
        } else {
            throw new LogicException($sql);
        }
    }

    public function inTransaction(): bool { return $this->snapshot !== null; }
    public function beginTransaction(): void { $this->snapshot = [$this->articles, $this->audit]; }
    public function commit(): void { $this->snapshot = null; }
    public function rollBack(): void
    {
        [$this->articles, $this->audit] = $this->snapshot;
        $this->snapshot = null;
    }
}

final class BulkPullClient
{
    public array $pages = [1 => [
        ['id' => 101, 'name' => 'Vorschau', 'sku' => 'SHOP-9',
            'meta_data' => [['key' => 'rank_math_title', 'value' => 'SEO Vorschau']]],
    ]];
    public array $requests = [];
    public array $lookupRequests = [];
    public array $variations = [];
    public bool $incompleteLookup = false;
    private int $total = 0;
    private ?int $lookupPages = null;

    public function get(string $endpoint, array $query): array
    {
        if (isset($query['sku'])) {
            $this->lookupRequests[] = $query['sku'];
            same('any', $query['status']);
            $terms = array_map('strtolower', array_merge([$query['sku']], explode(',', $query['sku'])));
            $rows = array_values(array_filter(array_merge(...array_values($this->pages + $this->variations)),
                static fn (array $row): bool => in_array(strtolower($row['sku'] ?? ''), $terms, true)));
            $this->total = count($rows) + (int) $this->incompleteLookup;
            $this->lookupPages = (int) ceil($this->total / $query['per_page']);
            return array_map(static fn (array $row): array => $row + ['parent_id' => 0],
                array_slice($rows, ($query['page'] - 1) * $query['per_page'], $query['per_page']));
        }
        $this->lookupPages = null;
        $this->requests[] = [$endpoint, $query['page'], $query['per_page']];
        $rows = $endpoint === 'products' ? ($this->pages[$query['page']] ?? []) : ($this->variations[$endpoint] ?? []);
        $this->total = count($rows);
        return $rows;
    }

    public function getLastResponse(): self { return $this; }
    public function getHeader(string $name): ?string
    {
        return match ($name) {
            'X-WP-Total' => (string) $this->total,
            'X-WP-TotalPages' => $this->lookupPages === null ? null : (string) $this->lookupPages,
            default => null,
        };
    }
}
