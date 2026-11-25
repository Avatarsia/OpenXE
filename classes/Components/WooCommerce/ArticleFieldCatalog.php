<?php
// SPDX-License-Identifier: AGPL-3.0-only

namespace Xentral\Components\WooCommerce;

use InvalidArgumentException;

final class ArticleFieldCatalog
{
    private const FIELDS = [
        'sku' => [
            'label' => 'Shop-SKU (Fremdnummer)', 'group' => 'Identitaet', 'storage' => 'foreign_number',
            'wc' => 'sku', 'pull' => false, 'edit' => false, 'csv' => null,
        ],
        'shop_name' => [
            'label' => 'Shop-Name', 'group' => 'Texte', 'storage' => 'artikel:shop_name_de',
            'wc' => 'name', 'pull' => true, 'edit' => true, 'csv' => 'shop_name_de',
        ],
        'description' => [
            'label' => 'Shop-Beschreibung', 'group' => 'Texte', 'storage' => 'artikel:uebersicht_de',
            'wc' => 'description', 'pull' => true, 'edit' => true, 'csv' => 'uebersicht_de',
        ],
        'short_description' => [
            'label' => 'Shop-Kurzbeschreibung', 'group' => 'Texte', 'storage' => 'artikel:kurztext_de',
            'wc' => 'short_description', 'pull' => true, 'edit' => true, 'csv' => 'kurztext_de',
        ],
        'seo_title' => [
            'label' => 'SEO-Titel', 'group' => 'SEO', 'storage' => 'artikel:metatitle_de',
            'wc' => 'meta:rank_math_title', 'pull' => true, 'edit' => true, 'csv' => 'metatitle_de',
        ],
        'seo_description' => [
            'label' => 'SEO-Beschreibung', 'group' => 'SEO', 'storage' => 'artikel:metadescription_de',
            'wc' => 'meta:rank_math_description', 'pull' => true, 'edit' => true, 'csv' => 'metadescription_de',
        ],
        'seo_keyword' => [
            'label' => 'Fokus-Keyword', 'group' => 'SEO', 'storage' => 'artikel:metakeywords_de',
            'wc' => 'meta:rank_math_focus_keyword', 'pull' => true, 'edit' => true, 'csv' => 'metakeywords_de',
        ],
        'price_net' => [
            'label' => 'Verkaufspreis netto', 'group' => 'Preis', 'storage' => 'price',
            'wc' => 'regular_price / sale_price', 'pull' => true, 'edit' => true, 'csv' => 'verkaufspreis1netto',
        ],
        'pseudo_price' => [
            'label' => 'Streichpreis (Pseudopreis/UVP), netto', 'group' => 'Preis', 'storage' => 'shop:pseudopreis',
            'wc' => 'regular_price', 'pull' => true, 'edit' => true, 'csv' => 'pseudopreis',
        ],
        'inactive' => [
            'label' => 'Artikel inaktiv', 'group' => 'Status', 'storage' => 'artikel:inaktiv',
            'wc' => 'status', 'pull' => true, 'edit' => true, 'csv' => 'inaktiv',
        ],
        'stock_sync' => [
            'label' => 'Lagerzahlen Sync.', 'group' => 'Lager-Steuerung', 'storage' => 'shop:autolagerlampe',
            'wc' => 'manage_stock', 'pull' => false, 'edit' => true, 'csv' => 'autolagerlampe',
        ],
        'pseudo_stock' => [
            'label' => 'Pseudo-Lagerzahl', 'group' => 'Lager-Steuerung', 'storage' => 'shop:pseudolager',
            'wc' => 'stock_quantity', 'pull' => false, 'edit' => true, 'csv' => 'pseudolager',
        ],
        'rest_quantity' => [
            'label' => 'Restmenge (Abverkauf)', 'group' => 'Lager-Steuerung', 'storage' => 'shop:restmenge',
            'wc' => 'backorders', 'pull' => false, 'edit' => true, 'csv' => 'restmenge',
        ],
        'sold_out' => [
            'label' => 'nicht mehr lieferbar', 'group' => 'Lager-Steuerung', 'storage' => 'artikel:ausverkauft',
            'wc' => 'stock_quantity', 'pull' => false, 'edit' => true, 'csv' => 'ausverkauft',
        ],
        'on_request' => [
            'label' => 'auf Nachfrage lieferbar', 'group' => 'Lieferstatus', 'storage' => 'onrequest',
            'wc' => 'meta:openxe_on_request', 'pull' => false, 'edit' => true, 'csv' => null,
        ],
        'delivery_time' => [
            'label' => 'Lieferzeit', 'group' => 'Lieferung', 'storage' => 'shop:lieferzeitmanuell',
            'wc' => 'meta:_lieferzeit', 'pull' => true, 'edit' => true, 'csv' => 'lieferzeitmanuell',
        ],
        'weight' => [
            'label' => 'Gewicht', 'group' => 'Logistik', 'storage' => 'artikel:gewicht',
            'wc' => 'weight', 'pull' => true, 'edit' => true, 'csv' => 'gewicht',
        ],
        'length' => [
            'label' => 'Laenge', 'group' => 'Logistik', 'storage' => 'artikel:laenge',
            'wc' => 'dimensions.length', 'pull' => true, 'edit' => true, 'csv' => 'laenge',
        ],
        'width' => [
            'label' => 'Breite', 'group' => 'Logistik', 'storage' => 'artikel:breite',
            'wc' => 'dimensions.width', 'pull' => true, 'edit' => true, 'csv' => 'breite',
        ],
        'height' => [
            'label' => 'Hoehe', 'group' => 'Logistik', 'storage' => 'artikel:hoehe',
            'wc' => 'dimensions.height', 'pull' => true, 'edit' => true, 'csv' => 'hoehe',
        ],
        'ean' => [
            'label' => 'EAN / GTIN', 'group' => 'Logistik', 'storage' => 'artikel:ean',
            'wc' => 'global_unique_id', 'pull' => true, 'edit' => true, 'csv' => 'ean',
        ],
        'customs_tariff' => [
            'label' => 'Zolltarifnummer', 'group' => 'Zoll', 'storage' => 'artikel:zolltarifnummer',
            'wc' => 'meta:_dhl_hs_code', 'pull' => true, 'edit' => true, 'csv' => 'zolltarifnummer',
        ],
        'origin_country' => [
            'label' => 'Herkunftsland', 'group' => 'Zoll', 'storage' => 'artikel:herkunftsland',
            'wc' => 'meta:_dhl_manufacture_country', 'pull' => true, 'edit' => true, 'csv' => 'herkunftsland',
        ],
        'manufacturer' => [
            'label' => 'Hersteller / GPSR-Herstellerangabe', 'group' => 'Hersteller', 'storage' => 'artikel:hersteller',
            'wc' => 'meta:_german_market_gpsr_manufacturer', 'pull' => true, 'edit' => true, 'csv' => 'hersteller',
        ],
    ];

