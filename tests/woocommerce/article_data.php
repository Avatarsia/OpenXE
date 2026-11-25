<?php
// SPDX-License-Identifier: AGPL-3.0-only
require __DIR__ . '/bootstrap.php';

use Xentral\Components\WooCommerce\ArticleDataRepository;
use Xentral\Components\WooCommerce\DeliveryTimeMap;
use Xentral\Components\WooCommerce\FieldDiff;
use Xentral\Components\WooCommerce\WooProductReader;

final class ArticleDataDatabase
{
    public array $article = ['id' => 9, 'nummer' => 'LOCAL-9', 'pseudopreis' => '10.00'];
    public array $override = ['id' => 18, 'aktiv' => 1, 'ausartikel' => 0, 'pseudopreis' => '12.00'];
    public array $config = [];
    public array $prices = [];
    public int $group = 0;
    public bool $failLog = false;
    private ?array $snapshot = null;

    public function inTransaction(): bool { return $this->snapshot !== null; }
    public function beginTransaction(): void
    {
        $this->snapshot = [$this->article, $this->override, $this->config, $this->prices];
    }
    public function commit(): void { $this->snapshot = null; }
    public function rollBack(): void
    {
        [$this->article, $this->override, $this->config, $this->prices] = $this->snapshot;
        $this->snapshot = null;
    }

    public function fetchRow(string $sql, array $parameters)
    {
        if (str_contains($sql, 'FROM artikel WHERE')) {
            return $parameters['article'] === 9 ? $this->article : false;
        }
        if (str_contains($sql, 'FROM artikel_onlineshops')) {
            check(str_contains($sql, 'aktiv = 1') && str_contains($sql, 'ausartikel = 0'));
            return $parameters === ['article' => 9, 'shop' => 5]
                && $this->override['aktiv'] === 1 && $this->override['ausartikel'] === 0 ? $this->override : false;
        }
        if (str_contains($sql, 'FROM verkaufspreise')) {
            check(str_contains($sql, 'ab_menge = 1') && str_contains($sql, 'ORDER BY v.preis DESC'));
            $active = array_filter($this->prices, static fn (array $price): bool => !$price['expired']);
            usort($active, static fn (array $left, array $right): int => $right['preis'] <=> $left['preis']);
            return $active[0] ?? false;
        }
        throw new RuntimeException('Unexpected row query: ' . $sql);
    }

    public function fetchValue(string $sql, array $parameters)
    {
        if (str_contains($sql, 'FROM shopexport')) {
            same(['shop' => 5], $parameters);
            return $this->group;
        }
        if (str_contains($sql, 'FROM artikelnummer_fremdnummern')) {
            same(['article' => 9, 'shop' => 5], $parameters);
            return false;
        }
        if (str_contains($sql, 'FROM konfiguration')) {
            return $this->config[$parameters['name']] ?? false;
        }
        throw new RuntimeException('Unexpected value query: ' . $sql);
    }

    public function perform(string $sql, array $parameters): void
    {
        if (preg_match('/^UPDATE (artikel|artikel_onlineshops) SET `([a-z_]+)` = :value/', $sql, $match)) {
            if ($match[1] === 'artikel') {
                same(9, $parameters['article']);
                $this->article[$match[2]] = $parameters['value'];
            } else {
                same(18, $parameters['id']);
                $this->override[$match[2]] = $parameters['value'];
            }
        } elseif (str_starts_with($sql, 'UPDATE verkaufspreise')) {
            check(str_contains($sql, 'DATE_SUB'));
            $this->prices[$parameters['id']]['expired'] = true;
        } elseif (str_starts_with($sql, 'INSERT INTO verkaufspreise')) {
            $id = count($this->prices) + 1;
            $this->prices[$id] = ['id' => $id, 'preis' => $parameters['value'], 'waehrung' => $parameters['currency'], 'expired' => false];
        } elseif (str_starts_with($sql, 'INSERT INTO konfiguration')) {
            if (!str_contains($sql, 'wert = VALUES(wert)')) {
                $this->config[$parameters['name']] ??= '[]';
                return;
            }
            if ($this->failLog && $parameters['name'] === 'woocommerce_pull_log_5') {
                throw new RuntimeException('Log write failed.');
            }
            $this->config[$parameters['name']] = $parameters['wert'];
        } else {
            throw new RuntimeException('Unexpected statement: ' . $sql);
        }
    }
}

