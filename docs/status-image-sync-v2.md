# Status- und Bildsync V2 (lokale Implementierung)

## Umfang

WP und die App bleiben eine gemeinsame Backend-Seite. Die App liest weiterhin WP.
OpenXE und WP gleichen Status und JPEG-/PNG-/WebP-Reparaturbilder in beide Richtungen
ab, einschliesslich vorhandener und abgeschlossener Vorgaenge. Andere Leistungsdaten,
Preise, Notizen und Beleg-Snapshots werden durch V2-Abgleiche nicht ueberschrieben.

Neue Funktionen sind standardmaessig ausgeschaltet. Lokale Implementierung und
Fixture-Aktivierung sind freigegeben; Live-Rollout und Live-Bestandslauf sind separat.

## Einrichtung

1. Auf beiden Seiten Schema/Plugin aktualisieren; ERP-Modulschema ist 1.8.0.
2. Vorhandene Server-API-URLs und Schluessel aus den jeweiligen geschuetzten
   Einstellungen konfigurieren; keine App-Mitarbeiter-Tokens an ERP weiterreichen.
3. Auf beiden Seiten Status-/Bildsync aktivieren (sync_v2_enabled). In OpenXE unter
   Reparaturen > Sync-Status, in WP bei den ERP-Einstellungen.
4. Serverzeit/NTP und historische Zeitzone pruefen. OpenXE nutzt fuer alte
   ticket_protokoll-Zeiten status_history_timezone (Vorgabe Europe/Berlin), WP die
   konfigurierte Website-Zeitzone. Neue Ereignisse nutzen UTC mit Mikrosekunden.
5. In OpenXE zuerst Bestand pruefen (Vorschau). Fehlende Zuordnungen oder unklare
   historische Statuswechsel anzeigen lassen. Ein belegter Benutzerwechsel gewinnt;
   ein allgemeines updated_at oder ein Sync-Echo ist kein Statuszeitpunkt.
6. Status und Bilder abgleichen startet einen fortsetzbaren Job. Offene Punkte bleiben
   sichtbar. Unklare Status lassen sich explizit aus WP/App oder OpenXE uebernehmen.
   Beide Kandidaten werden beim Ergebnis angezeigt. Der rueckwirkende Lauf sendet
   keine Kundenmails. Die App liest nach Oeffnen/Resume und alle 30 Sekunden im
   sichtbaren Vorgang Status/Bilder still nach.

## Worker

Bestehender OpenXE-Prozessstarter repair_sync bearbeitet auch den neuen Coordinator.
Fuer einen von Webzugriffen unabhaengigen Takt kann alternativ auf dem ERP-Host ein
realer Cron jede Minute laufen (Pfade/Benutzer/Logrotation passend setzen):

```cron
* * * * * /usr/bin/php /var/www/openxe/cronjobs/repair_exchange_cli.php >> /var/log/openxe-repair-sync.log 2>&1
```

Der CLI-Worker respektiert WFdbhost/WFdbport und die bestehende conf/user.inc.php,
startet Vollscans im Fuenf-Minuten-Abstand und setzt laufende Batches fort. Einzelne
Statusaenderungen und neue Bilder werden sofort versucht; dauerhafte Queues und
Backoff uebernehmen bei Fehlern. Ein neues Upload waehrend eines laufenden Jobs wird
ueber eine Generation nicht versehentlich aus der Queue entfernt.

Auf dem WP-Host ebenfalls echten Cron verwenden:

```cron
*/5 * * * * cd /var/www/wordpress && /usr/local/bin/wp p3d repair-sync >> /var/log/wp-repair-sync.log 2>&1
```

Ein reiner WP-Cron ohne Site-Traffic garantiert keinen Takt. Genau einen passenden
Betriebsweg einrichten; parallele ERP-Scan-Ticks sind durch DB-Advisory-Lock gesperrt.

## Datenregeln

- Echte Statusereignisse tragen UUID, Herkunft und urspruengliche UTC-Serverzeit.
  Retries aendern diese nicht. Reihenfolge: Zeit, bei exaktem Gleichstand OpenXE vor
  WP, dann UUID. OpenXE bestaetigt eine steigende Revision.
