<?php
// SPDX-License-Identifier: AGPL-3.0-only
namespace Xentral\Components\WooCommerce;

/** Escaped server-rendered fragments inside the existing shop administration. */
final class AdminView
{
    public static function url($shopId, $view, array $extra = [])
    {
        return 'index.php?' . http_build_query(array_merge([
            'module' => 'onlineshops', 'action' => 'edit', 'id' => $shopId,
            'cmd' => 'woocommerce', 'view' => $view,
        ], $extra), '', '&', PHP_QUERY_RFC3986);
    }

    public static function downloads($shopId, $token, $article, $settings, array $results, $search, $enabled)
    {
        $html = '<h2>Downloads</h2><p>Download-Dateien pro Artikel und Shop festlegen. Die normale Artikel&uuml;bertragung sendet die gespeicherten Werte an WooCommerce.</p>';
        if (!$enabled) {
            $html .= '<div class="warning">Download-Export ist deaktiviert. Aktivieren Sie ihn in den Shop-Details, damit diese Werte &uuml;bertragen werden.</div>';
        }
        $html .= '<form method="get" action="index.php" class="wc-admin-search">';
        foreach (['module' => 'onlineshops', 'action' => 'edit', 'id' => $shopId, 'cmd' => 'woocommerce', 'view' => 'downloads'] as $name => $value) {
            $html .= self::hidden($name, $value);
        }
        $html .= '<label for="wc-search">Artikelnummer oder Name</label> <input id="wc-search" name="search" value="' . self::e($search) . '" maxlength="100"> <button type="submit">Artikel suchen</button></form>';
        if ($search !== '') {
            $html .= '<ul class="wc-admin-results">';
            foreach ($results as $result) {
                $html .= '<li><a href="' . self::e(self::url($shopId, 'downloads', ['article' => $result['id']])) . '">' . self::e($result['nummer'] . ' · ' . $result['name_de']) . '</a></li>';
            }
            $html .= empty($results) ? '<li>Keine passenden Artikel in diesem Shop gefunden.</li>' : '';
            $html .= '</ul>';
            if (count($results) === 50) {
                $html .= '<p>Erste 50 Treffer. Bitte Suche eingrenzen.</p>';
            }
        }
        if (!$article) {
            return $html;
        }
        $settings = $settings ?? ['downloadable' => false, 'virtual' => false, 'downloads' => []];
        $html .= '<fieldset><legend>' . self::e($article['nummer'] . ' · ' . $article['name_de']) . '</legend>'
            . '<form method="post" action="' . self::e(self::url($shopId, 'downloads', ['article' => $article['id']])) . '">'
            . self::hidden('csrf_token', $token) . self::hidden('article', $article['id'])
            . '<p><label><input type="checkbox" name="downloadable" value="1"' . (!empty($settings['downloadable']) ? ' checked' : '') . '> Herunterladbarer Artikel</label></p>'
            . '<p><label><input type="checkbox" name="virtual" value="1"' . (!empty($settings['virtual']) ? ' checked' : '') . '> Virtueller Artikel (kein Versand)</label></p>'
            . '<p>Vollst&auml;ndige HTTPS- oder HTTP-Datei-URL eingeben. Dateien werden hier nicht hochgeladen. Nicht mehr ben&ouml;tigte Zeilen entfernen.</p>'
            . '<div class="wc-admin-scroll"><table class="wc-download-table"><thead><tr><th scope="col">Downloadname</th><th scope="col">Datei-URL</th><th scope="col">Aktion</th></tr></thead><tbody id="wc-download-rows">';
        foreach ($settings['downloads'] ?: [['name' => '', 'file' => '']] as $download) {
            $html .= self::downloadRow($download);
        }
        return $html . '</tbody></table></div><template id="wc-download-template">' . self::downloadRow(['name' => '', 'file' => '']) . '</template>'
            . '<p><button type="button" id="wc-download-add">Download hinzuf&uuml;gen</button></p>'
            . '<p class="wc-admin-actions"><button type="submit" class="btnBlueNew" name="wc_action" value="save_downloads">Downloads speichern</button> '
            . '<button type="submit" name="wc_action" value="clear_downloads" formnovalidate>Smarty-Vorgabe verwenden</button></p>'
            . '<p>Speichern ersetzt die Download-Vorgaben dieses Artikels. Ohne Haken bei &bdquo;Herunterladbarer Artikel&ldquo; werden vorhandene Downloads beim n&auml;chsten Export entfernt. &bdquo;Smarty-Vorgabe verwenden&ldquo; entfernt nur diese lokale Vorgabe.</p></form></fieldset>';
    }

