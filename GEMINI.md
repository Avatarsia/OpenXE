# GEMINI.md — OpenXE ERP System

> Gemini Code Assist Konfigurationsdatei.
> Diese Datei wird von Gemini bevorzugt vor AGENTS.md gelesen.
> Inhaltlich identisch — für Details siehe AGENTS.md.

## ⚠️ SESSION PROTOCOL (PFLICHT — ZUERST LESEN)

**Diese Schritte MÜSSEN vor jeder Arbeit ausgeführt werden. Keine Ausnahmen.**

### Vor JEDER Arbeit — Session-Start:
1. **Lies** `.ai/handover/CURRENT_STATE.md` — Prüfe ob eine aktive Aufgabe einer anderen KI vorliegt
2. **Wenn `active_task: true`:** Melde dem User den Stand und frage ob fortgesetzt werden soll
3. **Wenn `active_task: false`:** Bereit für neue Aufgabe
4. **Lies** die letzten Einträge in `.ai/handover/SESSION_LOG.md` für aktuellen Kontext
5. **Prüfe** `git status` auf uncommittete Änderungen einer vorherigen Session
6. **Lies** `.ai/handover/HANDOVER_PROTOCOL.md` für das vollständige Protokoll

### Nach JEDER Arbeit — Session-Ende:
1. **Überschreibe** `.ai/handover/CURRENT_STATE.md` mit aktuellem Fortschritt
2. **Aktualisiere** `.ai/handover/SESSION_LOG.md` — neuen Eintrag hinzufügen, ältesten archivieren wenn >3
3. **Committe** unfertigen Code mit `WIP: `-Prefix
4. **Aktualisiere** `.ai/changelog/CHANGELOG.md` bei abgeschlossenen Aufgaben

> Vollständiges Protokoll: [.ai/handover/HANDOVER_PROTOCOL.md](.ai/handover/HANDOVER_PROTOCOL.md)

## Verweis

Alle Anweisungen, Coding-Standards und Projektkontext befinden sich in:
- **[AGENTS.md](AGENTS.md)** — Vollständige Agent-Anweisungen
- **[.ai/OVERVIEW.md](.ai/OVERVIEW.md)** — Detaillierte Dokumentation, Skills, ADRs

Gemini: Lies bitte zuerst `AGENTS.md` und dann `.ai/OVERVIEW.md` für den vollständigen Kontext.

## Kurzregeln (Gemini-spezifisch)

1. **PHP 8.5** — Verwende alle modernen Features (typed props, readonly, enums, match)
2. **Prepared Statements** — Niemals sprintf/Interpolation in SQL
3. **Neue Logik** → `classes/Services/` oder `classes/Repository/`
4. **Keine neuen Methoden** in `class.erpapi.php` (39.520 Zeilen) oder `class.yui.php` (15.983 Zeilen)
5. **Kein HTML in SQL**, kein Inline-JS in PHP
6. **Skills lesen** vor wiederkehrenden Aufgaben: `.ai/skills/`
7. **ADRs prüfen** vor Architekturentscheidungen: `.ai/decisions/`
8. **CHANGELOG aktualisieren** nach Änderungen: `.ai/changelog/CHANGELOG.md`
