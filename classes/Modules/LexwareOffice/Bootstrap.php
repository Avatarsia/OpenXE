<?php

declare(strict_types=1);

namespace Xentral\Modules\LexwareOffice;

use ApplicationCore;
use Xentral\Components\Database\Database;
use Xentral\Components\Logger\Logger;
use Xentral\Core\DependencyInjection\ContainerInterface;
use Xentral\Modules\LexwareOffice\Service\LexwareOfficeApiClient;
use Xentral\Modules\LexwareOffice\Service\LexwareOfficeConfigService;
use Xentral\Modules\LexwareOffice\Service\LexwareOfficePayloadMapper;
use Xentral\Modules\LexwareOffice\Service\LexwareOfficeService;
use Xentral\Modules\SystemConfig\SystemConfigModule;

final class Bootstrap
{
    /**
     * Service-Registrierung fuer den OpenXE-Container.
     *
     * Wird von Xentral\Core\Installer\Installer::getBootstrapFiles()
     * per Auto-Discovery gefunden und statisch aufgerufen.
     *
     * @return array<string, string>
     */
    public static function registerServices(): array
    {
        return [
            'LexwareOfficeConfigService'  => 'onInitLexwareOfficeConfigService',
            'LexwareOfficeApiClient'      => 'onInitLexwareOfficeApiClient',
            'LexwareOfficePayloadMapper'  => 'onInitLexwareOfficePayloadMapper',
            'LexwareOfficeService'        => 'onInitLexwareOfficeService',
        ];
    }

    public static function onInitLexwareOfficeConfigService(ContainerInterface $container): LexwareOfficeConfigService
    {
        return new LexwareOfficeConfigService(
            $container->get('SystemConfigModule'),
            self::resolveMasterKeyPath($container)
        );
    }

    /**
     * Liefert den absoluten Pfad zur Master-Key-Datei.
     *
     * Konvention: {WFuserdata}/lexwareoffice.master.key. Liegt bewusst
     * ausserhalb des Code-Trees, damit die Datei nicht versehentlich
     * deployed/ueberschrieben wird, und ausserhalb der DB, damit ein
     * DB-Dump alleine den API-Schluessel nicht entschluesselbar macht.
     */
    public static function resolveMasterKeyPath(ContainerInterface $container): string
    {
        /** @var ApplicationCore $app */
        $app = $container->get('LegacyApplication');
        $userdata = isset($app->Conf->WFuserdata) ? (string)$app->Conf->WFuserdata : '';
        if ($userdata === '') {
            throw new \RuntimeException('LexwareOffice: WFuserdata ist nicht konfiguriert; Master-Key-Pfad nicht aufloesbar.');
        }

        return rtrim($userdata, '/\\') . DIRECTORY_SEPARATOR . 'lexwareoffice.master.key';
    }

    public static function onInitLexwareOfficeApiClient(ContainerInterface $container): LexwareOfficeApiClient
    {
        return new LexwareOfficeApiClient();
    }

    public static function onInitLexwareOfficePayloadMapper(ContainerInterface $container): LexwareOfficePayloadMapper
    {
        /** @var ApplicationCore $app */
        $app = $container->get('LegacyApplication');

        return new LexwareOfficePayloadMapper(
            $app->erp ?? null,
            $container->get('Logger')
        );
    }

    public static function onInitLexwareOfficeService(ContainerInterface $container): LexwareOfficeService
    {
        /** @var ApplicationCore $app */
        $app = $container->get('LegacyApplication');

        return new LexwareOfficeService(
            $container->get('Database'),
            $container->get('LexwareOfficeConfigService'),
            $container->get('LexwareOfficeApiClient'),
            $container->get('Logger'),
            $app->erp ?? null,
            $container->get('LexwareOfficePayloadMapper')
        );
    }

    /**
     * Stellt sicher, dass die fuer Idempotenz benoetigten Spalten existieren.
     *
     * Wird lazy vom LexwareOfficeConfigService::saveApiKey() aufgerufen, wenn
     * der erste API-Key hinterlegt wird. Idempotent: bestehende Spalten werden
     * nicht angefasst. Portable via SHOW COLUMNS (MySQL 5.7+, MariaDB 10.0+).
     */
    public static function ensureSchema(Database $db): void
    {
        self::addColumnIfMissing($db, 'adresse',   'lexware_contact_id',     'VARCHAR(36) DEFAULT NULL');
        self::addColumnIfMissing($db, 'rechnung',  'lexware_invoice_id',     'VARCHAR(36) DEFAULT NULL');
        self::addColumnIfMissing($db, 'rechnung',  'lexware_uploaded_at',    'DATETIME DEFAULT NULL');
        self::addColumnIfMissing($db, 'rechnung',  'lexware_pdf_uploaded_at','DATETIME DEFAULT NULL');
        self::addColumnIfMissing($db, 'gutschrift','lexware_creditnote_id',  'VARCHAR(36) DEFAULT NULL');
        self::addColumnIfMissing($db, 'gutschrift','lexware_uploaded_at',    'DATETIME DEFAULT NULL');
        self::addColumnIfMissing($db, 'gutschrift','lexware_pdf_uploaded_at','DATETIME DEFAULT NULL');
    }

