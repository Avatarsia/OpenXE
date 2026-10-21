<?php
// SPDX-License-Identifier: AGPL-3.0-only
require __DIR__ . '/bootstrap.php';
require dirname(__DIR__, 2) . '/www/lib/ShopimporterBase.php';
require dirname(__DIR__, 2) . '/www/pages/shopimporter_woocommerce.php';
require dirname(__DIR__, 2) . '/www/pages/shopexport.php';

use Xentral\Components\WooCommerce\DownloadSettings;
use Xentral\Components\WooCommerce\ProductSynchronizer;

class DownloadSettingsDatabase
{
    public $rows = [];
    public $calls = [];
    public $articles = [];
    public $articleShops = [];
    public $failInvalidation = false;
    private $transaction = false;
    private $snapshot;
    public function inTransaction() { return $this->transaction; }
    public function beginTransaction()
    {
        $this->transaction = true;
        $this->snapshot = [$this->rows, $this->articleShops];
    }
    public function commit() { $this->transaction = false; }
    public function rollBack()
    {
        [$this->rows, $this->articleShops] = $this->snapshot;
        $this->transaction = false;
    }
    public function SelectRow($sql)
    {
        if (preg_match('/FROM artikel WHERE id = ([0-9]+)/', $sql, $match)) {
            return $this->articles[$match[1]] ?? [];
        }
        if (preg_match('/FROM artikel_onlineshops WHERE artikel = ([0-9]+) AND shop = ([0-9]+)/', $sql, $match)) {
            return $this->articleShops[$match[2]][$match[1]] ?? [];
        }
        throw new RuntimeException('Unexpected Legacy query ' . $sql);
    }
    public function SelectArr($sql) { return []; }
    public function fetchValue($sql, $parameters)
    {
        $this->calls[] = [$sql, $parameters];
        return $this->rows[$parameters['name']] ?? false;
    }
    public function perform($sql, $parameters)
    {
        $this->calls[] = [$sql, $parameters];
        if (strpos($sql, 'UPDATE artikel_onlineshops') === 0) {
            if ($this->failInvalidation) {
                throw new RuntimeException('Invalidation failed');
            }
            $ids = [$parameters['article'], $this->articles[$parameters['source']]['variante_von'] ?? null];
            foreach ($ids as $id) {
                if (isset($this->articleShops[$parameters['shop']][$id])) {
                    $this->articleShops[$parameters['shop']][$id]['last_article_hash'] = '';
                }
            }
        } elseif (strpos($sql, 'DELETE') === 0) {
            unset($this->rows[$parameters['name']]);
        } else {
            $this->rows[$parameters['name']] = $parameters['wert'];
        }
    }
}

function downloadInput(array $changes = []): array
{
    return array_replace(['downloadable' => true, 'virtual' => true, 'downloads' => [['name' => 'Manual', 'file' => 'https://shop.example/manual.pdf']]], $changes);
}
function invalidDownload(callable $operation)
{
    try {
        $operation();
    } catch (InvalidArgumentException $error) {
        return;
    }
    throw new RuntimeException('Invalid download settings accepted');
}

class DownloadExportApi
{
    public $writes = [];
    private $nextId = 100;
    public function get($endpoint, $query = []) { return []; }
    public function post($endpoint, $payload)
    {
        $this->writes[$endpoint][] = $payload;
        return (object) ['id' => ++$this->nextId];
    }
}

function downloadConnector(DownloadSettings $store, $enabled): Shopimporter_Woocommerce
{
    $connector = (new ReflectionClass(Shopimporter_Woocommerce::class))->newInstanceWithoutConstructor();
    $article = array_fill_keys(['restmenge', 'inaktiv', 'lieferzeitmanuell', 'hersteller', 'herstellerlink', 'name_en', 'uebersicht_de', 'uebersicht_en', 'kurztext_de', 'gewicht', 'laenge', 'breite', 'hoehe', 'metadescription_de', 'metatitle_de', 'anzahl_lager', 'pseudolager', 'ausverkauft'], '');
    $connector->data = [array_replace($article, ['artikel' => 7, 'nummer' => 'PARENT', 'name_de' => 'Parent', 'preis' => 10, 'pseudopreis' => 10, 'steuersatz' => 19,
        'artikel_varianten' => [['artikel' => 8, 'nummer' => 'CHILD', 'preis' => 10]],
        'matrix_varianten' => ['artikel' => [8 => [['artikel' => 8, 'nummer' => 'CHILD', 'name' => 'Color', 'values' => 'Red']]]],
    ], downloadInput())];
    $connector->client = new DownloadExportApi();
    $connector->logger = new class { public function debug(...$args) {} public function info(...$args) {} };
    foreach (['downloadSettings' => $store, 'exportDownloads' => $enabled, 'backorders' => 0, 'metadataprefix' => '', 'productsmarty' => '', 'dateienuebertragen' => [], 'kategorienuebertragen' => false] as $key => $value) {
        if ((new ReflectionClass(Shopimporter_Woocommerce::class))->hasProperty($key)) {
            (new ReflectionProperty(Shopimporter_Woocommerce::class, $key))->setValue($connector, $value);
        } else {
            $connector->$key = $value;
        }
    }
    return $connector;
}

