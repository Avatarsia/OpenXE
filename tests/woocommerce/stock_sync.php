<?php
// SPDX-License-Identifier: AGPL-3.0-only
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/category-store.php';
require dirname(__DIR__, 2) . '/www/lib/ShopimporterBase.php';
require dirname(__DIR__, 2) . '/www/pages/shopimporter_woocommerce.php';

use Xentral\Components\WooCommerce\ShopCategoryStore;

function stockConnector(array $articles, CategoryStoreDatabase $db, bool $enabled): Shopimporter_Woocommerce
{
    $class = new ReflectionClass(Shopimporter_Woocommerce::class);
    $connector = $class->newInstanceWithoutConstructor();
    $connector->shopid = 5;
    $connector->data = $articles;
    $container = new class($db) {
        public function __construct(private CategoryStoreDatabase $db) {}
        public function get(string $name): CategoryStoreDatabase { same('Database', $name); return $this->db; }
    };
    $class->getProperty('app')->setValue($connector, (object) ['Container' => $container]);
    $class->getProperty('extendedFieldMapping')->setValue($connector, $enabled);
    $connector->logger = new class {
        public array $debug = [];
        public function debug(string $message, array $context = []): void { $this->debug[] = $context; }
        public function info(string $message, array $context = []): void {}
        public function error(string $message): void { throw new RuntimeException($message); }
    };
    $connector->client = new class {
        public array $writes = [];
        public function get(string $endpoint, array $query): array
        {
            same('products', $endpoint);
            $products = [
                'REQUESTED-PRODUCT' => ['id' => 31],
                'REQUESTED-VARIATION' => ['id' => 32, 'parent_id' => 91],
                'SOLD-OUT' => ['id' => 33],
                'NORMAL-VARIATION' => ['id' => 34, 'parent_id' => 91],
            ];
            return array_map(static fn (string $sku): object => (object) (['sku' => $sku] + $products[$sku]),
                explode(',', $query['sku']));
        }
        public function post(string $endpoint, array $payload): object
        {
            $this->writes[$endpoint] = $payload;
            return (object) ['update' => array_map(static fn (array $item): object => (object) $item, $payload['update'])];
        }
    };
    return $connector;
}

function stockArticle(int $id, string $sku, bool $soldOut = false): array
{
    return ['artikel' => $id, 'nummer' => $sku, 'anzahl_lager' => 4, 'pseudolager' => '',
        'ausverkauft' => $soldOut, 'inaktiv' => 0];
}

runTests([
    'on_request_products_and_variations_are_skipped_in_both_stock_batch_paths' => static function () {
        $db = new CategoryStoreDatabase();
        $store = new ShopCategoryStore($db, 5);
        foreach ([7, 8, 9] as $article) {
            $store->setOnRequest($article, true);
        }
        $articles = [
            stockArticle(7, 'REQUESTED-PRODUCT'),
            stockArticle(8, 'REQUESTED-VARIATION'),
            stockArticle(9, 'SOLD-OUT', true),
            stockArticle(10, 'NORMAL-VARIATION'),
        ];
        $connector = stockConnector($articles, $db, true);
        same(2, $connector->ImportSendListLager());
        same([
            'products/batch' => ['update' => [['id' => 33, 'manage_stock' => true, 'stock_quantity' => 0,
                'status' => 'publish', 'backorders' => 'no', 'stock_status' => 'outofstock']]],
            'products/91/variations/batch' => ['update' => [['id' => 34, 'manage_stock' => true, 'stock_quantity' => 4,
                'status' => 'publish']]],
        ], $connector->client->writes);
        same([7, 8], array_column($connector->logger->debug, 'article'));

        $disabled = stockConnector($articles, $db, false);
        same(4, $disabled->ImportSendListLager());
    },
]);
