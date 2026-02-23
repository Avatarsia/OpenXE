# Security Audit Prompt

> Verwende dieses Template für Sicherheitsüberprüfungen einzelner Module oder Dateien.

## Anleitung an den Agent

```
Du führst ein Sicherheits-Audit der Datei [DATEINAME] im OpenXE ERP-Projekt durch.

Suche systematisch nach diesen Schwachstellen:

### SQL Injection
- Suche nach: sprintf(), String-Interpolation in SQL, CONCAT() mit Variablen
- Prüfe: Werden alle User-Inputs über Prepared Statements eingebunden?
- Bewerte: Wird $app->Secure->GetGET/GetPOST korrekt genutzt?

### Cross-Site Scripting (XSS)
- Suche nach: Direkte Ausgabe von User-Input in HTML/JS
- Prüfe: $app->Tpl->Set() mit unescapten Variablen
- Bewerte: Werden htmlspecialchars/htmlentities verwendet?

### Directory Traversal / File Inclusion
- Suche nach: include/require mit variablen Pfaden
- Prüfe: Datei-Uploads und Pfad-Manipulation

### Authentication / Authorization
- Prüfe: Werden Berechtigungen vor dem Datenzugriff geprüft?
- Suche nach: Direkte ID-Parameter ohne Ownership-Check

### Information Disclosure
- Suche nach: var_dump, print_r, error details in Production
- Prüfe: Logging von sensiblen Daten (Passwörter, Tokens)

Erstelle einen Bericht im Format:

## Kritische Schwachstellen (sofort beheben)
| # | Typ | Zeile | Beschreibung | Fix |
|---|-----|-------|-------------|-----|
| 1 | SQLi | 42 | sprintf in WHERE | Prepared Statement |

## Mittlere Schwachstellen (zeitnah beheben)
[gleiche Tabelle]

## Niedrige Schwachstellen (im Rahmen von Refactoring)
[gleiche Tabelle]

## Empfehlungen
[Allgemeine Verbesserungsvorschläge]
```
