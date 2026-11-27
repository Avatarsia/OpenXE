# E2E Tests: RepairIntegration

## Voraussetzungen

- OpenXE laeuft (Docker oder lokal) unter `$OPENXE_URL`
- MariaDB erreichbar unter `$OPENXE_DB_HOST`
- `install.php` wurde ausgefuehrt (DB-Tabellen + Seed-Daten)
- Fuer Outbound-Tests: WordPress mit Partner3D Repair Intake Plugin
- `curl` und `openssl` im PATH (fuer API-Tests)
- `mysql` CLI im PATH (fuer DB-Tests)

## Tests ausfuehren

### 1. DB-Migration pruefen

Verifiziert dass alle 6 Tabellen, 27 Status-Werte und kritische Spalten existieren.

```bash
OPENXE_DB_HOST=localhost OPENXE_DB_USER=openxe OPENXE_DB_PASS=openxe \
  bash tests/E2E/RepairIntegration/test_db_migration.sh
```

### 2. Inbound-API testen (WP -> OpenXE)

Testet HMAC-Auth, Validierung, Rate-Limiting und Payload-Verarbeitung.

```bash
OPENXE_URL=http://localhost:8081 SHARED_SECRET=dein_secret \
  bash tests/E2E/RepairIntegration/test_api_inbound.sh
```

### 3. Outbound-Sync testen (OpenXE -> WP)

Interaktiver Test: Statusaenderung in OpenXE -> Queue-Eintrag -> Sync an WP.

```bash
OPENXE_URL=http://localhost:8081 WP_URL=http://localhost:8080 \
  OPENXE_DB_HOST=localhost OPENXE_DB_USER=openxe OPENXE_DB_PASS=openxe \
  bash tests/E2E/RepairIntegration/test_api_outbound.sh
```

## Manuelle Checkliste

### Flow 0: Verbindungsdaten einrichten (WordPress-Verbindungs-Tab)

- [ ] Einstellungsseite oeffnen: Tab "WordPress-Verbindung" sichtbar
- [ ] Endpoint-URL zeigt Scheme+Host der aktuellen Instanz
- [ ] "Generieren" bei leerem Secret erzeugt 64-Hex-Zeichen-Wert
- [ ] "Neu generieren" bei vorhandenem Wert fragt per confirm() nach
- [ ] Nach dem Generieren Seite neu laden (F5): kein erneutes Rotieren des Secrets
- [ ] Auge-Toggle blendet Klartext ein/aus
- [ ] Copy-Button kopiert Klartext (auch ueber http — Fallback-Pfad)
- [ ] Werte ins WP-Plugin eintragen -> Inbound-Test-Push liefert 200

### Flow 1: WP-Formular -> E-Mail -> OpenXE Ticket

- [ ] WP-Formular ausfuellen (Reparatur, Bambu Lab X1C)
- [ ] Bestaetigungs-E-Mail an Kunden pruefen (kommt von WP)
- [ ] Team-E-Mail mit `[REP]` Tag im Betreff pruefen
- [ ] Team-E-Mail enthaelt `<!--REPAIR_DATA_START ... REPAIR_DATA_END-->` Block
- [ ] Ticketnummer ist 12-stellig (YYYYMMDDxxxx)
- [ ] OpenXE IMAP-Cronjob laeuft (oder manuell starten)
- [ ] Ticket erscheint in OpenXE
- [ ] Ticketregel hat Warteschlange/Projekt gesetzt
- [ ] `ticket_repair_details` wurde befuellt (SQL pruefen)
- [ ] Repair-Tab im Ticket-Edit zeigt Hersteller, Modell, Seriennummer
- [ ] Status-Dropdown zeigt Reparatur-spezifische Status (optgroup)

### Flow 2: Statusaenderung OpenXE -> WP

- [ ] Ticket-Status in OpenXE auf `in_diagnose` aendern
- [ ] `repair_sync_queue` hat neuen Eintrag (pending)
- [ ] Sync-Cronjob verarbeitet den Eintrag
- [ ] `repair_sync_log` zeigt success=1
- [ ] WP-Dashboard: Status zeigt "In Diagnose"
- [ ] Kunden-E-Mail wurde gesendet (notify_customer=1 fuer in_diagnose)

### Flow 3: Diagnose-Felder speichern

- [ ] Ticket oeffnen, Repair-Tab sichtbar
- [ ] Diagnose-Ergebnis, KV-Betrag, Kosten, Notizen eintragen
- [ ] Speichern klicken
- [ ] Ticket erneut oeffnen — Felder sind persistent

### Flow 4: Beleg-Erstellung

- [ ] Aus Ticket heraus "Angebot erstellen"
- [ ] Angebot wird in OpenXE angelegt mit Ticket-Referenz im Betreff
- [ ] `repair_ticket_beleg` hat Eintrag
- [ ] Ticket-Protokoll zeigt "Angebot #XXX erstellt"
- [ ] Angebot-Protokoll zeigt "Erstellt aus Ticket #YYY"

### Flow 5: Ticket-Merge

- [ ] Zwei Duplikat-Tickets vorhanden
- [ ] Repairintegration -> Zusammenfuehren
- [ ] Quell-Ticket auf "abgeschlossen" mit Verweis
- [ ] Alle Nachrichten beim Ziel-Ticket
- [ ] Nachrichten-Zaehler korrekt
- [ ] repair_details beim Ziel (oder uebertragen)
- [ ] Protokoll bei beiden Tickets

### Flow 6: DSGVO-Anonymisierung

- [ ] Testticket mit `abgeschlossen` Status und altem Datum erstellen
- [ ] retention_anonymize_years auf 0 setzen (fuer Sofort-Test)
- [ ] repair_retention.php Cronjob ausfuehren
- [ ] `ticket.kunde` = 'anonymisiert', `ticket.mailadresse` = ''
- [ ] `ticket_nachricht.text` = '[Anonymisiert nach Aufbewahrungsfrist]'
- [ ] `ticket_repair_details.anonymized_at` ist gesetzt
- [ ] `ticket_repair_details.manufacturer` und `.model` bleiben erhalten

## Fixtures

| Datei | Beschreibung |
|-------|-------------|
| `fixtures/sample_repair_email.html` | Reparatur-E-Mail mit JSON-Block (Bambu Lab X1C) |
| `fixtures/sample_wartung_email.html` | Wartungs-E-Mail mit JSON-Block (Prusa MK4S) |
| `fixtures/inbound_api_payload.json` | API-Payload fuer Inbound-Test |
