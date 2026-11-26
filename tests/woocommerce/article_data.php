<?php
// SPDX-License-Identifier: AGPL-3.0-only
require __DIR__ . '/bootstrap.php';
require dirname(__DIR__, 2) . '/www/pages/shopexport.php';

use Xentral\Components\WooCommerce\ArticleDataRepository;
use Xentral\Components\WooCommerce\DeliveryTimeMap;
use Xentral\Components\WooCommerce\FieldDiff;
use Xentral\Components\WooCommerce\ShopCategoryStore;
use Xentral\Components\WooCommerce\WooProductReader;

final class ArticleDataDatabase
{
    public array $article = ['id' => 9, 'nummer' => 'LOCAL-9', 'pseudopreis' => '10.00'];
    public array $override = ['id' => 18, 'aktiv' => 1, 'ausartikel' => 0, 'pseudopreis' => '12.00'];
    public array $config = [];
    public array $exportRows = [];

    public function SelectRow(string $sql): array
    {
        if (preg_match('/FROM artikel WHERE id = ([0-9]+)/', $sql, $match)) {
            return (int)$match[1] === 9 ? $this->article : ['id' => (int)$match[1]];
        }
        if (preg_match('/FROM artikel_onlineshops WHERE artikel = ([0-9]+) AND shop = ([0-9]+)/', $sql, $match)) {
            return $this->exportRows[(int)$match[2]][(int)$match[1]] ?? [];
        }
        throw new LogicException($sql);
    }
    public function SelectArr(string $sql): array { return []; }
    public array $prices = [];
    public int $group = 0;
    public bool $groupActive = true;
    public int $groupPriceCalls = 0;
    public int $writes = 0;
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
        throw new RuntimeException('Unexpected row query: ' . $sql);
    }

    public function fetchAll(string $sql, array $parameters): array
    {
        if (str_contains($sql, 'FROM verkaufspreise')) {
            same(['article' => 9], $parameters);
            check(str_contains($sql, "v.ab_menge = 1 AND v.geloescht = 0 AND v.art != 'Gruppe'"));
            check(str_contains($sql, "(v.objekt = 'Standard' OR v.objekt = '') AND (v.adresse = '0' OR v.adresse = '')"));
            check(str_contains($sql, "(v.gueltig_bis >= NOW() OR v.gueltig_bis = '0000-00-00')"));
            check(str_contains($sql, 'ORDER BY v.preis DESC LIMIT 2'));
            $active = array_filter($this->prices, static fn (array $price): bool =>
                !$price['expired'] && ($price['geloescht'] ?? 0) === 0
                && ($price['art'] ?? 'Kunde') !== 'Gruppe'
                && in_array($price['objekt'] ?? 'Standard', ['Standard', ''], true)
                && in_array($price['adresse'] ?? '0', ['0', ''], true)
                && ($price['ab_menge'] ?? '1') == 1
            );
            usort($active, static fn (array $left, array $right): int => $right['preis'] <=> $left['preis']);
            return array_slice($active, 0, 2);
        }
        throw new RuntimeException('Unexpected rows query: ' . $sql);
    }

    public function fetchValue(string $sql, array $parameters)
    {
        if (str_contains($sql, 'FROM shopexport')) {
            same(['shop' => 5], $parameters);
            check(str_contains($sql, 'INNER JOIN gruppen g ON s.preisgruppe = g.id AND g.aktiv = 1'));
            return $this->groupActive ? $this->group : false;
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
        $this->writes++;
        if (preg_match('/^UPDATE (artikel|artikel_onlineshops) SET `([a-z_]+)` = :value/', $sql, $match)) {
            if ($match[1] === 'artikel') {
                same(9, $parameters['article']);
                $this->article[$match[2]] = $parameters['value'];
            } else {
                same(18, $parameters['id']);
                $this->override[$match[2]] = $parameters['value'];
            }
        } elseif (str_starts_with($sql, 'UPDATE artikel_onlineshops SET last_article_hash')) {
            foreach ($this->exportRows as $shop => &$rows) {
                if (isset($parameters['shop']) && $parameters['shop'] !== $shop) {
                    continue;
                }
                foreach ([$parameters['article'], $this->article['variante_von'] ?? 0] as $id) {
                    if (isset($rows[$id])) {
                        $rows[$id]['last_article_hash'] = '';
                    }
                }
            }
            unset($rows);
        } elseif (str_starts_with($sql, 'UPDATE verkaufspreise')) {
            check(str_contains($sql, 'DATE_SUB'));
            $this->prices[$parameters['id']]['expired'] = true;
        } elseif (str_starts_with($sql, 'INSERT INTO verkaufspreise')) {
            $id = count($this->prices) + 1;
            if (str_contains($sql, 'FROM verkaufspreise WHERE id = :id')) {
                check(str_contains($sql, 'SELECT artikel, objekt, projekt, adresse, :value, waehrung, ab_menge, vpe, vpe_menge'));
                check(str_contains($sql, 'bemerkung, bearbeiter, NOW(), firma, geloescht, kundenartikelnummer, art, gruppe, 1'));
                $this->prices[$id] = array_replace($this->prices[$parameters['id']],
                    ['id' => $id, 'preis' => $parameters['value'], 'expired' => false]);
            } else {
                same(9, $parameters['article']);
                check(str_contains($sql, "VALUES (:article, 'Standard', '', '0', :value, 'EUR', 1, CURDATE(),"));
                check(str_contains($sql, "1, 0, 'Kunde', NULL, 1)"));
                $this->prices[$id] = ['id' => $id, 'preis' => $parameters['value'], 'waehrung' => 'EUR',
                    'expired' => false, 'gruppe' => null, 'ab_menge' => '1', 'objekt' => 'Standard', 'adresse' => '0',
                    'art' => 'Kunde'];
            }
        } elseif (str_starts_with($sql, 'INSERT INTO konfiguration')) {
            if (!str_contains($sql, 'wert = VALUES(wert)')) {
                $this->config[$parameters['name']] ??= $parameters['wert'] ?? '[]';
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
        'erp' => new class ($db) {
            public function __construct(private ArticleDataDatabase $db) {}
            public function GetVerkaufspreisGruppe(int $article, int $quantity, int $group): string
            {
                same([9, 1, 7], [$article, $quantity, $group]);
                $this->db->groupPriceCalls++;
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
    'active_group_displays_erp_price_and_rejects_changes_before_any_write' => static function () {
        $db = new ArticleDataDatabase();
        $db->group = 7;
        $db->prices = [
            1 => ['id' => 1, 'preis' => '30.00', 'waehrung' => 'EUR', 'expired' => false],
            2 => ['id' => 2, 'preis' => '26.00', 'waehrung' => 'EUR', 'expired' => false],
        ];
        $repo = articleRepository($db);
        $stored = $repo->load(9);
        same(1, $db->groupPriceCalls);
        same('24.00', $stored['price_net']['value']);
        $reason = 'Shop nutzt eine Preisgruppe - Preis bitte unter Verkaufspreise pflegen';
        same($reason, ArticleDataRepository::priceBlockReason($stored['price_net']['source']));
        $diff = articleDiff($stored, ['price_net' => '28'], 'price_net');
        same(false, $diff['pullable']);
        same($reason, $diff['reason']);
        try {
            $repo->apply(9, ['pseudo_price' => '20', 'price_net' => '28'], 'pull');
            throw new LogicException('Price change for a shop with an active group was accepted.');
        } catch (InvalidArgumentException $error) {
            same($reason, $error->getMessage());
        }
        same(0, $db->writes);
        same(false, $db->inTransaction());
    },
    'multiple_standard_prices_display_the_highest_and_reject_changes_before_any_write' => static function () {
        $db = new ArticleDataDatabase();
        $db->prices = [
            1 => ['id' => 1, 'preis' => '24.00', 'waehrung' => 'EUR', 'expired' => false],
            2 => ['id' => 2, 'preis' => '26.00', 'waehrung' => 'EUR', 'expired' => false],
        ];
        $repo = articleRepository($db);
        $stored = $repo->load(9);
        same('26.00', $stored['price_net']['value']);
        $reason = 'Mehrere gueltige Preise ab Menge 1 - bitte unter Verkaufspreise pflegen';
        same($reason, ArticleDataRepository::priceBlockReason($stored['price_net']['source']));
        $diff = articleDiff($stored, ['price_net' => '28'], 'price_net');
        same(false, $diff['pullable']);
        same($reason, $diff['reason']);

        $db->beginTransaction();
        try {
            $repo->apply(9, ['pseudo_price' => '20', 'price_net' => '28'], 'pull');
            throw new LogicException('Price change with multiple standard prices was accepted.');
        } catch (InvalidArgumentException $error) {
            same($reason, $error->getMessage());
        }
        same(0, $db->writes);
        same(true, $db->inTransaction());
        $db->rollBack();
    },
    'missing_standard_price_cannot_be_created_by_any_woocommerce_writer' => static function () {
        foreach (['pull', 'article_tab', 'csv_import'] as $origin) {
            $db = new ArticleDataDatabase();
            $db->prices[1] = ['id' => 1, 'preis' => '24', 'expired' => false, 'ab_menge' => '0.5'];
            $before = $db->prices;
            $repo = articleRepository($db);
            $reason = 'Kein Standardpreis vorhanden - bitte unter Verkaufspreise anlegen';
            same($reason, articleDiff($repo->load(9), ['price_net' => '28'], 'price_net')['reason'] ?? null);
            try {
                $repo->apply(9, ['price_net' => '28'], $origin);
                throw new LogicException('The WooCommerce writer created a price.');
            } catch (InvalidArgumentException $error) {
                same($reason, $error->getMessage());
            }
            same($before, $db->prices);
            same(0, $db->writes);
        }
    },
    'inactive_group_uses_the_editable_standard_price' => static function () {
        $db = new ArticleDataDatabase();
        $db->group = 7;
        $db->groupActive = false;
        $db->prices[1] = ['id' => 1, 'preis' => '30.00', 'waehrung' => 'EUR', 'expired' => false];
        $repo = articleRepository($db);
        same(['value' => '30.00', 'source' => 'price'], $repo->load(9)['price_net']);
        same(['price_net' => ['old' => '30.00', 'new' => '20']], $repo->apply(9, ['price_net' => '20'], 'pull'));
        same(0, $db->groupPriceCalls);
    },
    'active_group_without_standard_row_has_no_export_price' => static function () {
        $db = new ArticleDataDatabase();
        $db->group = 7;
        $db->prices[1] = ['id' => 1, 'preis' => '24.00', 'waehrung' => 'EUR', 'expired' => false, 'art' => 'Gruppe', 'gruppe' => 7];
        $repo = articleRepository($db);
        $stored = $repo->load(9);
        same('', $stored['price_net']['value']);
        $diff = array_column(FieldDiff::compare($stored, ['price_net' => '19']), null, 'key');
        same(false, $diff['price_net']['pullable']);
        same('Shop nutzt eine Preisgruppe - Preis bitte unter Verkaufspreise pflegen', $diff['price_net']['reason']);
        try {
            $repo->apply(9, ['price_net' => '19'], 'pull');
            throw new LogicException('Price change without a standard row was accepted.');
        } catch (InvalidArgumentException $error) {
            same('Shop nutzt eine Preisgruppe - Preis bitte unter Verkaufspreise pflegen', $error->getMessage());
        }
        same(0, $db->writes);
        same(0, $db->groupPriceCalls);
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
        foreach (['artikel' => '10.00', 'shop' => '12.00'] as $target => $old) {
            $entry = $log[$target === 'artikel' ? 498 : 499];
            check((bool) preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $entry['time'] ?? ''));
            same(['user' => 42, 'article' => 9, 'field' => 'pseudo_price', 'target' => $target,
                'old' => $old, 'new' => '15', 'origin' => 'pull'], array_diff_key($entry, ['time' => true]));
        }
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
    'standard_price_replaces_export_row_preserving_attributes_and_history' => static function () {
        $db = new ArticleDataDatabase();
        $original = ['id' => 1, 'preis' => '30.00', 'waehrung' => 'CHF', 'expired' => false,
            'ab_menge' => '1.0000', 'adresse' => '0', 'objekt' => '', 'art' => 'Kunde', 'gruppe' => 11,
            'vpe' => '2', 'projekt' => '3', 'bemerkung' => 'Price note'];
        $db->prices[1] = $original;
        $repo = articleRepository($db);
        same(['price_net' => ['old' => '30.00', 'new' => '20.5']], $repo->apply(9, ['price_net' => '20,50'], 'pull'));
        same(array_replace($original, ['expired' => true]), $db->prices[1]);
        same(array_replace($original, ['id' => 2, 'preis' => '20.5']), $db->prices[2]);
        same(['value' => '20.5', 'source' => 'price'], $repo->load(9)['price_net']);
    },
    'empty_shop_price_is_visible_but_not_pullable' => static function () {
        $db = new ArticleDataDatabase();
        $db->prices[1] = ['id' => 1, 'preis' => '30.00', 'waehrung' => 'EUR', 'expired' => false];
        $values = wooReader(['regular_price' => ''])->findBySku('ABC')['values'];
        $rows = array_column(FieldDiff::compare(articleRepository($db)->load(9), $values), null, 'key');
        same(true, $rows['price_net']['differs']);
        same(false, $rows['price_net']['pullable'] ?? true);
        same('leerer Shop-Preis wird nicht uebernommen', $rows['price_net']['reason'] ?? null);
        same(true, $rows['pseudo_price']['pullable'] ?? false);
        same(true, $rows['pseudo_price']['differs']);
    },
    'empty_standard_price_is_rejected_before_any_write' => static function () {
        $db = new ArticleDataDatabase();
        $db->prices[1] = ['id' => 1, 'preis' => '30.00', 'waehrung' => 'EUR', 'expired' => false];
        $repo = articleRepository($db);
        foreach (['', '  ', null] as $empty) {
            try {
                $repo->apply(9, ['shop_name' => 'Changed', 'price_net' => $empty], 'pull');
                throw new LogicException('Empty standard price was accepted.');
            } catch (InvalidArgumentException $error) {
                check(str_contains($error->getMessage(), 'price_net'));
            }
        }
        same(0, $db->writes);
        same(['value' => '30.00', 'source' => 'price'], $repo->load(9)['price_net']);
        $repo->apply(9, ['pseudo_price' => ''], 'pull');
        same('0', $db->article['pseudopreis']);
        same('0', $db->override['pseudopreis']);
    },
    'rejects_overlong_text_before_any_write' => static function () {
        $db = new ArticleDataDatabase();
        $repo = articleRepository($db);
        foreach ([
            'shop_name' => [str_repeat('x', 256), 255],
            'customs_tariff' => [str_repeat('x', 65), 64],
            'delivery_time' => [str_repeat('x', 256), 255],
            'weight' => [str_repeat('9', 256), 255],
            'description' => [str_repeat("\u{20ac}", 21846), 65535],
        ] as $key => [$value, $limit]) {
            try {
                $repo->apply(9, ['seo_title' => 'Changed', $key => $value], 'pull');
                throw new LogicException('Overlong value was accepted: ' . $key);
            } catch (InvalidArgumentException $error) {
                check(str_contains($error->getMessage(), $key));
                check(str_contains($error->getMessage(), (string) $limit));
            }
        }
        same(0, $db->writes);
        $valid = str_repeat("\u{20ac}", 64);
        $repo->apply(9, ['customs_tariff' => $valid], 'pull');
        same($valid, $db->article['zolltarifnummer']);
    },
    'price_diff_uses_export_precision_without_rounding_other_values_or_erp_edits' => static function () {
        foreach (['price_net', 'pseudo_price'] as $key) {
            same(false, articleDiff([$key => ['value' => '8.395']], [$key => '8.40'], $key)['differs']);
            same(true, articleDiff([$key => ['value' => '8.395']], [$key => '8.39'], $key)['differs']);
            same(false, articleDiff([$key => ['value' => '8.395']], [$key => '8.395'], $key)['differs']);
            same(false, articleDiff([$key => ['value' => '8.395']], [$key => '8.396'], $key)['differs']);
        }
        same(true, articleDiff(['weight' => ['value' => '8.395']], ['weight' => '8.40'], 'weight')['differs']);
        $db = new ArticleDataDatabase();
        $db->prices[1] = ['id' => 1, 'preis' => '8.395', 'waehrung' => 'EUR', 'expired' => false];
        same(['price_net' => ['old' => '8.395', 'new' => '8.4']],
            articleRepository($db)->apply(9, ['price_net' => '8.40'], 'tab'));
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
    'price_only_changes_invalidate_real_cron_hash_for_all_assigned_shops' => static function () {
        $db = new ArticleDataDatabase();
        $db->prices[1] = ['id' => 1, 'preis' => '25', 'expired' => false];
        $export = new Shopexport((object)['DB' => $db], true);
        foreach ([5, 6] as $shop) {
            $db->exportRows[$shop][9] = ['last_article_hash' => $export->getArticleHash(9, $shop)];
            same(false, $export->hasArticleHashChanged(9, $shop)['changed']);
        }
        articleRepository($db)->apply(9, ['price_net' => '27'], 'article_tab');
        foreach ([5, 6] as $shop) {
            same(true, $export->hasArticleHashChanged(9, $shop)['changed']);
        }
    },
    'on_request_only_invalidates_real_cron_hash_for_variant_and_parent_in_its_shop' => static function () {
        $db = new ArticleDataDatabase();
        $db->article['variante_von'] = 8;
        $export = new Shopexport((object)['DB' => $db], true);
        foreach ([5, 6] as $shop) {
            foreach ([8, 9] as $id) {
                $db->exportRows[$shop][$id] = ['last_article_hash' => $export->getArticleHash($id, $shop)];
                same(false, $export->hasArticleHashChanged($id, $shop)['changed']);
            }
        }
        articleRepository($db)->apply(9, ['on_request' => '1'], 'article_tab');
        foreach ([8, 9] as $id) {
            same(true, $export->hasArticleHashChanged($id, 5)['changed']);
            same(false, $export->hasArticleHashChanged($id, 6)['changed']);
        }
    },
    'on_request_preserves_other_article_config' => static function () {
        $db = new ArticleDataDatabase();
        $db->config['woocommerce_article_5_9'] = '{"onRequest":true}';
        $repo = articleRepository($db);
        $store = new ShopCategoryStore($db, 5);
        $category = $store->create('Category');
        $store->assign(9, [$category]);
        same(['value' => '1', 'source' => 'onrequest'], $repo->load(9)['on_request']);
        same(['on_request' => ['old' => '1', 'new' => '0']], $repo->apply(9, ['on_request' => '0'], 'tab'));
        same([$category], $store->categoriesOf(9));
        same(false, $store->isOnRequest(9));
        $log = json_decode($db->config['woocommerce_pull_log_5'], true);
        same(['user' => 42, 'article' => 9, 'field' => 'on_request', 'target' => 'onrequest',
            'old' => '1', 'new' => '0', 'origin' => 'tab'], array_diff_key($log[0], ['time' => true]));

        $db->failLog = true;
        $before = $db->config;
        try {
            $repo->apply(9, ['on_request' => '1'], 'tab');
            throw new LogicException('Log failure did not propagate.');
        } catch (RuntimeException $error) {
            same('Log write failed.', $error->getMessage());
        }
        same($before, $db->config);
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
    'inherited_variation_sku_leaves_parent_matched' => static function () {
        $client = new class {
            public function get(string $endpoint, array $query): array
            {
                $parent = (object) ['id' => 100, 'sku' => 'PARENT', 'type' => 'variable'];
                $inherited = (object) ['id' => 101, 'sku' => 'PARENT', 'parent_id' => 100, 'name' => 'Inherited'];
                if (isset($query['sku'])) {
                    return [$inherited, $parent];
                }
                return $endpoint === 'products' ? [$parent] : [$inherited, (object) ['id' => 102, 'sku' => '']];
            }
        };
        $reader = new WooProductReader($client, new DeliveryTimeMap(static fn () => [], ''));
        $page = $reader->page(1);
        same(['PARENT'], array_keys($page['items']));
        same([101, 102], array_column($page['unmatched'], 'id'));
        same(['keine eigene SKU', 'keine eigene SKU'], array_column($page['unmatched'], 'reason'));
        same(100, $reader->findBySku('PARENT')['id']);
    },
    'inherited_variation_logistics_are_not_compared' => static function () {
        $client = new class {
            public function get(string $endpoint, array $query): array
            {
                $logistics = ['weight' => '5', 'dimensions' => (object) ['length' => '4', 'width' => '3', 'height' => '2']];
                $variant = (object) (['id' => 101, 'parent_id' => 100, 'sku' => 'VAR'] + $logistics);
                if (isset($query['sku']) || $endpoint === 'products/100/variations') {
                    return [$variant];
                }
                return [(object) (['id' => 100, 'sku' => 'PARENT', 'type' => 'variable'] + $logistics)];
            }
        };
        $reader = new WooProductReader($client, new DeliveryTimeMap(static fn () => [], ''));
        foreach ([$reader->page(1)['items']['VAR'], $reader->findBySku('VAR')] as $variation) {
            $rows = array_column(FieldDiff::compare([], $variation['values']), null, 'key');
            foreach (['weight', 'length', 'width', 'height'] as $key) {
                check(array_key_exists($key, $variation['values']));
                same(null, $variation['values'][$key]);
                check(!array_key_exists($key, $rows));
            }
        }
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
    'unknown_delivery_time_does_not_propose_clearing_the_existing_value' => static function () {
        $metadata = [(object) ['key' => '_lieferzeit', 'value' => 999]];
        $product = wooReader(['meta_data' => $metadata])->findBySku('ABC');
        $openxe = ['delivery_time' => ['value' => '2-3 Werktage']];
        same(null, $product['values']['delivery_time']);
        same('Lieferzeit-ID 999 unbekannt', $product['reasons']['delivery_time'] ?? null);
        $rows = array_column(FieldDiff::compare($openxe, $product['values']), null, 'key');
        check(!array_key_exists('delivery_time', $rows));

        $metadata[0]->value = -1;
        $default = wooReader(['meta_data' => $metadata])->findBySku('ABC');
        $row = articleDiff($openxe, $default['values'], 'delivery_time');
        same('', $row['woocommerce']);
        same(true, $row['differs']);
        same(true, $row['pullable'] ?? false);
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
