<?php
// SPDX-License-Identifier: AGPL-3.0-only

use Xentral\Components\WooCommerce\AssignmentPage;
use Xentral\Components\WooCommerce\ShopCategoryStore;
use Xentral\Components\WooCommerce\StockCorrection;
use Xentral\Components\WooCommerce\VisibilityQueue;

require_once __DIR__ . '/fixtures/assignment.php';

$tests = [
    'legacy_only_assignment_is_checked_and_excluded_from_unassigned_filter' => function (): void {
        [$page, $db] = assignmentSetup();
        $db->article['shop2'] = 7;
        $html = $page->render();
        expect(true, str_contains($html, 'ueber Artikelstamm zugeordnet'));
        expect(1, preg_match('/data-command="toggle_shop"[^>]+ checked/', $html));
        $page = new AssignmentPage(assignmentFixtureApp($db, ['unassigned' => 1]));
        expect(false, str_contains($page->render(), 'data-article="9"'));
    },
    'unchecking_a_legacy_only_assignment_clears_all_matching_slots_and_queues_hide' => function (): void {
        [$page, $db] = assignmentSetup();
        $db->article = array_replace($db->article, ['shop' => 7, 'shop2' => 8, 'shop3' => 7]);
        expect(true, assignmentCommand($page, 'toggle_shop')['ok']);
        expect([0, 8, 0], array_values(array_intersect_key($db->article, array_flip(['shop', 'shop2', 'shop3']))));
        expect('WC-ARTICLE', (new VisibilityQueue($db, 7))->pending()[9]['sku']);
        expect(0, $db->assignment['aktiv'] ?? null);
    },
    'variants_follow_the_main_article_but_can_change_delivery_flags' => function (): void {
        [$page, $db, $store] = assignmentSetup();
        $db->article['variante'] = 1;
        $db->article['variante_von'] = 6;
        $html = $page->render();
        expect(false, str_contains($html, 'data-command="toggle_shop"'));
        expect(true, str_contains($html, 'folgt Hauptartikel'));
        expect(true, str_contains($html, 'data-command="toggle_soldout"'));
        expect(true, str_contains($html, 'data-command="toggle_onrequest"'));
        expect(false, assignmentCommand($page, 'toggle_shop', ['enabled' => 1])['ok']);
        expect(true, assignmentCommand($page, 'toggle_soldout', ['enabled' => 1])['ok']);
        expect(true, assignmentCommand($page, 'toggle_onrequest', ['enabled' => 1])['ok']);
        expect(1, $db->article['ausverkauft']);
        expect(true, $store->isOnRequest(9));
    },
    'category_project_and_shop_filters_apply_before_sql_pagination' => function (): void {
        [, $db, $store] = assignmentSetup();
        $category = $store->create('Parts');
        for ($id = 1; $id <= 420; $id++) {
            $db->catalog[$id] = [
                'id' => $id, 'nummer' => sprintf('%04d', $id), 'name_de' => 'Part',
                'projekt' => $id % 2 === 0 ? 4 : 5, 'ausverkauft' => 0, 'variante' => 0,
                'in_shop' => $id % 8 === 0 ? 1 : 0,
            ];
            if ($id % 4 === 0) {
                $store->assign($id, [$category]);
            }
        }
        foreach ([
            [0, false, range(202, 398, 4), 'Seite 2 von 3'],
            [$category, true, range(404, 420, 8), 'Seite 2 von 2'],
        ] as [$selected, $filter, $ids, $label]) {
            $page = new AssignmentPage(assignmentFixtureApp($db, [
                'page' => 2, 'category' => $selected, 'unassigned' => (int) $filter,
            ]));
            $html = $page->render();
            preg_match_all('/<tr data-article="(\d+)">/', $html, $rows);
            expect($ids, array_map('intval', $rows[1]));
            expect(true, str_contains($html, $label));
            expect($ids, $db->batchArticleIds);
        }
    },
    'tree_changes_release_the_tree_lock_before_invalidating_assignments' => function (): void {
        [$page, $db, $store] = assignmentSetup();
        $category = $store->create('Parts');
        $store->assign(9, [$category]);
        $db->assignment = ['aktiv' => 1, 'last_article_hash' => 'old'];
        $db->rejectAssignmentWritesAfterTreeLock = true;
        expect(true, assignmentCommand($page, 'cat_rename', ['category' => $category, 'name' => 'Tools'])['ok']);
        expect('Tools', $store->tree()[0]['name']);
        expect('', $db->assignment['last_article_hash']);
        expect(true, assignmentCommand($page, 'toggle_onrequest', ['enabled' => 1])['ok']);
    },
    'toggle_shop_creates_assignment_with_required_columns' => function (): void {
        [$page, $db] = assignmentSetup();
        expect(true, assignmentCommand($page, 'toggle_shop', ['enabled' => '1'])['ok']);
        expect(['aktiv' => 1, 'pseudolager' => '', 'lieferzeitmanuell' => '', 'last_article_hash' => ''], $db->assignment);
    },
    'remove_assignment_enqueues_hide' => function (): void {
        [$page, $db] = assignmentSetup();
        $db->assignment = ['aktiv' => 1];
        expect(true, assignmentCommand($page, 'toggle_shop')['ok']);
        expect(0, $db->assignment['aktiv']);
        expect('WC-ARTICLE', (new VisibilityQueue($db, 7))->pending()[9]['sku']);
    },
    'visibility_disabled_uses_no_queue' => function (): void {
        [$page, $db] = assignmentSetup();
        $db->shop['einstellungen_json'] = '{}';
        $db->assignment = ['aktiv' => 1];
        expect(true, assignmentCommand($page, 'toggle_shop')['ok']);
        expect(0, $db->assignment['aktiv']);
        expect([], (new VisibilityQueue($db, 7))->pending());
    },
    'full_queue_keeps_assignment' => function (): void {
        [$page, $db] = assignmentSetup();
        $db->assignment = ['aktiv' => 1, 'last_article_hash' => 'unchanged'];
        $entries = array_fill_keys(range(20, 2020), ['sku' => 'EXISTING']);
        $db->config['woocommerce_visibility_7'] = json_encode($entries);
        $response = assignmentCommand($page, 'toggle_shop');
        expect(false, $response['ok']);
        expect(true, str_contains($response['error'], 'Sichtbarkeitsqueue ist voll'));
        expect(['aktiv' => 1, 'last_article_hash' => 'unchanged'], $db->assignment);
        expect($entries, (new VisibilityQueue($db, 7))->pending());
    },
    'readd_cancels_hide' => function (): void {
        [$page, $db] = assignmentSetup();
        $db->assignment = ['aktiv' => 1];
        $queue = new VisibilityQueue($db, 7);
        expect(true, assignmentCommand($page, 'toggle_shop')['ok']);
        expect(true, isset($queue->pending()[9]));
        expect(true, assignmentCommand($page, 'toggle_shop', ['enabled' => '1'])['ok']);
        expect(1, $db->assignment['aktiv']);
        expect([], $queue->pending());
    },
    'soldout_mirrors_ausverkauft' => function (): void {
        [$page, $db] = assignmentSetup();
        expect(true, assignmentCommand($page, 'toggle_soldout', ['enabled' => '1'])['ok']);
        expect(1, $db->article['ausverkauft']);
        expect(true, assignmentCommand($page, 'toggle_soldout')['ok']);
        expect(0, $db->article['ausverkauft']);
    },
    'both_boxes_allowed_and_stored' => function (): void {
        [$page, $db, $store] = assignmentSetup();
        expect(true, assignmentCommand($page, 'toggle_soldout', ['enabled' => '1'])['ok']);
        expect(true, assignmentCommand($page, 'toggle_onrequest', ['enabled' => '1'])['ok']);
        expect(1, $db->article['ausverkauft']);
        expect(true, $store->isOnRequest(9));
    },
    'category_commands_require_token' => function (): void {
        [$page, $db] = assignmentSetup();
        foreach (['cat_create', 'cat_rename', 'cat_move', 'cat_delete', 'cat_assign', 'cat_unassign'] as $command) {
            $response = assignmentCommand($page, $command, ['csrf_token' => 'invalid']);
            expect(false, $response['ok']);
            expect(true, str_contains($response['error'], 'Formular abgelaufen'));
        }
        expect([], $db->config);
    },
    'shop_and_article_project_rights_are_independent' => function (): void {
        [$page, $db, , $app] = assignmentSetup();
        $app->erp->projects = [3];
        expect(false, assignmentCommand($page, 'toggle_soldout', ['enabled' => '1'])['ok']);
        $app->erp->projects = [4];
        expect(false, assignmentCommand($page, 'cat_create', ['name' => 'Hotends'])['ok']);
        $app->erp->projects = [3, 4];
        $app->erp->rights = ['artikel'];
        expect(false, assignmentCommand($page, 'cat_create', ['name' => 'Hotends'])['ok']);
        expect(0, $db->article['ausverkauft']);
        expect([], $db->config);
    },
    'non_empty_delete_returns_error' => function (): void {
        [$page, , $store] = assignmentSetup();
        $category = $store->create('Hotends');
        $store->assign(9, [$category]);
        expect(['ok' => false, 'error' => 'Kategorie ist nicht leer.'], assignmentCommand($page, 'cat_delete', ['category' => $category]));
        expect([9], $store->articlesIn($category));
    },
    'changes_invalidate_export_hash' => function (): void {
        [$page, $db, $store] = assignmentSetup();
        $category = $store->create('Hotends');
        $second = $store->create('Ersatzteile');
        $store->assign(9, [$second]);
        foreach (['cat_assign', 'cat_rename', 'cat_move', 'cat_unassign', 'toggle_onrequest', 'toggle_soldout'] as $command) {
            $db->assignment = ['aktiv' => 1, 'last_article_hash' => 'previous-hash'];
            $response = assignmentCommand($page, $command, [
                'category' => $category, 'name' => 'Hotend-Ersatzteile', 'parent' => $second, 'sort' => 1,
                'enabled' => '1',
            ]);
            expect(true, $response['ok']);
            expect('', $db->assignment['last_article_hash']);
        }
        expect([$second], $store->categoriesOf(9));
    },
    'empty_tree_opens_category_creator_and_populated_tree_adds_row_assign_select' => function (): void {
        [$page, , $store] = assignmentSetup();
        $emptyHtml = $page->render();
        expect(true, str_contains($emptyHtml, '<details class="wc-assignment-panel" open>'));
        expect(true, str_contains($emptyHtml, '+ Neue Kategorie'));
        expect(false, str_contains($emptyHtml, 'class="wc-assignment-category-select"'));

        $parent = $store->create('Ersatzteile');
        $child = $store->create('Hotends', $parent);
        $populatedHtml = $page->render();
        expect(false, str_contains($populatedHtml, '<details class="wc-assignment-panel" open>'));
        expect(true, str_contains($populatedHtml, 'Kategorie zuordnen'));
        expect(true, str_contains($populatedHtml, 'class="wc-assignment-category-select" data-command="cat_assign"'));
        expect(true, str_contains($populatedHtml, '<option value="' . $child . '">&nbsp;&nbsp;Hotends</option>'));
    },
    'stock_column_shows_inline_form_for_single_place' => function (): void {
        [$page, $db] = assignmentSetup();
        $db->addStock(9, 1, '7.0000');
        $html = $page->render();
        expect(true, str_contains($html, '<th scope="col" class="wc-col-name">Name</th><th scope="col" class="wc-col-stock">Lagerbestand</th>'));
        expect(true, str_contains($html, '<form class="wc-stock-inline" data-command="stock_set">'));
        expect(true, str_contains($html, 'data-place="1" data-expected="7.0000" value="7"'));
        expect(false, str_contains($html, 'data-stock-dialog'));
    },
    'stock_column_without_places_offers_standard_storage_input' => function (): void {
        [$page] = assignmentSetup();
        expect(true, str_contains($page->render(), 'data-place="0" data-expected="0" value="0"'));
    },
    'stock_column_opens_dialog_for_multiple_places' => function (): void {
        [$page, $db] = assignmentSetup();
        $db->addStock(9, 1, '5.0000');
        $db->addStock(9, 2, '2.5000');
        $html = $page->render();
        expect(true, str_contains($html, '<span class="wc-stock-total">7,5</span>'));
        expect(true, str_contains($html, 'data-stock-dialog'));
        expect(true, str_contains($html, '<template class="wc-stock-template">'));
        expect(true, str_contains($html, 'data-place="1" data-expected="5.0000" value="5"'));
        expect(true, str_contains($html, 'data-place="2" data-expected="2.5000" value="2,5"'));
        expect(false, str_contains($html, 'wc-stock-inline'));
    },
    'stock_column_is_read_only_for_tracked_non_stock_and_missing_rights' => function (): void {
        [$page, $db, , $app] = assignmentSetup();
        $db->addStock(9, 1, '4.0000');
        $db->article['seriennummern'] = 'eigene';
        $html = $page->render();
        expect(true, str_contains($html, 'Korrektur im Lager-Modul'));
        expect(false, str_contains($html, 'data-command="stock_set"'));
        $db->article['seriennummern'] = 'keine';
        $db->article['lagerartikel'] = '0';
        $html = $page->render();
        expect(true, str_contains($html, '<td class="wc-col-stock">&ndash;</td>'));
        expect(false, str_contains($html, 'data-command="stock_set"'));
        $db->article['lagerartikel'] = '1';
        $app->erp->rights = ['artikel', 'onlineshops', 'lager/bucheneinlagern'];
        $html = $page->render();
        expect(true, str_contains($html, '<td class="wc-col-stock"><span class="wc-stock-total">4</span></td>'));
        expect(false, str_contains($html, 'data-command="stock_set"'));
    },
    'stock_query_runs_once_per_page' => function (): void {
        [$page, $db] = assignmentSetup();
        foreach ([21, 22, 23] as $id) {
            $db->catalog[$id] = array_merge($db->article, ['id' => (string) $id, 'nummer' => 'ERP-' . $id, 'in_shop' => 0]);
        }
        $html = $page->render();
        expect(1, $db->stockQueries);
        expect(3, substr_count($html, 'class="wc-col-stock"><form'));
    },
    'stock_set_books_difference_and_returns_new_cell' => function (): void {
        [$page, $db, , $app] = assignmentSetup();
        $db->addStock(9, 1, '7.0000');
        $result = assignmentCommand($page, 'stock_set', ['counts' => '1:7.0000:5']);
        expect(true, $result['ok']);
        expect(9, $result['article']);
        expect([['aus', 9, 1, 2.0, 4, StockCorrection::REASON]], $app->erp->bookings);
        expect(true, str_starts_with($result['cell'], '<td class="wc-col-stock">'));
        expect(true, str_contains($result['cell'], 'data-place="1" data-expected="5.0000" value="5"'));
    },
    'stock_set_requires_token_and_lager_rights' => function (): void {
        [$page, $db, , $app] = assignmentSetup();
        $db->addStock(9, 1, '7.0000');
        expect(false, assignmentCommand($page, 'stock_set', ['counts' => '1:7.0000:5', 'csrf_token' => 'wrong'])['ok']);
        $app->erp->rights = ['artikel', 'onlineshops', 'lager/buchenauslagern'];
        $result = assignmentCommand($page, 'stock_set', ['counts' => '1:7.0000:5']);
        expect(false, $result['ok']);
        expect('Keine Berechtigung fuer Lagerbuchungen.', $result['error']);
        expect([], $app->erp->bookings);
    },
    'stock_set_respects_article_project_right' => function (): void {
        [$page, $db, , $app] = assignmentSetup();
        $db->addStock(9, 1, '7.0000');
        $app->erp->projects = [3];
        $result = assignmentCommand($page, 'stock_set', ['counts' => '1:7.0000:5']);
        expect(false, $result['ok']);
        expect('Artikel nicht vorhanden oder nicht freigegeben.', $result['error']);
        expect([], $app->erp->bookings);
    },
    'stock_set_rejects_malformed_counts' => function (): void {
        [$page, $db, , $app] = assignmentSetup();
        $db->addStock(9, 1, '7.0000');
        foreach (['1:7:abc', '', '1:7', '1:7:5;;2:1:1', str_repeat('1:7:5;', 50) . '1:7:5'] as $counts) {
            $result = assignmentCommand($page, 'stock_set', ['counts' => $counts]);
            expect(false, $result['ok']);
            expect('Ungueltige Eingabe.', $result['error']);
        }
        expect([], $app->erp->bookings);
    },
    'stock_set_does_not_open_a_database_transaction' => function (): void {
        [$page, $db] = assignmentSetup();
        $db->addStock(9, 1, '7.0000');
        expect(true, assignmentCommand($page, 'stock_set', ['counts' => '1:7.0000:6,5'])['ok']);
        expect(0, $db->transactions);
    },
    'stock_set_accepts_unchanged_negative_place_from_dialog' => function (): void {
        [$page, $db, , $app] = assignmentSetup();
        $db->addStock(9, 1, '5.0000');
        $db->addStock(9, 2, '-2.0000');
        $result = assignmentCommand($page, 'stock_set', ['counts' => '1:5.0000:4;2:-2.0000:-2']);
        expect(true, $result['ok']);
        expect([['aus', 9, 1, 1.0, 4, StockCorrection::REASON]], $app->erp->bookings);
    },
    'stock_set_reports_partial_failure_text' => function (): void {
        [$page, $db, , $app] = assignmentSetup();
        $db->addStock(9, 1, '5.0000');
        $db->addStock(9, 2, '3.0000');
        $app->erp->failAt = 2;
        $result = assignmentCommand($page, 'stock_set', ['counts' => '1:5.0000:4;2:3.0000:1']);
        expect(false, $result['ok']);
        expect(true, str_starts_with($result['error'], 'Teilweise gebucht (A-01). Fehler bei B-01:'));
    },
];

function expect(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assignmentSetup(): array
{
    if (!class_exists(AssignmentPage::class)) {
        throw new RuntimeException('AssignmentPage is not implemented.');
    }
    $db = new AssignmentFixtureDatabase();
    $app = assignmentFixtureApp($db);
    return [new AssignmentPage($app), $db, new ShopCategoryStore($db, 7), $app];
}

function assignmentCommand(AssignmentPage $page, string $command, array $post = []): array
{
    return $page->handle($command, $post + ['shop' => '7', 'article' => '9', 'enabled' => '0', 'csrf_token' => 'assignment-token']);
}

$failed = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo "PASS $name\n";
    } catch (Throwable $error) {
        echo "FAIL $name: {$error->getMessage()}\n";
        $failed++;
    }
}
echo 'Assignment tests: ' . (count($tests) - $failed) . '/' . count($tests) . " passed\n";
exit($failed === 0 ? 0 : 1);
