# Design: WordPress-Verbindungsdaten-Tab (RepairIntegration)

Datum: 2026-07-27
Branch: `feature/repair-integration-port`
Status: vom User freigegeben (Design-Gespraech 2026-07-27)

## Ziel

Nach dem (Auto-)Setup des RepairIntegration-Moduls soll der Admin auf der
Einstellungsseite alle Daten, die das WordPress-Plugin (partner3d-repair-intake)
braucht, **generieren und per Klick kopieren** koennen — ohne Werte von Hand
zu erzeugen oder aus Password-Feldern zu fischen.

## Geklaerte Entscheidungen

| Frage | Entscheidung |
|---|---|
| Scope | Inbound **und** Outbound (Endpoint-URL, Inbound Shared Secret, WP API-Key) |
| Platzierung | 2. Tab "WordPress-Verbindung" auf bestehender Einstellungsseite (`repair_config.tpl`) |
| Secret-Darstellung | Maskiert + Auge-Toggle + Copy-Button (Copy kopiert immer Klartext) |
| Umsetzung | POST + Reload (Ansatz A) — keine AJAX-Action, keine Client-Generierung |

## Aenderungen

### 1. `www/pages/repairintegration.php` — Action `RepairSettings()`

- Neue POST-Zweige (zusaetzlich zu `submit=save`):
  - `submit=generate_inbound_secret` → `bin2hex(random_bytes(32))` → `RepairConfigService::set('inbound_shared_secret', ...)`
  - `submit=generate_wp_api_key` → analog fuer `wp_api_key`
- Ueberschreib-Schutz: Buttons heissen "Neu generieren", wenn schon ein Wert
  existiert; JS-`confirm()` vor dem Submit (Warnung: WP-Plugin-Seite muss danach
  aktualisiert werden). Server-seitig kein zusaetzlicher Schutz noetig —
  Aktion ist idempotent-harmlos und Admin-only per Permission `repairintegration`.
- Neue Tpl-Variable `ENDPOINT_URL`:
  `<scheme>://<host>/repairapi/index.php/repair-status`
  - scheme: `https` wenn `$_SERVER['HTTPS']` truthy (nicht `off`), sonst `http`
  - host: `$_SERVER['HTTP_HOST']`
- Erfolg/Fehler ueber bestehenden `[MESSAGE]`-Block.

### 2. `www/pages/content/repair_config.tpl`

- Tab-Leiste erweitert: `#tabs-2` "WordPress-Verbindung".
- Inhalt Tab 2 (drei Zeilen, gleiche `mkTableFormular`-Optik):
  1. **OpenXE Endpoint-URL** — readonly Text-Input `[ENDPOINT_URL]` + Copy-Button.
     Hinweis: "Im WP-Plugin als OpenXE-Endpoint eintragen."
  2. **Inbound Shared Secret** — Password-Input (readonly) + Auge-Toggle +
     Copy-Button + Generieren-Button (eigenes `<form method="post">` mit
     `submit=generate_inbound_secret`).
     Hinweis: "Im WP-Plugin als Bearer-Token eintragen."
  3. **WP API-Key (Outbound)** — analog, `submit=generate_wp_api_key`.
     Hinweis: "Im WP-Plugin als API-Key fuer eingehende Status-Updates eintragen."
- Bestehender Tab 1 (Einstellungen-Formular) bleibt unveraendert.

### 3. `classes/Modules/RepairIntegration/www/js/repairintegration.js`

- `repairCopyToClipboard(inputId)`: `navigator.clipboard.writeText()` wenn
  `window.isSecureContext`, sonst Fallback `input.select()` +
  `document.execCommand('copy')` — Test-VM laeuft ueber `http://192.168.0.150`,
  dort gibt es die Clipboard-API nicht.
  Kurzes visuelles Feedback (Button-Text "Kopiert!" fuer ~1,5 s).
- `repairToggleSecret(inputId)`: `type="password"` ↔ `type="text"`.
- `confirm()`-Handler auf den Generieren-Buttons: Server rendert
  `data-confirm="1"` + Label "Neu generieren" wenn bereits ein Wert existiert,
  sonst ohne data-Attribut + Label "Generieren"; JS fragt nur bei
  `data-confirm="1"` nach.

### 4. Fehlerbehandlung

- `random_bytes()` kann `\Exception` werfen (Entropie) → try/catch,
  Fehlermeldung in `[MESSAGE]`, kein Whitescreen.
- Copy-Fallback schlaegt fehl → Button-Feedback "Manuell kopieren",
  Feld wird selektiert.

## Bewusst weggelassen (YAGNI)

- "Alle Werte als Block kopieren", QR-Code, AJAX-Generierung,
  Secret-Rotation-History, Einmal-Anzeige.

## Verifikation

1. `php -l` auf `repairintegration.php` (tpl/js haben kein Lint-Gate).
2. Manuell auf Test-VM (192.168.0.150):
   - Tab 2 sichtbar, Endpoint-URL zeigt VM-Host
   - Generieren beider Secrets (leer → Wert; vorhanden → confirm + neuer Wert)
   - Toggle blendet Klartext ein/aus
   - Copy funktioniert (http-Fallback-Pfad!)
   - Werte ins WP-Plugin eintragen → Inbound-Test-Push (test_api_inbound.sh
     oder WP-Formular) → 200/Ticket angelegt
3. `tests/E2E/RepairIntegration/README.md`: manuelle Checkliste um
   "Flow 0: Verbindungsdaten einrichten" ergaenzen.

## Deployment

- Commit auf `feature/repair-integration-port` (Worktree
  `C:\worktrees\openxe-repair-integration`), Push nach User-Go,
  Production-Build via `build-production.yml` (Manifest enthaelt den Branch
  bereits), Live-Pull mit Upgrader `-f`.
