<?php
declare(strict_types=1);
require_once __DIR__ . '/../Service/EpcQrPayloadBuilder.php';
require_once __DIR__ . '/../Service/PaymentQrSettingsService.php';
require_once __DIR__ . '/../Service/QrItemAssembler.php';

use Xentral\Modules\PaymentQr\Service\EpcQrPayloadBuilder;
use Xentral\Modules\PaymentQr\Service\PaymentQrSettingsService;
use Xentral\Modules\PaymentQr\Service\QrItemAssembler;

$fails = 0;
function check(string $name, bool $cond): void {
  global $fails;
  if ($cond) { echo "OK   $name\n"; } else { $fails++; echo "FAIL $name\n"; }
}

class FakeDb {
  public function SelectArr($sql) { return []; }
}

$payloadBuilder = new EpcQrPayloadBuilder();
$settingsService = new PaymentQrSettingsService(new FakeDb());
$pngFactory = fn(string $payload): string => 'PNG:' . $payload;
$noWero = fn(int $dateiId): ?string => null;

// existierende Bilddatei fuer positive Wero-Faelle
$weroFile = tempnam(sys_get_temp_dir(), 'wero');
file_put_contents($weroFile, 'BILD');
$weroResolver = fn(int $dateiId): ?string => $weroFile;

$build = function(array $rechnung, array $configs, ?callable $wero = null)
    use ($pngFactory, $noWero, $payloadBuilder, $settingsService): array {
  return QrItemAssembler::build($rechnung, $configs, $pngFactory, $wero ?? $noWero, $payloadBuilder, $settingsService);
};

$rechnung = function(array $o = []): array {
  return array_merge([
    'belegnr' => 'RE-100', 'zahlungsweise' => 'rechnung', 'soll' => '123.45',
    'waehrung' => 'EUR', 'projekt' => '0', 'kundennummer' => 'K1',
  ], $o);
};
$giro = function(array $s = []): array {
  return ['id' => 1, 'type' => 'rechnung', 'modul' => 'rechnung_qr', 'projekt' => 0,
    'settings' => array_merge([
      'qr_aktiv' => '1', 'qr_nur_bei_passender_zahlungsweise' => '',
      'qr_iban' => 'DE71110220330123456789', 'qr_bic' => '',
      'qr_kontoinhaber' => 'Muster GmbH', 'qr_verwendungszweck' => '', 'qr_beschriftung' => '',
    ], $s)];
};
$paypal = function(array $s = []): array {
  return ['id' => 2, 'type' => 'paypal', 'modul' => 'paypal_qr', 'projekt' => 0,
    'settings' => array_merge([
      'qr_aktiv' => '1', 'qr_nur_bei_passender_zahlungsweise' => '',
      'paypalme_handle' => 'musterfirma', 'qr_beschriftung' => '',
    ], $s)];
};
$wero = function(array $s = []): array {
  return ['id' => 3, 'type' => 'wero', 'modul' => 'wero', 'projekt' => 0,
    'settings' => array_merge([
      'qr_aktiv' => '1', 'qr_nur_bei_passender_zahlungsweise' => '',
      'qr_datei' => '7', 'qr_beschriftung' => '',
    ], $s)];
};

// 1. Passende Zahlungsweise + qr_nur=1: genau 1 GiroCode-Item mit korrektem
//    EPC-Payload (IBAN/Name/Betrag/aufgeloester Zweck) und Default-Label
$items = $build($rechnung(), ['rechnung' => $giro(['qr_nur_bei_passender_zahlungsweise' => '1'])]);
$expectedPayload = "BCD\n002\n1\nSCT\n\nMuster GmbH\nDE71110220330123456789\nEUR123.45\n\n\nRE-100";
check('genau 1 GiroCode-Item', count($items) === 1);
check('GiroCode-Payload korrekt', ($items[0]['png'] ?? '') === 'PNG:' . $expectedPayload);
check('GiroCode Default-Label', ($items[0]['label'] ?? '') === 'Mit Banking-App scannen & bezahlen');
$items1b = $build($rechnung(), ['rechnung' => $giro(['qr_verwendungszweck' => 'Rg {BELEGNR} Kd {KUNDENNUMMER}'])]);
check('Verwendungszweck-Platzhalter aufgeloest', strpos($items1b[0]['png'] ?? '', 'Rg RE-100 Kd K1') !== false);

