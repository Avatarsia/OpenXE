<?php
// SPDX-License-Identifier: AGPL-3.0-only

namespace Xentral\Components\WooCommerce;

use DomainException;
use InvalidArgumentException;
use LengthException;
use RuntimeException;
use Throwable;
use Xentral\Components\Http\Session\SessionHandler;

final class AssignmentPage
{
    private const URL = 'index.php?module=artikel&action=woocommerce';
    private const PAGE_SIZE = 50;
    private $db;

    public function __construct(private $app)
    {
        $this->db = $app->Container->get('Database');
    }

    public function render(): string
    {
        try {
            $this->requireRights();
            return $this->renderAuthorized();
        } catch (Throwable $error) {
            return '<div class="error" role="alert">' . AdminView::e($this->errorMessage($error)) . '</div>';
        }
    }

    private function renderAuthorized(): string
    {
        $shops = $this->shops();
        if ($shops === []) {
            return '<div class="info">Kein aktiver WooCommerce-Shop verf&uuml;gbar.</div>';
        }
        $secure = $this->app->Secure;
        $shopId = (int) $secure->GetGET('shop');
        $shopId = $shopId === 0 ? array_key_first($shops) : $shopId;
        $shop = $shops[$shopId] ?? null;
        if ($shop === null) {
            throw new DomainException('WooCommerce-Shop nicht vorhanden oder nicht freigegeben.');
        }

        $store = new ShopCategoryStore($this->db, $shopId);
        $tree = $store->tree();
        $categories = $this->categoryOptions($tree);
        $category = (int) $secure->GetGET('category');
        if (!isset($categories[$category])) {
            $category = 0;
        }
        $onlyUnassigned = $secure->GetGET('unassigned') === '1';
        $articles = $this->articles($store, $shopId, $category, $onlyUnassigned);
        $lastPage = max(1, (int) ceil(count($articles) / self::PAGE_SIZE));
        $page = max(1, min($lastPage, (int) $secure->GetGET('page')));
        $rows = array_slice($articles, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE);
        $url = self::URL . '&shop=' . $shopId;
        $categoryUrl = $url . '&category=' . $category;
        $viewUrl = $categoryUrl . ($onlyUnassigned ? '&unassigned=1' : '');
        $session = $this->app->Container->get('Session');
        $token = $session->createCsrfToken('woocommerce_admin');
        SessionHandler::commitSession($session);

        $shopOptions = '';
        foreach ($shops as $id => $option) {
            $shopOptions .= '<option value="' . $id . '"' . ($id === $shopId ? ' selected' : '') . '>'
                . AdminView::e($option['bezeichnung']) . '</option>';
        }
        $shopPicker = count($shops) > 1
            ? '<form class="wc-admin-search" method="get" action="index.php"><input type="hidden" name="module" value="artikel">'
                . '<input type="hidden" name="action" value="woocommerce"><label for="wc-assignment-shop">Shop</label>'
                . '<select id="wc-assignment-shop" name="shop">' . $shopOptions . '</select>'
                . '<button type="submit">Anzeigen</button></form>'
            : '<p>Shop: ' . AdminView::e($shop['bezeichnung']) . '</p>';
        $categoryNotice = empty($this->settings($shop)['shopCategoryTree'])
            ? '<p class="info">Der Export des Shop-Kategorienbaums ist in den Shop-Einstellungen deaktiviert.</p>' : '';

        $categoryLabel = $category === 0 ? 'Artikel ohne Kategorie'
            : 'Artikel in &bdquo;' . AdminView::e(ltrim($categories[$category]['label'])) . '&ldquo;';
        $treeUrl = $url . ($onlyUnassigned ? '&unassigned=1' : '');
        $errors = $this->categoryErrors($store);
        $filter = '<a href="' . AdminView::e($categoryUrl . ($onlyUnassigned ? '' : '&unassigned=1')) . '">'
            . ($onlyUnassigned ? 'Alle Artikel anzeigen' : 'Nur Artikel anzeigen, die nicht im Shop sind') . '</a>';
        $pagination = '';
        if ($lastPage > 1) {
            $pagination = '<nav class="wc-admin-pages" aria-label="Artikelseiten">'
                . ($page > 1 ? '<a href="' . AdminView::e($viewUrl . '&page=' . ($page - 1)) . '">Zur&uuml;ck</a>' : '')
                . '<span>Seite ' . $page . ' von ' . $lastPage . '</span>'
                . ($page < $lastPage ? '<a href="' . AdminView::e($viewUrl . '&page=' . ($page + 1)) . '">Weiter</a>' : '')
                . '</nav>';
        }

        return '<div class="wc-admin wc-assignment" data-shop="' . $shopId . '" data-category="' . $category . '" data-url="'
            . AdminView::e($url) . '" data-unassigned="' . (int) $onlyUnassigned . '" data-token="' . AdminView::e($token) . '">'
            . $shopPicker . $categoryNotice . '<p class="error" role="alert" data-wc-assignment-error hidden></p>'
            . '<p class="info" role="status" data-wc-assignment-status hidden></p>'
            . '<div class="wc-assignment-layout"><section><h2>Shop-Kategorien</h2>'
            . $this->treeLink($treeUrl, 0, $category, 'ohne Kategorie') . $this->treeLinks($tree, $treeUrl, $category)
            . $this->categoryControls($categories, $category) . $errors . '</section>'
            . '<section><h2>' . $categoryLabel . '</h2><div class="wc-admin-actions">' . $filter . '</div>'
            . ($category === 0 ? '' : '<form class="wc-admin-search" data-command="cat_assign">'
                . '<label for="wc-assignment-article">Artikelnr.</label><input id="wc-assignment-article" name="number" required>'
                . '<button type="submit">+ Artikel zuordnen</button></form>')
            . $this->articleTable($store, $rows, $category) . $pagination . '</section></div></div>';
    }

