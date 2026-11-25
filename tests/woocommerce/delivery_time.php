<?php
// SPDX-License-Identifier: AGPL-3.0-only

require __DIR__ . '/bootstrap.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/www/lib/ShopimporterBase.php';
require dirname(__DIR__, 2) . '/www/pages/shopimporter_woocommerce.php';

use Xentral\Components\WooCommerce\DeliveryTimeMap;

class DeliveryTimeConnector extends Shopimporter_Woocommerce
{
    public array $requests = [];
    public array $result = [];

    public function __construct($database)
    {
        $this->app = (object) ['DB' => $database];
    }

    public function wordpress_request(string $endpoint, $postdata = null, $method = null, $getdata = null, ?string $content_disposition = null, ?string $content_type = null, $debug = false, $debugurl = null, bool $ssl_ignore = false, int $timeout_seconds = 30)
    {
        $this->requests[] = [$endpoint, $ssl_ignore];
        return $this->result;
    }
}

function deliveryTimeConnector(bool $withCredentials): DeliveryTimeConnector
{
    $database = new class {
        public array $settings = [
            'ImportWoocommerceApiUrl' => 'https://shop.example/',
            'filenamesmarty' => '',
            'productsmarty' => '',
            'metadatasmarty' => '',
            'fileunique' => '',
            'timeout' => '30',
            'backorders' => 'no',
            'deliveryTimeMapping' => "sofort lieferbar=61\r\nca. 48 Stunden=63",
        ];

        public function Select($sql)
        {
            return json_encode(['felder' => $this->settings], JSON_THROW_ON_ERROR);
        }

        public function SelectRow($sql)
        {
            return ['dateienuebertragen' => '', 'kategorienuebertragen' => ''];
        }
    };
    if ($withCredentials) {
        $database->settings['ImportWordpressUserName'] = 'fixture-user';
        $database->settings['ImportWordpressApplicationPassword'] = 'fixture-password';
    }
    $connector = new DeliveryTimeConnector($database);
    $connector->getKonfig(1, []);
    return $connector;
}

runTests([
    'maps_known_terms' => static function () {
        $map = new DeliveryTimeMap(static fn () => [61 => 'sofort lieferbar', 65 => 'ca. 3-4 Werktage'], '');
        same(65, $map->idFor('ca. 3-4 Werktage'));
        same('sofort lieferbar', $map->textFor(61));
    },
    'empty_is_shop_default' => static function () {
        $map = new DeliveryTimeMap(static fn () => [61 => 'sofort lieferbar'], '');
        same(-1, $map->idFor(''));
        same(-1, $map->idFor(" \t "));
        same('', $map->textFor(-1));
    },
    'unknown_text_is_null' => static function () {
        $map = new DeliveryTimeMap(static fn () => [61 => 'sofort lieferbar'], '');
        same(null, $map->idFor('ca. 30 Werktage'));
        same('', $map->textFor(68));
    },
    'fallback_when_fetch_fails' => static function () {
        $map = new DeliveryTimeMap(static function () {
            throw new RuntimeException('WordPress unavailable');
        }, "sofort lieferbar=61\nca. 48 Stunden=63");
        same(63, $map->idFor('ca. 48 Stunden'));
        same('sofort lieferbar', $map->textFor(61));
    },
    'matching_ignores_case_and_surrounding_space' => static function () {
        $map = new DeliveryTimeMap(static fn () => [64 => 'ca. 2-3 Werktage', 69 => "Nicht verf\u{00FC}gbar"], '');
        same(64, $map->idFor(" \tCA. 2-3 WERKTAGE  "));
        same(69, $map->idFor(" NICHT VERF\u{00DC}GBAR "));
    },
    'fallback_ignores_invalid_rows_and_accepts_crlf' => static function () {
        $map = new DeliveryTimeMap(static function () {
            throw new RuntimeException('WordPress unavailable');
        }, "ca. 24 Stunden = 62\r\nca. 48 Stunden=63x\r\n=-1\r\nca. 30 Werktage=0\r\nmissing separator");
        same(62, $map->idFor('ca. 24 Stunden'));
        same(null, $map->idFor('ca. 48 Stunden'));
        same(null, $map->idFor('ca. 30 Werktage'));
    },
    'connector_declares_the_fallback_textarea_after_backorders' => static function () {
        $fields = deliveryTimeConnector(false)->EinstellungenStruktur()['felder'];
        $keys = array_keys($fields);
        same('deliveryTimeMapping', $keys[array_search('backorders', $keys, true) + 1]);
        same('textarea', $fields['deliveryTimeMapping']['typ']);
        same('{|Lieferzeit-Zuordnung (Text=ID, nur falls WordPress-API nicht erreichbar)|}', $fields['deliveryTimeMapping']['bezeichnung']);
    },
    'connector_maps_rest_terms_and_caches_the_result' => static function () {
        $connector = deliveryTimeConnector(true);
        $connector->ssl_ignore = true;
        $connector->result = ['status' => true, 'response' => [
            ['id' => 61, 'name' => 'sofort lieferbar'],
            ['id' => 62, 'name' => 'ca. 24 Stunden'],
            ['id' => 64, 'name' => 'ca. 2-3 Werktage'],
            ['id' => 65, 'name' => 'ca. 3&#8211;4 Werktage'],
            ['id' => 66, 'name' => 'ca. 10 Werktage'],
            ['id' => 67, 'name' => 'ca. 14 Werktage'],
            ['id' => 68, 'name' => 'ca. 30 Werktage'],
            ['id' => 69, 'name' => 'derzeit nicht lieferbar'],
            ['id' => 70, 'name' => 'keine Lieferzeit (z.B. Download)'],
        ], 'message' => '', 'http_code' => 200];
        $map = $connector->deliveryTimeMap();
        same(65, $map->idFor("ca. 3\u{2013}4 Werktage"));
        same("ca. 3\u{2013}4 Werktage", $map->textFor(65));
        same(70, $map->idFor('keine Lieferzeit (z.B. Download)'));
        same(null, $map->idFor('ca. 48 Stunden'));
        same($map, $connector->deliveryTimeMap());
        same([['product_delivery_times?per_page=100', true]], $connector->requests);
    },
    'connector_uses_fallback_without_wordpress_credentials' => static function () {
        $connector = deliveryTimeConnector(false);
        same(63, $connector->deliveryTimeMap()->idFor('ca. 48 Stunden'));
        same(61, $connector->deliveryTimeMap()->idFor('sofort lieferbar'));
        same([], $connector->requests);
    },
    'connector_uses_fallback_for_failed_or_malformed_rest_response' => static function () {
        foreach ([
            ['status' => false, 'response' => ['code' => 'rest_no_route'], 'message' => 'Not found', 'http_code' => 404],
            ['status' => true, 'response' => ['unexpected' => 'object'], 'message' => '', 'http_code' => 200],
        ] as $result) {
            $connector = deliveryTimeConnector(true);
            $connector->result = $result;
            same(63, $connector->deliveryTimeMap()->idFor('ca. 48 Stunden'));
            same(63, $connector->deliveryTimeMap()->idFor('ca. 48 Stunden'));
            same([['product_delivery_times?per_page=100', false]], $connector->requests);
        }
    },
]);
