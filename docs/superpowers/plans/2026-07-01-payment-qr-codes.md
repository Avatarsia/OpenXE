# ZahlungsQR Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Update-sicheres OpenXE-Modul, das GiroCode- (EPC-QR), PayPal.me- und statische Wero-QR-Codes auf Rechnungs-PDFs rendert.

**Architecture:** Neues Modul `zahlungsqr` (www/pages) + Services in `classes/Modules/PaymentQr/`; PDF-Einbindung ausschließlich über den DB-Hook `briefpapier_render_footer_hook2`; Einstellungen in `zahlungsweisen.einstellungen_json` über drei neue Zahlungsweisen-Modulklassen. Null Änderungen an bestehenden Core-Dateien.

**Tech Stack:** PHP 8 (OpenXE/Xentral-CE-Fork), FPDF/FPDI, vorhandene `BarcodeFactory` (TCPDF-2D-Barcode), MySQL/MariaDB, gh CLI für Git-Fallback.

**Spec:** `docs/superpowers/specs/2026-07-01-payment-qr-design.md` (freigegeben, Review: Approved)

---

## Rahmenbedingungen der Umgebung (verifiziert am 2026-07-01)

| Fakt | Konsequenz |
|---|---|
| PHP 8.4.22 CLI unter Windows verfügbar (`php -v`) | `php -l` und standalone Test-Scripts laufen lokal |
| PHPUnit NICHT im vendor/ (phpunit.xml ist Altlast, composer.json hat kein require-dev) | Tests als **standalone PHP-Scripts** mit eigenen assert-Helpern, Runner = `php <script>`, Exit-Code 0/1 |
| `git clone`/fetch-pack bricht auf diesem Netz reproduzierbar ab (early EOF, 8+ Versuche); 206-MB-Zip via codeload lief fehlerfrei | Partial Clone **ohne Checkout** (`--filter=blob:none --no-checkout`) — lädt nur Metadaten (klein); Push lädt nur unsere kleinen Objekte hoch. Fallback: GitHub REST API via `gh` (authentifiziert als Avatarsia, repo-Scope) |
| Quellcode-Referenz: `OpenXE-src/` (Zip-Stand production) | Dort NUR lesen; alle neuen Dateien entstehen im Git-Repo `OpenXE-git/` |
| Kein lokaler OpenXE-Server/DB | Alles Instanzabhängige (Install-Aufruf, Upload, PDF-Sichtprüfung) ist als Abnahme-Schritt für den Nutzer dokumentiert (Task 8) |

**⚠ HINWEIS (nach Task 0 obsolet):** Die lokale Git-Route ist gescheitert (s. Banner in Task 0). Es gilt: Dateien liegen unter `feature-files/<repo-pfad>`; **überall, wo dieser Plan `OpenXE-git/<pfad>` nennt, ist `feature-files/<pfad>` gemeint**; jeder `git add/commit/push`-Step wird durch `python push_commit.py "<message>" <repo/pfad>...` ersetzt. In `OpenXE-git/` KEINE git-Kommandos mehr ausführen (Index ist unvollständig — `git commit` dort würde Müll erzeugen).

**Arbeitsverzeichnis aller Kommandos:** `C:\Users\3D Partner\Desktop\Claude\openxe qrcodes` (Git Bash: `/c/Users/3D Partner/Desktop/Claude/openxe qrcodes`). Pfade mit Leerzeichen immer quoten.

**Commit-Konvention:** Commits zentral (nicht durch Subagenten). Jede Commit-Message endet mit `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`.

---

## Datei-Struktur (alle Dateien NEU)

```
OpenXE-git/
├─ docs/superpowers/specs/2026-07-01-payment-qr-design.md      (Spec, Task 0)
├─ docs/superpowers/plans/2026-07-01-payment-qr-codes.md       (dieser Plan, Task 0)
├─ classes/Modules/PaymentQr/
│  ├─ Bootstrap.php                       Container-Service-Registrierung
│  ├─ README.md                           Kurzdoku Install/Deinstall/Konfiguration
│  ├─ Service/EpcQrPayloadBuilder.php     EPC069-12-Payload (pure, keine Deps)
│  ├─ Service/PaymentQrSettingsService.php  Settings projektbewusst laden (Dep: DB via Konstruktor)
│  ├─ Service/QrBlockRenderer.php         QR-Block ins FPDF zeichnen (Deps: BarcodeFactory via Konstruktor)
│  └─ tests/
│     ├─ EpcQrPayloadBuilderTest.php      standalone, php <file>
│     ├─ PaymentQrSettingsServiceTest.php standalone, Fake-DB
│     ├─ QrBlockRendererTest.php          standalone, PDF-Stub + Fake-Factory
│     └─ ZahlungsweisenStrukturTest.php   Smoke-Test der 3 Modulklassen
├─ www/lib/zahlungsweisen/
│  ├─ rechnung_qr.php                     Zahlungsweise_rechnung_qr extends Zahlungsweise_rechnung
│  ├─ paypal_qr.php                       Zahlungsweise_paypal_qr extends Zahlungsweisenmodul
│  └─ wero.php                            Zahlungsweise_wero extends Zahlungsweisenmodul
├─ www/pages/zahlungsqr.php               Modulseite: Install/Uninstall, Übersicht, Wero-Upload, Hook-Handler
└─ www/pages/content/zahlungsqr_settings.tpl  Template der Modulseite
```

Verantwortlichkeiten: Payload-Bau (EpcQrPayloadBuilder) ist frei von OpenXE-Abhängigkeiten und vollständig testbar; Settings-Beschaffung (SettingsService) kapselt SQL; Zeichnen (QrBlockRenderer) kapselt FPDF-Aufrufe; der Controller `Zahlungsqr` verdrahtet nur noch.

**Referenz-Muster im Bestandscode** (bei jedem Task zuerst ansehen, Pfade relativ `OpenXE-src/`):
- Hook-Punkt: `www/lib/dokumente/class.briefpapier.php:2417` (renderFooter → `RunHook('briefpapier_render_footer_hook2', 1, $this)`)
- Hook-API: `www/lib/class.erpapi.php:10580` (GenerateHook), `:10694` (RegisterHook), `:11044` (RunHook), RegisterNavigationHook ebenfalls in erpapi
- QR-PNG-Einbettung: `www/lib/dokumente/class.etiketten.php:189` (createQrCode→toPng→Temp-Datei→Image→unlink)
- BarcodeFactory: `classes/Components/Barcode/BarcodeFactory.php` + `Bootstrap.php` daneben (Container-Muster)
- Zahlungsweisen-Modul-Muster: `www/lib/zahlungsweisen/rechnung.php` (require_once Basisklasse, Konstruktor lädt einstellungen_json) und `www/lib/class.zahlungsweise.php` (Einstellungen()-Formular, Struktur-Keys = JSON-Keys)
- Update-sicheres Modul-Vorbild: `www/pages/lexwareoffice.php` + `classes/Modules/LexwareOffice/` (Branch feature/lexwareoffice-module im Fork)

