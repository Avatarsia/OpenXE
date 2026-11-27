<?php
// SPDX-License-Identifier: AGPL-3.0-only
require __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/www/lib/class.erpapi.php';
require dirname(__DIR__, 2) . '/www/lib/class.remote.php';
require dirname(__DIR__, 2) . '/www/lib/ShopimporterBase.php';

use Psr\Log\NullLogger;
use Xentral\Modules\Onlineshop\Data\ArticleExportResult;

function exportResult(int $id, bool $success, bool $retry = false): ArticleExportResult
{
    $result = new ArticleExportResult();
    $result->articleId = $id;
    $result->success = $success;
    $result->retryExport = $retry;
    return $result;
}

class ApplicationCore
{
    public $DB;
    public $erp;
    public $remote;
    public $Conf;
    public $Container;

    public function __construct($legacyDatabase, $database = null)
    {
        $this->DB = $legacyDatabase;
        $this->Conf = new stdClass();
        $this->Container = new class($database) {
            public function __construct(private $database) {}
            public function get(string $name)
            {
                return $name === 'Logger' ? new NullLogger() : $this->database;
            }
        };
    }
}

final class ExportCronDatabase
{
    public bool $connection = true;
    public int $mutex = 0;
    public array $queue = [1 => 42, 2 => 43, 3 => 44, 4 => 45];
    public bool $failStockSelection = false;
    private int $affected = 0;

    public function Select(string $sql): int
    {
        return str_contains($sql, 'SELECT COUNT') ? count($this->queue) : $this->mutex;
    }

    public function SelectArr(string $sql): array
    {
        if (str_contains($sql, 'shopexport_artikeluebertragen_check')) {
            return [];
        }
        if (str_contains($sql, 'INNER JOIN shopexport') || str_contains($sql, 'FROM ' . chr(96) . 'shopexport' . chr(96))) {
            return [['id' => 1, 'shop' => 1, 'bezeichnung' => 'Test', 'artikelexportbatchsize' => 3]];
        }
        preg_match('/\x60id\x60 > (\d+)/', $sql, $cursor);
        $rows = [];
        foreach ($this->queue as $id => $article) {
            if ($id > ($cursor[1] ?? 0)) {
                $rows[] = ['id' => $id, 'artikel' => $article];
            }
        }
        return array_slice($rows, 0, 3);
    }

    public function SelectFirstCols(string $sql): array
    {
        same(1, $this->mutex, 'Stock job must own the mutex before selecting work');
        if ($this->failStockSelection) {
            throw new Error('Stock selection failed');
        }
        return [55, 56];
    }

    public function Update(string $sql): void
    {
        $this->affected = 0;
        if (preg_match('/\x60mutex\x60\s*=\s*([01])/', explode('WHERE', $sql)[0], $match)) {
            $next = (int) $match[1];
            if ($next !== $this->mutex) {
                $this->affected = 1;
                $this->mutex = $next;
            }
        }
    }

    public function affected_rows(): int { return $this->affected; }

    public function Delete(string $sql): void
    {
        if (preg_match('/\x60id\x60 IN \(([\d,]+)\)/', $sql, $ids)) {
            foreach (explode(',', $ids[1]) as $id) {
                unset($this->queue[$id]);
            }
        } else {
            throw new RuntimeException('Expected deletion by queue ID');
        }
    }
}

final class CronExportErp
{
    public array $synced = [];
    public bool $failStock = false;
    public array $config = [];

    public function LagerSync(int $articleId, bool $print, array $shops = []): string
    {
        $this->synced[] = [$articleId, $print];
        if ($this->failStock && $articleId === 55) {
            throw new TypeError('Stock transfer failed');
        }
        return '';
    }

    public function Firmendaten(string $field): int { return 1; }
    public function GetKonfiguration(string $key): int { return $this->config[$key] ?? 0; }
    public function SetKonfigurationValue(string $key, int $value): void { $this->config[$key] = $value; }
}

function runExportCron(ApplicationCore $app, string $name): void
{
    require dirname(__DIR__, 2) . '/cronjobs/' . $name . '.php';
}

final class LagerSyncErpFixture extends erpAPI
{
    public function getHookByAlias($alias) { return []; }
    public function GenerateHook($name, $parametercount, $aktiv, $new = false)
    {
        return ['id' => 1, 'aktiv' => 0, 'parametercount' => $parametercount];
    }
    public function GetArtikelshops($artikel) { return [1]; }
    public function GetArtikelShopEinstellung($wert, $artikel, $shop = null)
    {
        return ['autolagerlampe' => 1, 'pseudolager' => '0'];
    }
    public function Projektdaten($projekt, $feld) { return 0; }
    public function ArtikelAnzahlVerkaufbar($artikelid, $offset = 0, $projektlager = 0, $shopid = 0, $modus = 0, $returnArray = false)
    {
        return 4;
    }
}

final class LagerSyncDatabaseFixture
{
    public array $updates = [];

