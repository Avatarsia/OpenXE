<?php
// SPDX-License-Identifier: AGPL-3.0-only

use Xentral\Components\WooCommerce\CategoryImporter;
use Xentral\Components\WooCommerce\ShopCategoryStore;

final class CategoryExportClient
{
    public array $terms;
    public array $products;
    public array $writes = [];
    public ?Closure $duringPost = null;
    public ?string $failedLookup = null;
    public bool $omitTotal = false;
    public array $reads = [];
    private ?int $total = null;
    private int $nextId = 1001;

    public function __construct(array $terms = [], array $products = [])
    {
        $this->terms = array_column($terms, null, 'id');
        $this->products = array_column($products, null, 'id');
    }

    public function get(string $endpoint, array $params = []): object|array
    {
        $this->reads[] = [$endpoint, $params];
        $this->total = null;
        if (preg_match('#^products/categories/(\d+)$#', $endpoint, $matches)) {
            return (object) $this->terms[(int) $matches[1]];
        }
        if (preg_match('#^products/(\d+)$#', $endpoint, $matches)) {
            return (object) $this->products[(int) $matches[1]];
        }
        $rows = match ($endpoint) {
            'products/categories' => $this->terms,
            'products' => $this->products,
        };
        if ($params['per_page'] === 1 && $endpoint === $this->failedLookup) {
            throw new RuntimeException('Lookup failed');
        }
        if (isset($params['category'])) {
            $rows = array_filter($rows, static fn (array $row): bool =>
                (($row['status'] ?? 'publish') === 'publish' || ($params['status'] ?? '') === 'any')
                && in_array($params['category'], array_column($row['categories'], 'id'), true));
        }
        if (isset($params['parent'])) {
            $rows = array_filter($rows, static fn (array $row): bool => $row['parent'] === $params['parent']);
        }
        $this->total = $this->omitTotal ? null : count($rows);
        return array_map(static fn (array $row): object => (object) $row,
            array_slice($rows, (($params['page'] ?? 1) - 1) * $params['per_page'], $params['per_page']));
    }

    public function getLastResponse(): object
    {
        return new class($this->total) {
            public function __construct(private ?int $total) {}
            public function getHeader(string $name): ?string
            {
                return $name === 'X-WP-Total' && $this->total !== null ? (string) $this->total : null;
            }
        };
    }

    public function post(string $endpoint, array $payload): object
    {
        $this->writes[] = ['POST', $endpoint, $payload];
        $id = $this->nextId++;
        $term = $this->terms[$id] = ['id' => $id, 'count' => 0] + $payload;
        if ($this->duringPost !== null) {
            ($this->duringPost)();
        }
        return (object) $term;
    }

    public function put(string $endpoint, array $payload): object
    {
        $this->writes[] = ['PUT', $endpoint, $payload];
        $id = (int) basename($endpoint);
        if (str_starts_with($endpoint, 'products/categories/')) {
            return (object) ($this->terms[$id] = $payload + $this->terms[$id]);
        }
        return (object) ($this->products[$id] = $payload + $this->products[$id]);
    }

    public function delete(string $endpoint, array $params): void
    {
        $this->writes[] = ['DELETE', $endpoint, $params];
        unset($this->terms[(int) basename($endpoint)]);
    }
}

function categoryTerm(int $id, string $name, string $slug, int $parent = 0, int $count = 0): array
{
    return compact('id', 'name', 'slug', 'parent', 'count');
}

function categoryImportClient(): CategoryExportClient
{
    return new CategoryExportClient([
        categoryTerm(8, 'Tools', 'tools', 2),
        categoryTerm(6, 'V6 Nozzles', 'v6-nozzles-original', 1),
        categoryTerm(1, 'Hotends', 'hotends'),
        categoryTerm(2, 'Accessories', 'accessories'),
        categoryTerm(3, 'Electronics', 'electronics'),
        categoryTerm(4, 'Printers', 'printers'),
        categoryTerm(5, 'Filaments', 'filaments'),
        categoryTerm(7, 'Heatbreaks', 'heatbreaks', 1),
        categoryTerm(9, 'Drivers', 'drivers', 8),
        categoryTerm(10, 'Boards', 'boards', 3),
        categoryTerm(11, 'PLA', 'pla', 5),
    ], [
        ['id' => 40, 'name' => 'Product with foreign SKU', 'sku' => 'SHOP-9', 'categories' => [['id' => 6], ['id' => 10]]],
        ['id' => 41, 'name' => '<Unknown>', 'sku' => 'UNKNOWN', 'categories' => [['id' => 5]]],
        ['id' => 42, 'name' => 'No SKU', 'sku' => '', 'categories' => []],
    ]);
}

function categoryImportFixture(): array
{
    $store = new ShopCategoryStore(new CategoryStoreDatabase(), 7);
    $client = categoryImportClient();
    return [new CategoryImporter($client, $store, static fn (string $sku): ?int => $sku === 'SHOP-9' ? 9 : null),
        $store, $client];
}
