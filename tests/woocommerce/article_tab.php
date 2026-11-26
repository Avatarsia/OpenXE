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
    public array $additionalPrices = [];
    private array $expiredPrice = [];
    private bool $transaction = false;
    public int $writes = 0;
    public int $group = 0;
    public bool $failWrites = false;
    public array $errors = [];

    public function fetchAll(string $sql, array $params): array
    {
        if (str_contains($sql, 'FROM verkaufspreise')) {
            return $this->price === [] ? [] : [$this->price, ...$this->additionalPrices];
        }
        check(str_contains($sql, "s.modulename = 'shopimporter_woocommerce'"));
        check(str_contains($sql, 's.aktiv = 1') && str_contains($sql, 'ao.aktiv = 1'));
        return $params === ['article' => 9] ? $this->shops : [];
    }

    public function fetchRow(string $sql, array $params)
    {
        return match (true) {
            str_contains($sql, 'FROM artikel WHERE') => $this->article,
            str_contains($sql, 'FROM artikel_onlineshops') => $this->override,
            default => throw new RuntimeException('Unexpected query: ' . $sql),
        };
    }

    public function fetchValue(string $sql, array $params)
    {
        return str_contains($sql, 'FROM shopexport') ? $this->group : ($this->config[$params['name'] ?? ''] ?? false);
    }

    public function perform(string $sql, array $params): void
    {
        $this->writes++;
        if ($this->failWrites) {
            throw new RuntimeException('Synthetic extras write failure');
        }
        if (str_starts_with($sql, 'UPDATE artikel_onlineshops SET ')) {
            same(6, $params['id']);
            preg_match('/SET \x60([a-z_]+)\x60/', $sql, $match);
            $this->override[$match[1]] = $params['value'];
        } elseif (str_starts_with($sql, 'UPDATE verkaufspreise')) {
            same(4, $params['id']);
            $this->expiredPrice = $this->price;
            $this->price = [];
        } elseif (str_starts_with($sql, 'INSERT INTO verkaufspreise')) {
            same($this->expiredPrice['id'], $params['id']);
            $this->price = array_replace($this->expiredPrice, ['id' => 7, 'preis' => $params['value']]);
        } elseif (str_starts_with($sql, 'INSERT INTO konfiguration')) {
            if (str_contains($sql, 'ON DUPLICATE KEY UPDATE name = VALUES(name)')) {
                $this->config[$params['name']] ??= $params['wert'] ?? '[]';
                return;
            }
            $this->config[$params['name']] = $params['wert'];
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
                    'Logger' => new class ($this->db) {
                        public function __construct(private ArticleTabDatabase $db) {}
                        public function error(string $message): void { $this->db->errors[] = $message; }
                    },
                };
            }
        },
        'DB' => new class {
            public function Select(string $sql): bool { return false; }
        },
        'erp' => new class {
            public function ReplaceBetrag(int $db, string $value, int $fromForm): string
            {
                same([1, 1], [$db, $fromForm]);
                return str_replace(',', '.', $value);
            }
            public function UserProjektRecht(int $project): bool { return $project === 0; }
            public function GetVerkaufspreisGruppe(int $article, int $quantity, int $group): string
            {
                same([9, 1, 7], [$article, $quantity, $group]);
                return '24.00';
            }
        },
        'Secure' => new class {
            public array $get = ['module' => 'artikel', 'id' => 9];
            public array $POST = [];
            public function GetGET(string $name) { return $this->get[$name] ?? ''; }
            public function GetPOST(string $name, ...$args) { return $this->POST[$name] ?? ''; }
            public function RuleCheck(string $value, string $rule): bool { return $rule === 'notempty' && $value !== ''; }
            public function CleanString(string $html, string $rule, int $sqlcheckoff): string
            {
                same(['nojs', 1], [$rule, $sqlcheckoff]);
                return strip_tags($html, '<p>');
            }
        },
        'Tpl' => new class {
            public array $values = [];
            public function Set(string $name, string $value): void { $this->values[$name] = $value; }
            public function Add(string $name, string $value): void { $this->values[$name] = ($this->values[$name] ?? '') . $value; }
            public function Parse(string $target, string $template): void { $this->values[$target] = $template; }
        },
    ];
    $app->FormHandler = new FormHandler($app);
    return [new ArticleTab($app), $db, $token, $app];
}