---

### Task 0: Git-Repo aufsetzen und Push-Pipeline validieren

> **⚠ ERGEBNIS DER AUSFÜHRUNG (2026-07-01):** Step 0.1 (Partial Clone) gelang, aber Step 0.2 scheiterte endgültig: `git read-tree`/`git write-tree` verlangen in einem Blob-losen Partial Clone die referenzierten Blob-Objekte (Promisor-Prefetch → Netzwerkabbruch; mit `GIT_NO_LAZY_FETCH=1` verweigert `write-tree` den Baum). **Es gilt die API-Route:** Alle Dateien werden unter `feature-files/<repo-relativer-pfad>` gepflegt; Commits erzeugt `python push_commit.py "<message>" <repo/pfad1> <repo/pfad2> ...` (Git-Data-API: Blobs → Tree mit base_tree → Commit → Ref-Update; ein sauberer Multi-File-Commit pro Task, server-seitiges Tree-Splicing, null Blob-Downloads). Der Branch wurde einmalig per `gh api repos/.../git/refs` vom production-HEAD erzeugt. **Alle `git add/commit/push`-Steps in Tasks 1-7 sind durch einen `push_commit.py`-Aufruf mit denselben Dateien/Messages ersetzt.** Das lokale `OpenXE-git/` wird nicht mehr verwendet.

**Files:**
- Create: `OpenXE-git/` (Partial Clone)
- Create: `OpenXE-git/docs/superpowers/specs/2026-07-01-payment-qr-design.md` (Kopie)
- Create: `OpenXE-git/docs/superpowers/plans/2026-07-01-payment-qr-codes.md` (Kopie)

- [ ] **Step 0.1: Partial Clone ohne Checkout**

```bash
cd "/c/Users/3D Partner/Desktop/Claude/openxe qrcodes"
git clone --filter=blob:none --no-checkout https://github.com/Avatarsia/OpenXE OpenXE-git
cd OpenXE-git
git config core.longpaths true
git log -1 --format='%h %ci %s' origin/production
```
Expected: Clone ohne Fehler (lädt nur Commits/Trees, wenige MB — der frühere Fehlschlag betraf das Blob-Nachladen beim Checkout, das hier nie passiert). `git log` zeigt den production-HEAD (Stand ~2026-06-10).

Fallback, falls auch das scheitert → **GitHub-API-Route** (dann gilt für ALLE Commit-Steps dieses Plans statt add/commit/push):
```bash
# Branch anlegen (einmalig): SHA von production holen, Ref erzeugen
SHA=$(gh api repos/Avatarsia/OpenXE/git/ref/heads/production --jq .object.sha)
gh api repos/Avatarsia/OpenXE/git/refs -f ref='refs/heads/feature/payment-qr-codes' -f sha="$SHA"
# Pro Datei (erzeugt je einen Commit auf dem Branch):
gh api -X PUT "repos/Avatarsia/OpenXE/contents/<repo/pfad/datei.php>" \
  -f message='<commit message>' -f branch='feature/payment-qr-codes' \
  -f content="$(base64 -w0 '<lokale/datei>')"
```

- [ ] **Step 0.2: Feature-Branch ohne Checkout anlegen**

```bash
cd OpenXE-git
git branch feature/payment-qr-codes origin/production
git symbolic-ref HEAD refs/heads/feature/payment-qr-codes
git read-tree origin/production^{tree}
git write-tree
git rev-parse origin/production^{tree}
```
Expected: Die letzten beiden Kommandos geben **denselben** Tree-SHA aus (Index == production-Baum, nichts verloren).

- [ ] **Step 0.3: Spec + Plan ins Repo kopieren, erster Commit, Push**

```bash
mkdir -p docs/superpowers/specs docs/superpowers/plans
cp "../docs/superpowers/specs/2026-07-01-payment-qr-design.md" docs/superpowers/specs/
cp "../docs/superpowers/plans/2026-07-01-payment-qr-codes.md" docs/superpowers/plans/
git add docs/superpowers/specs/2026-07-01-payment-qr-design.md docs/superpowers/plans/2026-07-01-payment-qr-codes.md
git commit -m "docs: Spec und Plan fuer ZahlungsQR-Modul (Zahlungs-QR-Codes auf Rechnungen)

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
git push -u origin feature/payment-qr-codes
```
Expected: Push OK (Upload nur weniger KB). Verifikation: `gh api repos/Avatarsia/OpenXE/branches/feature/payment-qr-codes --jq .commit.commit.message | head -1` zeigt die Commit-Message. Damit ist die gesamte Pipeline bewiesen, bevor Code entsteht.

---

### Task 1: EpcQrPayloadBuilder (TDD)

**Files:**
- Create: `OpenXE-git/classes/Modules/PaymentQr/Service/EpcQrPayloadBuilder.php`
- Test: `OpenXE-git/classes/Modules/PaymentQr/tests/EpcQrPayloadBuilderTest.php`

- [ ] **Step 1.1: Failing Test schreiben**

Vollständiger Test (standalone, kein PHPUnit):

```php
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

// 7. Umlaute bleiben UTF-8 erhalten
$p7 = $b->build(['kontoinhaber'=>'Müller & Söhne GmbH','iban'=>'DE12345678901234567890','betrag'=>9.99,'verwendungszweck'=>'Rechnung Nr. 7']);
check('Umlaute unveraendert', strpos($p7, 'Müller & Söhne GmbH') !== false);

echo $fails === 0 ? "\nALLE TESTS OK\n" : "\n$fails TEST(S) FEHLGESCHLAGEN\n";
exit($fails === 0 ? 0 : 1);
```

- [ ] **Step 1.2: Test laufen lassen — muss fehlschlagen**

Run: `php "OpenXE-git/classes/Modules/PaymentQr/tests/EpcQrPayloadBuilderTest.php"`
Expected: Fatal error (EpcQrPayloadBuilder.php nicht vorhanden).

- [ ] **Step 1.3: Implementierung**

