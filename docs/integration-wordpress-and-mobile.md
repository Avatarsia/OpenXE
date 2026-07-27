# Integration: WordPress-Plugin (heute) und Mobile-App (Phase 2.5)

**Repo:** OpenXE-Fork (Worktree `C:\worktrees\openxe-repair-integration\`, Branch `feature/repair-integration-port`)
**Pfad dieser Datei:** `docs/integration-wordpress-and-mobile.md`

Diese Datei beschreibt das Modul `Xentral\Modules\RepairIntegration` aus Sicht der externen Schnittstellen:

- **Inbound von WP-Plugin:** Push neuer Reparatur-Anfragen / Status-Updates aus dem Plugin nach OpenXE (Phase 1, implementiert)
- **Outbound nach WP-Plugin:** Status-Echo wenn OpenXE-Tickets sich aendern (Phase 2, implementiert)
- **Inbound von Mobile-App:** Direkt-Anbindung der Werkstatt-App (Phase 2.5, Roadmap)

Cross-Links:

- WP-Plugin-Seite: `C:\Users\3D Partner\Desktop\Claude\Business\Reparaturformular\plugin\partner3d-repair-intake\docs\integration-openxe-and-mobile.md`
- Flutter-App-Seite: `C:\Users\3D Partner\Desktop\Claude\Business\Reparatur_app\docs\integration-wp-and-openxe.md`

---

## Datenfluss

```
Phase 1 + 2 (aktiv):

  +-------------+   POST   +--------+
  | WP-Plugin   | ------>  | OpenXE |   /repairapi/index.php/repair-status
  |             |          |        |   Bearer ODER HMAC-SHA256
  +-------------+          +--------+

  +-------------+  Bearer  +--------+
  | WP-Plugin   | <------  | OpenXE |   POST p3d/v1/requests/status
  |             |          |        |   Queue (repair_sync_queue) + Cron
  +-------------+          +--------+


Phase 2.5 (Roadmap):

  +---------+    Bearer    +--------+
  | Android |  -------->   | OpenXE |
  |  App    |  <--------   |        |
  +---------+   neue       +--------+
              Mobile-API
              (zu bauen)
```

---

## (A) Inbound von WP — `Phase 1, implementiert`

WP-Plugin pusht bei jeder Status-Aenderung einer Reparatur-Anfrage einen JSON-Payload an OpenXE. OpenXE legt — falls noch nicht vorhanden — ein Ticket (`ticket`-Tabelle) inklusive Initial-Nachricht (`ticket_nachricht`) an und speichert / aktualisiert die Reparatur-Details in `repair_details` (Gateway: `RepairDetailsGateway`).

**Quelle:**
- Entry-Point: `www/repairapi/index.php` (kein Session-/Login-Layer, eigener Bootstrap mit DB-Container)
- Alternativer Entry-Point: `www/pages/repairapi.php` (laeuft ueber regulaeren Action-Handler-Stack)
- Controller: `classes/Modules/RepairIntegration/Api/RepairApiController.php`
- Auth: `classes/Modules/RepairIntegration/Api/RepairApiAuth.php`
- Fixture: `tests/E2E/RepairIntegration/fixtures/inbound_api_payload.json`

### Endpoint

| Method | URL                                                | Auth                                              |
|--------|----------------------------------------------------|---------------------------------------------------|
| POST   | `/repairapi/index.php/repair-status`               | `Bearer <token>` ODER HMAC-SHA256 (siehe unten)   |
| POST   | `/repairapi/index.php?action=push_details`         | gleiche Auth, alternative Routing-Variante        |

Path-Routing (`/repair-status`) wurde ergaenzt, damit das WP-Plugin den vom Plugin generierten Pfad direkt aufrufen kann (`Apache: AcceptPathInfo on`).

### Auth-Schema

```
Content-Type: application/json
```

**Variante 1 — HMAC (bevorzugt):**
```
X-Signature: <hmac-sha256(timestamp + "." + raw-body, <hmac-secret>)>
X-Timestamp: <unix-timestamp>
```

- Timestamp-Toleranz: 300 Sekunden (`RepairApiAuth::TIMESTAMP_TOLERANCE`)
- Vergleich via `hash_equals` (timing-safe)
- Wenn `<hmac-secret>` (DB-Feld `repair_inbound_shared_secret`, gelesen ueber `RepairConfigService`) nicht konfiguriert → `AuthenticationException: SHARED_SECRET_NOT_CONFIGURED`

**Variante 2 — Bearer (Fallback, WP-Plugin-Compat):**
```
Authorization: Bearer <bearer-token>
```

- Token-Compare via `hash_equals` mit demselben `<hmac-secret>` Konfigurations-Wert
- Nur aktiv wenn `X-Signature` / `X-Timestamp` Header fehlen

### Rate-Limit

60 Requests / Minute / Remote-IP (Tabelle `repair_api_ratelimit`, Window-Key `YmdHi`). Bei Ueberschreitung HTTP 403 `RATE_LIMIT_EXCEEDED`.

### Payload-Schema

Pflicht-Felder:
- `request_number` (string, max 20 chars)
- `service_type` (`reparatur` | `wartung` | `reverse_engineering` | `individualisierung`)

Optional:
- `status` (string, max 30 chars) — WP-Slug, siehe [Status-Uebernahme](#status-uebernahme-inbound). Unbekannte Werte sind kein Fehler.
- `customer_quote_amount` (Zahl oder numerischer String) — vom Kunden im WP-Frontend freigegebener KVA-Preis. Wird in `ticket_repair_details.customer_quote_amount` gespeichert und im Repair-Panel read-only angezeigt. Tolerant geparst (auch deutsche Schreibweise); ungueltige Werte werden ignoriert (error_log), nie mit 400 abgelehnt. **WP-Plugin muss das Feld ab dem naechsten Plugin-Release liefern** — aeltere Plugin-Versionen senden es nicht, das Feld bleibt dann `NULL`.

Feldnamen-Aliase (das Plugin sendet je nach Version die kurze oder lange Variante, `mapInboundData()` akzeptiert beide):

| Kanonisch                | Alias              |
|--------------------------|--------------------|
| `device.serial_number`   | `device.serial`    |
| `service_delivery_type`  | `service_delivery` |

Beispiel (siehe `tests/E2E/RepairIntegration/fixtures/inbound_api_payload.json`):

```json
{
  "request_number": "202604050001",
  "status": "new",
  "service_type": "reparatur",
  "service_delivery_type": "einsendung",
  "customer_quote_amount": "149.90",
  "customer": {
    "name": "Max Mustermann",
    "email": "max@example.com",
    "phone": "+49 471 12345678",
    "company": null,
    "vat_id": null,
    "address": {
      "street": "Rudloffstr.",
      "house_number": "111",
      "postal_code": "27568",
      "city": "Bremerhaven",
      "country": "DE"
    }
  },
  "device": {
    "manufacturer": "Bambu Lab",
    "model": "X1 Carbon",
    "serial_number": "BL-2024-123456",
    "mods_present": false,
    "mods_text": null
  },
  "service_details": {
    "issue_category": "print_quality",
    "issue_description": "Druckqualitaet verschlechtert nach 500h",
    "warranty_status": "no",
    "cost_limit": "200",
    "is_express": false
  }
}
```

Body-Max: 65536 Bytes (`MAX_PAYLOAD_SIZE`).

### Response

- `200 OK`: `{ "success": true }`
- `400`: `{ "success": false, "error": "INVALID_JSON" | "UNSUPPORTED_MEDIA_TYPE" | "Missing required field: <name>" | "Invalid service_type" | "PAYLOAD_TOO_LARGE" }`
- `401`: `{ "success": false, "error": "MISSING_AUTH" | "INVALID_BEARER_TOKEN" | "INVALID_SIGNATURE" | "TIMESTAMP_EXPIRED" | "SHARED_SECRET_NOT_CONFIGURED" }`
- `403`: `{ "success": false, "error": "RATE_LIMIT_EXCEEDED" }`
- `500`: `{ "success": false, "error": "INTERNAL_ERROR" }`

### Ticket-Anlage

Wenn kein Ticket mit `schluessel = <request_number>` existiert, wird automatisch eines angelegt (siehe `createTicketFromPayload`):

| Ticket-Feld     | Quelle                                              |
|-----------------|-----------------------------------------------------|
| `schluessel`    | `data.request_number`                                |
| `quelle`        | `'api'`                                              |
| `status`        | aus `data.status` gemappt, sonst `'neu'` (siehe unten) |
| `betreff`       | `[REP|WRT|REV|IND] Ticket #<nr> - <Hersteller> <Modell>` |
| `kunde`         | `"<name> <<email>>"`                                  |
| `mailadresse`   | `data.customer.email`                                 |
| `firma`         | `data.customer.company` oder leer                     |
| `prio`          | `3`                                                   |
| `notiz`         | `"Automatisch erstellt via WP API Push (<service_type>)"` |

Anschliessend wird die `issue_description` als erste `ticket_nachricht` geschrieben, und `nachrichten_anz` aktualisiert.

Reparatur-Details landen in `repair_details` via `RepairDetailsGateway::create()` bzw. `update()`.

### Status-Uebernahme (Inbound)

Das optionale Feld `status` im Payload wird **ausschliesslich bei der Ticket-Anlage** ausgewertet. Danach besitzt OpenXE den Workflow: bei einem Push auf ein bereits existierendes Ticket bleibt `ticket.status` unveraendert, es werden nur die Reparatur-Details aktualisiert. Sonst wuerde ein verspaeteter WP-Push einen in OpenXE weitergedrehten Vorgang zuruecksetzen.

Ablauf bei Neuanlage (`RepairApiController::processPushDetails()`):

1. `RepairApiController::normalizeWpStatus()` — reine Funktion: trim, lowercase, Pattern `^[a-z0-9_]+$`, max 30 Zeichen. Ergebnis `null` = nicht verwertbar.
2. `ServiceType::tryFrom(service_type)?->statusCategory()` liefert die Kategorie (`repair` | `maintenance` | `reverse_engineering` | `individualization`).
3. `RepairStatusConfigGateway::getByWpMapping($wpStatus, $category)` sucht in `ticket_status_config` die aktive Zeile mit passendem `wp_status_mapping`. Ein WP-Slug ist mehrdeutig (`in_repair` existiert in allen vier Kategorien), daher die Aufloesung ueber die Kategorie; kategorie-spezifische Zeilen gewinnen gegen `general`.
4. Kein Treffer → Fallback `'neu'`.

Validierung: `status` wird **nicht** hart geprueft und kann einen Request nie mit HTTP 400 abweisen — das Feld ist beratend, ein kaputter Wert darf die Ticket-Anlage nicht verhindern. Nicht verwertbare Werte (Nicht-String, leer, ueber 30 Zeichen, unerlaubte Zeichen) verhalten sich wie ein fehlendes Feld: `normalizeWpStatus()` liefert `null`, es greift der Fallback `'neu'`. Ein **wohlgeformter, aber unbekannter** Slug ist ebenfalls kein Fehler — der Request wird mit Fallback verarbeitet und der `repair_sync_log`-Eintrag der Neuanlage vermerkt es:

```
TICKET_CREATED (WP-Status "some_future_status" ohne Mapping, Fallback "neu")
```

Aufloesungs-Matrix (Stand Seed 1.2.0, `-` = Fallback `neu`):

| WP-Status        | reparatur      | wartung          | reverse_engineering | individualisierung |
|------------------|----------------|------------------|---------------------|--------------------|
| `new`            | `neu`          | `neu`            | `neu`               | `neu`              |
| `in_diagnosis`   | `in_diagnose`  | -                | `re_analyse`        | `ind_planung`      |
| `quote_sent`     | `kv_gesendet`  | -                | `re_angebot`        | `ind_angebot`      |
| `quote_declined` | `kv_abgelehnt` | -                | `re_abgelehnt`      | `ind_abgelehnt`    |
| `approved`       | `freigegeben`  | -                | `re_freigabe`       | `ind_freigabe`     |
| `in_repair`      | `in_reparatur` | `wartung_laeuft` | `re_umsetzung`      | `ind_fertigung`    |
| `repaired`       | `repariert`    | `wartung_fertig` | `re_fertig`         | `ind_fertig`       |
| `returned`       | `versendet`    | -                | -                   | -                  |
| `closed`         | `abgeschlossen`| `abgeschlossen`  | `abgeschlossen`     | `abgeschlossen`    |

`versendet` liegt in Kategorie `repair`, wird aber von den `*_fertig`-Status aller Kategorien als `next_status_slug` genutzt. Ein `returned`-Push auf ein Wartungs-/RE-/IND-Ticket findet daher kein kategorie-eigenes Ziel und faellt auf `neu` zurueck — in der Praxis irrelevant, weil ein Rueckversand nach der Anlage passiert und Inbound-Status nur bei der Anlage greift.

### Audit-Log

Jeder Request (Erfolg und Fehler) wird in `repair_sync_log` mit `direction = 'inbound'`, `action = 'push_details'` geloggt, inkl. Remote-IP und gekuerztem Payload.

---

## (B) Outbound nach WP (Status-Echo) — `Phase 2, implementiert`

Wenn ein OpenXE-Mitarbeiter den Ticket-Status aendert (z.B. "In Reparatur" → "Repariert"), pusht OpenXE den Status-Change zurueck ins WP-Plugin, damit der Kunde im `frontend-status-lookup.php` aktuelle Daten sieht.

**Quelle:**
- Trigger: `www/pages/ticket_custom.php::ticket_edit()` instanziiert nach dem Speichern direkt `classes/Modules/RepairIntegration/Hook/TicketStatusChangeHook.php::onTicketEditAfter($ticketId, $oldStatus)` (keine `hook_register`-Registrierung — die frueheren, toten `ticket_edit_after`/`ticket_list_after`-Eintraege entfernt `install.php` self-healing per DELETE)
- Service: `classes/Modules/RepairIntegration/Service/RepairSyncService.php`
- Queue-Gateway: `classes/Modules/RepairIntegration/Gateway/RepairSyncQueueGateway.php`
- Cron: `cronjobs/repair_sync.php` (Prozessstarter-Parameter `repair_sync`, einmal taeglich, periode 1440, Mutex-geschuetzt)
- Mapping-Quelle: `ticket_status_config.wp_status_mapping` ueber `RepairStatusConfigGateway::getWpMapping()`

### Ablauf

1. `TicketStatusChangeHook::onTicketEditAfter($ticketId, $oldStatus)` vergleicht alten und aktuellen `ticket.status`; bei Gleichstand passiert nichts.
2. `RepairSyncService::checkAndQueueStatusChange($ticketId)` bricht ab, wenn das Modul deaktiviert ist, keine `repair_details` existieren oder `wp_request_number` leer ist.
3. `getWpMapping(ticket.status)` liefert den WP-Slug. **Ist das Mapping `NULL`, wird nicht synchronisiert** — das ist der bewusste Weg, um interne Status (z.B. `offen`, `warten_e`, `wartung_geplant`) vor dem Kunden-Frontend zu verbergen.
4. Der Payload wird in `repair_sync_queue` eingereiht (`action = 'status_change'`, Ziel-URL aus `RepairConfigService::getWpApiUrl()` + `/wp-json/p3d/v1/requests/status`).
5. `cronjobs/repair_sync.php` ruft `RepairSyncService::processQueue()` (max. 50 Eintraege pro Lauf), Ergebnis landet in `repair_sync_log` (`direction = 'outbound'`).

### Ziel-Endpoint (im WP-Plugin)

| Method | URL                                                        | Auth              |
|--------|------------------------------------------------------------|-------------------|
| POST   | `https://partner-3d.de/wp-json/p3d/v1/requests/status`     | `Bearer <token>`  |

Header: `Content-Type: application/json`, `Authorization: Bearer <wp_api_key>`, `X-Repair-Source: openxe`.

Body:
```json
{ "request_number": "2026-0431", "status": "in_repair" }
```

Response 200:
```json
{ "success": true, "old_status": "in_diagnosis", "new_status": "in_repair", "audit_logged": true }
```

`status` muss in der WP-Allowlist sein (siehe Plugin-`Helpers::get_status_labels()`): `new`, `in_diagnosis`, `quote_sent`, `quote_declined`, `approved`, `in_repair`, `repaired`, `returned`, `closed`.

### Retry / Backoff

`RepairSyncService::RETRY_DELAYS` = `[120, 600, 1800, 7200, 28800]` Sekunden (2 min, 10 min, 30 min, 2 h, 8 h). Nach `max_retries` (Default 5) geht der Eintrag auf `permanently_failed`. Jeder Versuch schreibt `last_error` + `last_http_code` in `repair_sync_queue`; die UI dazu liegt unter `index.php?module=repairintegration&action=syncstatus`.

### Status-Mapping-Tabelle

Gepflegt in `ticket_status_config`, ausgeliefert von `Migration/sql/002_seed_status_config.sql` (aktuelle Schema-Version 1.3.0). `wp_status_mapping = NULL` bedeutet: kein Outbound-Push. `notify_customer = 1` steuert `RepairEmailService::shouldSendNotification()` — der eigentliche Mailversand ist noch nicht verdrahtet (der Hook bereitet ihn nur vor, siehe Kommentar in `TicketStatusChangeHook`), das Flag ist heute also reine Konfiguration.

| Slug              | Label                                  | Kategorie             | Sort | `wp_status_mapping` | `next_status_slug` | notify |
|-------------------|----------------------------------------|-----------------------|------|---------------------|--------------------|--------|
| `neu`             | Neu                                    | general               | 10   | `new`               | `offen`            | 0      |
| `offen`           | Offen                                  | general               | 20   | -                   | -                  | 0      |
| `warten_e`        | Warten auf Intern                      | general               | 30   | -                   | -                  | 0      |
| `warten_kd`       | Warten auf Kunde                       | general               | 40   | -                   | -                  | 0      |
| `klaeren`         | Klaeren                                | general               | 50   | -                   | -                  | 0      |
| `beantwortet`     | Beantwortet                            | general               | 60   | -                   | -                  | 0      |
| `in_diagnose`     | In Diagnose                            | repair                | 100  | `in_diagnosis`      | `kv_gesendet`      | 1      |
| `kv_gesendet`     | Kostenvoranschlag gesendet             | repair                | 110  | `quote_sent`        | `freigegeben`      | 1      |
| `kv_abgelehnt`    | Kostenvoranschlag abgelehnt            | repair                | 115  | `quote_declined`    | `versendet`        | 1      |
| `freigegeben`     | Freigegeben                            | repair                | 120  | `approved`          | `in_reparatur`     | 1      |
| `in_reparatur`    | In Reparatur                           | repair                | 130  | `in_repair`         | `repariert`        | 0      |
| `repariert`       | Repariert                              | repair                | 140  | `repaired`          | `versendet`        | 1      |
| `versendet`       | Versendet                              | repair                | 150  | `returned`          | `abgeschlossen`    | 1      |
| `wartung_geplant` | Wartung geplant                        | maintenance           | 200  | -                   | `wartung_laeuft`   | 1      |
| `wartung_laeuft`  | Wartung laeuft                         | maintenance           | 210  | `in_repair`         | `wartung_fertig`   | 0      |
| `wartung_fertig`  | Wartung abgeschlossen                  | maintenance           | 220  | `repaired`          | `versendet`        | 1      |
| `re_analyse`      | RE: Analyse                            | reverse_engineering   | 300  | `in_diagnosis`      | `re_angebot`       | 1      |
| `re_angebot`      | RE: Angebot erstellt                   | reverse_engineering   | 310  | `quote_sent`        | `re_freigabe`      | 1      |
| `re_abgelehnt`    | RE: Angebot abgelehnt                  | reverse_engineering   | 315  | `quote_declined`    | `versendet`        | 1      |
| `re_freigabe`     | RE: Freigegeben                        | reverse_engineering   | 320  | `approved`          | `re_umsetzung`     | 1      |
| `re_umsetzung`    | RE: In Umsetzung                       | reverse_engineering   | 330  | `in_repair`         | `re_fertig`        | 0      |
| `re_fertig`       | RE: Fertig                             | reverse_engineering   | 340  | `repaired`          | `versendet`        | 1      |
| `ind_planung`     | Individualisierung: Planung            | individualization     | 400  | `in_diagnosis`      | `ind_angebot`      | 1      |
| `ind_angebot`     | Individualisierung: Angebot            | individualization     | 410  | `quote_sent`        | `ind_freigabe`     | 1      |
| `ind_abgelehnt`   | Individualisierung: Angebot abgelehnt  | individualization     | 415  | `quote_declined`    | `versendet`        | 1      |
| `ind_freigabe`    | Individualisierung: Freigegeben        | individualization     | 420  | `approved`          | `ind_fertigung`    | 1      |
| `ind_fertigung`   | Individualisierung: In Fertigung       | individualization     | 430  | `in_repair`         | `ind_fertig`       | 0      |
| `ind_fertig`      | Individualisierung: Fertig             | individualization     | 440  | `repaired`          | `versendet`        | 1      |
| `abgeschlossen`   | Abgeschlossen                          | general               | 900  | `closed`            | -                  | 0      |
| `spam`            | Papierkorb                             | general               | 999  | -                   | -                  | 0      |

Bewusst ohne Mapping: die `general`-Arbeitsstatus (`offen`, `warten_e`, `warten_kd`, `klaeren`, `beantwortet`, `spam`) und `wartung_geplant` — eine geplante Wartung ist keine Diagnose, ein WP-Echo waere irrefuehrend.

### Deploy-Reihenfolge — WP-Plugin zuerst

> **Wichtig:** Die WP-Plugin-Version, die die Slugs `in_repair` und `quote_declined` kennt (**v3.29.0**), muss **vor oder zusammen mit** dieser OpenXE-Version deployed werden. Ein spaeter nachgezogenes Plugin verliert Status-Echos dauerhaft.

Schema-Version 1.1.0 ergaenzt `wp_status_mapping`-Eintraege fuer `quote_declined` (`kv_abgelehnt`, `re_abgelehnt`, `ind_abgelehnt`) und `in_repair` (`wartung_laeuft`, `re_umsetzung`, `ind_fertigung`). Sobald ein Ticket in einen dieser Status wechselt, stellt `TicketStatusChangeHook` einen Outbound-Eintrag in `repair_sync_queue`.

Kennt das Ziel-Plugin einen dieser Slugs noch nicht, antwortet es mit einem non-2xx-Code. `RepairSyncService` wiederholt den Versuch entlang `RETRY_DELAYS` (2 min, 10 min, 30 min, 2 h) und setzt den Eintrag nach `max_retries` (Default 5, in Summe also nach ca. 2 Stunden 42 Minuten) endgueltig auf `permanently_failed`. Von dort wird **nicht** automatisch erneut zugestellt: die Sync-Status-Seite (`index.php?module=repairintegration&action=syncstatus`) zeigt `permanently_failed` nur als Zaehler, eine Wiedervorlage-Aktion gibt es nicht — betroffene Eintraege muessten in `repair_sync_queue` von Hand zurueckgesetzt werden.

### Migration bestehender Installationen

Der Seed (`002_...`) laeuft nur bei Erstinstallation (`RepairIntegrationMigration::needsInstall()`). Bestehende Installationen bekommen Mapping-Aenderungen, neue Status und neue Spalten ueber die Upgrade-Kette `Migration/sql/003_*.sql` bis `005_*.sql` (1.3.0: `005_customer_quote_amount.sql` ergaenzt `ticket_repair_details.customer_quote_amount`, idempotent per information_schema-Check):

- `RepairIntegrationMigration::needsUpgrade()` vergleicht die in `systemconfig` (`repair_integration.schema_version`) gespeicherte Version mit `SCHEMA_VERSION`
- `upgrade()` fuehrt das Upgrade-SQL aus und schreibt die neue Version
- Ausgeloest via `classes/Modules/RepairIntegration/install.php` (Action `index.php?module=repairintegration&action=install`) **und** automatisch beim ersten Seitenaufruf des Moduls (`repairintegration.php::ensureInstalled()`)
- Idempotent: `INSERT IGNORE` fuer neue Zeilen, `UPDATE`s eng auf das jeweilige alte Seed-Wertepaar begrenzt (`WHERE slug = 'x' AND wp_status_mapping IS NULL`), damit vom Admin angepasste Zeilen nicht ueberschrieben werden

### Reduce-Coupling-Note

Outbound nutzt aktuell den Bearer-Token (`RepairConfigService::getWpApiKey()`), weil das Plugin ihn in beide Richtungen akzeptiert. Perspektivisch sollte auch Outbound **HMAC** verwenden (`RepairApiAuth::generateSignature()` ist wiederverwendbar), damit ein Leak des Bearer-Tokens allein nicht ausreicht — ein Angreifer braucht zusaetzlich das HMAC-Secret und einen aktuellen Timestamp.

---

## (C) Inbound von der Mobile-App — `Phase 2.5, geplant`

Heute spricht die Flutter-App ausschliesslich das WP-Plugin. Endziel ist `Android → OpenXE` direkt. OpenXE braucht dafuer einen neuen Endpoint-Satz, idealerweise unter `/repairapi/index.php/mobile/*` (oder als eigenes Modul `MobileApi`).

### Vorgeschlagene Endpoints

Alle mit Header `X-Repair-Token: <64-hex>`, Rate-Limit pro Token (60/min, analog WP), HTTPS-only.

| Phase | Method | Pfad                                            | Zweck                                                   |
|-------|--------|-------------------------------------------------|---------------------------------------------------------|
| 2.5   | GET    | `/repairapi/index.php/mobile/repair`            | Single-Vorgang per Token — Detail-View (read)           |
| 2.5   | POST   | `/repairapi/index.php/mobile/repair/status`     | Status setzen — schreibt `ticket.status` + Audit         |
| 2.5   | POST   | `/repairapi/index.php/mobile/repair/note`       | Notiz hinzufuegen — `ticket_nachricht` Eintrag           |
| 2.5   | POST   | `/repairapi/index.php/mobile/repair/image`      | Foto-Upload (multipart) — `ticket_dateien` / S3         |
| 3     | GET    | `/repairapi/index.php/mobile/tickets`           | Liste offener Tickets (Mitarbeiter-Token statt Vorgangs-Token) |

### Token-Strategie

- **Token-Tabelle:** neue `repair_mobile_token` Tabelle (`id`, `ticket_id`, `token` UNIQUE 64-hex, `kind` ENUM `vorgang|mitarbeiter`, `created_at`, `revoked_at`, `last_used_at`)
- **Generierung:** beim WP→OpenXE-Push erzeugt OpenXE pro neuem Ticket einen Mobile-Token und gibt ihn in der Response zurueck. WP-Plugin speichert den Token in `staff_qr_token` und druckt ihn auf den Beleg.
- **Vergleich:** `hash_equals` timing-safe (siehe `RepairApiAuth::validateRequest`)
- **Format:** `/^[a-f0-9]{64}$/` (64-hex statt der heutigen WP-32-hex — Migration plant `api-contract.md` der App)

### Response-Format

JSON, analog zum App-`api-contract.md`-Vorschlag. App-Modelle (`RepairDetail`, `Note`, `RepairImage`) bleiben gleich — Mapper passt sich nur OpenXE-Feldnamen an.

Beispiel `GET /repairapi/index.php/mobile/repair`:

```json
{
  "id": 431,
  "request_number": "2026-0431",
  "status": "in_diagnose",
  "status_label": "In Diagnose",
  "created_at": "2026-05-21T09:14:00+02:00",
  "service_type": "reparatur",
  "device": { "manufacturer": "Bambu Lab", "model": "P1S", "serial_number": "01PA12345" },
  "customer": { "name": "Max Mustermann", "email": "max@example.de", "phone": "+49 471 1234567" },
  "issue_description": "X-Achse blockiert, Bett krumm",
  "notes": [
    { "id": 17, "ts": "2026-05-22T14:02:00+02:00", "verfasser": "Sven", "text": "Bett geprueft, OK" }
  ],
  "images": [
    { "id": 9, "url": "https://internal-lan/openxe/files/9aF7.../front.jpg", "uploaded_at": "2026-05-22T11:50:00+02:00" }
  ],
  "available_status_transitions": [
    { "key": "waiting_customer", "label": "Wartet auf Kundenfreigabe" },
    { "key": "in_repair",        "label": "In Reparatur" }
  ]
}
```

### Hinweise zur Implementierung

- Neuer Bootstrap unter `www/repairapi/index.php` ergaenzen (Routing-Tabelle mit `/mobile/...`-Pfaden)
- KEIN Login-/Session-Layer — Token-Auth wie Inbound-Seite
- Audit-Log in `repair_sync_log` mit `direction = 'mobile_in'`, `action = 'status_change' | 'note' | 'image'`
- Image-Upload: gleiche Sanitization-Regeln wie WP-`UploadHandler` (Mime-Whitelist, Magic-Bytes, Size-Limit). Idealerweise in einen randomisierten Ordner schreiben.

---

## Sicherheitsregeln (alle Phasen)

- Keine Secrets in dieser Datei. `<bearer-token>` / `<hmac-secret>` werden ueber `RepairConfigService` aus der DB gelesen — Bestueckung via OpenXE-Settings-UI.
- Server-Clock per NTP synchron halten (HMAC-Toleranz nur 300 Sekunden)
- Bearer-Token niemals in `error_log` / `repair_sync_log` ausgeben — Controller loggt nur Payload + Error-Klasse
- Remote-IP wird normalisiert via `$_SERVER['REMOTE_ADDR']` — bei OpenXE hinter Reverse-Proxy muss `trusted_proxies` korrekt konfiguriert sein, sonst greift Rate-Limit IP-uebergreifend
- Mobile-Endpoints (Phase 2.5): pro-Token Rate-Limit ergaenzen (nicht nur pro-IP) — sonst kann ein gestohlener Token aus mehreren IPs missbraucht werden
