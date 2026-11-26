<?php
// SPDX-License-Identifier: AGPL-3.0-only
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/bulk-pull.php';
require __DIR__ . '/fixtures/category-export.php';

use Xentral\Components\Http\Session\Session;
use Xentral\Components\WooCommerce\AdminPage;
use Xentral\Components\WooCommerce\DeliveryTimeMap;

final class Shopimporter_Woocommerce
{
    public static BulkPullClient|CategoryExportClient $fixtureClient;
    public $client;

    public static function clientForShop($app, int $shopId): self
    {
        $connector = new self();
        $connector->client = self::$fixtureClient;
        return $connector;
    }

    public function deliveryTimeMap(): DeliveryTimeMap
    {
        return new DeliveryTimeMap(static fn () => [], '');
    }
}

final class AdminDatabaseFake
{
    public $writes = [];
    public $values = [];
    public $module = 'shopimporter_woocommerce';
    public $articleProject = 0;
    public $assigned = true;
    private int $lastTemplateId = 0;
    public array $templates = [];
    private $transaction = false;
    public function fetchRow($sql, $params) {
        check(!str_contains($sql, 'preisgruppe'));
        return ['id' => 7, 'modulename' => $this->module, 'projekt' => 0,
            'einstellungen_json' => '{"felder":{"exportDownloads":1}}'];
    }
    public function fetchAll($sql, $params) {
        if (isset($params['sku'])) {
            return $this->assigned && $params['sku'] === 'SHOP-9'
                ? [['id' => 9, 'nummer' => 'A9', 'name_de' => 'Artikel', 'projekt' => $this->articleProject]] : [];
        }
        if (strpos($sql, 'FROM artikel') !== false) {
            check(isset($params['shop'], $params['shop2'], $params['shop3'], $params['assigned_shop']));
            return $this->assigned ? [['id' => 9, 'nummer' => 'A9', 'name_de' => 'Artikel', 'projekt' => $this->articleProject]] : [];
        }
        return [];
    }
    public function fetchValue($sql, $params) {
        if (strpos($sql, 'GET_LOCK') !== false || strpos($sql, 'RELEASE_LOCK') !== false) return 1;
        if (preg_match('/SELECT id FROM (exportvorlage|importvorlage)/', $sql, $match)) {
            return $this->templates[$match[1]]['id'] ?? false;
        }
        if (strpos($sql, 'COUNT') !== false) return 0;
        return $this->values[$params['name']] ?? false;
    }
    public function perform($sql, $params) {
        if (preg_match('/INSERT INTO (exportvorlage|importvorlage)/', $sql, $match)) {
            $this->templates[$match[1]] = ['id' => ++$this->lastTemplateId] + $params;
            return;
        }
        $this->writes[] = [$sql, $params];
        if (isset($params['name'])) {
            if (str_contains($sql, 'ON DUPLICATE KEY UPDATE name = VALUES(name)')) {
                $this->values[$params['name']] ??= $params['wert'];
            } else {
                $this->values[$params['name']] = $params['wert'] ?? $params['value'] ?? '';
            }
        }
    }
    public function lastInsertId(): int { return $this->lastTemplateId; }
    public function inTransaction() { return $this->transaction; }
    public function beginTransaction() { $this->transaction = true; }
    public function commit() { $this->transaction = false; }
    public function rollBack() { $this->transaction = false; }
}