```php
<?php

namespace Xentral\Modules\PaymentQr\Service;

/**
 * Baut den Payload eines EPC-QR-Codes (GiroCode) nach EPC069-12 v3.1.
 *
 * Version 002 (BIC optional, EWR), Zeichensatz UTF-8, Trenner LF,
 * kein Trenner nach dem letzten belegten Element, Gesamtlaenge max. 331 Bytes.
 */
class EpcQrPayloadBuilder
{
    const MAX_PAYLOAD_BYTES = 331;
    const MAX_NAME_LENGTH = 70;
    const MAX_REMITTANCE_LENGTH = 140;
    const MIN_AMOUNT = 0.01;
    const MAX_AMOUNT = 999999999.99;

    /**
     * @param array $data Keys: kontoinhaber (Pflicht), iban (Pflicht),
     *                    bic (optional), betrag (Pflicht, EUR),
     *                    verwendungszweck (optional, unstrukturiert)
     *
     * @throws \InvalidArgumentException bei ungueltigen Daten
     *
     * @return string EPC-Payload
     */
    public function build(array $data)
    {
        $name = trim((string)($data['kontoinhaber'] ?? ''));
        if ($name === '' || mb_strlen($name, 'UTF-8') > self::MAX_NAME_LENGTH) {
            throw new \InvalidArgumentException('Kontoinhaber fehlt oder laenger als 70 Zeichen');
        }

        $iban = strtoupper(preg_replace('/\s+/', '', (string)($data['iban'] ?? '')));
        if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $iban)) {
            throw new \InvalidArgumentException('IBAN ungueltig: ' . $iban);
        }

        $bic = strtoupper(trim((string)($data['bic'] ?? '')));
        if ($bic !== '' && !preg_match('/^[A-Z0-9]{8}([A-Z0-9]{3})?$/', $bic)) {
            throw new \InvalidArgumentException('BIC ungueltig: ' . $bic);
        }

        $betrag = (float)($data['betrag'] ?? 0);
        if ($betrag < self::MIN_AMOUNT || $betrag > self::MAX_AMOUNT) {
            throw new \InvalidArgumentException('Betrag ausserhalb 0.01 bis 999999999.99');
        }
        $betragStr = 'EUR' . number_format($betrag, 2, '.', '');

        $zweck = trim((string)($data['verwendungszweck'] ?? ''));
        if (mb_strlen($zweck, 'UTF-8') > self::MAX_REMITTANCE_LENGTH) {
            throw new \InvalidArgumentException('Verwendungszweck laenger als 140 Zeichen');
        }

        // Elementreihenfolge nach EPC069-12; Purpose (9) und
        // strukturierte Referenz (10) werden nicht genutzt.
        $elements = [
            'BCD',       // 1 Service Tag
            '002',       // 2 Version
            '1',         // 3 Zeichensatz: UTF-8
            'SCT',       // 4 Identification
            $bic,        // 5 BIC (bei 002 optional)
            $name,       // 6 Empfaengername
            $iban,       // 7 IBAN
            $betragStr,  // 8 Betrag
            '',          // 9 Purpose Code
            '',          // 10 strukturierte Referenz
            $zweck,      // 11 unstrukturierter Verwendungszweck
        ];

        // Leere Elemente am Ende entfernen (Spec: duerfen weggelassen werden)
        while ($elements !== [] && end($elements) === '') {
            array_pop($elements);
        }

        $payload = implode("\n", $elements);
        if (strlen($payload) > self::MAX_PAYLOAD_BYTES) {
            throw new \InvalidArgumentException('EPC-Payload ueberschreitet 331 Bytes');
        }

        return $payload;
    }
}
```

- [ ] **Step 1.4: Test laufen lassen — muss bestehen**

Run: `php "OpenXE-git/classes/Modules/PaymentQr/tests/EpcQrPayloadBuilderTest.php"`
Expected: `ALLE TESTS OK`, Exit 0.

- [ ] **Step 1.5: Lint + Commit**

```bash
php -l "OpenXE-git/classes/Modules/PaymentQr/Service/EpcQrPayloadBuilder.php"
php -l "OpenXE-git/classes/Modules/PaymentQr/tests/EpcQrPayloadBuilderTest.php"
cd OpenXE-git
git add classes/Modules/PaymentQr/Service/EpcQrPayloadBuilder.php classes/Modules/PaymentQr/tests/EpcQrPayloadBuilderTest.php
git commit -m "feat(paymentqr): EPC069-12-Payload-Builder fuer GiroCode

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>"
git push
```

---

### Task 2: PaymentQrSettingsService (TDD, Fake-DB)

**Files:**
- Create: `OpenXE-git/classes/Modules/PaymentQr/Service/PaymentQrSettingsService.php`
- Test: `OpenXE-git/classes/Modules/PaymentQr/tests/PaymentQrSettingsServiceTest.php`

Verantwortung: aktive QR-Konfigurationen projektbewusst aus `zahlungsweisen` laden; Verwendungszweck-Platzhalter auflösen. DB wird als Objekt mit `SelectArr($sql)` injiziert (wie `$app->DB`).

- [ ] **Step 2.1: Failing Test schreiben**

```php
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
```

- [ ] **Step 2.2: Run — Expected: FAIL** (`php .../PaymentQrSettingsServiceTest.php` → Fatal error, Datei fehlt)

- [ ] **Step 2.3: Implementierung**

```php
<?php

namespace Xentral\Modules\PaymentQr\Service;

/**
 * Laedt die QR-Konfigurationen aus der Tabelle `zahlungsweisen`.
 *
 * Projektlogik wie erpAPI::Zahlungsweisetext: projektspezifischer
 * Eintrag gewinnt gegen den globalen (projekt = 0).
 */
class PaymentQrSettingsService
{
    /** Zahlungsweisen-Module, die QR-Einstellungen tragen */
    const QR_MODULES = ['rechnung_qr', 'paypal_qr', 'wero'];

    /** @var object DB-Objekt mit SelectArr() (Signatur wie $app->DB) */
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * @param int $projekt Projekt-ID des Belegs (0 = keins)
     *
     * @return array type => ['id'=>..,'type'=>..,'modul'=>..,'projekt'=>..,'settings'=>array]
     *               nur Eintraege mit gesetztem qr_aktiv
     */
    public function getActiveQrConfigs($projekt)
    {
        $projekt = (int)$projekt;
        $moduleList = "'" . implode("','", self::QR_MODULES) . "'";
        $rows = $this->db->SelectArr(
            "SELECT z.id, z.type, z.modul, z.projekt, z.einstellungen_json
             FROM `zahlungsweisen` AS `z`
             WHERE z.modul IN ($moduleList) AND z.aktiv = 1 AND z.geloescht = 0
               AND (z.projekt = 0 OR z.projekt = $projekt)
             ORDER BY z.projekt DESC"
        );
        if (empty($rows)) {
            return [];
        }
        $configs = [];
        foreach ($rows as $r) {
            $type = (string)$r['type'];
            if (isset($configs[$type])) {
                continue; // projektspezifischer Eintrag (projekt DESC) hat gewonnen
            }
            $settings = json_decode((string)$r['einstellungen_json'], true);
            if (!is_array($settings) || empty($settings['qr_aktiv'])) {
                continue;
            }
            $configs[$type] = [
                'id' => (int)$r['id'],
                'type' => $type,
                'modul' => (string)$r['modul'],
                'projekt' => (int)$r['projekt'],
                'settings' => $settings,
            ];
        }
        return $configs;
    }

    /**
     * Ersetzt {BELEGNR} und {KUNDENNUMMER}; leere Vorlage = Belegnummer.
     *
     * @return string
     */
    public function resolveVerwendungszweck($vorlage, array $beleg)
    {
        $vorlage = trim((string)$vorlage);
        if ($vorlage === '') {
            $vorlage = '{BELEGNR}';
        }
        return strtr($vorlage, [
            '{BELEGNR}' => (string)($beleg['belegnr'] ?? ''),
            '{KUNDENNUMMER}' => (string)($beleg['kundennummer'] ?? ''),
        ]);
    }
}
```

