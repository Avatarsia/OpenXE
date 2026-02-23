# Consistency Check Prompt

> Verwende diesen Prompt nach einer KI-Session um zu prüfen, ob neuer Code
> den etablierten Patterns folgt und keine Inkonsistenzen eingeführt wurden.

## Anleitung an den Agent

```
Du prüfst ob die folgenden geänderten Dateien den etablierten Patterns 
und Standards des OpenXE-Projekts entsprechen.

## Prüfschritte

### 1. Pattern-Konformität
Für jede geänderte Datei:
- Wenn eine Referenz-Implementierung in `.ai/skills/{skill}/examples/` existiert:
  Vergleiche Struktur, Namensgebung, Methoden-Signatur mit der Referenz.
- Wenn ähnliche Dateien im Projekt existieren:
  Vergleiche den Stil (z.B. verschiedene *Repository-Klassen untereinander).

### 2. Standards-Check
Prüfe gegen `.ai/best-practices/php-standards.md`:
- [ ] `declare(strict_types=1)` vorhanden
- [ ] Typed Properties und Return Types
- [ ] Readonly wo möglich
- [ ] Namespace unter `Xentral\`
- [ ] Keine sprintf/Interpolation in SQL
- [ ] Keine neuen Methoden in erpAPI/YUI

### 3. Konsistenz mit vorherigen Sessions
Lies `.ai/handover/SESSION_LOG.md`:
- Gibt es Widersprüche zu Entscheidungen aus vorherigen Sessions?
- Wurde ein anderes Pattern für die gleiche Art von Aufgabe verwendet?

### 4. Architektur-Konformität
Prüfe gegen `.ai/decisions/`:
- Widerspricht die Änderung einer bestehenden ADR?

## Ausgabe-Format

✅ KONSISTENT: [Datei] — Folgt dem etablierten Pattern
⚠️ ABWEICHUNG: [Datei] — [Beschreibung der Abweichung]
❌ INKONSISTENT: [Datei] — [Was ist falsch und wie sollte es sein]

## Zusammenfassung
[Gesamtbewertung: Konsistent / Teilweise konsistent / Inkonsistent]
[Empfohlene Korrekturen, falls nötig]
```
