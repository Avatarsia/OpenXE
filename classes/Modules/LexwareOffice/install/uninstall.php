<?php
/**
 * Deinstallations-Script fuer das LexwareOffice-Modul.
 *
 * Symmetrisches Gegenstueck zu install.php. Aufruf aus dem
 * OpenXE-Projekt-Root:
 *
 *     php classes/Modules/LexwareOffice/install/uninstall.php
 *     php classes/Modules/LexwareOffice/install/uninstall.php --drop-columns
 *     php classes/Modules/LexwareOffice/install/uninstall.php --delete-api-key
 *     php classes/Modules/LexwareOffice/install/uninstall.php --drop-columns --delete-api-key
 *
 * Schritte:
 *   1) Service-Cache invalidieren.
 *   2) OpenXE-Bootstrap laden.
 *   3) hook_register-Eintraege fuer module='lexwareoffice' loeschen.
 *   4) Optional (--drop-columns): Bootstrap::removeSchema() - droppt
 *      die drei Lexware-Spalten aus rechnung/adresse.
 *   5) Optional (--delete-api-key): system_config-Eintraege mit
 *      namespace='lexwareoffice' loeschen.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Dieses Script darf nur via CLI ausgefuehrt werden.\n");
    exit(1);
}

$dropColumns  = in_array('--drop-columns',  $argv, true);
$deleteApiKey = in_array('--delete-api-key', $argv, true);

// classes/Modules/LexwareOffice/install -> projekt-root
$projectRoot = dirname(__DIR__, 4);
chdir($projectRoot);

echo "=== LexwareOffice Uninstall ===\n";
echo "  --drop-columns  : " . ($dropColumns  ? 'JA' : 'nein') . "\n";
echo "  --delete-api-key: " . ($deleteApiKey ? 'JA' : 'nein') . "\n\n";

// ---------------------------------------------------------------------
// 1) Service-Cache invalidieren.
// ---------------------------------------------------------------------
echo "[1/5] Invalidiere Service-Cache ... ";
try {
    require_once $projectRoot . '/vendor/autoload.php';
    require_once $projectRoot . '/conf/main.conf.php';

    $config = \Xentral\Core\LegacyConfig\ConfigLoader::load();
    $cacheDir = $config->WFuserdata . '/tmp/' . $config->WFdbname;
    $serviceCacheFile = $cacheDir . '/cache_services.php';

    if (is_file($serviceCacheFile)) {
        if (!@unlink($serviceCacheFile)) {
            throw new RuntimeException(sprintf(
                'Cache-Datei "%s" konnte nicht geloescht werden (Schreibrechte?).',
                $serviceCacheFile
            ));
        }
        echo "geloescht\n";
    } else {
        echo "nicht vorhanden (ok)\n";
    }
} catch (\Throwable $e) {
    echo "FAIL\n  " . $e->getMessage() . "\n";
    exit(1);
}

// ---------------------------------------------------------------------
// 2) OpenXE-Bootstrap laden.
// ---------------------------------------------------------------------
echo "[2/5] Lade OpenXE-Bootstrap ... ";
try {
    require_once $projectRoot . '/xentral_autoloader.php';
    require_once $projectRoot . '/phpwf/class.application_core.php';
    require_once $projectRoot . '/phpwf/class.application.php';
    require_once $projectRoot . '/www/eproosystem.php';

    /** @var \ApplicationCore $app */
    $app = new \erpooSystem($config);

    if (!isset($app->Container)) {
        throw new RuntimeException('Container wurde nicht initialisiert.');
    }

    /** @var \Xentral\Components\Database\Database $db */
    $db = $app->Container->get('Database');
    echo "OK\n";
} catch (\Throwable $e) {
    echo "FAIL\n  " . $e->getMessage() . "\n";
    exit(1);
}

// ---------------------------------------------------------------------
// 3) hook_register-Eintraege loeschen.
// ---------------------------------------------------------------------
echo "[3/5] Entferne Hook-Registrierungen ... ";
try {
    $db->perform(
        "DELETE FROM `hook_register` WHERE `module` = :module",
        ['module' => 'lexwareoffice']
    );
    echo "OK\n";
} catch (\Throwable $e) {
    echo "FAIL\n  " . $e->getMessage() . "\n";
    exit(1);
}

// ---------------------------------------------------------------------
// 4) Schema-Rollback (optional).
// ---------------------------------------------------------------------
echo "[4/5] Schema-Rollback ... ";
try {
    if ($dropColumns) {
        \Xentral\Modules\LexwareOffice\Bootstrap::removeSchema($db);
        echo "OK (Spalten entfernt)\n";
    } else {
        echo "uebersprungen\n";
        echo "  Hinweis: lexware_contact_id, lexware_invoice_id, lexware_uploaded_at bleiben\n";
        echo "           erhalten. Flag --drop-columns nutzen um sie zu entfernen.\n";
    }
} catch (\Throwable $e) {
    echo "FAIL\n  " . $e->getMessage() . "\n";
    exit(1);
}

// ---------------------------------------------------------------------
// 5) API-Key entfernen (optional).
// ---------------------------------------------------------------------
echo "[5/5] API-Key entfernen ... ";
try {
    if ($deleteApiKey) {
        $db->perform(
            "DELETE FROM `system_config` WHERE `namespace` = :namespace",
            ['namespace' => 'lexwareoffice']
        );
        echo "OK (system_config-Eintraege entfernt)\n";
    } else {
        echo "uebersprungen\n";
        echo "  Hinweis: der verschluesselte API-Key in system_config bleibt erhalten.\n";
        echo "           Flag --delete-api-key nutzen um ihn zu entfernen.\n";
    }
} catch (\Throwable $e) {
    echo "FAIL\n  " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nLexwareOffice-Modul deinstalliert.\n";
exit(0);
