<?php
// SPDX-License-Identifier: AGPL-3.0-only
require __DIR__ . '/bootstrap.php';
require dirname(__DIR__, 2) . '/phpwf/plugins/class.formhandler.php';
require dirname(__DIR__, 2) . '/www/pages/artikel.php';

use Xentral\Components\Http\Session\Session;
use Xentral\Components\WooCommerce\ArticleFieldCatalog;
use Xentral\Components\WooCommerce\ArticleTab;
use Xentral\Components\WooCommerce\DeliveryTimeMap;

final class Shopimporter_Woocommerce
{
    public static array $products = [];
    public static function clientForShop($app, int $shopId): self
    {
        same(5, $shopId);
        return new self();
    }

    public $client;
    public function __construct()
    {
        $this->client = new class {
            public function get(string $endpoint, array $query): array
            {
                same('products', $endpoint);
                same('LOCAL-9', $query['sku']);
                return Shopimporter_Woocommerce::$products;
            }
        };
    }

    public function deliveryTimeMap(): DeliveryTimeMap
    {
        return new DeliveryTimeMap(static fn (): array => [], '');
    }
}

final class ArticleTabDatabase
{
    public array $article = ['id' => 9, 'nummer' => 'LOCAL-9', 'pseudopreis' => '15.00',
        'autolagerlampe' => '0', 'pseudolager' => '', 'restmenge' => '0', 'lieferzeitmanuell' => ''];
    public array $override = ['id' => 6, 'pseudopreis' => '12.00'];
    public array $shops = [['id' => 5, 'bezeichnung' => 'Shop A', 'projekt' => 0, 'article_project' => 0]];
    public array $config = [];
    public array $price = ['id' => 4, 'preis' => '10.00', 'waehrung' => 'EUR'];
    private bool $transaction = false;
    public int $writes = 0;

    public function fetchAll(string $sql, array $params): array
    {
        check(str_contains($sql, "s.modulename = 'shopimporter_woocommerce'"));
        check(str_contains($sql, 's.aktiv = 1') && str_contains($sql, 'ao.aktiv = 1'));
        return $params === ['article' => 9] ? $this->shops : [];
    }

    public function fetchRow(string $sql, array $params)
    {
        return match (true) {
            str_contains($sql, 'FROM artikel WHERE') => $this->article,
            str_contains($sql, 'FROM artikel_onlineshops') => $this->override,
            str_contains($sql, 'FROM verkaufspreise') => $this->price,
            default => throw new RuntimeException('Unexpected query: ' . $sql),
        };
    }

    public function fetchValue(string $sql, array $params)
    {
        return $this->config[$params['name'] ?? ''] ?? false;
    }

    public function perform(string $sql, array $params): void
    {
        $this->writes++;
        if (str_starts_with($sql, 'UPDATE artikel_onlineshops SET ')) {
            same(6, $params['id']);
            preg_match('/SET \x60([a-z_]+)\x60/', $sql, $match);
            $this->override[$match[1]] = $params['value'];
        } elseif (str_starts_with($sql, 'UPDATE verkaufspreise')) {
            same(4, $params['id']);
            $this->price = [];
        } elseif (str_starts_with($sql, 'INSERT INTO verkaufspreise')) {
            same(9, $params['article']);
            $this->price = ['id' => 7, 'preis' => $params['value'], 'waehrung' => $params['currency']];
        } elseif (str_starts_with($sql, 'INSERT INTO konfiguration')) {
            $this->config[$params['name']] = $params['wert'] ?? ($this->config[$params['name']] ?? '[]');
        } else {
            throw new RuntimeException('Unexpected write: ' . $sql);
        }
    }

    public function inTransaction(): bool { return $this->transaction; }
    public function beginTransaction(): void { $this->transaction = true; }
    public function commit(): void { $this->transaction = false; }
    public function rollBack(): void { $this->transaction = false; }
}

function articleTabFixture(): array
{
    $db = new ArticleTabDatabase();
    $session = new Session();
    $token = $session->createCsrfToken('woocommerce_admin');
    $app = (object) [
        'Container' => new class ($db, $session) {
            public function __construct(private ArticleTabDatabase $db, private Session $session) {}
            public function get(string $service)
            {
                return match ($service) {
                    'Database' => $this->db,
                    'Session' => $this->session,
                    'Logger' => new class {
                        public function error(string $message): void {}
                    },
                };
            }
        },
        'erp' => new class {
            public function UserProjektRecht(int $project): bool { return $project === 0; }
        },
        'Secure' => new class {
            public function CleanString(string $html, string $rule, int $sqlcheckoff): string
            {
                same(['nojs', 1], [$rule, $sqlcheckoff]);
                return strip_tags($html, '<p>');
            }
        },
        'Tpl' => new class {
            public array $values = [];
            public function Set(string $name, string $value): void { $this->values[$name] = $value; }
        },
    ];
    return [new ArticleTab($app), $db, $token, $app];
}

