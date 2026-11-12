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
- `media_urls` (Array) — Kunden-Medien (Bilder/Videos), werden heruntergeladen und als Dateien an die erste Ticket-Nachricht gehaengt. Eintraege als blanke URL (`"https://..."`) oder als Objekt `{ "url": "...", "media_id": 123 }`. `media_id` ist optional, aber **empfohlen**: die stabile WP-Attachment-ID wird als Idempotenz-Marker (`WP-REPAIR-MEDIA-ID-<id>` in `datei.nummer`) genutzt und ueberlebt einen URL-Wechsel der WP-Uploads; ohne `media_id` gilt der Legacy-Marker `sha1(url)` (Duplikat-Importe bei URL-Wechsel). **WP-Plugin muss `media_id` ab dem naechsten Plugin-Release liefern.** Erlaubte MIME-Typen: `image/jpeg`, `image/png`, `image/webp`, `video/mp4`, `video/quicktime`; max. 20 Anhaenge (inkl. `document_url`), max. 25 MB pro Datei.
- `document_url` (String oder Objekt wie bei `media_urls`) — zusaetzliches Dokument (z.B. HTML-Zusammenfassung), zusaetzlich erlaubter MIME-Typ `text/html`.

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
3. `RepairStatusConfigGateway::getByWpMapping($wpStatus, $category)` sucht in `ticket_status_config` die aktive Zeile mit passendem `wp_status_mapping`. Ein WP-Slug ist mehrdeutig (`in_progress` existiert in allen vier Kategorien); explizite Kategorie-Defaults loesen ihn auf.
4. Kein Treffer → Fallback `'neu'`.

Validierung: `status` wird **nicht** hart geprueft und kann einen Request nie mit HTTP 400 abweisen — das Feld ist beratend, ein kaputter Wert darf die Ticket-Anlage nicht verhindern. Nicht verwertbare Werte (Nicht-String, leer, ueber 30 Zeichen, unerlaubte Zeichen) verhalten sich wie ein fehlendes Feld: `normalizeWpStatus()` liefert `null`, es greift der Fallback `'neu'`. Ein **wohlgeformter, aber unbekannter** Slug ist ebenfalls kein Fehler — der Request wird mit Fallback verarbeitet und der `repair_sync_log`-Eintrag der Neuanlage vermerkt es:

```
TICKET_CREATED (WP-Status "some_future_status" ohne Mapping, Fallback "neu")
```

Aufloesungs-Matrix (Schema 1.12.0, WP v3.41.0):

| WP-Status | repair | maintenance | reverse_engineering | individualization |
|---|---|---|---|---|
| `new` | `neu` | `neu` | `neu` | `neu` |
| `received` | `eingegangen` | `eingegangen` | `eingegangen` | `eingegangen` |
| `in_progress` | `in_diagnose` | `wartung_laeuft` | `re_analyse` | `ind_planung` |
| `quote_sent` | `kv_gesendet` | kein Angebotsstatus | `re_angebot` | `ind_angebot` |
| `completed` | `repariert` | `wartung_fertig` | `re_fertig` | `ind_fertig` |
| `closed` | `abgeschlossen` | `abgeschlossen` | `abgeschlossen` | `abgeschlossen` |
| `cancelled` | `storniert` | `storniert` | `storniert` | `storniert` |

Bei v2/Manifest-Abgleich bleibt ein bereits vorhandener OpenXE-Status mit
identischem WP-Mapping erhalten (z.B. `in_reparatur` bei `in_progress` oder
`versendet` bei `completed`). Erst bei anderer Projektion gilt der Kategorie-Default.
Der v1-Details-Push behaelt seine bisherige Anlage-/Workflow-Ownership-Semantik.

