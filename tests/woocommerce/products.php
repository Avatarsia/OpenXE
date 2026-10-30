<?php
// SPDX-License-Identifier: AGPL-3.0-only
require __DIR__ . '/bootstrap.php';

use Xentral\Components\WooCommerce\CategorySynchronizer;
use Xentral\Components\WooCommerce\ProductSynchronizer;

/** In-memory HTTP boundary; mapping and synchronization use production code. */
class ProductApi
{
    public $collections = [];
    public $writes = [];
    public $reads = [];
    private $nextId = 1000;

    public function get($endpoint, $query = [])
    {
        $this->reads[] = [$endpoint, $query];
        if (isset($this->collections[$endpoint])) {
            $items = $this->collections[$endpoint];
            if (isset($query['sku'])) {
                $items = array_values(array_filter($items, static function ($row) use ($query) {
                    return (string) ($row['sku'] ?? '') === (string) $query['sku'];
                }));
            }
            return array_map(static function ($row) { return (object) $row; }, array_slice($items, (($query['page'] ?? 1) - 1) * ($query['per_page'] ?? 100), $query['per_page'] ?? 100));
        }
        if (preg_match('#^products/(\d+)$#', $endpoint, $match)) {
            foreach ($this->collections['products'] ?? [] as $row) {
                if ((int) $row['id'] === (int) $match[1]) {
                    return (object) $row;
                }
            }
        }
        throw new RuntimeException('Unexpected GET ' . $endpoint);
    }

    public function post($endpoint, $payload)
    {
        $this->writes[] = ['POST', $endpoint, $payload];
        $row = array_merge($payload, ['id' => ++$this->nextId]);
        $this->collections[$endpoint][] = $row;
        return (object) $row;
    }

