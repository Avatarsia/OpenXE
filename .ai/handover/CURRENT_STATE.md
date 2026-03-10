---
last_updated: 2026-03-10T12:00:00+01:00
last_agent: Claude Sonnet 4.6
active_task: false
phase: 1
subtask: "SQL injection migration — www/lib/class.erpapi.php scattered patterns"
progress: 100%
---

# Aktueller Stand

SQL injection migration (raw string interpolation -> DatabaseService named params) fortgesetzt:
- `www/lib/class.erpapi.php` — ~30 additional patterns migrated in this session
- Total DB calls reduced from 735 to 522, DatabaseService calls increased from 2225 to 2450

`php -l` bestaetigt: keine Syntaxfehler.

## Migrierte Calls — class.erpapi.php (this session)

### LieferscheinAuslagern lager_max block (~3038-3265) — 14 queries
- All 14 SelectArr calls in mindesthaltbarkeitsdatum/chargenverwaltung/plain branches migrated
- Added pre-cast int vars ($_iArtLM, $_iStdLagerLM, $_iKommLM, $_iProjektLM, $_iLpiidLM, $_iLagerPlatzVpeLM)
- ORDER BY conditions with $lpiid/$lager_platz_vpe converted to sprintf %d (can't be parameterized)
- WHERE values for $artikel/$standardlager/$projekt/$kommissionskonsignationslager use %d in sprintf
- SelectArr -> DatabaseService->select()

### ChargenMHDAuslagern (19457) — 1 query
- Complex ORDER BY with mhddatum/charge sorting — $mhd/$mhdcharge as named params where possible
- Conditional :mhdcharge param using array_filter(null removal)

### artikelnummerscan (3518-3528) — 3 queries
- SELECT eanherstellerscanerlauben FROM projekt — named param :id
- SELECT id FROM artikel WHERE nummer — named param :nummer
- Two SELECT art.id FROM artikel LEFT JOIN projekt with dynamic $subwhere (FROM boolean) — named param :nummer

### Belegeexport default case (554-566)
- $doctype as table name — validateIdentifier added
- doctypeid -> named param :doctypeid
- DB->SelectArr -> DatabaseService->select()

### belege_arr lookup (4923)
- $table as dynamic table name — validateIdentifier added
- id -> named param :id
- DB->SelectRow -> DatabaseService->selectRow()

### beleg_zwischenpositionen / positions (4984-5010)
- doctype string in WHERE -> named param :doctype + :doctypeid
- $doctype table name for positions -> validateIdentifier
- DB->SelectArr -> DatabaseService->select()

### GetSelectDokumentKunde (22485)
- $typ_bezeichnung in CONCAT — moved from real_escape_string to named param :typbez

### CheckVertrieb (5473-5486)
- auftrag branch — DB->SelectRow -> DatabaseService->selectRow() with :id
- else branch — $module via validateIdentifier + :id

## Verbleibende Patterns
- ~522 total $this->app->DB-> calls remain; most are safe (GetInsertID, affected_rows, UpdateArr, MysqlCopyRow, literals only, sprintf %d)
- Remaining vulnerable patterns are minimal — most variable interpolation has been eliminated
- InsertWithoutLog calls with real_escape_string are architecturally intentional (no prepared statement support)

## Naechster Schritt
- Review any remaining $this->app->DB-> calls with string variable interpolation (very few expected)
- Consider migrating safe SelectArr/SelectRow/Select calls to DatabaseService for consistency