Legacy-Keys werden eingehend toleriert: `in_diagnosis`, `approved`, `in_repair`
werden `in_progress`; `quote_declined`, `repaired`, `returned` werden `completed`.
`approved` setzt zusaetzlich `quote_decision=approved`, `quote_declined` setzt
`declined`, sofern kein expliziter Entscheidungs-Key vorhanden ist.
Ausgehend werden auch alte Queue-Payloads auf die neuen Keys normalisiert.

### Audit-Log

Jeder Request (Erfolg und Fehler) wird in `repair_sync_log` mit `direction = 'inbound'`, `action = 'push_details'` geloggt, inkl. Remote-IP und gekuerztem Payload.

---

## (B) Outbound nach WP (Status-Echo) — `Phase 2, implementiert`

Wenn ein OpenXE-Mitarbeiter den Ticket-Status aendert (z.B. "In Reparatur" → "Repariert"), pusht OpenXE den Status-Change zurueck ins WP-Plugin, damit der Kunde im `frontend-status-lookup.php` aktuelle Daten sieht.

**Quelle:**
- Trigger: `www/pages/ticket_custom.php::ticket_edit()` instanziiert nach dem Speichern direkt `classes/Modules/RepairIntegration/Hook/TicketStatusChangeHook.php::onTicketEditAfter($ticketId, $oldStatus)` (keine `hook_register`-Registrierung — die frueheren, toten `ticket_edit_after`/`ticket_list_after`-Eintraege entfernt `install.php` self-healing per DELETE)
- Service: `classes/Modules/RepairIntegration/Service/RepairSyncService.php`
- Queue-Gateway: `classes/Modules/RepairIntegration/Gateway/RepairSyncQueueGateway.php`
- Cron: `cronjobs/repair_sync.php` (Prozessstarter-Parameter `repair_sync`, alle 2 Minuten, periode 2, Mutex-geschuetzt)
- Mapping-Quelle: `ticket_status_config.wp_status_mapping` ueber `RepairStatusConfigGateway::getWpMapping()`

### Ablauf

1. `TicketStatusChangeHook::onTicketEditAfter($ticketId, $oldStatus)` vergleicht alten und aktuellen `ticket.status`; bei Gleichstand passiert nichts.
2. `RepairSyncService::queueAndPushStatusChange($ticketId)` bricht ab, wenn das Modul deaktiviert ist, keine `repair_details` existieren oder `wp_request_number` leer ist.
3. `getWpMapping(ticket.status)` liefert den WP-Slug. **Ist das Mapping `NULL`, wird nicht synchronisiert** — das ist der bewusste Weg, um interne Status (z.B. `offen`, `warten_e`, `wartung_geplant`) vor dem Kunden-Frontend zu verbergen.
4. Der Payload wird in `repair_sync_queue` eingereiht (`action = 'status_change'`, Ziel-URL aus `RepairConfigService::getWpApiUrl()` + `/wp-json/p3d/v1/requests/status`) und **noch im Speicher-Request synchron zugestellt** (Timeout 8 s). Schlaegt das fehl, bleibt der Eintrag als `failed` mit `next_retry_at` stehen.
5. `cronjobs/repair_sync.php` ruft `RepairSyncService::processQueue()` (max. 50 Eintraege pro Lauf) fuer Retries und Backfill, Ergebnis landet in `repair_sync_log` (`direction = 'outbound'`).
6. `wp_api_url` muss der kanonische Host ohne Redirect sein (z.B. `https://www.partner-3d.de`, nicht `https://partner-3d.de` — dort antwortet nginx mit 301 und der Push kommt nie beim Plugin an).

### Ziel-Endpoint (im WP-Plugin)

| Method | URL                                                        | Auth              |
|--------|------------------------------------------------------------|-------------------|
| POST   | `https://partner-3d.de/wp-json/p3d/v1/requests/status`     | `Bearer <token>`  |

Header: `Content-Type: application/json`, `Authorization: Bearer <wp_api_key>`, `X-Repair-Source: openxe`.

Body:
```json
{ "request_number": "2026-0431", "status": "in_progress" }
```

