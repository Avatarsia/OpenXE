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
    private const EXPORT_INVALIDATION_WARNING = 'Kategorie gespeichert. Die Aktualisierung der Artikel-Export-Hashes steht noch aus.';
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
        [$rows, $page, $lastPage] = $this->articles($store, $shopId, $category, $onlyUnassigned, (int) $secure->GetGET('page'));
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
            ? '<form class="wc-admin-search wc-assignment-shop-form" method="get" action="index.php"><input type="hidden" name="module" value="artikel">'
                . '<input type="hidden" name="action" value="woocommerce"><label for="wc-assignment-shop">Shop</label>'
                . '<select id="wc-assignment-shop" name="shop">' . $shopOptions . '</select>'
                . '<button type="submit" class="wc-btn wc-btn-secondary">Anzeigen</button></form>'
            : '<p class="wc-assignment-shop-label">Shop: <strong>' . AdminView::e($shop['bezeichnung']) . '</strong></p>';
        $categoryNotice = empty($this->settings($shop)['shopCategoryTree'])
            ? '<p class="info">Der Export des Shop-Kategorienbaums ist in den Shop-Einstellungen deaktiviert.</p>' : '';

        $categoryLabel = $category === 0 ? 'Artikel ohne Kategorie'
            : 'Artikel in &bdquo;' . AdminView::e(ltrim($categories[$category]['label'])) . '&ldquo;';
        $treeUrl = $url . ($onlyUnassigned ? '&unassigned=1' : '');
        $errors = $this->categoryErrors($store);
        $filter = '<a class="wc-btn wc-btn-secondary wc-assignment-filter' . ($onlyUnassigned ? ' is-active' : '') . '" href="'
            . AdminView::e($categoryUrl . ($onlyUnassigned ? '' : '&unassigned=1')) . '">'
            . ($onlyUnassigned ? 'Alle Artikel anzeigen' : 'Nur Artikel anzeigen, die nicht im Shop sind') . '</a>';
        $pagination = '';
        if ($lastPage > 1) {
            $pagination = '<nav class="wc-admin-pages" aria-label="Artikelseiten">'
                . ($page > 1 ? '<a class="wc-btn wc-btn-secondary" href="' . AdminView::e($viewUrl . '&page=' . ($page - 1)) . '">Zur&uuml;ck</a>' : '')
                . '<span>Seite ' . $page . ' von ' . $lastPage . '</span>'
                . ($page < $lastPage ? '<a class="wc-btn wc-btn-secondary" href="' . AdminView::e($viewUrl . '&page=' . ($page + 1)) . '">Weiter</a>' : '')
                . '</nav>';
        }

        return '<div class="wc-admin wc-assignment" data-shop="' . $shopId . '" data-category="' . $category . '" data-url="'
            . AdminView::e($url) . '" data-unassigned="' . (int) $onlyUnassigned . '" data-token="' . AdminView::e($token) . '">'
            . '<div class="wc-assignment-header">' . $shopPicker . '</div>'
            . $categoryNotice . '<p class="error" role="alert" data-wc-assignment-error hidden></p>'
            . '<p class="info" role="status" data-wc-assignment-status hidden></p>'
            . '<div class="wc-assignment-layout">'
            . '<section class="wc-assignment-sidebar"><h2>Shop-Kategorien</h2>'
            . '<nav class="wc-assignment-tree-nav" aria-label="Shop-Kategorien">'
            . '<div class="wc-assignment-tree-root">' . $this->treeLink($treeUrl, 0, $category, 'ohne Kategorie') . '</div>'
            . $this->treeLinks($tree, $treeUrl, $category)
            . '</nav>'
            . $this->categoryControls($categories, $category) . $errors . '</section>'
            . '<section class="wc-assignment-main">'
            . '<div class="wc-assignment-toolbar"><h2>' . $categoryLabel . '</h2>'
            . '<div class="wc-admin-actions">' . $filter . '</div></div>'
            . ($category === 0 ? '' : '<form class="wc-admin-search wc-assignment-assign-form" data-command="cat_assign">'
                . '<label for="wc-assignment-article">Artikelnr.</label><input id="wc-assignment-article" name="number" placeholder="Artikelnummer eingeben" required>'
                . '<button type="submit" class="wc-btn wc-btn-primary">+ Artikel zuordnen</button></form>')
            . $this->articleTable($store, $rows, $category, $categories) . $pagination . '</section></div></div>';
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

        $create = '<details class="wc-assignment-panel"' . ($categories === [] ? ' open' : '') . '>'
            . '<summary class="wc-assignment-summary wc-assignment-summary--primary">+ Neue Kategorie</summary>'
            . '<form class="wc-assignment-fields" data-command="cat_create">'
            . $nameField('wc-assignment-create-name', '') . $parentField('wc-assignment-create-parent', $selected, 0)
            . '<button type="submit" class="wc-btn wc-btn-primary">Kategorie anlegen</button></form></details>';
        if ($selected === 0) {
            return '<div class="wc-assignment-controls">' . $create . '</div>';
        }
        $node = $categories[$selected];
        return '<div class="wc-assignment-controls">' . $create
            . '<details class="wc-assignment-panel"><summary class="wc-assignment-summary">Umbenennen</summary><form class="wc-assignment-fields" data-command="cat_rename">'
            . $nameField('wc-assignment-rename-name', $node['name'])
            . '<button type="submit" class="wc-btn wc-btn-secondary">Namen speichern</button></form></details>'
            . '<details class="wc-assignment-panel"><summary class="wc-assignment-summary">Verschieben</summary><form class="wc-assignment-fields" data-command="cat_move">'
            . $parentField('wc-assignment-move-parent', $node['parent'], $selected)
            . '<label for="wc-assignment-sort">Sortierwert (aufsteigend)</label>'
            . '<input id="wc-assignment-sort" type="number" name="sort" min="0" required value="' . $node['sort'] . '">'
            . '<button type="submit" class="wc-btn wc-btn-secondary">Kategorie verschieben</button></form></details>'
            . '<button type="button" class="wc-btn wc-btn-danger" data-command="cat_delete">Kategorie l&ouml;schen</button></div>';
    }

    private function categoryErrors(ShopCategoryStore $store): string
    {
        $html = '';
        if ($store->treeSyncBudgetExhaustions() >= 3) {
            $html .= '<p class="warning" role="status">Die Synchronisierung des Kategorienbaums hat mindestens dreimal in Folge das Zeitbudget erreicht.</p>';
        }
        if ($store->hasPendingExportInvalidations()) {
            $html .= '<p class="warning" role="status">' . self::EXPORT_INVALIDATION_WARNING . '</p>';
        }
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

    private function articles(ShopCategoryStore $store, int $shopId, int $category, bool $onlyUnassigned, int $requestedPage): array
    {
        $projectIds = [];
        foreach ($this->db->fetchAll('SELECT id FROM projekt') as $project) {
            $id = (int) $project['id'];
            if ($this->app->erp->UserProjektRecht($id)) {
                $projectIds[] = $id;
            }
        }
        $projectFilter = $projectIds === [] ? '' : ' OR a.projekt IN (' . implode(',', $projectIds) . ')';
        $membership = ShopAssignment::assignedSql('assignment_owner', ':shop');
        $from = ' FROM artikel AS a' . ShopAssignment::ownerJoinSql()
            . ' LEFT JOIN konfiguration AS wc ON wc.name = CONCAT(:article_prefix, a.id)'
            . ' WHERE a.geloescht = 0 AND (COALESCE(a.projekt, 0) <= 0' . $projectFilter . ')';
        $params = ['article_prefix' => $store->articleConfigPrefix()];
        if ($category === 0) {
            $from .= " AND COALESCE(JSON_LENGTH(wc.wert, '$.categories'), 0) = 0";
        } else {
            $from .= " AND JSON_CONTAINS(wc.wert, :category_id, '$.categories') = 1";
            $params['category_id'] = (string) $category;
        }
        if ($onlyUnassigned) {
            $from .= ' AND NOT ' . $membership;
            $params['shop'] = $shopId;
        }
        $count = (int) $this->db->fetchValue('SELECT COUNT(*)' . $from, $params);
        $lastPage = max(1, (int) ceil($count / self::PAGE_SIZE));
        $page = max(1, min($lastPage, $requestedPage));
        $inShop = $onlyUnassigned ? '0' : $membership;
        $legacyOnly = $onlyUnassigned ? '0' : '(' . ShopAssignment::legacySql('assignment_owner', ':shop')
            . ' AND NOT ' . ShopAssignment::activeSql('assignment_owner', ':shop') . ')';
        $rows = $this->db->fetchAll(
            'SELECT a.id, a.nummer, a.name_de, a.ausverkauft, a.variante, a.variante_von, ' . $inShop . ' AS in_shop, '
            . $legacyOnly . ' AS legacy_only' . $from . ' ORDER BY a.nummer, a.id LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . (($page - 1) * self::PAGE_SIZE),
            $params + ['shop' => $shopId]
        );
        return [$rows, $page, $lastPage];
    }

    private function articleTable(ShopCategoryStore $store, array $rows, int $category, array $categories = []): string
    {
        if ($rows === []) {
            return '<p class="info">Keine Artikel in dieser Auswahl.</p>';
        }
        $columns = [
            'toggle_shop' => 'im Shop',
            'toggle_soldout' => 'nicht mehr lieferbar',
            'toggle_onrequest' => 'auf Nachfrage lieferbar',
        ];
        $showAssignSelect = $category === 0 && $categories !== [];
        $categoryOptionsHtml = '';
        if ($showAssignSelect) {
            foreach ($categories as $catId => $node) {
                $categoryOptionsHtml .= '<option value="' . $catId . '">'
                    . str_replace('  ', '&nbsp;&nbsp;', AdminView::e($node['label'])) . '</option>';
            }
        }
        $html = '<div class="wc-admin-scroll" role="region" aria-label="Shop-Artikel" tabindex="0">'
            . '<table class="wc-assignment-table"><thead><tr><th scope="col" class="wc-col-number">Nr.</th><th scope="col" class="wc-col-name">Name</th>';
        foreach ($columns as $label) {
            $html .= '<th scope="col" class="wc-col-flag">' . $label . '</th>';
        }
        if ($category !== 0) {
            $html .= '<th scope="col" class="wc-col-category">Kategorie</th>';
        } elseif ($showAssignSelect) {
            $html .= '<th scope="col" class="wc-col-category">Kategorie zuordnen</th>';
        }
        $html .= '</tr></thead><tbody>';
        $states = $store->articleStates(array_map('intval', array_column($rows, 'id')));
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $checked = [
                'toggle_shop' => (bool) $row['in_shop'],
                'toggle_soldout' => (bool) $row['ausverkauft'],
                'toggle_onrequest' => $states[$id]['onRequest'],
            ];
            $number = AdminView::e($row['nummer']);
            $html .= '<tr data-article="' . $id . '"><th scope="row" class="wc-col-number">' . $number . '</th><td class="wc-col-name">' . AdminView::e($row['name_de']) . '</td>';
            foreach ($columns as $cmd => $label) {
                if ($cmd === 'toggle_shop' && ArticleFieldCatalog::isVariant($row)) {
                    $html .= '<td class="wc-col-flag wc-flag-variant">folgt Hauptartikel</td>';
                    continue;
                }
                $html .= '<td class="wc-col-flag"><input type="checkbox" data-command="' . $cmd . '" aria-label="' . $label . ' (' . $number . ')"'
                    . ($checked[$cmd] ? ' checked' : '') . '>'
                    . ($cmd === 'toggle_shop' && !empty($row['legacy_only']) ? ' <small class="wc-flag-note">ueber Artikelstamm zugeordnet</small>' : '')
                    . '</td>';
            }
            if ($category !== 0) {
                $html .= '<td class="wc-col-category"><button type="button" class="wc-btn wc-btn-danger wc-btn-sm" data-command="cat_unassign" aria-label="Kategoriezuordnung f&uuml;r '
                    . $number . ' entfernen">Entfernen</button></td>';
            } elseif ($showAssignSelect) {
                $html .= '<td class="wc-col-category"><select class="wc-assignment-category-select" data-command="cat_assign" aria-label="Kategorie f&uuml;r '
                    . $number . ' zuordnen"><option value="">Kategorie w&auml;hlen &hellip;</option>' . $categoryOptionsHtml . '</select></td>';
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
            $result = $this->transactional(fn (): array => match ($cmd) {
                'cat_create', 'cat_rename', 'cat_move', 'cat_delete' => $this->editTree($store, $cmd, $post),
                'toggle_shop', 'toggle_soldout', 'toggle_onrequest', 'cat_assign', 'cat_unassign'
                    => $this->editArticle($store, $shop, $cmd, $post),
                default => throw new DomainException('Unbekannte Aktion.'),
            });
            if ($cmd === 'cat_rename' || $cmd === 'cat_move') {
                // The tree change is already committed. Keep the export work pending on failure.
                try {
                    $store->drainExportInvalidations();
                    if ($store->hasPendingExportInvalidations()) {
                        $result['warning'] = self::EXPORT_INVALIDATION_WARNING;
                    }
                } catch (Throwable $error) {
                    $result['warning'] = self::EXPORT_INVALIDATION_WARNING;
                    try {
                        $this->errorMessage($error);
                    } catch (Throwable) {
                        // A failed log cannot change the outcome of the committed edit.
                    }
                }
            }
            return ['ok' => true, 'error' => null] + $result;
        } catch (Throwable $error) {
            return ['ok' => false, 'error' => $this->errorMessage($error)];
        }
    }

    private function transactional(callable $write): mixed
    {
        $this->db->beginTransaction();
        try {
            $result = $write();
            $this->db->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    private function editTree(ShopCategoryStore $store, string $cmd, array $post): array
    {
        if ($cmd === 'cat_create') {
            $category = $store->create($this->name($post), $this->integer($post['parent'] ?? null, 0));
        } else {
            $category = $this->integer($post['category'] ?? null, 1);
            if ($cmd === 'cat_delete') {
                $store->delete($category);
                $category = 0;
            } elseif ($cmd === 'cat_rename') {
                $store->rename($category, $this->name($post));
            } else {
                $store->move($category, $this->integer($post['parent'] ?? null, 0), $this->integer($post['sort'] ?? null, 0));
            }
        }
        $store->markTreeSyncPending();
        if ($cmd === 'cat_rename' || $cmd === 'cat_move') {
            $store->markExportInvalidationPending($category);
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
                'SELECT id, projekt, variante, shop, shop2, shop3 FROM artikel WHERE id = :article AND geloescht = 0 FOR UPDATE',
                ['article' => $this->integer($post['article'] ?? null, 1)]
            );
        }
        if (!$article || !$this->app->erp->UserProjektRecht((int) $article['projekt'])) {
            throw new DomainException('Artikel nicht vorhanden oder nicht freigegeben.');
        }

        $id = (int) $article['id'];
        $enabled = str_starts_with($cmd, 'toggle_') ? $this->integer($post['enabled'] ?? null, 0, 1) : 0;
        if ($cmd === 'toggle_shop' && ArticleFieldCatalog::isVariant($article)) {
            throw new DomainException('Die Shop-Zuordnung der Variante folgt dem Hauptartikel.');
        }
        // Invalidate first so article edits also take assignment locks before the tree lock.
        $store->invalidateArticleExport($id);
        switch ($cmd) {
            case 'toggle_shop':
                $hadLegacyAssignment = ShopAssignment::hasLegacyReference($article, $shopId);
                $this->toggleShop($shopId, $id, $enabled, !empty($this->settings($shop)['visibilitySync']), $hadLegacyAssignment);
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
        return ['article' => $id];
    }

    private function toggleShop(int $shopId, int $articleId, int $enabled, bool $visibilitySync, bool $hadLegacyAssignment): void
    {
        $params = ['shop' => $shopId, 'article' => $articleId];
        // Lock the assignment before the visibility queue, in the same order as its drain.
        $assignment = $this->db->fetchRow(
            'SELECT id, aktiv FROM artikel_onlineshops WHERE artikel = :article AND shop = :shop'
            . ' ORDER BY aktiv DESC, id LIMIT 1 FOR UPDATE',
            $params
        );
        $queue = new VisibilityQueue($this->db, $shopId);
        if ($enabled === 0) {
            if ($assignment) {
                $this->db->perform('UPDATE artikel_onlineshops SET aktiv = :enabled WHERE artikel = :article AND shop = :shop',
                    $params + ['enabled' => 0]);
            } elseif ($hadLegacyAssignment && $visibilitySync) {
                // Keep an explicit unassignment after clearing the only legacy reference.
                $this->insertAssignment($params, 0);
            }
            if ($hadLegacyAssignment) {
                $this->db->perform(
                    'UPDATE artikel SET shop = IF(shop = :shop, 0, shop), shop2 = IF(shop2 = :shop2, 0, shop2),'
                    . ' shop3 = IF(shop3 = :shop3, 0, shop3) WHERE id = :article',
                    $params + ['shop2' => $shopId, 'shop3' => $shopId]
                );
            }
            if ($visibilitySync && ((int) ($assignment['aktiv'] ?? 0) === 1 || $hadLegacyAssignment)) {
                $sku = $this->db->fetchValue(
                    'SELECT ' . ArticleDataRepository::SHOP_SKU_SQL . ' FROM artikel AS a WHERE a.id = :article',
                    $params
                );
                $queue->enqueue($articleId, (string) $sku);
            }
        } else {
            if ($assignment) {
                $this->db->perform('UPDATE artikel_onlineshops SET aktiv = :enabled WHERE id = :id',
                    ['enabled' => 1, 'id' => (int) $assignment['id']]);
            } else {
                $this->insertAssignment($params, 1);
            }
            $queue->cancel($articleId);
        }
    }

    private function insertAssignment(array $params, int $enabled): void
    {
        $this->db->perform(
            'INSERT INTO artikel_onlineshops (artikel, shop, aktiv, pseudolager, lieferzeitmanuell, last_article_hash)'
            . " VALUES (:article, :shop, :enabled, '', '', '')",
            $params + ['enabled' => $enabled]
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
