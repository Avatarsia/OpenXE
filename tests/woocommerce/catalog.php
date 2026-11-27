<?php
// SPDX-License-Identifier: AGPL-3.0-only

require __DIR__ . '/bootstrap.php';

use Xentral\Components\WooCommerce\ArticleFieldCatalog;

runTests([
    'catalog_keys_and_storage' => static function () {
        same([
            'sku', 'shop_name', 'description', 'short_description', 'seo_title', 'seo_description',
            'seo_keyword', 'price_net', 'pseudo_price', 'inactive', 'stock_sync', 'pseudo_stock',
            'rest_quantity', 'sold_out', 'on_request', 'delivery_time', 'weight', 'length', 'width',
            'height', 'ean', 'customs_tariff', 'origin_country', 'manufacturer',
        ], array_column(ArticleFieldCatalog::fields(), 'key'));

        foreach ([
            'shop_name' => 'artikel:shop_name_de',
            'description' => 'artikel:uebersicht_de',
            'short_description' => 'artikel:kurztext_de',
            'pseudo_price' => 'shop:pseudopreis',
            'stock_sync' => 'shop:autolagerlampe',
            'pseudo_stock' => 'shop:pseudolager',
            'rest_quantity' => 'shop:restmenge',
            'sold_out' => 'artikel:ausverkauft',
            'on_request' => 'onrequest',
            'delivery_time' => 'shop:lieferzeitmanuell',
        ] as $key => $storage) {
            same($storage, ArticleFieldCatalog::field($key)['storage'], $key);
        }

        foreach ([
            'seo_title' => 'meta:rank_math_title',
            'seo_description' => 'meta:rank_math_description',
            'seo_keyword' => 'meta:rank_math_focus_keyword',
            'on_request' => 'meta:openxe_on_request',
            'delivery_time' => 'meta:_lieferzeit',
            'customs_tariff' => 'meta:_dhl_hs_code',
            'origin_country' => 'meta:_dhl_manufacture_country',
            'manufacturer' => 'meta:_german_market_gpsr_manufacturer',
        ] as $key => $target) {
            same($target, ArticleFieldCatalog::field($key)['wc'], $key);
        }

        same('nicht mehr lieferbar', ArticleFieldCatalog::field('sold_out')['label']);
        $onRequest = ArticleFieldCatalog::field('on_request');
        same('auf Nachfrage lieferbar', $onRequest['label']);
        same('Lieferstatus', $onRequest['group']);
        same(false, ArticleFieldCatalog::field('sku')['edit']);
        foreach (['sku', 'stock_sync', 'pseudo_stock', 'rest_quantity', 'sold_out', 'on_request'] as $key) {
            same(false, ArticleFieldCatalog::field($key)['pull'], $key);
        }
    },
    'catalog_rejects_unknown_field' => static function () {
        try {
            ArticleFieldCatalog::field('unknown');
        } catch (InvalidArgumentException $error) {
            return;
        }
        throw new RuntimeException('Unknown field accepted');
    },
    'csv_columns_start_with_number' => static function () {
        $columns = ArticleFieldCatalog::csvColumns();
        same(['export' => 'nummer', 'import' => 'nummer'], $columns[0]);
        $exports = array_column($columns, 'export', 'import');
        foreach (['sku', 'on_request'] as $key) {
            same(null, ArticleFieldCatalog::field($key)['csv'], $key);
            check(!array_key_exists($key, $exports), $key . ' is included in the CSV');
        }

        same(['woo_preis_netto'], array_values(array_filter(array_keys($exports),
            static fn ($column) => str_starts_with($column, 'verkaufspreis') || str_starts_with($column, 'woo_preis'))));

        $price = $exports['woo_preis_netto'];
        foreach ([
            'FROM verkaufspreise AS v',
            'v.artikel = art.id',
            'v.ab_menge = 1',
            'v.geloescht = 0',
            "v.art != 'Gruppe'",
            "(v.objekt = 'Standard' OR v.objekt = '')",
            "(v.adresse = '0' OR v.adresse = '')",
            "(v.gueltig_bis >= NOW() OR v.gueltig_bis = '0000-00-00')",
            'ORDER BY v.preis DESC LIMIT 1) AS standardpreisnetto',
        ] as $clause) {
            check(str_contains($price, $clause), 'Missing price clause ' . $clause);
        }
        foreach ($exports as $expression) {
            check(!str_contains($expression, ';'), 'Export template separator inside ' . $expression);
        }
    },
]);