    public static function refunds($shopId, $token, array $page)
    {
        $html = '<h2>Refund-Status</h2><p>WooCommerce-R&uuml;ckerstattungen und ihr Abgleich mit OpenXE. Alle Zeiten in UTC. Die Ansicht l&ouml;st keine Gateway-Zahlung oder Lagerbuchung aus.</p>'
            . '<p>Bei &bdquo;Manuell pr&uuml;fen&ldquo; ist der Buchungsabschluss unklar. Zuerst Gutschrift und Journal gem&auml;&szlig; Recovery-Anleitung pr&uuml;fen. &bdquo;Erneut versuchen&ldquo; setzt nur den n&auml;chsten Abgleich vor; gesperrte Buchungen bleiben gesperrt.</p>';
        if (!$page['rows']) {
            return $html . '<div class="info">Noch keine Refund-Abgleiche gespeichert. Der Import muss laufen und der Refund-Abgleich in den Shop-Details aktiviert sein.</div>';
        }
        $html .= '<div class="wc-admin-scroll" tabindex="0" role="region" aria-label="Refund-Status"><table class="wc-refund-table"><thead><tr><th scope="col">Woo-Auftrag / Refund</th><th scope="col">Status</th><th scope="col">Gutschrift-ID</th><th scope="col">Versuche</th><th scope="col">Letzter / n&auml;chster Versuch (UTC)</th><th scope="col">Letzter Fehler / Aktion</th></tr></thead><tbody>';
        foreach ($page['rows'] as $row) {
            $status = $row['status'] === 'invalid' ? 'Ung&uuml;ltiger Status: manuell pr&uuml;fen' : ($row['type'] === 'queue' ? 'Abgleich ausstehend' : ($row['status'] === 'complete' ? 'Abgeschlossen' : 'Manuell pr&uuml;fen'));
            $html .= '<tr><td>' . self::e($row['order_id']) . ' / ' . self::e($row['refund_id'] ?: '–') . '</td><td>' . $status . '</td><td>' . self::e($row['credit_note_id'] ?: '–') . '</td><td>' . self::e($row['attempts']) . '</td><td>'
                . self::time($row['last_attempt']) . '<br>' . self::time($row['next_attempt']) . '</td><td>' . self::e($row['last_error']);
            if ($row['retry_available']) {
                $html .= '<form method="post" action="' . self::e(self::url($shopId, 'refunds', ['page' => $page['page']])) . '">'
                    . self::hidden('csrf_token', $token) . self::hidden('order_id', $row['order_id'])
                    . '<button type="submit" name="wc_action" value="retry_refund">Erneut versuchen</button></form>';
            }
            $html .= '</td></tr>';
        }
        $html .= '</tbody></table></div><nav class="wc-admin-pages" aria-label="Refund-Seiten">';
        if ($page['page'] > 1) {
            $html .= '<a href="' . self::e(self::url($shopId, 'refunds', ['page' => $page['page'] - 1])) . '">Vorherige Seite</a> ';
        }
        $html .= 'Seite ' . (int) $page['page'] . ' von ' . (int) $page['pages'] . ' (' . (int) $page['total'] . ' Eintr&auml;ge)';
        if ($page['page'] < $page['pages']) {
            $html .= ' <a href="' . self::e(self::url($shopId, 'refunds', ['page' => $page['page'] + 1])) . '">N&auml;chste Seite</a>';
        }
        return $html . '</nav>';
    }

    public static function pendingCategoryDeletions(array $pending, array $reasons): string
    {
        if ($pending === []) {
            return '';
        }
        $html = '<h2>Vorgemerkte WooCommerce-Kategoriel&ouml;schungen</h2><ul class="wc-admin-results">';
        foreach ($pending as $termId => $slug) {
            $html .= '<li>#' . (int) $termId . ' (' . self::e($slug) . ') &ndash; '
                . self::e($reasons[$termId] ?? 'Wartet auf den naechsten Kategorieexport.') . '</li>';
        }
        return $html . '</ul>';
    }

