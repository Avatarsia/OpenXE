<?php
// SPDX-License-Identifier: AGPL-3.0-only

require __DIR__ . '/bootstrap.php';

use Xentral\Components\WooCommerce\RefundStateStore;
use Xentral\Components\WooCommerce\RefundSynchronizer;
use Xentral\Components\WooCommerce\RefundPreflight;

final class RefundDatabaseFake
{
    public $values = [];
    public $locks = [];
    public $markerWrites = [];
    public $failCompletion = false;

    public function inTransaction()
    {
        return false;
    }

    public function fetchValue($sql, array $params)
    {
        if (strpos($sql, 'GET_LOCK') !== false) {
            if (!empty($this->locks[$params['lock']])) {
                return 0;
            }
            $this->locks[$params['lock']] = true;
            return 1;
        }
        if (strpos($sql, 'RELEASE_LOCK') !== false) {
            unset($this->locks[$params['lock']]);
            return 1;
        }
        return $this->values[$params['name']] ?? false;
    }

    public function perform($sql, array $params)
    {
        if (strpos($sql, 'gutschrift') !== false) {
            $this->markerWrites[] = $params;
            return;
        }
        if ($this->failCompletion && strpos($sql, 'UPDATE') === 0) {
            throw new RuntimeException('completion write failed');
        }
        if (strpos($sql, 'INSERT') === 0 && isset($this->values[$params['name']])) {
            throw new RuntimeException('duplicate journal entry');
        }
        $this->values[$params['name']] = $params['value'];
    }
}

final class RefundClientFake
{
    public $pages;
    public $calls = [];

    public function __construct(array $refunds)
    {
        $this->pages = [1 => $refunds];
    }

    public function get($endpoint, $params = [])
    {
        $this->calls[] = [$endpoint, $params];
        return $this->pages[$params['page']] ?? [];
    }
}

function refundOrder()
{
    return (object) [
        'id' => 41, 'currency' => 'EUR', 'total' => '47.60',
        'line_items' => [(object) ['id' => 71, 'quantity' => 4, 'total' => '40.00', 'total_tax' => '7.60']],
        'shipping_lines' => [(object) ['id' => 72, 'total' => '5.00', 'total_tax' => '0.95']],
        'fee_lines' => [(object) ['id' => 73, 'total' => '2.00', 'total_tax' => '0.38']],
    ];
}

function refundFixture($id = 601, $quantity = -2, $total = '-20.00', $tax = '-3.80', $amount = '23.80')
{
    return (object) [
        'id' => $id, 'amount' => $amount,
        'line_items' => [(object) [
            'id' => 901, 'quantity' => $quantity, 'total' => $total, 'total_tax' => $tax,
            'meta_data' => [(object) ['key' => '_refunded_item_id', 'value' => 71]],
        ]],
    ];
}

