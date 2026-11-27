# WooCommerce-Shopzuordnung: Lagerbestand-Spalte Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Spalte "Lagerbestand" in `Artikel -> WooCommerce` mit Inventur-Korrektur (Zielbestand) inline bzw. per Popup bei mehreren Lagerplaetzen.

**Architecture:** Neue Klasse `StockCorrection` kapselt Lesen (Plaetze/Summen, eine Query fuer alle Seitenzeilen) und Buchen (Differenz via `erpAPI::LagerEinlagern`/`LagerAuslagernRegal`, ausserhalb der `Database`-Transaktion, da `MysqliAdapter` eine eigene Verbindung oeffnet). `AssignmentPage` rendert die Zelle (inkl. `<template>` fuers Popup) und dispatcht Kommando `stock_set`; JS tauscht nach Erfolg die Zelle gegen die vom Server gerenderte `cell`.

**Tech Stack:** PHP 8.1+, OpenXE `erpAPI`, `Xentral\Components\Database`, jQuery, natives `<dialog>`, Test-Runner `tests/woocommerce/*.php` (eigenes `expect()`).

**Spec:** `docs/superpowers/specs/2026-11-27-woocommerce-lagerbestand-design.md`

## Global Constraints
- Lagerbewegungs-Grund exakt: `Inventur-Korrektur (WooCommerce-Uebersicht)`.
- Summe = `SUM(lager_platz_inhalt.menge)` ueber Plaetze mit `lager_platz.sperrlager != 1` (wie `ArtikelAnzahlLager`).
- Buchen nur via `$app->erp->LagerEinlagern($artikel, $menge, $regal, $projekt, $grund)` und `$app->erp->LagerAuslagernRegal($artikel, $regal, $menge, $projekt, $grund)`; nie direkt in `lager_platz_inhalt`/`lager_bewegung` schreiben.
- Lagerbuchung NICHT in `AssignmentPage::transactional()`.
- Editierrecht: `artikel/edit` + `onlineshops/edit` (bestehend) + `lager/bucheneinlagern` + `lager/buchenauslagern` + `UserProjektRecht(artikel.projekt)`.
- Nicht editierbar: `lagerartikel != 1` (Anzeige `&ndash;`); `chargenverwaltung > 0` oder `mindesthaltbarkeitsdatum = 1` oder `seriennummern NOT IN ('', 'keine')` (Zahl + `Korrektur im Lager-Modul`). Server lehnt diese auch bei direktem POST ab.
- Mengen: DECIMAL(14,4) kommt als String; Ziel >= 0, max. 4 Nachkommastellen, Komma oder Punkt; Vergleiche nach `round(..., 4)`.
- Anzeige-Format: Komma-Dezimal, nachlaufende Nullen weg, kein Tausendertrenner (`"12.5000"` -> `12,5`, `"3.0000"` -> `3`).
- Fehlertexte ASCII (`ae/oe/ue`), wie bestehende `DomainException`-Texte; HTML-Texte mit Entities.
- `php -l` auf jede geaenderte PHP-Datei; `node --check` auf das JS; Umlaut-Grep auf geaenderte Dateien.
- Baseline: `php tests/woocommerce/run.php` -> `Suites failed: 0` (25 Suites) muss gruen bleiben.

## Review Focus
- Eingabe `2,5` (Komma) muss als 2.5 gebucht werden, nicht als Fehler/2.
- Negativer Platzbestand (`"-2.0000"`) mit Ziel 0 -> Einlagerung 2, kein Auslagern-Fehler.
- Bestand zwischen Seitenaufruf und Buchen geaendert (anderer User/Lieferschein) -> Abbruch ohne jede Buchung.
- Standard-Lagerplatz ist Sperrlager oder existiert nicht -> Fehlermeldung, keine Buchung.
- Chargen/MHD/SN-Artikel per manipuliertem POST -> Server-Ablehnung.

---

### Task 1: `StockCorrection` (Lesen + Buchen)

**Files:**
- Create: `classes/Components/WooCommerce/StockCorrection.php`
- Create: `tests/woocommerce/stock_correction.php` (eigene Fakes: DB mit `public array $stock`, `public array $article`, `public array $places`; erp-Fake mit `public array $bookings`, `public ?string $failOn` (`'ein'|'aus'`), mutiert `$db->stock`)
- Modify: `tests/woocommerce/run.php` (Suite `stock_correction` nach `assignment` eintragen)

