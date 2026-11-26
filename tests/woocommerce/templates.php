<?php
// SPDX-License-Identifier: AGPL-3.0-only
require __DIR__ . '/bootstrap.php';

use Xentral\Components\WooCommerce\ArticleFieldCatalog;
use Xentral\Components\WooCommerce\TemplateInstaller;

require __DIR__ . '/../../www/pages/importvorlage.php';
require __DIR__ . '/../../www/pages/exportvorlage.php';

final class TemplateDatabaseFake
{
    public array $templates = ['exportvorlage' => [], 'importvorlage' => []];
    public array $config = [];
    public int $inserts = 0;
    private int $lastId = 0;

    public function fetchValue($sql, $params)
    {
        if (str_contains($sql, '_LOCK(')) {
            return 1;
        }
        if (preg_match('/SELECT id FROM (exportvorlage|importvorlage)/', $sql, $match)) {
            return $this->templates[$match[1]][$params['label']]['id'] ?? false;
        }
        if (str_contains($sql, 'FROM konfiguration')) {
            return $this->config[$params['name']] ?? false;
        }
        throw new LogicException($sql);
    }

    public function perform($sql, $params): void
    {
        if (preg_match('/INSERT INTO (exportvorlage|importvorlage)/', $sql, $match)) {
            $this->templates[$match[1]][$params['label']] = ['id' => ++$this->lastId, 'bezeichnung' => $params['label']] + $params;
            ++$this->inserts;
        } elseif (str_contains($sql, 'INSERT INTO konfiguration')) {
            $this->config[$params['name']] = $params['wert'];
        } else {
            throw new LogicException($sql);
        }
    }

    public function lastInsertId(): int { return $this->lastId; }
    public function beginTransaction(): void {}
    public function commit(): void {}
    public function rollBack(): void {}
}

function installedTemplates(bool $includePrice = true): array
{
    check(class_exists(TemplateInstaller::class), 'TemplateInstaller fehlt');
    $db = new TemplateDatabaseFake();
    $ids = (new TemplateInstaller($db))->ensure($includePrice);
    return [$db, $db->templates['exportvorlage']['WooCommerce Artikeldaten'], $db->templates['importvorlage']['WooCommerce Artikeldaten'], $ids];
}


final class CsvImportDatabaseFake
{
    public array $article = ['id' => 9, 'nummer' => 'A9', 'pseudolager' => '0'];
    public array $overrides = [
        7 => ['id' => 70, 'shop' => 7, 'pseudolager' => '0', 'lieferzeitmanuell' => 'alt'],
        71 => ['id' => 71, 'shop' => 7, 'pseudolager' => '1', 'lieferzeitmanuell' => 'weiterer Wert'],
        8 => ['id' => 80, 'shop' => 8, 'pseudolager' => '4', 'lieferzeitmanuell' => 'alt'],
        11 => ['id' => 110, 'shop' => 11, 'pseudolager' => '5'],
    ];
    public array $audit = [];
    public array $prices = [['id' => 1, 'preis' => '35', 'active' => true]];
    private bool $transaction = false;
    public function __construct(public array $template) {}

    public function SelectRow($sql) { return $this->template; }
    public function Select($sql)
    {
        if (str_contains(str_replace(chr(96), '', $sql), 'FROM importvorlage')) {
            return str_contains($sql, 'fields') ? $this->template['fields'] : 'artikel';
        }
        if (str_contains($sql, "WHERE " . chr(96) . "nummer" . chr(96) . "= 'A9'")) {
            return 9;
        }
        if (str_contains($sql, 'SELECT preis FROM verkaufspreise')) {
            check(str_contains($sql, "ab_menge='1'"));
            $rows = array_filter($this->prices, static fn ($row) => $row['active']);
            return reset($rows)['preis'];
        }
        if (str_contains($sql, "WHERE id ='9'")) {
            return 9;
        }
        return false;
    }
    public function SelectArr($sql): array { return []; }
    public function real_escape_string($value): string { return addslashes($value); }
    public function Delete($sql): void {}
    public function Update($sql): void
    {
        if (preg_match("/^UPDATE artikel SET (\\w+)='(.*)' WHERE id='9' LIMIT 1$/s", $sql, $match)) {
            $this->article[$match[1]] = stripslashes($match[2]);
        } elseif (str_contains($sql, 'UPDATE verkaufspreise SET gueltig_bis')) {
            check(str_contains($sql, "ab_menge='1' LIMIT 1"));
            // The legacy UPDATE has no validity predicate or ORDER BY.
            $this->prices[0]['active'] = false;
        } else {
            throw new LogicException($sql);
        }
    }

