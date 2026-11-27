<?php
// SPDX-License-Identifier: AGPL-3.0-only
require __DIR__ . '/bootstrap.php';

use Xentral\Components\Http\Session\Session;
use Xentral\Components\WooCommerce\AdminPage;

final class AdminDatabaseFake
{
    public $writes = [];
    public $values = [];
    public $module = 'shopimporter_woocommerce';
    public $articleProject = 0;
    public $assigned = true;
    private $transaction = false;
    public function fetchRow($sql, $params) { return ['id' => 7, 'modulename' => $this->module, 'projekt' => 0, 'einstellungen_json' => '{"felder":{"exportDownloads":1}}']; }
    public function fetchAll($sql, $params) {
        if (strpos($sql, 'FROM artikel') !== false) {
            check(isset($params['shop'], $params['shop2'], $params['shop3'], $params['assigned_shop']));
            return $this->assigned ? [['id' => 9, 'nummer' => 'A9', 'name_de' => 'Artikel', 'projekt' => $this->articleProject]] : [];
        }
        return [];
    }
    public function fetchValue($sql, $params) {
        if (strpos($sql, 'GET_LOCK') !== false || strpos($sql, 'RELEASE_LOCK') !== false) return 1;
        if (strpos($sql, 'COUNT') !== false) return 0;
        return $this->values[$params['name']] ?? false;
    }
    public function perform($sql, $params) {
        $this->writes[] = [$sql, $params];
        if (isset($params['name'])) $this->values[$params['name']] = $params['wert'] ?? $params['value'] ?? '';
    }
    public function inTransaction() { return $this->transaction; }
    public function beginTransaction() { $this->transaction = true; }
    public function commit() { $this->transaction = false; }
    public function rollBack() { $this->transaction = false; }
}

function adminFixture(array $post = [], array $get = [])
{
    $_SESSION = [];
    $db = new AdminDatabaseFake();
    $session = new Session();
    $token = $session->createCsrfToken('woocommerce_admin');
    $secure = new class($get + ['article' => 9], $post + ['csrf_token' => $token]) {
        private $get; private $post;
        public function __construct($get, $post) { $this->get = $get; $this->post = $post; }
        public function GetGET($key, ...$unused) { return $this->get[$key] ?? ''; }
        public function GetPOST($key, ...$unused) { return $this->post[$key] ?? ''; }
    };
    $erp = new class {
        public $allowed = true;
        public function RechteVorhanden($module, $action) { same('onlineshops', $module); same('edit', $action); return $this->allowed; }
        public function UserProjektRecht($id) { return $id === 0; }
    };
    $container = new class($db, $session) {
        private $db; private $session;
        public function __construct($db, $session) { $this->db = $db; $this->session = $session; }
        public function get($key) { return $key === 'Database' ? $this->db : $this->session; }
    };
    $app = (object) ['Container' => $container, 'Secure' => $secure, 'erp' => $erp];
    return [new AdminPage($app, 7), $db, $erp];
}

runTests([
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
