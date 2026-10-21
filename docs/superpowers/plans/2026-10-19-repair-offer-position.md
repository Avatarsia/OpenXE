# Reparaturartikel in OpenXE-Belegen

> Ausfuehrung: superpowers:executing-plans, vom Nutzer freigegebene echte Integration.

**Ziel:** Ein aus dem Reparaturticket erzeugtes normales Angebot enthaelt genau eine konfigurierte Reparaturposition mit aufgeloestem Produkttext; weitere Artikel bleiben im nativen Positionseditor hinzufuegbar.

**Architektur:** Bestehende ERP-Belegerstellung und AddPositionManuell verwenden. Konfiguration in repair_integration/systemconfig, Kunden-Leistungsdaten in ticket_repair_details; Belegtexte bleiben Snapshots. Native Templates erweitern, keine separate Anwendung.

**Rahmen:** PHP 8.1, keine neuen Produktionsabhaengigkeiten, kein Push/Production-Build. Artikel wird explizit konfiguriert, keine Stammdatenanlage. Nettopreis der Position ist getrennt von der gesamten Angebotssumme. Formularpreise bleiben historische Snapshots; Brutto/Netto-Umrechnung ueber den echten ERP-Steuersatz.

## Schritte

1. [ ] Template-/Betragslogik und Artikelkonfiguration mit Tests: erlaubte Variablen, keine internen Notizen, Quote vs. Rechnung, fehlende Pflichtangaben, unbekannte Variablen und deutsche Betraege.
2. [ ] Idempotente Migration fuer Kunden-Diagnose, Arbeiten, Endkontrolle, Nettopreis und historische Formularpreise; Gateway/Inbound-Mapping und native Ticket-/Einstellungsformulare erweitern.
3. [ ] Native Position ueber ERP anlegen, Artikel/Steuern/Preis erhalten, nur neue Belege befuellen, bestehende Belege unveraendert oeffnen. Fehler sichtbar behandeln; keine doppelte Anlage bei Wiederholung.
4. [ ] WordPress uebermittelt vorhandene Preis-Snapshots und Wartungsnotizen. Keine neuen Formularfelder oder Rueckberechnung aus heutigen Einstellungen.
5. [ ] Kompakter Filter fuer erledigte Vorgaenge. PHP-Lint auf jede geaenderte Datei, passende Unit-/Integrationstests, komplette vorhandene Suite und Review.

## Pruefschwerpunkte

- Versand als zusaetzlicher Artikel darf nicht in den Reparatur-Positionspreis zurueckfliessen.
- Apostrophe, Umlaute und HTML aus Kundenfeldern bleiben korrekt und sicher.
- Bestehende Angebote werden bei erneutem Klick weder dupliziert noch ueberschrieben.
- KVA-only verwendet denselben Artikel und ausschliesslich die vereinbarte Gebuehr, nie automatisch 0 bei fehlenden Daten.
- Unterschiedliche Steuersaetze und Steuerbefreiung, Schema-Upgrade von 1.5.0 und Neuinstallation.

## Verifikation nach Context-Handoff (2026-10-19)

- PHP 8.1.34 / PHPUnit 9.6.38: Repair-Unit-Suite 101 Tests, 247 Assertions, 0 Fehler.
- Separate isolierte DB repair_position_review_20261019: 9 Integrationstests, 39 Assertions, 0 Fehler. Legacy Core-Deprecations vorhanden. Create/Load/Neuberechnen und Session sind im Fixture teilweise ersetzt.
- Gesamte vorhandene Tests ohne DB-Freigabe: 124 Tests, 247 Assertions, 23 uebersprungen (9 obige Positions-DB-Tests separat bestanden, 14 bestehende Sync-DB-Tests nicht ausgefuehrt).
- WordPress-Worktree C:/worktrees/repair-intake-erp-positions: 5 Payload-Tests, 11 Assertions, 0 Fehler. Alle 3 neuen/geaenderten PHP-Dateien lint-geprueft.
- OpenXE: PHP-Lint fuer alle 14 neuen/geaenderten PHP-Dateien erfolgreich. node --check und git diff --check erfolgreich.
- Unabhaengiges Read-only-Review: CSRF und geloeschte Entwuerfe must-fix. Neuer form_id-Cast verliert String-Slugs, nur Metadaten betroffen. Native Rabattuebernahme gefaehrdet feste KVA-Gebuehr (Quellpfad belegt, kein DB-Repro).
- CSRF separat mit echten Session-Klassen reproduziert: next-request=false, nach commitSession=true fuer repair_beleg und repair_settings.
- Geloeschter Entwurf separat durch echten Controller reproduziert: Neuanlage erwartet, 0 Angebote, Redirect auf geloeschte ID 1 (1 absichtlich roter Diagnosetest).
- Browserpruefung der nativen OpenXE-Maske steht aus: keine laufende OpenXE-Webinstanz festgestellt. WordPress-Staging unter localhost:9000 antwortete 502.
- Keine Implementation nach diesen Review-Funden geaendert. Konkrete Korrekturfreigabe gemaess User-AGENTS.md angefragt.
- Repro-/Lint-Scripts liegen unter C:/tmp/openxe-repair-native-20261019. Keine Secrets in Berichten oder Ausgaben.