**Interfaces:**
- Produces:
  - `final class StockCorrection { public const REASON = 'Inventur-Korrektur (WooCommerce-Uebersicht)'; public function __construct(object $db, object $erp) }`
  - `public function places(array $articleIds): array` -> `array<int articleId, list<array{id:int, name:string, lager:string, menge:string}>>`; eine Query: `lager_platz_inhalt` JOIN `lager_platz` (sperrlager != 1) LEFT JOIN `lager`, `GROUP BY lpi.artikel, lp.id HAVING SUM(lpi.menge) <> 0`, sortiert nach `lp.kurzbezeichnung`. Leere ID-Liste -> `[]` ohne Query.
  - `public static function total(array $places): string` -> Summe als `number_format(..., 4, '.', '')`.
  - `public static function format(string $menge): string` -> Anzeigeformat (Global Constraints).
  - `public static function isTracked(array $article): bool` (Chargen/MHD/SN-Regel).
  - `public function correct(int $articleId, array $counts): array` mit `$counts = list<array{place:int, expected:string, target:string}>` (`place = 0` = Standard-Lagerplatz, nur erlaubt wenn Artikel keine Plaetze hat); Rueckgabe `array{places: list<...>}` (frisch geladen). Wirft `DomainException` bei jedem Validierungsfehler VOR der ersten Buchung.
  - `final class StockCorrectionPartialFailure extends DomainException` (in derselben Datei) — Meldung `Teilweise gebucht (<Platz-Kurzbezeichnungen>). Fehler bei <Platz>: <msg>. Seite neu laden.`

- [ ] **Step 1: Failing tests schreiben** (`tests/woocommerce/stock_correction.php`, Muster wie `assignment.php`: `$tests`-Array, `expect()`, Exit-Code)
  - `places_sums_per_article_excluding_blocked_places`: stock `[9,p1,'5.0000'],[9,p1,'2.0000'],[9,p2(sperrlager),'4.0000'],[10,p3,'1.0000']` -> `places([9,10])[9] === [['id'=>1,'name'=>'A-01','lager'=>'Haupt','menge'=>'7.0000']]`, `total(...) === '7.0000'`.
  - `format_trims_zeros_and_uses_comma`: `format('12.5000') === '12,5'`, `format('3.0000') === '3'`, `format('-2.0000') === '-2'`.
  - `tracked_detection`: `isTracked(['chargenverwaltung'=>'1',...])`, `mindesthaltbarkeitsdatum '1'`, `seriennummern 'eigene'` -> true; `seriennummern 'keine'`/`''` + Rest `'0'` -> false.
  - `single_place_writeoff_books_auslagern_with_reason`: Platz 1 `'7.0000'`, counts `[[1,'7.0000','5']]` -> `bookings === [['aus', 9, 1, 2.0, 4, StockCorrection::REASON]]` (Format `[typ, artikel, platz, menge, projekt, grund]`).
  - `comma_target_is_accepted`: counts target `'2,5'` bei `'3.0000'` -> eine `aus`-Buchung mit `0.5`.
  - `negative_place_to_zero_books_einlagern`: `'-2.0000'` -> target `'0'` -> `['ein', 9, 1, 2.0, 4, REASON]`.
  - `no_places_uses_standard_storage`: keine Plaetze, `article.lager_platz='3'`, Platz 3 existiert, nicht Sperrlager; counts `[[0,'0','4']]` -> `['ein', 9, 3, 4.0, ...]`.
  - `missing_or_blocked_standard_storage_is_rejected`: `lager_platz=''` bzw. Platz 3 `sperrlager=1` -> DomainException `Kein gueltiger Standard-Lagerplatz am Artikel hinterlegt.`, `bookings === []`.
  - `stale_expected_aborts_before_any_booking`: Plaetze 1 (`'5.0000'`), 2 (`'3.0000'`); counts `[[1,'5.0000','4'],[2,'2.0000','1']]` -> DomainException `Bestand wurde zwischenzeitlich geaendert. Seite neu laden.`, `bookings === []`.
  - `unknown_place_and_place_zero_with_places_are_rejected`: Platz 99 bzw. place 0 obwohl Plaetze existieren -> DomainException, keine Buchung.
  - `invalid_targets_are_rejected`: `'-1'`, `'abc'`, `'1.23456'`, `''` -> DomainException `Bitte eine Menge >= 0 mit hoechstens 4 Nachkommastellen eingeben.`.
  - `tracked_or_non_stock_article_is_rejected`: `lagerartikel '0'` -> `Artikel wird nicht lagergefuehrt.`; tracked -> `Chargen-, MHD- oder Seriennummernartikel bitte im Lager-Modul korrigieren.`.
  - `unchanged_counts_book_nothing`: target == expected (`'5'` vs `'5.0000'`) -> `bookings === []`, Rueckgabe enthaelt `places`.
  - `partial_failure_reports_booked_places`: 2 Plaetze, erp-Fake wirft beim 2. (`failOn`) `Exception('Auslagern fehlgeschlagen! (Bestand zu gering)')` -> `StockCorrectionPartialFailure`, Meldung enthaelt `A-01` und `Bestand zu gering`; erste Buchung bleibt in `bookings`.
  - `first_booking_failure_is_plain_domain_error`: Fehler bei erster Buchung -> `DomainException` (nicht Partial) mit Original-Meldung.
