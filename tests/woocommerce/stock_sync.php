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
        public array $errors = [];
        public function error(string $message, array $context = []): void { $this->errors[] = [$message, $context]; }
    };
    $connector->client = new class {
        public array $writes = [];
        public ?object $batchResponse = null;
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
            return $this->batchResponse ?? (object) ['update' => array_map(static fn (array $item): object => (object) $item, $payload['update'])];
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
    'unknown_on_request_state_never_writes_stock_or_status_and_later_items_export' => static function () {
        $db = new CategoryStoreDatabase();
        $db->config['woocommerce_article_5_7'] = '{';
        $connector = stockConnector([stockArticle(7, 'REQUESTED-PRODUCT'), stockArticle(10, 'NORMAL-VARIATION')], $db, true);
        same(1, $connector->ImportSendListLager());
        same(['products/91/variations/batch'], array_keys($connector->client->writes));
        same(7, $connector->logger->errors[0][1]['article']);
        same([['article' => 7, 'shop' => 5]], $db->stockRetries);
    },
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
                'backorders' => 'no', 'stock_status' => 'outofstock']]],
            'products/91/variations/batch' => ['update' => [['id' => 34, 'manage_stock' => true, 'stock_quantity' => 4]]],
        ], $connector->client->writes);
        same([7, 8], array_column($connector->logger->debug, 'article'));

        $disabled = stockConnector($articles, $db, false);
        same(4, $disabled->ImportSendListLager());
    },
    'stock updates use real stock for nonpositive overrides and preserve active product status' => static function () {
        $articles = [
            array_replace(stockArticle(7, 'REQUESTED-PRODUCT'), ['anzahl_lager' => '79.1', 'pseudolager' => '0']),
            array_replace(stockArticle(8, 'REQUESTED-VARIATION'), ['anzahl_lager' => 9, 'pseudolager' => 0]),
            array_replace(stockArticle(9, 'SOLD-OUT'), ['anzahl_lager' => -1, 'pseudolager' => '-4', 'restmenge' => '1', 'inaktiv' => 1]),
            array_replace(stockArticle(10, 'NORMAL-VARIATION'), ['pseudolager' => '3.9']),
        ];
        $connector = stockConnector($articles, new CategoryStoreDatabase(), false);
        same(4, $connector->ImportSendListLager());
        same([
            'products/batch' => ['update' => [
                ['id' => 31, 'manage_stock' => true, 'stock_quantity' => 79],
                ['id' => 33, 'manage_stock' => true, 'stock_quantity' => 0, 'status' => 'private', 'backorders' => 'no'],
            ]],
            'products/91/variations/batch' => ['update' => [
                ['id' => 32, 'manage_stock' => true, 'stock_quantity' => 9],
                ['id' => 34, 'manage_stock' => true, 'stock_quantity' => 3],
            ]],
        ], $connector->client->writes);
    },
    'stock transfer only confirms IDs requested in the chunk with a successful response' => static function () {
        $connector = stockConnector([stockArticle(7, 'REQUESTED-PRODUCT')], new CategoryStoreDatabase(), false);
        $connector->client->batchResponse = (object) ['update' => [
            (object) ['id' => 999],
            (object) ['id' => 31, 'error' => (object) ['code' => 'failed']],
            (object) [],
        ]];
        same(0, $connector->ImportSendListLager());
        $connector->client->batchResponse->update = [(object) ['id' => 31], (object) ['id' => 31]];
        same(1, $connector->ImportSendListLager());
    },
]);