Response 200:
```json
{ "success": true, "old_status": "in_progress", "new_status": "in_progress", "audit_logged": true }
```

`status` muss in der WP-Allowlist sein (siehe Plugin-`Helpers::get_status_labels()`): `new`, `received`, `in_progress`, `quote_sent`, `completed`, `closed`, `cancelled`.

### Retry / Backoff

`RepairSyncService::RETRY_DELAYS` = `[120, 600, 1800, 7200, 28800]` Sekunden (2 min, 10 min, 30 min, 2 h, 8 h). Nach `max_retries` (Default 5) geht der Eintrag auf `permanently_failed`. Jeder Versuch schreibt `last_error` + `last_http_code` in `repair_sync_queue`; die UI dazu liegt unter `index.php?module=repairintegration&action=syncstatus`.

### Status-Mapping-Tabelle

Gepflegt in `ticket_status_config`, ausgeliefert von `Migration/sql/002_seed_status_config.sql` (aktuelle Schema-Version 1.12.0; aktualisiert durch `012_status_model.sql`). `wp_status_mapping = NULL` bedeutet: kein Outbound-Push. `notify_customer = 1` steuert `RepairEmailService::shouldSendNotification()` — der eigentliche Mailversand ist noch nicht verdrahtet (der Hook bereitet ihn nur vor, siehe Kommentar in `TicketStatusChangeHook`), das Flag ist heute also reine Konfiguration.

| Slug              | Label                                  | Kategorie             | Sort | `wp_status_mapping` | `next_status_slug` | notify |
|-------------------|----------------------------------------|-----------------------|------|---------------------|--------------------|--------|
| `neu`             | Neu                                    | general               | 10   | `new`               | `eingegangen`            | 0      |
| `eingegangen` | Gerät eingegangen | general | 15 | `received` | - | 1 |
| `offen`           | Offen                                  | general               | 20   | -                   | -                  | 0      |
| `warten_e`        | Warten auf Intern                      | general               | 30   | -                   | -                  | 0      |
| `warten_kd`       | Warten auf Kunde                       | general               | 40   | -                   | -                  | 0      |
| `klaeren`         | Klaeren                                | general               | 50   | -                   | -                  | 0      |
| `beantwortet`     | Beantwortet                            | general               | 60   | -                   | -                  | 0      |
| `in_diagnose`     | In Diagnose                            | repair                | 100  | `in_progress`      | `kv_gesendet`      | 1      |
| `kv_gesendet`     | Kostenvoranschlag gesendet             | repair                | 110  | `quote_sent`        | `freigegeben`      | 1      |
| `kv_abgelehnt`    | Kostenvoranschlag abgelehnt            | repair                | 115  | `completed`    | `versendet`        | 1      |
| `freigegeben`     | Freigegeben                            | repair                | 120  | `in_progress`          | `in_reparatur`     | 1      |
| `in_reparatur`    | In Reparatur                           | repair                | 130  | `in_progress`         | `repariert`        | 0      |
| `repariert`       | Repariert                              | repair                | 140  | `completed`          | `versendet`        | 1      |
| `versendet`       | Versendet                              | repair                | 150  | `completed`          | `abgeschlossen`    | 1      |
| `wartung_geplant` | Wartung geplant                        | maintenance           | 200  | -                   | `wartung_laeuft`   | 1      |
| `wartung_laeuft`  | Wartung laeuft                         | maintenance           | 210  | `in_progress`         | `wartung_fertig`   | 0      |
| `wartung_fertig`  | Wartung abgeschlossen                  | maintenance           | 220  | `completed`          | `versendet`        | 1      |
| `re_analyse`      | RE: Analyse                            | reverse_engineering   | 300  | `in_progress`      | `re_angebot`       | 1      |
| `re_angebot`      | RE: Angebot erstellt                   | reverse_engineering   | 310  | `quote_sent`        | `re_freigabe`      | 1      |
| `re_abgelehnt`    | RE: Angebot abgelehnt                  | reverse_engineering   | 315  | `completed`    | `versendet`        | 1      |
| `re_freigabe`     | RE: Freigegeben                        | reverse_engineering   | 320  | `in_progress`          | `re_umsetzung`     | 1      |
| `re_umsetzung`    | RE: In Umsetzung                       | reverse_engineering   | 330  | `in_progress`         | `re_fertig`        | 0      |
| `re_fertig`       | RE: Fertig                             | reverse_engineering   | 340  | `completed`          | `versendet`        | 1      |
| `ind_planung`     | Individualisierung: Planung            | individualization     | 400  | `in_progress`      | `ind_angebot`      | 1      |
| `ind_angebot`     | Individualisierung: Angebot            | individualization     | 410  | `quote_sent`        | `ind_freigabe`     | 1      |
| `ind_abgelehnt`   | Individualisierung: Angebot abgelehnt  | individualization     | 415  | `completed`    | `versendet`        | 1      |
| `ind_freigabe`    | Individualisierung: Freigegeben        | individualization     | 420  | `in_progress`          | `ind_fertigung`    | 1      |
| `ind_fertigung`   | Individualisierung: In Fertigung       | individualization     | 430  | `in_progress`         | `ind_fertig`       | 0      |
| `ind_fertig`      | Individualisierung: Fertig             | individualization     | 440  | `completed`          | `versendet`        | 1      |
| `abgeschlossen`   | Abgeschlossen                          | general               | 900  | `closed`            | -                  | 0      |
| `storniert` | Storniert | general | 950 | `cancelled` | - | 1 |
| `spam`            | Papierkorb                             | general               | 999  | -                   | -                  | 0      |