- [ ] **Step 2: Laufen lassen, muss scheitern**
  Run: `php tests/woocommerce/stock_correction.php` — Expected: FAIL (`StockCorrection` fehlt)
- [ ] **Step 3: `StockCorrection` implementieren**
  `correct()`: Artikel via `fetchRow('SELECT id, projekt, lagerartikel, chargenverwaltung, mindesthaltbarkeitsdatum, seriennummern, lager_platz FROM artikel WHERE id = :article AND geloescht = 0', ...)`; aktuelle Plaetze via `places()`; alle Counts validieren + Diffs berechnen (`round((float)$target - (float)$current, 4)`, `abs < 0.00005` -> skip); Standardplatz: `fetchRow('SELECT id, kurzbezeichnung FROM lager_platz WHERE id = :id AND sperrlager != 1 AND geloescht != 1', ...)`; dann buchen (Diff > 0 ein, < 0 aus mit `-diff`), Fehler ab 2. Buchung -> Partial.
- [ ] **Step 4: Tests gruen**
  Run: `php tests/woocommerce/stock_correction.php` — Expected: `.../15 passed`, Exit 0; `php -l classes/Components/WooCommerce/StockCorrection.php` -> `No syntax errors`
- [ ] **Step 5: Commit**
  `git add classes/Components/WooCommerce/StockCorrection.php tests/woocommerce/stock_correction.php tests/woocommerce/run.php` · `git commit -F <msgfile>` mit `feat(woocommerce): add stock correction service for assignment page`

### Task 2: Spalte rendern (`AssignmentPage`)

**Files:**
- Modify: `classes/Components/WooCommerce/AssignmentPage.php` (`articles()` SELECT ~Z.230, `articleTable()` ~Z.243-307, neue private Methoden)
- Modify: `tests/woocommerce/fixtures/assignment.php` (DB: `public array $stock = []`, `fetchAll` fuer `FROM lager_platz_inhalt`, `fetchRow` fuer `FROM lager_platz `; Artikel-Default um `lagerartikel=>'1', chargenverwaltung=>'0', mindesthaltbarkeitsdatum=>'0', seriennummern=>'keine', lager_platz=>''`; erp-Fake: `RechteVorhanden` zusaetzlich `in_array("$module/$action", $this->rights)`, Default-Rechte inkl. `lager/bucheneinlagern`, `lager/buchenauslagern`; `LagerEinlagern`/`LagerAuslagernRegal` protokollieren in `public array $bookings` und mutieren `$db->stock`)
- Test: `tests/woocommerce/assignment.php`

