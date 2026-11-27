# Reparatur-Workflow: OpenXE-Layoutvorschau

`index.html` direkt im Browser oeffnen. Startet in den Reparatur-Details. Keine Verbindung zu OpenXE oder WordPress, keine echten Belege; Aenderungen bleiben bis zum Neuladen im Browser.

## Layout und Produkttext

- Referenzen: page.tpl, ticket_edit.tpl, repair_detail_tab.tpl, styles.css und Dark Theme aus dem integrierten Worktree openxe-rebase-20261019. Sidebar, kompakte Modulnavigation, Tabs, row/row-height/col-md-Raster, fieldset/legend und mkTableFormular mit Feldbezeichnungen links.
- Eigenstaendige Vorschau bildet den benoetigten Layoutausschnitt mit lokalem CSS nach. Produktive Templates und Theme bleiben unveraendert.
- Artikelnummer 100003 und Bezeichnung "3D-Drucker Reparaturservice & Wartung (nach Aufwand)" aus dem Nutzer-Screenshot vorbelegt. Keine echte Artikelzuordnung oder Stammdaten-Pruefung erfolgt.
- Erster Beispielvorgang verwendet Geraet, Fehlerbild und Leistungsbloecke aus dem Screenshot. Kunde, Seriennummer, Preise, Status und Vorgangsnummer sind Beispieldaten.
- Produkttext: Geraet, Fehlerbild, Eingangsdiagnose, Reparatur-/Wartungsarbeiten, Endkontrolle. Kunden-Diagnose und Endkontrolle haben eigene Felder; interne Notizen werden nicht uebernommen.
- Angebot/Auftrag: geplante Arbeiten und vorgesehene Endkontrolle. Rechnung: eigene Vorlage fuer durchgefuehrte Arbeiten. Der Button "Leistungsnachweis einsetzen" setzt den Rechnungstext bewusst aus den aktuellen Leistungsdaten ein; fruehere Belege bleiben unveraendert.
- Neue Platzhalter: {eingangsdiagnose}, {reparaturarbeiten}, {endkontrolle}. Freitext und Aufzaehlungen bleiben bearbeitbar.

## Weiterhin enthalten

- Erledigte standardmaessig ausblenden; Repariert/Versendet bleiben bis zum Abschluss sichtbar. Service-/Statusfilter und Suche kombinierbar.
- Formularpreis links, bei Wartung Paketpreis und Kundenanmerkungen.
- Ein Artikel: Reparatur als Angebot zum Gesamtpreis, weiter in Auftrag/Rechnung. Keine zusaetzliche KVA-Position.
- Ohne Reparatur: gleicher Artikel, nur urspruenglichen Formular-KVA-Preis vorschlagen. Fehlende Preise bleiben leer.
- Belegentwuerfe behalten Text/Preis unabhaengig von spaeter geaenderten Vorlagen. Leistungsdaten bleiben beim Wechsel der Abrechnung erhalten.

## Dateien und Grenzen

- source/index.html, source/style.css, source/app.js: bearbeitbare Quellen.
- python build-preview.py: erstellt ausschliesslich die Offline-Datei index.html; kein OpenXE- oder Production-Build.
- VERIFICATION.md und browser-checks.json: tatsaechliche Pruefergebnisse.
- Beispielpreise sind brutto; Steuerberechnung exemplarisch mit 19 % USt. Echte Preis-/Steuerlogik muss spaeter aus ERP/Artikel kommen.
- Backend-Anbindung (WP-Snapshots, OpenXE-Schema/Mapping, Artikelwahl und Belegpositionen) ist nicht Bestandteil dieser Layoutvorschau.
