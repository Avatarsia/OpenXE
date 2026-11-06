# Repair Status/Image Sync Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans. Steps use checkbox tracking.

**Goal:** Status und Bilder laufend und rueckwirkend zwischen WP/OpenXE/App angleichen.
**Architecture:** App bleibt WP-Client. Authentifizierte gezielte Sync-Endpunkte, stabile UTC-Statusereignisse und idempotente Bildmanifeste; OpenXE fuehrt den resumierbaren Bestandsabgleich aus.
**Tech Stack:** vorhandenes PHP/MariaDB, WP REST, Flutter/Riverpod/Dio; keine neue Bibliothek.
**Spec:** ../specs/2026-10-20-repair-status-image-sync-design.md (2026-10-20 lokal freigegeben).

## Global Constraints

- Deutsch, kleine chirurgische Aenderungen, vorhandene uncommittete Beleg-Arbeit erhalten.
- Kein Commit/Push/Production-Deployment oder Live-Bestandslauf autorisiert.
- Statusereignisse behalten UUID und UTC-Zeit bei Retries; Empfaenge erzeugen keine neuen Events/Mails.
- Bilder additiv und vorgangsbezogen; nur JPEG/PNG/WebP, keine Loeschweitergabe.
- Bestehende Auth-, Host-, MIME-/Groessen-Pruefungen erhalten; lokale Testfreigaben getrennt.
- V2-Funktion explizit aktivieren (sync_v2_enabled); Legacy-Sync bleibt bei deaktiviertem V2 erhalten.

## Gemeinsamer API-Vertrag (fuer alle Implementer bindend)

OpenXE-Pfade relativ repairapi/index.php: /sync/status, /sync/manifest, /sync/image.
WP-Pfade relativ wp-json/p3d/v1: /erp-sync/status, /erp-sync/requests, /erp-sync/manifest, /erp-sync/image.
Authentifizierung wie heutiger Server-zu-Server-Sync, nie App-Staff-Token an OpenXE weitergeben.

Status POST JSON auf /status:
`{request_number,status,event_id,origin,changed_at_utc,revision}`.
- status ist WP-Slug, origin genau wp oder openxe.
- event_id UUID, changed_at_utc kanonisch YYYY-MM-DDTHH:MM:SS.ffffffZ.
- revision ist OpenXE-Revision (WP-lokal zunaechst 0), kein Bestandteil des Vergleichsschluessels.
- Reihenfolge UTC-Zeit, dann origin (openxe gewinnt Gleichstand), dann event_id lexikographisch.
- Antwort `{success:true,applied:bool,event:<kanonisches Event oder null>,reason:<string>}`.
- Nicht-neuere Ereignisse erfolgreich bestaetigen, event mit aktuellem Stand zurueckgeben.

Manifest GET /manifest?request_number=X:
`{success:true,request:{id,request_number,service_type,status,status_event,history_status_changed_at_utc,history_ambiguous,images:[...]}}`.
- status_event ist Event wie oben oder null. History: letzte echte Statusaenderung zum aktuellen Status, keine Echo-/allgemeine updated_at-Zeit; sonst null/ambiguous=true.
- images je `{origin_id,sha256,mime_type,file_name,uploaded_at_utc}`.
- origin_id bleibt bei Importen erhalten. Native WP-ID `wp:<persistierte Instanz-UUID>:<upload_id>`.
- Native ERP-ID `openxe:<persistierte Instanz-UUID>:<datei_id>:<version_id>`.
- SHA256 hex64, Dateiinhalt, pro Vorgang deduplizieren.
- Private/unveroeffentlichte/nicht eindeutige ERP-Vorgaenge/Bilder nicht exportieren.

WP GET /requests?cursor=0&limit=50:
`{success:true,requests:[<Manifest request Objekt>],next_cursor:<int>,has_more:<bool>}`.
- Cursor ist WP-Anfragen-ID, alle Service-Anfragen einschliesslich abgeschlossen.
- Keine Dateibytes oder unnoetigen Kundendaten im Manifest.

Bilder GET /image?request_number=X&origin_id=Y liefert authentifizierte Bytes.
Bilder POST /image multipart mit request_number,origin_id,sha256,file,optional uploaded_at_utc.
- Antwort `{success:true,image:<Manifest-Bildobjekt>,created:<bool>}`.
- Abweichender Hash/Dateityp/Zuordnung -> 4xx; Retry nach gleichem Import -> erfolgreich ohne Duplikat.