function articleRepository(ArticleDataDatabase $db): ArticleDataRepository
{
    $app = (object) [
        'Container' => new class ($db) {
            public function __construct(private ArticleDataDatabase $database) {}
            public function get(string $service): ArticleDataDatabase
            {
                same('Database', $service);
                return $this->database;
            }
        },
        'erp' => new class {
            public function GetVerkaufspreisGruppe(int $article, int $quantity, int $group): string
            {
                same([9, 1, 7], [$article, $quantity, $group]);
                return '24.00';
            }
        },
        'User' => new class {
            public function GetID(): int { return 42; }
        },
        'Secure' => new class {
            public function CleanString(string $value, string $rule, int $sqlcheckoff): string
            {
                same(['nojs', 1], [$rule, $sqlcheckoff]);
                return $value;
            }
        },
    ];
    return new ArticleDataRepository($app, 5);
}

function wooReader(array $product): WooProductReader
{
    $client = new class ($product) {
        public function __construct(private array $product) {}
        public function get(string $endpoint, array $query): array
        {
            same('products', $endpoint);
            same('ABC', $query['sku']);
            return [(object) ['id' => 3, 'sku' => 'OTHER'], (object) ($this->product + ['id' => 4, 'sku' => 'ABC'])];
        }
    };
    return new WooProductReader($client, new DeliveryTimeMap(static fn () => [13 => '2-3 Werktage'], ''));
}

function articleDiff(array $openxe, array $woo, string $key): array
{
    return array_column(FieldDiff::compare($openxe, $woo), null, 'key')[$key];
}

