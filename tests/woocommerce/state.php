<?php
require __DIR__ . '/bootstrap.php';

use Xentral\Components\WooCommerce\ImportStateStore;

class SharedImportDatabase
{
    public array $rows = [];
    public array $locks = [];
    public array $queue = [];
    public array $orders = [];
}

class ImportDatabaseConnection
{
    public array $calls = [];
    public function __construct(public SharedImportDatabase $server, public int $connection) {}
    public function fetchValue($sql, $parameters = []) {
        $this->calls[] = [$sql, $parameters];
        if (strpos($sql, 'IS_USED_LOCK') !== false) {
            return ($this->server->locks[$parameters['name']] ?? null) === $this->connection ? 1 : 0;
        }
        if (strpos($sql, 'GET_LOCK') !== false) {
            $owner = $this->server->locks[$parameters['name']] ?? null;
            if ($owner !== null && $owner !== $this->connection) { return 0; }
            $this->server->locks[$parameters['name']] = $this->connection;
            return 1;
        }
        if (strpos($sql, 'RELEASE_LOCK') !== false) {
            if (($this->server->locks[$parameters['name']] ?? null) !== $this->connection) { return 0; }
            unset($this->server->locks[$parameters['name']]); return 1;
        }
        if (strpos($sql, 'konfiguration') !== false) { return $this->server->rows[$parameters['name']] ?? false; }
        if (strpos($sql, 'shopimport_auftraege') !== false) { return $this->server->queue[$parameters['shop']][$parameters['id']] ?? false; }
        if (strpos($sql, 'FROM auftrag') !== false) { return $this->server->orders[$parameters['shop']][$parameters['id']] ?? false; }
        throw new RuntimeException('Unexpected query: ' . $sql);
    }
    public function perform($sql, $parameters = []) {
        check(strpos($sql, 'adresse, firma') !== false, 'Strict-mode columns must be supplied');
        $this->server->rows[$parameters['name']] = $parameters['wert'];
    }
}

runTests([
    'shop journals are isolated and use durable named parameters' => static function () {
        $server = new SharedImportDatabase(); $db = new ImportDatabaseConnection($server, 1);
        $a = new ImportStateStore($db, 1); $b = new ImportStateStore($db, 2);
        $a->save(['ids' => [7]]);
        same(['ids' => [7]], $a->load()); same([], $b->load());
    },
    'delivery remains exclusive across store instances and connections' => static function () {
        $server = new SharedImportDatabase();
        $connectionA = new ImportDatabaseConnection($server, 1);
        $a = new ImportStateStore($connectionA, 1);
        $sameRequest = new ImportStateStore($connectionA, 1);
        $b = new ImportStateStore(new ImportDatabaseConnection($server, 2), 1);
        check($a->holdDelivery()); check($sameRequest->holdDelivery()); check(!$b->holdDelivery());
        same(1, count(array_filter($connectionA->calls, static fn($row) => strpos($row[0], 'GET_LOCK') !== false)));
        $sameRequest->releaseDelivery(); check($b->holdDelivery()); $b->releaseDelivery();
    },
    'different shops can deliver concurrently' => static function () {
        $server = new SharedImportDatabase();
        $a = new ImportStateStore(new ImportDatabaseConnection($server, 1), 1);
        $b = new ImportStateStore(new ImportDatabaseConnection($server, 2), 2);
        check($a->holdDelivery()); check($b->holdDelivery()); $a->releaseDelivery(); $b->releaseDelivery();
    },
    'state lock is released after callback exception' => static function () {
        $server = new SharedImportDatabase();
        $a = new ImportStateStore(new ImportDatabaseConnection($server, 1), 1);
        try { $a->locked(static function () { throw new RuntimeException('write failed'); }); }
        catch (RuntimeException $error) { same('write failed', $error->getMessage()); }
        same([], $server->locks);
    },
    'queue and ERP identities cannot cross shop boundaries' => static function () {
        $server = new SharedImportDatabase(); $server->queue[1]['7'] = 10; $server->orders[1]['8'] = 11;
        $db = new ImportDatabaseConnection($server, 1);
        $a = new ImportStateStore($db, 1); $b = new ImportStateStore($db, 2);
        check($a->isStored(7)); check($a->isStored(8)); check(!$b->isStored(7)); check(!$b->isStored(8));
        foreach ($db->calls as [$sql, $parameters]) {
            if (isset($parameters['shop'])) { check(strpos($sql, ':shop') !== false && strpos($sql, ':id') !== false); }
        }
    },
    'corrupt journal fails without resetting cursor' => static function () {
        $server = new SharedImportDatabase(); $server->rows['woocommerce_import_1'] = '{broken';
        try { (new ImportStateStore(new ImportDatabaseConnection($server, 1), 1))->load(); throw new RuntimeException('corrupt accepted'); }
        catch (JsonException $error) { check($error->getMessage() !== ''); }
        same('{broken', $server->rows['woocommerce_import_1']);
    },
]);
