<?php
declare(strict_types=1);
require_once __DIR__ . '/../Service/QrBlockRenderer.php';

use Xentral\Modules\PaymentQr\Service\QrBlockRenderer;

$fails = 0;
function check(string $name, bool $cond): void {
  global $fails;
  if ($cond) { echo "OK   $name\n"; } else { $fails++; echo "FAIL $name\n"; }
}

class PdfStub {
  public $calls = [];
  public $y = 200.0;
  public $PageBreakTrigger = 260.0;
  public $lMargin = 22.0;
  public $FontSizePt = 10;
  public $FontStyle = '';
  public function GetY() { return $this->y; }
  public function SetY($y) { $this->y = $y; $this->calls[] = ['SetY', $y]; }
  public function SetXY($x, $y) { $this->calls[] = ['SetXY', $x, $y]; }
  public function AddPage() { $this->y = 50.0; $this->calls[] = ['AddPage']; }
  public function Image($file, $x, $y, $w, $h, $type = '') {
    $this->calls[] = ['Image', $file, $x, $y, $w, $h, $type, file_exists($file)];
  }
  public function SetFont($f, $s = '', $pt = 0) { $this->calls[] = ['SetFont']; }
  public function GetFont() { return 'Arial'; }
  public function MultiCell($w, $h, $txt, $b = 0, $a = 'L') { $this->calls[] = ['MultiCell', $w, $txt]; }
}
class ThrowingImagePdfStub extends PdfStub {
  public function Image($file, $x, $y, $w, $h, $type = '') { throw new \Exception('boom'); }
}
function callNames(PdfStub $p): array { return array_map(fn($c) => $c[0], $p->calls); }
function imageCalls(PdfStub $p): array { return array_values(array_filter($p->calls, fn($c) => $c[0] === 'Image')); }

// winziges gueltiges PNG (1x1) fuer Tests
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
// winziges gueltiges GIF (1x1) und JPEG (1x1) fuer inhaltsbasierte Typpruefung
$gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
$jpeg = base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AVN//2Q==');

$r = new QrBlockRenderer();

// 1. Zwei Items nebeneinander: x-Abstand = 25 + 10
$pdf = new PdfStub();
$r->render($pdf, [ ['png' => $png, 'label' => 'GiroCode'], ['png' => $png, 'label' => 'PayPal'] ]);
$imgs = imageCalls($pdf);
check('zwei Images gezeichnet', count($imgs) === 2);
check('Temp-Dateien existierten beim Image-Aufruf', $imgs[0][7] === true && $imgs[1][7] === true);
check('x-Versatz 35mm', abs(($imgs[1][2] - $imgs[0][2]) - 35.0) < 0.001);
check('Startet am linken Rand', abs($imgs[0][2] - 22.0) < 0.001);
check('QR-Groesse 25mm', $imgs[0][4] == 25 && $imgs[0][5] == 25);
check('Labels gerendert', count(array_filter($pdf->calls, fn($c) => $c[0] === 'MultiCell' && in_array($c[2], ['GiroCode', 'PayPal']))) === 2);
check('kein AddPage noetig (200+37<260)', !in_array('AddPage', callNames($pdf)));

// 2. Seitenumbruch, wenn Platz nicht reicht (y=240, 240+37>260)
$pdf2 = new PdfStub();
$pdf2->y = 240.0;
$r->render($pdf2, [ ['png' => $png, 'label' => 'GiroCode'] ]);
check('AddPage bei Platzmangel', in_array('AddPage', callNames($pdf2)));

// 3. Temp-Dateien werden aufgeraeumt (nach der Schleife, wegen FPDF-Image-Cache)
$pdf3 = new PdfStub();
$r->render($pdf3, [ ['png' => $png, 'label' => 'X'] ]);
$file = imageCalls($pdf3)[0][1];
check('Temp-Datei geloescht', !file_exists($file));