    /**
     * Erzeugt idempotent eine Master-Key-Datei, mit der der API-Schluessel
     * verschluesselt wird. Liegt ausserhalb der DB, damit ein DB-Dump
     * alleine den API-Key nicht entschluesselbar macht.
     *
     * Returnt true, wenn die Datei neu erstellt wurde; false, wenn sie
     * bereits existierte. Wirft RuntimeException bei Schreib-/chmod-Fehlern.
     *
     * Datei-Inhalt: 64 Hex-Zeichen (= 32 Bytes Entropie aus random_bytes).
     * Datei-Permissions: 0600. Verzeichnis wird bei Bedarf mit 0700 angelegt.
     */
    public static function ensureMasterKeyFile(string $path): bool
    {
        if (is_file($path)) {
            return false;
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new \RuntimeException(sprintf(
                    'Master-Key-Verzeichnis konnte nicht erstellt werden: %s',
                    $dir
                ));
            }
        }

        // Race-safe via O_EXCL ('x' fopen-mode): fopen schlaegt fehl, wenn
        // die Datei bereits existiert. Kein TOCTOU-Loch zwischen is_file()
        // und Erzeugung, auch nicht bei parallelen PHP-FPM-Workern.
        $fp = @fopen($path, 'xb');
        if ($fp === false) {
            // Verlorenes Rennen: anderer Worker hat die Datei in der
            // Zwischenzeit erstellt -> dessen Key ist autoritativ.
            if (is_file($path)) {
                return false;
            }
            throw new \RuntimeException(sprintf(
                'Master-Key-Datei konnte nicht erzeugt werden: %s',
                $path
            ));
        }

        // Permissions VOR dem Schreiben des Secrets setzen, damit waehrend
        // des kurzen Schreib-Fensters keine 0644-Datei mit Klartext existiert.
        if (!@chmod($path, 0600)) {
            fclose($fp);
            @unlink($path);
            throw new \RuntimeException(sprintf(
                'chmod 0600 fehlgeschlagen fuer: %s',
                $path
            ));
        }

        $key = bin2hex(random_bytes(32));
        if (fwrite($fp, $key) === false) {
            fclose($fp);
            @unlink($path);
            throw new \RuntimeException(sprintf(
                'Master-Key konnte nicht geschrieben werden: %s',
                $path
            ));
        }
        fclose($fp);

        return true;
    }

    /**
     * Symmetrisches Gegenstueck zu ensureSchema fuer Modul-Deinstallation.
     * Droppt die Lexware-spezifischen Spalten, wenn sie existieren.
     */
    public static function removeSchema(Database $db): void
    {
        // Reverse-Reihenfolge: gutschrift zuerst, dann rechnung, zum Schluss adresse.
        self::dropColumnIfExists($db, 'gutschrift','lexware_pdf_uploaded_at');
        self::dropColumnIfExists($db, 'gutschrift','lexware_uploaded_at');
        self::dropColumnIfExists($db, 'gutschrift','lexware_creditnote_id');
        self::dropColumnIfExists($db, 'rechnung',  'lexware_pdf_uploaded_at');
        self::dropColumnIfExists($db, 'rechnung',  'lexware_uploaded_at');
        self::dropColumnIfExists($db, 'rechnung',  'lexware_invoice_id');
        self::dropColumnIfExists($db, 'adresse',   'lexware_contact_id');
    }

    /**
     * Idempotenter ADD COLUMN via SHOW COLUMNS-Check.
     * DDL committet implizit - nicht innerhalb einer Transaktion aufrufen.
     */
    private static function addColumnIfMissing(Database $db, string $table, string $column, string $definition): void
    {
        if (!preg_match('/^[a-z0-9_]+$/i', $table) || !preg_match('/^[a-z0-9_]+$/i', $column)) {
            throw new \InvalidArgumentException(sprintf('Invalid identifier: %s.%s', $table, $column));
        }

        $rows = $db->fetchAll(sprintf(
            'SHOW COLUMNS FROM `%s` LIKE %s',
            $table,
            $db->escapeString($column)
        ));

        if (count($rows) > 0) {
            return;
        }

        $db->exec(sprintf(
            'ALTER TABLE `%s` ADD COLUMN `%s` %s',
            $table,
            $column,
            $definition
        ));
    }

    /**
     * Idempotentes DROP COLUMN via SHOW COLUMNS-Check.
     */
    private static function dropColumnIfExists(Database $db, string $table, string $column): void
    {
        if (!preg_match('/^[a-z0-9_]+$/i', $table) || !preg_match('/^[a-z0-9_]+$/i', $column)) {
            throw new \InvalidArgumentException(sprintf('Invalid identifier: %s.%s', $table, $column));
        }

        $rows = $db->fetchAll(sprintf(
            'SHOW COLUMNS FROM `%s` LIKE %s',
            $table,
            $db->escapeString($column)
        ));

        if (count($rows) === 0) {
            return;
        }

        $db->exec(sprintf('ALTER TABLE `%s` DROP COLUMN `%s`', $table, $column));
    }
}