Bewusst ohne Mapping: die `general`-Arbeitsstatus (`offen`, `warten_e`, `warten_kd`, `klaeren`, `beantwortet`, `spam`) und `wartung_geplant` — eine geplante Wartung ist keine Diagnose, ein WP-Echo waere irrefuehrend.

### Deploy-Reihenfolge — WP-Plugin zuerst

WP **v3.41.0** muss vor oder zusammen mit Schema **1.12.0** eingesetzt werden,
damit die sieben neuen Status-Keys akzeptiert werden. Historische Migrationen
bleiben unveraendert; `012_status_model.sql` stellt die Mappings idempotent um.

Kennt das Ziel-Plugin einen dieser Slugs noch nicht, antwortet es mit einem non-2xx-Code. `RepairSyncService` wiederholt den Versuch entlang `RETRY_DELAYS` (2 min, 10 min, 30 min, 2 h) und setzt den Eintrag nach `max_retries` (Default 5, in Summe also nach ca. 2 Stunden 42 Minuten) endgueltig auf `permanently_failed`. Von dort wird **nicht** automatisch erneut zugestellt: die Sync-Status-Seite (`index.php?module=repairintegration&action=syncstatus`) zeigt `permanently_failed` nur als Zaehler, eine Wiedervorlage-Aktion gibt es nicht — betroffene Eintraege muessten in `repair_sync_queue` von Hand zurueckgesetzt werden.

### Migration bestehender Installationen

Der Seed (`002_...`) laeuft nur bei Erstinstallation (`RepairIntegrationMigration::needsInstall()`). Bestehende Installationen bekommen Mapping-Aenderungen, neue Status und neue Spalten ueber die Upgrade-Kette `Migration/sql/003_*.sql` bis `012_status_model.sql` (1.3.0: `005_customer_quote_amount.sql` ergaenzt `ticket_repair_details.customer_quote_amount`, idempotent per information_schema-Check):

