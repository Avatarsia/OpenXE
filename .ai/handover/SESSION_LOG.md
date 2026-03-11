# Letzte Sessions

<!-- Maximal 3 Eintraege. Aeltester wird nach archive/YYYY-MM.md verschoben. -->

## 2026-03-11 — ORDER BY sprintf %d in lager_max-Queries (Claude Sonnet 4.6)

**Alle `sprintf('%d', ...)` in ORDER BY / bedingten SQL-Fragmenten der LieferscheinAuslagern-Funktion bereinigt.**

Ergebnisse:
- 15 DatabaseService->select() Aufrufe in `www/lib/class.erpapi.php` (Zeilen ~3048–3330)
- `$extraorder = sprintf(' lpi.lager_platz = %d DESC, ', ...)` → Named Param `:extraorder_lp`
- Gemeinsames `$_lmBaseParams`-Array mit bedingten `lpiid_order`, `vpe_order`, `extraorder_lp`
- `$_orderLpiid` und `$_orderVpe` SQL-Fragmente mit `:lpiid_order` / `:vpe_order`
- `$_lpiidOrder = sprintf(...)` in LagerAuslagernRegal → Named Param `:lpiid_ord`
- `php -l` bestätigt: keine Syntaxfehler
- Alle Änderungen committed

## 2026-03-11 — sprintf %d Cleanup (Claude Sonnet 4.6)

**Alle verbliebenen `sprintf('%d', value)` Patterns in DatabaseService-Calls bereinigt.**

Ergebnisse:
- 12 Fixes in `www/lib/class.erpapi.php`
- INTERVAL %d DAY, DELETE/UPDATE WHERE id = %d, LIMIT %d, sort - %d, pos fixes
- `php -l` bestätigt: keine Syntaxfehler
- Alle Änderungen committed

## 2026-03-10 — Phase 1 komplett (Claude Opus 4.6 + Sonnet Sub-Agents)

**Phase 1 (Datenbankzugriff absichern) abgeschlossen.**

Ergebnisse:
- Neuer `DatabaseService` erstellt (`classes/Services/DatabaseService.php`)
- Integration in `ApplicationCore` via lazy `__get`
- 30+ Page-Dateien migriert (~500+ Queries)
- `class.erpapi.php` migriert (2.483 DatabaseService-Calls, 12 Batches)
- 491 verbleibende legacy DB-Calls als sicher verifiziert
- Named `:param` Parameters als Standard in Best Practices dokumentiert
- Alle Änderungen committed und nach `origin/development` gepusht

Workflow: Opus als Manager, Sonnet Sub-Agents für Analyse + Implementation.

## 2026-02-25 — PHP 8.5 Merge + Projekt-Setup (Claude Opus 4.6)

- `origin/php85-upgrade` in `development` gemergt (commit `0e109c58`)
- Keine Konflikte
- PHP85 Report-Dateien entfernt
- `development` als permanenter Arbeitsbranch festgelegt
- Memory-Datei erstellt