## Task 1: OpenXE Statusereignisse (Agent)

Files: neue Service/RepairStatusExchangeService.php und API/RepairStatusExchangeApiController.php,
Migration/sql/009_status_exchange.sql und Migration-Kette 1.7.0; gezielt RepairSyncService,
RepairStatusService, TicketStatusChangeHook sowie Statusregressionen.
Interfaces: Konstruktor ExchangeService(Database, RepairConfigService, RepairStatusConfigGateway).
- captureLocalChange(int ticketId): ?array Event nur bei echter Benutzerstatusaenderung.
- getSnapshot(int ticketId): array mit status,status_event,history_status_changed_at_utc,history_ambiguous.
- receive(array event): array API-Antwort; atomare native Status-/Event-/Revision-Speicherung.
- reconcile(int ticketId,array remote,bool apply=false,?string choice=null): array
  `{state:equal|local_newer|remote_newer|unclear|unmapped,event:?array,changed:bool}`.
  choice local/remote fuer explizite historische Aufloesung.
- lokale Events und Queue atomar, verlorene Core-Hooks durch nachvollziehbare Protokollhistorie erkennen.
- [x] RED-Regressionen, Implementierung, GREEN, php-lint und eigene Kurzpruefung.

## Task 2: WP Server und Bildsync (Agent)

Files: gezielte neue includes/class-erp-exchange.php plus Bootstrapping/Core,
ERP-Integration, Storage/Migration, Mobile-Upload-/Status-/Admin-Hooks und Tests.
Interfaces: komplette WP-API oben; eigener V2-Queue-/Worker darf bestehende Queue wiederverwenden,
gezielte Payloads niemals volle Details als Sync-Shortcut.
- [x] RED fuer Reihenfolge/Echo/Bestand/Bilder/Upload-Push, Implementierung, GREEN/Lint.
- [x] Native WP-/App-Statusaenderungen und Uploads einreihen, ERP-Empfang ohne Echo/Mail.
- [x] Persistente Ursprung-/Hash-Zuordnung und begrenzte/retrybare Jobs statt Transient-only-Erfolg.

## Task 3: App-Aktualisierung (Agent, eigener Worktree)

Files: RepairDetail Provider/Screen und zugehoerige Tests; keine API-Backend-Migration.
- [x] RED fuer Vordergrund/30s/Dispose/keine parallelen Fetches, minimale Implementierung.
- [x] Nach Upload/Status aktuelles Modell neu lesen, stille Aktualisierung bewahrt UI/Eingaben.
- [x] flutter analyze und passende Tests; keine Commit/Push-Aktionen.

## Task 4: ERP Bilder, Coordinator, Cron und UI (Root)

Files: Service/RepairImageExchangeService.php, API/RepairExchangeApiController.php, Service/RepairReconcileService.php, Service/RepairExchangeTransport.php,
Migration/sql/010_media_exchange.sql (1.8.0), API-Routing/Bootstrap, bestehender Sync-Cron,
Sync-Statusmaske und neue Tests.
- [x] Bildmanifeste/Downloads/Imports mit Legacy-Deduplikation und physischer Hash-Verifikation.
- [x] Preview read-only, Apply resumierbar, alle WP-Seiten + zugeordnete lokale Reparaturen,
      historische Unklarheiten explizit aufloesen, keine Daten ausser Status/Bilder anfassen.
- [x] Stabile Batches/Cursor, Status/Bildteilfehler und Fortschritt, echter 5-Minuten-Worker-Aufruf.
- [x] Alle V2-Routen vor Legacy-Fallback pruefen; erforderliche Auth/Services selbststaendig booten.

## Review Focus und Verifikation

