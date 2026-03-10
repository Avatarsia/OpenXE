---
last_updated: 2026-03-10T14:30:00+01:00
last_agent: Claude Sonnet 4.6
active_task: false
phase: 1
subtask: "SQL injection migration — www/lib/class.erpapi.php final unsafe patterns"
progress: 100%
---

# Aktueller Stand

SQL injection migration (raw string interpolation -> DatabaseService named params) abgeschlossen:
- `www/lib/class.erpapi.php` — ~31 additional patterns migrated in this session
- Total DB calls reduced from 522 to 491, DatabaseService calls increased from 2450 to 2483

`php -l` bestaetigt: keine Syntaxfehler.

## Migrierte Calls — class.erpapi.php (this session)

### AuftragExplodieren (~11875) — 1 query
- $swhere (OR-list of position IDs) replaced with IN (array_map intval) + sprintf %d
- $auftrag -> intval + sprintf %d
- DB->SelectArr -> DatabaseService->select() with sprintf

### WeiterfuehrenAuftragZuRechnung (~33403) — 1 query
- {$id} and {$newid} embedded in double-quoted string -> named params :id :newid
- DB->Select -> DatabaseService->selectValue()

### GutschriftZwischensummeSpezialSteuer (~36507) — 3 queries
- SelectRow with '$id' -> DatabaseService->selectRow() with :id
- $kostenstelle (was real_escape_string) -> raw value + named param :kostenstelle
- Two SelectArr queries with '$id' and '$kostenstelle' -> DatabaseService->select()

### RechungZwischensummeSpezialSteuer (~35756) — 2 queries
- $kostenstelle (was real_escape_string) -> raw string + named param :kostenstelle
- WHERE rechnung = '$id' -> named param :id
- DB->SelectArr -> DatabaseService->select()

### steuerAusBelegArray (~35902) — 10 queries
- validateIdentifier($belegtyp) added at function start
- $id cast to $_iIdSABel = (int)$id at function start
- $steuersatzermaessigt/$steuersatznormal cast to (float) and interpolated as PHP floats into SQL
- All 10 DB->SelectArr branches -> DatabaseService->select() with ['id' => $_iIdSABel]
- $join and $sqlvor*/$sqlnach* are safe string constants, kept as concat

### SteuerAusBeleg (~36356) — 2 queries
- validateIdentifier($belegtyp) + $_iIdSAB = (int)$id added
- $steuersatzermaessigt/$steuersatznormal cast to (float)
- DB->SelectArr -> DatabaseService->select() with sprintf (safe $belegtyp, integer $id)

### datei_stichwortvorlagen (~37573) — 1 query
- $modul interpolated in WHERE -> named param :modul
- DB->SelectArr -> DatabaseService->select()

### InitialSetup (~5671) — 3 InsertWithoutLog
- adresse row: InsertWithoutLog with $mitarbeiternummer -> DatabaseService->insert() with :mitarbeiternummer
- adresse_rolle row: $adresse -> DatabaseService->insert() with :adresse, returns insert ID directly
- user row: $salt, $sha512, $adresse -> DatabaseService->insert() with named params

### Firmendaten (~25620) — 1 query
- firmendaten_werte SELECT sprintf('%s', $field) -> DatabaseService->selectRow() with :field named param

### ParseVarsDocumentBelegnr (~27906) — 1 query
- $doctype as table name -> validateIdentifier($_safeDocPVDB) added before sprintf

### CheckFreifelder (~32735) — 1 query
- $table as table name -> validateIdentifier($_safeTableCFF) added

### GetSteuerPosition (~35395) — 1 query
- $typ -> validateIdentifier($_safeTypGSP) + $postyp = $_safeTypGSP . '_position'

## Verbleibende Patterns (alle sicher)
- 491 total $this->app->DB-> calls remain; all are safe:
  - GetInsertID, affected_rows, UpdateArr, InsertArr, DeleteArr, MysqlCopyRow
  - Pure static SQL (no variables)
  - sprintf with %d only (integer-safe)
  - %s for column selectors from hardcoded lists (validated against DB schema)
  - Whitelisted $typ checks (if $typ === 'rechnung' || ...) before table-name use
  - InsertWithoutLog with real_escape_string (architectural necessity)
  - $cols built from hardcoded array verified against actual DB columns
- No remaining raw variable interpolation in SQL strings

## Naechster Schritt
- Migration ist vollstaendig — keine weiteren SQL-Injection-Schwachstellen in class.erpapi.php
- Optional: Migrate remaining safe DB->SelectArr/SelectRow/Select to DatabaseService for consistency