// 4. Wero: vorhandene Bilddatei wird direkt verwendet, nicht geloescht
$weroFile = tempnam(sys_get_temp_dir(), 'wero') . '.png';
file_put_contents($weroFile, $png);
$pdf4 = new PdfStub();
$r->render($pdf4, [ ['imagefile' => $weroFile, 'label' => 'Wero'] ]);
check('Wero-Datei verwendet', imageCalls($pdf4)[0][1] === $weroFile);
check('Wero-Datei NICHT geloescht', file_exists($weroFile));
// Typ wird inhaltsbasiert bestimmt (PNG-Daten -> 'png')
check('Wero-Typ PNG (inhaltsbasiert)', imageCalls($pdf4)[0][6] === 'png');
unlink($weroFile);

// 5. Fehlerhaftes Item wird uebersprungen, Rest gerendert
$pdf5 = new PdfStub();
$r->render($pdf5, [ ['imagefile' => '/gibt/es/nicht.png', 'label' => 'kaputt'], ['png' => $png, 'label' => 'OK'] ]);
check('kaputtes Item uebersprungen, gutes gerendert', count(imageCalls($pdf5)) === 1);

// 6. Leere Item-Liste: gar nichts passiert
$pdf6 = new PdfStub();
$r->render($pdf6, []);
check('leere Liste = keine Calls', $pdf6->calls === []);

// 7. Garbage-PNG (keine PNG-Magic-Bytes): Item entfaellt, bevor FPDF die Daten sieht
$pdf7 = new PdfStub();
$r->render($pdf7, [ ['png' => 'kein-png', 'label' => 'X'] ]);
check('Garbage-PNG uebersprungen', count(imageCalls($pdf7)) === 0);

// 8. Wero-Datei mit GIF-Inhalt trotz .png-Endung: inhaltsbasiert abgelehnt
$gifFile = tempnam(sys_get_temp_dir(), 'werogif') . '.png';
file_put_contents($gifFile, $gif);
$pdf8 = new PdfStub();
$r->render($pdf8, [ ['imagefile' => $gifFile, 'label' => 'Wero'] ]);
check('GIF-Inhalt trotz .png-Endung abgelehnt', count(imageCalls($pdf8)) === 0);
unlink($gifFile);

// 9. Werfendes Image(): Exception entkommt nicht, Temp-Dateien trotzdem aufgeraeumt
$payqrGlob = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'payqr*';
$before = count(glob($payqrGlob));
$pdf9 = new ThrowingImagePdfStub();
$thrown = false;
try { $r->render($pdf9, [ ['png' => $png, 'label' => 'X'] ]); } catch (\Throwable $e) { $thrown = true; }
$after = count(glob($payqrGlob));
check('Image-Exception entkommt nicht', $thrown === false);
check('Temp-Dateien trotz Exception aufgeraeumt', $after === $before);

// 10. Finale Cursor-Position: y0 + 4 (Top-Margin) + 25 (QR) + 8 (Label) = y0 + 37
$pdf10 = new PdfStub();
$r->render($pdf10, [ ['png' => $png, 'label' => 'X'] ]);
check('Cursor unter dem Block (y0+37)', abs($pdf10->y - 237.0) < 0.001);

// 11. JPEG-Inhalt (absichtlich mit .png-Endung): inhaltsbasiert als 'jpg' erkannt
$jpegFile = tempnam(sys_get_temp_dir(), 'werojpg') . '.png';
file_put_contents($jpegFile, $jpeg);
$pdf11 = new PdfStub();
$r->render($pdf11, [ ['imagefile' => $jpegFile, 'label' => 'Wero'] ]);
check('JPEG inhaltsbasiert als jpg erkannt', count(imageCalls($pdf11)) === 1 && imageCalls($pdf11)[0][6] === 'jpg');
unlink($jpegFile);

echo $fails === 0 ? "\nALLE TESTS OK\n" : "\n$fails TEST(S) FEHLGESCHLAGEN\n";
exit($fails === 0 ? 0 : 1);
