<?php
// SPDX-License-Identifier: AGPL-3.0-only
require __DIR__ . '/bootstrap.php';
require dirname(__DIR__, 2) . '/www/lib/ShopimporterBase.php';
require dirname(__DIR__, 2) . '/www/pages/shopimporter_woocommerce.php';

/** HTTP boundary only. The connector, mapper, categories and variations are real. */
class IntegrationProductApi
{
    public $collections = ['products' => [], 'products/categories' => []];
    public $writes = [];
    private $nextId = 100;

    public function get($endpoint, $query = [])
    {
        if (preg_match('#^products/(\d+)$#', $endpoint, $match)) {
            foreach ($this->collections['products'] as $row) {
                if ((int) $row['id'] === (int) $match[1]) {
                    return (object) $row;
                }
            }
            throw new RuntimeException('Product not found ' . $endpoint);
        }
        $rows = $this->collections[$endpoint] ?? [];
        if (isset($query['sku'])) {
            $rows = array_values(array_filter($rows, static function ($row) use ($query) {
                return ($row['sku'] ?? '') === $query['sku'];
            }));
        }
        $size = (int) ($query['per_page'] ?? 100);
        return array_map(static function ($row) { return (object) $row; }, array_slice($rows, ((int) ($query['page'] ?? 1) - 1) * $size, $size));
    }

    public function post($endpoint, $payload)
    {
        $this->writes[] = ['POST', $endpoint, $payload];
        $row = array_merge(['parent_id' => 0, 'categories' => []], $payload, ['id' => ++$this->nextId]);
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

class IntegrationProductConnector extends Shopimporter_Woocommerce
{
    public $uploadRequests = [];
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
    $connector->logger = new class { public function debug(...$args) {} public function info(...$args) {} public function error(...$args) {} };
    foreach (array_replace(['backorders' => 0, 'metadataprefix' => 'ox_', 'productsmarty' => '', 'filenamesmarty' => '', 'fileunique' => '-', 'kategorienuebertragen' => false, 'dateienuebertragen' => []], $settings) as $key => $value) {
        if ((new ReflectionClass(Shopimporter_Woocommerce::class))->hasProperty($key)) {
            (new ReflectionProperty(Shopimporter_Woocommerce::class, $key))->setValue($connector, $value);
        } else {
            $connector->$key = $value;
        }
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

runTests([
    'real ImportSendList preserves mapped metadata prices stock dimensions EAN and result identity' => static function () {
        $connector = productConnector([remoteProduct()]);
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
        same(['_yoast_wpseo_metadesc' => 'SEO description', '_yoast_wpseo_title' => 'SEO title', 'ox_custom' => 'kept'], array_column($payload['meta_data'], 'value', 'key'));
    },
    'real product Smarty transformation remains ahead of product mapping' => static function () {
        require_once dirname(__DIR__, 2) . '/vendor/smarty/smarty/libs/Smarty.class.php';
        $directory = sys_get_temp_dir() . '/openxe-smarty-' . bin2hex(random_bytes(5));
        mkdir($directory . '/smarty/templates', 0777, true);
        try {
            $connector = productConnector([remoteProduct()], ['productsmarty' => '{ "name_de":"{$artikel->nummer} custom","preis":7.5,"meta_data":{ "template":"kept" } }']);
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
            same('kept', array_column($payload['meta_data'], 'value', 'key')['ox_template']);
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
        same('variant metadata', $meta['ox_custom'] ?? null);
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
