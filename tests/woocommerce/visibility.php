<?php
// SPDX-License-Identifier: AGPL-3.0-only

require __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/www/lib/ShopimporterBase.php';
require_once dirname(__DIR__, 2) . '/www/pages/shopimporter_woocommerce.php';

use Xentral\Components\WooCommerce\AdminPage;
use Xentral\Components\WooCommerce\AdminView;
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
    public array $failures = [];
    public int $orderCount = 0;
    public bool $missingTotals = false;
    private int $total = 0;
    private int $pages = 0;

    public function get(string $endpoint, array $query): array
    {
        if ($endpoint === 'orders') {
            $this->total = $this->orderCount;
            return [];
        }
        same('products', $endpoint);
        $this->lookups++;
        if (in_array($query['sku'], $this->failures, true)) {
            throw new RuntimeException('Lookup unavailable');
        }
        $matches = $this->products[$query['sku']] ?? [];
        $this->total = count($matches);
        $this->pages = (int) ceil($this->total / $query['per_page']);
        return array_slice($matches, ($query['page'] - 1) * $query['per_page'], $query['per_page']);
    }

    public function getLastResponse(): object
    {
        return new class($this->total, $this->pages, $this->missingTotals) {
            public function __construct(private int $total, private int $pages, private bool $missingTotals) {}
            public function getHeader(string $name): ?int
            {
                return strtolower($name) === 'x-wp-totalpages'
                    ? ($this->missingTotals ? null : $this->pages)
                    : $this->total;
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
                public bool $unavailable = false;
                public function error(...$args): void
                {
                    if ($this->unavailable) {
                        throw new Error('Logger unavailable');
                    }
                    $this->errors[] = $args;
                }
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
        same(60, $before[41]['nextAttemptAt'] - $before[41]['lastAttemptAt']);
        same(['processed' => [], 'failed' => []], $queue->drain(static function (): void {
            throw new RuntimeException('Retry started before it was due');
        }));

        $before[41]['nextAttemptAt'] = time() - 1;
        $db->config['woocommerce_visibility_7'] = json_encode($before);
        $queue->drain(static function (): void { throw new RuntimeException('Still unavailable'); });
        $retry = $queue->pending()[41];
        same(2, $retry['attempts']);
        same(120, $retry['nextAttemptAt'] - $retry['lastAttemptAt']);

        $retry['attempts'] = 50;
        $retry['nextAttemptAt'] = time() - 1;
        $db->config['woocommerce_visibility_7'] = json_encode([41 => $retry]);
        $queue->drain(static function (): void { throw new RuntimeException('Still unavailable'); });
        $retry = $queue->pending()[41];
        same(86400, $retry['nextAttemptAt'] - $retry['lastAttemptAt']);

        $retry['nextAttemptAt'] = time() - 1;
        $db->config['woocommerce_visibility_7'] = json_encode([41 => $retry]);
        $queue->drain(static function (string $sku): void { same('A', $sku); });
        same([], $queue->pending());
    },
    'drain_bounds_backlog_and_prioritizes_never_attempted_entries' => static function () {
        $db = new VisibilityDatabase();
        $queue = new VisibilityQueue($db, 7);
        $connector = visibilityConnector($db);
        $connector->client->orderCount = 3;
        foreach (range(1, 50) as $id) {
            $sku = 'SKU-' . $id;
            $queue->enqueue($id, $sku);
            $connector->client->products[$sku] = [(object) ['id' => $id, 'parent_id' => 0, 'sku' => $sku]];
        }
        $connector->client->failures = array_map(static fn (int $id): string => 'SKU-' . $id, range(1, 10));
        foreach ([10, 30, 40] as $run => $hidden) {
            same(3, $connector->ImportGetAuftraegeAnzahl());
            same(($run + 1) * 20, $connector->client->lookups);
            same($hidden, count($connector->client->updates));
            $pending = $queue->pending();
            foreach (range(1, 10) as $id) {
                same($run === 2 ? 2 : 1, $pending[$id]['attempts']);
                $pending[$id]['nextAttemptAt'] = time() - 1;
            }
            $db->config['woocommerce_visibility_7'] = json_encode($pending);
        }
        same(range(1, 10), array_keys($queue->pending()));
    },
    'drain_tries_oldest_due_attempt_before_newer_due_attempt' => static function () {
        $db = new VisibilityDatabase();
        $db->config['woocommerce_visibility_7'] = json_encode([
            1 => ['sku' => 'NEWER', 'attempts' => 1, 'lastError' => '', 'queuedAt' => 1, 'lastAttemptAt' => 100, 'nextAttemptAt' => 160],
            2 => ['sku' => 'OLDER', 'attempts' => 1, 'lastError' => '', 'queuedAt' => 2, 'lastAttemptAt' => 50, 'nextAttemptAt' => 110],
        ]);
        $queue = new VisibilityQueue($db, 7);
        same([2, 1], $queue->drain(static function (): void {})['processed']);
    },
    'drain_does_not_start_another_entry_at_the_time_limit' => static function () {
        $db = new VisibilityDatabase();
        $seconds = 0.0;
        $queue = new VisibilityQueue($db, 7, static function () use (&$seconds): float { return $seconds; });
        $queue->enqueue(1, 'A');
        $queue->enqueue(2, 'B');
        $result = $queue->drain(static function () use (&$seconds): void { $seconds += 20; });
        same([1], $result['processed']);
        same([2], array_keys($queue->pending()));
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
    'order_count_hides_paginated_exact_comma_sku_but_leaves_multiple_exact_matches' => static function () {
        $db = new VisibilityDatabase();
        $queue = new VisibilityQueue($db, 7);
        $queue->enqueue(1, 'A,B');
        $queue->enqueue(2, 'A');
        $connector = visibilityConnector($db);
        $connector->client->products = [
            'A,B' => array_merge(
                array_map(static fn (int $id): object => (object) ['id' => $id, 'parent_id' => 0, 'sku' => 'A'], range(1, 100)),
                [(object) ['id' => 101, 'parent_id' => 0, 'sku' => 'A,B']]
            ),
            'A' => [
                (object) ['id' => 1, 'parent_id' => 0, 'sku' => 'A'],
                (object) ['id' => 2, 'parent_id' => 0, 'sku' => 'A'],
            ],
        ];
        same(0, $connector->ImportGetAuftraegeAnzahl());
        same([['products/101', ['status' => 'private']]], $connector->client->updates);
        same(3, $connector->client->lookups);
        same(['A'], array_column($queue->pending(), 'sku'));
        check(str_contains($queue->pending()[2]['lastError'], 'mehrfach'));
    },
    'empty_lookup_without_both_headers_keeps_the_entry' => static function () {
        $db = new VisibilityDatabase();
        $queue = new VisibilityQueue($db, 7);
        $queue->enqueue(41, 'A');
        $connector = visibilityConnector($db);
        $connector->client->missingTotals = true;
        same(0, $connector->ImportGetAuftraegeAnzahl());
        same(['A'], array_column($queue->pending(), 'sku'));
        same([], $connector->client->updates);
    },
    'sku_zero_is_shown_for_manual_action_and_never_retried' => static function () {
        $db = new VisibilityDatabase();
        $queue = new VisibilityQueue($db, 7);
        $queue->enqueue(41, '0');
        $connector = visibilityConnector($db);
        same(0, $connector->ImportGetAuftraegeAnzahl());
        same(['0'], array_column($queue->pending(), 'sku'));
        same([1], array_column($queue->pending(), 'attempts'));
        $entry = $queue->pending()[41];
        same('SKU 0 kann nicht eindeutig gesucht werden - bitte manuell ausblenden', $entry['lastError']);
        check($entry['manualAction']);
        check(str_contains(AdminView::visibility($queue->pending()), 'Manuell ausblenden'));
        $entry['nextAttemptAt'] = time() - 1;
        $db->config['woocommerce_visibility_7'] = json_encode([41 => $entry]);
        same(0, $connector->ImportGetAuftraegeAnzahl());
        same([1], array_column($queue->pending(), 'attempts'));
        same(0, $connector->client->lookups);
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
    'storage_and_logger_failure_do_not_break_order_count' => static function () {
        $db = new VisibilityDatabase();
        $db->unavailable = true;
        $connector = visibilityConnector($db);
        $connector->client->orderCount = 4;
        $connector->logger->unavailable = true;
        same(4, $connector->ImportGetAuftraegeAnzahl());
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