    public static function categories(int $shopId, string $token, string $nonce, array $preview): string
    {
        $html = '<h2>Shop-Kategorien &uuml;bernehmen</h2>'
            . '<p>Pr&uuml;fen Sie die WooCommerce-Kategorien und Artikelzuordnungen vor der Erst&uuml;bernahme in OpenXE.</p>';
        if ($preview['nodes'] === []) {
            $html .= '<p>WooCommerce enth&auml;lt keine Kategorien zur &Uuml;bernahme.</p>';
        } else {
            $html .= '<h3>Kategorien (' . count($preview['nodes']) . ')</h3>'
                . '<div class="wc-admin-scroll" tabindex="0" role="region" aria-label="WooCommerce-Kategorien">'
                . '<table><thead><tr><th scope="col">Kategorie</th><th scope="col">WooCommerce-ID</th>'
                . '<th scope="col">Slug</th><th scope="col">Oberkategorie</th></tr></thead><tbody>';
            foreach ($preview['nodes'] as $termId => $term) {
                $parent = $preview['nodes'][$term['parent']]['name'] ?? '';
                $html .= '<tr><th scope="row">' . self::e($term['name']) . '</th><td>' . (int) $termId
                    . '</td><td>' . self::e($term['slug']) . '</td><td>' . self::e($parent) . '</td></tr>';
            }
            $html .= '</tbody></table></div>';
        }

        $html .= '<h3>Artikelzuordnungen (' . count($preview['assignments']) . ')</h3>';
        if ($preview['assignments'] !== []) {
            $html .= '<ul class="wc-admin-results">';
            foreach ($preview['assignments'] as $articleId => $termIds) {
                $names = array_map(static fn (int $id): string => $preview['nodes'][$id]['name'], $termIds);
                $html .= '<li>OpenXE-Artikel #' . (int) $articleId . ' &ndash; ' . self::e(implode(', ', $names)) . '</li>';
            }
            $html .= '</ul>';
        }
        $html .= '<h3>Nicht zugeordnete WooCommerce-Produkte (' . count($preview['unmatched']) . ')</h3>';
        if ($preview['unmatched'] !== []) {
            $html .= '<ul class="wc-admin-results">';
            foreach ($preview['unmatched'] as $row) {
                $sku = $row['sku'] === '' ? '' : ' (' . $row['sku'] . ')';
                $html .= '<li>' . self::e('#' . $row['id'] . ' ' . $row['name'] . $sku)
                    . ' &ndash; ' . self::e($row['reason']) . '</li>';
            }
            $html .= '</ul>';
        }

        if ($preview['nodes'] !== []) {
            $html .= '<form method="post" action="' . self::e(self::url($shopId, 'categories')) . '">'
                . self::hidden('csrf_token', $token) . self::hidden('category_preview', $nonce)
                . '<p><button class="btnBlueNew" type="submit" name="wc_action" value="apply_categories">'
                . 'Kategorien und Zuordnungen &uuml;bernehmen</button></p></form>';
        }
        return $html;
    }

    public static function categoryNotice(string $message): string
    {
        return '<h2>Shop-Kategorien &uuml;bernehmen</h2><p role="status">' . $message . '</p>';
    }

