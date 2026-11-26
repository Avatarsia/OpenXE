<?php
// SPDX-License-Identifier: AGPL-3.0-only

require __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/www/lib/ShopimporterBase.php';
require_once dirname(__DIR__, 2) . '/www/pages/shopimporter_woocommerce.php';

use Xentral\Components\WooCommerce\AdminPage;
use Xentral\Components\WooCommerce\VisibilityQueue;

final class VisibilityDatabase
{
    public array $config = [];
    public array $assigned = [];
    public bool $unavailable = false;
    private ?array $snapshot = null;
    private ?string $locked = null;

    public function inTransaction(): bool { return $this->snapshot !== null; }
    public function beginTransaction(): void { $this->snapshot = $this->config; }
    public function commit(): void { $this->snapshot = $this->locked = null; }
    public function rollBack(): void
    {
        $this->config = $this->snapshot;
        $this->commit();
    }

    public function fetchValue(string $sql, array $params)
    {
        if (str_contains($sql, 'artikel_onlineshops')) {
            check(str_contains($sql, 'aktiv = 1'));
            return $this->assigned[$params['shop']][$params['article']] ?? false;
        }
        if (str_contains($sql, 'GET_LOCK') || str_contains($sql, 'RELEASE_LOCK')) {
            return 1;
        }
        $key = $params['name'];
        if ($this->unavailable && str_starts_with($key, 'woocommerce_visibility_')) {
            throw new Error('Visibility storage unavailable');
        }
        if (str_ends_with($sql, 'FOR UPDATE')) {
            check($this->inTransaction() && isset($this->config[$key]));
            $this->locked = $key;
        }
        return $this->config[$key] ?? false;
    }

    public function perform(string $sql, array $params): void
    {
        check($this->inTransaction());
        $key = $params['name'];
        if (str_starts_with($sql, 'INSERT INTO konfiguration')) {
            $this->config[$key] ??= $params['wert'];
            return;
        }
        check(str_starts_with($sql, 'UPDATE konfiguration'));
        same($key, $this->locked);
        $this->config[$key] = $params['wert'];
    }

    public function fetchRow(string $sql, array $params): array
    {
        same(7, $params['shop']);
        return ['modulename' => 'shopimporter_woocommerce', 'projekt' => 0];
    }
}

final class VisibilityApi
{
    public array $products = [];
    public array $updates = [];
    public int $lookups = 0;
    private int $total = 0;

    public function get(string $endpoint, array $query): array
    {
        if ($endpoint === 'orders') {
            $this->total = 0;
            return [];
        }
        same('products', $endpoint);
        $this->lookups++;
        $matches = $this->products[$query['sku']] ?? [];
        $this->total = count($matches);
        return array_slice($matches, 0, $query['per_page']);
    }

    public function getLastResponse(): object
    {
        return new class($this->total) {
            public function __construct(private int $total) {}
            public function getHeader(string $name): int
            {
                return strtolower($name) === 'x-wp-totalpages' ? 1 : $this->total;
            }
        };
    }

    public function put(string $endpoint, array $data): object
    {
        $this->updates[] = [$endpoint, $data];
        return (object) $data;
    }
}

function visibilityConnector(VisibilityDatabase $db, bool $enabled = true): Shopimporter_Woocommerce
{
    $container = new class($db) {
        public object $logger;
        public function __construct(private VisibilityDatabase $db)
        {
            $this->logger = new class {
                public array $errors = [];
                public array $debug = [];
                public function error(...$args): void { $this->errors[] = $args; }
                public function debug(...$args): void { $this->debug[] = $args; }
            };
        }
        public function get(string $service): object
        {
            return match ($service) {
                'Database' => $this->db,
                'Logger' => $this->logger,
            };
        }
    };
    $connector = new Shopimporter_Woocommerce((object) ['Container' => $container]);
    $connector->shopid = 7;
    $connector->client = new VisibilityApi();
    $connector->lastImportTimestamp = '2025-01-01T00:00:00';
    if ($enabled) {
        (new ReflectionProperty(Shopimporter_Woocommerce::class, 'visibilitySync'))->setValue($connector, true);
    }
    return $connector;
}