## Freigegebene Review-Korrekturen abgeschlossen

- Nutzerfreigabe: alle vier Korrekturen.
- CSRF: Token werden nach Rendering in der Session persistiert, Regression prueft echte PHP-Session-Dateien in getrennten Prozessen und bewahrt bestehende Login-Daten.
- Geloeschte Entwuerfe: Belegexistenz unter Ticket-Lock pruefen, verwaiste eigene Verknuepfung entfernen und neue native Position anlegen. Wiederholungsklick auf existierenden Beleg bleibt unveraendert.
- form_id: ungenutzte Erweiterung aus WP-Payload, ERP-Mapping, Gateway und neuer Schema-Erweiterung entfernt. Vorhandene bereits lokal erzeugte Spalten werden nicht destruktiv geloescht.
- Feste KVA-Gebuehr: neue Gebuehrenposition vor nativer Neuberechnung gegen automatische Rabattuebernahme sperren. Reproduziert im echten Core-Rabattabschnitt: 49,00 EUR wurden vorher 44,10 EUR, danach bleiben 49,00 EUR. Unabhaengige Totals-/Frontend-Verifikation steht aus.
- Regressionen zuerst RED, danach GREEN. Alte Sync-Test-Fixture: zwei Hook-Tabellen an vorhandene Core-Spalten angeglichen (vorher 14 Setup-Fehler Unknown column alias, keine Produktivlogik geaendert).
- Finale gesamte OpenXE-Suite mit beiden explizit isolierten DBs: 129 Tests, 348 Assertions, 0 Fehler, 0 uebersprungen.
- Finale WP-Payload-Suite: 7 Tests, 12 Assertions, 0 Fehler. PHP-Lint: 16 OpenXE- und 3 WP-Dateien, alles erfolgreich. JavaScript-Syntax und git diff --check erfolgreich.
- Final review: unabhaengige Findings technisch gegengeprueft, alle vier freigegebenen Korrekturen durch Regressionen belegt. Keine verbleibenden Findings aus dieser Review-Runde.
- Keine Commits, Pushes, Releases oder Production-Builds. Native Browser-E2E bleibt mangels laufender lokaler OpenXE-Webinstanz offen.

## Lokaler echter OpenXE-Teststand

- Runtime-Snapshot C:/tmp/openxe-repair-web-20261019/app, Docker Compose im uebergeordneten Ordner, Port 127.0.0.1:18081. PHP 8.1.34 / MariaDB 10.11, frische komplette struktur.sql plus 92 fehlerfreie Core-Upgrades.
- Login demo, Kennwort nur in lokaler .env. Demotickets 1 und 2, Serviceartikel 100003, Versandartikel VERSAND. WordPress-Sync deaktiviert, keine Live-Daten uebernommen.
- Echte Browserpruefungen: Login, Ticketformular, Artikel-/Vorlageneinstellungen speichern, Vorlagentyp wechseln, Erledigtenfilter 1/2 Vorgaenge, Angebot erzeugen, native Versandposition ergaenzen/bearbeiten, Wiederholungsklick ohne Duplikat, KVA-Rechnung 49,00 EUR brutto, Leistungsdaten mit Umlauten speichern/neuladen.
- Asset-Defekt durch Browser entdeckt und explizit vom Nutzer freigegeben: Player registriert nur Ticket-Basisklasse. TicketCustom registriert Assets jetzt vor Parent-Konstruktor direkt ueber ModuleScriptCache. Regression RED -> GREEN, echte Vorschau aktualisiert sich im Browser.
- Aktuelle komplette OpenXE-Suite: 130 Tests, 351 Assertions, 0 Fehler/Skips. WP-Suite zuletzt 7 Tests erfolgreich. Lint der beiden zusaetzlichen PHP-Aenderungen und git diff --check erfolgreich.
- Weiterer UI-Befund: createbeleg redirect zeigt direkt den nackten Positions-Iframe. Vollstaendige Maske funktioniert ueber action=edit&id=4#tabs-2. Korrektur angefragt, noch nicht freigegeben/umgesetzt.
- Screenshot: C:/Users/3D Partner/.t3/userdata/browser-artifacts/browser-screenshot-127-0-0-1-mvfinqy8-66ffa575.png

## Vollstaendige Belegmaske freigegeben und verifiziert

