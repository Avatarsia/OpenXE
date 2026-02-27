# jQuery 4.0 Upgrade Report - OpenXE

- Datum: 2026-02-27
- Repository: `OpenXE`
- Ziel: Aufwand und Risiko fuer ein Upgrade auf jQuery 4.0 bewerten
- Ergebnis in einem Satz: Das Upgrade ist moeglich, aber nicht als reines Versions-Update; Hauptaufwand liegt in Bootstrap 3 und Legacy-Plugins.

## 1. Executive Summary

Ein direktes Umschalten von jQuery 3.5.0 auf 4.0 ist im aktuellen Stand **nicht produktionsreif**.

Die wichtigsten Gruende:
1. `www/js/bootstrap.min.js` (Bootstrap 3.3.7) blockiert jQuery >= 4 per explizitem Runtime-Check.
2. Zentral eingebundene Legacy-Abhaengigkeiten (jQuery UI 1.11.4, Timepicker Addon 1.4, jEditable 1.7.1, DataTables 1.10.18 + ColumnFilter 1.5.6) erzeugen hohes Inkompatibilitaetsrisiko.
3. Im Eigen-Code existieren mehrere jQuery-Muster, die unter jQuery 4 entfernt/deprecated sind.

**Gesamtschaetzung**:
- Technischer PoC (laeuft, Kernseiten klickbar): **3-6 PT**
- Produktionsreifes Upgrade inkl. Regressionen: **30-55 PT**
- Falls Bootstrap 3 ersetzt werden muss (realistisch): **+15-30 PT**

## 2. Scope und Methode

Der Report basiert auf:
1. Analyse der globalen Script-Einbindung in den Haupttemplates
2. Statische Suche nach kritischen jQuery-Patterns im Eigen-Code
3. Versionssichtung der zentral eingebundenen Frontend-Bibliotheken
4. Risiko-/Aufwandsmodell pro Upgrade-Baustein

Nicht enthalten:
- Vollstaendige Laufzeittests aller Module
- Browser-Matrix-Tests
- Last-/Performance-Tests

## 3. Ist-Zustand (technisch)

### 3.1 Global eingebundener Frontend-Stack

Haupttemplates:
- `www/themes/new/templates/page.tpl`
- `www/themes/new/templates/popup.tpl`

Zentrale Befunde:
- jQuery 3.5.0 global eingebunden
- jQuery Migrate 3.2.0 global eingebunden (`JQUERYMIGRATESRC`)
- Bootstrap 3.3.7 global eingebunden
- jQuery UI 1.11.4 global eingebunden
- weitere alte jQuery-Plugins global aktiv (DataTables Bundle, ColumnFilter, jEditable, Timepicker Addon)

### 3.2 Kritischer Blocker

`www/js/bootstrap.min.js` enthaelt einen harten Versionscheck:
- Fehlermeldungstext im Code: "requires jQuery version ... lower than version 4"
- Folge: Bei jQuery 4 faellt die UI schon waehrend der Initialisierung aus.

## 4. Quantitative Code-Funde (Eigen-Code)

Suchraum: `phpwf`, `classes`, `www/pages`, `www/widgets` (ohne Minified/ausgewaehlte Vendor-Artefakte)

Treffer:
- `toggleClass(className, boolean)`: **60**
- `.bind(...)`: **22**
- `$.trim(...)`: **4**
- `.size()`: **2**

Diese Patterns gelten als relevante Upgrade-Kandidaten fuer jQuery 4.

## 5. Abhaengigkeiten mit hohem Risiko

### 5.1 Bootstrap 3.3.7 (kritisch)

- Aktuell im Kernlayout eingebunden
- Inkompatibel zu jQuery 4 (hart codiert)
- Ohne Loesung an dieser Stelle ist ein produktiver Rollout blockiert

### 5.2 jQuery UI 1.11.4 (hoch)

- Sehr alte Version
- Hohe Wahrscheinlichkeit von Reibung mit moderner jQuery-Linie
- Da Dialoge/Datepicker/Draggable in OpenXE intensiv genutzt werden, ist dies ein zentraler Risikofaktor

### 5.3 Legacy-Plugins (hoch)

- Timepicker Addon 1.4 (2013)
- jEditable 1.7.1
- DataTables Bundle 1.10.18 + ColumnFilter 1.5.6

Diese Komponenten sind funktional zentral, aber alt. Sie muessen einzeln auf jQuery-4-Faehigkeit validiert bzw. ersetzt werden.

## 6. Aufwandsschaetzung nach Szenarien

### Szenario A - Minimal/PoC

Ziel:
- jQuery 4 laeuft lokal
- Basisnavigation und 10-15 Kernseiten aufrufbar

Arbeit:
1. jQuery 4 + Migrate 4.x einbauen
2. Schnellfixes fuer offensichtliche Pattern
3. Bootstrap-Blocker temporaer behandeln (nur fuer Testzwecke)