function refundFailure(callable $work, $contains)
{
    try {
        $work();
    } catch (RuntimeException $error) {
        check(strpos($error->getMessage(), $contains) !== false, $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected failure containing ' . $contains);
}

function refundService($client, $db, callable $callback, $shopId = 5)
{
    return new RefundSynchronizer($client, $shopId, new RefundStateStore($db), $callback);
}

final class RefundPreflightDatabaseFake
{
    public $order = ['id' => 11];
    public $invoice = [];
    public $webIds = [['webid' => '71']];
    public $queries = [];
    public function fetchRow($sql, array $params)
    {
        $this->queries[] = [$sql, $params];
        return strpos($sql, 'shopexport') !== false ? $this->order : $this->invoice;
    }
    public function fetchAll($sql, array $params)
    {
        $this->queries[] = [$sql, $params];
        return $this->webIds;
    }
}

$tests = [
    'partial refund uses original line identity and unit gross without gateway writes' => static function () {
        $db = new RefundDatabaseFake();
        $client = new RefundClientFake([refundFixture()]);
        $seen = [];
        $sync = refundService($client, $db, static function ($data) use (&$seen) {
            $seen[] = $data;
            return 88;
        });
        same([601 => 88], $sync->sync(refundOrder()));
        same('41', $seen[0]->extid);
        same(23.8, $seen[0]->amount);
        same('71', $seen[0]->positions[0]->webid);
        same(2.0, $seen[0]->positions[0]->quantity);
        same(11.9, $seen[0]->positions[0]->price);
        same(true, $seen[0]->preserveinvoice);
        same('woocommerce-refund:5:41:601', $seen[0]->source);
        check(!isset($seen[0]->createinvoice));
        same('orders/41/refunds', $client->calls[0][0]);
    },
    'completed refunds survive new synchronizer instances and duplicate source IDs' => static function () {
        $db = new RefundDatabaseFake();
        $client = new RefundClientFake([refundFixture(), refundFixture()]);
        $count = 0;
        $callback = static function () use (&$count) { $count++; return 88; };
        refundService($client, $db, $callback)->sync(refundOrder());
        same([601 => 88], refundService($client, $db, $callback)->sync(refundOrder()));
        same(1, $count);
    },
    'full refund retains explicit quantities instead of invoking whole invoice cancellation' => static function () {
        $seen = null;
        $sync = refundService(new RefundClientFake([refundFixture(601, -4, '-40', '-7.60', '47.60')]), new RefundDatabaseFake(), static function ($data) use (&$seen) { $seen = $data; return 88; });
        $sync->sync(refundOrder());
        same(4.0, $seen->positions[0]->quantity);
        same(11.9, $seen->positions[0]->price);
        same(true, $seen->preserveinvoice);
    },
    'refunds are processed in source order even when Woo returns newest first' => static function () {
        $seen = [];
        $sync = refundService(new RefundClientFake([refundFixture(602), refundFixture(601)]), new RefundDatabaseFake(), static function ($data) use (&$seen) { $seen[] = $data->source; return count($seen); });
        $sync->sync(refundOrder());
        same(['woocommerce-refund:5:41:601', 'woocommerce-refund:5:41:602'], $seen);
    },
    'quantity zero money only and fee shipping refunds defer before booking' => static function () {
        foreach ([71, 72, 73] as $sourceId) {
            $refund = refundFixture(601, 0);
            $refund->line_items[0]->meta_data[0]->value = $sourceId;
            $db = new RefundDatabaseFake();
            $count = 0;
            $sync = refundService(new RefundClientFake([$refund]), $db, static function () use (&$count) { $count++; return 88; });
            refundFailure(static function () use ($sync) { $sync->sync(refundOrder()); }, 'quantity');
            same(0, $count);
            same([], $db->values);
        }
        $refund = refundFixture();
        $refund->line_items = [];
        $sync = refundService(new RefundClientFake([$refund]), new RefundDatabaseFake(), static function () { throw new LogicException('must not book'); });
        refundFailure(static function () use ($sync) { $sync->sync(refundOrder()); }, 'positions');
    },
    'unknown source IDs cannot book a refund' => static function () {
        $refund = refundFixture();
        $refund->line_items[0]->meta_data[0]->value = 999;
        $sync = refundService(new RefundClientFake([$refund]), new RefundDatabaseFake(), static function () { throw new LogicException('must not book'); });
        refundFailure(static function () use ($sync) { $sync->sync(refundOrder()); }, 'source');
    },
    'amount differences cannot become untaxed adjustment positions' => static function () {
        $refund = refundFixture();
        $refund->amount = '30.00';
        $sync = refundService(new RefundClientFake([$refund]), new RefundDatabaseFake(), static function () { throw new LogicException('must not book'); });
        refundFailure(static function () use ($sync) { $sync->sync(refundOrder()); }, 'amount');
    },
    'mapping failures remain retryable and later refunds cannot overtake' => static function () {
        $refund = refundFixture(601, 0);
        $client = new RefundClientFake([refundFixture(602), $refund]);
        $db = new RefundDatabaseFake();
        $count = 0;
        $sync = refundService($client, $db, static function () use (&$count) { $count++; return 88; });
        refundFailure(static function () use ($sync) { $sync->sync(refundOrder()); }, 'quantity');
        same(0, $count);
        $client->pages[1] = [refundFixture(602), refundFixture(601)];
        same([601 => 88, 602 => 88], $sync->sync(refundOrder()));
        same(2, $count);
    },
    'uncertain callback failure persists pending across restart and blocks later refunds' => static function () {
        $db = new RefundDatabaseFake();
        $client = new RefundClientFake([refundFixture(602), refundFixture(601)]);
        $count = 0;
        $callback = static function () use (&$count) { $count++; throw new RuntimeException('ERP failed after possible write'); };
        $sync = refundService($client, $db, $callback);
        refundFailure(static function () use ($sync) { $sync->sync(refundOrder()); }, 'ERP failed');
        $sync = refundService($client, $db, $callback);
        refundFailure(static function () use ($sync) { $sync->sync(refundOrder()); }, 'pending');
        same(1, $count);
        same([], $db->locks);
    },
    'completion write failure cannot rebook an already created credit note' => static function () {
        $db = new RefundDatabaseFake();
        $db->failCompletion = true;
        $count = 0;
        $sync = refundService(new RefundClientFake([refundFixture()]), $db, static function () use (&$count) { $count++; return 88; });
        refundFailure(static function () use ($sync) { $sync->sync(refundOrder()); }, 'completion');
        $db->failCompletion = false;
        refundFailure(static function () use ($sync) { $sync->sync(refundOrder()); }, 'pending');
        same(1, $count);
    },
    'concurrent workers cannot book the same order while first callback runs' => static function () {
        $db = new RefundDatabaseFake();
        $client = new RefundClientFake([refundFixture()]);
        $second = refundService($client, $db, static function () { throw new LogicException('second worker booked'); });
        $sync = refundService($client, $db, static function () use ($second) {
            refundFailure(static function () use ($second) { $second->sync(refundOrder()); }, 'locked');
            return 88;
        });
        same([601 => 88], $sync->sync(refundOrder()));
        same([], $db->locks);
    },
    'shop identities isolate identical source order and refund IDs' => static function () {
        $db = new RefundDatabaseFake();
        $client = new RefundClientFake([refundFixture()]);
        $count = 0;
        $callback = static function () use (&$count) { $count++; return 88; };
        refundService($client, $db, $callback, 5)->sync(refundOrder());
        refundService($client, $db, $callback, 6)->sync(refundOrder());
        same(2, $count);
    },
    'refund pagination reads every page before booking in source order' => static function () {
        $client = new RefundClientFake([]);
        for ($i = 0; $i < 100; $i++) {
            $client->pages[1][] = refundFixture(700 + $i);
        }
        $client->pages[2] = [refundFixture(601)];
        $seen = [];
        $sync = refundService($client, new RefundDatabaseFake(), static function ($data) use (&$seen) { $seen[] = $data->source; return 88; });
        $sync->sync(refundOrder());
        same(101, count($seen));
        same('woocommerce-refund:5:41:601', $seen[0]);
        same(2, count($client->calls));
    },
    'transport failure while fetching later pages cannot partially book the refund set' => static function () {
        $client = new class {
            public function get($endpoint, $params) {
                if ($params['page'] > 1) { throw new RuntimeException('Woo offline'); }
                $rows = [];
                for ($i = 0; $i < 100; $i++) { $rows[] = refundFixture(700 + $i); }
                return $rows;
            }
        };
        $db = new RefundDatabaseFake();
        $count = 0;
        $sync = refundService($client, $db, static function () use (&$count) { $count++; return 88; });
        refundFailure(static function () use ($sync) { $sync->sync(refundOrder()); }, 'offline');
        same(0, $count);
        same([], $db->values);
        same([], $db->locks);
    },
    'invalid callback result cannot be marked successful or blindly retried' => static function () {
        $db = new RefundDatabaseFake();
        $sync = refundService(new RefundClientFake([refundFixture()]), $db, static function () { return false; });
        refundFailure(static function () use ($sync) { $sync->sync(refundOrder()); }, 'credit note ID');
        refundFailure(static function () use ($sync) { $sync->sync(refundOrder()); }, 'pending');
    },
    'corrupt completed journal cannot hide a refund behind an invalid credit note ID' => static function () {
        $db = new RefundDatabaseFake();
        $db->values['woocommerce_refund:5:41:601'] = '{"status":"complete","credit_note_id":-1}';
        $sync = refundService(new RefundClientFake([refundFixture()]), $db, static function () { throw new LogicException('must not book'); });
        refundFailure(static function () use ($sync) { $sync->sync(refundOrder()); }, 'journal');
    },
    'preflight missing invoice stays retryable after invoice appears without creating pending intent' => static function () {
        $db = new RefundDatabaseFake();
        $preflightDb = new RefundPreflightDatabaseFake();
        $preflight = new RefundPreflight($preflightDb, 5);
        $count = 0;
        $sync = new RefundSynchronizer(new RefundClientFake([refundFixture()]), 5, new RefundStateStore($db),
            static function () use (&$count) { $count++; return 88; }, [$preflight, 'validate']);
        refundFailure(static function () use ($sync) { $sync->sync(refundOrder()); }, 'invoice');
        same([], $db->values);
        same(0, $count);
        $preflightDb->invoice = ['id' => 21, 'status' => 'freigegeben', 'waehrung' => 'EUR'];
        same([601 => 88], $sync->sync(refundOrder()));
        same(1, $count);
        same(['shop' => '5', 'extid' => '41', 'empty' => '', 'cancelled' => 'storniert'], $preflightDb->queries[0][1]);
    },
    'preflight rejects cancelled invoices currency mismatch and missing original webids before pending' => static function () {
        foreach (['cancelled', 'currency', 'webid', 'order'] as $case) {
            $db = new RefundDatabaseFake();
            $preflightDb = new RefundPreflightDatabaseFake();
            $preflightDb->invoice = ['id' => 21, 'status' => 'freigegeben', 'waehrung' => 'EUR'];
            if ($case === 'cancelled') { $preflightDb->invoice['status'] = 'storniert'; }
            if ($case === 'currency') { $preflightDb->invoice['waehrung'] = 'USD'; }
            if ($case === 'webid') { $preflightDb->webIds = [['webid' => '999']]; }
            if ($case === 'order') { $preflightDb->order = []; }
            $preflight = new RefundPreflight($preflightDb, 5);
            $sync = new RefundSynchronizer(new RefundClientFake([refundFixture()]), 5, new RefundStateStore($db),
                static function () { throw new LogicException('must not book'); }, [$preflight, 'validate']);
            refundFailure(static function () use ($sync) { $sync->sync(refundOrder()); }, $case);
            same([], $db->values);
        }
    },
];

require_once dirname(__DIR__, 2) . '/www/pages/shopimport.php';
require_once dirname(__DIR__, 2) . '/www/lib/class.erpapi.php';

final class RefundLegacyDatabaseFake
{
    public $invoiceStatus = 'freigegeben';
    public $hasInvoice = true;
    public $writes = [];

    public function real_escape_string($value) { return addslashes($value); }
    public function SelectRow($sql)
    {
        if (strpos($sql, '`shopexport`') !== false) { return ['id' => 5, 'aktiv' => 1, 'artikelrabatt' => 9]; }
        if (strpos($sql, '`auftrag`') !== false) { return ['id' => 11]; }
        if (strpos($sql, '`rechnung`') !== false) { return $this->hasInvoice ? ['id' => 21, 'status' => $this->invoiceStatus, 'waehrung' => 'EUR'] : []; }
        if (strpos($sql, '`gutschrift`') !== false) { return ['steuersatz_normal' => 19, 'steuersatz_ermaessigt' => 7]; }
        throw new RuntimeException('Unexpected legacy SELECT ' . $sql);
    }
    public function Select($sql)
    {
        if (strpos($sql, '`soll`') !== false) { return '23.80'; }
        if (strpos($sql, 'SELECT adresse') !== false) { return 9; }
        return '';
    }
    public function SelectArr($sql)
    {
        if (strpos($sql, 'NOW() as datum') !== false) { return [['projekt' => 1]]; }
        if (strpos($sql, 'ip.auftrag_position_id') !== false) {
            return [['webid' => '71', 'artikel' => 3, 'menge' => 4, 'preis' => 10, 'rabatt' => 0, 'auftrag_position_id' => 31]];
        }
        if (strpos($sql, 'cnp.steuersatz') !== false) {
            return [['webid' => '71', 'id' => 91, 'menge' => 4, 'preis' => 10, 'rabatt' => 0, 'umsatzsteuer' => 'normal', 'steuersatz' => 19]];
        }
        return [];
    }
    public function Update($sql)
    {
        $this->writes[] = $sql;
        if (strpos($sql, "UPDATE rechnung SET schreibschutz='1',status='storniert'") !== false) {
            $this->invoiceStatus = 'storniert';
        }
    }
    public function UpdateArr($table, $id, $idColumn, $data, $clear = false) {}
    public function Delete($sql) { $this->writes[] = $sql; }
}

final class RefundErpConversionFake extends erpAPI
{
    public function __construct($app) { $this->app = $app; }
    public function CreateGutschrift($adresse = '') { return 88; }
    public function Firmendaten($field, $projekt = '', $clearcache = false) { return ''; }
    public function LoadKurs($id, $typ, $projekt = '', $force = false) {}
    public function CopyBelegZwischenpositionen($orgtyp, $orgid, $newtyp, $newid, $zwischenpositionen = null) {}
    public function RechnungProtokoll($id, $text) {}
    public function SchnellFreigabe($beleg, $id) {}
}

final class RefundShopErpFake
{
    public $db;
    public $preserve = [];
    public function __construct($db) { $this->db = $db; }
    public function WeiterfuehrenRechnungZuGutschrift($id, $grund = 'Rechnung storniert', $preserveInvoiceStatus = false)
    {
        $this->preserve[] = $preserveInvoiceStatus;
        if (!$preserveInvoiceStatus) { $this->db->invoiceStatus = 'storniert'; }
        return 88;
    }
    public function RechnungProtokoll($id, $text) {}
    public function GutschriftProtokoll($id, $text) {}
    public function BelegFreigabe($type, $id) {}
    public function GutschriftMitUmsatzeuer($id) { return true; }
    public function GutschriftNeuberechnen($id) {}
}

final class RefundAppFake
{
    public $DB;
    public $erp;
    public $prepared;
    public function Container($name) { same('Database', $name); return $this->prepared; }
}

$tests['real ERP conversion can preserve invoice status while legacy default still cancels'] = static function () {
    $app = new RefundAppFake();
    $app->DB = new RefundLegacyDatabaseFake();
    $erp = new RefundErpConversionFake($app);
    same(88, $erp->WeiterfuehrenRechnungZuGutschrift(21, 'WooCommerce refund', true));
    same('freigegeben', $app->DB->invoiceStatus);
    same(88, $erp->WeiterfuehrenRechnungZuGutschrift(21));
    same('storniert', $app->DB->invoiceStatus);
};

$tests['Shopimport adapter preserves invoice for multiple partial refunds and records source identity'] = static function () {
    $app = new RefundAppFake();
    $app->DB = new RefundLegacyDatabaseFake();
    $app->erp = new RefundShopErpFake($app->DB);
    $app->prepared = new RefundDatabaseFake();
    $shopimport = new Shopimport($app, true);
    $db = new RefundDatabaseFake();
    $sync = refundService(new RefundClientFake([refundFixture(601), refundFixture(602)]), $db, static function ($data) use ($shopimport) { return $shopimport->Refund(5, $data); });
    same([601 => 88, 602 => 88], $sync->sync(refundOrder()));
    same([true, true], $app->erp->preserve);
    same('freigegeben', $app->DB->invoiceStatus);
    same(2, count($app->prepared->markerWrites));
    same('woocommerce-refund:5:41:601', $app->prepared->markerWrites[0]['source']);
    check(in_array('UPDATE `gutschrift_position` SET `menge` = 2.000000 WHERE `id` = 91', $app->DB->writes, true));
    check(in_array('UPDATE `gutschrift_position` SET `preis` = 10.000000, `rabatt` = 0 WHERE `id` = 91', $app->DB->writes, true));
};

$tests['Shopimport rejects currency mismatch before any credit note creation'] = static function () {
    $app = new RefundAppFake();
    $app->DB = new RefundLegacyDatabaseFake();
    $app->erp = new RefundShopErpFake($app->DB);
    $data = (object) ['extid' => '41', 'currency' => 'USD'];
    $shopimport = new Shopimport($app, true);
    try { $shopimport->Refund(5, $data); } catch (Exception $error) {
        check(strpos($error->getMessage(), 'currency') !== false, $error->getMessage());
        same([], $app->erp->preserve);
        return;
    }
    throw new RuntimeException('Currency mismatch was accepted');
};

$tests['Shopimport never creates a missing invoice for Woo refunds'] = static function () {
    $app = new RefundAppFake();
    $app->DB = new RefundLegacyDatabaseFake();
    $app->DB->hasInvoice = false;
    $app->erp = new RefundShopErpFake($app->DB);
    $data = (object) ['extid' => '41', 'currency' => 'EUR', 'preserveinvoice' => true];
    $shopimport = new Shopimport($app, true);
    try { $shopimport->Refund(5, $data); } catch (Exception $error) {
        same('order has no invoice', $error->getMessage());
        same([], $app->erp->preserve);
        return;
    }
    throw new RuntimeException('Missing invoice was accepted');
};

runTests($tests);