    private function categoryOptions(array $nodes, int $depth = 0): array
    {
        $categories = [];
        foreach ($nodes as $node) {
            $categories[$node['id']] = ['label' => str_repeat('  ', $depth) . $node['name']] + $node;
            $categories += $this->categoryOptions($node['children'], $depth + 1);
        }
        return $categories;
    }

    private function treeLink(string $url, int $id, int $selected, string $label): string
    {
        return '<a href="' . AdminView::e($url . '&category=' . $id) . '"'
            . ($id === $selected ? ' aria-current="page"' : '') . '>' . AdminView::e($label) . '</a>';
    }

    private function treeLinks(array $nodes, string $url, int $selected): string
    {
        if ($nodes === []) {
            return '';
        }
        $html = '<ul class="wc-assignment-tree">';
        foreach ($nodes as $node) {
            $html .= '<li>' . $this->treeLink($url, $node['id'], $selected, $node['name'])
                . $this->treeLinks($node['children'], $url, $selected) . '</li>';
        }
        return $html . '</ul>';
    }

    private function categoryControls(array $categories, int $selected): string
    {
        $nameField = static fn (string $id, string $name): string => '<label for="' . $id . '">Name</label>'
            . '<input id="' . $id . '" name="name" maxlength="200" value="' . AdminView::e($name) . '" required>';
        $parentField = static function (string $id, int $parent, int $exclude) use ($categories): string {
            $html = '<label for="' . $id . '">Elternkategorie</label><select id="' . $id
                . '" name="parent"><option value="0">Oberste Ebene</option>';
            foreach ($categories as $category => $node) {
                for ($ancestor = $category; $ancestor !== 0; $ancestor = $categories[$ancestor]['parent']) {
                    if ($ancestor === $exclude) {
                        continue 2;
                    }
                }
                $html .= '<option value="' . $category . '"' . ($category === $parent ? ' selected' : '') . '>'
                    . str_replace('  ', '&nbsp;&nbsp;', AdminView::e($node['label'])) . '</option>';
            }
            return $html . '</select>';
        };

        $create = '<details><summary>Neu</summary><form class="wc-assignment-fields" data-command="cat_create">'
            . $nameField('wc-assignment-create-name', '') . $parentField('wc-assignment-create-parent', $selected, 0)
            . '<button type="submit">Kategorie anlegen</button></form></details>';
        if ($selected === 0) {
            return $create;
        }
        $node = $categories[$selected];
        return '<div class="wc-assignment-controls">' . $create
            . '<details><summary>Umbenennen</summary><form class="wc-assignment-fields" data-command="cat_rename">'
            . $nameField('wc-assignment-rename-name', $node['name'])
            . '<button type="submit">Namen speichern</button></form></details>'
            . '<details><summary>Verschieben</summary><form class="wc-assignment-fields" data-command="cat_move">'
            . $parentField('wc-assignment-move-parent', $node['parent'], $selected)
            . '<label for="wc-assignment-sort">Sortierwert (aufsteigend)</label>'
            . '<input id="wc-assignment-sort" type="number" name="sort" min="0" required value="' . $node['sort'] . '">'
            . '<button type="submit">Kategorie verschieben</button></form></details>'
            . '<button type="button" data-command="cat_delete">L&ouml;schen</button></div>';
    }

