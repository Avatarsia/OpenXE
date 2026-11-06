<?php
// SPDX-License-Identifier: AGPL-3.0-only
require __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/www/lib/ShopimporterBase.php';
require_once dirname(__DIR__, 2) . '/www/pages/shopimporter_woocommerce.php';
require_once dirname(__DIR__, 2) . '/www/pages/shopimport.php';

use Xentral\Components\Http\Session\Session;

class RecoveryDatabase
{
    public array $journal = [];
    public array $queue = [];
    public array $orders = [];
    public array $locks = [];
    public array $inserts = [];
    public bool $failInsert = false;
    public string $module = 'shopimporter_woocommerce';
    public function fetchValue($sql, $params = []) {
        if (strpos($sql, 'IS_USED_LOCK') !== false) { return ($this->locks[$params['name']] ?? '') === 'self' ? 1 : 0; }
        if (strpos($sql, 'GET_LOCK') !== false) {
            if (($this->locks[$params['name']] ?? 'self') !== 'self') { return 0; }
            $this->locks[$params['name']] = 'self'; return 1;
        }
        if (strpos($sql, 'RELEASE_LOCK') !== false) {
            if (($this->locks[$params['name']] ?? '') === 'self') { unset($this->locks[$params['name']]); }
            return 1;
        }
        if (strpos($sql, 'konfiguration') !== false) { return $this->journal[$params['name']] ?? false; }
        if (strpos($sql, 'FROM shopexport') !== false) { return 1001; }
        $rows = strpos($sql, 'shopimport_auftraege') !== false ? $this->queue : $this->orders;
        return $rows[$params['shop']][$params['id']] ?? false;
    }
    public function perform($sql, $params = []) { $this->journal[$params['name']] = $params['wert']; }
    public function Select($sql) {
        if (strpos($sql, 'prozessstarter') !== false) { return 0; }
        return $this->module;
    }
    public function SelectRow($sql) { return ['projekt' => 1001, 'holealle' => 0, 'modulename' => $this->module]; }
    public function real_escape_string($value) { return addslashes((string) $value); }
    public function Insert($sql) {
        if ($this->failInsert) { throw new RuntimeException('cart storage failed'); }
        check(preg_match('/INSERT INTO shopimport_auftraege \(([^)]+)\)/', $sql, $columns) === 1);
        $columns = array_map('trim', explode(',', $columns[1]));
        check(in_array('trash', $columns, true), 'Missing NOT NULL trash column required by STRICT_TRANS_TABLES');
        check(preg_match('/VALUES\((.*)\)\s*$/s', $sql, $values) === 1);
        $values = str_getcsv($values[1], ',', "'", '\\');
        same('0', trim($values[array_search('trash', $columns, true)]), 'New cart must be visible');
        $this->inserts[] = $sql;
        check(preg_match("/VALUES\('([0-9]+)'/", $sql, $match) === 1, 'Expected real cart INSERT');
        $this->queue[3][$match[1]] = count($this->inserts);
    }
    public function GetInsertID() { return count($this->inserts); }
}

class RecoveryApi
{
    public array $gets = [];
    public array $puts = [];
    public array $orders;
    public bool $fail = false;
    public bool $failPut = false;
    public function __construct() {
        $this->orders = [9429 => (object) ['id' => 9429, 'number' => 'SHOP-9429', 'status' => 'processing',
            'date_created_gmt' => '2026-10-01T10:00:00', 'date_modified_gmt' => '2026-10-29T10:00:00']];
    }
    public function get($endpoint, $query = []) {
        $this->gets[] = [$endpoint, $query];
        if ($this->fail) { throw new RuntimeException('HTTP failure consumer_secret=private'); }
        if ($endpoint === 'orders') { throw new RuntimeException('Single import must not query the feed'); }
        if (!isset($this->orders[(int) basename($endpoint)])) { throw new RuntimeException('Order not found'); }
        return $this->orders[(int) basename($endpoint)];
    }
    public function put($endpoint, $body) {
        if ($this->failPut) { throw new RuntimeException('status transport failed consumer_secret=private'); }
        $this->puts[] = [$endpoint, $body];
    }
}

class RecoveryConnector extends Shopimporter_Woocommerce
{
    public function parseOrder($order) { return ['auftrag' => $order->id, 'onlinebestellnummer' => $order->number]; }
}