**Interfaces:**
- Consumes: `StockCorrection::places/total/format/isTracked` (Task 1)
- Produces: `private function stockCell(array $row, array $places, bool $canBook): string` -> komplettes `<td class="wc-col-stock">...</td>`; `private function canBookStock(): bool`. Markup-Vertrag fuer Task 4:
  - Inline: `<form class="wc-stock-inline" data-command="stock_set"><input name="target" inputmode="decimal" data-place="{id|0}" data-expected="{menge roh|0}" value="{format}" aria-label="Lagerbestand ({nr})"><button type="submit" class="wc-btn wc-btn-secondary wc-btn-sm">Buchen</button></form>`
  - Mehrere Plaetze: `<span class="wc-stock-total">{format(total)}</span> <button type="button" class="wc-btn wc-btn-secondary wc-btn-sm" data-stock-dialog>Korrigieren</button><template class="wc-stock-template"><form class="wc-stock-places" data-command="stock_set"><h3>Lagerbestand {nr}</h3><table>` je Platz `<tr><th>{name}</th><td>{lager}</td><td>{format(menge)}</td><td><input name="target" inputmode="decimal" data-place="{id}" data-expected="{menge}" value="{format(menge)}" aria-label="Ist-Menge {name}"></td></tr>` `</table><button type="submit" class="wc-btn wc-btn-primary">Buchen</button> <button type="button" class="wc-btn wc-btn-secondary" data-stock-close>Abbrechen</button></form></template>`
  - Spaltenkopf `<th scope="col" class="wc-col-stock">Lagerbestand</th>` direkt nach `Name`.

- [ ] **Step 1: Failing tests** in `assignment.php`
  - `stock_column_shows_inline_form_for_single_place`: stock 1 Platz `'7.0000'` -> HTML enthaelt `>Lagerbestand</th>`, `data-command="stock_set"`, `data-place="1" data-expected="7.0000" value="7"`.
  - `stock_column_without_places_offers_standard_storage_input`: -> `data-place="0" data-expected="0" value="0"`.
  - `stock_column_opens_dialog_for_multiple_places`: 2 Plaetze -> `data-stock-dialog`, `<template class="wc-stock-template">`, beide `data-place`.
  - `stock_column_is_read_only_for_tracked_non_stock_and_missing_rights`: `seriennummern='eigene'` -> `Korrektur im Lager-Modul`, kein `stock_set`; `lagerartikel='0'` -> `&ndash;`; Rechte ohne `lager/buchenauslagern` -> Zahl, kein `stock_set`.
  - `stock_query_runs_once_per_page`: `catalog` mit 3 Artikeln -> Fixture-Zaehler fuer `FROM lager_platz_inhalt` === 1.
- [ ] **Step 2: Laufen lassen, muss scheitern** — `php tests/woocommerce/assignment.php` -> neue Tests FAIL, alte 17 PASS
- [ ] **Step 3: Implementieren** — SELECT in `articles()` um `a.projekt, a.lagerartikel, a.chargenverwaltung, a.mindesthaltbarkeitsdatum, a.seriennummern` erweitern; in `articleTable()` einmal `places(array_column ids)` holen, `canBookStock()` einmal auswerten, Zelle via `stockCell()`; editierbar nur wenn `canBook && UserProjektRecht((int)$row['projekt'])`.
- [ ] **Step 4: Gruen** — `php tests/woocommerce/assignment.php` -> `22/22 passed`; `php -l` beide PHP-Dateien
- [ ] **Step 5: Commit** — `feat(woocommerce): show stock column in shop assignment table`

### Task 3: Kommando `stock_set`

**Files:**
- Modify: `classes/Components/WooCommerce/AssignmentPage.php` (`handle()` ~Z.311: Zweig VOR `transactional()`), neue `private function parseCounts(mixed $raw): array`
- Modify: `www/pages/artikel.php:6930` (POST-Key-Liste um `'counts'` erweitern)
- Test: `tests/woocommerce/assignment.php`

**Interfaces:**
- Consumes: `StockCorrection::correct()`, `StockCorrectionPartialFailure` (Task 1), `stockCell()` (Task 2)
- Produces: Request `cmd=stock_set`, POST `article`, `counts` = `place:expected:target` getrennt durch `;` (Regex je Eintrag `/^(\d+):(-?\d+(?:\.\d+)?):([0-9]+(?:[.,][0-9]+)?)$/`, max. 50 Eintraege). Response Erfolg `{ok:true, error:null, article:int, cell:string}`; Fehler `{ok:false, error:string}`.