    private function categoryErrors(ShopCategoryStore $store): string
    {
        $html = '';
        foreach ($store->skipReasons() as $id => $reason) {
            $html .= '<p class="warning" role="status">Kategorie ' . (int) $id . ': ' . AdminView::e($reason) . '</p>';
        }
        $reasons = $store->pendingDeletionReasons();
        foreach ($store->pendingDeletions() as $id => $slug) {
            $html .= '<p class="warning" role="status">Shop-L&ouml;schung vorgemerkt: ' . AdminView::e($slug)
                . (isset($reasons[$id]) ? ' &ndash; ' . AdminView::e($reasons[$id]) : '') . '</p>';
        }
        return $html;
    }

    private function articles(ShopCategoryStore $store, int $shopId, int $category, bool $onlyUnassigned): array
    {
        $membership = 'EXISTS (SELECT 1 FROM artikel_onlineshops AS ao WHERE ao.artikel = a.id AND ao.shop = :shop AND ao.aktiv = 1)';
        $sql = 'SELECT a.id, a.nummer, a.name_de, a.projekt, a.ausverkauft, ' . $membership
            . ' AS in_shop FROM artikel AS a WHERE a.geloescht = 0';
        if ($category !== 0) {
            $ids = $store->articlesIn($category);
            if ($ids === []) {
                return [];
            }
            // The store supplies only positive integer article IDs.
            $sql .= ' AND a.id IN (' . implode(',', $ids) . ')';
        }
        $rows = $this->db->fetchAll($sql . ($onlyUnassigned ? ' HAVING in_shop = 0' : '') . ' ORDER BY nummer, id',
            ['shop' => $shopId]);
        $ids = $category === 0 ? array_fill_keys($store->articlesWithoutCategory(array_map('intval', array_column($rows, 'id'))), true)
            : null;
        $allowed = [];
        return array_values(array_filter($rows, function (array $row) use ($ids, &$allowed): bool {
            if ($ids !== null && !isset($ids[$row['id']])) {
                return false;
            }
            $project = (int) $row['projekt'];
            return $allowed[$project] ??= $this->app->erp->UserProjektRecht($project);
        }));
    }

