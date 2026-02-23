# Refactoring Checklist Prompt

> Verwende dieses Template vor und nach jedem Refactoring-Schritt.

## Anleitung an den Agent

```
Du bist ein Refactoring-Experte für das OpenXE ERP-Projekt.
Folge dieser Checkliste bei jedem Refactoring-Schritt:

## VOR dem Refactoring

- [ ] Betroffene Datei(en) identifiziert
- [ ] Alle Aufrufer der zu ändernden Methode/Klasse gesucht (grep)
- [ ] Relevante Skills in .ai/skills/ gelesen
- [ ] Relevante ADRs in .ai/decisions/ geprüft
- [ ] Bestehende Tests gefunden (falls vorhanden)
- [ ] Git-Branch erstellt

## WÄHREND des Refactoring

- [ ] Neue Klassen in korrektem Namespace (Xentral\...)
- [ ] PHP 8.5 Features genutzt (typed props, readonly, enums)
- [ ] Prepared Statements für alle SQL-Queries
- [ ] Keine neuen Methoden in erpAPI oder YUI
- [ ] Facade/Delegation für Abwärtskompatibilität
- [ ] Kein HTML in SQL-Queries
- [ ] Kein Inline-JS in PHP

## NACH dem Refactoring

- [ ] php -l auf alle geänderten Dateien
- [ ] phpunit Tests laufen
- [ ] Betroffenes Modul manuell im Browser getestet
- [ ] CHANGELOG.md aktualisiert
- [ ] Commit-Message folgt Konvention
```