function adminFixture(array $post = [], array $get = [], $db = null, ?Session $session = null)
{
    $_SERVER['REQUEST_METHOD'] = $post === [] ? 'GET' : 'POST';
    if ($session === null) {
        $_SESSION = [];
        $session = new Session();
    }
    $db = $db ?? new AdminDatabaseFake();
    $token = $post['csrf_token'] ?? $session->createCsrfToken('woocommerce_admin');
    $secure = new class($get + ['article' => 9], $post + ['csrf_token' => $token]) {
        private $get; private $post;
        public function __construct($get, $post) { $this->get = $get; $this->post = $post; }
        public function GetGET($key, ...$unused) { return $this->get[$key] ?? ''; }
        public function GetPOST($key, ...$unused) { return $this->post[$key] ?? ''; }
    };
    $erp = new class {
        public $allowed = true;
        public bool $articleAllowed = true;
        public function RechteVorhanden($module, $action)
        {
            same('edit', $action);
            return match ($module) {
                'onlineshops' => $this->allowed,
                'artikel' => $this->articleAllowed,
            };
        }
        public function UserProjektRecht($id) { return $id === 0; }
    };
    $container = new class($db, $session) {
        private $db; private $session;
        public function __construct($db, $session) { $this->db = $db; $this->session = $session; }
        public function get($key) {
            return match ($key) {
                'Database' => $this->db,
                'Session' => $this->session,
                'Logger' => new class {
                    public function info(...$args): void {}
                    public function error(...$args): void {}
                },
            };
        }
    };
    $app = (object) ['Container' => $container, 'Secure' => $secure, 'erp' => $erp];
    return [new AdminPage($app, 7), $db, $erp];
}

function bulkAdminPreview(BulkPullDatabase $db): array
{
    $client = Shopimporter_Woocommerce::$fixtureClient = new BulkPullClient();
    $get = ['view' => 'pull'];
    [$page] = adminFixture([], $get, $db);
    $html = $page->render();
    preg_match('/name="pull_preview" value="([^"]+)"/', $html, $preview);
    preg_match('/name="csrf_token" value="([^"]+)"/', $html, $token);
    return [$client, $get, ['wc_action' => 'apply_pull', 'csrf_token' => $token[1], 'pull_preview' => $preview[1]]];
}

