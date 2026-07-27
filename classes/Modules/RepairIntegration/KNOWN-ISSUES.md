# RepairIntegration — bekannte offene Punkte

Stand 2026-07-27, nach dem Ausbau created_at/Medien-Import/Kundenkonto/Panel
(Commits ae60e439, 7ada23a0, e533cfe7). Quellen: zwei Verifikations-Reviews
der Implementierung. Alle Punkte sind bewusst offen gelassen, nicht vergessen.

## Funktional offen (User-sichtbar)

| Punkt | Detail | Ort |
|---|---|---|
| Status-Dropdown-Override wirkungslos | `REPAIRSTATUSDROPDOWN` wird gesetzt, aber kein Template enthaelt den Platzhalter; Set laeuft zudem nach dem PAGE-Parse. Repair-Stati erscheinen nicht im Ticket-Status-Dropdown. Fix braucht Eingriff in `[STATUS]`-Handling des Parents. | `www/pages/ticket_custom.php` (~Z. 50) |
| API-Adressen ohne Kundennummer | Standalone-Endpoint hat keinen Zugriff auf den Nummernkreis (`GetNextKundennummer` braucht `$app->erp`). Nachvergabe via `ensureKundennummer()` erst beim ersten `createbeleg`/`createadresse` im Web-Kontext. | `www/pages/repairintegration.php` |
| Test-Tickets auf Live | 14 Stueck (5 Fixtures `20260406xxxx` + WP-Push-Tests). Loeschung zurueckgestellt, vorher SELECT zeigen. | Live-DB |

## Sicherheit / Robustheit (akzeptierte Risiken)

| Punkt | Detail | Ort |
|---|---|---|
| SSRF-Flaeche Medien-Download | `downloadAttachment` prueft nur Scheme http/https, folgt bis 3 Redirects, keine Host-/IP-Allowlist. Akzeptiert, weil WP im LAN haengt und URLs nur via HMAC/Bearer-authentifizierten Payload kommen. Haertung: Host gegen konfigurierte `wp_api_url` pruefen. | `Api/RepairApiController.php` |
| CSRF bei createbeleg/createadresse | Zustandsaendernde GET-Links ohne Token — entspricht OpenXE-Konvention (`AdresseCreateDokument` identisch). | `www/pages/repairintegration.php` |
| Kein UNIQUE auf Idempotenz-Marker | Marker `WP-REPAIR-MEDIA-<sha1(url)>` in `datei.nummer`. Bricht der `datei_stichwoerter`-INSERT nach dem `datei`-INSERT ab, bleibt eine unverknuepfte datei-Zeile; naechster Push importiert ein Duplikat. | `Api/RepairApiController.php` |
| Re-Import bei URL-Wechsel | Ziehen die WP-Uploads um (andere URLs), greift der Marker nicht und Medien werden erneut importiert. | ebd. |
| Keine Idempotenz bei createbeleg | Reload/Doppelklick legt einen zweiten Beleg an (wie `adresse.php` auch). Vorab-Check in `repair_ticket_beleg` waere die Abhilfe. | `www/pages/repairintegration.php` |
| Pre-existing `NoRights()`-Aufrufe | 5 Alt-Stellen (RepairList/Settings/Merge/SyncStatus) rufen nicht existentes `$app->erp->NoRights()` → Fatal bei Rechteverweigerung. Faellt nicht auf, weil die zentrale Modul-Rechtepruefung vorher blockt. Die 3 neuen Actions nutzen Redirect+msg. | `www/pages/repairintegration.php` |

## UX / Datenqualitaet (kosmetisch)

| Punkt | Detail |
|---|---|
| Stiller Verlust bei unparsebarem Betrag | `repair_normalize_amount('ca. 300')` → NULL → bisheriger Wert wird kommentarlos ueberschrieben. |
| Deutsche Tausendertrennung ohne Nachkomma | `'1.234'` → als 1.23 EUR gespeichert (`decimal(10,2)`). Mit Komma (`1.234,56`) korrekt. |
| Panel-Stale-Read | Panel liest `ticket.adresse` vor dem Parent-Save; aendert derselbe POST die Adresse, zeigt das Panel den alten Stand (Reload korrigiert). |
| Leerer Geraete-Link | Hersteller UND Modell leer → Listen-Link auf ein einzelnes Leerzeichen. |
| Uneinheitliche "erste Nachricht" | Zeit-Korrektur filtert `medium='api'`, `importAttachments` nimmt `MIN(id)` ohne Filter — divergiert nur bei zusammengefuehrten Tickets. |
| Zeitzonen-Annahme | WP sendet `current_time('mysql')` (Site-TZ), OpenXE interpretiert Europe/Berlin. Bei DE/DE identisch, nicht garantiert. |

## Deploy-Pflichten

- Nach jedem Deploy mit neuen Services: Container-Service-Cache leeren
  (`classes/bootstrap.php` regeneriert die Map nur, wenn die Cache-Datei FEHLT
  → `userdata/tmp/<db>/` leeren bzw. `refreshFileCache.php`) + Apache reload
  (OPcache).
- production wird per CI force-gepusht → auf dem Server `fetch + reset --hard
  FETCH_HEAD` bzw. Upgrader mit Force, nie `git pull`.
