# Integration: WordPress-Plugin (heute) und Mobile-App (Phase 2.5)

**Repo:** OpenXE-Fork (Worktree `C:\worktrees\openxe-repair-integration\`, Branch `feature/repair-integration-port`)
**Pfad dieser Datei:** `docs/integration-wordpress-and-mobile.md`

Diese Datei beschreibt das Modul `Xentral\Modules\RepairIntegration` aus Sicht der externen Schnittstellen:

- **Inbound von WP-Plugin:** Push neuer Reparatur-Anfragen / Status-Updates aus dem Plugin nach OpenXE (Phase 1, implementiert)
- **Outbound nach WP-Plugin:** Status-Echo wenn OpenXE-Tickets sich aendern (Phase 2.5, Roadmap)
- **Inbound von Mobile-App:** Direkt-Anbindung der Werkstatt-App (Phase 2.5, Roadmap)

Cross-Links:

- WP-Plugin-Seite: `C:\Users\3D Partner\Desktop\Claude\Business\Reparaturformular\plugin\partner3d-repair-intake\docs\integration-openxe-and-mobile.md`
- Flutter-App-Seite: `C:\Users\3D Partner\Desktop\Claude\Business\Reparatur_app\docs\integration-wp-and-openxe.md`

---

## Datenfluss

```
Phase 1 (aktiv):

  +-------------+   POST   +--------+
  | WP-Plugin   | ------>  | OpenXE |   /repairapi/index.php/repair-status
  |             |          |        |   Bearer ODER HMAC-SHA256
  +-------------+          +--------+


Phase 2.5 (Roadmap):

  +---------+    Bearer    +--------+    HMAC+Bearer    +-------------+
  | Android |  -------->   | OpenXE |  ------------->   | WP-Plugin   |
  |  App    |  <--------   |        |   Status-Echo     |             |
  +---------+   neue       +--------+   POST p3d/v1/    +-------------+
              Mobile-API               requests/status
              (zu bauen)               (Plugin-Seite ready)
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

Beispiel (siehe `tests/E2E/RepairIntegration/fixtures/inbound_api_payload.json`):

```json
{
  "request_number": "202604050001",
  "status": "new",
  "service_type": "reparatur",
  "service_delivery_type": "einsendung",
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
| `status`        | `'neu'`                                              |
| `betreff`       | `[REP|WRT|REV|IND] Ticket #<nr> - <Hersteller> <Modell>` |
| `kunde`         | `"<name> <<email>>"`                                  |
| `mailadresse`   | `data.customer.email`                                 |
| `firma`         | `data.customer.company` oder leer                     |
| `prio`          | `3`                                                   |
| `notiz`         | `"Automatisch erstellt via WP API Push (<service_type>)"` |

Anschliessend wird die `issue_description` als erste `ticket_nachricht` geschrieben, und `nachrichten_anz` aktualisiert.

Reparatur-Details landen in `repair_details` via `RepairDetailsGateway::create()` bzw. `update()`.

### Audit-Log

Jeder Request (Erfolg und Fehler) wird in `repair_sync_log` mit `direction = 'inbound'`, `action = 'push_details'` geloggt, inkl. Remote-IP und gekuerztem Payload.

---

## (B) Outbound nach WP (Status-Echo) — `Phase 2.5, geplant`

Wenn ein OpenXE-Mitarbeiter den Ticket-Status aendert (z.B. UI-Action "In Reparatur" → "Reparatur abgeschlossen"), soll OpenXE diesen Status-Change zurueck ins WP-Plugin pushen, damit der Kunde im `frontend-status-lookup.php` aktuelle Daten sieht.

**Status:** WP-Plugin-Endpoint ist fertig, OpenXE-Outbound-Modul muss noch gebaut werden.

### Ziel-Endpoint (im WP-Plugin)

| Method | URL                                                        | Auth              |
|--------|------------------------------------------------------------|-------------------|
| POST   | `https://partner-3d.de/wp-json/p3d/v1/requests/status`     | `Bearer <token>`  |

Body:
```json
{ "request_number": "2026-0431", "status": "in_repair" }
```

Response 200:
```json
{ "success": true, "old_status": "in_diagnose", "new_status": "in_repair", "audit_logged": true }
```

`status` muss in der WP-Allowlist sein (siehe Plugin-`Helpers::get_status_labels()`).

### Vorschlag fuer das Outbound-Modul

Spiegelbild zur WP-seitigen ERP-Queue. Empfohlene Komponenten:

- **Trigger:** OpenXE-Event-Hook auf `ticket_status_change` (oder `repair_details_status_change` wenn Repair-Status separat gehalten wird) — pusht `request_number + neuer_status` in eine neue Tabelle `repair_outbound_queue`
- **Queue-Tabelle:** `repair_outbound_queue` (Spalten: `id`, `request_number`, `status`, `retry_count`, `next_retry_at`, `last_error`, `created_at`)
- **Cron:** alle 5 Minuten (analog zu WP `p3d_erp_queue_process`)
- **Backoff:** `[5, 15, 60, 240, 720]` Minuten — identisch zum WP-Plugin, damit Operations-Verhalten konsistent ist
- **HTTP-Client:** Symfony HttpClient ueber den OpenXE-Container; `RepairApiAuth::generateSignature()` **wiederverwenden** (gleicher HMAC-Algorithmus), Header-Format identisch zur Inbound-Seite
- **Config:** neuer Settings-Eintrag `repair_outbound_wp_url`, `repair_outbound_bearer_token` (kann derselbe wie inbound sein), HMAC-Secret darf identisch sein

### Reduce-Coupling-Note

Auch wenn das Plugin den Bearer-Token in beide Richtungen akzeptiert, sollte OpenXE-Outbound **HMAC** verwenden, damit ein eventuelles Leak des Bearer-Tokens (durch z.B. Log-Capture irgendwo im Stack) nicht ausreicht — Angreifer braucht zusaetzlich den HMAC-Secret und einen aktuellen Timestamp.

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
