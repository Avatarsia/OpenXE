<?php
// SPDX-License-Identifier: AGPL-3.0-only
namespace Xentral\Components\WooCommerce;

use RuntimeException;
use InvalidArgumentException;
use Shopimporter_Woocommerce;
use Throwable;
use Xentral\Components\Http\Session\Session;
use Xentral\Components\Http\Session\SessionHandler;

/** Reuses onlineshops/edit authorization; every write also checks the session token. */
final class AdminPage
{
    private const INCOMPLETE_PULL = 'Auswahl unvollstaendig uebertragen - bitte weniger Artikel auswaehlen';

    private $app;
    private $db;
    private $shopId;

    public function __construct($app, $shopId)
    {
        $this->app = $app;
        $this->db = $app->Container->get('Database');
        $this->shopId = (int) $shopId;
    }

    public function render()
    {
        try {
            return $this->renderAuthorized();
        } catch (RuntimeException $error) {
            return '<div class="error" role="alert">' . AdminView::e(RefundStatusService::sanitizeError($error->getMessage())) . '</div>';
        }
    }

    private function renderAuthorized()
    {
        if (!$this->app->erp->RechteVorhanden('onlineshops', 'edit')) {
            throw new RuntimeException('Keine Berechtigung zur Shopverwaltung.');
        }
        $shop = $this->db->fetchRow('SELECT id, modulename, projekt, einstellungen_json FROM shopexport WHERE id = :shop', ['shop' => $this->shopId]);
        if (!$shop || $shop['modulename'] !== 'shopimporter_woocommerce' || !$this->app->erp->UserProjektRecht((int) $shop['projekt'])) {
            throw new RuntimeException('WooCommerce-Shop nicht vorhanden oder nicht freigegeben.');
        }
        $session = $this->app->Container->get('Session');
        $view = match ($this->app->Secure->GetGET('view')) {
            'refunds' => 'refunds',
            'pull' => 'pull',
            default => 'downloads',
        };
        $action = $this->app->Secure->GetPOST('wc_action');
        if ($view === 'pull') {
            return $this->navigation($view) . $this->renderPull($session, $action) . '</div>';
        }
        $notice = '';
        $noticeClass = 'info';
        $draft = null;
        $settings = new DownloadSettings($this->db, $this->shopId);
        $refunds = new RefundStatusService($this->db, $this->shopId);
        $articleId = (int) ($action ? $this->app->Secure->GetPOST('article') : $this->app->Secure->GetGET('article'));
        $article = $articleId > 0 ? $this->article($articleId) : null;
        if ($articleId > 0 && !$article) {
            throw new RuntimeException('Artikel ist diesem Shop nicht zugeordnet oder nicht freigegeben.');
        }
        if ($action) {
            $this->requireToken($session);
            try {
                if ($view === 'downloads' && $article && $action === 'save_downloads') {
                    $names = $this->app->Secure->GetPOST('download_name', '', '', 1);
                    $files = $this->app->Secure->GetPOST('download_file', '', '', 1);
                    $names = $names === '' ? [] : $names;
                    $files = $files === '' ? [] : $files;
                    if (!is_array($names) || !is_array($files) || count($names) !== count($files) || count($names) > 50) {
                        throw new RuntimeException('Ung&uuml;ltige Download-Zeilen. Maximal 50 Downloads sind erlaubt.');
                    }
                    $downloads = [];
                    foreach (array_values($names) as $index => $name) {
                        $file = array_values($files)[$index];
                        if (!is_string($name) || !is_string($file)) {
                            throw new RuntimeException('Downloadname und URL m&uuml;ssen Text sein.');
                        }
                        if (trim($name) !== '' || trim($file) !== '') {
                            $downloads[] = ['name' => $name, 'file' => $file];
                        }
                    }
                    $draft = [
                        'downloadable' => $this->app->Secure->GetPOST('downloadable') === '1',
                        'virtual' => $this->app->Secure->GetPOST('virtual') === '1', 'downloads' => $downloads,
                    ];
                    $settings->save($articleId, $draft);
                    $notice = 'Downloads gespeichert. Die n&auml;chste Artikel&uuml;bertragung sendet die Werte an WooCommerce.';
                } elseif ($view === 'downloads' && $article && $action === 'clear_downloads') {
                    $settings->clear($articleId);
                    $notice = 'Lokale Download-Vorgabe entfernt. Der Export verwendet wieder die bisherigen Vorgaben.';
                } elseif ($view === 'refunds' && $action === 'retry_refund') {
                    $orderId = $this->app->Secure->GetPOST('order_id', '', '', 1);
                    $notice = $refunds->retry($orderId) ? 'Abgleich vorgemerkt. Der n&auml;chste Importlauf verarbeitet ihn.' : 'Kein offener Abgleich vorhanden.';
                } else {
                    throw new RuntimeException('Aktion nicht verf&uuml;gbar.');
                }
            } catch (RuntimeException | InvalidArgumentException $error) {
                $noticeClass = 'error';
                $notice = 'Eingabe nicht gespeichert: ' . AdminView::e(RefundStatusService::sanitizeError($error->getMessage()));
            }
        }
        $token = $session->createCsrfToken('woocommerce_admin');
        SessionHandler::commitSession($session);
        $html = $this->navigation($view);
        if ($notice !== '') {
            $html .= '<div class="' . $noticeClass . '" role="' . ($noticeClass === 'error' ? 'alert' : 'status') . '">' . $notice . '</div>';
        }
        if ($view === 'refunds') {
            $html .= AdminView::refunds($this->shopId, $token, $refunds->page((int) $this->app->Secure->GetGET('page')));
        } else {
            $search = $this->app->Secure->GetGET('search', '', '', 1);
            $search = is_string($search) ? substr(trim($search), 0, 100) : '';
            $config = json_decode($shop['einstellungen_json'] ?: '{}', true);
            $html .= AdminView::downloads($this->shopId, $token, $article, $draft ?? ($article ? $settings->load($articleId) : null),
                $search !== '' ? $this->search($search) : [], $search, !empty($config['felder']['exportDownloads']));
        }
        return $html . '</div>';
    }