    public function put($endpoint, $payload)
    {
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

function matrixArticle()
{
    return [
        'artikel' => 7, 'nummer' => 'PARENT', 'name_de' => 'Shirt', 'preis' => 10,
        'eigenschaften' => [['name' => 'Material', 'values' => 'Cotton']],
        'matrix_varianten' => [
            'gruppen' => ['Farbe' => ['Rot' => true, 'Blau' => true], 'Groesse' => ['S' => true]],
            'artikel' => [
                8 => [
                    ['artikel' => 8, 'nummer' => 'RED-S', 'preis' => 12, 'lag' => 2, 'name' => 'Farbe', 'values' => 'Rot'],
                    ['name' => 'Groesse', 'values' => 'S'],
                ],
                9 => [
                    ['artikel' => 9, 'nummer' => 'BLUE-S', 'preis' => 13, 'lag' => 0, 'name' => 'Farbe', 'values' => 'Blau'],
                    ['name' => 'Groesse', 'values' => 'S'],
                ],
            ],
        ],
        // Remote adds images and further properties here before removing Dateien from matrix rows.
        'artikel_varianten' => [['artikel' => 8, 'nummer' => 'RED-S', 'preis' => 12, 'lag' => 2, 'ean' => '1234567890123']],
    ];
}

runTests([
    'category pages beyond 100 and parent scope reuse existing legacy categories' => static function () {
        $api = new ProductApi();
        $api->collections['products/categories'] = [];
        for ($i = 1; $i <= 100; $i++) {
            $api->collections['products/categories'][] = ['id' => $i, 'name' => 'Other ' . $i, 'slug' => 'other-' . $i, 'parent' => 0];
        }
        $api->collections['products/categories'][] = ['id' => 101, 'name' => 'Root', 'slug' => 'root', 'parent' => 0];
        $api->collections['products/categories'][] = ['id' => 102, 'name' => 'Same', 'slug' => 'same', 'parent' => 101];
        $api->collections['products/categories'][] = ['id' => 103, 'name' => 'Same', 'slug' => 'same-wrong', 'parent' => 0];
        $ids = (new CategorySynchronizer($api, 5))->sync([['id' => 20, 'name' => 'Same', 'parent' => 10]], [['id' => 10, 'name' => 'Root', 'parent' => 0]]);
        same([102], $ids);
        same(103, count($api->collections['products/categories']));
        same('openxe-5-20', $api->collections['products/categories'][101]['slug']);
    },
    'category hierarchy is created parent first and identity survives rename' => static function () {
        $api = new ProductApi();
        $api->collections['products/categories'] = [];
        $sync = new CategorySynchronizer($api, 5);
        $ids = $sync->sync([['id' => 20, 'name' => 'Child', 'parent' => 10]], [['id' => 10, 'name' => 'Root', 'parent' => 0]]);
        same('Root', $api->writes[0][2]['name']);
        same(1001, $api->writes[1][2]['parent']);
        same([1002], $ids);
        same([1002], (new CategorySynchronizer($api, 5))->sync([['id' => 20, 'name' => 'Renamed', 'parent' => 10]], [['id' => 10, 'name' => 'Root', 'parent' => 0]]));
        same(2, count($api->collections['products/categories']));
        same('Renamed', $api->collections['products/categories'][1]['name']);
    },
    'categories from another source shop are not adopted' => static function () {
        $api = new ProductApi();
        $api->collections['products/categories'] = [['id' => 3, 'name' => 'Same', 'parent' => 0, 'slug' => 'openxe-6-10']];
        same([1001], (new CategorySynchronizer($api, 5))->sync([['id' => 10, 'name' => 'Same', 'parent' => 0]]));
    },
    'configured category root is excluded by remote and lvl zero starts the exported tree' => static function () {
        $api = new ProductApi();
        $api->collections['products/categories'] = [];
        same([1001], (new CategorySynchronizer($api, 5))->sync([['id' => 10, 'name' => 'Visible root', 'parent' => 99, 'lvl' => 0]]));
        same(0, $api->writes[0][2]['parent']);
    },
    'category assignment is additive by default and replacement must be explicit' => static function () {
        $api = new ProductApi();
        $api->collections['products'] = [['id' => 6, 'categories' => [(object) ['id' => 2]]]];
        $sync = new CategorySynchronizer($api, 5);
        $sync->assign(6, [3, 3]);
        same([['id' => 2], ['id' => 3]], $api->writes[0][2]['categories']);
        $sync->assign(6, [], 'replace');
        same([], $api->writes[1][2]['categories']);
    },
    'missing or cyclic category ancestors fail before mutations' => static function () {
        foreach ([[['id' => 1, 'name' => 'A', 'parent' => 2]], [['id' => 1, 'name' => 'A', 'parent' => 2], ['id' => 2, 'name' => 'B', 'parent' => 1]]] as $categories) {
            $api = new ProductApi();
            $api->collections['products/categories'] = [];
            try {
                (new CategorySynchronizer($api, 5))->sync($categories);
                throw new RuntimeException('Expected category validation error');
            } catch (InvalidArgumentException $error) {
                same([], $api->writes);
            }
        }
    },
    'matrix parent and existing variation beyond first page sync idempotently' => static function () {
        $api = new ProductApi();
        $api->collections['products'] = [['id' => 50, 'sku' => 'PARENT', 'type' => 'simple', 'parent_id' => 0]];
        $api->collections['products/50/variations'] = [];
        for ($i = 1; $i <= 100; $i++) {
            $api->collections['products/50/variations'][] = ['id' => $i + 100, 'sku' => 'OTHER-' . $i];
        }
        $api->collections['products/50/variations'][] = ['id' => 201, 'sku' => 'RED-S'];
        $sync = new ProductSynchronizer($api);
        $result = $sync->sync(matrixArticle(), ['images' => [['id' => 77]], 'meta_data' => [['key' => 'openxe_product_attachments', 'value' => [['wp_media_id' => 78]]]]], ['backorders' => 'notify']);
        same(50, $result['id']);
        same(201, $result['variations'][8]);
        same('variable', $api->writes[0][2]['type']);
        same([['id' => 77]], $api->writes[0][2]['images']);
        $attrs = $api->writes[0][2]['attributes'];
        same(['Rot', 'Blau'], $attrs[1]['options']);
        same(true, $attrs[1]['variation']);
        same('products/50/variations/201', $api->writes[1][1]);
        same([['name' => 'Farbe', 'option' => 'Rot'], ['name' => 'Groesse', 'option' => 'S']], $api->writes[1][2]['attributes']);
        same('1234567890123', $api->writes[1][2]['global_unique_id']);
        same('notify', $api->writes[1][2]['backorders']);
        $again = $sync->sync(matrixArticle());
        same($result['variations'], $again['variations']);
        same(102, count($api->collections['products/50/variations']));
    },
    'new variable parent is created before children and child image is singular' => static function () {
        $api = new ProductApi();
        $api->collections['products'] = [];
        $api->collections['products/1001/variations'] = [];
        $result = (new ProductSynchronizer($api))->sync(matrixArticle(), [], ['variationPayloads' => [8 => ['images' => [['id' => 77]]]]]);
        same('products', $api->writes[0][1]);
        same('products/1001/variations', $api->writes[1][1]);
        same(['id' => 77], $api->writes[1][2]['image']);
        check(!isset($api->writes[1][2]['images']));
        same(1001, $result['id']);
    },
    'ordinary variants use their properties and retain foreign SKU' => static function () {
        $api = new ProductApi();
        $api->collections['products'] = [];
        $api->collections['products/1001/variations'] = [];
        $article = ['nummer' => 'P', 'artikel_varianten' => [['artikel' => 8, 'nummer' => 'INTERNAL', 'artikelnummer_fremdnummern' => [['nummer' => 'SHOP-SKU']], 'preis' => 0, 'eigenschaften' => [['name' => 'Size', 'values' => 'XL']]]]];
        (new ProductSynchronizer($api))->sync($article);
        same('SHOP-SKU', $api->writes[1][2]['sku']);
        same([['name' => 'Size', 'option' => 'XL']], $api->writes[1][2]['attributes']);
        same('0.00', $api->writes[1][2]['regular_price']);
    },
    'ordinary variants with equal properties remain separately selectable by SKU' => static function () {
        $api = new ProductApi();
        $api->collections['products'] = [];
        $api->collections['products/1001/variations'] = [];
        (new ProductSynchronizer($api))->sync(['nummer' => 'P', 'artikel_varianten' => [
            ['artikel' => 8, 'nummer' => 'A', 'eigenschaften' => [['name' => 'Material', 'values' => 'Cotton']]],
            ['artikel' => 9, 'nummer' => 'B', 'eigenschaften' => [['name' => 'Material', 'values' => 'Cotton']]],
        ]]);
        same(['name' => 'Artikelnummer', 'option' => 'A'], $api->writes[1][2]['attributes'][1]);
        same(['name' => 'Artikelnummer', 'option' => 'B'], $api->writes[2][2]['attributes'][1]);
    },
    'blank foreign SKU falls back to the actual article number' => static function () {
        $mapped = (new ProductSynchronizer(new ProductApi()))->map(['nummer' => 'INTERNAL', 'artikelnummer_fremdnummern' => [['nummer' => '']]]);
        same('INTERNAL', $mapped['sku']);
    },
    'stock override sold out rest quantity and reactivation map explicitly' => static function () {
        $sync = new ProductSynchronizer(new ProductApi());
        $pseudo = $sync->map(['nummer' => 'N', 'anzahl_lager' => 0, 'pseudolager' => 8, 'inaktiv' => 0], ['backorders' => 'yes']);
        same(8, $pseudo['stock_quantity']);
        same('publish', $pseudo['status']);
        $sold = $sync->map(['nummer' => 'N', 'anzahl_lager' => 8, 'ausverkauft' => 1], ['backorders' => 'yes']);
        same(0, $sold['stock_quantity']);
        same('outofstock', $sold['stock_status']);
        same('no', $sold['backorders']);
        $last = $sync->map(['nummer' => 'N', 'anzahl_lager' => -3, 'restmenge' => 1], ['backorders' => 'notify']);
        same(0, $last['stock_quantity']);
        same('no', $last['backorders']);
    },
    'mapping prices stock dimensions metadata and local properties' => static function () {
        $mapped = (new ProductSynchronizer(new ProductApi()))->map([
            'nummer' => 'N', 'name_de' => 'A &amp; B', 'preis' => '12.5', 'pseudopreis' => 20,
            'anzahl_lager' => '0', 'pseudolager' => '0', 'gewicht' => '1.2', 'laenge' => 0, 'breite' => '', 'hoehe' => '4.5',
            'ean' => '0123456789012', 'metadescription_de' => 'SEO', 'meta_data' => ['custom' => 'value'],
            'eigenschaften' => [['name' => 'Material', 'values' => 'Cotton']],
        ], ['backorders' => 1, 'metadataprefix' => 'ox_']);
        same('A & B', $mapped['name']);
        same('20.00', $mapped['regular_price']);
        same('12.50', $mapped['sale_price']);
        same(0, $mapped['stock_quantity']);
        same('onbackorder', $mapped['stock_status']);
        same('notify', $mapped['backorders']);
        same(['length' => '0', 'width' => '', 'height' => '4.5'], $mapped['dimensions']);
        same('0123456789012', $mapped['global_unique_id']);
        same([['key' => '_yoast_wpseo_metadesc', 'value' => 'SEO'], ['key' => 'ox_custom', 'value' => 'value']], $mapped['meta_data']);
        same([['name' => 'Material', 'visible' => true, 'variation' => false, 'options' => ['Cotton']]], $mapped['attributes']);
    },
    'gross pricing and empty stock preserve price basis and unmanaged inventory' => static function () {
        $mapped = (new ProductSynchronizer(new ProductApi()))->map(['nummer' => 'N', 'preis' => 10, 'bruttopreis' => 11.9, 'pseudopreis' => 20, 'anzahl_lager' => ''], ['priceType' => 'gross']);
        same('23.80', $mapped['regular_price']);
        same('11.90', $mapped['sale_price']);
        same(false, $mapped['manage_stock']);
        check(!isset($mapped['stock_quantity']));
    },
    'downloads require explicit opt in and never consume public attachments' => static function () {
        $sync = new ProductSynchronizer(new ProductApi());
        $public = $sync->map(['nummer' => 'N', 'Dateien' => [['dateipfad' => 'public.pdf']], 'downloads' => [['name' => 'Paid', 'file' => 'https://example.test/paid.zip']]]);
        check(!isset($public['downloads']));
        check(!isset($public['downloadable']));
        $paid = $sync->map(['nummer' => 'N', 'downloadable' => '1', 'virtual' => true, 'downloads' => [['id' => 'release', 'name' => 'Paid', 'file' => 'https://example.test/paid.zip']]]);
        same(true, $paid['downloadable']);
        same(true, $paid['virtual']);
        same([['id' => 'release', 'name' => 'Paid', 'file' => 'https://example.test/paid.zip']], $paid['downloads']);
        $disabled = $sync->map(['nummer' => 'N', 'downloadable' => 'false', 'virtual' => '0']);
        same(false, $disabled['downloadable']);
        same(false, $disabled['virtual']);
    },
    'exporting an individual existing variation uses parent endpoint' => static function () {
        $api = new ProductApi();
        $api->collections['products'] = [['id' => 60, 'sku' => 'CHILD', 'type' => 'variation', 'parent_id' => 50]];
        $api->collections['products/50/variations'] = [['id' => 60, 'sku' => 'CHILD']];
        $result = (new ProductSynchronizer($api))->sync(['nummer' => 'CHILD', 'preis' => 10, 'lag' => 0, 'ean' => '123']);
        same(60, $result['id']);
        same('products/50/variations/60', $api->writes[0][1]);
        same('123', $api->writes[0][2]['global_unique_id']);
    },
]);