// 2. qr_nur=1 bei zahlungsweise=paypal: GiroCode entfaellt, PayPal kommt
$items2 = $build($rechnung(['zahlungsweise' => 'paypal']), [
  'rechnung' => $giro(['qr_nur_bei_passender_zahlungsweise' => '1']),
  'paypal' => $paypal(['qr_nur_bei_passender_zahlungsweise' => '1']),
]);
check('GiroCode entfaellt, PayPal kommt', count($items2) === 1
  && ($items2[0]['png'] ?? '') === 'PNG:https://paypal.me/musterfirma/123.45EUR');
check('PayPal Default-Label', ($items2[0]['label'] ?? '') === 'Mit PayPal zahlen');

// 3. qr_nur leer: Item kommt unabhaengig von der Zahlungsweise
$items3 = $build($rechnung(['zahlungsweise' => 'vorkasse']), ['rechnung' => $giro()]);
check('qr_nur leer: GiroCode trotz anderer Zahlungsweise', count($items3) === 1 && isset($items3[0]['png']));

// 4. Waehrung != EUR: GiroCode+PayPal entfallen, Wero bleibt; leere Waehrung = EUR
$items4 = $build($rechnung(['waehrung' => 'USD']),
  ['rechnung' => $giro(), 'paypal' => $paypal(), 'wero' => $wero()], $weroResolver);
check('USD: nur Wero-Item', count($items4) === 1 && ($items4[0]['imagefile'] ?? '') === $weroFile);
$items4b = $build($rechnung(['waehrung' => '']), ['rechnung' => $giro(), 'paypal' => $paypal()]);
check('leere Waehrung gilt als EUR', count($items4b) === 2);

// 5. Betrag <= 0: GiroCode+PayPal entfallen, Wero bleibt
$items5 = $build($rechnung(['soll' => '0.00']),
  ['rechnung' => $giro(), 'paypal' => $paypal(), 'wero' => $wero()], $weroResolver);
check('Betrag 0: nur Wero-Item', count($items5) === 1 && isset($items5[0]['imagefile']));
$items5b = $build($rechnung(['soll' => '-5.00']), ['rechnung' => $giro(), 'paypal' => $paypal()]);
check('Betrag negativ: keine GiroCode/PayPal-Items', $items5b === []);

// 6. Fehlende Pflichtdaten / Builder-Exception: GiroCode entfaellt still
$items6 = $build($rechnung(), ['rechnung' => $giro(['qr_iban' => '']), 'paypal' => $paypal()]);
check('fehlende IBAN: GiroCode entfaellt, PayPal bleibt', count($items6) === 1
  && strpos($items6[0]['png'] ?? '', 'paypal.me') !== false);
$items6b = $build($rechnung(), ['rechnung' => $giro(['qr_iban' => 'UNGUELTIG']), 'paypal' => $paypal()]);
check('Builder-Exception gefangen: PayPal bleibt', count($items6b) === 1
  && strpos($items6b[0]['png'] ?? '', 'paypal.me') !== false);
$items6c = $build($rechnung(), ['rechnung' => $giro(['qr_kontoinhaber' => ''])]);
check('fehlender Kontoinhaber: GiroCode entfaellt', $items6c === []);