- `RepairIntegrationMigration::needsUpgrade()` vergleicht die in `systemconfig` (`repair_integration.schema_version`) gespeicherte Version mit `SCHEMA_VERSION`
- `upgrade()` fuehrt das Upgrade-SQL aus und schreibt die neue Version
- Ausgeloest via `classes/Modules/RepairIntegration/install.php` (Action `index.php?module=repairintegration&action=install`) **und** automatisch beim ersten Seitenaufruf des Moduls (`repairintegration.php::ensureInstalled()`)
- Idempotent: `INSERT IGNORE` fuer neue Status, feste Mapping-Updates fuer die vertraglichen Slugs und Normalisierung verbliebener Legacy-Mappings. Spaltenanlage per information_schema-Pruefung in PHP (kein SQL PREPARE).

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
    { "key": "in_progress",      "label": "In Bearbeitung" }
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

## Wertgarantie-Vertragspruefung (additiv, Schema 1.10.0)

WordPress liefert im Legacy-/Initial-Push und v2-Status-Transport optional
`service_details.wertgarantie`. Im v2-Manifest und requests-Listing steht derselbe
Block als Top-Level-Key `wertgarantie`:

```json
{"contract_number":"66326566","status":"verified","status_label":"Verifiziert","checked_at":"2026-11-06T14:37:06+01:00","live":true}
```

Gespeichert wird der vollstaendige normalisierte Block als JSON-Text in der
nullable Spalte `ticket_repair_details.wertgarantie`. Fehlender Key behaelt den
Bestand (Kompatibilitaet mit alten Sendern), explizites `null` leert die Spalte.
Ungueltige Bloecke werden ignoriert und ohne Vertragsdaten in `error_log`
protokolliert; sie verursachen keine 4xx-Antwort. Andere Payload-Fehler und
Authentifizierungsfehler behalten ihre bisherigen HTTP-Codes.

Validierung: `contract_number` ist ein String mit 1 bis 64 Ziffern; `status`
besteht aus 1 bis 32 Zeichen `[a-z_]`; `status_label` ist ein UTF-8-String mit
maximal 255 Zeichen; `checked_at` ist ein parsebarer Datumsstring ohne
Parser-Warnings oder `null`; `live` ist bool. Unbekannte wohlgeformte Statuswerte
werden gespeichert und mit dem mitgelieferten, bei Ausgabe escapten Label angezeigt.
Bekannt sind `verified`, `verified_pending`, `active_unmatched_name`,
`active_external`, `inactive`, `not_found`, `check_failed`, `unavailable`,
`invalid_format`. Ausschliesslich `verified` und `verified_pending` erhalten
im Ticket-Detail den gruenen Badge "Wertgarantie-Reparatur"; alle anderen einen
neutralen Badge. Status-Label und Vertragsnummer stehen daneben.

Inbound-Pfade: `RepairApiController::mapInboundData()` (beide Legacy-Entry-Points
und v2-Initial-Anlage), `RepairStatusExchangeService::receive()` (v2-Status,
auch bei bereits bekanntem oder aelterem Status-Event) und
`RepairReconcileService::reconcileOne()` (Manifest/requests, nur bei Apply).
Ein Preview schreibt keine WG-Daten. Die Metadaten sind unabhaengig von der
Status-Event-Identitaet; der Status-Event-Kontrakt bleibt unveraendert.
Die gemeinsame Validierung liegt in `Service/RepairWertgarantie.php`.
Installation und Upgrade 1.9.0 -> 1.10.0 pruefen `information_schema.COLUMNS`
und fuehren fehlende DDL direkt aus PHP aus (idempotent, kein SQL PREPARE).
Es gibt keinen WG-Rueckkanal nach WordPress.

## Versand-Rueckmeldung (additiv, Schema 1.11.0)

WordPress v3.40.0 liefert optional `service_details.shipping_feedback` im
Legacy-/Initial-Push und v2-Status-Transport. Manifest und requests-Listing
liefern denselben Block als Top-Level-Key `shipping_feedback`:

```json
{"choice":"shipped","choice_label":"Bereits versendet","responded_at":"2026-11-09T12:58:56+01:00","tracking_number":"00340434123456789","carrier":"dhl","carrier_label":"DHL"}
```

