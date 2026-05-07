<?php
/**
 * Installations-Script fuer das LexwareOffice-Modul.
 *
 * Idempotent - mehrfacher Aufruf ist ungefaehrlich. Aufruf aus dem
 * OpenXE-Projekt-Root:
 *
 *     php classes/Modules/LexwareOffice/install/install.php
 *
 * Schritte:
 *   1) Service-Cache invalidieren, damit der Installer die neue
 *      LexwareOffice/Bootstrap.php beim Neuaufbau entdeckt.
 *   2) OpenXE-Bootstrap laden (ConfigLoader, xentral_autoloader,
 *      erpooSystem) - liefert $app mit Container, erp, DB.
 *   3) Bootstrap::ensureSchema() - idempotentes ADD COLUMN via
 *      SHOW COLUMNS.
 *   4) Lexwareoffice::Install() aufrufen - registriert die
 *      Rechnungs-Dropdown-Hooks persistent in hook_register.
 *   5) Bootstrap::ensureMasterKeyFile() - erzeugt idempotent eine
 *      Master-Key-Datei in {WFuserdata}, die zur Verschluesselung
 *      des API-Schluessels genutzt wird (chmod 0600).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Dieses Script darf nur via CLI ausgefuehrt werden.\n");
    exit(1);
}

// classes/Modules/LexwareOffice/install -> projekt-root
$projectRoot = dirname(__DIR__, 4);
chdir($projectRoot);

echo "=== LexwareOffice Install ===\n\n";

// ---------------------------------------------------------------------
// 1) Service-Cache invalidieren VOR dem Bootstrap, damit der Installer
//    die neue LexwareOffice/Bootstrap.php auto-discoverd.
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
// 2) OpenXE-Bootstrap: Autoloader + erpooSystem instanziieren.
//    erpooSystem erbt von Application -> ApplicationCore, welches in
//    seinem Konstruktor classes/bootstrap.php included und $this->Container
//    setzt. $app->erp liefert die Legacy-erpAPI mit RegisterHook().
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
    echo "OK\n";
} catch (\Throwable $e) {
    echo "FAIL\n  " . $e->getMessage() . "\n";
    exit(1);
}

// ---------------------------------------------------------------------
// 3) Schema-Migration: idempotent, haengt die drei Lexware-Spalten an.
// ---------------------------------------------------------------------
echo "[3/5] Pruefe Datenbank-Schema ... ";
try {
    /** @var \Xentral\Components\Database\Database $db */
    $db = $app->Container->get('Database');
    \Xentral\Modules\LexwareOffice\Bootstrap::ensureSchema($db);
    echo "OK\n";
} catch (\Throwable $e) {
    echo "FAIL\n  " . $e->getMessage() . "\n";
    exit(1);
}

// ---------------------------------------------------------------------
// 4) Hooks registrieren: Lexwareoffice::Install() haengt die
//    Rechnungs-Dropdown-Hooks in hook_register ein. RegisterHook()
//    in erpAPI ist idempotent (UPDATE wenn vorhanden, sonst INSERT).
// ---------------------------------------------------------------------
echo "[4/5] Registriere Rechnungs-Dropdown-Hooks ... ";
try {
    require_once $projectRoot . '/www/pages/lexwareoffice.php';

    // $intern=true ueberspringt ActionHandler-Registration und den
    // SuperSearchIndex-Setup. Install() ist dennoch aufrufbar.
    $page = new \Lexwareoffice($app, true);
    $page->Install();
    echo "OK\n";
} catch (\Throwable $e) {
    echo "FAIL\n  " . $e->getMessage() . "\n";
    exit(1);
}

// ---------------------------------------------------------------------
// 5) Master-Key-Datei: Erzeugt idempotent die Datei in {WFuserdata}, die
//    zur Verschluesselung des API-Schluessels genutzt wird. Liegt
//    bewusst ausserhalb der DB.
// ---------------------------------------------------------------------
echo "[5/5] Pruefe Master-Key-Datei ... ";
$masterKeyPath = null;
$masterKeyCreated = false;
try {
    $masterKeyPath = \Xentral\Modules\LexwareOffice\Bootstrap::resolveMasterKeyPath($app->Container);
    $masterKeyCreated = \Xentral\Modules\LexwareOffice\Bootstrap::ensureMasterKeyFile($masterKeyPath);
    echo $masterKeyCreated ? "neu erstellt\n" : "bereits vorhanden\n";
} catch (\Throwable $e) {
    echo "FAIL\n  " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nLexwareOffice-Modul installiert.\n";
if ($masterKeyCreated && $masterKeyPath !== null) {
    echo "\nWICHTIG: Master-Key wurde neu erstellt unter\n";
    echo "  " . $masterKeyPath . "\n";
    echo "Diese Datei muss in dein Backup mit aufgenommen werden.\n";
    echo "Bei Verlust ist der gespeicherte API-Schluessel nicht mehr entschluesselbar.\n";
}
echo "\nNaechster Schritt: index.php?module=lexwareoffice&action=edit aufrufen\n";
echo "und den Lexware-API-Schluessel hinterlegen.\n";
exit(0);
