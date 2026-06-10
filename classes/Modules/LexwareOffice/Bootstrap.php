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
            $container->get('SystemConfigModule')
        );
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