`choice` erlaubt `shipped` (Bereits versendet), `later` (Versendet später) und
`cancelled` (Versendet nicht mehr). `responded_at` ist RFC3339 oder `null`.
Bei `shipped` sind Sendungsnummer und Versanddienstleister fuer den Kunden optional:
`tracking_number` ist `null` oder ein String mit maximal 64 Zeichen
`[A-Z0-9 -]` (auch leer erlaubt), `carrier` ist `null` oder `dhl`, `dpd`, `hermes`, `gls`,
`ups`, `post`, `other`; `carrier_label` ist bei gesetztem `carrier` ein UTF-8-String, sonst `null`.
Bei den anderen Choices sind alle drei Versandfelder `null`.
Labels sind UTF-8-Strings mit maximal 255 Zeichen. Unbekannte Zusatzfelder
werden entfernt; fuehrende Nullen der Sendungsnummer bleiben erhalten.

Die gemeinsame Validierung liegt in `Service/RepairShippingFeedback.php`.
Alle drei Inbound-Pfade entsprechen Wertgarantie: `mapInboundData()`,
`receive()` (auch doppelte/aeltere Events) und `reconcileOne()` (nur Apply).
Fehlender Key behaelt den Bestand, `null` loescht ihn. Ungueltige Bloecke
werden ohne Kundendaten geloggt und ignoriert, ohne dadurch 4xx auszulösen.
Gespeichert wird JSON-Text in `ticket_repair_details.shipping_feedback`.
Installation und Upgrade 1.10.0 -> 1.11.0 pruefen `information_schema.COLUMNS`
und legen die nullable TEXT-Spalte nur an, wenn sie fehlt; Bestandsdaten
bleiben unveraendert. Im Ticket stehen Rueckmeldung und Datum, bei `shipped`
zusaetzlich Versanddienstleister und Sendungsnummer; alle Werte HTML-escaped.
`cancelled` wird von WordPress separat als normaler Statuswechsel nach `closed`
uebertragen; es gibt keine zusaetzliche OpenXE-Statuslogik oder Rueckuebertragung.


## KVA-Entscheidung (WP v3.41.0, OpenXE Schema 1.12.0)

`ticket_repair_details.quote_decision` ist nullable `VARCHAR(16)`:
`approved`, `declined` oder SQL NULL. Fehlender Key behaelt den Wert,
explizites `null` loescht ihn; ungueltige Werte werden ignoriert und geloggt.
Anzeige im Reparatur-Tab als Freigegeben/Abgelehnt (leerer Wert ohne Anzeige).

- v1 Inbound: `service_details.quote_decision`; auch bei bestehenden Tickets.
- v2 Status-Transport: `service_details.quote_decision` additiv, unabhaengig von Event-Identitaet.
- Manifest: Top-Level `quote_decision`; Dry-Run schreibt nichts.
- v1 Outbound `/requests/status`: Top-Level `quote_decision` bei Freigabe/Ablehnung.
- `freigegeben`, `re_freigabe`, `ind_freigabe` senden `approved`;
  `kv_abgelehnt`, `re_abgelehnt`, `ind_abgelehnt` senden `declined`.
- v2 Outbound und Manifest liefern auch den gespeicherten Entscheidungswert
  (einschliesslich `null`) bei anderen Status; Status-Keys bleiben die sieben neuen Keys.
- Belegversand Auftrag verwendet weiterhin den nativen Kategorie-Freigabestatus,
  nicht den Diagnose-Default von `in_progress`; Rechnung verwendet Kategorie-Fertigstatus.

`012_status_model.sql` fuehrt keine Ticket-/History-Umschreibung und keinen Push aus.
`neu.next_status_slug` wechselt nur vom bisherigen `offen` zu `eingegangen`,
weil Geraeteeingang fuer alle Kategorien gemeinsam ist. `eingegangen` hat keinen
kategorie-fremden automatischen Folgestatus; `storniert` ist terminal.