- [ ] **Step 1: Failing tests**
  - `stock_set_books_difference_and_returns_new_cell`: Platz 1 `'7.0000'`, `counts='1:7.0000:5'` -> `ok === true`, `bookings === [['aus', 9, 1, 2.0, 4, StockCorrection::REASON]]`, `cell` enthaelt `value="5"`.
  - `stock_set_requires_token_and_lager_rights`: falscher Token -> `ok false`; ohne `lager/bucheneinlagern` -> `ok false`, `error === 'Keine Berechtigung fuer Lagerbuchungen.'`, `bookings === []`.
  - `stock_set_respects_article_project_right`: `erp->projects = [3]` (Artikelprojekt 4) -> `ok false`, keine Buchung.
  - `stock_set_rejects_malformed_counts`: `'1:7:abc'`, `''`, `'1:7'` -> `ok false`, `error === 'Ungueltige Eingabe.'`.
  - `stock_set_does_not_open_a_database_transaction`: Fixture zaehlt `beginTransaction()` -> 0 waehrend `stock_set`.
  - `stock_set_reports_partial_failure_text`: erp-Fake wirft beim 2. Platz -> `error` beginnt mit `Teilweise gebucht`.
- [ ] **Step 2: Scheitern** — `php tests/woocommerce/assignment.php` -> neue FAIL
- [ ] **Step 3: Implementieren** — in `handle()` nach Token/Shop-Pruefung: `if ($cmd === 'stock_set') { return ['ok' => true, 'error' => null] + $this->setStock($post); }`; `setStock()` prueft `canBookStock()` + Artikel-Projektrecht, ruft `correct()`, rendert `cell` mit frischem Artikel-Row (`fetchRow` inkl. Lager-Flags) und zurueckgegebenen `places`. `StockCorrectionPartialFailure`/`DomainException` laufen ueber bestehendes `errorMessage()`.
- [ ] **Step 4: Gruen** — `php tests/woocommerce/assignment.php` -> `28/28 passed`; `php -l` auf `AssignmentPage.php`, `www/pages/artikel.php`
- [ ] **Step 5: Commit** — `feat(woocommerce): book inventory corrections from assignment table`

### Task 4: Frontend (JS + CSS)

**Files:**
- Modify: `classes/Modules/Onlineshop/www/js/woocommerce-assignment.js`
- Modify: `classes/Modules/Onlineshop/www/css/woocommerce-admin.css`

**Interfaces:**
- Consumes: Markup-Vertrag (Task 2), Request/Response (Task 3)

- [ ] **Step 1: JS**
  - Generischen Submit-Handler auf `form[data-command]:not([data-command="stock_set"])` einschraenken.
  - Ein `<dialog class="wc-stock-dialog">` beim Init an `$page` anhaengen; Klick `[data-stock-dialog]` -> Template-Inhalt klonen, Artikel-ID als `data-article` auf das Formular, `showModal()`; `[data-stock-close]` -> `close()`.
  - Submit `form[data-command="stock_set"]`: `counts` aus `input[data-place]` bauen (`place + ':' + expected + ':' + val.trim()`), `;`-getrennt; `save($form, {article, counts})`.
  - In `save().done`: Zweig `cmd === 'stock_set'` VOR den bestehenden Zweigen -> `td.wc-col-stock` der Zeile `[data-article=ID]` durch `response.cell` ersetzen, Dialog schliessen, Status `Gespeichert.`; kein Reload.
- [ ] **Step 2: CSS** — `.wc-col-stock` (rechtsbuendig, nowrap), `.wc-stock-inline input` (Breite ~6em), `.wc-stock-dialog` + `::backdrop`, Tabelle im Dialog; vorhandene Farb-Variablen/Dark-Theme-Klassen der Datei wiederverwenden.
- [ ] **Step 3: Verify** — `node --check classes/Modules/Onlineshop/www/js/woocommerce-assignment.js` (kein Output = OK); `php tests/woocommerce/run.php` -> `Suites failed: 0` (26 Suites); Umlaut-Grep auf alle geaenderten Dateien leer.
- [ ] **Step 4: Manuelle Pruefung auf Testsystem** (User): Artikel mit 1 Platz abschlagen, Artikel mit 2 Plaetzen per Popup, Artikel ohne Platz + Standardplatz, SN-Artikel read-only; Lagerbewegung mit Grund im Artikel-Lager-Tab sichtbar.
- [ ] **Step 5: Commit** — `feat(woocommerce): inline and dialog stock editing in assignment UI`