    private function navigation(string $view): string
    {
        return '<div class="wc-admin"><nav class="wc-admin-nav" aria-label="WooCommerce-Verwaltung">'
            . '<a' . ($view === 'downloads' ? ' aria-current="page"' : '') . ' href="' . AdminView::e(AdminView::url($this->shopId, 'downloads')) . '">Downloads</a> '
            . '<a href="index.php?module=shopimport&amp;action=einzelimport&amp;id=' . $this->shopId . '">Auftrag nachholen</a> '
            . '<a' . ($view === 'refunds' ? ' aria-current="page"' : '') . ' href="' . AdminView::e(AdminView::url($this->shopId, 'refunds')) . '">Refund-Status</a> '
            . '<a' . ($view === 'pull' ? ' aria-current="page"' : '') . ' href="' . AdminView::e(AdminView::url($this->shopId, 'pull')) . '">Artikeldaten abgleichen</a></nav>';
    }

    private function requireToken(Session $session): void
    {
        $token = $this->app->Secure->GetPOST('csrf_token', '', '', 1);
        if (!is_string($token) || !$session->isCsrfTokenValid('woocommerce_admin', $token)) {
            throw new RuntimeException('Formular abgelaufen. Seite neu laden und erneut speichern.');
        }
    }

    private function renderPull(Session $session, $action): string
    {
        $secure = $this->app->Secure;
        $page = max(1, filter_var($secure->GetGET('page'), FILTER_VALIDATE_INT) ?: 1);
        $groupsByKey = [];
        foreach (ArticleFieldCatalog::fields() as $field) {
            if ($field['pull']) {
                $groupsByKey[$field['key']] = $field['group'];
            }
        }
        $groups = array_values(array_unique($groupsByKey));
        $submitted = $secure->GetGET('groups', '', '', 1);
        $selectedGroups = $secure->GetGET('filter') === '1'
            ? array_values(array_filter($groups, static fn (string $group): bool =>
                is_array($submitted) && in_array($group, $submitted, true)))
            : $groups;
        $sessionKey = 'pull_' . $this->shopId;

        if ($action || ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $lastError = error_get_last();
            if ((defined('OPENXE_INPUT_VARS_TRUNCATED') && OPENXE_INPUT_VARS_TRUNCATED)
                || str_contains($lastError['message'] ?? '', 'Input variables exceeded')
                || !$action) {
                throw new RuntimeException(self::INCOMPLETE_PULL);
            }
            $this->requireToken($session);
            if ($action !== 'apply_pull' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                throw new RuntimeException('Aktion nicht verfuegbar.');
            }
            $preview = $session->getValue('woocommerce_admin', $sessionKey);
            $nonce = $secure->GetPOST('pull_preview', '', '', 1);
            if (!is_string($nonce) || !isset($preview['articles']) || $preview['page'] !== $page
                || !hash_equals($preview['nonce'], $nonce)) {
                throw new RuntimeException('Vorschau erneut laden und Felder auswaehlen.');
            }
            $selection = $this->pullSelection();
            $accepted = $identities = $results = [];
            foreach ($selection as $id => $keys) {
                $allowed = $preview['articles'][$id] ?? null;
                if ($allowed === null || array_diff_key(array_fill_keys($keys, true), $allowed['fields']) !== []) {
                    $results[$id] = ['error' => 'Feld war in der Vorschau nicht auswaehlbar.'];
                    continue;
                }
                $accepted[$id] = $keys;
                $identities[$id] = $allowed['identity'];
            }
            if ($accepted !== []) {
                $session->removeValue('woocommerce_admin', $sessionKey);
                SessionHandler::commitSession($session);
                try {
                    $results += $this->bulkPull()->apply($accepted, 'bulk_pull', $page, $identities);
                } catch (Throwable $error) {
                    $this->logPullError($error);
                    foreach ($accepted as $id => $keys) {
                        $results[$id] = ['error' => 'Aktuelle WooCommerce-Artikeldaten konnten nicht geladen werden.'];
                    }
                }
            }
            return AdminView::pullResults($this->shopId, $page, $selectedGroups, $results);
        }

        try {
            $data = $this->bulkPull()->compare($page);
        } catch (Throwable $error) {
            $this->logPullError($error);
            throw new RuntimeException('WooCommerce-Artikeldaten konnten nicht verglichen werden.');
        }
        $articles = [];
        foreach ($data['articles'] as $id => &$article) {
            $article['rows'] = array_filter($article['rows'], static fn (array $row): bool =>
                in_array($groupsByKey[$row['key']], $selectedGroups, true));
            foreach ($article['rows'] as $row) {
                if ($row['pullable']) {
                    $articles[$id]['identity'] = $article['identity'];
                    $articles[$id]['fields'][$row['key']] = true;
                }
            }
        }
        unset($article);
        $nonce = bin2hex(random_bytes(16));
        $session->setValue('woocommerce_admin', $sessionKey, ['nonce' => $nonce, 'page' => $page, 'articles' => $articles]);
        $token = $session->createCsrfToken('woocommerce_admin');
        SessionHandler::commitSession($session);

        return AdminView::pull($this->shopId, $token, $nonce, $page, $data, $groups, $selectedGroups);
    }

