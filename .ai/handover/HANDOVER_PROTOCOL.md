# AI Handover Protocol

> Pflichtprotokoll für alle KI-Agenten, die an diesem Projekt arbeiten.
> Dieses Protokoll stellt Konsistenz bei Wechseln zwischen verschiedenen KIs sicher.

---

## Session-Start (PFLICHT)

Führe diese Schritte aus BEVOR du mit irgendeiner Aufgabe beginnst:

### 1. State prüfen
```
Lies: .ai/handover/CURRENT_STATE.md
```
- Hat `active_task: true`? → Melde dem User den Stand und frage ob fortgesetzt werden soll
- Hat `active_task: false`? → Bereit für neue Aufgabe
- Prüfe `last_agent` — du übernimmst möglicherweise von einer anderen KI

### 2. Letzte Sessions prüfen
```
Lies: .ai/handover/SESSION_LOG.md
```
- Verstehe was in den letzten 1–3 Sessions passiert ist
- Prüfe ob uncommitterte Änderungen existieren (`git status`)

### 3. Kontext laden
- Lies relevante Skills in `.ai/skills/` für die anstehende Aufgabe
- Prüfe relevante ADRs in `.ai/decisions/`
- Lies `.ai/best-practices/php-standards.md` bei Code-Änderungen

### 4. User informieren
Sage dem User kurz:
- "Letzter Stand: [Phase X.Y, Z% fertig, von {Agent}]"
- "Nächster Schritt wäre: [aus CURRENT_STATE.md]"
- "Soll ich dort weitermachen oder gibt es eine andere Aufgabe?"

---

## Session-Ende (PFLICHT)

Führe diese Schritte aus BEVOR du die Session beendest:

### 1. CURRENT_STATE.md aktualisieren
**ÜBERSCHREIBE** die gesamte Datei (nicht anhängen!):
```markdown
---
last_updated: [ISO 8601 Zeitstempel]
last_agent: [dein Name: claude/gemini/codex/cursor/copilot]
active_task: [true wenn Arbeit unfertig, false wenn Aufgabe abgeschlossen]
phase: [aktuelle Phase-Nummer, 0-10]
subtask: "[Beschreibung des aktuellen Teilschritts]"
progress: [Prozent der aktuellen Teilaufgabe]
---

# Aktueller Stand

## Aktive Aufgabe
[Was wird gerade gemacht?]

## Letzter Schritt
[Was wurde in DIESER Session konkret getan? Max 3 Zeilen.]

## Nächster Schritt
1. [Konkretester nächster Schritt]
2. [Danach]
3. [Optional: übernächster]

## Offene Entscheidungen
- [Entscheidungen die noch nicht getroffen wurden]

## Geänderte Dateien (uncommitted)
- [Liste der geänderten/neuen Dateien]
```

### 2. SESSION_LOG.md aktualisieren

**Archiv-Rotation:**
- Zähle die vorhandenen Einträge in SESSION_LOG.md
- Wenn bereits **3 Einträge** vorhanden:
  1. Verschiebe den ältesten Eintrag nach `.ai/handover/archive/YYYY-MM.md`
  2. Erstelle die Archiv-Datei falls sie noch nicht existiert
- Füge deinen neuen Eintrag **oben** ein (nach dem Kommentar-Header)

**Format eines Eintrags (maximal 2 Zeilen):**
```markdown
## YYYY-MM-DD HH:MM — [Agent-Name]
Phase X.Y | [Was wurde getan — Zusammenfassung in einem Satz]
```

### 3. Code committen
- Wenn Arbeit unfertig: `git add . && git commit -m "WIP: [Beschreibung]"`
- Wenn Arbeit fertig: `git add . && git commit -m "feat/fix/refactor(...): [Beschreibung]"`

### 4. Changelog aktualisieren
- Bei abgeschlossenen Aufgaben: Eintrag in `.ai/changelog/CHANGELOG.md`
- Bei Phase-Fortschritt: `.ai/changelog/migration-log.md` aktualisieren

---

## Notfall-Übergabe (Session bricht ab)

Falls eine Session unerwartet endet (Budget, Timeout, Fehler):
- Der User startet eine neue Session mit einer beliebigen KI
- Die neue KI liest automatisch `CURRENT_STATE.md` (steht in AGENTS.md als Pflicht)
- `git diff` zeigt uncommittete Änderungen
- SESSION_LOG.md gibt zusätzlichen Kontext

Auch ohne perfekte Übergabe bleibt die Arbeit nachvollziehbar.

---

## Konsistenz-Regeln

### Referenz-Implementierungen nutzen
Wenn in `.ai/skills/{skill}/examples/` eine Referenz-Datei existiert:
- **Folge dem exakten Pattern** (Klassen-Struktur, Namensgebung, Methoden-Signatur)
- Weiche nur bei dokumentiertem Grund ab (→ neuer ADR-Eintrag)

### Bei Unsicherheit
- Prüfe wie ähnliche Dateien im Projekt bereits umgesetzt sind
- Lies den relevanten Skill in `.ai/skills/`
- Im Zweifel: Frage den User, statt eine eigene Entscheidung zu treffen

### Code-Stil
- Alle Regeln in `.ai/best-practices/php-standards.md` sind verbindlich
- `match()` statt `switch`, Typed Properties, Readonly wo möglich
- Prepared Statements für ALLE SQL-Queries, ausnahmslos