    public static function pull(int $shopId, string $token, string $preview, int $page, array $data, array $groups, array $selectedGroups): string
    {
        $extra = ['page' => $page, 'filter' => '1', 'groups' => $selectedGroups];
        $html = '<h2>Artikeldaten abgleichen</h2><p>W&auml;hlen Sie die Felder aus, die OpenXE aus WooCommerce &uuml;bernehmen soll. Beim Speichern werden die aktuellen Shop-Werte geladen.</p>'
            . '<form method="get" action="index.php"><fieldset><legend>Feldgruppen</legend>';
        foreach (['module' => 'onlineshops', 'action' => 'edit', 'id' => $shopId, 'cmd' => 'woocommerce',
            'view' => 'pull', 'page' => $page, 'filter' => '1'] as $name => $value) {
            $html .= self::hidden($name, $value);
        }
        $html .= '<div class="wc-admin-actions">';
        foreach ($groups as $group) {
            $html .= '<label><input type="checkbox" name="groups[]" value="' . self::e($group) . '"'
                . (in_array($group, $selectedGroups, true) ? ' checked' : '') . '> ' . self::e($group) . '</label>';
        }
        $html .= '</div><p><button type="submit">Filtern</button></p></fieldset></form>';

        $html .= '<form method="post" id="wc-pull-form" data-max-articles="' . BulkPull::MAX_SELECTION
            . '" action="' . self::e(self::url($shopId, 'pull', $extra)) . '">'
            . self::hidden('wc_action', 'apply_pull') . self::hidden('csrf_token', $token)
            . self::hidden('pull_preview', $preview) . self::hidden('pull_count', '0')
            . '<div id="wc-pull-selection" hidden></div>';
        $hasRows = $hasSelection = false;
        foreach ($data['articles'] as $id => $article) {
            if ($article['rows'] === []) {
                continue;
            }
            $hasRows = true;
            $label = $article['nummer'] . ' - ' . $article['name'];
            $html .= '<fieldset><legend>' . self::e($label) . '</legend>'
                . '<div class="wc-admin-scroll" tabindex="0" role="region" aria-label="' . self::e('Feldvergleich ' . $label) . '">'
                . '<table class="wc-pull-table"><thead><tr><th scope="col">Feld</th><th scope="col">OpenXE</th>'
                . '<th scope="col">WooCommerce</th><th scope="col">&Uuml;bernehmen</th></tr></thead><tbody>';
            foreach ($article['rows'] as $row) {
                $html .= '<tr><th scope="row">' . self::e($row['label']) . '</th><td>' . self::pullValue($row['openxe'])
                    . '</td><td>' . self::pullValue($row['woocommerce']) . '</td><td>';
                if ($row['pullable']) {
                    $hasSelection = true;
                    $html .= '<input type="checkbox" data-pull-article="' . (int) $id . '" value="' . self::e($row['key'])
                        . '" aria-label="' . self::e($row['label'] . ' uebernehmen: ' . $label) . '">';
                } else {
                    $html .= self::e($row['reason']);
                }
                $html .= '</td></tr>';
            }
            $html .= '</tbody></table></div></fieldset>';
        }
        if ($hasSelection) {
            $html .= '<p>Pro &Uuml;bernahme sind h&ouml;chstens ' . BulkPull::MAX_SELECTION . ' Artikel ausw&auml;hlbar.</p>'
                . '<p id="wc-pull-limit" role="status" hidden>Auswahllimit erreicht. Entfernen Sie die Auswahl eines Artikels, um weitere Artikel auszuw&auml;hlen.</p>'
                . '<p class="wc-admin-actions"><button type="submit" class="btnBlueNew" disabled>Ausgew&auml;hlte Werte &uuml;bernehmen</button></p>'
                . '<noscript><p>Aktivieren Sie JavaScript, um ausgew&auml;hlte Werte zu &uuml;bernehmen.</p></noscript>';
        }
        $html .= '</form>';
        if (!$hasRows) {
            $html .= '<p>Auf dieser Seite gibt es f&uuml;r die ausgew&auml;hlten Feldgruppen keine Unterschiede.</p>';
        }

        if ($data['unmatched'] !== []) {
            $html .= '<h3>Nicht zugeordnete WooCommerce-Produkte</h3><ul class="wc-admin-results">';
            foreach ($data['unmatched'] as $product) {
                $html .= '<li>' . self::e('#' . $product['id'] . ' ' . $product['name'] . ' - ' . $product['reason']) . '</li>';
            }
            $html .= '</ul>';
        }

        $html .= '<nav class="wc-admin-pages" aria-label="Artikelseiten">';
        if ($page > 1) {
            $html .= '<a href="' . self::e(self::url($shopId, 'pull', ['page' => $page - 1] + $extra)) . '">Zur&uuml;ck</a> ';
        }
        $html .= 'Seite ' . $page;
        if ($data['more']) {
            $html .= ' <a href="' . self::e(self::url($shopId, 'pull', ['page' => $page + 1] + $extra)) . '">Weiter</a>';
        }
        return $html . '</nav>';
    }

    public static function pullResults(int $shopId, int $page, array $selectedGroups, array $results): string
    {
        $html = '<h2>Artikeldaten abgleichen</h2><ul class="wc-admin-results">';
        foreach ($results as $id => $result) {
            $html .= '<li>OpenXE-Artikel #' . self::e($id) . ' ';
            if (isset($result['error'])) {
                $html .= '<span class="error" role="alert">Fehler beim Speichern. ' . self::e($result['error']) . '</span>';
            } else {
                $message = match (count($result)) {
                    0 => 'Keine &Auml;nderung erforderlich.',
                    1 => '1 Feld &uuml;bernommen.',
                    default => count($result) . ' Felder &uuml;bernommen.',
                };
                $html .= '<span role="status">' . $message . '</span>';
            }
            $html .= '</li>';
        }
        return $html . '</ul><p><a href="' . self::e(self::url($shopId, 'pull',
            ['page' => $page, 'filter' => '1', 'groups' => $selectedGroups])) . '">Vorschau neu laden</a></p>';
    }

    private static function pullValue(string $value): string
    {
        return $value === '' ? '<em>leer</em>' : self::e($value);
    }

    private static function downloadRow(array $download)
    {
        return '<tr><td><input name="download_name[]" aria-label="Downloadname" maxlength="255" value="' . self::e($download['name']) . '"></td><td><input name="download_file[]" type="url" aria-label="Datei-URL" maxlength="2048" value="' . self::e($download['file']) . '"></td><td><button type="button" class="wc-download-remove">Entfernen</button></td></tr>';
    }

    private static function hidden($name, $value)
    {
        return '<input type="hidden" name="' . self::e($name) . '" value="' . self::e($value) . '">';
    }

    private static function time($value)
    {
        return $value > 0 ? gmdate('Y-m-d H:i:s', (int) $value) : '–';
    }

    public static function e($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