runTests([
    'untouched articles have no override and preserve Smarty fields' => static function () {
        $store = new DownloadSettings(new DownloadSettingsDatabase(), 5);
        same(null, $store->load(7));
        $source = array_merge(['artikel' => 7, 'nummer' => 'PARENT'], downloadInput());
        same($source, $store->apply($source));
    },
    'download settings persist per shop and article with named parameters' => static function () {
        $db = new DownloadSettingsDatabase();
        $first = new DownloadSettings($db, 5);
        same(downloadInput(), $first->save(7, downloadInput()));
        same(downloadInput(), $first->load(7));
        same(null, (new DownloadSettings($db, 6))->load(7));
        same(null, $first->load(8));
        same(['woocommerce_downloads:5:7'], array_keys($db->rows));
        foreach ($db->calls as [$sql, $parameters]) {
            if (strpos($sql, 'UPDATE artikel_onlineshops') === 0) {
                same(['shop' => '5', 'article' => '7', 'source' => '7'], $parameters);
            } else {
                check(strpos($sql, ':name') !== false);
                check(isset($parameters['name']));
            }
        }
    },
    'form flags normalize and blank rows are omitted' => static function () {
        $store = new DownloadSettings(new DownloadSettingsDatabase(), 5);
        same(downloadInput(['virtual' => false]), $store->save(7, ['downloadable' => '1', 'virtual' => '0', 'downloads' => [['name' => ' Manual ', 'file' => ' https://shop.example/manual.pdf '], ['name' => '', 'file' => '']]]));
    },
    'explicit disable overrides Smarty and produces empty Woo download payload' => static function () {
        $store = new DownloadSettings(new DownloadSettingsDatabase(), 5);
        $store->save(7, ['downloadable' => false, 'virtual' => false, 'downloads' => []]);
        $source = $store->apply(array_merge(['artikel' => 7, 'nummer' => 'PARENT'], downloadInput()));
        $mapped = (new ProductSynchronizer(null))->map($source);
        same(false, $mapped['downloadable']);
        same(false, $mapped['virtual']);
        same([], $mapped['downloads']);
    },
    'clear removes only the selected shop article override' => static function () {
        $db = new DownloadSettingsDatabase();
        $store = new DownloadSettings($db, 5);
        $store->save(7, downloadInput());
        $store->save(8, downloadInput());
        $store->clear(7);
        same(null, $store->load(7));
        same(downloadInput(), $store->load(8));
    },
    'both variant source forms load their own article settings' => static function () {
        $store = new DownloadSettings(new DownloadSettingsDatabase(), 5);
        $store->save(8, downloadInput());
        $source = ['artikel' => 7, 'artikel_varianten' => [['artikel' => 8, 'nummer' => 'A'], ['artikel' => 9, 'nummer' => 'B']], 'matrix_varianten' => ['artikel' => [8 => [['nummer' => 'A', 'name' => 'Size', 'values' => 'L']]]]];
        $result = $store->apply($source);
        same(downloadInput(), array_intersect_key($result['artikel_varianten'][0], downloadInput()));
        same(downloadInput(), array_intersect_key($result['matrix_varianten']['artikel'][8][0], downloadInput()));
        same($source['artikel_varianten'][1], $result['artikel_varianten'][1]);
        check(!isset($result['downloadable']), 'Child configuration leaked onto parent');
    },
    'malformed identifiers are rejected before database access' => static function () {
        foreach ([0, -1, '1 OR 1', [], true] as $id) {
            invalidDownload(static function () use ($id) { new DownloadSettings(new DownloadSettingsDatabase(), $id); });
            invalidDownload(static function () use ($id) { (new DownloadSettings(new DownloadSettingsDatabase(), 5))->load($id); });
        }
    },
    'only absolute HTTP URLs without embedded credentials are accepted' => static function () {
        foreach (['file:///etc/passwd', 'javascript:alert(1)', '/manual.pdf', 'ftp://shop.example/file', 'https://user:secret@shop.example/file', 'https://shop.example/invalid path', 'https://shop.example/file' . chr(10)] as $url) {
            invalidDownload(static function () use ($url) { (new DownloadSettings(new DownloadSettingsDatabase(), 5))->save(7, downloadInput(['downloads' => [['name' => 'File', 'file' => $url]]])); });
        }
        $store = new DownloadSettings(new DownloadSettingsDatabase(), 5);
        same('http://shop.example/file', $store->save(7, downloadInput(['downloads' => [['name' => 'File', 'file' => 'http://shop.example/file']]]))['downloads'][0]['file']);
    },
    'download row and field limits are enforced' => static function () {
        foreach ([array_fill(0, 51, ['name' => 'File', 'file' => 'https://shop.example/file']), [['name' => str_repeat('x', 256), 'file' => 'https://shop.example/file']], [['name' => 'File', 'file' => 'https://shop.example/' . str_repeat('x', 2050)]], [['name' => '', 'file' => 'https://shop.example/file']], [['name' => 'File', 'file' => '']]] as $rows) {
            invalidDownload(static function () use ($rows) { (new DownloadSettings(new DownloadSettingsDatabase(), 5))->save(7, downloadInput(['downloads' => $rows])); });
        }
    },
    'invalid field shapes and unsupported flag values are rejected' => static function () {
        foreach ([['downloadable' => 'yes'], ['virtual' => []], ['downloads' => 'x'], ['downloads' => ['x']], ['downloads' => [['name' => [], 'file' => 'https://shop.example/file']]]] as $changes) {
            invalidDownload(static function () use ($changes) { (new DownloadSettings(new DownloadSettingsDatabase(), 5))->save(7, downloadInput($changes)); });
        }
    },
    'real connector exports parent disable and child downloads from persisted settings' => static function () {
        $store = new DownloadSettings(new DownloadSettingsDatabase(), 5);
        $store->save(7, ['downloadable' => false, 'virtual' => false, 'downloads' => []]);
        $store->save(8, downloadInput());
        $connector = downloadConnector($store, true);
        $connector->ImportSendList();
        $parent = $connector->client->writes['products'][0];
        $child = $connector->client->writes['products/101/variations'][0];
        same(false, $parent['downloadable']);
        same([], $parent['downloads']);
        same(downloadInput()['downloads'], $child['downloads']);
        same(true, $child['virtual']);
        $store->save(8, ['downloadable' => false, 'virtual' => false, 'downloads' => []]);
        $connector = downloadConnector($store, true);
        $connector->ImportSendList();
        $child = $connector->client->writes['products/101/variations'][0];
        same(false, $child['downloadable']);
        same([], $child['downloads']);
    },
    'disabled saved settings keep editable rows but export empty downloads for parent and child' => static function () {
        $store = new DownloadSettings(new DownloadSettingsDatabase(), 5);
        $disabled = downloadInput(['downloadable' => false, 'virtual' => false]);
        $store->save(7, $disabled);
        $store->save(8, $disabled);
        $connector = downloadConnector($store, true);
        $connector->ImportSendList();
        foreach (['products', 'products/101/variations'] as $path) {
            $payload = $connector->client->writes[$path][0];
            same(false, $payload['downloadable']);
            same([], $payload['downloads']);
        }
        same($disabled, $store->load(7));
        same($disabled, $store->load(8));
    },
    'global export switch blocks persisted settings and Smarty fields on all products' => static function () {
        $store = new DownloadSettings(new DownloadSettingsDatabase(), 5);
        $store->save(7, downloadInput());
        $store->save(8, downloadInput());
        $connector = downloadConnector($store, false);
        $connector->ImportSendList();
        foreach (['products', 'products/101/variations'] as $path) {
            $payload = $connector->client->writes[$path][0];
            foreach (['downloadable', 'downloads', 'virtual'] as $field) {
                check(!array_key_exists($field, $payload));
            }
        }
    },
    'names allow 255 Unicode characters and reject incomplete persisted objects' => static function () {
        $store = new DownloadSettings(new DownloadSettingsDatabase(), 5);
        $name = str_repeat(chr(195) . chr(164), 255);
        same($name, $store->save(7, downloadInput(['downloads' => [['name' => $name, 'file' => 'https://shop.example/file']]]))['downloads'][0]['name']);
        $db = new DownloadSettingsDatabase();
        $db->rows['woocommerce_downloads:5:7'] = '{}';
        try {
            (new DownloadSettings($db, 5))->load(7);
        } catch (UnexpectedValueException $error) {
            return;
        }
        throw new RuntimeException('Incomplete persisted settings silently disable downloads');
    },
    'four byte Unicode names roundtrip through ASCII JSON for utf8mb3 storage' => static function () {
        $db = new DownloadSettingsDatabase();
        $store = new DownloadSettings($db, 5);
        $emoji = chr(240) . chr(159) . chr(147) . chr(152);
        $settings = downloadInput(['downloads' => [['name' => $emoji . ' Manual', 'file' => 'https://shop.example/manual.pdf']]]);
        $store->save(7, $settings);
        same($settings, $store->load(7));
        $serialized = $db->rows['woocommerce_downloads:5:7'];
        same(0, preg_match('/[^\\x00-\\x7f]/', $serialized));
        check(strpos($serialized, '\\ud83d\\udcd8') !== false, 'Emoji must use JSON Unicode surrogate escaping');
    },
    'save and clear invalidate real change detection for article and variant parent only in their shop' => static function () {
        $db = new DownloadSettingsDatabase();
        $db->articles = [7 => ['id' => 7, 'name_de' => 'Parent', 'variante_von' => null], 8 => ['id' => 8, 'name_de' => 'Child', 'variante_von' => 7]];
        $export = new Shopexport((object) ['DB' => $db], true);
        foreach ([5, 6] as $shop) {
            foreach ([7, 8] as $article) {
                $db->articleShops[$shop][$article] = ['last_article_hash' => $export->getArticleHash($article, $shop), 'last_article_transfer' => '2026-10-21'];
                same(false, $export->hasArticleHashChanged($article, $shop)['changed']);
            }
        }
        $store = new DownloadSettings($db, 5);
        $store->save(8, downloadInput());
        foreach ([7, 8] as $article) {
            same(true, $export->hasArticleHashChanged($article, 5)['changed']);
            same(false, $export->hasArticleHashChanged($article, 6)['changed']);
            $db->articleShops[5][$article]['last_article_hash'] = $export->getArticleHash($article, 5);
        }
        $store->clear(8);
        foreach ([7, 8] as $article) {
            same(true, $export->hasArticleHashChanged($article, 5)['changed']);
            same(false, $export->hasArticleHashChanged($article, 6)['changed']);
        }
        $db->articleShops[5][7]['last_article_hash'] = $export->getArticleHash(7, 5);
        $store->save(7, downloadInput());
        same(true, $export->hasArticleHashChanged(7, 5)['changed']);
    },
    'invalidation failure rolls back save and clear so the user can safely retry' => static function () {
        $db = new DownloadSettingsDatabase();
        $store = new DownloadSettings($db, 5);
        $store->save(7, downloadInput());
        $db->failInvalidation = true;
        foreach (['save', 'clear'] as $operation) {
            try {
                if ($operation === 'save') {
                    $store->save(7, downloadInput(['virtual' => false]));
                } else {
                    $store->clear(7);
                }
            } catch (RuntimeException $error) {
                same('Invalidation failed', $error->getMessage());
                same(downloadInput(), $store->load(7));
                same(false, $db->inTransaction());
                continue;
            }
            throw new RuntimeException('Invalidation failure was swallowed');
        }
    },
    'broken persisted settings fail closed instead of clearing remote products' => static function () {
        $db = new DownloadSettingsDatabase();
        $db->rows['woocommerce_downloads:5:7'] = '{invalid';
        try {
            (new DownloadSettings($db, 5))->load(7);
        } catch (UnexpectedValueException $error) {
            return;
        }
        throw new RuntimeException('Corrupt download settings accepted');
    },
]);
