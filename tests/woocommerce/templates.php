<?php
// SPDX-License-Identifier: AGPL-3.0-only
require __DIR__ . '/bootstrap.php';

use Xentral\Components\Http\Session\Session;
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

function installedTemplates(): array
{
    check(class_exists(TemplateInstaller::class), 'TemplateInstaller fehlt');
    $db = new TemplateDatabaseFake();
    $ids = (new TemplateInstaller($db))->ensure();
    return [$db, $db->templates['exportvorlage']['WooCommerce Artikeldaten'], $db->templates['importvorlage']['WooCommerce Artikeldaten'], $ids];
}


final class CsvImportDatabaseFake
{
    public array $article = ['id' => 9, 'nummer' => 'A9', 'projekt' => 0, 'pseudolager' => '0'];
    public array $shopProjects = [];
    public bool $shopEdit = true;
    public array $overrides = [
        7 => ['id' => 70, 'shop' => 7, 'pseudolager' => '0', 'lieferzeitmanuell' => 'alt'],
        71 => ['id' => 71, 'shop' => 7, 'pseudolager' => '1', 'lieferzeitmanuell' => 'weiterer Wert'],
        8 => ['id' => 80, 'shop' => 8, 'pseudolager' => '4', 'lieferzeitmanuell' => 'alt'],
        11 => ['id' => 110, 'shop' => 11, 'pseudolager' => '5'],
    ];
    public array $audit = [];
    public array $writes = [];
    public array $lookups = [];
    public array $activeShopIds = [7, 8];
    public array $priceGroups = [];
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
        if (str_contains($sql, 'SELECT id FROM adresse WHERE')) {
            $this->lookups[] = $sql;
            return 10;
        }
        if (str_contains($sql, "WHERE id ='9'")) {
            return 9;
        }
        return false;
    }
    public function SelectArr($sql): array { return []; }
    public function real_escape_string($value): string { return addslashes($value); }
    public function Delete($sql): void { $this->writes[] = $sql; }
    public function Update($sql): void
    {
        $this->writes[] = $sql;
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
            check(str_contains($sql, 'LIMIT 2 FOR UPDATE'));
            $active = array_values(array_filter($this->prices, static fn ($price) => $price['active']));
            usort($active, static fn ($a, $b) => (float) $b['preis'] <=> (float) $a['preis']);
            return array_slice($active, 0, 2);
        }
        if (str_contains($sql, 'FROM shopexport')) {
            check(str_contains($sql, 'a.aktiv = 1') && !str_contains($sql, 'ausartikel'));
            check(str_contains($sql, 'shopimporter_woocommerce') && str_contains($sql, 's.geloescht = 0'));
            check(str_contains($sql, 's.aktiv = 1'), 'price import must ignore deactivated shops');
            same(9, $params['article']);
            return array_map(fn ($id) => ['shop' => $id, 'projekt' => $this->shopProjects[$id] ?? 0], $this->activeShopIds);
        }
        check(str_contains($sql, 'FROM artikel_onlineshops') && str_contains($sql, 'ausartikel = 0'));
        check(str_contains($sql, 'shopimporter_woocommerce') && str_contains($sql, 's.geloescht = 0'));
        same(9, $params['article']);
        return array_map(fn ($override) => $override + ['projekt' => $this->shopProjects[$override['shop']] ?? 0],
            [$this->overrides[7], $this->overrides[71], $this->overrides[8]]);
    }
    public function fetchValue(string $sql, array $params)
    {
        if (str_contains($sql, 'SELECT s.preisgruppe')) {
            check(str_contains($sql, 'g.aktiv = 1') && str_contains($sql, 'FOR UPDATE'));
            return $this->priceGroups[$params['shop']] ?? false;
        }
        return $this->audit[$params['name'] ?? ''] ?? false;
    }
    public function perform(string $sql, array $params): void
    {
        $this->writes[] = $sql;
        if (str_starts_with($sql, 'UPDATE artikel_onlineshops SET last_article_hash')) {
            return;
        }
        if (str_starts_with($sql, 'UPDATE verkaufspreise SET gueltig_bis')) {
            foreach ($this->prices as &$price) {
                if ($price['id'] === $params['id']) {
                    $price['active'] = false;
                }
            }
            unset($price);
        } elseif (str_starts_with($sql, 'INSERT INTO verkaufspreise')) {
            $this->prices[] = ['id' => count($this->prices) + 1, 'preis' => $params['value'], 'active' => true];
        } elseif (preg_match('/^UPDATE artikel SET .(\w+)./', $sql, $match)) {
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

function runCsvImport(CsvImportDatabaseFake $db, string $csv, int $id, bool $confirm = true): array
{
    $path = tmpfile();
    fwrite($path, $csv);
    try {
        $app = (object) ['DB' => $db, 'Container' => new class($db) {
            public function __construct(private $db) {}
            public function get($name) { return $this->db; }
        }, 'User' => new class {
            public function GetID(): int { return 42; }
        }, 'erp' => new class($db) {
            public function __construct(private $db) {}
            public function GetKonfiguration($name) { return '42,12,2'; }
            public function RunHook(...$args): void {}
            public function UserProjektRecht(int $project): bool { return $project === 0; }
            public function RechteVorhanden(string $module, string $action): bool { return $this->db->shopEdit; }
            public function ImportCreateArtikel(...$args): int { throw new LogicException('Artikel wurde angelegt'); }
            public function AddVerkaufspreis($id, $amount, $address, $price, ...$args): void
            {
                same(9, (int) $id);
                same(1, (int) $amount);
                same(0, $address);
                $this->db->prices[] = ['id' => count($this->db->prices) + 1, 'preis' => (string) $price, 'active' => true];
            }
        }];
        $importer = new Importvorlage($app, true);
        $file = stream_get_meta_data($path)['uri'];
        if ($confirm && TemplateInstaller::isUpdateOnly($id, $app->erp->GetKonfiguration(TemplateInstaller::UPDATE_ONLY))) {
            return (new ReflectionMethod($importer, 'executeConfirmedWooCsv'))
                ->invoke($importer, ['user' => 42, 'template' => $id, 'file' => $file], []);
        }
        return $importer->ImportvorlageDo('utf8', ['id' => $id, 'row' => [], 'stueckliste_csv' => $file]);
    } catch (ErrorException $error) {
        throw new RuntimeException($error->getMessage() . " in " . $error->getFile() . ":" . $error->getLine());
    } finally {
        fclose($path);
    }
}

function csvImportTemplate(string $fields): array
{
    $fields = implode(";\r\n", preg_split('/;\s*/', rtrim($fields, "; \r\n")));
    return ['fields' => $fields, 'importtrennzeichen' => 'semikolon', 'importerstezeilenummer' => 2,
        'importdatenmaskierung' => 'gaensefuesschen', 'importzeichensatz' => ''];
}

runTests([
    'csv_text_round_trips_with_reserved_characters' => static function () {
        [$templates, $export, $import, $ids] = installedTemplates();
        $value = "D\u{00FC}se ae %title% | Partner 3D; \"Set\"\r\nZeile 2";
        $headers = array_map(static fn ($field) => $field === 'woo_preis_netto' ? 'standardpreisnetto' : $field,
            array_column(ArticleFieldCatalog::csvColumns(), 'import'));
        $row = array_fill_keys($headers, '0');
        $row['standardpreisnetto'] = '29.90';
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
        same(['29.9'], array_column(array_filter($db->prices, static fn ($price) => $price['active']), 'preis'));
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
    'csv_confirmation_is_bound_to_the_preview_and_is_single_use' => static function () {
        $file = tmpfile();
        fwrite($file, "nummer;pseudolager
A9;3
");
        $path = stream_get_meta_data($file)['uri'];
        $session = new Session();
        $app = (object) [
            'Container' => new class ($session) {
                public function __construct(private Session $session) {}
                public function get($name): Session { return $this->session; }
            },
            'Secure' => new class {
                public string $token = '';
                public function GetPOST(...$args): string { return $this->token; }
            },
            'User' => new class {
                public int $id = 42;
                public function GetID(): int { return $this->id; }
            },
            'erp' => new class (dirname($path) . DIRECTORY_SEPARATOR) {
                public function __construct(private string $dir) {}
                public function GetTMP(): string { return $this->dir; }
            },
        ];
        $importer = new Importvorlage($app, true);
        $preview = new ReflectionMethod($importer, 'prepareWooCsvConfirmation');
        $confirm = new ReflectionMethod($importer, 'takeWooCsvConfirmation');
        $assertDenied = static function (int $template, int $job) use ($confirm, $importer): void {
            try {
                $confirm->invoke($importer, $template, $job);
                throw new LogicException('CSV confirmation unexpectedly accepted.');
            } catch (RuntimeException $error) {
                check(str_contains($error->getMessage(), 'Dateivorschau'));
            }
        };
        $method = $_SERVER['REQUEST_METHOD'] ?? null;
        try {
            $token = $preview->invoke($importer, 12, 4, $path);
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $assertDenied(12, 4);
            $app->Secure->token = $token;
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $assertDenied(12, 4);
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $assertDenied(12, 5);
            $assertDenied(13, 4);
            $app->User->id = 43;
            $assertDenied(12, 4);
            $app->User->id = 42;
            same($path, $confirm->invoke($importer, 12, 4)['file']);
            $assertDenied(12, 4);
            $app->Secure->token = $preview->invoke($importer, 12, 4, $path);
            fwrite($file, "A9;4
");
            $assertDenied(12, 4);
        } finally {
            fclose($file);
            if ($method === null) {
                unset($_SERVER['REQUEST_METHOD']);
            } else {
                $_SERVER['REQUEST_METHOD'] = $method;
            }
        }
    },
    'registered_csv_execution_requires_an_authorized_preview' => static function () {
        $db = new CsvImportDatabaseFake(csvImportTemplate('1:nummer;2:pseudolager'));
        $result = runCsvImport($db, "nummer;pseudolager
A9;7
", 12, false);
        same(false, $result['success']);
        check(str_contains($result['message'], 'Dateivorschau'));
        same([], $db->writes);
    },
    'registered_csv_skips_articles_outside_the_users_projects' => static function () {
        $db = new CsvImportDatabaseFake(csvImportTemplate('1:nummer;2:shop_name_de;3:woo_preis_netto'));
        $db->article['projekt'] = 12;
        $result = runCsvImport($db, "nummer;shop_name_de;standardpreisnetto
A9;Neuer Name;25
", 12);
        check(str_contains($result['message'], 'Projektzugriff'));
        same([], $db->writes);
    },
    'registered_csv_only_mirrors_fields_to_authorized_shops' => static function () {
        foreach ([true, false] as $shopEdit) {
            $db = new CsvImportDatabaseFake(csvImportTemplate('1:nummer;2:pseudolager;3:woo_preis_netto'));
            $db->shopProjects[8] = 12;
            $db->shopEdit = $shopEdit;
            $prices = $db->prices;
            $result = runCsvImport($db, "nummer;pseudolager;standardpreisnetto
A9;7;25
", 12);
            same('7', $db->article['pseudolager']);
            same($shopEdit ? '7' : '0', $db->overrides[7]['pseudolager']);
            same('4', $db->overrides[8]['pseudolager']);
            same($prices, $db->prices);
            check(str_contains($result['message'], 'Shopzugriff'));
        }
    },
    'ambiguous_price_is_reported_without_writes_even_when_it_matches_the_highest' => static function () {
        $db = new CsvImportDatabaseFake(csvImportTemplate("1:nummer;2:woo_preis_netto"));
        $db->prices[] = ['id' => 2, 'preis' => '34', 'active' => true];
        $before = $db->prices;
        $result = runCsvImport($db, "nummer;standardpreisnetto\nA9;35\n", 12);
        check(str_contains($result['message'], 'Mehrere gueltige Preise ab Menge 1'));
        check(str_contains($result['message'], 'Zeile: 1'));
        same([], $db->writes);
        same($before, $db->prices);
    },
    'non_numeric_price_is_reported_without_writes' => static function () {
        $db = new CsvImportDatabaseFake(csvImportTemplate("1:nummer;2:woo_preis_netto"));
        $before = $db->prices;
        $result = runCsvImport($db, "nummer;standardpreisnetto\nA9;n/a\n", 12);
        check(str_contains($result['message'], 'Ungueltiger Zahlenwert'));
        same([], $db->writes);
        same($before, $db->prices);
    },
    'zero_price_updates_only_the_exported_row_once' => static function () {
        $db = new CsvImportDatabaseFake(csvImportTemplate("1:nummer;2:woo_preis_netto"));
        $db->prices[] = ['id' => 8, 'preis' => '40', 'active' => false];
        $result = runCsvImport($db, "nummer;standardpreisnetto\nA9;0\n", 12);
        same('', $result['message']);
        $active = array_filter($db->prices, static fn ($price) => $price['active']);
        same(['0'], array_column($active, 'preis'));
        same(3, count($db->prices));
        same(['id' => 8, 'preis' => '40', 'active' => false], $db->prices[1]);
        same('csv_import', json_decode($db->audit['woocommerce_pull_log_7'], true)[0]['origin']);
    },
    'csv_variant_row_only_changes_fields_in_the_nested_export_payload' => static function () {
        $db = new CsvImportDatabaseFake(csvImportTemplate('1:nummer;2:ean;3:pseudolager;4:metatitle_de;5:woo_preis_netto'));
        $db->article += ['variante' => 1, 'variante_von' => 8, 'ean' => '111', 'metatitle_de' => 'Alt'];
        $before = $db->prices;
        $result = runCsvImport($db, "nummer;ean;pseudolager;metatitle_de;standardpreisnetto
A9;222;4;Neu;25
", 12);
        check(str_contains($result['message'], 'Variantenpreis'));
        same('222', $db->article['ean']);
        same('0', $db->article['pseudolager']);
        same('Alt', $db->article['metatitle_de']);
        same($before, $db->prices);
    },
    'unchanged_csv_values_preserve_different_effective_shop_overrides' => static function () {
        $db = new CsvImportDatabaseFake(csvImportTemplate('1:nummer;2:pseudolager;3:lieferzeitmanuell'));
        $db->article['lieferzeitmanuell'] = 'Artikelwert';
        $before = $db->overrides;
        $result = runCsvImport($db, "nummer;pseudolager;lieferzeitmanuell
A9;0;Artikelwert
", 12);
        same(true, $result['success']);
        same($before, $db->overrides);
        same([], $db->writes);
    },
    'empty_price_is_ignored' => static function () {
        $db = new CsvImportDatabaseFake(csvImportTemplate("1:nummer;2:woo_preis_netto"));
        same('', runCsvImport($db, "nummer;standardpreisnetto\nA9;\n", 12)['message']);
        same([], $db->writes);
    },
    'active_price_group_in_second_shop_prevents_shared_price_write' => static function () {
        $db = new CsvImportDatabaseFake(csvImportTemplate("1:nummer;2:woo_preis_netto"));
        $db->priceGroups[8] = 26;
        $before = $db->prices;
        $result = runCsvImport($db, "nummer;standardpreisnetto\nA9;29,90\n", 12);
        check(str_contains($result['message'], 'Shop nutzt eine Preisgruppe'));
        same([], $db->writes);
        same($before, $db->prices);
    },
    'header_mismatch_aborts_before_any_write' => static function () {
        foreach (['nummer;inaktiv;standardpreisnetto', 'nummer;standardpreisnetto;inaktiv;extra',
                  'nummer;standardpreisnetto', 'nummer;woo_preis_netto;inaktiv', ''] as $header) {
            $db = new CsvImportDatabaseFake(csvImportTemplate("1:nummer;2:woo_preis_netto;3:inaktiv"));
            $result = runCsvImport($db, $header . "\nA9;1;0\n", 12);
            same(false, $result['success'], $header);
            check(str_contains($result['message'], 'CSV-Kopfzeile'));
            same([], $db->writes);
        }
    },
    'legacy_template_keeps_unknown_fields_and_looks_up_zero_address_numbers' => static function () {
        $db = new CsvImportDatabaseFake(csvImportTemplate(
            "1:nummer;2:ausverkauft;3:shop_name_de;4:lieferzeitmanuell;5:woo_preis_netto;"
            . "6:kundennummer;7:lieferantennummer"
        ));
        $result = runCsvImport($db, "nummer;ausverkauft;shop_name_de;lieferzeitmanuell;preis;kunde;lieferant\n"
            . "A9;1;Name;Sofort;29,90;0;0\n", 13);
        foreach (['ausverkauft', 'shop_name_de', 'lieferzeitmanuell', 'woo_preis_netto'] as $field) {
            check(str_contains($result['message'], 'Feld unbekannt: ' . $field));
            check(!isset($db->article[$field]));
        }
        check(str_contains($db->lookups[0], "lieferantennummer='0'"));
        check(str_contains($db->lookups[1], "kundennummer='0'"));
        same(["DELETE FROM artikeleigenschaftenwerte WHERE wert = ''"], $db->writes);
    },
    'legacy_price_template_still_updates_its_standard_price' => static function () {
        $db = new CsvImportDatabaseFake(csvImportTemplate('1:nummer;2:verkaufspreis1netto;3:verkaufspreis1menge;'
            . '4:verkaufspreis1gueltigab;5:verkaufspreis1gueltigbis;6:verkaufspreis1preisfuermenge;'
            . '7:verkaufspreis1waehrung;8:verkaufspreis1internerkommentar'));
        runCsvImport($db, "nummer;preis;menge;von;bis;divisor;waehrung;kommentar\nA9;29,90;1;;;;EUR;\n", 13);
        same(['29.9'], array_column(array_filter($db->prices, static fn ($price) => $price['active']), 'preis'));
        same([], $db->audit);
    },
    'registered_template_does_not_execute_legacy_price_fields' => static function () {
        $db = new CsvImportDatabaseFake(csvImportTemplate("1:nummer;2:verkaufspreis1netto;3:verkaufspreis1menge"));
        $result = runCsvImport($db, "nummer;verkaufspreis1netto;verkaufspreis1menge\nA9;29,90;1\n", 12);
        check(str_contains($result['message'], 'woo_preis_netto'));
        same([], $db->writes);
    },

    'ensure_is_idempotent' => static function () {
        [$db, $export, $import, $ids] = installedTemplates();
        $db->templates['exportvorlage']['WooCommerce Artikeldaten']['fields'] .= ';custom';
        same($ids, (new TemplateInstaller($db))->ensure());
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
    'ensure_recreates_each_missing_template_with_the_same_layout' => static function () {
        [$db, $export, $import] = installedTemplates();
        foreach (['export' => $export, 'import' => $import] as $side => $template) {
            $table = $side . 'vorlage';
            unset($db->templates[$table][TemplateInstaller::LABEL]);
            $ids = (new TemplateInstaller($db))->ensure();
            check($template['id'] !== $ids[$side]);
            $recreated = $db->templates[$table][TemplateInstaller::LABEL];
            same($template['fields'], $recreated['fields']);
        }
        same(4, $db->inserts);
        check(str_contains($db->templates['exportvorlage'][TemplateInstaller::LABEL]['fields'], ' AS standardpreisnetto'));
        check(str_contains($db->templates['importvorlage'][TemplateInstaller::LABEL]['fields'], ':woo_preis_netto;'));
    },
    'update_only_registered' => static function () {
        $db = new TemplateDatabaseFake();
        $db->config['importvorlage_update_only'] = '42,120';
        $ids = (new TemplateInstaller($db))->ensure();
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