    public function SelectArr(string $sql): array
    {
        return [['id' => 42, 'bestandalternativartikel' => 0, 'cache_lagerplatzinhaltmenge' => 4, 'nummer' => 'SKU', 'name_de' => 'SKU']];
    }

    public function SelectRow(string $sql): array
    {
        return ['storage_cache' => 1, 'pseudostorage_cache' => 0, 'last_storage_transfer_hours' => 0];
    }

    public function Update(string $sql): void { $this->updates[] = $sql; }
}

final class ObjGenArtikel
{
    private int $id;
    public function __construct($app) {}
    public function Select($id): void { $this->id = $id; }
    public function GetMatrixprodukt(): bool { return $this->id === 2; }
    public function GetVariante(): bool { return $this->id === 3; }
    public function GetVariante_Von(): int { return $this->id === 3 ? 2 : 0; }
    public function GetNummer(): string { return 'SKU-' . $this->id; }
    public function __call(string $method, array $args): string { return ''; }
}

final class RemoteExportDatabaseFixture
{
    public bool $priceAvailable = true;
    public array $updates = [];

    public function SelectRow(string $sql): array
    {
        return [
            'artikelexport' => 1, 'modulename' => 'shopimporter_woocommerce',
            'lagergrundlage' => 0, 'shopbilderuebertragen' => 0, 'dateienuebertragen' => '',
            'projekt' => 0, 'variantenuebertragen' => 1, 'texteuebertragen' => 0,
            'kategorienuebertragen' => 0, 'eigenschaftenuebertragen' => 0,
            'holealle' => 0, 'holeallestati' => 0, 'datumvon' => '2000-01-01 00:00:00',
            'datumbis' => '2000-01-02 00:00:00', 'tmpdatumvon' => null, 'tmpdatumbis' => null, 'anzgleichzeitig' => 5,
        ];
    }

    public function SelectArr(string $sql): array
    {
        return $this->priceAvailable && str_contains($sql, 'ORDER BY v.preis DESC LIMIT 1')
            ? [['preis' => 10, 'waehrung' => 'EUR']] : [];
    }

    public function Select(string $sql): string { return ''; }
    public function Update(string $sql): void { $this->updates[] = $sql; }
}

final class MatrixExportDatabaseFixture
{
    public array $offsets = [];

    public function __construct(private int $count) {}

    public function yieldAll(string $sql): iterable
    {
        if (!preg_match('/LIMIT 5000 OFFSET (\d+)/', $sql, $match)) {
            return;
        }
        $offset = (int) $match[1];
        $this->offsets[] = $offset;
        for ($i = $offset; $i < min($offset + 5000, $this->count); $i++) {
            yield [
                'artikel' => 100 + $i, 'umsatzsteuer' => 'normal', 'pseudolager' => 0,
                'zolltarifnummer' => '', 'gewicht' => 0, 'preis' => 10, 'gesperrt' => 0,
                'nummer' => 'VAR-' . $i, 'lieferzeitmanuell' => '', 'altersfreigabe' => 0,
                'ean' => '', 'pseudopreis' => '', 'restmenge' => 0, 'inaktiv' => 0,
                'name_de' => '', 'name_en' => '', 'uebersicht_de' => '', 'uebersicht_en' => '',
            ];
        }
        check(str_contains($sql, 'ASC, art.id ASC'), 'Matrix paging needs a unique tie-breaker');
    }
}

function remoteExportApp(int $variants = 0): ApplicationCore
{
    $app = new ApplicationCore(new RemoteExportDatabaseFixture(), new MatrixExportDatabaseFixture($variants));
    $app->erp = new class {
        public function ModulVorhanden(string $name): bool { return $name === 'matrixprodukt'; }
        public function GetArtikelShopEinstellung(string $key) { return $key === 'autolagerlampe' ? 1 : ''; }
        public function RunHook(...$args): void {}
        public function GetEinkaufspreis(...$args): int { return 0; }
        public function ArtikelAnzahlVerkaufbar(...$args): int { return 4; }
        public function GetStandardSteuersatzErmaessigt($project): int { return 7; }
        public function GetStandardSteuersatzNormal($project): int { return 19; }
        public function Systemlog(string $message): void {}
    };
    return $app;
}

