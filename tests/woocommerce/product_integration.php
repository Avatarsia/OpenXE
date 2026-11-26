<?php
// SPDX-License-Identifier: AGPL-3.0-only
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/category-store.php';
require __DIR__ . '/fixtures/assignment.php';

use Xentral\Components\WooCommerce\AssignmentPage;
use Xentral\Components\WooCommerce\ClientWrapper;
use Xentral\Components\WooCommerce\ShopCategoryStore;
use Xentral\Components\WooCommerce\VisibilityQueue;
require dirname(__DIR__, 2) . '/www/lib/ShopimporterBase.php';
require dirname(__DIR__, 2) . '/www/pages/shopimporter_woocommerce.php';

/** HTTP boundary only. The connector, mapper, categories and variations are real. */
class IntegrationProductApi extends ClientWrapper
{
    public function __construct() {}
    public $collections = ['products' => [], 'products/categories' => []];
    public $writes = [];
    public ?Closure $duringCategoryPost = null;
    public ?Closure $duringCategoryRead = null;
    public bool $failCategoryReads = false;
    public ?string $failCategoryAssignment = null;
    public ?Closure $duringProductLookup = null;
    private int $total = 0;
    private $nextId = 100;

    public function get($endpoint, $query = [])
    {
        if ($endpoint === 'products/categories') {
            ($this->duringCategoryRead)?->__invoke();
        }
        if ($endpoint === 'products/categories' && $this->failCategoryReads) {
            throw new Error('Category index unavailable');
        }
        if (preg_match('#^(products(?:/categories)?)/(\d+)$#', $endpoint, $match)) {
            foreach ($this->collections[$match[1]] as $row) {
                if ((int) $row['id'] === (int) $match[2]) {
                    return (object) $row;
                }
            }
            throw new RuntimeException('Resource not found ' . $endpoint);
        }
        $rows = $this->collections[$endpoint] ?? [];
        if (isset($query['sku'])) {
            $rows = array_values(array_filter($rows, static function ($row) use ($query) {
                return in_array($row['sku'] ?? '', explode(',', $query['sku']), true);
            }));
        }
        if (isset($query['parent'])) {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => $row['parent'] === $query['parent']));
        }
        $size = (int) ($query['per_page'] ?? 100);
        $this->total = count($rows);
        if ($endpoint === 'products' && isset($query['sku'])) {
            ($this->duringProductLookup)?->__invoke($query);
        }
        return array_map(static function ($row) { return (object) $row; }, array_slice($rows, ((int) ($query['page'] ?? 1) - 1) * $size, $size));
    }

    public function getLastResponse(): object
    {
        return new class($this->total) {
            public function __construct(private int $total) {}
            public function getHeader(string $name): int
            {
                return strtolower($name) === 'x-wp-totalpages' ? (int) ($this->total > 0) : $this->total;
            }
        };
    }

    public function delete($endpoint, $query = []): void
    {
        $this->writes[] = ['DELETE', $endpoint, $query];
        $id = (int) basename($endpoint);
        $this->collections['products/categories'] = array_values(array_filter(
            $this->collections['products/categories'], static fn (array $term): bool => $term['id'] !== $id
        ));
    }

    public function post($endpoint, $payload)
    {
        $this->writes[] = ['POST', $endpoint, $payload];
        if (str_ends_with($endpoint, '/batch')) {
            return (object) ['update' => array_map(static fn (array $row): object => (object) $row, $payload['update'])];
        }
        $row = array_merge(['parent_id' => 0, 'categories' => [], 'count' => 0], $payload, ['id' => ++$this->nextId]);
        $this->collections[$endpoint][] = $row;
        if ($endpoint === 'products/categories' && $this->duringCategoryPost !== null) {
            ($this->duringCategoryPost)();
        }
        return (object) $row;
    }

    public function put($endpoint, $payload)
    {
        if ($endpoint === $this->failCategoryAssignment && isset($payload['categories'])) {
            throw new Error('Category assignment unavailable');
        }
        $this->writes[] = ['PUT', $endpoint, $payload];
        $parts = explode('/', $endpoint);
        $id = (int) array_pop($parts);
        $collection = implode('/', $parts);
        foreach ($this->collections[$collection] ?? [] as $key => $row) {
            if ((int) $row['id'] === $id) {
                $this->collections[$collection][$key] = array_merge($row, $payload);
                return (object) $this->collections[$collection][$key];
            }
        }
        throw new RuntimeException('Unexpected PUT ' . $endpoint);
    }
}

class IntegrationProductConnector extends Shopimporter_Woocommerce
{
    public $uploadRequests = [];
    public $deliveryRequests = [];
    public int $treeSyncTime = 0;

    protected function categorySyncTime(): int
    {
        return $this->treeSyncTime;
    }

    public function wordpress_request(string $endpoint, $postdata = null, $method = null, $getdata = null, string $content_disposition = null, string $content_type = null, $debug = false, $debugurl = null, bool $ssl_ignore = false, int $timeout_seconds = 30)
    {
        $this->deliveryRequests[] = $endpoint;
        return ['status' => true, 'response' => [['id' => 65, 'name' => 'ca. 3-4 Werktage']]];
    }

    public function uploadFiles($files)
    {
        $this->uploadRequests[] = $files;
        $uploaded = [];
        foreach ($files as $index => $file) {
            $uploaded[] = ['wordpressid' => 700 + $index, 'type' => strtolower($file['stichwort']), 'name' => $file['filename'], 'dateipfad' => $file['dateipfad']];
        }
        return ['success' => true, 'files' => $uploaded];
    }
}

