<?php
declare(strict_types=1);
// Standalone-Test: php EpcQrPayloadBuilderTest.php  → Exit 0 = OK
require_once __DIR__ . '/../Service/EpcQrPayloadBuilder.php';

use Xentral\Modules\PaymentQr\Service\EpcQrPayloadBuilder;

$fails = 0;
function check(string $name, bool $cond): void {
  global $fails;
  if ($cond) { echo "OK   $name\n"; } else { $fails++; echo "FAIL $name\n"; }
}
function expectException(string $name, callable $fn): void {
  try { $fn(); check($name, false); }
  catch (\InvalidArgumentException $e) { check($name, true); }
}

$b = new EpcQrPayloadBuilder();

// 1. Referenz-Payload: Version 002, BIC leer, LF, kein Trailing-LF
$p = $b->build([
  'kontoinhaber' => 'Muster GmbH',
  'iban' => 'DE12 3456 7890 1234 5678 90',
  'betrag' => 123.45,
  'verwendungszweck' => 'RE-2026-1042',
]);
check('payload exakt', $p === "BCD\n002\n1\nSCT\n\nMuster GmbH\nDE12345678901234567890\nEUR123.45\n\n\nRE-2026-1042");
check('kein Trailing-Separator', substr($p, -1) !== "\n");

// 2. Mit BIC
$p2 = $b->build(['kontoinhaber'=>'X','iban'=>'DE12345678901234567890','bic'=>'ABCDDEFFXXX','betrag'=>1,'verwendungszweck'=>'T']);
check('BIC in Zeile 5', explode("\n", $p2)[4] === 'ABCDDEFFXXX');

// 3. Ohne Verwendungszweck: Trailing-Leerfelder entfallen (Payload endet nach Betrag)
$p3 = $b->build(['kontoinhaber'=>'X','iban'=>'DE12345678901234567890','betrag'=>1]);
check('trailing leere Felder weggelassen', $p3 === "BCD\n002\n1\nSCT\n\nX\nDE12345678901234567890\nEUR1.00");

// 4. Betragsformat: immer 2 Nachkommastellen, Punkt, keine Tausendertrenner
$p4 = $b->build(['kontoinhaber'=>'X','iban'=>'DE12345678901234567890','betrag'=>1234.5]);
check('Betrag EUR1234.50', strpos($p4, "EUR1234.50") !== false);

// 5. Validierungen → InvalidArgumentException
expectException('Betrag 0',        fn() => $b->build(['kontoinhaber'=>'X','iban'=>'DE12345678901234567890','betrag'=>0]));
expectException('Betrag negativ',  fn() => $b->build(['kontoinhaber'=>'X','iban'=>'DE12345678901234567890','betrag'=>-5]));
expectException('Betrag zu gross', fn() => $b->build(['kontoinhaber'=>'X','iban'=>'DE12345678901234567890','betrag'=>1000000000]));
expectException('Name leer',       fn() => $b->build(['kontoinhaber'=>'','iban'=>'DE12345678901234567890','betrag'=>1]));
expectException('Name >70',        fn() => $b->build(['kontoinhaber'=>str_repeat('a',71),'iban'=>'DE12345678901234567890','betrag'=>1]));
expectException('IBAN ungueltig',  fn() => $b->build(['kontoinhaber'=>'X','iban'=>'FOO','betrag'=>1]));
expectException('BIC ungueltig',   fn() => $b->build(['kontoinhaber'=>'X','iban'=>'DE12345678901234567890','bic'=>'12','betrag'=>1]));
expectException('Zweck >140',      fn() => $b->build(['kontoinhaber'=>'X','iban'=>'DE12345678901234567890','betrag'=>1,'verwendungszweck'=>str_repeat('z',141)]));

// 6. 331-Byte-Limit (UTF-8-Bytes zaehlen: 'ä' = 2 Bytes):
// Name 70 Zeichen 'ä' = 140 Bytes, Zweck 140 Zeichen 'ä' = 280 Bytes
// -> Feldgrenzen (70/140 Zeichen) eingehalten, Gesamt ~469 Bytes > 331
expectException('331-Byte-Limit', function() use ($b) {
  $b->build(['kontoinhaber'=>str_repeat('ä',70),'iban'=>'DE12345678901234567890','betrag'=>1,'verwendungszweck'=>str_repeat('ä',140)]);
});

// 6b. Ungueltiges UTF-8 wird abgelehnt (utf8Len darf false nicht zu 0 casten)
expectException('ungueltiges UTF-8', fn() => $b->build(['kontoinhaber' => str_repeat("\xC3", 10), 'iban' => 'DE12345678901234567890', 'betrag' => 1]));

// 7. Umlaute bleiben UTF-8 erhalten
$p7 = $b->build(['kontoinhaber'=>'Müller & Söhne GmbH','iban'=>'DE12345678901234567890','betrag'=>9.99,'verwendungszweck'=>'Rechnung Nr. 7']);
check('Umlaute unveraendert', strpos($p7, 'Müller & Söhne GmbH') !== false);

// 8. Steuerzeichen werden abgelehnt (eingebettetes LF wuerde die Payload-Zeilen verschieben)
expectException('LF im Namen',            fn() => $b->build(['kontoinhaber' => "Foo\nBar", 'iban' => 'DE12345678901234567890', 'betrag' => 1]));
expectException('LF im Verwendungszweck', fn() => $b->build(['kontoinhaber' => 'X', 'iban' => 'DE12345678901234567890', 'betrag' => 1, 'verwendungszweck' => "A\nB"]));

// 9. Komma-Dezimalstring wird abgelehnt statt still verfaelscht ((float)'123,45' waere 123.0)
expectException('Komma-Betrag abgelehnt', fn() => $b->build(['kontoinhaber' => 'X', 'iban' => 'DE12345678901234567890', 'betrag' => '123,45']));

// 10. Akzeptanz-Grenzen gepinnt
$p10 = $b->build(['kontoinhaber' => 'X', 'iban' => 'DE12345678901234567890', 'betrag' => '123.45']);
check('String-Betrag mit Punkt akzeptiert', strpos($p10, 'EUR123.45') !== false);
$p10b = $b->build(['kontoinhaber' => 'X', 'iban' => 'DE12345678901234567890', 'betrag' => 0.01]);
check('Betrag 0.01 akzeptiert', strpos($p10b, 'EUR0.01') !== false);
$p10c = $b->build(['kontoinhaber' => 'X', 'iban' => 'DE12345678901234567890', 'betrag' => 999999999.99]);
check('Betrag-Maximum akzeptiert', strpos($p10c, 'EUR999999999.99') !== false);
$p10d = $b->build(['kontoinhaber' => str_repeat('a', 70), 'iban' => 'DE12345678901234567890', 'betrag' => 1]);
check('Name exakt 70 akzeptiert', strpos($p10d, str_repeat('a', 70)) !== false);
$p10e = $b->build(['kontoinhaber' => 'X', 'iban' => 'DE12345678901234567890', 'betrag' => 1, 'verwendungszweck' => str_repeat('z', 140)]);
check('Zweck exakt 140 akzeptiert', strpos($p10e, str_repeat('z', 140)) !== false);
$p10f = $b->build(['kontoinhaber' => 'X', 'iban' => 'DE12345678901234567890', 'betrag' => 123.456]);
check('Rundung 123.456 -> EUR123.46', strpos($p10f, 'EUR123.46') !== false);

echo $fails === 0 ? "\nALLE TESTS OK\n" : "\n$fails TEST(S) FEHLGESCHLAGEN\n";
exit($fails === 0 ? 0 : 1);
