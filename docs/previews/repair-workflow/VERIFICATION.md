# Verifikation am 19.10.2026

- JavaScript-Syntax: node --check source/app.js erfolgreich.
- Browser: 39/39 Verhaltenspruefungen bestanden, Einzelresultate in browser-checks.json.
- OpenXE-Formstruktur, Artikelvorbelegung, Kunden-Leistungsdaten und Druckvorschau geprueft.
- Angebot -> Auftrag -> Rechnung: Artikel 100003 und 189,00 EUR Gesamtpreis bleiben erhalten. Rechnungstext wird bewusst aus der Rechnungsvorlage eingesetzt.
- Nur KVA: gleicher Artikel, urspruengliche Beispielgebuehr 49,00 EUR; keine Reparatur-/Endkontrollbloecke oder zusaetzlichen Kostenpunkte.
- Leistungsdaten bleiben beim Abrechnungswechsel erhalten. Interne Notizen fliessen nicht automatisch in den Produkttext.
- Vorlagenaenderungen schreiben bestehende Belege nicht um. Unbekannte Platzhalter werden blockiert; fehlende Werte markiert.
- Vier Ansichten bei 390 und 1600 CSS-Pixeln ohne Seitenueberlauf; breite Tabellen scrollen innerhalb ihres Containers.
- Desktop und Mobil visuell kontrolliert; kompakte OpenXE-Formulare und Produkttext nach Nutzerreferenz.

Ein erster Lauf erkannte beim Abrechnungswechsel eine nicht uebernommene Diagnose. Nach Synchronisierung der gemeinsamen Leistungsdaten bestanden alle 39 Pruefungen.

Keine echten ERP-/WordPress-Daten veraendert, keine PHP-Aenderungen, keine GitHub-Actions-Laeufe, kein Commit/Push und kein Production-Build.