/** Keeps uploadFiles real; substitutes only its outgoing WordPress HTTP request. */
class IntegrationWordPressConnector extends Shopimporter_Woocommerce
{
    public $requests = [];
    public function wordpress_request(string $endpoint, $postdata = null, $method = null, $getdata = null, string $content_disposition = null, string $content_type = null, $debug = false, $debugurl = null, bool $ssl_ignore = false, int $timeout_seconds = 30)
    {
        $this->requests[] = ['endpoint' => $endpoint, 'body' => $postdata, 'method' => $method, 'query' => $getdata];
        // Exactly the public wrapper's result shape: no top-level http_code field.
        if ($method === 'POST') {
            return ['status' => true, 'response' => ['id' => 901, 'guid' => ['rendered' => 'https://shop.invalid/red.png']], 'message' => null, 'curl_getinfo' => ['http_code' => 201]];
        }
        return ['status' => true, 'response' => [], 'message' => null, 'curl_getinfo' => ['http_code' => 200]];
    }
}

function productConnector(array $articles, array $settings = []): IntegrationProductConnector
{
    $connector = (new ReflectionClass(IntegrationProductConnector::class))->newInstanceWithoutConstructor();
    $connector->data = $articles;
    $connector->client = new IntegrationProductApi();
    $connector->shopid = 5;
    $connector->logger = new class {
        public array $warnings = [];
        public array $warningContexts = [];
        public array $errors = [];
        public function debug(...$args) {}
        public function info(...$args) {}
        public function error(...$args) { $this->errors[] = $args; }
        public function warning(string $message, array $context = []): void
        {
            $this->warnings[] = $message;
            $this->warningContexts[] = $context;
        }
    };
    foreach (array_replace(['backorders' => 0, 'metadatasmarty' => '', 'productsmarty' => '', 'filenamesmarty' => '', 'fileunique' => '-', 'kategorienuebertragen' => false, 'dateienuebertragen' => []], $settings) as $key => $value) {
        if ((new ReflectionClass(Shopimporter_Woocommerce::class))->hasProperty($key)) {
            (new ReflectionProperty(Shopimporter_Woocommerce::class, $key))->setValue($connector, $value);
        } else {
            $connector->$key = $value;
        }
    }
    configureProductDatabase($connector, new CategoryStoreDatabase());
    if (!empty($settings['shopCategoryTree'])) {
        (new ReflectionProperty(Shopimporter_Woocommerce::class, 'categorySyncClient'))->setValue($connector, $connector->client);
    }
    return $connector;
}

/** Fields present in a full class.remote.php article export, plus optional Smarty metadata. */
function remoteProduct(array $changes = []): array
{
    return array_replace([
        'artikel' => 7, 'nummer' => 'PARENT', 'restmenge' => 0, 'inaktiv' => 0, 'lieferzeitmanuell' => '',
        'hersteller' => '', 'herstellerlink' => '', 'name_de' => 'Shirt &amp; Book', 'name_en' => '',
        'uebersicht_de' => 'Description', 'uebersicht_en' => '', 'preis' => 10, 'kurztext_de' => 'Short',
        'gewicht' => '1.2', 'laenge' => '0', 'breite' => '3', 'hoehe' => '', 'ean' => '0123456789012',
        'metadescription_de' => 'SEO description', 'metatitle_de' => 'SEO title', 'pseudopreis' => 20,
        'steuersatz' => 19, 'anzahl_lager' => '0', 'pseudolager' => '0', 'ausverkauft' => 0,
        'meta_data' => ['custom' => 'kept'],
    ], $changes);
}

function remoteMatrixProduct(array $changes = []): array
{
    return remoteProduct(array_replace([
        'eigenschaften' => [['name' => 'Material', 'values' => 'Cotton']],
        'matrix_varianten' => [
            'gruppen' => ['Farbe' => ['Rot' => true]],
            'artikel' => [8 => [
                ['artikel' => 8, 'nummer' => 'RED', 'preis' => 12, 'pseudopreis' => 15, 'lag' => '0', 'pseudolager' => 0, 'name' => 'Farbe', 'values' => 'Rot'],
            ]],
        ],
        'artikel_varianten' => [['artikel' => 8, 'nummer' => 'RED', 'preis' => 12, 'lag' => '0', 'ean' => '1234567890123']],
    ], $changes));
}

function productWrite(IntegrationProductApi $api, string $endpoint): array
{
    foreach ($api->writes as [$method, $path, $payload]) {
        if ($path === $endpoint) {
            return $payload;
        }
    }
    throw new RuntimeException('Missing product write ' . $endpoint);
}

function configureProductDatabase(IntegrationProductConnector $connector, object $db): void
{
    $container = new class($db) {
        public function __construct(private object $db) {}
        public function get(string $name): object { same('Database', $name); return $this->db; }
    };
    (new ReflectionProperty(Shopimporter_Woocommerce::class, 'app'))->setValue($connector, (object) ['Container' => $container]);
}