runTests([
    'enqueue_is_idempotent' => static function () {
        check(class_exists(VisibilityQueue::class), 'VisibilityQueue is missing');
        $db = new VisibilityDatabase();
        $queue = new VisibilityQueue($db, 7);
        $queue->enqueue(41, 'A');
        $before = $db->config;
        $queue->enqueue(41, 'A');
        same($before, $db->config);
        same(['woocommerce_visibility_7'], array_keys($db->config));
        same(['A'], array_column((new VisibilityQueue($db, 7))->pending(), 'sku'));
        same([], (new VisibilityQueue($db, 8))->pending());
    },
    'cancel_on_reassign' => static function () {
        $db = new VisibilityDatabase();
        $queue = new VisibilityQueue($db, 7);
        $queue->enqueue(41, 'cancelled');
        $queue->cancel(41);
        $queue->enqueue(42, 'active');
        $db->assigned[7][42] = true;
        $queue->enqueue(43, 'other shop');
        $db->assigned[8][43] = true;
        $seen = [];
        $queue->drain(static function (string $sku) use (&$seen): void { $seen[] = $sku; });
        same(['other shop'], $seen);
        same([], $queue->pending());
    },
    'failed_hide_stays_queued' => static function () {
        $db = new VisibilityDatabase();
        $queue = new VisibilityQueue($db, 7);
        $queue->enqueue(41, 'A');
        $queue->enqueue(42, 'B');
        $result = $queue->drain(static function (string $sku): void {
            if ($sku === 'A') {
                throw new Error('<b>Unavailable</b> https://example.org/?consumer_key=ck_secret ' . str_repeat('long word ', 30));
            }
        });
        same([42], $result['processed']);
        $queue = new VisibilityQueue($db, 7);
        $before = $queue->pending();
        same(['A'], array_column($before, 'sku'));
        same([1], array_column($before, 'attempts'));
        $reason = $before[41]['lastError'];
        same($reason, $result['failed'][41]);
        check(str_starts_with($reason, 'Unavailable'));
        check(strlen($reason) <= 200 && !str_contains($reason, 'secret') && !str_contains($reason, '<b>'));
        $queue->enqueue(41, 'A');
        same($before, $queue->pending());
        $queue->drain(static function (string $sku): void { same('A', $sku); });
        same([], $queue->pending());
    },
    'drain_skipped_when_setting_off' => static function () {
        $db = new VisibilityDatabase();
        $queue = new VisibilityQueue($db, 7);
        $queue->enqueue(41, 'A');
        $connector = visibilityConnector($db, false);
        same(0, $connector->ImportGetAuftraegeAnzahl());
        same(0, $connector->client->lookups);
        same(['A'], array_column($queue->pending(), 'sku'));
    },
    'order_count_hides_only_unique_unassigned_products_and_variations' => static function () {
        $db = new VisibilityDatabase();
        $queue = new VisibilityQueue($db, 7);
        $connector = visibilityConnector($db);
        foreach ([1 => 'product', 2 => 'variation', 3 => 'duplicate', 4 => 'missing', 5 => 'active'] as $id => $sku) {
            $queue->enqueue($id, $sku);
        }
        $db->assigned[7][5] = true;
        $connector->client->products = [
            'product' => [(object) ['id' => 11, 'parent_id' => 0, 'sku' => 'product']],
            'variation' => [(object) ['id' => 22, 'parent_id' => 21, 'sku' => 'variation']],
            'duplicate' => [(object) ['id' => 31, 'parent_id' => 0, 'sku' => 'duplicate'],
                (object) ['id' => 32, 'parent_id' => 31, 'sku' => 'duplicate']],
            'active' => [(object) ['id' => 51, 'parent_id' => 0, 'sku' => 'active']],
        ];

        same(0, $connector->ImportGetAuftraegeAnzahl());
        same([['products/11', ['status' => 'private']], ['products/21/variations/22', ['status' => 'private']]], $connector->client->updates);
        same(['duplicate'], array_column($queue->pending(), 'sku'));
        same([1], array_column($queue->pending(), 'attempts'));
        check(str_contains($queue->pending()[3]['lastError'], 'mehrfach'));
        same(1, count($connector->logger->debug));
        same(1, count($connector->logger->errors));
    },
    'invalid_sku_filter_does_not_discard_a_potential_variation' => static function () {
        $db = new VisibilityDatabase();
        $queue = new VisibilityQueue($db, 7);
        $queue->enqueue(41, '0');
        $connector = visibilityConnector($db);
        same(0, $connector->ImportGetAuftraegeAnzahl());
        same(['0'], array_column($queue->pending(), 'sku'));
        same([1], array_column($queue->pending(), 'attempts'));
        same([], $connector->client->updates);
    },
    'storage_failure_does_not_break_order_count' => static function () {
        $db = new VisibilityDatabase();
        $queue = new VisibilityQueue($db, 7);
        $queue->enqueue(41, 'A');
        $db->unavailable = true;
        $connector = visibilityConnector($db);
        same(0, $connector->ImportGetAuftraegeAnzahl());
        same(1, count($connector->logger->errors));
        same([], $connector->client->updates);
        $db->unavailable = false;
        same(['A'], array_column($queue->pending(), 'sku'));
    },
    'enqueue_rejects_text_overflow_without_losing_previous_entries' => static function () {
        $db = new VisibilityDatabase();
        $queue = new VisibilityQueue($db, 7);
        $queue->enqueue(41, 'A');
        $before = $db->config;
        try {
            $queue->enqueue(42, str_repeat('x', 65000));
            throw new RuntimeException('Expected LengthException');
        } catch (LengthException $error) {
            check(str_contains($error->getMessage(), '65000'));
        }
        same($before, $db->config);
        check(!$db->inTransaction());
    },
    'admin_visibility_is_read_only_and_escapes_pending_details' => static function () {
        $db = new VisibilityDatabase();
        $db->config['woocommerce_visibility_7'] = '{"41":{"sku":"<SKU>","attempts":2,"lastError":"<error>","queuedAt":1764072000}}';
        $before = $db->config;
        $app = (object) [
            'Container' => new class($db) {
                public function __construct(private VisibilityDatabase $db) {}
                public function get(string $name): object { same('Database', $name); return $this->db; }
            },
            'erp' => new class {
                public function RechteVorhanden($module, $action): bool { return true; }
                public function UserProjektRecht($id): bool { return true; }
            },
            'Secure' => new class {
                public function GetGET($name): string { return $name === 'view' ? 'visibility' : ''; }
            },
        ];
        $html = (new AdminPage($app, 7))->render();
        foreach (['&lt;SKU&gt;', '&lt;error&gt;', 'UTC', '2025-11-25 12:00:00', '>2</td>'] as $text) {
            check(str_contains($html, $text), 'Missing ' . $text);
        }
        check(!str_contains($html, '<form'));
        same($before, $db->config);
    },
]);