    public function fetchRow(string $sql, array $params)
    {
        if (str_contains($sql, 'FROM artikel WHERE')) {
            return $this->article;
        }
        foreach ($this->overrides as $override) {
            if ($override['shop'] === $params['shop'] && ($params['override'] ?? $override['id']) === $override['id']) {
                return $override;
            }
        }
        return false;
    }
    public function fetchAll(string $sql, array $params): array
    {
        if (str_contains($sql, 'FROM verkaufspreise')) {
            return $this->prices;
        }
        check(str_contains($sql, 'FROM artikel_onlineshops') && str_contains($sql, 'ausartikel = 0'));
        check(str_contains($sql, 'shopimporter_woocommerce') && str_contains($sql, 's.geloescht = 0'));
        same(9, $params['article']);
        return [$this->overrides[7], $this->overrides[71], $this->overrides[8]];
    }
    public function fetchValue(string $sql, array $params)
    {
        return $this->audit[$params['name'] ?? ''] ?? false;
    }
    public function perform(string $sql, array $params): void
    {
        if (preg_match('/^UPDATE artikel SET .(\w+)./', $sql, $match)) {
            $this->article[$match[1]] = $params['value'];
        } elseif (preg_match('/^UPDATE artikel_onlineshops SET .(\w+)./', $sql, $match)) {
            foreach ($this->overrides as &$override) {
                if ($override['id'] === $params['id']) {
                    $override[$match[1]] = $params['value'];
                }
            }
            unset($override);
        } elseif (str_contains($sql, 'INSERT INTO konfiguration')) {
            $this->audit[$params['name']] = $params['wert'] ?? ($this->audit[$params['name']] ?? '[]');
        } else {
            throw new LogicException($sql);
        }
    }
    public function inTransaction(): bool { return $this->transaction; }
    public function beginTransaction(): void { $this->transaction = true; }
    public function commit(): void { $this->transaction = false; }
    public function rollBack(): void { $this->transaction = false; }
}

function runCsvImport(CsvImportDatabaseFake $db, string $csv, int $id): array
{
    $path = tmpfile();
    fwrite($path, $csv);
    try {
        $app = (object) ['DB' => $db, 'Container' => new class($db) {
            public function __construct(private $db) {}
            public function get($name) { return $this->db; }
        }, 'erp' => new class($db, $id) {
            public function __construct(private $db, private int $id) {}
            public function GetKonfiguration($name) { return '42,' . $this->id; }
            public function RunHook(...$args): void {}
            public function ImportCreateArtikel(...$args): int { throw new LogicException('Artikel wurde angelegt'); }
            public function AddVerkaufspreis($id, $amount, $address, $price, ...$args): void
            {
                same(9, (int) $id);
                same(1, (int) $amount);
                same(0, $address);
                $this->db->prices[] = ['id' => count($this->db->prices) + 1, 'preis' => (string) $price, 'active' => true];
            }
        }];
        return (new Importvorlage($app, true))->ImportvorlageDo('utf8', [
            'id' => $id, 'row' => [], 'stueckliste_csv' => stream_get_meta_data($path)['uri'],
        ]);
    } catch (ErrorException $error) {
        throw new RuntimeException($error->getMessage() . " in " . $error->getFile() . ":" . $error->getLine());
    } finally {
        fclose($path);
    }
}

function csvImportTemplate(string $fields): array
{
    return ['fields' => $fields, 'importtrennzeichen' => 'semikolon', 'importerstezeilenummer' => 2,
        'importdatenmaskierung' => 'gaensefuesschen', 'importzeichensatz' => ''];
}