runTests([
    'tab_hidden_without_woocommerce_shop' => static function () {
        [$tab, $db, , $app] = articleTabFixture();
        same(5, (int) $tab->shopsFor(9)[0]['id']);
        $db->shops[0]['article_project'] = 17;
        same([], $tab->shopsFor(9));
        $db->shops = [];
        same([], $tab->shopsFor(9));
        $class = new ReflectionClass(Artikel::class);
        $page = $class->newInstanceWithoutConstructor();
        $page->app = $app;
        $class->getMethod('prepareWooCommerceArticleTab')->invoke($page, 9);
        same(['WOOCOMMERCETABOPEN' => '<!--', 'WOOCOMMERCETABCLOSE' => '-->'], $app->Tpl->values);
    },
    'tab_renders_all_catalog_fields_with_target' => static function () {
        [$tab, $db, $token] = articleTabFixture();
        $html = $tab->render(9, 5, $token);
        foreach (ArticleFieldCatalog::fields() as $field) {
            check(str_contains($html, $field['label']), $field['key'] . ' label missing');
            check(str_contains($html, '>' . $field['wc'] . '<'), $field['key'] . ' WooCommerce target missing');
        }
        check(preg_match('/<input [^>]*name="woo\[sku\]"[^>]* readonly/', $html) === 1);
        check(str_contains($html, 'wird auch in der Shop-Zuordnung gesetzt'));
        check(preg_match('/<input [^>]*name="woo\[pseudo_price\]"[^>]*value="15\.00"/', $html) === 1);
    },
    'mirror_attributes_only_for_form_fields' => static function () {
        [$tab] = articleTabFixture();
        $html = $tab->render(9, 5, 'token');
        preg_match_all('/ data-mirror="([^"]+)"/', $html, $matches);
        $expected = [
            'shop_name_de', 'uebersicht_de', 'kurztext_de', 'metatitle_de', 'metadescription_de', 'metakeywords_de',
            'inaktiv', 'ausverkauft', 'gewicht', 'laenge', 'breite', 'hoehe', 'ean', 'zolltarifnummer',
            'herkunftsland', 'hersteller', 'autolagerlampe', 'pseudolager', 'restmenge', 'lieferzeitmanuell', 'pseudopreis',
        ];
        sort($expected);
        $actual = $matches[1];
        sort($actual);
        same($expected, $actual);
    },
    'compare_returns_rows_and_handles_missing_product' => static function () {
        [$tab] = articleTabFixture();
        same(['rows' => [], 'productId' => null, 'error' => 'Produkt mit SKU LOCAL-9 nicht in WooCommerce gefunden'], $tab->compare(9, 5));
        Shopimporter_Woocommerce::$products = [[
            'id' => 19, 'sku' => 'LOCAL-9', 'name' => 'Woo name', 'description' => '<p>Woo text</p><iframe></iframe>',
        ]];
        $result = $tab->compare(9, 5);
        same(null, $result['error']);
        same(19, $result['productId']);
        $rows = array_column($result['rows'], null, 'key');
        same('Woo name', $rows['shop_name']['woocommerce']);
        same('<p>Woo text</p>', $rows['description']['woocommerce']);
        same(true, $rows['shop_name']['differs']);
        same(null, $tab->compare(9, 8)['productId']);
    },
    'save_extras_rejects_invalid_token' => static function () {
        [$tab, $db] = articleTabFixture();
        try {
            $tab->saveExtras(9, 5, ['woo_csrf_token' => 'invalid', 'woo' => ['price_net' => '19.99']]);
            check(false, 'Invalid token accepted');
        } catch (RuntimeException $error) {
            check(str_contains($error->getMessage(), 'Formular abgelaufen'));
        }
        same(0, $db->writes);
    },
    'save_extras_syncs_shop_override_to_article_value' => static function () {
        [$tab, $db, $token] = articleTabFixture();
        $result = $tab->saveExtras(9, 5, ['woo_csrf_token' => $token, 'woo' => ['pseudo_price' => '99']]);
        same('15', $db->override['pseudopreis']);
        same(['old' => '12.00', 'new' => '15'], $result['pseudo_price']);
    },
    'save_extras_writes_price' => static function () {
        [$tab, $db, $token] = articleTabFixture();
        $result = $tab->saveExtras(9, 5, ['woo_csrf_token' => $token, 'woo' => ['price_net' => '19,99']]);
        same('19.99', $db->price['preis']);
        same(['old' => '10.00', 'new' => '19.99'], $result['price_net']);
    },
    'save_extras_stores_on_request_checked_and_unchecked' => static function () {
        [$tab, $db, $token] = articleTabFixture();
        foreach (['1', '0'] as $checked) {
            $tab->saveExtras(9, 5, ['woo_csrf_token' => $token, 'woo' => ['on_request' => $checked]]);
            same(['onRequest' => $checked === '1'], json_decode($db->config['woocommerce_article_5_9'], true));
        }
    },
    'extras_callback_runs_after_successful_legacy_save_before_redirect' => static function () {
        $trace = [];
        $article = new class ($trace) {
            public function __construct(private array &$trace) {}
            public function Select($id): void { same(9, $id); }
            public function Update(): void { $this->trace[] = 'article updated'; }
            public function GetId(): int { return 9; }
        };
        $app = (object) [
            'Secure' => new class {
                public function GetGET(string $name): int { same('id', $name); return 9; }
            },
            'DB' => new class {
                public function error(): bool { return false; }
            },
            'ObjAPI' => new class ($article) {
                public function __construct(private object $article) {}
                public function Get(string $table): object { same('artikel', $table); return $this->article; }
            },
            'erp' => new class {
                public string $commonreadonly = '0';
                public function StartChangeLog(string $table): void {}
                public function WriteChangeLog(): void {}
                public function RunHook(...$args): void {}
            },
        ];
        $afterSave = static function (int $id) use (&$trace): ?string {
            same(9, $id);
            $trace[] = 'extras saved';
            return null;
        };
        $form = new class ($app, 'artikel', $afterSave, $trace) extends FormActionHandler {
            public bool $valid = true;
            public function __construct($app, string $name, Closure $callback, private array &$trace)
            {
                parent::__construct($app, $name, $callback);
            }
            public function FormhandlerEvent() { return true; }
            public function CallbackAndMandatorycheck($returnonly = false) { return $this->valid; }
            public function ActionAfterExecute(?string $notice = null) { $this->trace[] = 'redirect'; }
        };
        $form->UseTable('artikel');
        $form->Edit();
        same(['article updated', 'extras saved', 'redirect'], $trace);
    },
]);