- Nutzerfreigabe: vollstaendige Belegmaske oeffnen.
- Einzeiliger Controller-Fix: Redirect fuer neue und vorhandene Belege auf action=edit&id=...#tabs-2.
- Regression zuerst RED (3 erwartete Fehler), danach komplette OpenXE-Suite GREEN: 130 Tests, 354 Assertions, 0 Fehler/Skips.
- Browser: Angebots-Klick aus Ticket 1 oeffnet native Belegmaske mit OpenXE-Navigation und aktivem Positionen-Tab, vorhandene Service-/Versandpositionen bleiben unveraendert. KVA-Klick aus Ticket 2 oeffnet entsprechende native Rechnungsmaske mit aktivem Positionen-Tab.
- Controller und Integrationstest PHP-lint-fehlerfrei, git diff --check ohne Befund. Lokaler Runtime-Snapshot aktualisiert. Keine verbleibende Freigabe fuer die Beleg-Weiterleitung erforderlich.

## Standard-Checklisten und KVA-Hinweis (2026-10-20)

- Nutzerauftrag: Hinweis unter KVA entfernen; Diagnose/Endkontrolle aus Screenshot als Standard, Reparatur-/Wartungsarbeiten individuell; Abweichung Entwurf/Vorschau erklaeren.
- Referenzpunkte aus erhaltener Screenshot-Uebertragung in docs/previews/repair-workflow/source/app.js uebernommen: 5 Diagnose- und 5 Endkontrollpunkte. In Reparatur und Wartung bearbeitbar vorausgefuellt; Arbeiten bleiben leer.
- Nur nie befuellte NULL-Felder erhalten Vorgaben. Bestehende eigene Texte und explizit leer gespeicherte Felder bleiben erhalten. Keine Reparaturdaten-/Beleg-Snapshots rueckwirkend ueberschrieben.
- Wartungsvorlage enthaelt jetzt ebenfalls Diagnose und Endkontrolle; in lokaler Demo nur unveraenderte bisherige Standardvorlage aktualisiert (CRLF/LF fuer Vergleich normalisiert), individuelle Vorlagen bleiben erhalten.
- Hinweise entfernt; produktive und lokale Runtime-Dateien synchronisiert.
- Abweichung geprueft: Beleg #4 enthaelt gespeicherte Endkontrolle aus Erstellungszeitpunkt, Ticket #1 nachtraeglich geaenderte Kontrolle. Layout des echten Belegs folgt nativem OpenXE, Sidebar-Vorschau ist Textvorschau.
- Eigene neue Demotickets 3 (Reparatur) und 4 (Wartung) zeigen Standardtexte und leere individuelle Arbeiten. Keine zusaetzlichen Belege fuer diese Tickets angelegt.
- RED/GREEN fuer Standardwerte, UI-Vorbelegung, Leer-Speicherung und Wartungs-Belegtext. Gesamte Suite: 133 Tests, 374 Assertions, 0 Fehler/Skips; PHP-Lint aller 6 in dieser Aenderung beruehrten Dateien erfolgreich; git diff --check ohne Befund.
- Echte Browserpruefung fuer beide neuen Vorgangstypen: Checklisten korrekt, Arbeiten leer, KVA-Hinweis fehlt. Bisherige Demobelege unveraendert.

## PDF-Zeilenabstaende (2026-10-20)

- Diagnose: nl2br speicherte br-Tags plus rohe Newlines, der native PDF-Renderer wertete beide aus. Lokales Angebot #5, Position #6: 19 br-Tags plus 19 Newlines.
- Nutzerfreigabe: Konvertierung korrigieren und lokalen Angebotsentwurf #5 bereinigen. Token-Audit weiterhin vertagt.
- Minimale Konvertierung: CRLF/CR/LF direkt durch br ersetzen; HTML-Escaping und absichtliche Leerzeilen zwischen Bloecken erhalten. Gilt fuer neu erstellte Angebote, Auftraege und Rechnungen.
- Entwurf #5 ausschliesslich an Position #6 bereinigt, mit Transaktion und Pruefung auf unveraenderten, nicht freigegebenen Entwurf. Vorheriger Text ausserhalb des Repository unter /tmp/offer-5-position-6-before-spacing.json im lokalen App-Container gesichert. Keine anderen Belege geaendert.
- Runtime-Kopie bytegleich mit Source. Echte PDF-Ausgaben vor/nachher abgerufen und gerendert: Checklisten-Zeilenabstand 18,2 -> 9,1 pt; Text extrahiert identisch, 119,00 EUR brutto unveraendert; Sichtpruefung der gesamten Seite erfolgreich.
- Regression zuerst RED, danach GREEN fuer LF/CRLF/CR, Blockabsaetze, HTML-Escaping und SQL-Persistenz. Vollstaendige Suite: 134 Tests, 377 Assertions, 0 Fehler/Skips. Alle 4 beruehrten PHP-Dateien lint-fehlerfrei, git diff --check erfolgreich.
- Vergleichsartefakte: C:/tmp/openxe-repair-web-20261019/offer-5-before.pdf und offer-5-after.pdf, jeweils auch PNG. Kein Commit/Push/Production-Deployment.