Aufwand: **3-6 PT**

Risiko: Hoch (nicht produktionsreif)

### Szenario B - Produktionstauglich

Ziel:
- Stabiler Betrieb fuer Hauptmodule
- Rueckfallplan + Regressionstest

Arbeit:
1. Bootstrap-Strategie (Upgrade/Replacement/Entkopplung)
2. jQuery-UI-/Plugin-Kompatibilitaet sichern
3. Systematische Pattern-Migration im Eigen-Code
4. Vollstaendige Funktions- und Regressionsrunde

Aufwand: **30-55 PT**

Risiko: Mittel bis hoch (haengt von Plugin-Lage ab)

### Szenario C - Mit Bootstrap-Ersatz

Ziel:
- Zukunftssicherer Frontend-Sockel ohne Bootstrap-3-Altlast

Zusatzaufwand zu Szenario B:
- **+15-30 PT**

## 7. Empfohlene Migrationsstrategie

### Phase 0 - Vorbereitung (2-4 PT)
1. Saubere Asset-Inventur (was wird wirklich auf welchen Seiten geladen)
2. Feature-Flag fuer Umschalten `jquery3` <-> `jquery4`
3. Definition der "kritischen User Journeys" fuer Regression

### Phase 1 - Kompatibilitaets-Backbone (5-10 PT)
1. jQuery 4 in parallelem Buildpfad bereitstellen
2. jQuery Migrate 4.x aktivieren
3. JavaScript-Error-Logging zentral sammeln

Exit-Kriterium:
- Keine Startabbrueche im Basislayout

### Phase 2 - Blocker abbauen (8-18 PT)
1. Bootstrap-3-Blocker loesen
2. jQuery UI auf kompatible Linie anheben oder betroffene Widgets ersetzen
3. Kritische Legacy-Plugins priorisiert behandeln (Timepicker, jEditable, ColumnFilter)

Exit-Kriterium:
- Hauptseiten ohne JS-Abbruch nutzbar

### Phase 3 - Eigen-Code-Haertung (6-12 PT)
1. `.bind()` -> `.on()`
2. `.size()` -> `.length`
3. `$.trim()` -> `String(...).trim()`
4. `toggleClass(class, bool)` auf add/remove oder kompatibles Pattern umstellen

Exit-Kriterium:
- Keine Migrate-Fehler fuer eigene Skripte

### Phase 4 - Stabilisierung und Rollout (6-11 PT)
1. Regressionssuite auf Kernmodule
2. UAT mit Fachbereichen
3. Stufenrollout (Canary -> breite Freigabe)

Exit-Kriterium:
- Fehlerquote und Supporttickets im Zielkorridor

## 8. Teststrategie (empfohlen)

Pflicht-Checks pro Welle:
1. Login/Logout
2. Suche/Supersearch
3. CRUD in Artikel, Auftrag, Angebot, Rechnung
4. Popups/Dialogs/Datepicker
5. Tabellen (Sortieren, Filtern, Export)
6. Drag-and-drop/Farbpicker wo vorhanden

Technisch:
- Browser-Konsole auf JS-Errors + Migrate-Warnungen
- Smoke-Test-Suite fuer 20-30 Kernpfade
- Vergleich mit jQuery-3-Pfad (A/B)

## 9. Entscheidungsoptionen

### Option 1 - Kurzfristig risikoarm
- Bei jQuery 3.5.0 bleiben
- Legacy-Konsolidierung vorziehen

### Option 2 - Schrittweise Modernisierung (empfohlen)
- Paralleler jQuery-4-Pfad mit Feature-Flag
- Blocker schrittweise entfernen
- Nach stabiler Telemetrie cut-over

### Option 3 - Big-Bang
- Nicht empfohlen fuer dieses Repository
- Zu hohe Kopplung an alte Frontend-Bibliotheken

## 10. Konkrete naechste Schritte

1. Entscheidung treffen: "Upgrade jetzt" vs. "Vorbereitung zuerst"
2. Bei Upgrade: zuerst Bootstrap-3-Strategie festlegen (Blocker Nummer 1)
3. Kompatibilitaets-Matrix pro Plugin erstellen (keep, upgrade, replace)
4. Feature-Flag fuer jQuery-Pfad bauen und auf Staging testen
5. Danach erst breite Pattern-Migration im Eigen-Code starten

## 11. Anhang - zentrale Referenzdateien

- `www/themes/new/templates/page.tpl`
- `www/themes/new/templates/popup.tpl`
- `phpwf/class.player.php`
- `www/js/bootstrap.min.js`
- `www/themes/new/js/jquery-ui-1.11.4.custom.min.js`
- `www/js/jquery-ui-timepicker-addon.js`
- `www/js/jquery.jeditable.js`
- `www/js/datatables/datatables.min.js`
- `www/js/jquery.dataTables.columnFilter.js`
- `phpwf/plugins/class.yui.php`