runTests([
    'article cron advances beyond retries and syncs only the successful result ID' => static function () {
        $app = new ApplicationCore(new ExportCronDatabase());
        $app->erp = new CronExportErp();
        $app->remote = new class($app) {
            public array $batches = [];
            public function __construct(private ApplicationCore $app) {}
            public function RemoteSendArticleList($shop, array $articles): array
            {
                same(1, $this->app->DB->mutex);
                $this->batches[] = $articles;
                return array_map(static fn (int $id) => match ($id) {
                    42 => exportResult($id, false, true),
                    43 => exportResult($id, false),
                    default => exportResult($id, true),
                }, $articles) + [9 => exportResult(0, true), 10 => 'invalid'];
            }
        };
        runExportCron($app, 'artikeluebertragen');
        same([1 => 42, 4 => 45], $app->DB->queue);
        same([[44, true]], $app->erp->synced);
        same(0, $app->DB->mutex);
        runExportCron($app, 'artikeluebertragen');
        same([[42, 43, 44], [45]], $app->remote->batches);
        same([1 => 42], $app->DB->queue);
        same([[44, true], [45, true]], $app->erp->synced);
        same(0, $app->DB->mutex);
    },
    'article cron retains batches on Throwable or null and releases the mutex' => static function () {
        $app = new ApplicationCore(new ExportCronDatabase());
        $app->erp = new CronExportErp();
        $app->remote = new class {
            private bool $fail = true;
            public function RemoteSendArticleList($shop, array $articles)
            {
                if ($this->fail) {
                    $this->fail = false;
                    throw new TypeError('batch failed');
                }
                return null;
            }
        };
        for ($i = 0; $i < 2; $i++) {
            runExportCron($app, 'artikeluebertragen');
            same([1 => 42, 2 => 43, 3 => 44, 4 => 45], $app->DB->queue);
            same(0, $app->DB->mutex);
        }
    },
    'stock cron catches per-article errors and releases mutex on exceptional exit' => static function () {
        $app = new ApplicationCore(new ExportCronDatabase());
        $app->erp = new CronExportErp();
        $app->erp->failStock = true;
        $app->remote = new stdClass();
        runExportCron($app, 'lagerzahlen');
        same([[55, false], [56, false]], $app->erp->synced);
        same(0, $app->DB->mutex);
        $app->DB->failStockSelection = true;
        runExportCron($app, 'lagerzahlen');
        same(0, $app->DB->mutex);
    },
    'LagerSync updates shop and article caches only after a confirmed transfer' => static function () {
        $app = new ApplicationCore(new LagerSyncDatabaseFixture());
        $app->erp = new LagerSyncErpFixture($app);
        $app->remote = new class($app->DB) {
            public $result = 0;
            public function __construct(private LagerSyncDatabaseFixture $db) {}
            public function RemoteSendArticleList($shop, $articles, $external, $stockOnly)
            {
                same([], $this->db->updates, 'Cache update before remote confirmation');
                same([42], $articles);
                same(true, $stockOnly);
                return $this->result;
            }
        };
        $shops = [1 => ['projekt' => 0, 'lagergrundlage' => 0]];
        foreach ([0, null, exportResult(42, false, true), [exportResult(99, true)], 1, exportResult(42, true), [exportResult(42, true)]] as $index => $result) {
            $app->remote->result = $result;
            $app->DB->updates = [];
            $app->erp->LagerSync(42, false, $shops);
            if ($index < 4) {
                same([], $app->DB->updates);
            } else {
                $sql = implode("\n", $app->DB->updates);
                foreach (['storage_cache', 'pseudostorage_cache', 'last_storage_transfer', 'cache_lagerplatzinhaltmenge', 'laststorage_sync'] as $field) {
                    check(str_contains($sql, $field), $field . ' was not written');
                }
            }
        }
    },
    'matrix product keeps every page and both surrounding products in the batch' => static function () {
        foreach ([5000, 5001] as $count) {
            $app = remoteExportApp($count);
            $remote = new class($app, $count) extends Remote {
                public function __construct($app, private int $variants) { parent::__construct($app); }
                protected function sendlist(int $shop_id, array $data, $isLagerExported): array
                {
                    foreach ($data as $row) {
                        if ($row['artikel'] === 2) {
                            same(range(100, 99 + $this->variants), array_keys($row['matrix_varianten']['artikel']));
                            same($this->variants, count($row['artikel_varianten']));
                        }
                    }
                    return array_map(static fn (int $id) => exportResult($id, true), array_column($data, 'artikel'));
                }
            };
            same([1, 2, 3], array_column($remote->RemoteSendArticleList(1, [1, 2, 3]), 'articleId'));
            same([0, 5000], $app->Container->get('Database')->offsets);
            same([], $app->DB->updates);
        }
    },
    'Remote handles stock-only descriptions, missing price, null log context and empty order count' => static function () {
        $app = remoteExportApp();
        $app->DB->priceAvailable = false;
        $remote = new class($app) extends Remote {
            public array $commands = [];
            public function RemoteCommand($id, $action, $data = '')
            {
                $this->commands[$action] = $data;
                return $action === 'sendlistlager' ? 1 : null;
            }
        };
        same(1, $remote->RemoteSendArticleList(1, [3], '', true));
        same([], $app->DB->updates);
        same(null, $remote->RemoteSendArticleList(1, [1]));
        same('ignore', $remote->commands['sendlist'][0]['artikel']);
        same(1, $remote->commands['sendlist'][0]['artikelid']);
        same(null, $remote->RemoteGetAuftraegeAnzahl(1));
    },
]);
