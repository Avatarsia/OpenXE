# Letzte Sessions

<!-- Maximal 3 Eintraege. Aeltester wird nach archive/YYYY-MM.md verschoben. -->

## 2026-03-11 — sprintf %d Cleanup in class.erpapi.php (Claude Sonnet 4.6)

**Alle verbliebenen `sprintf('%d', value)` Patterns in DatabaseService-Calls bereinigt.**

Ergebnisse:
- 12 Fixes in `www/lib/class.erpapi.php`
- INTERVAL %d DAY → INTERVAL :days DAY (Zeilen 3869, 3917, 4382, 4386)
- DELETE/UPDATE WHERE id = %d → WHERE id = :id (Zeilen 19314, 19335, 19378)
- LIMIT %d → LIMIT :limit mit if/else Branch (Zeile 24833)
- UPDATE %s_position ... sort - %d → :diff (Zeile 32615)
- pos - %d, sort + %d → :diff, :offset (Zeile 32622)
- pos > %d → pos > :pos (Zeile 32628)
- INTERVAL %d day → INTERVAL :days day (Zeile 37731)
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