- [ ] **Step 2.4: Run — Expected: `ALLE TESTS OK`**

- [ ] **Step 2.5: Lint + Commit** (analog Task 1; Message: `feat(paymentqr): Settings-Service mit projektbewusster Aufloesung`)

---

### Task 3: QrBlockRenderer (TDD, PDF-Stub + Fake-Factory)

**Files:**
- Create: `OpenXE-git/classes/Modules/PaymentQr/Service/QrBlockRenderer.php`
- Test: `OpenXE-git/classes/Modules/PaymentQr/tests/QrBlockRendererTest.php`

Verantwortung: bekommt FPDF-artiges Objekt + Liste von QR-Items, zeichnet sie nebeneinander (25 mm, Beschriftung 8 pt darunter), macht vorher einen Seitenumbruch-Check, räumt Temp-Dateien auf, überspringt fehlerhafte Items einzeln. Items:
`['png' => <binary>, 'label' => string]` (GiroCode/PayPal: PNG kommt von der BarcodeFactory — die Erzeugung passiert im Controller/Service davor) **oder** `['imagefile' => </pfad/wero.png>, 'label' => string]` (Wero).

Layout-Konstanten: `QR_SIZE_MM = 25`, `GAP_MM = 10`, `LABEL_H_MM = 8`, `BLOCK_TOP_MARGIN_MM = 4`. Benötigte Höhe = 4 + 25 + 8 = 37 mm.

- [ ] **Step 3.1: Failing Test** — Stub zeichnet auf, was aufgerufen wird:

```php
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
function callNames(PdfStub $p): array { return array_map(fn($c) => $c[0], $p->calls); }
function imageCalls(PdfStub $p): array { return array_values(array_filter($p->calls, fn($c) => $c[0] === 'Image')); }

// winziges gueltiges PNG (1x1) fuer Tests
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

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

// 3. Temp-Dateien werden aufgeraeumt
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
unlink($weroFile);

// 5. Fehlerhaftes Item wird uebersprungen, Rest gerendert
$pdf5 = new PdfStub();
$r->render($pdf5, [ ['imagefile' => '/gibt/es/nicht.png', 'label' => 'kaputt'], ['png' => $png, 'label' => 'OK'] ]);
check('kaputtes Item uebersprungen, gutes gerendert', count(imageCalls($pdf5)) === 1);

// 6. Leere Item-Liste: gar nichts passiert
$pdf6 = new PdfStub();
$r->render($pdf6, []);
check('leere Liste = keine Calls', $pdf6->calls === []);

echo $fails === 0 ? "\nALLE TESTS OK\n" : "\n$fails TEST(S) FEHLGESCHLAGEN\n";
exit($fails === 0 ? 0 : 1);
```

- [ ] **Step 3.2: Run — Expected: FAIL** (Datei fehlt)

- [ ] **Step 3.3: Implementierung**

```php
<?php

namespace Xentral\Modules\PaymentQr\Service;

/**
 * Zeichnet den Zahlungs-QR-Block in ein FPDF-basiertes Beleg-PDF
 * (Aufruf aus dem Hook briefpapier_render_footer_hook2, d.h. im
 * normalen Content-Flow direkt nach dem Zahlungsweise-Hinweistext).
 *
 * Items: ['png' => <PNG-Binaerdaten>, 'label' => string]
 *   oder ['imagefile' => <Pfad>, 'label' => string]
 */
class QrBlockRenderer
{
    const QR_SIZE_MM = 25;
    const GAP_MM = 10;
    const LABEL_H_MM = 8;
    const BLOCK_TOP_MARGIN_MM = 4;
    const FALLBACK_PAGE_BREAK_Y = 260.0;
    const FALLBACK_LEFT_MARGIN = 20.0;

    /**
     * @param object $pdf   Briefpapier/FPDF-Objekt
     * @param array  $items s. Klassenkommentar; fehlerhafte Items werden uebersprungen
     */
    public function render($pdf, array $items)
    {
        if (empty($items)) {
            return;
        }

        $blockHeight = self::BLOCK_TOP_MARGIN_MM + self::QR_SIZE_MM + self::LABEL_H_MM;
        $breakY = isset($pdf->PageBreakTrigger) ? (float)$pdf->PageBreakTrigger : self::FALLBACK_PAGE_BREAK_Y;
        if ($pdf->GetY() + $blockHeight > $breakY) {
            $pdf->AddPage();
        }

        $x = isset($pdf->lMargin) ? (float)$pdf->lMargin : self::FALLBACK_LEFT_MARGIN;
        $top = $pdf->GetY() + self::BLOCK_TOP_MARGIN_MM;
        $rendered = 0;

        foreach ($items as $item) {
            $tmpbase = '';
            $tmpfile = '';
            try {
                if (isset($item['png'])) {
                    // tempnam legt bereits eine endungslose Datei an - beide aufraeumen
                    $tmpbase = tempnam(sys_get_temp_dir(), 'payqr');
                    $tmpfile = $tmpbase . '.png';
                    if (file_put_contents($tmpfile, $item['png']) === false) {
                        continue;
                    }
                    $file = $tmpfile;
                } elseif (!empty($item['imagefile']) && is_file($item['imagefile'])) {
                    $file = $item['imagefile'];
                } else {
                    continue;
                }

                $pdf->Image($file, $x, $top, self::QR_SIZE_MM, self::QR_SIZE_MM, 'png');
                $pdf->SetFont($pdf->GetFont(), '', 8);
                $pdf->SetXY($x, $top + self::QR_SIZE_MM + 1);
                $pdf->MultiCell(self::QR_SIZE_MM + self::GAP_MM - 2, 3, (string)($item['label'] ?? ''));

                $x += self::QR_SIZE_MM + self::GAP_MM;
                $rendered++;
            } catch (\Throwable $e) {
                // einzelnes Item ueberspringen; PDF-Erzeugung nie gefaehrden
            } finally {
                if ($tmpfile !== '' && is_file($tmpfile)) {
                    @unlink($tmpfile);
                }
                if ($tmpbase !== '' && is_file($tmpbase)) {
                    @unlink($tmpbase);
                }
            }
        }

        if ($rendered > 0) {
            $pdf->SetY($top + $blockHeight - self::BLOCK_TOP_MARGIN_MM);
        }
    }
}
```