    private function articleTable(ShopCategoryStore $store, array $rows, int $category): string
    {
        if ($rows === []) {
            return '<p class="info">Keine Artikel in dieser Auswahl.</p>';
        }
        $columns = [
            'toggle_shop' => 'im Shop',
            'toggle_soldout' => 'nicht mehr lieferbar',
            'toggle_onrequest' => 'auf Nachfrage lieferbar',
        ];
        $html = '<div class="wc-admin-scroll" role="region" aria-label="Shop-Artikel" tabindex="0">'
            . '<table class="wc-assignment-table"><thead><tr><th scope="col">Nr.</th><th scope="col">Name</th>';
        foreach ($columns as $label) {
            $html .= '<th scope="col">' . $label . '</th>';
        }
        $html .= $category === 0 ? '</tr></thead><tbody>' : '<th scope="col">Kategorie</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $checked = [(bool) $row['in_shop'], (bool) $row['ausverkauft'], $store->isOnRequest($id)];
            $number = AdminView::e($row['nummer']);
            $html .= '<tr data-article="' . $id . '"><th scope="row">' . $number . '</th><td>' . AdminView::e($row['name_de']) . '</td>';
            foreach ($columns as $cmd => $label) {
                $html .= '<td><input type="checkbox" data-command="' . $cmd . '" aria-label="' . $label . ' (' . $number . ')"'
                    . (array_shift($checked) ? ' checked' : '') . '></td>';
            }
            if ($category !== 0) {
                $html .= '<td><button type="button" data-command="cat_unassign" aria-label="Kategoriezuordnung f&uuml;r '
                    . $number . ' entfernen">Entfernen</button></td>';
            }
            $html .= '</tr>';
        }
        return $html . '</tbody></table></div>';
    }


    public function handle(string $cmd, array $post): array
    {
        try {
            $this->requireRights();
            $token = $post['csrf_token'] ?? null;
            if (!is_string($token) || !$this->app->Container->get('Session')->isCsrfTokenValid('woocommerce_admin', $token)) {
                throw new DomainException('Formular abgelaufen. Seite neu laden und erneut speichern.');
            }
            $shopId = $this->integer($post['shop'] ?? null, 1);
            $shop = $this->shops()[$shopId] ?? null;
            if ($shop === null) {
                throw new DomainException('WooCommerce-Shop nicht vorhanden oder nicht freigegeben.');
            }

            $store = new ShopCategoryStore($this->db, $shopId);
            $this->db->beginTransaction();
            try {
                $result = match ($cmd) {
                    'cat_create', 'cat_rename', 'cat_move', 'cat_delete' => $this->editTree($store, $shopId, $cmd, $post),
                    'toggle_shop', 'toggle_soldout', 'toggle_onrequest', 'cat_assign', 'cat_unassign'
                        => $this->editArticle($store, $shop, $cmd, $post),
                    default => throw new DomainException('Unbekannte Aktion.'),
                };
                $this->db->commit();
            } catch (Throwable $error) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $error;
            }
            return ['ok' => true, 'error' => null] + $result;
        } catch (Throwable $error) {
            return ['ok' => false, 'error' => $this->errorMessage($error)];
        }
    }

    private function editTree(ShopCategoryStore $store, int $shopId, string $cmd, array $post): array
    {
        if ($cmd === 'cat_create') {
            return ['category' => $store->create($this->name($post), $this->integer($post['parent'] ?? null, 0))];
        }
        $category = $this->integer($post['category'] ?? null, 1);
        if ($cmd === 'cat_delete') {
            $store->delete($category);
            return ['category' => 0];
        }
        if ($cmd === 'cat_rename') {
            $store->rename($category, $this->name($post));
        } else {
            $store->move($category, $this->integer($post['parent'] ?? null, 0), $this->integer($post['sort'] ?? null, 0));
        }
        foreach ($store->articlesIn($category) as $articleId) {
            $this->invalidateExport($shopId, $articleId);
        }
        return ['category' => $category];
    }

    private function editArticle(ShopCategoryStore $store, array $shop, string $cmd, array $post): array
    {
        $shopId = (int) $shop['id'];
        $number = $post['number'] ?? '';
        if ($cmd === 'cat_assign' && is_string($number) && trim($number) !== '') {
            $matches = $this->db->fetchAll(
                'SELECT id, projekt FROM artikel WHERE nummer = :number AND geloescht = 0 AND intern_gesperrt != 1 LIMIT 2 FOR UPDATE',
                ['number' => trim($number)]
            );
            if (count($matches) !== 1) {
                throw new DomainException('Artikelnr. nicht eindeutig. Bitte eine eindeutige Artikelnr. eingeben.');
            }
            $article = $matches[0];
        } else {
            $article = $this->db->fetchRow(
                'SELECT id, projekt FROM artikel WHERE id = :article AND geloescht = 0 FOR UPDATE',
                ['article' => $this->integer($post['article'] ?? null, 1)]
            );
        }
        if (!$article || !$this->app->erp->UserProjektRecht((int) $article['projekt'])) {
            throw new DomainException('Artikel nicht vorhanden oder nicht freigegeben.');
        }

        $id = (int) $article['id'];
        $enabled = str_starts_with($cmd, 'toggle_') ? $this->integer($post['enabled'] ?? null, 0, 1) : 0;
        switch ($cmd) {
            case 'toggle_shop':
                $this->toggleShop($shopId, $id, $enabled, !empty($this->settings($shop)['visibilitySync']));
                break;
            case 'toggle_soldout':
                $this->db->perform('UPDATE artikel SET ausverkauft = :enabled WHERE id = :article',
                    ['enabled' => $enabled, 'article' => $id]);
                break;
            case 'toggle_onrequest':
                $store->setOnRequest($id, (bool) $enabled);
                break;
            default:
                $category = $this->integer($post['category'] ?? null, 1);
                $categories = $store->categoriesOf($id);
                $store->assign($id, $cmd === 'cat_assign' ? [...$categories, $category] : array_diff($categories, [$category]));
                break;
        }
        $this->invalidateExport($shopId, $id);
        return ['article' => $id];
    }

    private function toggleShop(int $shopId, int $articleId, int $enabled, bool $visibilitySync): void
    {
        $params = ['shop' => $shopId, 'article' => $articleId];
        // Lock the assignment before the visibility queue, in the same order as its drain.
        $assignment = $this->db->fetchRow(
            'SELECT id, aktiv FROM artikel_onlineshops WHERE artikel = :article AND shop = :shop'
            . ' ORDER BY aktiv DESC, id LIMIT 1 FOR UPDATE',
            $params
        );
        $queue = new VisibilityQueue($this->db, $shopId);
        if ($assignment && $enabled === 0) {
            $this->db->perform('UPDATE artikel_onlineshops SET aktiv = :enabled WHERE artikel = :article AND shop = :shop',
                $params + ['enabled' => 0]);
            if ($visibilitySync && (int) $assignment['aktiv'] === 1) {
                $sku = $this->db->fetchValue(
                    'SELECT ' . ArticleDataRepository::SHOP_SKU_SQL . ' FROM artikel AS a WHERE a.id = :article',
                    $params
                );
                $queue->enqueue($articleId, (string) $sku);
            }
        } elseif ($enabled === 1) {
            if ($assignment) {
                $this->db->perform('UPDATE artikel_onlineshops SET aktiv = :enabled WHERE id = :id',
                    ['enabled' => 1, 'id' => (int) $assignment['id']]);
            } else {
                $this->db->perform(
                    'INSERT INTO artikel_onlineshops (artikel, shop, aktiv, pseudolager, lieferzeitmanuell, last_article_hash)'
                    . " VALUES (:article, :shop, 1, '', '', '')",
                    $params
                );
            }
            $queue->cancel($articleId);
        }
    }

    private function invalidateExport(int $shopId, int $articleId): void
    {
        // The normal and matrix variant payloads are exported through their parent article.
        $this->db->perform(
            "UPDATE artikel_onlineshops SET last_article_hash = '' WHERE shop = :shop "
            . 'AND (artikel = :article OR artikel = (SELECT variante_von FROM artikel WHERE id = :source))',
            ['shop' => $shopId, 'article' => $articleId, 'source' => $articleId]
        );
    }

    private function shops(): array
    {
        $shops = [];
        foreach ($this->db->fetchAll(
            "SELECT id, bezeichnung, projekt, einstellungen_json FROM shopexport WHERE aktiv = 1 AND geloescht = 0"
            . " AND modulename = 'shopimporter_woocommerce' ORDER BY bezeichnung, id"
        ) as $shop) {
            if ($this->app->erp->UserProjektRecht((int) $shop['projekt'])) {
                $shops[(int) $shop['id']] = $shop;
            }
        }
        return $shops;
    }

    private function settings(array $shop): array
    {
        $settings = json_decode((string) $shop['einstellungen_json'], true)['felder'] ?? null;
        return is_array($settings) ? $settings : [];
    }

    private function requireRights(): void
    {
        if (!$this->app->erp->RechteVorhanden('artikel', 'edit') || !$this->app->erp->RechteVorhanden('onlineshops', 'edit')) {
            throw new DomainException('Keine Berechtigung zur Artikel- und Shopbearbeitung.');
        }
    }

    private function integer(mixed $value, int $min, int $max = PHP_INT_MAX): int
    {
        $number = is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => $min, 'max_range' => $max]]) : false;
        if ($number === false) {
            throw new DomainException('Ungueltige Eingabe.');
        }
        return $number;
    }

    private function name(array $post): string
    {
        $raw = $post['name'] ?? '';
        $name = is_string($raw) ? trim($raw) : '';
        if (!preg_match('/^.{1,200}$/usD', $name)) {
            throw new DomainException('Bitte einen Kategorienamen mit 1 bis 200 Zeichen eingeben.');
        }
        return $name;
    }

    private function errorMessage(Throwable $error): string
    {
        if ($error instanceof DomainException || $error instanceof InvalidArgumentException || $error instanceof LengthException
            || ($error instanceof RuntimeException && $error->getMessage() === 'Kategorie ist nicht leer.')) {
            return $error->getMessage();
        }
        $this->app->Container->get('Logger')->error('WooCommerce-Shopzuordnung: ' . RefundStatusService::sanitizeError($error->getMessage()));
        return 'Die WooCommerce-Shopzuordnung konnte nicht verarbeitet werden.';
    }
}