function recoveryFixture()
{
    $_SESSION = [];
    $db = new RecoveryDatabase();
    $session = new Session();
    $secure = new class {
        public array $post = []; public array $get = ['id' => 3];
        public function GetGET($key, ...$unused) { return $this->get[$key] ?? ''; }
        public function GetPOST($key, ...$unused) { return $this->post[$key] ?? ''; }
    };
    $tpl = new class {
        public array $values = [];
        public function Set($key, $value) { $this->values[$key] = $value; }
        public function Add($key, $value) { $this->values[$key] = ($this->values[$key] ?? '') . $value; }
        public function Parse(...$args) {}
    };
    $erp = new class {
        public $shopimport;
        public bool $projectAllowed = true;
        public function LoadModul($module) { same('shopimport', $module); return $this->shopimport; }
        public function MenuEintrag(...$args) {}
        public function UserProjektRecht($project) { same(1001, $project); return $this->projectAllowed; }
    };
    $logger = new class {
        public array $errors = [];
        public function error($message, $context) { $this->errors[] = [$message, $context]; }
    };
    $container = new class($db, $session, $logger) {
        public function __construct(public $db, public $session, public $logger) {}
        public function get($key) { return $key === 'Session' ? $this->session : ($key === 'Logger' ? $this->logger : $this->db); }
    };
    $app = (object) ['Container' => $container, 'DB' => $db, 'Secure' => $secure, 'Tpl' => $tpl, 'erp' => $erp];
    $shopimport = new Shopimport($app, true); $erp->shopimport = $shopimport;
    $connector = new RecoveryConnector($app, true);
    $connector->client = new RecoveryApi(); $connector->shopid = 3;
    $connector->data = ['nummer' => '9429', 'change_status' => false];
    $connector->statusPending = 'processing;pending;on-hold';
    $connector->statusProcessing = 'xentral'; $connector->statusCompleted = 'completed';
    $connector->lastImportTimestamp = '2026-10-28T00:00:00';
    $app->remote = new class($connector) {
        public array $calls = [];
        public function __construct(public $connector) {}
        public function RemoteConnection($shop) { return 'success'; }
        public function RemoteCommand($shop, $action, $data) {
            $this->calls[] = [$shop, $action, $data];
            $this->connector->data = $data;
            $method = 'Import' . $action;
            return $this->connector->$method();
        }
        public function RemoteGetAuftraegeAnzahlNummer(...$args) { throw new RuntimeException('Legacy single import path used'); }
    };
    return [$connector, $db, $app, $shopimport, $session];
}

function enableCompleted($connector)
{
    (new ReflectionProperty(Shopimporter_Woocommerce::class, 'importCompletedOrders'))->setValue($connector, true);
}