    private function pullSelection(): array
    {
        $secure = $this->app->Secure;
        $submitted = $secure->GetPOST('pull', '', '', 1);
        $expectedCount = filter_var($secure->GetPOST('pull_count', '', '', 1), FILTER_VALIDATE_INT);
        $submitted = $submitted === '' ? [] : $submitted;
        if (!is_array($submitted) || $expectedCount === false) {
            throw new RuntimeException(self::INCOMPLETE_PULL);
        }
        if (count($submitted) > BulkPull::MAX_SELECTION) {
            throw new RuntimeException('Pro Uebernahme sind hoechstens ' . BulkPull::MAX_SELECTION . ' Artikel auswaehlbar.');
        }
        $selection = [];
        $count = 0;
        foreach ($submitted as $id => $keys) {
            if (!is_int($id) || $id <= 0 || !is_string($keys) || !preg_match('/^[a-z_]+(?:,[a-z_]+)*$/D', $keys)) {
                throw new RuntimeException(self::INCOMPLETE_PULL);
            }
            $selection[$id] = array_values(array_unique(explode(',', $keys)));
            $count += count($selection[$id]);
        }
        if ($count !== $expectedCount) {
            throw new RuntimeException(self::INCOMPLETE_PULL);
        }
        if ($selection === []) {
            throw new RuntimeException('Bitte mindestens ein Feld auswaehlen.');
        }
        return $selection;
    }

