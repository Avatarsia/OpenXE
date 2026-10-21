| Datum | Tag | Fehler | Vermeidung |
|-------|-----|--------|------------|
| 2026-10-21 | php,sql | Download-Overrides ausserhalb des Artikel-Hashes wurden vom Autoexport uebersehen. | Bei Override-Aenderungen die shopbezogene Export-Aenderungskennung fuer Artikel und betroffene Parent-Artikel aktualisieren und echten Cron-Hash-Pfad testen. |
| 2026-10-21 | php,sql | Unescaped Unicode-JSON kann vierbyte Zeichen nicht in utf8mb3 speichern. | JSON fuer konfiguration mit Unicode-Escaping speichern und Emoji-Roundtrip testen. |
| 2026-10-21 | html,ui | Native URL-Validierung blockierte auch das Verwerfen ungueltiger Formulareingaben. | Reine Reset-/Fallback-Submitaktionen mit formnovalidate versehen; Schreibvalidierung fuer Speichern beibehalten. |
| 2026-10-21 | git | Unabhaengig angelegte NOT-TO-DO.md erzeugten add/add-Konflikte im Production-Build. | Fuer das gemeinsame Fehlerprotokoll merge=union nutzen und Erhalt aller Zeilen pruefen. |
| 2026-09-08 | php,sql | SQL PREPARE ueber Database::perform wird erneut prepared und bricht Migration 005 ab | Dynamische DDL nach information_schema-Pruefung direkt aus PHP ausfuehren |
| 2026-09-08 | php,test | Test-Stream lieferte nach EOF weiter Daten und erschoepfte Speicher | stream_read muss nach dem letzten Chunk einen leeren String liefern |
| 2026-10-19 | js | Platzhalter-Pruefung mit Lowercase-Regex ignorierte {Modell} | Alle Tokens in geschweiften Klammern erfassen und gegen erlaubte Variablen pruefen |
| 2026-10-19 | css | Absoluter Screenreader-Spaltentitel verbreiterte mobile Seite trotz scrollbarer Tabelle | Scrollcontainer als position:relative zum Containing Block machen |
| 2026-10-19 | js | Getrennte Abrechnungsentwuerfe verloren geaenderte Diagnose beim Szenariowechsel | Leistungsdaten am Vorgang halten und bestehende Szenarioentwuerfe synchronisieren; Beleg-Snapshots unveraendert lassen |
| 2026-10-19 | php,openxe | CSRF-Token nur im Session-Objekt erzeugt, naechster Request verwirft Formular | Nach Token-Erzeugung SessionHandler::commitSession aufrufen und Request-Wechsel testen |
| 2026-10-19 | php,openxe | Beleg-Link blieb nach nativem Entwurf-Loeschen bestehen und blockierte Neuanlage | Vor Wiederverwendung Existenz des Zielbelegs pruefen |
| 2026-10-19 | api,wp | WordPress form_id ist ein String-Slug, Integer-Cast vernichtet oder verfaelscht den Wert | Schnittstellentyp am Source-Schema pruefen und Slugs nicht als Integer uebertragen |
| 2026-10-19 | php,test | Alte Sync-Test-Fixture enthielt nur hook.id/name und scheiterte vor allen Tests an echter Hook-Migration | Hook-Testtabellen an Core-Schema mit alias/aktiv/Registrierungsspalten ausrichten |
| 2026-10-19 | php,js,openxe | Player laedt Assets der Basisklasse Ticket, Properties in TicketCustom werden ignoriert | Custom-Assets vor parent-Konstruktor ausdruecklich ueber ModuleScriptCache registrieren |
| 2026-10-19 | docker | Docker Desktop veroeffentlichte Host-Port auf internal-only Netzwerk nicht (Ports 80/tcp leer) | Lokalen Web-Teststand ueber normales Bridge-Netz und explizites 127.0.0.1-Portbinding erreichbar machen |
| 2026-10-19 | php,openxe | Weiterleitung auf action=positionen zeigte nur den internen Iframe ohne Belegrahmen | Nach Belegerstellung action=edit&id=...#tabs-2 verwenden |
| 2026-10-20 | php,pdf | nl2br erhaelt rohe Newlines neben br-Tags und verdoppelt Abstaende im OpenXE-PDF | CRLF/CR/LF direkt durch br ersetzen; Blockabsaetze erhalten und PDF rendern |
| 2026-10-20 | php,test | Beleg-Link-Testfixture ohne ticket_schluessel scheitert vor Verhaltenspruefung am echten Pflichtfeld | Fixtures fuer repair_ticket_beleg ueber RepairBelegGateway::link erzeugen |
| 2026-10-20 | php,sql,test | DMS-Fixture ohne Core-Pflichtfeld datei.geschuetzt verdeckte Importfehler unter Strict SQL | Aktuell upgegradetes Core-Schema im gekoppelten Test nutzen und Pflichtspalten explizit belegen |
| 2026-10-20 | powershell,test | Verschachtelte Quotes in Inline-Python und generiertem PHP-SQL verursachten Parserfehler | Mehrzeilige Scripts via literal Here-String schreiben und SQL-Fixture vor Verhaltenstest linten |
