<?php
declare(strict_types=1);
// Smoke-Test: Struktur-Keys der drei Zahlungsweisen-Modulklassen.
// Aufruf: OPENXE_CORE=/pfad/zu/OpenXE-src php ZahlungsweisenStrukturTest.php
// OPENXE_CORE ist PFLICHT und zeigt auf einen OpenXE-Core-Tree (nur die
// Core-Parents class.zahlungsweise.php und zahlungsweisen/rechnung.php
// werden daraus gelesen). Die drei eigenen Moduldateien kommen automatisch
// aus dem eigenen Repo-Tree; der Test baut sich einen Temp-Merge-Tree -
// KEIN manuelles Kopieren noetig. Stubs fuer Application/DB, da die
// Konstruktoren die DB lesen.

$fails = 0;
function check(string $name, bool $cond): void {
  global $fails;
  if ($cond) { echo "OK   $name\n"; } else { $fails++; echo "FAIL $name\n"; }
}

class StubDb {
  public function SelectRow($sql) {
    return ['id' => 1, 'type' => 'x', 'einstellungen_json' => ''];
  }
  public function Select($sql) { return ''; }
  public function SelectArr($sql) { return []; }
  public function real_escape_string($s) { return addslashes($s); }
  public function Update($sql) {}
}
class StubSecure { public function GetPOST($n, $a = '', $b = '', $c = 0) { return ''; } }
class StubErp {
  // Zahlungsweise_rechnung::EinstellungenStruktur() ruft Beschriftung() fuer Default-Texte
  public function Beschriftung($field, $sprache = '') { return ''; }
  public function __call($name, $args) { return ''; }
}
class StubApp {
  public $DB; public $Secure; public $erp;
  public function __construct() { $this->DB = new StubDb(); $this->Secure = new StubSecure(); $this->erp = new StubErp(); }
}

// --- Temp-Merge-Tree bauen: Core-Parents + eigene Moduldateien ---
$core = getenv('OPENXE_CORE');
if ($core === false || $core === '' || !is_file($core . '/www/lib/class.zahlungsweise.php')) {
  fwrite(STDERR, "FEHLER: OPENXE_CORE muss auf einen OpenXE-Core-Tree zeigen\n");
  fwrite(STDERR, "        (erwartet: <OPENXE_CORE>/www/lib/class.zahlungsweise.php)\n");
  fwrite(STDERR, 'Aufruf:  OPENXE_CORE=/pfad/zu/OpenXE-src php ' . basename(__FILE__) . "\n");
  exit(1);
}

// Eigener Repo-Tree: tests/ -> PaymentQr -> Modules -> classes -> Repo-Root
$own = dirname(__DIR__, 4) . '/www/lib/zahlungsweisen';

$tmp = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'zahlungsqr_smoke_' . uniqid();
if (!mkdir($tmp . '/zahlungsweisen', 0777, true)) {
  fwrite(STDERR, "FEHLER: Temp-Verzeichnis konnte nicht angelegt werden: $tmp\n");
  exit(1);
}

// Aufraeumen auch bei Fatal Error / vorzeitigem exit()
register_shutdown_function(function () use ($tmp): void {
  foreach (glob($tmp . '/zahlungsweisen/*.php') ?: [] as $f) {
    @unlink($f);
  }
  @unlink($tmp . '/class.zahlungsweise.php');
  @rmdir($tmp . '/zahlungsweisen');
  @rmdir($tmp);
});

$copies = [
  $core . '/www/lib/class.zahlungsweise.php' => $tmp . '/class.zahlungsweise.php',
  $core . '/www/lib/zahlungsweisen/rechnung.php' => $tmp . '/zahlungsweisen/rechnung.php',
  $own . '/rechnung_qr.php' => $tmp . '/zahlungsweisen/rechnung_qr.php',
  $own . '/paypal_qr.php' => $tmp . '/zahlungsweisen/paypal_qr.php',
  $own . '/wero.php' => $tmp . '/zahlungsweisen/wero.php',
];
foreach ($copies as $src => $dst) {
  if (!is_file($src) || !copy($src, $dst)) {
    fwrite(STDERR, "FEHLER: Datei fehlt oder Kopie fehlgeschlagen: $src\n");
    exit(1);
  }
}

require_once $tmp . '/zahlungsweisen/rechnung_qr.php';
require_once $tmp . '/zahlungsweisen/paypal_qr.php';
require_once $tmp . '/zahlungsweisen/wero.php';

$app = new StubApp();

$r = new Zahlungsweise_rechnung_qr($app, 1);
$s = $r->EinstellungenStruktur();
check('rechnung_qr erbt Eltern-Keys', array_key_exists('invoice_immediately', $s));
foreach (['qr_aktiv','qr_nur_bei_passender_zahlungsweise','qr_iban','qr_bic','qr_kontoinhaber','qr_verwendungszweck','qr_beschriftung'] as $k) {
  check("rechnung_qr Key $k", array_key_exists($k, $s));
}
check('rechnung_qr qr_aktiv ist checkbox', ($s['qr_aktiv']['typ'] ?? '') === 'checkbox');
check('rechnung_qr qr_nur_bei_passender_zahlungsweise ist checkbox', ($s['qr_nur_bei_passender_zahlungsweise']['typ'] ?? '') === 'checkbox');

$p = new Zahlungsweise_paypal_qr($app, 1);
$sp = $p->EinstellungenStruktur();
foreach (['qr_aktiv','qr_nur_bei_passender_zahlungsweise','paypalme_handle','qr_beschriftung'] as $k) {
  check("paypal_qr Key $k", array_key_exists($k, $sp));
}

$w = new Zahlungsweise_wero($app, 1);
$sw = $w->EinstellungenStruktur();
foreach (['qr_aktiv','qr_nur_bei_passender_zahlungsweise','qr_beschriftung','qr_datei'] as $k) {
  check("wero Key $k", array_key_exists($k, $sw));
}

echo $fails === 0 ? "\nALLE TESTS OK\n" : "\n$fails TEST(S) FEHLGESCHLAGEN\n";
exit($fails === 0 ? 0 : 1);
