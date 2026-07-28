# RepairIntegration — bekannte offene Punkte

Stand 2026-07-27, nach dem Bugfix-Paket (SSRF-Haertung, Queue-Reaper,
install.php-Cronjob-Fix, Dropdown-Mechanismus, tote Hooks/Enricher entfernt)
und der Aufnahme von `customer_quote_amount` (Schema 1.3.0). Alle offenen
Punkte sind bewusst offen gelassen, nicht vergessen.

## Funktional offen (User-sichtbar)

| Punkt | Detail | Ort |
|---|---|---|
| Test-Tickets auf Live | 14 Stueck (5 Fixtures `20260406xxxx` + WP-Push-Tests). Loeschung zurueckgestellt, vorher SELECT zeigen. | Live-DB |

## Entscheidungen (gewollt, kein Bug)

| Punkt | Begruendung |
|---|---|
| API-Adressen ohne Kundennummer | BY DESIGN per User-Entscheid: die Kundennummer wird bewusst erst vergeben, wenn der Kunde wirklich angelegt wird (Geraet eingegangen, KVA wird erstellt) — also beim `createadresse`/`createbeleg` im Web-Kontext via `ensureKundennummer()`. Der Standalone-Endpoint vergibt bewusst keine Nummern. |
| Erinnerungsmail-Versand liegt beim WP-Plugin | BY DESIGN per User-Entscheid: der Kunden-Versand der 21-Tage-Erinnerung wird vom WP-Plugin geloest (es kennt Eingangsdatum und Status selbst), Versender ist `kontakt@partner-3d.de`. `repair_reminders` schreibt nur den internen Dedup-Marker `Erinnerung faellig` ins Ticket-Protokoll; OpenXE versendet bewusst keine Erinnerungsmail. |
## Sicherheit / Robustheit (akzeptierte Risiken)

| Punkt | Detail | Ort |
|---|---|---|
| CSRF bei createbeleg/createadresse | Zustandsaendernde GET-Links ohne Token — entspricht OpenXE-Konvention (`AdresseCreateDokument` identisch). | `www/pages/repairintegration.php` |
| `ticket.schluessel` nicht UNIQUE | Zwei parallele Pushes wuerden sonst Duplikat-Tickets anlegen. Mitigiert via benanntem `GET_LOCK('repair_ticket_<nr>')` rund um Find-or-Create; ein DB-seitiger UNIQUE-Index ist wegen Altlasten in `ticket` nicht ohne Weiteres moeglich. | `Api/RepairApiController.php` |

## UX / Datenqualitaet (kosmetisch)

| Punkt | Detail |
|---|---|
| Panel-Stale-Read | Panel liest `ticket.adresse` vor dem Parent-Save; aendert derselbe POST die Adresse, zeigt das Panel den alten Stand (Reload korrigiert). |
| Zeitzonen-Annahme | WP sendet `current_time('mysql')` (Site-TZ), OpenXE interpretiert `created_at` als Europe/Berlin. Bei DE/DE identisch, nicht garantiert. |

## Abhaengigkeit WP-Plugin

| Punkt | Detail |
|---|---|
| `customer_quote_amount` | Das Feld (vom Kunden freigegebener KVA-Preis, Schema 1.3.0) muss die WP-Seite ab dem naechsten Plugin-Release im Push-Payload liefern. Aeltere Plugin-Versionen senden es nicht — die Spalte bleibt dann `NULL`, das Panel zeigt leer. |
| `media_id` bei Medien | Die stabile WP-Attachment-ID muss die WP-Seite ab dem naechsten Plugin-Release in den Medien-Eintraegen liefern (`media_urls`/`document_url` als Objekte `{"url": ..., "media_id": ...}`). Aeltere Plugin-Versionen senden sie nicht — dann greift weiter der Legacy-Marker `sha1(url)` (Duplikat-Importe bei URL-Wechsel der WP-Uploads). |

## Behoben (2026-07-28)

- Medien-Marker auf stabile WP-Attachment-ID umgestellt: liefert der Push
  `media_id` in den Medien-Eintraegen, wird `WP-REPAIR-MEDIA-ID-<id>` statt
  `WP-REPAIR-MEDIA-<sha1(url)>` in `datei.nummer` geschrieben — der Marker
  ueberlebt damit einen URL-Wechsel der WP-Uploads. Der Dedup-Check prueft
  weiter beide Formate, Alt-Anhaenge mit Legacy-Marker werden nicht erneut
  importiert. Voraussetzung: das WP-Plugin liefert `media_id` ab dem
  naechsten Plugin-Release (siehe "Abhaengigkeit WP-Plugin").

## Behoben (2026-07-27)

- SSRF-Haertung Medien-Download (`downloadAttachment`): nur https,
  Host-Allowlist gegen den Host der konfigurierten `wp_api_url`, aufgeloeste
  Ziel-IPs muessen oeffentlich sein (privat/reserviert abgelehnt), Redirects
  (max. 3) werden manuell verfolgt und jedes Ziel erneut geprueft. Die fruehere
  Akzeptanz-Begruendung "WP haengt im LAN" war eine Fehlannahme — WP ist
  oeffentlich erreichbar, die Payload-URLs damit angreiferbeeinflussbar.
- Auth-Bypass Bearer-Pfad geschlossen: bei leerem konfiguriertem Secret
  waere `hash_equals('', '')` mit einem leeren Bearer-Token wahr gewesen —
  jetzt `SHARED_SECRET_NOT_CONFIGURED`.
- Cronjob-Typo `letzteausfuehrung` -> `letzteausfuerhung` in allen drei
  repair-Cronjobs (mutex blieb haengen, Jobs liefen nie wieder)
