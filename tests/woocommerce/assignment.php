<?php
// SPDX-License-Identifier: AGPL-3.0-only

use Xentral\Components\WooCommerce\AssignmentPage;
use Xentral\Components\WooCommerce\ShopCategoryStore;
use Xentral\Components\WooCommerce\VisibilityQueue;

require_once __DIR__ . '/fixtures/assignment.php';

$tests = [
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