    public static function fields(): array
    {
        $fields = [];
        foreach (self::FIELDS as $key => $field) {
            $fields[] = ['key' => $key] + $field;
        }
        return $fields;
    }

    public static function field(string $key): array
    {
        if (!isset(self::FIELDS[$key])) {
            throw new InvalidArgumentException('Unknown WooCommerce article field: ' . $key);
        }
        return ['key' => $key] + self::FIELDS[$key];
    }

    public static function csvColumns(): array
    {
        $columns = [['export' => 'nummer', 'import' => 'nummer']];
        foreach (self::FIELDS as $field) {
            $column = $field['csv'];
            if ($column === null) {
                continue;
            }
            if ($field['storage'] === 'price') {
                $columns[] = [
                    'export' => '(SELECT v.preis FROM verkaufspreise AS v'
                        . " WHERE v.artikel = art.id AND v.ab_menge = 1 AND v.geloescht = 0 AND v.art != 'Gruppe'"
                        . " AND (v.objekt = 'Standard' OR v.objekt = '') AND (v.adresse = '0' OR v.adresse = '')"
                        . " AND (v.gueltig_bis >= NOW() OR v.gueltig_bis = '0000-00-00')"
                        . ' ORDER BY v.preis DESC LIMIT 1) AS standardpreisnetto',
                    'import' => $column,
                ];
                $columns[] = ['export' => '1 AS standardpreismenge', 'import' => 'verkaufspreis1menge'];
                continue;
            }
            $columns[] = ['export' => $column, 'import' => $column];
        }
        return $columns;
    }
}
