<?php

use Xentral\Components\Database\Adapter\MysqliAdapter;
use Xentral\Components\Database\Database;
use Xentral\Components\Database\DatabaseConfig;
use Xentral\Components\Database\SqlQuery\QueryFactory;
use Xentral\Modules\Api\Exception\InvalidArgumentException;
use Xentral\Modules\Api\Resource\ArticleOnlineShopResource;
use Xentral\Modules\Api\Resource\ArticleTreeArticleResource;
use Xentral\Modules\Api\Validator\Rule\DbValueRule;
use Xentral\Modules\Api\Validator\Rule\NotPresentRule;
use Xentral\Modules\Api\Validator\Validator;

// Run with API_TEST_DB_HOST, API_TEST_DB_PORT, API_TEST_DB_USER,
// API_TEST_DB_PASSWORD and API_TEST_DB_NAME for a disposable MariaDB database.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

function expectSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
}

$dbName = getenv('API_TEST_DB_NAME');
if (!$dbName) {
    throw new RuntimeException('Set API_TEST_DB_NAME to a disposable database.');
}
$config = new DatabaseConfig(
    getenv('API_TEST_DB_HOST') ?: '127.0.0.1',
    getenv('API_TEST_DB_USER') ?: 'root',
    getenv('API_TEST_DB_PASSWORD'),
    $dbName,
    'utf8mb4',
    (int)(getenv('API_TEST_DB_PORT') ?: 3306)
);
$db = new Database(new MysqliAdapter($config), new QueryFactory('mysql'));
$db->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES'");

$schema = file_get_contents(dirname(__DIR__, 2) . '/database/struktur.sql');
foreach (['artikel_onlineshops', 'artikelbaum_artikel'] as $table) {
    if (!preg_match('/^CREATE TABLE `' . $table . '` \([\s\S]*?^\) ENGINE=[^;]+;/m', $schema, $match)) {
        throw new RuntimeException('Missing schema for ' . $table);
    }
    $db->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $match[0]));
}
$db->exec('CREATE TEMPORARY TABLE artikel (id INT PRIMARY KEY)');
$db->exec('CREATE TEMPORARY TABLE shopexport (id INT PRIMARY KEY)');
$db->exec('CREATE TEMPORARY TABLE artikelkategorien (
    id INT PRIMARY KEY, bezeichnung VARCHAR(64), parent INT, projekt INT, geloescht INT
)');
$db->exec('CREATE TEMPORARY TABLE api_mapping (id_int INT, tabelle VARCHAR(64))');
$db->exec('INSERT INTO artikel VALUES (123)');
$db->exec('INSERT INTO shopexport VALUES (2)');
$db->exec("INSERT INTO artikelkategorien VALUES (5, 'Test', 0, 0, 0)");

$validator = new Validator();
$validator->addValidator('db_value', new DbValueRule($db));
$validator->addValidator('not_present', new NotPresentRule());
$tree = new ArticleTreeArticleResource($db, $validator);
$shops = new ArticleOnlineShopResource($db, $validator);

$tests = [
    'empty category include' => function () use ($tree) {
        expectSame([], $tree->getList([], [], [], ['kategorie'])->getData(), 'Empty list');
    },
    'unknown include on empty page' => function () use ($tree) {
        try {
            $tree->getList([], [], [], ['kategorie', 'unknown']);
        } catch (InvalidArgumentException $exception) {
            expectSame(400, $exception->getStatusCode(), 'Unknown include status');
            return;
        }
        throw new RuntimeException('Unknown include was accepted');
    },
    'category assignment writes and populated include' => function () use ($tree) {
        $input = ['artikel' => 123, 'kategorie' => 5, 'haupt' => 1];
        $extra = ['storage_cache' => 123, 'unknown' => 'ignored'];
        $created = $tree->insert($input + $extra)->getData();
        expectSame(1, (int)$created['haupt'], 'Created main assignment');
        $input['haupt'] = 0;
        $updated = $tree->edit($created['id'], $input + $extra)->getData();
        expectSame(0, (int)$updated['haupt'], 'Updated main assignment');
        $rows = $tree->getList([], [], [], ['kategorie'])->getData();
        expectSame('Test', $rows[0]['kategorie']['bezeichnung'], 'Expanded category');
        expectSame([], $tree->getList([], [], [], ['kategorie'], 2, 1)->getData(), 'Empty later page');
    },
    'minimal shop assignment gets strict-mode defaults' => function () use ($db, $shops) {
        $row = $shops->insert(['artikel' => 123, 'shop' => 2])->getData();
        expectSame('', $row['pseudolager'], 'Stock default');
        expectSame('', $row['lieferzeitmanuell'], 'Delivery time default');
        expectSame('', $db->fetchValue(
            'SELECT last_article_hash FROM artikel_onlineshops WHERE id = :id', ['id' => $row['id']]
        ), 'Internal hash default');
    },
    'shop assignment writes accept only public fields' => function () use ($db, $shops) {
        $input = ['artikel' => 123, 'shop' => 2];
        $extra = ['last_article_hash' => 'from-client', 'storage_cache' => 99, 'unknown' => 1];
        $created = $shops->insert($input + [
            'pseudolager' => '7', 'lieferzeitmanuell' => '2 Tage',
        ] + $extra)->getData();
        expectSame('7', $created['pseudolager'], 'Explicit stock on insert');
        expectSame('2 Tage', $created['lieferzeitmanuell'], 'Explicit delivery time on insert');
        $id = ['id' => $created['id']];
        $internal = 'SELECT last_article_hash, storage_cache FROM artikel_onlineshops WHERE id = :id';
        expectSame(['last_article_hash' => '', 'storage_cache' => null], $db->fetchRow($internal, $id),
            'Internal fields after insert');
        $db->perform("UPDATE artikel_onlineshops SET last_article_hash = 'existing' WHERE id = :id", $id);
        $updated = $shops->edit($created['id'], $input + ['lieferzeitmanuell' => '3 Tage'] + $extra)->getData();
        expectSame('7', $updated['pseudolager'], 'Omitted stock preserved on update');
        expectSame('3 Tage', $updated['lieferzeitmanuell'], 'Updated delivery time');
        expectSame(['last_article_hash' => 'existing', 'storage_cache' => null], $db->fetchRow($internal, $id),
            'Internal fields after update');
    },
];

$failures = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo "PASS: $name\n";
    } catch (Throwable $exception) {
        ++$failures;
        fwrite(STDERR, "FAIL: $name: " . $exception->getMessage() . "\n");
    }
}
printf("PHP %s: %d passed, %d failed\n", PHP_VERSION, count($tests) - $failures, $failures);
exit($failures > 0 ? 1 : 0);