- install.php: prozessstarter-INSERT mit allen NOT-NULL-Spalten,
  `repair_sync` periode 1440 (taeglich), Self-healing-UPDATEs fuer
  Bestandsinstallationen (periode/art/typ/mutex)
- Queue-Reaper: Eintraege, die laenger als 15 Minuten in `processing`
  haengen (abgestuerzter Lauf), werden wieder auf `pending` gesetzt
- `max_retries` und `notify_on_permanent_fail` verdrahtet: Retry-Grenze kommt
  aus der Modul-Konfiguration, bei `permanently_failed` geht eine
  Benachrichtigungsmail raus
- `RepairDetailsGateway::update()`: Whitelist fehlte komplett (stillschweigend
  verworfene Updates), jetzt inkl. `customer_type`/`company_name`/`vat_id`
- Rate-Limit-Reihenfolge: Limit greift jetzt NACH der Authentifizierung,
  ungueltige Requests verbrauchen das IP-Kontingent legitimer Pushes nicht
- Optionale IP-Allowlist inbound (`checkAllowedIps`)
- Dedup beim Einreihen: noch ausstehende Status-Syncs desselben Tickets
  werden verworfen, bevor ein neuer Eintrag in `repair_sync_queue` landet
- Medien-Import atomar: `datei` + `datei_version` + `datei_stichwoerter`
  laufen in einer Transaktion — kein verwaister `datei`-Datensatz mehr, der
  den Idempotenz-Marker aushebelt
- Find-or-Create pro Ticket-Schluessel via `GET_LOCK` serialisiert
- Inbound-Log befuellt `wp_request_number` (aus Payload bzw. Schluessel)
- Leerer Geraete-Link in der Reparatur-Liste unterdrueckt (SQL-IF)
- Betrags-Normalisierung Panel: unparsebare Eingabe behaelt alten Wert +
  Warnung; `1.234` als deutsche Tausender-Notation erkannt
- createbeleg-Idempotenz: bestehender Beleg desselben Typs wird direkt
  geoeffnet statt Duplikat anzulegen
- `NoRights()`-Alt-Stellen auf redirectNoRights() (welcome/info + msg)
  umgestellt
- Tote Hook-Registrierungen `ticket_edit_after`/`ticket_list_after` entfernt
  (install.php loescht Bestandszeilen self-healing)
- Toter Mail-Enrichment-Pfad entfernt (TicketCreateHook,
  RepairTicketEnricher, `isAutoEnrichEnabled()`) — kein Core-Hookpunkt nach
  Ticket-Anlage aus Mail-Import vorhanden
- `firma`-Konstanten: Single-Tenant-Annahme als dokumentierte Konstante
  `FIRMA_ID` statt verstreuter Magic Numbers
- Status-Dropdown: toter `REPAIRSTATUSDROPDOWN`-Override entfernt;
  `www/pages/ticket.php` ergaenzt `[STATUS]` jetzt selbst um aktive Slugs aus
  `ticket_status_config`; der damit ungenutzte
  `RepairStatusService::renderStatusDropdown()` entfaellt
- Bulk-Statusaenderung in der Ticketliste stoesst jetzt den WP-Sync an
- "Erste Nachricht" einheitlich: Anhang-Import filtert wie die
  `created_at`-Korrektur auf `medium='api'`
- Ungueltiges `customer_quote_amount` im Push wird ignoriert statt den
  Request abzulehnen (error_log-Hinweis)

## Deploy-Pflichten

- Nach jedem Deploy mit neuen Services: Container-Service-Cache leeren
  (`classes/bootstrap.php` regeneriert die Map nur, wenn die Cache-Datei FEHLT
  → `userdata/tmp/<db>/` leeren bzw. `refreshFileCache.php`) + Apache reload
  (OPcache).
- Nach DIESEM Deploy zusaetzlich:
  - `install.php` einmal laufen lassen, damit die Self-healing-UPDATEs die
    prozessstarter-Zeilen reparieren (mutex-Reset, periode 1440, art/typ).
    Passiert automatisch beim ersten Aufruf einer Modulseite via
    `ensureInstalled()` — oder explizit ueber
    `index.php?module=repairintegration&action=install`.
  - Container-Service-Cache leeren wegen Bootstrap-Aenderung
    (RepairTicketEnricher entfernt, RepairSyncService-Signatur geaendert).
  - Schema 1.3.0 zieht per Migration die Spalte
    `ticket_repair_details.customer_quote_amount` nach (ebenfalls via
    `ensureInstalled()`/install.php).
- production wird per CI force-gepusht → auf dem Server `fetch + reset --hard
  FETCH_HEAD` bzw. Upgrader mit Force, nie `git pull`.

## Upstream-Status: GetTicketBelege (Issue #282)

- Unser fix/ticket-belege-objekt-filter (dst.objekt = 'Ticket') ist OBSOLET und
  aus Manifest + origin geloescht: upstream 8328f765 fixt beide Funktionen
  (GetTicketBelege + GetBelegTickets) selbst — allerdings mit
  `dst.objekt LIKE 'ticket%'`.
- Restluecke der LIKE-Variante: `ticket_header`-Anhaenge (Dateien-Tab am
  Ticket, parameter = ticket.id) laufen mit durch, werden aber gegen
  ticket_nachricht.id gejoint → dieselbe ID-Kollisionsklasse in kleinerem
  Massstab; Kopf-Anhaenge matchen nur zufaellig. OR-Zweig vorgeschlagen
  (Issue #282, Kommentar 2026-07-28), Antwort von OpenXE-ERP ausstehend.
- Bis dahin: falls wieder fremde Belege an Tickets auftauchen, zuerst
  ticket_header-Kollisionen pruefen.
