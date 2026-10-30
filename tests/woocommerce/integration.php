<?php
require __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/www/lib/ShopimporterBase.php';
require_once dirname(__DIR__, 2) . '/www/pages/shopimporter_woocommerce.php';

class ConnectorDatabase
{
    public array $journal = [];
    public array $stored = [];
    public bool $failRetryInsert = false;
    public function inTransaction() { return false; }
    public function fetchValue($query, $values = []) {
        if (strpos($query, 'GET_LOCK') !== false || strpos($query, 'RELEASE_LOCK') !== false) { return 1; }
        if (strpos($query, 'IS_USED_LOCK') !== false) { return 0; }
        if (strpos($query, 'konfiguration') !== false) { return $this->journal[$values['name']] ?? false; }
        return isset($this->stored[$values['shop']][$values['id']]) ? 1 : false;
    }
    public function fetchAll($query, $values = []) {
        if (!isset($values['prefix'])) { return []; }
        $prefix = substr($values['prefix'], 0, -1); $rows = [];
        foreach ($this->journal as $name => $value) {
            if (strpos($name, $prefix) === 0) { $rows[] = ['name' => $name, 'wert' => $value]; }
        }
        return $rows;
    }
    public function perform($query, $values = []) {
        if ($this->failRetryInsert && strpos($values['name'], 'woocommerce_refund_retry:') === 0) { throw new RuntimeException('retry storage failed'); }
        if (strpos($query, 'DELETE') === 0) { unset($this->journal[$values['name']]); return; }
        $this->journal[$values['name']] = $values['wert'] ?? $values['value'];
    }
}

class ConnectorApi
{
    public array $calls = [];
    public object $order;
    public array $orders = [];
    public int $total = 0;
    public function __construct() {
        $this->order = (object) ['id' => 7, 'number' => '7', 'status' => 'processing', 'date_created_gmt' => '2020-01-01T00:00:00', 'date_modified_gmt' => '2026-01-01T00:00:00'];
        $this->orders = [7 => $this->order];
    }
    public function get($endpoint, $query = []) {
        $this->calls[] = [$endpoint, $query];
        if (strpos($endpoint, '/refunds') !== false) { throw new RuntimeException('Refund API unavailable'); }
        if ($endpoint !== 'orders') { return $this->orders[(int) basename($endpoint)]; }
        $rows = array_values(array_filter($this->orders, static function ($order) use ($query) {
            return $order->date_modified_gmt > $query['modified_after'] && $order->date_modified_gmt < $query['modified_before']
                && !in_array($order->id, $query['exclude'] ?? [], true);
        }));
        usort($rows, static fn($a, $b) => strcmp($a->date_modified_gmt, $b->date_modified_gmt));
        $this->total = count($rows);
        return array_slice($rows, 0, $query['per_page']);
    }
    public function put($endpoint, $data) { $this->calls[] = [$endpoint, $data]; return (object) $data; }
    public function getLastResponse() { return new class($this->total) { public function __construct(public $total) {} public function getHeader($key) { return $this->total; } }; }
}

class ConnectorLogger
{
    public array $errors = [];
    public function debug(...$args) {} public function info(...$args) {} public function warning(...$args) {} public function error(...$args) { $this->errors[] = $args; }
}

class IntegratedConnector extends Shopimporter_Woocommerce
{
    public function parseOrder($order) { return ['auftrag' => $order->id, 'onlinebestellnummer' => $order->number]; }
}

function integratedConnector(int $shop = 1, &$db = null): IntegratedConnector
{
    $db = new ConnectorDatabase();
    $container = new class($db) { public function __construct(public $db) {} public function get($name) { return $this->db; } };
    $app = (object) ['Container' => $container, 'erp' => new class { public function LoadModul($name) { return new stdClass(); } }];
    $connector = (new ReflectionClass(IntegratedConnector::class))->newInstanceWithoutConstructor();
    $property = new ReflectionProperty(Shopimporter_Woocommerce::class, 'app');
    $property->setAccessible(true); $property->setValue($connector, $app);
    $connector->client = new ConnectorApi(); $connector->logger = new ConnectorLogger();
    $connector->shopid = $shop; $connector->data = [];
    $connector->statusPending = 'processing'; $connector->statusProcessing = 'processing';
    $connector->lastImportTimestamp = '2025-01-01T00:00:00';
    $connector->lastImportTimestampIsFallback = false;
    return $connector;
}

function connectorWithDeferredRefund(&$db): IntegratedConnector
{
    $connector = integratedConnector(1, $db);
    (new ReflectionProperty(Shopimporter_Woocommerce::class, 'refundSync'))->setValue($connector, true);
    $old = clone $connector->client->order; $old->id = 1; $old->refunds = [(object) ['id' => 10]];
    $connector->client->order->date_modified_gmt = '2026-01-02T00:00:00';
    $connector->client->orders = [1 => $old, 7 => $connector->client->order];
    $db->stored[1]['1'] = true;
    return $connector;
}

runTests([
    'real connector reads modifications and saves pending without advancing checkpoint' => static function () {
        $connector = integratedConnector(1, $db);
        same(7, $connector->ImportGetAuftrag()[0]['id']);
        $state = json_decode($db->journal['woocommerce_import_1'] ?? '{}', true);
        same(7, $state['pending']['id'] ?? null);
        same(null, $state['timestamp'] ?? null);
        check(isset($connector->client->calls[0][1]['modified_after']));
    },
    'real connector refuses remote acknowledgement before durable queue write' => static function () {
        $connector = integratedConnector(1, $db);
        $connector->ImportGetAuftrag();
        $connector->data = ['auftrag' => 7];
        try { $connector->ImportDeleteAuftrag(); throw new RuntimeException('ack accepted'); }
        catch (LogicException $error) { check(strpos($error->getMessage(), 'stored') !== false); }
        same(1, count($connector->client->calls));
    },
    'acknowledged import is isolated by shop and commits after storage' => static function () {
        $connector = integratedConnector(3, $db);
        $connector->ImportGetAuftrag();
        $db->stored[3]['7'] = true;
        $connector->data = ['auftrag' => 7];
        same('ok', $connector->ImportDeleteAuftrag());
        $state = json_decode($db->journal['woocommerce_import_3'], true);
        same([7], $state['ids']);
        check(!isset($state['pending']));
        check(!isset($db->journal['woocommerce_import_1']));
    },
    'deferred refund does not block newer order and stays scheduled without Woo changes' => static function () {
        $connector = connectorWithDeferredRefund($db);
        same(7, $connector->ImportGetAuftrag()[0]['id']);
        check(isset($db->journal['woocommerce_refund_retry:1:1']));
        $db->stored[1]['7'] = true; $connector->data = ['auftrag' => 7]; $connector->ImportDeleteAuftrag();
        same(1, $connector->ImportGetAuftraegeAnzahl());
        same(null, $connector->ImportGetAuftrag());
        check(isset($db->journal['woocommerce_refund_retry:1:1']));
        same(1, count($connector->logger->errors));
        same('Refund API unavailable', $connector->logger->errors[0][1]['reason']);
        same(0, $connector->ImportGetAuftraegeAnzahl());
    },
    'failed durable refund enqueue cannot advance the order feed past the source' => static function () {
        $connector = connectorWithDeferredRefund($db); $db->failRetryInsert = true;
        try { $connector->ImportGetAuftrag(); throw new RuntimeException('storage failure hidden'); }
        catch (RuntimeException $error) { same('retry storage failed', $error->getMessage()); }
        check(!isset($db->journal['woocommerce_import_1']));
    },
]);