Hinweis Wero-Nicht-PNG: Ist die hochgeladene Wero-Datei ein JPG, muss der Typ-Parameter zum Dateiinhalt passen. Implementierung: Typ aus Dateiendung ableiten (`strtolower(pathinfo(...,PATHINFO_EXTENSION))`, jpg→'jpg', sonst 'png') — im Test 4 mitprüfen oder als bewusste Vereinfachung dokumentieren (Upload akzeptiert dann nur PNG, s. Task 6).

- [ ] **Step 3.4: Run — Expected: `ALLE TESTS OK`**

- [ ] **Step 3.5: Lint + Commit** (`feat(paymentqr): QR-Block-Renderer fuer Beleg-PDFs`)

---

### Task 4: Zahlungsweisen-Modulklassen (3 Dateien + Smoke-Test)

**Files:**
- Create: `OpenXE-git/www/lib/zahlungsweisen/rechnung_qr.php`
- Create: `OpenXE-git/www/lib/zahlungsweisen/paypal_qr.php`
- Create: `OpenXE-git/www/lib/zahlungsweisen/wero.php`
- Test: `OpenXE-git/classes/Modules/PaymentQr/tests/ZahlungsweisenStrukturTest.php`

**Vorab am Bestandscode verifizieren (Spec-Risiko #3/#5): ✅ ERLEDIGT 2026-07-01, Ergebnisse:**
- [x] **Step 4.1:** `class.zahlungsweise.php` verifiziert:
  - `Zahlungsweisenmodul` ist **abstract** mit `public abstract function ProcessPayment(array $transaction_block): array;` → `paypal_qr` und `wero` MÜSSEN ProcessPayment implementieren (No-Op: `['success' => false, 'successful_transactions' => [], 'errors' => [], 'payment_objects' => []]` — Struktur wie `Zahlungsweise_rechnung::ProcessPayment`, rechnung.php:306). `rechnung_qr` erbt die Implementierung vom Parent.
  - Unterstützte Formular-Typen: `text` (Default), `textarea`, `checkbox`, `select` (mit `optionen`), `submit`, `custom`. **Text-Inputs lesen `$val['size']` ohne isset-Guard** (class.zahlungsweise.php:126) → jedes `typ=text`-Feld MUSS `'size' => 40` definieren.
  - Optional pro Feld: `'info' => '<Hinweistext>'` wird kursiv hinter dem Feld gerendert — für VoP-Hinweis nutzen.
  - Konstruktor-Muster aus `rechnung.php:16-36` (lädt Row + einstellungen_json) für paypal_qr/wero übernehmen.

- [ ] **Step 4.2: Failing Smoke-Test schreiben** — lädt die 3 Dateien mit Stub-App, prüft: Klassen existieren, `EinstellungenStruktur()` enthält Eltern-Keys (bei rechnung_qr: `invoice_immediately` etc. aus dem Parent) UND die neuen `qr_*`-Keys:

```php
<?php
declare(strict_types=1);
// Smoke-Test: Struktur-Keys der drei Zahlungsweisen-Modulklassen.
// Benoetigt Stubs fuer Application/DB, da die Konstruktoren die DB lesen.

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

$root = getenv('OPENXE_CORE') ?: dirname(__DIR__, 3); // OPENXE_CORE ist PFLICHT und zeigt auf OpenXE-src (Fallback loest nur classes/ auf)
require_once $root . '/www/lib/zahlungsweisen/rechnung_qr.php';
require_once $root . '/www/lib/zahlungsweisen/paypal_qr.php';
require_once $root . '/www/lib/zahlungsweisen/wero.php';

$app = new StubApp();

$r = new Zahlungsweise_rechnung_qr($app, 1);
$s = $r->EinstellungenStruktur();
check('rechnung_qr erbt Eltern-Keys', array_key_exists('invoice_immediately', $s));
foreach (['qr_aktiv','qr_nur_bei_passender_zahlungsweise','qr_iban','qr_bic','qr_kontoinhaber','qr_verwendungszweck','qr_beschriftung'] as $k) {
  check("rechnung_qr Key $k", array_key_exists($k, $s));
}

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
```

Achtung: `rechnung_qr.php` zieht per require die Parent-Dateien aus dem **selben Repo-Baum** — die existieren in OpenXE-git nicht als Working-Tree-Dateien! Lösung im Test: vorher die zwei Core-Dateien aus OpenXE-src nach OpenXE-git kopieren? **NEIN** (würde sie ins Repo bringen). Stattdessen: Test setzt Konstante/Env `OPENXE_CORE=/pfad/zu/OpenXE-src` und unsere Modul-Dateien nutzen `require_once` mit relativem Pfad, der im echten Deployment stimmt — für den Test werden die Dateien stattdessen nach `OpenXE-src/www/lib/zahlungsweisen/` **kopiert** und dort geladen (OpenXE-src ist der vollständige Baum; Kopien dort sind Wegwerf-Testartefakte außerhalb des Repos). Der Test lädt also aus OpenXE-src:

```php
$root = getenv('OPENXE_CORE') ?: dirname(__DIR__, 3);
```
Runner-Aufruf: `cp feature-files/www/lib/zahlungsweisen/{rechnung_qr,paypal_qr,wero}.php OpenXE-src/www/lib/zahlungsweisen/ && OPENXE_CORE="$(pwd)/OpenXE-src" php feature-files/classes/Modules/PaymentQr/tests/ZahlungsweisenStrukturTest.php`

- [ ] **Step 4.3: Run — Expected: FAIL** (Dateien fehlen)

- [ ] **Step 4.4: Implementierung der drei Dateien**

`rechnung_qr.php`:
```php
<?php
require_once __DIR__.'/rechnung.php';

/**
 * Ueberweisung mit GiroCode (EPC-QR) auf dem Rechnungs-PDF.
 * Erbt die kompletten Text-/Skonto-Einstellungen der Zahlungsweise
 * "rechnung" und ergaenzt die QR-Felder. Das Rendering uebernimmt
 * das Modul zahlungsqr (Hook briefpapier_render_footer_hook2).
 */
class Zahlungsweise_rechnung_qr extends Zahlungsweise_rechnung
{
  public function EinstellungenStruktur()
  {
    $struktur = parent::EinstellungenStruktur();
    $struktur['qr_aktiv'] = [
      'bezeichnung' => 'GiroCode (EPC-QR) auf Rechnung anzeigen',
      'typ' => 'checkbox',
    ];
    $struktur['qr_nur_bei_passender_zahlungsweise'] = [
      'bezeichnung' => 'Nur anzeigen, wenn Zahlungsweise der Rechnung Ueberweisung ist (sonst auf allen Rechnungen)',
      'typ' => 'checkbox',
    ];
    $struktur['qr_iban'] = ['bezeichnung' => 'IBAN (Pflicht fuer GiroCode)', 'typ' => 'text', 'size' => 40];
    $struktur['qr_bic'] = ['bezeichnung' => 'BIC (optional)', 'typ' => 'text', 'size' => 40];
    $struktur['qr_kontoinhaber'] = [
      'bezeichnung' => 'Kontoinhaber',
      'typ' => 'text',
      'size' => 40,
      'info' => 'Muss exakt dem Namen beim Kontoinstitut entsprechen (Verification of Payee), sonst warnt die Banking-App des Kunden',
    ];
    $struktur['qr_verwendungszweck'] = [
      'bezeichnung' => 'Verwendungszweck-Vorlage (Platzhalter: {BELEGNR}, {KUNDENNUMMER}; leer = Belegnummer)',
      'typ' => 'text',
      'size' => 40,
    ];
    $struktur['qr_beschriftung'] = [
      'bezeichnung' => 'Beschriftung unter dem QR-Code (leer = "Mit Banking-App scannen & bezahlen")',
      'typ' => 'text',
      'size' => 40,
    ];
    return $struktur;
  }
}
```

`paypal_qr.php` (Konstruktor-Muster 1:1 aus `rechnung.php` übernehmen, Step 4.1):
```php
<?php
require_once dirname(__DIR__).'/class.zahlungsweise.php';

/**
 * PayPal-Zahlung per PayPal.me-QR auf dem Rechnungs-PDF.
 */
class Zahlungsweise_paypal_qr extends Zahlungsweisenmodul
{
  /** @var Application */
  var $app;
  /** @var array */
  protected $data;
  /** @var array (in der abstrakten Basisklasse nicht deklariert - dynamic property vermeiden) */
  public $einstellungen = array();

  public function __construct($app, $id)
  {
    $this->app = $app;
    $this->id = $id;
    $this->data = $this->app->DB->SelectRow(
      sprintf('SELECT * FROM zahlungsweisen WHERE id = %d', $id)
    );
    $einstellungen_json = $this->data['einstellungen_json'] ?? '';
    $decoded = !empty($einstellungen_json) ? json_decode($einstellungen_json, true) : null;
    $this->einstellungen = is_array($decoded) ? $decoded : array();
  }

  public function EinstellungenStruktur()
  {
    return [
      'qr_aktiv' => ['bezeichnung' => 'PayPal-QR auf Rechnung anzeigen', 'typ' => 'checkbox'],
      'qr_nur_bei_passender_zahlungsweise' => [
        'bezeichnung' => 'Nur anzeigen, wenn Zahlungsweise der Rechnung PayPal ist (sonst auf allen Rechnungen)',
        'typ' => 'checkbox',
      ],
      'paypalme_handle' => ['bezeichnung' => 'PayPal.me-Handle (paypal.me/<Handle>, Pflicht)', 'typ' => 'text', 'size' => 40],
      'qr_beschriftung' => ['bezeichnung' => 'Beschriftung unter dem QR-Code (leer = "Mit PayPal zahlen")', 'typ' => 'text', 'size' => 40],
    ];
  }

  // Pflicht: abstrakte Methode der Basisklasse; dieses Modul wickelt keine
  // Zahllaeufe ab (No-Op-Struktur wie Zahlungsweise_rechnung, rechnung.php:306)
  public function ProcessPayment(array $transaction_block): array
  {
    return [
      'success' => false,
      'successful_transactions' => [],
      'errors' => [],
      'payment_objects' => [],
    ];
  }
}
```

**WICHTIG (Review-Finding):** `Zahlungsweisenmodul` ist abstract mit `public abstract function ProcessPayment(array $transaction_block): array;` — `wero.php` braucht dieselbe No-Op-Implementierung und dieselbe `public $einstellungen = array();`-Deklaration wie `paypal_qr.php`. Alle `typ => 'text'`-Felder (auch in `rechnung_qr.php`!) MÜSSEN `'size' => 40` tragen (class.zahlungsweise.php:126 liest den Key ungeprüft).

`wero.php` (gleiches Muster; Struktur-Keys `qr_aktiv`, `qr_nur_bei_passender_zahlungsweise`, `qr_beschriftung` (Default-Hinweis "Mit Wero zahlen"), `qr_datei` mit Bezeichnung `'Datei-ID des hochgeladenen Wero-QR-Bildes (Upload auf der Modulseite ZahlungsQR)'`, typ `text`).

- [ ] **Step 4.5: Run Smoke-Test — Expected: `ALLE TESTS OK`**; Testartefakt-Kopien aus `OpenXE-src/www/lib/zahlungsweisen/` wieder löschen (`rm OpenXE-src/www/lib/zahlungsweisen/{rechnung_qr,paypal_qr,wero}.php`)
- [ ] **Step 4.6: Lint + Commit** (alle 4 Dateien; `feat(paymentqr): Zahlungsweisen-Module rechnung_qr, paypal_qr, wero`)

---

### Task 5: Modulseite `zahlungsqr.php` + Hook-Handler (TDD für die Gate-Logik)

**Files:**
- Create: `OpenXE-git/www/pages/zahlungsqr.php`
- Create: `OpenXE-git/www/pages/content/zahlungsqr_settings.tpl`
- Create: `OpenXE-git/classes/Modules/PaymentQr/Service/QrItemAssembler.php`
- Create: `OpenXE-git/classes/Modules/PaymentQr/tests/ZahlungsqrItemsTest.php`
- Modify: `OpenXE-git/classes/Modules/PaymentQr/Service/PaymentQrSettingsService.php` (nur falls Step 5.1 Abweichungen ergibt)
- Test: Gate-/Assembly-Logik liegt testbar im QrItemAssembler; Instanz-Verhalten wird in Task 8 abgenommen
- Commit (Step 5.8) umfasst ALLE vier neuen Dateien + ggf. den angepassten SettingsService

**Vorab am Bestandscode verifizieren (Spec-Risiken #1, #4, #8): TEILWEISE ERLEDIGT 2026-07-01:**
- [ ] **Step 5.1:**
  1. NOCH OFFEN: `OpenXE-src/www/lib/class.erpapi.php:11044ff` (RunHook) genau lesen: Bedingung `ModulVorhanden`, Instanziierung (`$intern`-Flag? Konstruktor-Signatur des Zielmoduls?), wie die Methode aufgerufen wird.
  2. NOCH OFFEN: `OpenXE-src/www/pages/lexwareoffice.php` als Vorlage lesen (falls in production nicht vorhanden: via `gh api` aus Branch feature/lexwareoffice-module holen); ebenso ein bestehendes einfaches Modul wie `www/pages/netzwerkdrucker o.ä.` als Zweitmuster.
  3. ✅ Tabelle `rechnung` (struktur.sql verifiziert): Bruttobetrag = **`soll`** decimal(18,2) (KEIN gesamtsumme!), `waehrung` varchar(255) DEFAULT 'EUR', `belegnr`, `status`, `zahlungsweise` varchar(255), `kundennummer` varchar(64) NULL, `projekt` **varchar(222)** (als int casten!).
  4. ✅ `Briefpapier` setzt `$this->id` und `$this->table` (class.briefpapier.php:728-729); `RechnungPDF` setzt zusätzlich `$this->doctypeid` (class.rechnung.php:64) und `$this->doctype='rechnung'` (Konstruktor). Gate: `$pdf->doctype === 'rechnung'`, ID: `(int)($pdf->id ?? $pdf->doctypeid ?? 0)`.
  5. NOCH OFFEN: Logging-API klären: `LogFile` wird im Bestand nur auskommentiert genutzt — tatsächlich vorhandene Methode auf `erpAPI` finden (z. B. via `grep -n "function .*[Ll]og" class.erpapi.php`) und diese verwenden; Fallback `error_log()`.

- [ ] **Step 5.2: Failing Test für die Item-Assembly** (Kernlogik als statisch testbare Methode — Signatur identisch mit dem Aufruf im Controller-Gerüst in Step 5.6: `QrItemAssembler::build(array $rechnung, array $configs, callable $pngFactory, callable $weroFileResolver, EpcQrPayloadBuilder $payloadBuilder, PaymentQrSettingsService $settingsService): array`):

Testfälle (gleicher standalone Stil wie Task 1-3, Datei `tests/ZahlungsqrItemsTest.php` — die Methode wird dafür in eine eigene kleine Klasse `classes/Modules/PaymentQr/Service/QrItemAssembler.php` gelegt, damit der Test nicht www/pages laden muss):
- Rechnung `zahlungsweise='rechnung'`, config rechnung_qr mit `qr_nur_bei_passender_zahlungsweise=1` → 1 GiroCode-Item mit korrektem Payload-Input (IBAN/Name/Betrag/aufgelöstem Zweck) und Default-Label
- config mit `qr_nur_bei_passender_zahlungsweise=1` aber `zahlungsweise='paypal'` → GiroCode-Item entfällt, PayPal-Item kommt
- `qr_nur_bei_passender_zahlungsweise` leer → Item kommt unabhängig von der Zahlungsweise
- Währung `!= EUR` (und leer ≠ EUR behandeln: leere Währung gilt als EUR, Standard in OpenXE — in Step 5.1.3 verifizieren!) → GiroCode+PayPal entfallen, Wero bleibt
- Betrag `<= 0` → GiroCode+PayPal entfallen
- fehlende IBAN in Config → GiroCode entfällt (kein Throw nach außen)
- PayPal-Link-Format: `https://paypal.me/<handle>/<betrag>EUR` mit `number_format($betrag, 2, '.', '')`
- Wero ohne aufgelöste Bilddatei → Item entfällt

- [ ] **Step 5.3: Run — FAIL** → **Step 5.4: `QrItemAssembler` implementieren** (pure Funktion: nimmt Rechnung-Row, Configs, `$pngFactory` = fn(payload)=>png-bytes für GiroCode/PayPal (kapselt BarcodeFactory, EC-Level 'M'), `$weroFileResolver` = fn(dateiId)=>pfad|null) → **Step 5.5: Run — OK**

- [ ] **Step 5.6: Controller `www/pages/zahlungsqr.php` implementieren** (Muster lexwareoffice.php; php -l als Mindestprüfung, Instanztest in Task 8):

Gerüst (Details nach Step 5.1 anpassen):
```php
<?php
use Xentral\Modules\PaymentQr\Service\EpcQrPayloadBuilder;
use Xentral\Modules\PaymentQr\Service\PaymentQrSettingsService;
use Xentral\Modules\PaymentQr\Service\QrBlockRenderer;
use Xentral\Modules\PaymentQr\Service\QrItemAssembler;

class Zahlungsqr
{
  /** @var Application */
  protected $app;

  const MODULE_NAME = 'PaymentQr';

  public function __construct($app, $intern = false)
  {
    $this->app = $app;
    if ($intern) { return; }
    $this->app->ActionHandlerInit($this);
    $this->app->ActionHandler('list', 'ZahlungsqrList');
    $this->app->ActionHandler('upload', 'ZahlungsqrUpload');
    $this->app->ActionHandler('uninstall', 'ZahlungsqrUninstall');
    $this->app->DefaultActionHandler('list');
    $this->app->ActionHandlerListen($app);
  }

  public function Install()
  {
    // 1. Hook-Stammsatz sicherstellen + registrieren
    $this->app->erp->GenerateHook('briefpapier_render_footer_hook2', 1, 1);
    $this->app->erp->RegisterHook('briefpapier_render_footer_hook2', 'zahlungsqr', 'RenderQrBlock');
    // 2. Menuepunkt (Bereich admin/Einstellungen; exakte first/sec-Werte aus
    //    bestehenden Eintraegen der Tabelle hook_navigation ableiten)
    $this->app->erp->RegisterNavigationHook('zahlungsqr', 'list', 'admin', 'zahlungsqr');
    // 3. Zahlungsweisen-Eintraege idempotent anlegen/umstellen (Spec 4.3):
    //    - type='rechnung': falls vorhanden -> modul='rechnung_qr'; sonst anlegen (aktiv=0)
    //    - type='paypal':   anlegen falls fehlt (modul='paypal_qr', aktiv=0)
    //    - type='wero':     anlegen falls fehlt (modul='wero', aktiv=0)
  }

  public function CheckRights()
  {
    return $this->app->User->GetType() === 'admin';
  }

  /**
   * Hook-Handler: briefpapier_render_footer_hook2.
   * Wird fuer JEDES Beleg-PDF aufgerufen - alle Gates hier.
   * Darf unter keinen Umstaenden eine Exception nach aussen lassen.
   */
  public function RenderQrBlock($pdf)
  {
    try {
      if (!is_object($pdf) || ($pdf->doctype ?? '') !== 'rechnung') { return; }
      $id = (int)($pdf->id ?? 0);              // Property-Name aus Step 5.1.4
      if ($id <= 0) { return; }

      $rechnung = $this->app->DB->SelectRow(
        "SELECT belegnr, zahlungsweise, soll, waehrung, projekt, kundennummer
         FROM rechnung WHERE id = $id"          // Bruttobetrag = soll (verifiziert Step 5.1.3)
      );
      if (empty($rechnung) || empty($rechnung['belegnr'])) { return; }

      $settingsService = new PaymentQrSettingsService($this->app->DB);
      $configs = $settingsService->getActiveQrConfigs((int)$rechnung['projekt']);
      if (empty($configs)) { return; }

      $barcodeFactory = $this->app->Container->get('BarcodeFactory');
      $payloadBuilder = new EpcQrPayloadBuilder();
      $items = QrItemAssembler::build(
        $rechnung,
        $configs,
        function ($payload) use ($barcodeFactory) {
          return $barcodeFactory->createQrCode($payload, 'M')->toPng(300, 300);
        },
        function ($dateiId) {
          return $this->WeroImagePath((int)$dateiId); // nutzt Datei-System, Step 5.1
        },
        $payloadBuilder,
        $settingsService
      );
      if (!empty($items)) {
        (new QrBlockRenderer())->render($pdf, $items);
      }
    } catch (\Throwable $e) {
      @error_log('zahlungsqr RenderQrBlock: ' . $e->getMessage()); // durch erpAPI-Log ersetzen, Step 5.1.5
    }
  }

  // ZahlungsqrList: Statusuebersicht (welche Zahlungsart aktiv/konfiguriert,
  //   fehlende Pflichtfelder als Warnung), Buttons Install/Uninstall,
  //   Upload-Formular fuer Wero-Bild -> Template zahlungsqr_settings.tpl
  // ZahlungsqrUpload: PNG entgegennehmen (nur image/png, max 2 MB),
  //   via CreateDatei (erpapi:36940) ablegen, Datei-ID in einstellungen_json
  //   des wero-Eintrags schreiben (UPDATE nur dieses Keys via json_decode/encode!)
  // ZahlungsqrUninstall: hook_register- und hook_navigation-Eintraege entfernen,
  //   zahlungsweisen wero/paypal deaktivieren, rechnung.modul zurueck auf 'rechnung'
}
```

Wichtig bei `ZahlungsqrUpload`: `einstellungen_json` des Wero-Eintrags per read-modify-write ändern (bestehende Keys erhalten) — NICHT das Struktur-Formular-Muster verwenden.

- [ ] **Step 5.7: Template `zahlungsqr_settings.tpl`** — minimal, Muster `lexwareoffice_settings.tpl` (Tabellenlayout, `{...}`-Platzhalter des TemplateParsers).
- [ ] **Step 5.8: Lint aller Dateien + Commit** (`feat(paymentqr): Modulseite zahlungsqr mit Install und PDF-Hook-Handler`)

---

### Task 6: Bootstrap + Verdrahtung + Gesamt-Lint

**Files:**
- Create: `OpenXE-git/classes/Modules/PaymentQr/Bootstrap.php`
- Create: `OpenXE-git/classes/Modules/PaymentQr/README.md`

- [ ] **Step 6.1:** `OpenXE-src/classes/Components/Barcode/Bootstrap.php` und `classes/Modules/LexwareOffice/Bootstrap.php` (Branch feature/lexwareoffice-module, notfalls via `gh api` den Datei-Inhalt holen) als Muster lesen; klären, ob Bootstrap-Registrierung nötig ist, wenn der Controller Services direkt instanziiert (`new EpcQrPayloadBuilder()`) — **YAGNI: Bootstrap nur anlegen, wenn der Autoloader die Namespace-Klassen sonst nicht findet.** PSR-4-Mapping von `classes/` prüfen (`Psr4ClassNameResolver`, cache_services): Läuft `Xentral\Modules\PaymentQr\Service\...` ohne Registrierung? Test: kleines PHP-Script, das nur den xentral_autoloader lädt und die Klasse instanziiert (gegen OpenXE-src + kopierte Dateien).
- [ ] **Step 6.2:** README.md schreiben: Zweck, Installation (Modul-URL `index.php?module=zahlungsqr&action=list` → Install-Button; führt Hook+Navigation+Zahlungsweisen-Anlage aus), Konfiguration (native Zahlungsarten-Einstellungen + Wero-Upload), Deinstallation, bekannte Grenzen (PayPal-Betrag änderbar, Wero statisch, nur EUR, **Wero-Upload akzeptiert nur PNG** — bewusste Vereinfachung ggü. Spec §4.2, dort dokumentieren).
- [ ] **Step 6.3:** Gesamt-Lint: `find`-Schleife `php -l` über alle neuen Dateien; alle 5 Test-Scripts laufen lassen (EpcQrPayloadBuilder, SettingsService, QrBlockRenderer, ZahlungsweisenStruktur, ZahlungsqrItems). Expected: alles grün.
- [ ] **Step 6.4: Commit** (`feat(paymentqr): Bootstrap/Autoload-Verdrahtung und README`)

---

### Task 7: Trailing-Whitespace-Cleanup + Review-Runden

- [ ] **Step 7.1:** `grep -rn ' $' OpenXE-git/classes/Modules/PaymentQr OpenXE-git/www/lib/zahlungsweisen/rechnung_qr.php OpenXE-git/www/lib/zahlungsweisen/paypal_qr.php OpenXE-git/www/lib/zahlungsweisen/wero.php OpenXE-git/www/pages/zahlungsqr.php` → Treffer bereinigen (Projektstandard: Subagenten erzeugen Trailing Whitespace).
- [ ] **Step 7.2:** Zwei parallele Review-Subagenten (general-purpose) dispatchen: (a) Korrektheit/Sicherheit (SQL-Injection: alle Query-Parameter gecastet/escaped? XSS im Template? Upload-Validierung?), (b) Integration (Hook-Signatur, FPDF-API-Nutzung gegen `OpenXE-src`-Quellcode, EPC-Payload gegen Spec Abschnitt 5.1). Funde verifizieren (False-Positive-Quote beachten), fixen, Tests erneut laufen lassen.
- [ ] **Step 7.3:** Commit der Review-Fixes (`fix(paymentqr): Review-Findings`), Push.

---

### Task 8: Abnahme auf der Instanz (Nutzer) + Abschluss

- [ ] **Step 8.1:** Abnahme-Anleitung an den Nutzer (im Chat): Branch `feature/payment-qr-codes` auf Test-Instanz deployen; `index.php?module=zahlungsqr&action=list` aufrufen (löst Install aus); Zahlungsart Überweisung konfigurieren (IBAN/Kontoinhaber), Test-Rechnung als PDF erzeugen; **GiroCode mit echter Banking-App scannen** (Empfänger/Betrag/Zweck korrekt?); PayPal-QR mit Handy-Kamera; Wero-Bild hochladen, Ausdruck mit Wero-App testen; Regression: Angebot/Auftrag/Lieferschein ohne QR-Block, Rechnung mit deaktivierten QRs unverändert.
- [ ] **Step 8.2:** Gefundene Probleme fixen (Diagnose-vor-Fix-Regel), Tests ergänzen.
- [ ] **Step 8.3:** superpowers:finishing-a-development-branch invoken (Merge/PR-Entscheidung beim Nutzer).

---

## Offene Punkte für die Ausführung

1. **Instanz:** Hat der Nutzer eine laufende OpenXE-Test-Instanz (Docker/Server) für Task 8? Vor Task 8 klären — für Task 0-7 nicht nötig.
2. **`gh`-Fallback:** Nur nutzen, wenn Step 0.1/0.3 scheitert; dann pro Task ein Contents-API-Commit pro Datei (Reihenfolge egal, Branch existiert nach Ref-Erzeugung).
3. Alle in Spec Abschnitt 9 gelisteten Verifikationspunkte sind in Steps 4.1, 5.1 und 6.1 abgedeckt.
