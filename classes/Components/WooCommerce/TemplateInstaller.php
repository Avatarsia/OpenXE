<?php
// SPDX-License-Identifier: AGPL-3.0-only

namespace Xentral\Components\WooCommerce;

use RuntimeException;
use Throwable;

final class TemplateInstaller
{
    public const LABEL = 'WooCommerce Artikeldaten';
    public const UPDATE_ONLY = 'importvorlage_update_only';
    private const LOCK = 'openxe_woocommerce_article_templates';

    public function __construct(private $database)
    {
    }

    public function ensure(bool $includePrice): array
    {
        $lock = ['lock' => self::LOCK];
        if ((int) $this->database->fetchValue('SELECT GET_LOCK(:lock, 5)', $lock) !== 1) {
            throw new RuntimeException('WooCommerce-Artikeldatenvorlagen sind gerade in Bearbeitung.');
        }
        try {
            $this->database->beginTransaction();
            try {
                $ids = $this->install($includePrice);
                $this->registerUpdateOnly($ids['import']);
                $this->database->commit();
                return $ids;
            } catch (Throwable $error) {
                $this->database->rollBack();
                throw $error;
            }
        } finally {
            $this->database->fetchValue('SELECT RELEASE_LOCK(:lock)', $lock);
        }
    }

    public static function isUpdateOnly(int $id, string $registeredIds): bool
    {
        return $id > 0 && in_array((string) $id, array_map('trim', explode(',', $registeredIds)), true);
    }

    private function install(bool $includePrice): array
    {
        $columns = ArticleFieldCatalog::csvColumns();
        if (!$includePrice) {
            $columns = array_values(array_filter($columns, static fn (array $column): bool =>
                !in_array($column['import'], ['verkaufspreis1netto', 'verkaufspreis1menge'], true)));
        }
        $importFields = '';
        foreach ($columns as $index => $column) {
            $importFields .= ($index + 1) . ':' . $column['import'] . ";\r\n";
        }
        $settings = ['label' => self::LABEL, 'ziel' => 'artikel', 'trennzeichen' => 'semikolon',
            'maskierung' => 'gaensefuesschen'];

        $export = $this->ensureTemplate(
            'exportvorlage',
            'INSERT INTO exportvorlage (bezeichnung, ziel, fields, fields_where, exporttrennzeichen,'
                . ' exportdatenmaskierung, exporterstezeilenummer, exportzeichensatz)'
                . " VALUES (:label, :ziel, :fields, '', :trennzeichen, :maskierung, :erstezeile, :zeichensatz)",
            $settings + ['fields' => implode(";\r\n", array_column($columns, 'export')),
                'erstezeile' => 1, 'zeichensatz' => 'UTF-8']
        );
        $import = $this->ensureTemplate(
            'importvorlage',
            'INSERT INTO importvorlage (bezeichnung, ziel, fields, importtrennzeichen, importdatenmaskierung,'
                . ' importerstezeilenummer, charset, utf8decode)'
                . ' VALUES (:label, :ziel, :fields, :trennzeichen, :maskierung, :erstezeile, :charset, :utf8decode)',
            $settings + ['fields' => $importFields, 'erstezeile' => 2, 'charset' => 'utf8', 'utf8decode' => 0]
        );
        return ['export' => $export, 'import' => $import];
    }

    private function ensureTemplate(string $table, string $insert, array $settings): int
    {
        $id = (int) $this->database->fetchValue(
            "SELECT id FROM $table WHERE bezeichnung = :label ORDER BY id LIMIT 1",
            ['label' => self::LABEL]
        );
        if ($id > 0) {
            return $id;
        }
        $this->database->perform($insert, $settings);
        return $this->database->lastInsertId();
    }

    private function registerUpdateOnly(int $importId): void
    {
        $name = ['name' => self::UPDATE_ONLY];
        $registered = (string) $this->database->fetchValue(
            'SELECT wert FROM konfiguration WHERE name = :name FOR UPDATE',
            $name
        );
        if (self::isUpdateOnly($importId, $registered)) {
            return;
        }
        $registered = trim($registered, ", \t\r\n");
        $value = ($registered === '' ? '' : $registered . ',') . $importId;
        if (strlen($value) > 65000) {
            throw new RuntimeException('Die Liste der Importvorlagen fuer Aktualisierungen ist zu gross.');
        }
        $this->database->perform(
            'INSERT INTO konfiguration (name, wert, adresse, firma) VALUES (:name, :wert, 0, 0)'
            . ' ON DUPLICATE KEY UPDATE wert = VALUES(wert)',
            $name + ['wert' => $value]
        );
    }
}