runTests([
    'category_preview_requires_confirm_and_uses_the_existing_sku_resolver' => static function () {
        $db = new AdminDatabaseFake();
        Shopimporter_Woocommerce::$fixtureClient = $client = categoryImportClient();
        $_SESSION = [];
        $session = new Session();
        $get = ['view' => 'categories'];
        [$page] = adminFixture([], $get, $db, $session);
        $html = $page->render();
        check(str_contains($html, 'V6 Nozzles'));
        check(str_contains($html, '&lt;Unknown&gt;'));
        check(str_contains($html, 'OpenXE-Artikel #9'));
        same([], $db->writes);
        preg_match('/name="category_preview" value="([^"]+)"/', $html, $preview);
        preg_match('/name="csrf_token" value="([^"]+)"/', $html, $token);
        $post = ['wc_action' => 'apply_categories', 'csrf_token' => $token[1], 'category_preview' => $preview[1]];
        [$page] = adminFixture(array_replace($post, ['csrf_token' => 'wrong']), $get, $db, $session);
        check(str_contains($page->render(), 'Formular abgelaufen'));
        same([], $db->writes);
        [$page] = adminFixture(array_replace($post, ['category_preview' => 'wrong']), $get, $db, $session);
        check(str_contains($page->render(), 'Vorschau erneut laden'));
        same([], $db->writes);
        [$page] = adminFixture($post, $get, $db, $session);
        check(str_contains($page->render(), 'Kategorien &uuml;bernommen'));
        $store = new \Xentral\Components\WooCommerce\ShopCategoryStore($db, 7);
        same(5, count($store->tree()));
        same(2, count($store->categoriesOf(9)));
        same([], $client->writes);
        [$page] = adminFixture($post, $get, $db, $session);
        check(!str_contains($page->render(), 'Kategorien &uuml;bernommen'));
    },
    'category_preview_and_apply_require_both_shop_and_article_edit_rights' => static function () {
        $db = new AdminDatabaseFake();
        Shopimporter_Woocommerce::$fixtureClient = $client = categoryImportClient();
        $get = ['view' => 'categories'];
        $_SESSION = [];
        $session = new Session();
        [$page, , $erp] = adminFixture([], $get, $db, $session);
        $erp->articleAllowed = false;
        $html = $page->render();
        check(str_contains($html, 'Keine Berechtigung zur Artikelbearbeitung'));
        check(!str_contains($html, 'OpenXE-Artikel #9'));
        same([], $client->reads);

        $erp->articleAllowed = true;
        $html = $page->render();
        preg_match('/name="category_preview" value="([^"]+)"/', $html, $preview);
        preg_match('/name="csrf_token" value="([^"]+)"/', $html, $token);
        $post = ['wc_action' => 'apply_categories', 'csrf_token' => $token[1], 'category_preview' => $preview[1]];
        $reads = $client->reads;
        [$page, , $erp] = adminFixture($post, $get, $db, $session);
        $erp->articleAllowed = false;
        check(str_contains($page->render(), 'Keine Berechtigung zur Artikelbearbeitung'));
        $erp->articleAllowed = true;
        $erp->allowed = false;
        check(str_contains($page->render(), 'Keine Berechtigung zur Shopverwaltung'));
        same($reads, $client->reads);
        same([], $db->writes);
    },
    'category_admin_shows_the_reason_for_a_pending_deletion' => static function () {
        $db = new AdminDatabaseFake();
        $store = new \Xentral\Components\WooCommerce\ShopCategoryStore($db, 7);
        $id = $store->create('Parts');
        $store->setWcId($id, 11, 'parts');
        $store->delete($id);
        $client = new CategoryExportClient([categoryTerm(11, 'Parts', 'parts', 0, 2)]);
        (new \Xentral\Components\WooCommerce\ShopCategoryExporter($client, $store))->syncTree();
        [$page] = adminFixture([], ['view' => 'categories'], $db);
        $html = $page->render();
        check(str_contains($html, '#11'));
        check(str_contains($html, 'parts'));
        check(str_contains($html, 'WooCommerce meldet die Kategorie nicht als leer.'));
    },
    'category_admin_shows_the_blocked_slug_even_before_the_next_retry' => static function () {
        $db = new AdminDatabaseFake();
        $store = new \Xentral\Components\WooCommerce\ShopCategoryStore($db, 7);
        $id = $store->create('Parts');
        $client = new CategoryExportClient();
        $client->duringPost = static function () use ($store, $id): void {
            $store->delete($id);
            $store->create('Parts');
        };
        (new \Xentral\Components\WooCommerce\ShopCategoryExporter($client, $store))->syncTree();
        [$page] = adminFixture([], ['view' => 'categories'], $db);
        check(str_contains($page->render(), 'Slug parts ist noch durch eine nicht loeschbare WooCommerce-Kategorie belegt'));
    },
    'changed_category_assignment_requires_a_new_preview' => static function () {
        $db = new AdminDatabaseFake();
        Shopimporter_Woocommerce::$fixtureClient = $client = categoryImportClient();
        $_SESSION = [];
        $session = new Session();
        $get = ['view' => 'categories'];
        [$page] = adminFixture([], $get, $db, $session);
        $html = $page->render();
        preg_match('/name="category_preview" value="([^"]+)"/', $html, $preview);
        preg_match('/name="csrf_token" value="([^"]+)"/', $html, $token);
        $client->products[40]['categories'] = [['id' => 11]];
        [$page] = adminFixture(['wc_action' => 'apply_categories', 'csrf_token' => $token[1],
            'category_preview' => $preview[1]], $get, $db, $session);
        check(str_contains($page->render(), 'Vorschau erneut laden'));
        same([], $db->writes);
    },
    'authorized_admin_installs_the_shared_templates_with_one_price_column' => static function () {
        $db = new AdminDatabaseFake();
        [$page] = adminFixture([], [], $db);
        $page->render();
        check(isset($db->templates['exportvorlage'], $db->templates['importvorlage']));
        check(str_contains($db->templates['exportvorlage']['fields'], ' AS standardpreisnetto'));
        check(str_contains($db->templates['importvorlage']['fields'], ':woo_preis_netto;'));
        same('2', $db->values['importvorlage_update_only']);
    },
    'apply_requires_token' => static function () {
        [$page, $db] = adminFixture(['wc_action' => 'apply_pull', 'pull' => [9 => 'shop_name'], 'pull_count' => '1',
            'csrf_token' => 'invalid'], ['view' => 'pull']);
        $html = $page->render();
        check(str_contains($html, 'Formular abgelaufen'));
        same([], $db->writes);
    },
    'apply_requires_a_matching_preview' => static function () {
        [$page, $db] = adminFixture(['wc_action' => 'apply_pull', 'pull' => [9 => 'shop_name'], 'pull_count' => '1'],
            ['view' => 'pull'], new BulkPullDatabase());
        check(str_contains($page->render(), 'Vorschau erneut laden'));
        same([], $db->writes);
    },
    'bulk preview filters fields and POST applies only displayed selections using fresh data' => static function () {
        $db = new BulkPullDatabase();
        $client = Shopimporter_Woocommerce::$fixtureClient = new BulkPullClient();
        $client->pages[2] = $client->pages[1];
        unset($client->pages[1]);
        $client->pages[2][] = ['id' => 102, 'name' => 'Kein Zugriff', 'sku' => 'LOCAL-10'];
        $client->pages[2][] = ['id' => 103, 'name' => 'Interne Artikelnummer', 'sku' => 'LOCAL-9'];
        $db->articles[10]['projekt'] = 55;
        $get = ['view' => 'pull', 'page' => '2', 'filter' => '1', 'groups' => ['SEO']];
        [$page] = adminFixture([], $get, $db);
        $html = $page->render();
        check(str_contains($html, 'data-pull-article="9" value="seo_title"'));
        check(!str_contains($html, 'value="shop_name"'));
        check(!str_contains($html, 'data-pull-article="10"'));
        same(2, substr_count($html, 'kein OpenXE-Artikel'));
        same([], $db->writes);
        preg_match('/name="pull_preview" value="([^"]+)"/', $html, $preview);
        preg_match('/name="csrf_token" value="([^"]+)"/', $html, $token);
        $post = ['wc_action' => 'apply_pull', 'csrf_token' => $token[1], 'pull_preview' => $preview[1]];
        $client->pages[2][0]['meta_data'][0]['value'] = 'SEO aktuell';

        [$page] = adminFixture($post + ['pull' => [9 => 'shop_name'], 'pull_count' => '1'], $get, $db, new Session($_SESSION));
        check(str_contains($page->render(), 'Feld war in der Vorschau nicht ausw'));
        same([], $db->writes);

        [$page] = adminFixture($post + ['pull' => [9 => 'seo_title'], 'pull_count' => '1', 'seo_title' => 'Gefaelschter Wert'],
            $get, $db, new Session($_SESSION));
        check(str_contains($page->render(), '1 Feld'));
        same('SEO aktuell', $db->articles[9]['metatitle_de']);
        same('Alter Name', $db->articles[9]['shop_name_de']);
        same([['products', 2, 100], ['products', 2, 100]], $client->requests);
    },
    'changed WooCommerce product id requires a new preview' => static function () {
        $db = new BulkPullDatabase();
        [$client, $get, $post] = bulkAdminPreview($db);
        $client->pages[1][0]['id'] = 201;
        $client->pages[1][0]['name'] = 'Ersatzprodukt';
        [$page] = adminFixture($post + ['pull' => [9 => 'shop_name'],
            'pull_count' => '1'], $get, $db, new Session($_SESSION));
        $html = $page->render();
        same([], $db->writes);
        check(str_contains($html, 'Shop-Produkt hat sich geaendert - bitte neu vergleichen'));
    },
    'mismatched field count rejects the entire submission' => static function () {
        $db = new BulkPullDatabase();
        [$client, $get, $post] = bulkAdminPreview($db);
        [$page] = adminFixture($post + ['pull' => [9 => 'shop_name'],
            'pull_count' => '2'], $get, $db, new Session($_SESSION));
        $html = $page->render();
        same([], $db->writes);
        check(str_contains($html, 'Auswahl unvollstaendig uebertragen - bitte weniger Artikel auswaehlen'));
    },
    'more than 200 articles rejects the entire submission' => static function () {
        $db = new BulkPullDatabase();
        [, $get, $post] = bulkAdminPreview($db);
        $selection = array_fill_keys(range(9, 209), 'shop_name');
        [$page] = adminFixture($post + ['pull' => $selection, 'pull_count' => '201'],
            $get, $db, new Session($_SESSION));
        $html = $page->render();
        same([], $db->writes);
        check(str_contains($html, 'hoechstens 200 Artikel auswaehlbar'));
    },
    'a SKU that became ambiguous after preview cannot be applied' => static function () {
        $db = new BulkPullDatabase();
        [$client, $get, $post] = bulkAdminPreview($db);
        $client->pages[2] = [['id' => 201, 'name' => 'Zweites Produkt', 'sku' => 'SHOP-9']];
        [$page] = adminFixture($post + ['pull' => [9 => 'shop_name'], 'pull_count' => '1'],
            $get, $db, new Session($_SESSION));
        $html = $page->render();
        same([], $db->writes);
        check(str_contains($html, 'SKU mehrfach'));
        same(['SHOP-9', 'SHOP-9'], $client->lookupRequests);
    },
    'PHP input variable warning rejects even a matching count' => static function () {
        $db = new BulkPullDatabase();
        [$client, $get, $post] = bulkAdminPreview($db);
        [$page] = adminFixture($post + ['pull' => [9 => 'shop_name'],
            'pull_count' => '1'], $get, $db, new Session($_SESSION));
        $maxInputVars = (int) ini_get('max_input_vars');
        @parse_str(str_repeat('unused=1&', $maxInputVars + 1), $unused);
        try {
            $html = $page->render();
            same([], $db->writes);
            check(str_contains($html, 'Auswahl unvollstaendig uebertragen - bitte weniger Artikel auswaehlen'));
        } finally {
            error_clear_last();
        }
    },
    'CSRF rejected before any writes' => static function () {
        [$page, $db] = adminFixture(['wc_action' => 'clear_downloads', 'article' => 9, 'csrf_token' => 'invalid']);
        check(strpos($page->render(), 'Formular abgelaufen') !== false); same([], $db->writes);
    },
    'edit permission and Woo shop enforced' => static function () {
        [$page, $db, $erp] = adminFixture(); $erp->allowed = false;
        check(strpos($page->render(), 'Keine Berechtigung') !== false); same([], $db->writes);
        [$page, $db] = adminFixture(); $db->module = 'shopimporter_other';
        check(strpos($page->render(), 'nicht freigegeben') !== false);
    },
    'foreign article and inaccessible project rejected' => static function () {
        [$page, $db] = adminFixture(['wc_action' => 'clear_downloads', 'article' => 9]); $db->assigned = false;
        check(strpos($page->render(), 'nicht zugeordnet') !== false); same([], $db->writes);
        [$page, $db] = adminFixture(['wc_action' => 'clear_downloads', 'article' => 9]); $db->articleProject = 55;
        check(strpos($page->render(), 'nicht zugeordnet') !== false); same([], $db->writes);
    },
    'save round trip persists URLs unescaped and commits CSRF session' => static function () {
        [$page, $db] = adminFixture(['wc_action' => 'save_downloads', 'article' => 9, 'downloadable' => '1',
            'download_name' => ['Handbuch'], 'download_file' => ['https://example.org/a.pdf?x=1&y=2']]);
        $html = $page->render();
        same(2, count($db->writes));
        check(strpos($db->writes[1][0], 'last_article_hash') !== false);
        $state = json_decode($db->writes[0][1]['wert'], true);
        same('https://example.org/a.pdf?x=1&y=2', $state['downloads'][0]['file']);
        check(strpos($html, 'Downloads gespeichert') !== false);
        $restored = new Session($_SESSION);
        preg_match('/name="csrf_token" value="([^"]+)"/', $html, $matches);
        check($restored->isCsrfTokenValid('woocommerce_admin', $matches[1]));
    },
    'invalid URL preserves draft and never saves' => static function () {
        [$page, $db] = adminFixture(['wc_action' => 'save_downloads', 'article' => 9, 'downloadable' => '1',
            'download_name' => ['Handbuch'], 'download_file' => ['ftp://example.org/file']]);
        $html = $page->render(); same([], $db->writes);
        check(strpos($html, 'role="alert"') !== false);
        check(strpos($html, 'ftp://example.org/file') !== false);
    },
    'all removed rows save an explicit empty download list' => static function () {
        [$page, $db] = adminFixture(['wc_action' => 'save_downloads', 'article' => 9]);
        $page->render();
        same([], json_decode($db->writes[0][1]['wert'], true)['downloads']);
    },
]);