    private function bulkPull(): BulkPull
    {
        if (!class_exists(Shopimporter_Woocommerce::class, false)) {
            require_once dirname(__DIR__, 3) . '/www/pages/shopimporter_woocommerce.php';
        }
        $connector = Shopimporter_Woocommerce::clientForShop($this->app, $this->shopId);
        return new BulkPull(
            new ArticleDataRepository($this->app, $this->shopId),
            new WooProductReader($connector->client, $connector->deliveryTimeMap()),
            $this->articleForSku(...)
        );
    }

    private function articleForSku(string $sku): ?array
    {
        $rows = $this->db->fetchAll(
            'SELECT a.id, a.nummer, a.name_de, a.projekt FROM artikel AS a WHERE a.geloescht = 0 '
            . 'AND EXISTS (SELECT 1 FROM artikel_onlineshops AS ao WHERE ao.artikel = a.id '
            . 'AND ao.shop = :assigned_shop AND ao.aktiv = 1) '
            . 'AND CAST(TRIM(COALESCE((SELECT fn.nummer FROM artikelnummer_fremdnummern AS fn '
            . 'WHERE fn.artikel = a.id AND fn.shopid = :shop AND fn.aktiv = 1 AND fn.nummer <> \'\' '
            . "ORDER BY fn.bezeichnung = 'SHOPID' DESC LIMIT 1), a.nummer)) AS BINARY) = CAST(:sku AS BINARY) LIMIT 2",
            ['assigned_shop' => $this->shopId, 'shop' => $this->shopId, 'sku' => $sku]
        );
        if (count($rows) !== 1 || !$this->app->erp->UserProjektRecht((int) $rows[0]['projekt'])) {
            return null;
        }
        return $rows[0];
    }

    private function logPullError(Throwable $error): void
    {
        $this->app->Container->get('Logger')->error(
            'WooCommerce-Artikeldatenabgleich fehlgeschlagen: ' . RefundStatusService::sanitizeError($error->getMessage())
        );
    }

    private function article($id)
    {
        $rows = $this->findArticles('a.id = :article', ['article' => $id]);
        return $rows[0] ?? null;
    }

    private function search($search)
    {
        return $this->findArticles('(a.nummer LIKE :number OR a.name_de LIKE :name)', ['number' => '%' . $search . '%', 'name' => '%' . $search . '%']);
    }

    private function findArticles($where, array $params)
    {
        $rows = $this->db->fetchAll(
            'SELECT a.id, a.nummer, a.name_de, a.projekt FROM artikel AS a WHERE a.geloescht = 0 '
            . 'AND (a.shop = :shop OR a.shop2 = :shop2 OR a.shop3 = :shop3 OR EXISTS '
            . '(SELECT 1 FROM artikel_onlineshops AS ao WHERE ao.artikel = a.id AND ao.shop = :assigned_shop AND ao.aktiv = 1)) '
            . 'AND ' . $where . ' ORDER BY a.nummer, a.id LIMIT 50',
            $params + ['shop' => $this->shopId, 'shop2' => $this->shopId, 'shop3' => $this->shopId, 'assigned_shop' => $this->shopId]
        );
        return array_values(array_filter($rows, function ($row) {
            return $this->app->erp->UserProjektRecht((int) $row['projekt']);
        }));
    }
}
