<?php
declare(strict_types=1);
require_once __DIR__ . '/../Service/PaymentQrSettingsService.php';

use Xentral\Modules\PaymentQr\Service\PaymentQrSettingsService;

$fails = 0;
function check(string $name, bool $cond): void {
  global $fails;
  if ($cond) { echo "OK   $name\n"; } else { $fails++; echo "FAIL $name\n"; }
}

class FakeDb {
  public array $rows = [];
  public string $lastSql = '';
  public function SelectArr($sql) { $this->lastSql = $sql; return $this->rows; }
}

$row = function(array $o): array {
  return array_merge([
    'id' => 1, 'type' => 'rechnung', 'modul' => 'rechnung_qr', 'projekt' => 0,
    'einstellungen_json' => json_encode(['qr_aktiv' => '1', 'qr_iban' => 'DE12']),
  ], $o);
};

// 1. Nur qr_aktiv-Eintraege kommen zurueck, JSON dekodiert
$db = new FakeDb();
$db->rows = [
  $row(['id' => 1]),
  $row(['id' => 2, 'type' => 'paypal', 'modul' => 'paypal_qr',
        'einstellungen_json' => json_encode(['qr_aktiv' => ''])]),
];
$svc = new PaymentQrSettingsService($db);
$configs = $svc->getActiveQrConfigs(0);
check('nur aktive QR-Configs', count($configs) === 1 && $configs['rechnung']['settings']['qr_iban'] === 'DE12');

// 2. Projektspezifischer Eintrag gewinnt gegen global
$db2 = new FakeDb();
$db2->rows = [
  $row(['id' => 1, 'projekt' => 5, 'einstellungen_json' => json_encode(['qr_aktiv' => '1', 'qr_iban' => 'PROJEKT'])]),
  $row(['id' => 2, 'einstellungen_json' => json_encode(['qr_aktiv' => '1', 'qr_iban' => 'GLOBAL'])]),
];
$svc2 = new PaymentQrSettingsService($db2);
$c2 = $svc2->getActiveQrConfigs(5);
check('projekt gewinnt', $c2['rechnung']['settings']['qr_iban'] === 'PROJEKT');

// 3. SQL filtert aktiv/geloescht/projekt und unsere drei Module
$svc2->getActiveQrConfigs(7);
$sql = $db2->lastSql;
check('SQL: aktiv=1',        strpos($sql, 'aktiv') !== false);
check('SQL: geloescht',      strpos($sql, 'geloescht') !== false);
check('SQL: projekt-Klausel',strpos($sql, "projekt = 0 OR") !== false && strpos($sql, "= 7") !== false);
check('SQL: modul-Filter',   strpos($sql, "rechnung_qr") !== false && strpos($sql, "paypal_qr") !== false && strpos($sql, "wero") !== false);
check('SQL: ORDER BY projekt DESC', strpos($sql, 'ORDER BY z.projekt DESC') !== false);

// 3b. Projektspezifisch deaktiviert: KEIN stiller Fallback auf den globalen Eintrag
$db2b = new FakeDb();
$db2b->rows = [
  $row(['id' => 1, 'projekt' => 5, 'einstellungen_json' => json_encode(['qr_aktiv' => ''])]),
  $row(['id' => 2, 'einstellungen_json' => json_encode(['qr_aktiv' => '1', 'qr_iban' => 'GLOBAL'])]),
  $row(['id' => 3, 'type' => 'paypal', 'modul' => 'paypal_qr',
        'einstellungen_json' => json_encode(['qr_aktiv' => '1', 'paypalme_handle' => 'h'])]),
];
$svc2b = new PaymentQrSettingsService($db2b);
$c2b = $svc2b->getActiveQrConfigs(5);
check('projekt-deaktiviert: kein stiller Fallback', !isset($c2b['rechnung']));
check('projekt-deaktiviert: andere types unberuehrt', isset($c2b['paypal']));

// 4. Kaputtes JSON wird ignoriert statt Exception
$db3 = new FakeDb();
$db3->rows = [$row(['einstellungen_json' => '{kaputt'])];
$svc3 = new PaymentQrSettingsService($db3);
check('kaputtes JSON ignoriert', $svc3->getActiveQrConfigs(0) === []);

// 5. Platzhalter-Aufloesung
$svc4 = new PaymentQrSettingsService(new FakeDb());
$z = $svc4->resolveVerwendungszweck('{BELEGNR} / Kd {KUNDENNUMMER}', ['belegnr' => 'RE-1', 'kundennummer' => 'K9']);
check('Platzhalter ersetzt', $z === 'RE-1 / Kd K9');
$z2 = $svc4->resolveVerwendungszweck('', ['belegnr' => 'RE-1']);
check('Default = Belegnummer', $z2 === 'RE-1');

echo $fails === 0 ? "\nALLE TESTS OK\n" : "\n$fails TEST(S) FEHLGESCHLAGEN\n";
exit($fails === 0 ? 0 : 1);