runTests([
    'load_prefers_shop_override' => static function () {
        $db = new ArticleDataDatabase();
        $repo = articleRepository($db);
        same(['value' => '12.00', 'source' => 'shop'], $repo->load(9)['pseudo_price']);
        $db->override['ausartikel'] = 1;
        same(['value' => '10.00', 'source' => 'artikel'], $repo->load(9)['pseudo_price']);
    },
    'load_uses_group_price_when_shop_has_group' => static function () {
        $db = new ArticleDataDatabase();
        $db->group = 7;
        $repo = articleRepository($db);
        same(['value' => '24.00', 'source' => 'group_price'], $repo->load(9)['price_net']);
        try {
            $repo->apply(9, ['shop_name' => 'Changed', 'price_net' => '20'], 'pull');
            throw new LogicException('Group price change was accepted.');
        } catch (RuntimeException $error) {
            check(str_contains($error->getMessage(), 'Gruppenpreis'));
        }
        check(!isset($db->article['shop_name_de']));
        same([], $db->config);
    },
    'apply_writes_article_and_shop_override_identically' => static function () {
        $db = new ArticleDataDatabase();
        $repo = articleRepository($db);
        same(['pseudo_price' => ['old' => '12.00', 'new' => '15']], $repo->apply(9, ['pseudo_price' => '15'], 'pull'));
        same('15', $db->article['pseudopreis']);
        same('15', $db->override['pseudopreis']);
        same(['value' => '15', 'source' => 'shop'], $repo->load(9)['pseudo_price']);
    },
    'apply_syncs_base_even_when_override_matches' => static function () {
        $db = new ArticleDataDatabase();
        $repo = articleRepository($db);
        same(['pseudo_price' => ['old' => '10.00', 'new' => '12']], $repo->apply(9, ['pseudo_price' => '12'], 'csv'));
        same('12', $db->article['pseudopreis']);
    },
    'apply_logs_changes' => static function () {
        $db = new ArticleDataDatabase();
        $db->config['woocommerce_pull_log_5'] = json_encode(array_fill(0, 500, ['field' => 'historical']));
        articleRepository($db)->apply(9, ['pseudo_price' => '15'], 'pull');
        $log = json_decode($db->config['woocommerce_pull_log_5'], true);
        same(500, count($log));
        $entry = $log[499];
        check((bool) preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $entry['time']));
        unset($entry['time']);
        same(['user' => 42, 'article' => 9, 'field' => 'pseudo_price', 'old' => '12.00', 'new' => '15', 'origin' => 'pull'], $entry);
    },
    'apply_ignores_sku_and_reports_only_changes' => static function () {
        $db = new ArticleDataDatabase();
        $db->article['pseudopreis'] = '12.0';
        $repo = articleRepository($db);
        same([], $repo->apply(9, ['sku' => 'REPLACE', 'pseudo_price' => '12,00'], 'pull'));
        same(['value' => 'LOCAL-9', 'source' => 'foreign_number'], $repo->load(9)['sku']);
        same([], $db->config);
    },
    'description_roundtrip_is_single_encoded' => static function () {
        $db = new ArticleDataDatabase();
        $repo = articleRepository($db);
        $woo = ['description' => '<p>Ger&auml;t &amp; Teil</p>'];
        $db->article['uebersicht_de'] = '&lt;p&gt;Ger&amp;auml;t &amp;amp; Teil&lt;/p&gt;';
        same(false, articleDiff($repo->load(9), $woo, 'description')['differs']);
        $db->article['uebersicht_de'] = '<p>Old</p>';
        $repo->apply(9, $woo, 'pull');
        same($woo['description'], $db->article['uebersicht_de']);
        same(false, articleDiff($repo->load(9), $woo, 'description')['differs']);
    },
    'description_distinguishes_markup_from_escaped_text' => static function () {
        $openxe = ['description' => ['value' => '<p>&lt;strong&gt;Text&lt;/strong&gt;</p>']];
        $woo = ['description' => '<p><strong>Text</strong></p>'];
        same(true, articleDiff($openxe, $woo, 'description')['differs']);

        $db = new ArticleDataDatabase();
        $woo = ['description' => '&lt;strong&gt;Text&lt;/strong&gt;'];
        articleRepository($db)->apply(9, $woo, 'pull');
        same($woo['description'], $db->article['uebersicht_de']);
    },
    'numeric_values_normalize' => static function () {
        $openxe = ['price_net' => ['value' => '12,50'], 'weight' => ['value' => ''], 'sku' => ['value' => 'A']];
        $woo = ['price_net' => '12.5', 'weight' => '0', 'sku' => 'B'];
        $rows = array_column(FieldDiff::compare($openxe, $woo), null, 'key');
        same(false, $rows['price_net']['differs']);
        same(false, $rows['weight']['differs']);
        check(!isset($rows['sku']));
    },
    'standard_price_replaces_export_row_with_history' => static function () {
        $db = new ArticleDataDatabase();
        $db->prices[1] = ['id' => 1, 'preis' => '30.00', 'waehrung' => 'CHF', 'expired' => false];
        $repo = articleRepository($db);
        same(['price_net' => ['old' => '30.00', 'new' => '20.5']], $repo->apply(9, ['price_net' => '20,50'], 'pull'));
        same(true, $db->prices[1]['expired']);
        same('CHF', $db->prices[2]['waehrung']);
        same(['value' => '20.5', 'source' => 'price'], $repo->load(9)['price_net']);
        $repo->apply(9, ['price_net' => ''], 'pull');
        same(['value' => '', 'source' => 'price'], $repo->load(9)['price_net']);
    },
    'failed_log_rolls_back_article_and_override' => static function () {
        $db = new ArticleDataDatabase();
        $db->failLog = true;
        try {
            articleRepository($db)->apply(9, ['pseudo_price' => '15'], 'pull');
            throw new LogicException('Log failure did not propagate.');
        } catch (RuntimeException $error) {
            same('Log write failed.', $error->getMessage());
        }
        same('10.00', $db->article['pseudopreis']);
        same('12.00', $db->override['pseudopreis']);
        same([], $db->config);
    },
    'on_request_preserves_other_article_config' => static function () {
        $db = new ArticleDataDatabase();
        $db->config['woocommerce_article_5_9'] = '{"categories":[2],"onRequest":false}';
        $repo = articleRepository($db);
        same(['value' => '0', 'source' => 'onrequest'], $repo->load(9)['on_request']);
        $repo->apply(9, ['on_request' => '1'], 'tab');
        same(['categories' => [2], 'onRequest' => true], json_decode($db->config['woocommerce_article_5_9'], true));
        same(['value' => '1', 'source' => 'onrequest'], $repo->load(9)['on_request']);
    },
    'variation_sku_maps_to_variant_article' => static function () {
        $client = new class {
            private string $pages = '1';
            public function get(string $endpoint, array $query): array
            {
                same(100, $query['per_page']);
                if ($endpoint === 'products') {
                    $this->pages = '1';
                    return [
                        (object) ['id' => 100, 'sku' => 'PARENT', 'type' => 'variable'],
                        (object) ['id' => 103, 'name' => 'No SKU'],
                        (object) ['id' => 104, 'sku' => 'DUP'],
                        (object) ['id' => 105, 'sku' => 'DUP'],
                    ];
                }
                same('products/100/variations', $endpoint);
                $this->pages = '2';
                return [(object) ['id' => 100 + $query['page'], 'sku' => 'VAR-' . $query['page'], 'regular_price' => '29']];
            }
            public function getLastResponse(): object
            {
                return new class ($this->pages) {
                    public function __construct(private string $pages) {}
                    public function getHeader(string $name): string
                    {
                        same('X-WP-TotalPages', $name);
                        return $this->pages;
                    }
                };
            }
        };
        $page = (new WooProductReader($client, new DeliveryTimeMap(static fn () => [], '')))->page(1);
        same(['PARENT', 'VAR-1', 'VAR-2'], array_keys($page['items']));
        same(100, $page['items']['VAR-2']['parent_id']);
        same(102, $page['items']['VAR-2']['id']);
        same('29', $page['items']['VAR-2']['values']['price_net']);
        same(['keine SKU', 'SKU mehrfach', 'SKU mehrfach'], array_column($page['unmatched'], 'reason'));
        same(false, $page['more']);
    },
    'sale_price_maps_to_pseudo_price' => static function () {
        $values = wooReader(['regular_price' => '16', 'sale_price' => '0'])->findBySku('ABC')['values'];
        same('0', $values['price_net']);
        same('16', $values['pseudo_price']);
        $values = wooReader(['regular_price' => '16', 'sale_price' => ''])->findBySku('ABC')['values'];
        same('16', $values['price_net']);
        same('', $values['pseudo_price']);
    },
    'delivery_term_maps_to_text' => static function () {
        $metadata = [(object) ['key' => '_lieferzeit', 'value' => 13]];
        same('2-3 Werktage', wooReader(['meta_data' => $metadata])->findBySku('ABC')['values']['delivery_time']);
        $metadata[0]->value = -1;
        same('', wooReader(['meta_data' => $metadata])->findBySku('ABC')['values']['delivery_time']);
    },
    'ean_uses_global_then_german_market_then_rank_math' => static function () {
        $metadata = [
            ['key' => '_rank_math_gtin_code', 'value' => '0003'],
            ['key' => '_gm_gtin', 'value' => '0002'],
        ];
        foreach (['0001', ' ', null] as $global) {
            $reader = wooReader(['global_unique_id' => $global, 'meta_data' => $metadata]);
            same($global === '0001' ? $global : '0002', $reader->findBySku('ABC')['values']['ean']);
        }
        same('0003', wooReader(['meta_data' => [$metadata[0]]])->findBySku('ABC')['values']['ean']);
    },
]);