runTests([
    'guard_reads_current_assignment_after_sku_lookup_for_a_prequeued_export' => static function () {
        $db = new AssignmentFixtureDatabase();
        $db->article['shop'] = $db->article['shop3'] = 7;
        $db->article['shop2'] = 8;
        $db->assignment = ['aktiv' => 1];
        $page = new AssignmentPage(assignmentFixtureApp($db));
        $queued = remoteProduct([
            'artikel' => 9,
            'nummer' => 'WC-ARTICLE',
            'artikel_varianten' => [['artikel' => 10, 'nummer' => 'CHILD', 'preis' => 12, 'inaktiv' => 0]],
        ]);
        $connector = productConnector([$queued], ['visibilitySync' => true]);
        $connector->shopid = 7;
        $connector->lastImportTimestamp = '2025-01-01T00:00:00';
        configureProductDatabase($connector, $db);
        $connector->client->collections['products'][] = ['id' => 20, 'sku' => 'WC-ARTICLE', 'parent_id' => 0, 'status' => 'publish'];
        $connector->client->duringProductLookup = static function (array $query) use ($connector, $page): void {
            if ($query['per_page'] !== 100) {
                return;
            }
            $connector->client->duringProductLookup = null;
            same(true, $page->handle('toggle_shop', ['shop' => 7, 'article' => 9, 'enabled' => 0, 'csrf_token' => 'assignment-token'])['ok']);
            same(0, $connector->ImportGetAuftraegeAnzahl());
            same('private', $connector->client->collections['products'][0]['status']);
        };

        same(true, $connector->ImportSendList()[0]->success);
        same([], (new VisibilityQueue($db, 7))->pending());
        same(['private', 'private', 'private'], array_map(static fn ($write) => $write[2]['status'], $connector->client->writes));
        same('private', $connector->client->collections['products/20/variations'][0]['status']);
        same([0, 8, 0], [$db->article['shop'], $db->article['shop2'], $db->article['shop3']]);
        same(true, $page->handle('toggle_shop', ['shop' => 7, 'article' => 9, 'enabled' => 1, 'csrf_token' => 'assignment-token'])['ok']);
        same(true, $connector->ImportSendList()[0]->success);
        same('publish', $connector->client->collections['products'][0]['status']);
        same('publish', $connector->client->collections['products/20/variations'][0]['status']);
    },
    'legacy_assignment_allows_full_export_without_an_active_row' => static function () {
        $db = new AssignmentFixtureDatabase();
        $db->article['shop3'] = 7;
        $connector = productConnector([remoteProduct(['artikel' => 9])], ['visibilitySync' => true]);
        $connector->shopid = 7;
        configureProductDatabase($connector, $db);
        foreach ([null, ['aktiv' => 0]] as $assignment) {
            $db->assignment = $assignment;
            same(true, $connector->ImportSendList()[0]->success);
            same('publish', $connector->client->collections['products'][0]['status']);
        }
    },
    'prebuilt_stock_batch_rechecks_assignment_for_main_and_variant_after_lookup' => static function () {
        $db = new AssignmentFixtureDatabase();
        $db->assignment = ['aktiv' => 1];
        $page = new AssignmentPage(assignmentFixtureApp($db));
        $connector = productConnector([
            remoteProduct(['artikel' => 9, 'nummer' => 'WC-ARTICLE', 'anzahl_lager' => '2', 'pseudolager' => '']),
            remoteProduct(['artikel' => 10, 'nummer' => 'CHILD', 'anzahl_lager' => '3', 'pseudolager' => '']),
        ], ['visibilitySync' => true]);
        $connector->shopid = 7;
        configureProductDatabase($connector, $db);
        $connector->client->collections['products'] = [
            ['id' => 20, 'sku' => 'WC-ARTICLE', 'parent_id' => 0],
            ['id' => 21, 'sku' => 'CHILD', 'parent_id' => 20],
        ];
        $connector->client->duringProductLookup = static function () use ($page): void {
            same(true, $page->handle('toggle_shop', [
                'shop' => 7, 'article' => 9, 'enabled' => 0, 'csrf_token' => 'assignment-token',
            ])['ok']);
        };
        same(0, $connector->ImportSendListLager());
        same([], $connector->client->writes);
        same([], $connector->logger->errors);

        $connector->client->duringProductLookup = null;
        $db->assignment = null;
        $db->article['shop'] = 7;
        same(2, $connector->ImportSendListLager());
        same([
            ['POST', 'products/batch', ['update' => [['id' => 20, 'manage_stock' => true, 'stock_quantity' => '2', 'status' => 'publish']]]],
            ['POST', 'products/20/variations/batch', ['update' => [['id' => 21, 'manage_stock' => true, 'stock_quantity' => '3', 'status' => 'publish']]]],
        ], $connector->client->writes);
    },
    'sold_out_variants_remain_unavailable_with_all_options_disabled' => static function () {
        $db = new CategoryStoreDatabase();
        $db->soldOut = [8 => 1, 10 => 1];
        $connector = productConnector([remoteMatrixProduct(['artikel_varianten' => [
            ['artikel' => 10, 'nummer' => 'BLUE', 'preis' => 12, 'lag' => '7'],
            ['artikel' => 8, 'nummer' => 'RED', 'preis' => 12, 'lag' => '5'],
        ]])]);
        configureProductDatabase($connector, $db);
        same(true, $connector->ImportSendList()[0]->success);
        $variants = array_column($connector->client->collections['products/101/variations'], null, 'sku');
        foreach (['RED', 'BLUE'] as $sku) {
            same('outofstock', $variants[$sku]['stock_status']);
            same(0, $variants[$sku]['stock_quantity']);
            same('no', $variants[$sku]['backorders']);
        }
    },
    'variants_export_sold_out_over_on_request_in_standard_and_matrix_payloads' => static function () {
        $db = new CategoryStoreDatabase();
        $db->soldOut = [8 => 1, 10 => 1];
        $store = new ShopCategoryStore($db, 5);
        $store->setOnRequest(8, true);
        $store->setOnRequest(10, true);
        $product = remoteMatrixProduct(['artikel_varianten' => [['artikel' => 10, 'nummer' => 'BLUE', 'preis' => 12, 'lag' => '7']]]);
        $connector = productConnector([$product], ['extendedFieldMapping' => true]);
        configureProductDatabase($connector, $db);

        same(true, $connector->ImportSendList()[0]->success);
        $variants = array_column($connector->client->collections['products/101/variations'], null, 'sku');
        foreach (['RED', 'BLUE'] as $sku) {
            $variant = $variants[$sku];
            same('outofstock', $variant['stock_status']);
            same('no', $variant['backorders']);
            same(0, $variant['stock_quantity']);
            same('0', array_column($variant['meta_data'], 'value', 'key')['openxe_on_request']);
        }
    },
    'tree_sync_transport_caps_requests_independently_of_shop_timeout' => static function () {
        require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
        $connector = productConnector([]);
        $app = (object) ['DB' => new class {
            public function Select(string $sql): string
            {
                return json_encode(['felder' => [
                    'shopCategoryTree' => true, 'timeout' => 75, 'filenamesmarty' => '', 'productsmarty' => '',
                    'metadatasmarty' => '', 'fileunique' => '-', 'backorders' => 0,
                    'ImportWoocommerceApiUrl' => 'https://shop.invalid',
                ]]);
            }
            public function SelectRow(string $sql): array
            {
                return ['dateienuebertragen' => '', 'kategorienuebertragen' => false];
            }
        }];
        (new ReflectionProperty(Shopimporter_Woocommerce::class, 'app'))->setValue($connector, $app);
        $connector->getKonfig(7, []);

        $getTimeout = static function (object $wrapper): int {
            $sdk = (new ReflectionProperty($wrapper, 'client'))->getValue($wrapper);
            return (new ReflectionProperty($sdk->http, 'options'))->getValue($sdk->http)->getTimeout();
        };
        same(75, $getTimeout($connector->client));
        $treeClient = (new ReflectionProperty(Shopimporter_Woocommerce::class, 'categorySyncClient'))->getValue($connector);
        same(10, $getTimeout($treeClient));
    },
    'full_export_and_order_drain_share_the_category_sync_lock' => static function () {
        $db = new AssignmentFixtureDatabase();
        $store = new ShopCategoryStore($db, 7);
        (new AssignmentPage(assignmentFixtureApp($db)))->handle('cat_create', [
            'shop' => 7, 'parent' => 0, 'name' => 'Parts', 'csrf_token' => 'assignment-token',
        ]);
        $connector = productConnector([], ['shopCategoryTree' => true]);
        $connector->shopid = 7;
        $connector->lastImportTimestamp = '2025-01-01T00:00:00';
        configureProductDatabase($connector, $db);

        $lock = 'openxe_wc_category_sync_7';
        $db->advisoryLocks[$lock] = true;
        $connector->ImportGetAuftraegeAnzahl();
        $connector->ImportSendList();
        same([], $connector->client->writes);
        check($store->pendingTreeSync() !== null);

        unset($db->advisoryLocks[$lock]);
        $connector->client->duringCategoryPost = static function () use ($db, $lock): void {
            check(isset($db->advisoryLocks[$lock]), 'Full export must hold the same lock.');
        };
        $connector->ImportSendList();
        same([], $db->advisoryLocks);
        same(null, $store->pendingTreeSync());
    },
    'three_consecutive_category_budget_exhaustions_warn_until_success' => static function () {
        $db = new AssignmentFixtureDatabase();
        $store = new ShopCategoryStore($db, 7);
        $page = new AssignmentPage(assignmentFixtureApp($db));
        $page->handle('cat_create', [
            'shop' => 7, 'parent' => 0, 'name' => 'Parts', 'csrf_token' => 'assignment-token',
        ]);
        $revision = $store->pendingTreeSync();
        $connector = productConnector([], ['shopCategoryTree' => true]);
        $connector->shopid = 7;
        $connector->lastImportTimestamp = '2025-01-01T00:00:00';
        configureProductDatabase($connector, $db);
        $connector->client->duringCategoryRead = static function () use ($connector): void {
            $connector->treeSyncTime += 11_000_000_000;
        };

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            same(0, $connector->ImportGetAuftraegeAnzahl());
            same($revision, $store->pendingTreeSync());
            same($attempt, $store->treeSyncBudgetExhaustions());
            same($attempt === 3, str_contains($page->render(), 'mindestens dreimal in Folge das Zeitbudget'));
        }
        $connector->client->duringCategoryRead = null;
        same(0, $connector->ImportGetAuftraegeAnzahl());
        same(null, $store->pendingTreeSync());
        same(0, $store->treeSyncBudgetExhaustions());
        same(false, str_contains($page->render(), 'mindestens dreimal in Folge das Zeitbudget'));
    },
    'saved_tree_change_warns_and_retries_hash_invalidation_on_order_import' => static function () {
        $db = new AssignmentFixtureDatabase();
        $db->assignment = ['aktiv' => 1, 'last_article_hash' => 'old'];
        $store = new ShopCategoryStore($db, 7);
        $node = $store->create('Parts');
        $store->assign(9, [$node]);
        $page = new AssignmentPage(assignmentFixtureApp($db));
        $db->failAssignmentWrites = true;
        $result = $page->handle('cat_rename', [
            'shop' => 7, 'category' => $node, 'name' => 'Tools', 'csrf_token' => 'assignment-token',
        ]);
        same('Tools', $store->tree()[0]['name']);
        check($store->pendingTreeSync() !== null);
        same(true, $result['ok']);
        check(!empty($result['warning']));
        check($store->hasPendingExportInvalidations());
        check(str_contains($page->render(), 'Artikel-Export-Hashes steht noch aus'));
        same('old', $db->assignment['last_article_hash']);

        $connector = productConnector([], ['shopCategoryTree' => true]);
        $connector->shopid = 7;
        $connector->lastImportTimestamp = '2025-01-01T00:00:00';
        configureProductDatabase($connector, $db);
        same(0, $connector->ImportGetAuftraegeAnzahl());
        same('old', $db->assignment['last_article_hash']);

        $db->failAssignmentWrites = false;
        same(0, $connector->ImportGetAuftraegeAnzahl());
        same('', $db->assignment['last_article_hash']);
        same(false, $store->hasPendingExportInvalidations());
        same(null, $store->pendingTreeSync());
    },
    'order_import_syncs_empty_categories_without_any_article_exports' => static function () {
        $db = new AssignmentFixtureDatabase();
        $store = new ShopCategoryStore($db, 7);
        $page = new AssignmentPage(assignmentFixtureApp($db));
        $connector = productConnector([], ['shopCategoryTree' => true]);
        $connector->shopid = 7;
        $connector->lastImportTimestamp = '2025-01-01T00:00:00';
        configureProductDatabase($connector, $db);
        $command = static fn (string $cmd, array $post): array => $page->handle($cmd,
            $post + ['shop' => 7, 'csrf_token' => 'assignment-token', 'parent' => 0]);

        $node = $command('cat_create', ['name' => 'Empty category'])['category'];
        $root = $command('cat_create', ['name' => 'Root'])['category'];
        $connector->client->failCategoryReads = true;
        same(0, $connector->ImportGetAuftraegeAnzahl());
        same([], $connector->client->writes);
        same(1, count($connector->logger->errors));
        check($store->pendingTreeSync() !== null);
        $connector->client->failCategoryReads = false;
        $connector->client->duringCategoryPost = static function () use ($connector, $command, $node): void {
            $connector->client->duringCategoryPost = null;
            same(true, $command('cat_rename', ['category' => $node, 'name' => 'Edited during export'])['ok']);
        };
        same(0, $connector->ImportGetAuftraegeAnzahl());
        same(['Empty category', 'Root'], array_column($connector->client->collections['products/categories'], 'name'));
        check($store->pendingTreeSync() !== null);
        same(0, $connector->ImportGetAuftraegeAnzahl());
        same('Edited during export', $connector->client->collections['products/categories'][0]['name']);
        same(null, $store->pendingTreeSync());

        same(true, $command('cat_rename', ['category' => $node, 'name' => 'Renamed'])['ok']);
        same(true, $command('cat_move', ['category' => $node, 'parent' => $root, 'sort' => 0])['ok']);
        same(0, $connector->ImportGetAuftraegeAnzahl());
        same('Renamed', $connector->client->collections['products/categories'][0]['name']);
        same(102, $connector->client->collections['products/categories'][0]['parent']);

        same(true, $command('cat_delete', ['category' => $node])['ok']);
        same(0, $connector->ImportGetAuftraegeAnzahl());
        same(['Root'], array_column($connector->client->collections['products/categories'], 'name'));
        same([], $store->pendingDeletions());
        $errors = $connector->logger->errors;
        $connector->client->failCategoryReads = true;
        same(0, $connector->ImportGetAuftraegeAnzahl());
        same($errors, $connector->logger->errors);
    },
    'shop_category_tree_replaces_the_legacy_source_and_assigns_the_product' => static function () {
        $db = new CategoryStoreDatabase();
        $store = new \Xentral\Components\WooCommerce\ShopCategoryStore($db, 5);
        $node = $store->create('Shop category');
        $store->assign(7, [$node]);
        $connector = productConnector([remoteProduct(['kategorien' => [['id' => 33, 'name' => 'ERP category']]])],
            ['shopCategoryTree' => true]);
        configureProductDatabase($connector, $db);
        $connector->ImportSendList();
        same(['shop-category'], array_column($connector->client->collections['products/categories'], 'slug'));
        same([['id' => 101]], $connector->client->collections['products'][0]['categories']);
    },
    'export_continues_after_a_recreated_category_collides_with_a_blocked_pending_term' => static function () {
        $db = new CategoryStoreDatabase();
        $store = new \Xentral\Components\WooCommerce\ShopCategoryStore($db, 5);
        $parts = $store->create('Parts');
        $articles = [remoteProduct(), remoteProduct(['artikel' => 8, 'nummer' => 'OTHER'])];
        $connector = productConnector($articles, ['shopCategoryTree' => true]);
        configureProductDatabase($connector, $db);
        $connector->client->duringCategoryPost = static function () use ($store, $parts): void {
            $store->delete($parts);
            $store->assign(7, [$store->create('Parts')]);
            $store->assign(8, [$store->create('Tools')]);
        };
        same([true, true], array_column($connector->ImportSendList(), 'success'));
        $connector->client->duringCategoryPost = null;
        $connector->client->collections['products/categories'][0]['count'] = 1;
        $store->assign(7, array_merge($store->categoriesOf(7), $store->categoriesOf(8)));

        $results = $connector->ImportSendList();
        same([true, true], array_column($results, 'success'));
        $reason = 'Slug parts ist noch durch eine nicht loeschbare WooCommerce-Kategorie belegt';
        check(str_contains($results[0]->message, $reason));
        same(null, $results[1]->message);
        same([['id' => 104]], $connector->client->collections['products'][0]['categories']);
        same([['id' => 104]], $connector->client->collections['products'][1]['categories']);
        same(['parts', 'tools'], array_column($connector->client->collections['products/categories'], 'slug'));
        check(in_array($reason, $connector->logger->warnings, true));
    },
    'category_assignment_failure_preserves_the_result_and_exports_the_next_article' => static function () {
        $db = new CategoryStoreDatabase();
        $store = new \Xentral\Components\WooCommerce\ShopCategoryStore($db, 5);
        $parts = $store->create('Parts');
        $store->assign(7, [$parts]);
        $store->assign(8, [$parts]);
        $connector = productConnector([remoteProduct(), remoteProduct(['artikel' => 8, 'nummer' => 'OTHER'])],
            ['shopCategoryTree' => true]);
        configureProductDatabase($connector, $db);
        $connector->client->failCategoryAssignment = 'products/102';
        $results = $connector->ImportSendList();
        same([true, true], array_column($results, 'success'));
        check(str_contains($results[0]->message, 'Category assignment unavailable'));
        same(null, $results[1]->message);
        same([['id' => 101]], $connector->client->collections['products'][1]['categories']);
        check(in_array(['shop' => 5, 'article' => 7, 'categories' => [$parts]], $connector->logger->warningContexts, true));
    },
    'category_index_failure_is_reported_for_each_article_and_does_not_stop_export' => static function () {
        $db = new CategoryStoreDatabase();
        $store = new \Xentral\Components\WooCommerce\ShopCategoryStore($db, 5);
        $parts = $store->create('Parts');
        $store->assign(7, [$parts]);
        $connector = productConnector([remoteProduct(), remoteProduct(['artikel' => 8, 'nummer' => 'OTHER'])],
            ['shopCategoryTree' => true]);
        configureProductDatabase($connector, $db);
        $connector->client->failCategoryReads = true;
        $connector->logger = new class {
            public function debug(...$args): void {}
            public function info(...$args): void {}
            public function warning(string $message, array $context = []): void
            {
                throw new RuntimeException('Log insert failed');
            }
        };
        $results = $connector->ImportSendList();
        same([true, true], array_column($results, 'success'));
        foreach ($results as $result) {
            check(str_contains($result->message, 'Category index unavailable'));
        }
        same(['PARENT', 'OTHER'], array_column($connector->client->collections['products'], 'sku'));
    },
    'on_request_export_uses_each_variants_local_article_id' => static function () {
        $db = new CategoryStoreDatabase();
        $store = new \Xentral\Components\WooCommerce\ShopCategoryStore($db, 5);
        $store->setOnRequest(7, true);
        $store->setOnRequest(10, true);
        $product = remoteMatrixProduct(['anzahl_lager' => 15, 'pseudolager' => '']);
        $product['artikel_varianten'][] = ['artikel' => 10, 'nummer' => 'BLUE', 'preis' => 12, 'lag' => '7'];
        $connector = productConnector([$product], ['extendedFieldMapping' => true]);
        configureProductDatabase($connector, $db);
        $connector->ImportSendList();
        $writes = array_column($connector->client->writes, 2);
        $metaBySku = [];
        foreach ($writes as $write) {
            $metaBySku[$write['sku']] = array_column($write['meta_data'], 'value', 'key')['openxe_on_request'];
        }
        same(['PARENT' => '1', 'RED' => '0', 'BLUE' => '1'], $metaBySku);
        $bySku = array_column($writes, null, 'sku');
        foreach (['PARENT', 'BLUE'] as $sku) {
            same(false, $bySku[$sku]['manage_stock']);
            same('onbackorder', $bySku[$sku]['stock_status']);
            same('notify', $bySku[$sku]['backorders']);
            check(!array_key_exists('stock_quantity', $bySku[$sku]));
        }
        same(true, $bySku['RED']['manage_stock']);
        same('outofstock', $bySku['RED']['stock_status']);
    },
    'real ImportSendList preserves mapped metadata prices stock dimensions EAN and result identity' => static function () {
        $connector = productConnector([remoteProduct(['lieferzeitmanuell' => 'ca. 3-4 Werktage'])], [
            'ImportWordpressUserName' => 'user', 'ImportWordpressApplicationPassword' => 'fixture',
        ]);
        $result = $connector->ImportSendList();
        same(true, $result[0]->success);
        same(7, $result[0]->articleId);
        $payload = productWrite($connector->client, 'products');
        same('Shirt & Book', $payload['name']);
        same('20.00', $payload['regular_price']);
        same('10.00', $payload['sale_price']);
        same(0, $payload['stock_quantity']);
        same('outofstock', $payload['stock_status']);
        same('0', $payload['dimensions']['length']);
        same('0123456789012', $payload['global_unique_id']);
        same([], array_column($payload['meta_data'], 'value', 'key'));
        same([], $connector->deliveryRequests);
    },
    'real product Smarty transformation remains ahead of product mapping' => static function () {
        require_once dirname(__DIR__, 2) . '/vendor/smarty/smarty/libs/Smarty.class.php';
        $directory = sys_get_temp_dir() . '/openxe-smarty-' . bin2hex(random_bytes(5));
        mkdir($directory . '/smarty/templates', 0777, true);
        try {
            $connector = productConnector([remoteProduct()], ['productsmarty' => '{ "name_de":"{$artikel->nummer} custom","preis":7.5 }', 'metadatasmarty' => '{ "template":"{$artikel->nummer} kept" }']);
            $app = (object) ['erp' => new class($directory) {
                private $directory;
                public function __construct($directory) { $this->directory = $directory; }
                public function GetTMP() { return $this->directory; }
            }];
            (new ReflectionProperty(Shopimporter_Woocommerce::class, 'app'))->setValue($connector, $app);
            $connector->ImportSendList();
            $payload = productWrite($connector->client, 'products');
            same('PARENT custom', $payload['name']);
            same('7.50', $payload['sale_price']);
            same('PARENT kept', array_column($payload['meta_data'], 'value', 'key')['template']);
        } finally {
            foreach (glob($directory . '/smarty/templates/*') as $path) {
                unlink($path);
            }
            rmdir($directory . '/smarty/templates');
            rmdir($directory . '/smarty');
            rmdir($directory);
        }
    },
    'actual remote source without optional Smarty meta_data exports successfully' => static function () {
        $article = remoteProduct();
        unset($article['meta_data']);
        $connector = productConnector([$article]);
        same(true, $connector->ImportSendList()[0]->success);
    },
    'real matrix payload creates selectable parent children and preserves replay IDs' => static function () {
        $connector = productConnector([remoteMatrixProduct()], ['backorders' => 1]);
        $connector->ImportSendList();
        $parent = productWrite($connector->client, 'products');
        same('variable', $parent['type']);
        same(false, $parent['manage_stock']);
        same(['Rot'], $parent['attributes'][1]['options']);
        $child = productWrite($connector->client, 'products/101/variations');
        same([['name' => 'Farbe', 'option' => 'Rot']], $child['attributes']);
        same('1234567890123', $child['global_unique_id']);
        same('onbackorder', $child['stock_status']);
        same('15.00', $child['regular_price']);
        $connector->ImportSendList();
        same(1, count($connector->client->collections['products']));
        same(1, count($connector->client->collections['products/101/variations']));
    },
    'real category integration adds assigned child without losing shop categories' => static function () {
        $connector = productConnector([remoteProduct([
            'kategorien' => [['id' => 20, 'name' => 'Child', 'parent' => 10, 'lvl' => 1]],
            'kompletter_kategorienbaum' => [['id' => 10, 'name' => 'Root', 'parent' => 99, 'lvl' => 0]],
        ])], ['kategorienuebertragen' => true]);
        $connector->client->collections['products'] = [['id' => 80, 'sku' => 'PARENT', 'parent_id' => 0, 'categories' => [(object) ['id' => 90]]]];
        $connector->ImportSendList();
        $product = $connector->client->collections['products'][0];
        same([['id' => 90], ['id' => 102]], $product['categories']);
        same('Root', $connector->client->collections['products/categories'][0]['name']);
        same(101, $connector->client->collections['products/categories'][1]['parent']);
    },
    'existing top-level upload chain preserves gallery and public attachment metadata' => static function () {
        $connector = productConnector([remoteProduct(['Dateien' => [
            ['dateipfad' => 'shirt.png', 'filename' => 'shirt.png', 'stichwort' => 'Shopbild'],
            ['dateipfad' => 'manual.pdf', 'filename' => 'manual.pdf', 'stichwort' => 'Datenblatt'],
        ]])], ['dateienuebertragen' => ['shopbild', 'datenblatt']]);
        $connector->ImportSendList();
        $payload = productWrite($connector->client, 'products');
        same([['id' => 700]], $payload['images']);
        $meta = array_column($payload['meta_data'], 'value', 'key');
        same([['name' => 'manual.pdf', 'wp_media_id' => 701, 'typ' => 'datenblatt']], $meta['openxe_product_attachments']);
        check(!isset($payload['downloads']));
    },
    'metadata_is_merged_not_replaced' => static function () {
        require_once dirname(__DIR__, 2) . '/vendor/smarty/smarty/libs/Smarty.class.php';
        $directory = sys_get_temp_dir() . '/openxe-smarty-' . bin2hex(random_bytes(5));
        mkdir($directory . '/smarty/templates', 0777, true);
        try {
            $article = remoteMatrixProduct([
                'metakeywords_de' => 'repair', 'lieferzeitmanuell' => 'ca. 3-4 Werktage',
                'Dateien' => [['dateipfad' => 'manual.pdf', 'filename' => 'manual.pdf', 'stichwort' => 'Datenblatt']],
            ]);
            $article['artikel_varianten'][0] += ['metatitle_de' => 'Variant title', 'lieferzeitmanuell' => 'Unknown'];
            $connector = productConnector([$article], [
                'extendedFieldMapping' => true, 'yoastMeta' => true, 'dateienuebertragen' => ['datenblatt'],
                'metadatasmarty' => '{ "template": "Smarty", "rank_math_title": "Smarty title", "openxe_product_attachments": "old" }',
                'ImportWordpressUserName' => 'user', 'ImportWordpressApplicationPassword' => 'fixture',
            ]);
            $app = (object) ['erp' => new class($directory) {
                private $directory;
                public function __construct($directory) { $this->directory = $directory; }
                public function GetTMP() { return $this->directory; }
            }];
            $appProperty = new ReflectionProperty(Shopimporter_Woocommerce::class, 'app');
            $appProperty->getValue($connector)->erp = $app->erp;
            $connector->client->collections['products'] = [['id' => 80, 'sku' => 'PARENT', 'parent_id' => 0]];
            same(true, $connector->ImportSendList()[0]->success);
            same(['PUT', 'products/80'], array_slice($connector->client->writes[0], 0, 2));
            $rows = $connector->client->writes[0][2]['meta_data'];
            $meta = array_column($rows, 'value', 'key');
            same(count($meta), count($rows), 'Metadata keys must be unique');
            same('Smarty', $meta['template']);
            same('Smarty title', $meta['rank_math_title']);
            same('SEO title', $meta['_yoast_wpseo_title']);
            same('repair', $meta['rank_math_focus_keyword'] ?? null);
            same(65, $meta['_lieferzeit']);
            same('kept', $meta['custom']);
            same([['name' => 'manual.pdf', 'wp_media_id' => 700, 'typ' => 'datenblatt']], $meta['openxe_product_attachments']);
            $child = array_column(productWrite($connector->client, 'products/80/variations')['meta_data'], 'value', 'key');
            same('Variant title', $child['rank_math_title']);
            same('Variant title', $child['_yoast_wpseo_title']);
            same('1234567890123', $child['_gm_gtin']);
            check(!array_key_exists('_lieferzeit', $child));
            same(['product_delivery_times?per_page=100'], $connector->deliveryRequests);
            same(1, count($connector->logger->warnings));
            check(str_contains($connector->logger->warnings[0], 'Unknown'));
        } finally {
            foreach (glob($directory . '/smarty/templates/*') as $path) {
                unlink($path);
            }
            rmdir($directory . '/smarty/templates');
            rmdir($directory . '/smarty');
            rmdir($directory);
        }
    },
    'replace mode clears the last explicit category despite a legacy article type name' => static function () {
        $connector = productConnector([remoteProduct(['kategorien' => [], 'kategoriename' => 'Legacy type'])], ['kategorienuebertragen' => true, 'categorySyncMode' => 'replace']);
        $connector->client->collections['products'] = [['id' => 80, 'sku' => 'PARENT', 'parent_id' => 0, 'categories' => [(object) ['id' => 90]]]];
        $connector->ImportSendList();
        same([], $connector->client->collections['products'][0]['categories']);
        same([], $connector->client->collections['products/categories']);
    },
    'real uploadFiles creates a new image after empty WordPress media search' => static function () {
        $connector = (new ReflectionClass(IntegrationWordPressConnector::class))->newInstanceWithoutConstructor();
        $connector->logger = new class { public function debug(...$args) {} };
        $path = tempnam(sys_get_temp_dir(), 'openxe-product-image-');
        try {
            file_put_contents($path, 'image bytes');
            $result = $connector->uploadFiles([['dateipfad' => $path, 'filename' => 'red.png', 'stichwort' => 'Shopbild', 'mimetype' => 'image/png']]);
            same(true, $result['success']);
            same(901, $result['files'][0]['wordpressid']);
            same('https://shop.invalid/red.png', $result['files'][0]['url']);
            same('POST', $connector->requests[1]['method']);
            same('image bytes', $connector->requests[1]['body']);
        } finally {
            unlink($path);
        }
    },
    'matrix source image in artikel_varianten Dateien reaches the variation singular image' => static function () {
        $article = remoteMatrixProduct();
        $article['artikel_varianten'][0]['Dateien'] = [
            'datei' => [base64_encode('png')], 'filename' => ['red.png'], 'extension' => ['png'],
            'titel' => ['Red'], 'beschreibung' => ['Red shirt'], 'stichwort' => ['Shopbild'],
            'id' => [15], 'version' => [1], 'extid' => [''],
        ];
        $connector = productConnector([$article], ['dateienuebertragen' => ['shopbild']]);
        $connector->ImportSendList();
        $variation = productWrite($connector->client, 'products/101/variations');
        same(['id' => 700], $variation['image'] ?? null);
    },
    'matrix Shopbild works with image-only source export and empty public attachment type setting' => static function () {
        $article = remoteMatrixProduct();
        $article['artikel_varianten'][0]['Dateien'] = ['datei' => [base64_encode('png'), base64_encode('pdf')], 'filename' => ['red.png', 'manual.pdf'], 'stichwort' => ['Shopbild', 'Datenblatt']];
        // getKonfig explodes the empty dateienuebertragen string; Source separately gates shopbilderuebertragen.
        $connector = productConnector([$article], ['dateienuebertragen' => ['']]);
        $connector->ImportSendList();
        $variation = productWrite($connector->client, 'products/101/variations');
        same(['id' => 700], $variation['image'] ?? null);
        check(!isset(array_column($variation['meta_data'] ?? [], 'value', 'key')['openxe_product_attachments']), 'Attachment type whitelist must still apply');
    },
    'variation public attachments preserve its custom metadata together with attachment metadata' => static function () {
        $article = remoteMatrixProduct();
        $article['artikel_varianten'][0]['meta_data'] = ['custom' => 'variant metadata', ['key' => 'openxe_product_attachments', 'value' => [['wp_media_id' => 1]]]];
        $article['artikel_varianten'][0]['Dateien'] = ['datei' => [base64_encode('pdf')], 'filename' => ['manual.pdf'], 'stichwort' => ['Datenblatt']];
        $connector = productConnector([$article], ['dateienuebertragen' => ['datenblatt']]);
        $connector->ImportSendList();
        $rows = productWrite($connector->client, 'products/101/variations')['meta_data'];
        same(2, count($rows), 'Prepared metadata overrides an existing key instead of appending a duplicate');
        $meta = array_column($rows, 'value', 'key');
        same('variant metadata', $meta['custom'] ?? null);
        same([['name' => 'manual.pdf', 'wp_media_id' => 700, 'typ' => 'datenblatt']], $meta['openxe_product_attachments']);
    },
    'default download setting removes explicit paid downloads from parent and all child source forms' => static function () {
        $article = remoteMatrixProduct(['downloadable' => true, 'virtual' => true, 'downloads' => [['name' => 'Paid', 'file' => 'https://example.invalid/paid.zip']]]);
        $article['artikel_varianten'][0]['downloadable'] = true;
        $article['artikel_varianten'][0]['downloads'] = [['name' => 'Paid', 'file' => 'https://example.invalid/paid.zip']];
        $connector = productConnector([$article]);
        $connector->ImportSendList();
        check(!isset(productWrite($connector->client, 'products')['downloadable']));
        check(!isset(productWrite($connector->client, 'products/101/variations')['downloads']), 'Disabled download export leaked to a child');
    },
    'enabled download option preserves only explicit input fields' => static function () {
        $connector = productConnector([remoteProduct(['downloadable' => true, 'virtual' => true, 'downloads' => [['name' => 'Paid', 'file' => 'https://example.invalid/paid.zip']]])], ['exportDownloads' => true]);
        $connector->ImportSendList();
        $payload = productWrite($connector->client, 'products');
        same(true, $payload['downloadable']);
        same(true, $payload['virtual']);
        same([['name' => 'Paid', 'file' => 'https://example.invalid/paid.zip']], $payload['downloads']);
    },
    'storage filtering permits categories properties pictures and variants' => static function () {
        $blocked = ShopimporterBase::storageNotNeededElements('shopimporter_woocommerce');
        foreach (['categories', 'properties', 'pictures', 'variants'] as $element) {
            check(!in_array($element, $blocked, true), 'Source export blocks ' . $element);
        }
    },
    'UI capabilities allow implemented category tree properties pictures and variants' => static function () {
        $capabilities = ShopimporterBase::shopCapabilities()['shopimporter_woocommerce'];
        foreach (['kategorie', 'kategoriebaum', 'bilder', 'varianten', 'matrixeigenschaften', 'eigenschaften'] as $name) {
            foreach (['createarticle', 'updatearticle'] as $operation) {
                check(in_array($capabilities[$name][$operation], [ShopimporterBase::CAN, ShopimporterBase::MUST], true), $name . ' ' . $operation . ' remains disabled');
            }
        }
    },
]);