// 7. PayPal-Link-Format + Handle-Bereinigung
$items7 = $build($rechnung(['soll' => '1234.5']), ['paypal' => $paypal()]);
check('PayPal-Betrag via number_format', ($items7[0]['png'] ?? '') === 'PNG:https://paypal.me/musterfirma/1234.50EUR');
$items7b = $build($rechnung(), ['paypal' => $paypal(['paypalme_handle' => ' @musterfirma '])]);
check('Handle: @ und Whitespace entfernt', ($items7b[0]['png'] ?? '') === 'PNG:https://paypal.me/musterfirma/123.45EUR');
$items7c = $build($rechnung(), ['paypal' => $paypal(['paypalme_handle' => 'https://paypal.me/musterfirma'])]);
check('Handle: paypal.me-Prefix entfernt', ($items7c[0]['png'] ?? '') === 'PNG:https://paypal.me/musterfirma/123.45EUR');
$items7d = $build($rechnung(), ['paypal' => $paypal(['paypalme_handle' => '  '])]);
check('leerer Handle: PayPal entfaellt', $items7d === []);
$items7e = $build($rechnung(), ['paypal' => $paypal(['paypalme_handle' => 'https://www.paypal.me/musterfirma/'])]);
check('Handle: Trailing-Slash entfernt', ($items7e[0]['png'] ?? '') === 'PNG:https://paypal.me/musterfirma/123.45EUR');
$items7f = $build($rechnung(), ['paypal' => $paypal(['paypalme_handle' => 'https://paypal.me/musterfirma/50'])]);
check('Handle: Betrags-Segment abgeschnitten', ($items7f[0]['png'] ?? '') === 'PNG:https://paypal.me/musterfirma/123.45EUR');
$items7g = $build($rechnung(), ['paypal' => $paypal(['paypalme_handle' => 'muster firma'])]);
check('Handle mit Leerzeichen: PayPal entfaellt', $items7g === []);
$items7h = $build($rechnung(), ['paypal' => $paypal(['paypalme_handle' => 'muster+firma?x=1'])]);
check('Handle mit Sonderzeichen: PayPal entfaellt', $items7h === []);

// 8. Wero: ohne aufgeloeste Bilddatei entfaellt das Item
$items8 = $build($rechnung(), ['wero' => $wero()], $noWero);
check('Wero ohne Datei entfaellt', $items8 === []);
$items8b = $build($rechnung(), ['wero' => $wero()],
  fn(int $id): ?string => sys_get_temp_dir() . '/gibt_es_nicht_' . uniqid() . '.png');
check('Wero mit nicht existenter Datei entfaellt', $items8b === []);
$items8c = $build($rechnung(['waehrung' => 'CHF', 'soll' => '0.00']), ['wero' => $wero()], $weroResolver);
check('Wero unabhaengig von Waehrung und Betrag', count($items8c) === 1
  && ($items8c[0]['imagefile'] ?? '') === $weroFile && ($items8c[0]['label'] ?? '') === 'Mit Wero zahlen');
$seenId = null;
$build($rechnung(), ['wero' => $wero(['qr_datei' => '7'])],
  function(int $id) use (&$seenId, $weroFile): ?string { $seenId = $id; return $weroFile; });
check('qr_datei wird als int an Resolver gegeben', $seenId === 7);

// 9. Reihenfolge GiroCode, PayPal, Wero + eigene Labels + qr_aktiv-Gate
$items9 = $build($rechnung(), [
  'wero' => $wero(['qr_beschriftung' => 'Wero!']),
  'paypal' => $paypal(['qr_beschriftung' => 'PP!']),
  'rechnung' => $giro(['qr_beschriftung' => 'Giro!']),
], $weroResolver);
check('Reihenfolge GiroCode, PayPal, Wero', count($items9) === 3
  && strpos($items9[0]['png'] ?? '', 'BCD') !== false
  && strpos($items9[1]['png'] ?? '', 'paypal.me') !== false
  && isset($items9[2]['imagefile']));
check('eigene Beschriftungen verwendet', ($items9[0]['label'] ?? '') === 'Giro!'
  && ($items9[1]['label'] ?? '') === 'PP!' && ($items9[2]['label'] ?? '') === 'Wero!');
$items9b = $build($rechnung(), ['rechnung' => $giro(['qr_aktiv' => ''])]);
check('qr_aktiv leer: Item entfaellt', $items9b === []);

unlink($weroFile);

echo $fails === 0 ? "\nALLE TESTS OK\n" : "\n$fails TEST(S) FEHLGESCHLAGEN\n";
exit($fails === 0 ? 0 : 1);