- Alte/duplizierte Events werden bestaetigt, ohne den neueren Stand zurueckzusetzen.
  Interne ERP-Status behalten die native Kategorie-/Kundenstatus-Abbildung.
- Historische Zeiten in der doppelten DST-Stunde gelten als unklar.
- Bilder werden hinzugefuegt, nicht automatisch geloescht. Ursprung-IDs sind an den
  Vorgang gebunden, Hash-Deduplikation bleibt auf denselben Vorgang beschraenkt.
- Ganze Dateien werden authentifiziert uebertragen, nicht als neue oeffentliche
  ERP-DMS-URLs. Multipart-HMAC nutzt image-v1 mit signiertem Vorgang, Ursprung, Hash
  und urspruenglichem Uploaddatum; Bildbytes werden gegen den signierten Hash geprueft.
- Nur zugeordnete, nicht private Reparaturtickets und veroeffentlichte Bildanhaenge
  sowie Ticket-Dateien exportieren. Nachrichten-Entwuerfe bleiben ausgeschlossen.
- Native direkte, flache und verschachtelte DMS-Ablagen bleiben lesbar; neue Dateien
  werden im nativen verschachtelten Pfad gespeichert. Fehlende gespeicherte Dateien,
  Hashkonflikte und Teilfehler werden sichtbar, statt als vollstaendiger Sync zu gelten.
- >20 Bilder werden in fortgesetzten Batches uebernommen. Dauerhafte Fehler bei den
  ersten Bildern blockieren spaetere Bilder nicht. Einzelausfaelle bleiben wiederholbar.
- Fehlende ERP-Anlage wird nur bei freiem Ticket-Schluessel nachgeholt. Fremde/private
  oder mehrdeutig zugeordnete vorhandene Tickets werden nicht uebernommen. Der
  explizite exchange_initial-Pfad ist create-only; wiederholte Anlage ueberschreibt
  keine vorhandenen Reparaturdaten.
- Legacy V1 bleibt bei deaktiviertem V2 erhalten. V2-Statusjobs und V1-Altjobs werden
  im Worker getrennt gefiltert; alte V1-Details-Pushes werden bei V2 nicht weiter als
  Status-/Bildsync verwendet.

## Tests und lokale Instanzen

ERP-Tests erfordern explizite isolierte DB-Flags. Neue Tests verwenden ausschliesslich
repair_status_exchange_20261020, repair_media_exchange_20261020 und
repair_reconcile_exchange_20261020. Sie duerfen Tabellen dieser Fixture-DBs loeschen;
niemals diese Flags auf eine echte Instanz richten.

Der gekoppelte HTTP-Test liegt lokal unter C:/tmp/openxe-repair-exchange-20261020.
Er verwendet separate Wegwerf-DBs repair_coupled_openxe_20261020 und
repair_coupled_wp_20261020 und blockiert alle Mailzustellungen. Quellcode ist read-only
in den Testcontainer gemountet; Zugangsdaten kommen nur aus einer lokalen ENV-Datei.
Die Test-HTTP-Freigabe REPAIR_EXCHANGE_ALLOW_LOCAL_HTTP=1 erlaubt im ERP-Transport nur
Loopback oder den expliziten REPAIR_EXCHANGE_LOCAL_HOST. Diese ENV-Freigabe ist nicht
fuer den produktiven Betrieb vorgesehen; dort wird HTTPS vorausgesetzt.


## Lokaler Abschlussstand 2026-10-20

ERP 178 Tests / 516 Assertions; WP 35 Tests / 159 Assertions; App 431 Tests und
flutter analyze erfolgreich. Der gekoppelte HTTP-Test prueft bidirektionale Daten,
read-only Vorschau, wiederholbaren Bestandsabgleich, App-Endpunkte sowie die sichere
nachtraegliche Anlage fehlender ERP-Tickets. Ursprung und Upload-ID bleiben bei der
Reparatur verlorener Bildbytes erhalten; WP-Importdateinamen sind kollisionsfrei.

Die lokale OpenXE-Demo unter http://127.0.0.1:18081 ist auf Schema 1.8.0 aktualisiert.
V2 bleibt dort ausgeschaltet. In keiner Live-Instanz wurde aktiviert oder abgeglichen.
