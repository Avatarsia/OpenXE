<?php
// SPDX-License-Identifier: AGPL-3.0-only
namespace Xentral\Components\WooCommerce;

use RuntimeException;
use InvalidArgumentException;
use Xentral\Components\Http\Session\SessionHandler;

/** Reuses onlineshops/edit authorization; every write also checks the session token. */
final class AdminPage
{
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
        $view = $this->app->Secure->GetGET('view') === 'refunds' ? 'refunds' : 'downloads';
        $action = $this->app->Secure->GetPOST('wc_action');
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
            $token = $this->app->Secure->GetPOST('csrf_token', '', '', 1);
            if (!is_string($token) || !$session->isCsrfTokenValid('woocommerce_admin', $token)) {
                throw new RuntimeException('Formular abgelaufen. Seite neu laden und erneut speichern.');
            }
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
        $html = '<div class="wc-admin"><nav class="wc-admin-nav" aria-label="WooCommerce-Verwaltung">'
            . '<a' . ($view === 'downloads' ? ' aria-current="page"' : '') . ' href="' . AdminView::e(AdminView::url($this->shopId, 'downloads')) . '">Downloads</a> '
            . '<a href="index.php?module=shopimport&amp;action=einzelimport&amp;id=' . $this->shopId . '">Auftrag nachholen</a> '
            . '<a' . ($view === 'refunds' ? ' aria-current="page"' : '') . ' href="' . AdminView::e(AdminView::url($this->shopId, 'refunds')) . '">Refund-Status</a></nav>';
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