runTests([
    'csv_text_round_trips_with_reserved_characters' => static function () {
        [$templates, $export, $import, $ids] = installedTemplates(false);
        $value = "D\u{00FC}se ae %title% | Partner 3D; \"Set\"\r\nZeile 2";
        $row = array_fill_keys(array_column(ArticleFieldCatalog::csvColumns(), 'import'), '0');
        unset($row['verkaufspreis1netto'], $row['verkaufspreis1menge']);
        $row['nummer'] = 'A9';
        $row['metatitle_de'] = $value;
        $row['pseudolager'] = '';
        $row['lieferzeitmanuell'] = '3 Tage';
        $exporter = new Exportvorlage((object) [
            'DB' => new class($export, $row) {
                private int $cursor = 0;
                public int $num_rows = 1;
                public function __construct(private $template, private $row) {}
                public function SelectRow($sql): array
                {
                    return ['exporttrennzeichen' => $this->template['trennzeichen'],
                        'exporterstezeilenummer' => $this->template['erstezeile'],
                        'exportdatenmaskierung' => $this->template['maskierung'], 'fields_where' => ''] + $this->template;
                }
                public function Query($sql) { return $this; }
                public function SelectArr($sql): array { return []; }
                public function data_seek(int $offset): void { $this->cursor = $offset; }
                public function Fetch_Assoc($query) { return $this->cursor++ === 0 ? $this->row + ['systemid' => 9] : null; }
            },
            'Secure' => new class { public function GetGET($field) { return ''; } },
            'erp' => new class {
                public function RunHook(...$args): void {}
                public function fixeUmlaute($value) { return $value; }
                public function ParseDecimalForCSV($value) { return $value; }
            },
        ], true);
        $csv = $exporter->ExportvorlageExport(true, $ids['export'], ['von' => '']);
        check(!str_contains(strtok($csv, "\r\n"), 'systemid'));
        $db = new CsvImportDatabaseFake(csvImportTemplate($import['fields']));
        $result = runCsvImport($db, $csv, $ids['import']);
        same('', $result['message']);
        same($value, $db->article['metatitle_de']);
        same('3 Tage', $db->article['lieferzeitmanuell']);
        same('', $db->article['pseudolager']);
        foreach ([7, 8] as $shop) {
            same('3 Tage', $db->overrides[$shop]['lieferzeitmanuell']);
            same('', $db->overrides[$shop]['pseudolager']);
            same('csv_import', json_decode($db->audit['woocommerce_pull_log_' . $shop], true)[0]['origin']);
        }
        same('', $db->overrides[71]['pseudolager']);
        same('3 Tage', $db->overrides[71]['lieferzeitmanuell']);
        same('5', $db->overrides[11]['pseudolager']);
    },
    'unknown_article_is_skipped' => static function () {
        $db = new CsvImportDatabaseFake(csvImportTemplate("1:nummer;\r\n2:shop_name_de;\r\n"));
        $result = runCsvImport($db, "nummer;shop_name_de\nMISSING;Name\nA9;Bekannter Artikel\n", 12);
        same('Artikelnummer MISSING unbekannt, uebersprungen', $result['message']);
        same('Bekannter Artikel', $db->article['shop_name_de']);
    },
    'preview_marks_unknown_articles_as_skipped' => static function () {
        $db = new CsvImportDatabaseFake(csvImportTemplate("1:nummer;\r\n2:shop_name_de;\r\n"));
        $importer = new Importvorlage((object) ['DB' => $db], true);
        $fields = $importer->ImportvorlageGetFieldsNew(12);
        $create = $update = 0;
        $preview = [];
        $importer->ImportPrepareRow(2, 'artikel', ['MISSING', 'Neuer Name'], $fields, $create, $update, $preview, [], true);
        $importer->ImportPrepareRow(3, 'artikel', ['A9', 'Bekannter Artikel'], $fields, $create, $update, $preview, [], true);
        same(0, $create);
        same(1, $update);
        same(['skip', 'update'], array_column($preview, 'action'));
        same('Artikelnummer MISSING unbekannt, uebersprungen', $preview[0]['action_anzeige']);
    },
    'truncated_csv_row_cannot_clear_fields' => static function () {
        $db = new CsvImportDatabaseFake(csvImportTemplate("1:nummer;\r\n2:pseudolager;\r\n"));
        $result = runCsvImport($db, "nummer;pseudolager\nA9\n", 12);
        same(false, $result['success']);
        same('0', $db->article['pseudolager']);
        same('4', $db->overrides[8]['pseudolager']);
    },
    'legacy_price_import_updates_single_standard_price_at_quantity_one' => static function () {
        $db = new CsvImportDatabaseFake(csvImportTemplate("1:nummer;\r\n2:verkaufspreis1netto;\r\n3:verkaufspreis1menge;\r\n"));
        $result = runCsvImport($db, "nummer;preis;menge\nA9;29,90;1\n", 12);
        same('', $result['message']);
        $active = array_values(array_filter($db->prices, static fn ($price) => $price['active']));
        same(['29.9'], array_column($active, 'preis'));
    },
    'legacy_price_import_can_leave_two_export_candidates_with_history' => static function () {
        $db = new CsvImportDatabaseFake(csvImportTemplate("1:nummer;\r\n2:verkaufspreis1netto;\r\n3:verkaufspreis1menge;\r\n"));
        array_unshift($db->prices, ['id' => 0, 'preis' => '40', 'active' => false]);
        runCsvImport($db, "nummer;preis;menge\nA9;29,90;1\n", 12);
        $active = array_values(array_filter($db->prices, static fn ($price) => $price['active']));
        // The WooCommerce export picks the higher candidate, leaving the old value visible.
        same(2, count($active));
        same(35.0, max(array_map('floatval', array_column($active, 'preis'))));
    },

    'ensure_is_idempotent' => static function () {
        [$db, $export, $import, $ids] = installedTemplates();
        $db->templates['exportvorlage']['WooCommerce Artikeldaten']['fields'] .= ';custom';
        same($ids, (new TemplateInstaller($db))->ensure(false));
        same(2, $db->inserts);
        same($export['fields'] . ';custom', $db->templates['exportvorlage']['WooCommerce Artikeldaten']['fields']);
        same($import, $db->templates['importvorlage']['WooCommerce Artikeldaten']);
    },
    'export_and_import_columns_align' => static function () {
        [, $export, $import] = installedTemplates();
        $columns = ArticleFieldCatalog::csvColumns();
        same(array_column($columns, 'export'), explode(";\r\n", $export['fields']));
        $expected = [];
        foreach ($columns as $index => $column) {
            $expected[] = ($index + 1) . ':' . $column['import'] . ";\r\n";
        }
        same(implode('', $expected), $import['fields']);
    },
    'templates_use_utf8_without_decode' => static function () {
        [, $export, $import] = installedTemplates();
        same(['artikel', 'semikolon', 'gaensefuesschen', 1, 'UTF-8'], [
            $export['ziel'], $export['trennzeichen'], $export['maskierung'], $export['erstezeile'], $export['zeichensatz'],
        ]);
        same(['artikel', 'semikolon', 'gaensefuesschen', 2, 'utf8', 0], [
            $import['ziel'], $import['trennzeichen'], $import['maskierung'], $import['erstezeile'], $import['charset'], $import['utf8decode'],
        ]);
    },
    'price_columns_omitted_with_price_group' => static function () {
        [, $export, $import] = installedTemplates(false);
        check(!str_contains($export['fields'], 'standardpreis'));
        check(!str_contains($import['fields'], 'verkaufspreis'));
        same(count(ArticleFieldCatalog::csvColumns()) - 2, count(explode(";\r\n", $export['fields'])));
    },
    'update_only_registered' => static function () {
        $db = new TemplateDatabaseFake();
        $db->config['importvorlage_update_only'] = '42,120';
        $ids = (new TemplateInstaller($db))->ensure(true);
        same('42,120,' . $ids['import'], $db->config['importvorlage_update_only']);
    },
    'mapped_fields_supported_by_article_import' => static function () {
        $source = file_get_contents(__DIR__ . '/../../www/pages/importvorlage.php');
        $start = strpos($source, "case \"einkauf\":");
        $end = strpos($source, 'HERE END artikel einkauf', $start);
        $source = substr($source, $start, $end - $start);
        foreach (array_column(ArticleFieldCatalog::csvColumns(), 'import') as $column) {
            check((bool) preg_match('/case\s+"' . $column . '":/', $source), 'Feld fehlt im Artikelimport: ' . $column);
        }
    },
]);