runTests([
    'real cart writer supplies the strict-mode trash column for a visible queue entry' => static function () {
        [$connector, $db, $app, $shopimport] = recoveryFixture();
        $result = $shopimport->create_shopimport_cart_entry([
            'id' => 9429, 'sessionid' => '', 'warenkorbjson' => '', 'warenkorb' => 'fixture', 'logdatei' => '',
        ], 1001, 3);
        same(1, $result['status']); same(1, count($db->inserts));
    },
    'manual recovery loads exact old ID without touching automatic checkpoint or source status' => static function () {
        [$connector, $db] = recoveryFixture();
        $db->journal['woocommerce_import_3'] = '{"timestamp":"2026-10-28T00:00:00","ids":[9500]}';
        $before = $db->journal;
        same(1, $connector->ImportSingleOrder()['status']);
        same([['orders/9429', []]], $connector->client->gets);
        same([], $connector->client->puts); same($before, $db->journal);
        same(1, count($db->inserts)); check(isset($db->queue[3]['9429'])); same([], $db->locks);
    },
    'manual retries detect both queued and ERP identities in the same shop' => static function () {
        foreach (['queue', 'orders'] as $table) {
            [$connector, $db] = recoveryFixture(); $db->{$table}[3]['9429'] = 100;
            same(0, $connector->ImportSingleOrder()['status']);
            same([], $db->inserts); same([], $connector->client->gets); same([], $db->locks);
        }
        [$connector, $db] = recoveryFixture(); $db->orders[4]['9429'] = 100;
        same(1, $connector->ImportSingleOrder()['status']);
    },
    'repeated recovery queues an order once and leaves checkpoint unchanged' => static function () {
        [$connector, $db] = recoveryFixture();
        same(1, $connector->ImportSingleOrder()['status']); same(0, $connector->ImportSingleOrder()['status']);
        same(1, count($db->inserts)); same([], $db->journal);
    },
    'malformed IDs cannot turn manual recovery into a feed query' => static function () {
        foreach (['', '0', '-1', '9429abc', '9429/notes', '1e3', ['9429']] as $id) {
            [$connector, $db] = recoveryFixture(); $connector->data['nummer'] = $id;
            same(0, $connector->ImportSingleOrder()['status']); same([], $connector->client->gets); same([], $db->inserts);
        }
    },
    'cancelled refunded and unmatched source statuses are never manually queued' => static function () {
        foreach (['cancelled', 'refunded', 'failed', 'xentral', 'completed'] as $status) {
            [$connector, $db] = recoveryFixture(); $connector->client->orders[9429]->status = $status;
            same(0, $connector->ImportSingleOrder()['status']); same([], $db->inserts); same([], $db->locks);
        }
    },
    'wrong source identity cannot queue another order' => static function () {
        [$connector, $db] = recoveryFixture(); $connector->client->orders[9429]->id = 9500;
        same(0, $connector->ImportSingleOrder()['status']); same([], $db->inserts); same([], $db->locks);
    },
    'failed storage cannot report success change source status or move checkpoint' => static function () {
        [$connector, $db] = recoveryFixture(); $db->failInsert = true; $connector->data['change_status'] = true;
        same(0, $connector->ImportSingleOrder()['status']);
        same([], $connector->client->puts); same([], $db->journal); same([], $db->locks);
    },
    'failed status feedback retains one recoverable cart without moving an existing pending cursor' => static function () {
        [$connector, $db] = recoveryFixture(); $connector->data['change_status'] = true; $connector->client->failPut = true;
        $db->journal['woocommerce_import_3'] = '{"timestamp":"2026-10-28T00:00:00","ids":[9500],"pending":{"id":9501,"timestamp":"2026-10-29T00:00:00","lease_until":0}}';
        $before = $db->journal;
        $result = $connector->ImportSingleOrder();
        same(1, $result['status']); check(strpos($result['info'], 'Statusrueckmeldung fehlgeschlagen') !== false);
        same(1, count($connector->logger->errors));
        [$message, $context] = $connector->logger->errors[0];
        same('WooCommerce single import status feedback failed', $message);
        same(3, $context['shop']); same(9429, $context['order']);
        check(strpos($context['reason'], 'status transport failed') !== false);
        check(strpos($context['reason'], 'private') === false);
        same(0, $connector->ImportSingleOrder()['status']); same(1, count($db->inserts));
        same($before, $db->journal); same([], $db->locks);
    },
    'transport failures are safe to display and leave no locks or writes' => static function () {
        [$connector, $db] = recoveryFixture(); $connector->client->fail = true;
        $result = $connector->ImportSingleOrder(); same(0, $result['status']);
        check(strpos($result['error'], 'private') === false); same([], $db->locks); same([], $db->inserts);
    },
    'an automatic delivery lock fences manual recovery' => static function () {
        [$connector, $db] = recoveryFixture(); $db->locks['openxe_woocommerce_delivery_3'] = 'other';
        same(0, $connector->ImportSingleOrder()['status']); same([], $connector->client->gets); same([], $db->inserts);
        same(['openxe_woocommerce_delivery_3' => 'other'], $db->locks);
    },
    'completed recovery is opt in and never regresses the source status' => static function () {
        [$connector, $db] = recoveryFixture(); enableCompleted($connector);
        $connector->client->orders[9429]->status = 'completed'; $connector->data['change_status'] = true;
        same(1, $connector->ImportSingleOrder()['status']); same([], $connector->client->puts);
        same([], $db->journal); same([], $db->locks);
    },
    'manual processing feedback runs only after durable storage without acknowledging the feed' => static function () {
        [$connector, $db] = recoveryFixture(); $connector->data['change_status'] = true;
        same(1, $connector->ImportSingleOrder()['status']); check(isset($db->queue[3]['9429']));
        same([['orders/9429', ['status' => 'xentral']]], $connector->client->puts);
        same([], $db->journal); same([], $db->locks);
    },
    'existing single import entry point routes WooCommerce through targeted recovery' => static function () {
        [$connector, $db, $app, $shopimport] = recoveryFixture();
        same(1, $shopimport->importSingleOrder(3, '9429', false, 1001)['status']);
        same(1, count($db->inserts)); same('singleorder', $app->remote->calls[0][1]);
    },
    'single import form works in status mode with a valid session token' => static function () {
        [$connector, $db, $app, $shopimport, $session] = recoveryFixture();
        $app->Secure->post = ['nummer' => '9429', 'csrf_token' => $session->createCsrfToken('woocommerce_single_import')];
        $shopimport->ShopimportEinzelimport();
        same(1, count($db->inserts)); check(strpos($app->Tpl->values['MESSAGE'], 'Import erfasst') !== false);
        check(strpos($app->Tpl->values['SINGLEIMPORTTOKEN'] ?? '', 'csrf_token') !== false);
    },
    'invalid CSRF and GET-only requests cannot trigger single import' => static function () {
        foreach ([false, true] as $getOnly) {
            [$connector, $db, $app, $shopimport] = recoveryFixture();
            if ($getOnly) { $app->Secure->get['nummer'] = '9429'; }
            else { $app->Secure->post = ['nummer' => '9429', 'csrf_token' => 'invalid']; }
            $shopimport->ShopimportEinzelimport(); same([], $db->inserts); same([], $app->remote->calls);
        }
    },
    'form token survives a new session object before submitting the order' => static function () {
        [$connector, $db, $app, $shopimport, $session] = recoveryFixture();
        $shopimport->ShopimportEinzelimport();
        check(preg_match('/value="([^"]+)"/', $app->Tpl->values['SINGLEIMPORTTOKEN'], $match) === 1);
        $app->Container->session = new Session($_SESSION);
        $app->Secure->post = ['nummer' => '9429', 'csrf_token' => $match[1]];
        $shopimport->ShopimportEinzelimport(); same(1, count($db->inserts));
    },
    'single import form rejects an inaccessible shop project before remote calls' => static function () {
        [$connector, $db, $app, $shopimport, $session] = recoveryFixture(); $app->erp->projectAllowed = false;
        $app->Secure->post = ['nummer' => '9429', 'csrf_token' => $session->createCsrfToken('woocommerce_single_import')];
        $shopimport->ShopimportEinzelimport(); same([], $db->inserts); same([], $app->remote->calls);
    },
]);