function articleTabPage(object $app): Artikel
{
    $page = (new ReflectionClass(Artikel::class))->newInstanceWithoutConstructor();
    $page->app = $app;
    return $page;
}

function prepareArticleTab(object $app)
{
    $page = articleTabPage($app);
    return (new ReflectionMethod($page, 'prepareWooCommerceArticleTab'))->invoke($page, 9);
}

function submitArticleTab(object $app, $shop, string $token, array $draft): void
{
    $app->Secure->POST = ['phpwf_formhandlerevent_YXJ0aWtlbA==' => 'go', 'speichern' => 'Speichern',
        'woo_shop' => $shop, 'woo_csrf_token' => $token, 'woo' => $draft];
}

runTests([
    'selected_shop_drives_render_compare_and_post' => static function () {
        [, $db, , $app] = articleTabFixture();
        $db->shops[] = ['id' => 8, 'bezeichnung' => 'Shop B', 'projekt' => 0, 'article_project' => 0];
        $db->shops[] = ['id' => 11, 'bezeichnung' => 'Forbidden', 'projekt' => 42, 'article_project' => 0];
        $app->Secure->get['woo_shop'] = '8';
        prepareArticleTab($app);
        $html = $app->Tpl->values['WOOCOMMERCETAB'];
        check(str_contains($html, '<select') && str_contains($html, 'value="8" selected'));
        check(str_contains($html, 'name="woo_shop" value="8"'));
        check(str_contains($html, '&amp;shop=8'));
        check(!str_contains($html, 'Forbidden'));
        $app->Secure->get['woo_shop'] = '11';
        prepareArticleTab($app);
        check(str_contains($app->Tpl->values['WOOCOMMERCETAB'], 'name="woo_shop" value="5"'));
    },
    'popup_saves_woocommerce_values_in_both_tables_and_returns_stored_fields' => static function () {
        [, , , $app] = articleTabFixture();
        $columns = ['pseudopreis' => '20.50', 'pseudolager' => '4', 'restmenge' => '1',
            'lieferzeitmanuell' => '2 Tage', 'autolagerlampe' => '1'];
        $app->Secure->POST = $columns + ['aktiv' => '1'];
        $app->DB = new class ($columns) {
            public array $updates = [];
            public bool $woo = true;
            public function __construct(private array $columns) {}
            public function Select(string $sql) { check(str_contains($sql, 'shopimporter_woocommerce')); return $this->woo ? '5' : ''; }
            public function Update(string $sql): bool { $this->updates[] = $sql; return true; }
            public function SelectRow(string $sql): array { return $this->columns; }
        };
        $page = articleTabPage($app);
        $save = new ReflectionMethod($page, 'saveOnlineShopFields');
        same($columns, $save->invoke($page, 9, 6, 5, [...array_keys($columns), 'aktiv']));
        foreach ($columns as $column => $value) {
            check(str_contains($app->DB->updates[0], "ao.$column = '$value'"));
            check(str_contains($app->DB->updates[0], "a.$column = '$value'"));
        }
        check(!str_contains($app->DB->updates[0], 'a.aktiv ='));
        $app->DB->woo = false;
        same([], $save->invoke($page, 9, 6, 5, [...array_keys($columns), 'aktiv']));
        check(!str_contains($app->DB->updates[1], 'JOIN artikel'));
    },
    'save_extras_leaves_override_on_unrelated_article_save' => static function () {
        [$tab, $db, $token] = articleTabFixture();
        $post = ['woo_csrf_token' => $token, 'woo' => ['on_request' => '1']];
        $snapshot = $db->article;
        $db->article['name_de'] = 'Unrelated change';
        $result = $tab->saveExtras(9, 5, $post, $snapshot);
        same('12.00', $db->override['pseudopreis']);
        same(['on_request'], array_keys($result));
    },
    'invalid_extra_rejects_legacy_edit_before_any_update' => static function () {
        $template = file_get_contents(dirname(__DIR__, 2) . '/www/widgets/templates/_gen/artikel.tpl');
        $messagePosition = strpos($template, '[MESSAGE]');
        check($messagePosition !== false && $messagePosition < strpos($template, '<div id="tabs">'),
            'Save errors must be visible on every tab');
        foreach ([
            ['5', true, ['price_net' => 'invalid'], 'price_net'],
            ['5', true, ['on_request' => 'maybe'], 'on_request'],
            ['5', true, ['price_net' => '10'], 'delivery_time', ['lieferzeitmanuell' => str_repeat('a', 256)]],
            ['5', true, ['price_net' => '10'], 'delivery_time', ['lieferzeitmanuell' => '2 Tage' . str_repeat(' ', 256)]],
            ['9', true, ['price_net' => '10'], 'nicht zugeordnet'],
            ['5', false, ['price_net' => '10'], 'Formular abgelaufen'],
        ] as $scenario) {
            [$shop, $validToken, $draft, $error] = $scenario;
            [, $db, $token, $app] = articleTabFixture();
            submitArticleTab($app, $shop, $validToken ? $token : 'invalid', $draft);
            $app->Secure->POST += $scenario[4] ?? [];
            check(prepareArticleTab($app), 'WooCommerce edit must keep the rendered form on this request');
            same('', $app->Secure->GetPOST('speichern'), 'Reject submit before WidgetArtikel allocates a number');
            $form = $app->FormHandler->CreateNew('artikel');
            $form->UseTable('artikel');
            $form->UseTemplate('artikel.tpl', 'PAGE');
            $form->Edit();
            same(0, $db->writes);
            check(str_contains($app->Tpl->values['MESSAGE'] ?? '', $error));
            same('artikel.tpl', $app->Tpl->values['PAGE']);
        }
    },
    'failed_extras_write_is_logged_and_returns_an_error_notice' => static function () {
        [, $db, $token, $app] = articleTabFixture();
        submitArticleTab($app, '5', $token, ['price_net' => '19,99']);
        check(prepareArticleTab($app));
        same(0, $db->writes);
        $form = $app->FormHandler->CreateNew('artikel');
        $callback = (new ReflectionProperty($form, 'afterEditSave'))->getValue($form);
        $db->failWrites = true;
        $notice = $callback(9);
        check(str_contains($notice, 'WooCommerce-Zusatzdaten konnten nicht gespeichert werden'));
        same(['WooCommerce-Artikeldaten nicht gespeichert: Synthetic extras write failure'], $db->errors);
        same('10.00', $db->price['preis']);
    },
    'rejected_submissions_render_the_post_draft' => static function () {
        foreach (['19,99', '<invalid>'] as $draftPrice) {
            [, $db, $token, $app] = articleTabFixture();
            submitArticleTab($app, '5', $token, ['price_net' => $draftPrice, 'on_request' => '1', 'sku' => 'bad']);
            check(prepareArticleTab($app), 'WooCommerce draft must survive a mandatory field failure');
            $form = $app->FormHandler->CreateNew('artikel');
            $form->UseTemplate('artikel.tpl', 'PAGE');
            $form->NewField(new class {
                public string $name = 'name_de';
                public string $value = '';
                public function Get(): string { return $this->value; }
                public function GetClose(): string { return ''; }
            });
            $form->AddMandatory('name_de', 'notempty', 'Pflichtfeld!', 'MSGNAME_DE');
            $form->Edit();
            same('artikel.tpl', $app->Tpl->values['PAGE']);
            same(0, $db->writes);
            check(str_contains($app->Tpl->values['MSGNAME_DE'], 'Pflichtfeld!'));
            $html = $app->Tpl->values['WOOCOMMERCETAB'];
            check(preg_match('/name="woo\[price_net\]"[^>]+value="' . preg_quote(htmlspecialchars($draftPrice), '/') . '"/', $html) === 1);
            check(preg_match('/data-woo-key="on_request"[^>]+ checked/', $html) === 1);
            check(str_contains($html, 'value="LOCAL-9"') && !str_contains($html, 'value="bad"'));
        }
    },
    'active_group_renders_readonly_erp_price_and_allows_saving_other_fields' => static function () {
        [$tab, $db, $token] = articleTabFixture();
        $db->group = 7;
        $reason = 'Shop nutzt eine Preisgruppe - Preis bitte unter Verkaufspreise pflegen';
        $html = $tab->render(9, 5, $token);
        check(preg_match('/data-woo-key="price_net"[^>]+readonly[^>]+value="24.00"/', $html) === 1);
        check(str_contains($html, $reason));
        $result = $tab->saveExtras(9, 5, ['woo_csrf_token' => $token,
            'woo' => ['price_net' => '24.00', 'on_request' => '1']]);
        same(['on_request' => ['old' => '0', 'new' => '1']], $result);
        $db->price = [];
        $html = $tab->render(9, 5, $token);
        check(preg_match('/data-woo-key="price_net"[^>]+readonly[^>]+value=""/', $html) === 1);
        check(str_contains($html, $reason));
    },
    'ambiguous_price_is_readonly_and_unchanged_readonly_input_can_be_submitted' => static function () {
        [$tab, $db, $token] = articleTabFixture();
        $db->additionalPrices = [['id' => 6, 'preis' => '8.00', 'waehrung' => 'EUR']];
        $html = $tab->render(9, 5, $token);
        check(preg_match('/data-woo-key="price_net"[^>]+readonly[^>]+value="10.00"/', $html) === 1);
        check(str_contains($html, 'Mehrere gueltige Preise ab Menge 1 - bitte unter Verkaufspreise pflegen'));
        $result = $tab->saveExtras(9, 5, ['woo_csrf_token' => $token, 'woo' => ['price_net' => '10.00', 'on_request' => '1']]);
        same(['on_request' => ['old' => '0', 'new' => '1']], $result);
    },
    'readonly_price_change_is_rejected_in_prevalidation' => static function () {
        foreach ([
            0 => 'Mehrere gueltige Preise ab Menge 1 - bitte unter Verkaufspreise pflegen',
            7 => 'Shop nutzt eine Preisgruppe - Preis bitte unter Verkaufspreise pflegen',
        ] as $group => $reason) {
            [, $db, $token, $app] = articleTabFixture();
            $db->group = $group;
            if ($group === 0) {
                $db->additionalPrices = [['id' => 6, 'preis' => '8.00', 'waehrung' => 'EUR']];
            }
            submitArticleTab($app, '5', $token, ['price_net' => '28']);
            check(prepareArticleTab($app));
            same('', $app->Secure->GetPOST('speichern'));
            $form = $app->FormHandler->CreateNew('artikel');
            $form->UseTable('artikel');
            $form->UseTemplate('artikel.tpl', 'PAGE');
            $form->Edit();
            check(str_contains($app->Tpl->values['MESSAGE'], $reason));
            same(0, $db->writes);
        }
    },
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
        } catch (DomainException $error) {
            check(str_contains($error->getMessage(), 'Formular abgelaufen'));
        }
        same(0, $db->writes);
    },
    'save_extras_syncs_shop_override_to_article_value' => static function () {
        [$tab, $db, $token] = articleTabFixture();
        $db->article['pseudopreis'] = '10.00';
        $snapshot = $db->article;
        $db->article['pseudopreis'] = '15.00';
        $result = $tab->saveExtras(9, 5, ['woo_csrf_token' => $token, 'woo' => ['pseudo_price' => '99']], $snapshot);
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
            same(['categories' => [], 'onRequest' => $checked === '1'], json_decode($db->config['woocommerce_article_5_9'], true));
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