- [x] Verspaetete/duplizierte Statusereignisse, UTC/History-Ambiguitaet und Kategorie-Mapping.
- [x] DMS-Nachrichten-ID vs Ticket-ID, private Tickets/Entwuerfe, File/DB-Atomaritaet.
- [x] Herkunft/Hash/Legacy-Marker, >20 Bilder, Bildfehler trotz erfolgreichem Status.
- [x] Kein WP-Frontend-Mail-Echo, kein versehentlicher Preis-/Belegdaten-Push.
- [x] App-Refresh ohne Ueberschreiben von Eingaben oder Request-Sturm.
- [x] Unabhaengige Reviews, Root prueft Findings; volle ERP-/WP-Suiten und PHP-Lint.
- [x] Gekoppelte lokale Testinstanzen mit absichtlich divergenten Daten und echten Bildern:
      Preview ohne Writes; Apply -> gleiche Zustaende; zwei Wiederholungen -> keine Duplikate;
      danach Aenderungen aus WP/App/OpenXE; Fehler/Retry sichtbar.

## Ledger

- 2026-10-20: Nutzer hat Design lokal freigegeben; Live-Aktivierung separat.
- Ruling: bestehende OpenXE-/WP-Feature-Worktrees weiterverwenden, App isoliert aus sauberem
  feature/review-fixes-ux abzweigen; keine vorhandene Arbeit ueberschreiben.


## Implementiert und lokal verifiziert (2026-10-20)

- ERP: C:/worktrees/openxe-repair-clean, feature/repair-integration-clean.
- WP: C:/worktrees/repair-intake-erp-positions; Plugin 3.35.0, V2 standardmaessig aus.
- App: C:/worktrees/repair-app-status-image-sync, feature/status-image-sync.
- Vollstaendige ERP-Suite: 178 Tests / 516 Assertions, keine Fehler oder Skips.
- WP-Suite: 35 Tests / 159 Assertions einschliesslich echter HTTP-Bildimports.
- App: 431 Tests erfolgreich, flutter analyze ohne Findings; 13 neue Refresh-Tests
  unabhaengig erneut ausgefuehrt.
- PHP-Lint: 37 geaenderte/neue ERP-PHP-Dateien und 15 WP-PHP-Dateien erfolgreich;
  git diff --check in allen drei Worktrees ohne Fehler.
- Gekoppelter HTTP-Lauf: 16 Pruefungen fuer read-only Vorschau, historischen Abgleich,
  Bilder in beide Richtungen, zwei idempotente Wiederholungen, echte App-Endpunkte,
  native ERP-Aenderungen und unveraenderte private Notizen/Preise. Weitere 5 Pruefungen
  fuer nachtraegliche Erstanlage, persistiertes Binding, Retry und manipulierte HMAC-Metadaten.
- CLI-Worker gegen dieselben Wegwerf-DBs erfolgreich; abgeschlossene Scans ohne offene Punkte.
- Native lokale Demo: Schema 1.8.0, bestehende Reparaturdaten unveraendert, Sync V2 aus.
  Browser GET und POST der Sync-Seite HTTP 200; CSRF-Token ueber Requests gueltig.
- Fuenf initiale Review-Rollen plus App-Performance-Review, danach fokussierte Re-Reviews.
  Belegte Findings korrigiert: Multipart-HMAC, sichtbare Dateifehler, DST-Fold,
  vorgangsgebundene Origins, native DMS-Pfade und DB-Port; atomare Erstanlage,
  Wiederherstellung fehlender Bildbytes und kollisionsfreie WP-Importdateinamen.
- Kein Live-Bestandslauf, keine Live-Aktivierung, kein Commit oder Push.


## UI-Nacharbeit 2026-10-20

- Nutzerwunsch: Sync-Status anschaulicher im bestehenden OpenXE-Layout darstellen.
- Aktivierungsstatus und Sync-Regeln oben, zusammengefasste Vorschau-/Abgleichaktionen,
  getrennte Ergebnisansicht, deutsche Status-Badges, einklappbare Laufhistorie und Technik.
- Vorherige Laeufe ueber CSRF-geschuetztes view-POST anzeigen, ohne Sync auszufuehren.
- Lokale Demo aktualisiert; Sync bleibt aus. Keine WP-/App- oder Sync-Algorithmus-Aenderungen.
- Verifiziert: 37 PHP-Lints, Formularregressionen 2 Tests/6 Assertions, Read-only-View-POST
  gegen isolierte DB, nativer Browser bei 1280px und Ergebnis-Pruefansicht bei 700px.
  Tabellen scrollen lokal, kein Seiten-Overflow; CSS-Layout-Detektor ohne Findings.
