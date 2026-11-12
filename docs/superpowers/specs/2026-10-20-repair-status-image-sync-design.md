# Design: Status- und Bildabgleich zwischen WP, OpenXE und App

Datum: 2026-10-20
Status: Am 2026-10-20 lokal freigegeben, implementiert und verifiziert; Live-Aktivierung separat.

## Auftrag und pruefbares Ziel

Der Nutzer will Status und Reparaturbilder systemuebergreifend aktuell halten,
auch fuer bereits auseinanderliegende Bestandsvorgaenge. Weitere Leistungsdaten,
Notizen, Belege und Preise sind nicht Teil dieser Erweiterung. Offline-Bearbeitung
ist kein eigener Produkt-Workflow dieser Aufgabe.

Nach Abschluss eines Abgleichs stimmen die gemappten Status und Bildbestaende
aller eindeutig verbundenen Reparaturvorgaenge ueberein. Wiederholung erzeugt
keine neuen Statusereignisse, Bildduplikate oder Kundenmails. Fehler bleiben
sichtbar und wiederholbar; Teilfehler gelten nicht als vollstaendiger Sync.

## Gepruefter Bestand und Ursachen

- App spricht WP, nicht direkt OpenXE: App lib/features/status/status_repository.dart
  und lib/features/repair_detail/repair_repository.dart. Diese Architektur bleibt.
- OpenXE RepairApiController.php:328 wertet WP-Status nur bei Neuanlage aus.
  App/WP-Aenderungen gehen bei bestehenden Tickets deshalb nicht in den ERP-Status.
- RepairSyncService.php:182 stoppt den bisherigen einmaligen Outbound-Backfill nach
  completed; ein dauerhafter beidseitiger Vergleich existiert nicht.
- WP includes/class-mobile-rest-api.php:911-981 speichert Bilder, ohne anschliessend
  p3d_erp_push aufzurufen. Der Statuspfad ruft den Hook dagegen auf.
- OpenXE RepairApiController.php:765-856 behandelt Bildimportfehler nur als Log;
  Gesamt-Push gilt weiter als erfolgreich. Pro Request maximal 20 Anhaenge;
  groessere Bestandsmengen werden ohne fortgesetzten Bild-Cursor abgeschnitten.
- WP-Medien-IDs werden bereits in OpenXE-DMS-Markern zur Deduplizierung verwendet.
  Es fehlt eine Gegenrichtung fuer neue ERP-Bilder und ein Bildmanifest-Abgleich.
- WP hat Status-Timeline mit Herkunftsmarkern; OpenXE hat ticket_protokoll und
  Queue-/Sync-Logs. Allgemeines updated_at ist kein Status-Aenderungsdatum.

## Laufender Statusabgleich

Entscheidung dieses Entwurfs: echte Benutzer-Statusereignisse erhalten eine stabile
UUID, Herkunft (WP oder OpenXE) und UTC-Serverzeit mit Mikrosekunden. App-Aenderungen
werden vom WP-Server datiert; die Geraeteuhr ist unbeteiligt. Beide Serveruhren werden
bei Inbetriebnahme auf korrekte Zeit/Zeitzone geprueft. Unter diesen Voraussetzungen
entscheidet das neuere Statusereignis, nicht Empfangs-/Retry-Zeit und nicht updated_at.

- Vergleichsschluessel: (changed_at_utc, origin_priority, event_id), auf beiden Seiten
  identisch. Nur bei exakt gleichem Zeitpunkt gewinnt OpenXE vor WP, danach UUID.
  Ein bestaetigtes Ereignis wird bei erneutem Versand unveraendert wiederverwendet.
- OpenXE vergibt beim Uebernehmen des kanonischen Ereignisses atomar eine steigende
  Statusrevision. WP speichert Ereignisschluessel und bestaetigte Revision ebenfalls.
  Eine neue lokale WP-Aenderung wird nicht durch ein aelteres ERP-Echo ueberschrieben.
- Statusspeicherung und dauerhaftes Einreihen des Ereignisses erfolgen atomar;
  Nachrichten gelten erst nach Commit als angenommen. Gleichbleibender Status
  ohne echte Aenderung erzeugt kein neues Ereignis.
- Aeltere Ereignisse und wiederholte event_id sind erfolgreiche No-Ops mit Rueckgabe
  des aktuellen kanonischen Stands. Transportaenderungen erzeugen keine neue UUID,
  keine neue Aenderungszeit und keine weiteren Kundenbenachrichtigungen.
- Bestehende native Status-/Kategorie-Mappings bleiben erhalten. Ein Echo eines
  gemappten WP-Status setzt einen feineren OpenXE-Status nicht zurueck. Echte neue
  WP-Benutzeraenderungen werden kategoriegerecht aufgeloest. Status ohne oeffentliches
  Mapping wird als intern behandelt und nicht willkuerlich nach WP projiziert.
- Sofortige Zustellung nach erfolgreicher lokaler Speicherung mit begrenztem Timeout;
  dauerhafte Queue und bestehender Backoff bei Fehlern. Aeltere Queue-Ereignisse koennen
  durch spaeteres Eintreffen keinen neueren Status zuruecksetzen.
- Neue gezielte Sync-Endpunkte uebertragen ausschliesslich Status/Bilder. Kein Rueckgriff
  auf volle push_details-Payloads fuer Reparaturdaten, Preise oder Beleg-Snapshots.

## Bildabgleich

- JPEG, PNG und WebP aus dem verknuepften Reparaturvorgang in beide Richtungen.
  App liest weiter WP-Bilder, daher ist keine dritte Bildablage erforderlich.
- OpenXE-Quellen: echte Bilddateien am ticket_header sowie Bildanhaenge veroeffentlichter
  Nachrichten desselben Tickets einschliesslich API-Eingang. Unversendete Entwuerfe,
  private Tickets und fachfremde DMS-Dateien werden nicht exportiert.
- Neue App-/WP-Bilduploads reihen direkt Bildsync ein. Neue ERP-Bilder werden beim
  relevanten Upload erfasst; der regelmaessige Manifest-Abgleich faengt verpasste Hooks ab.
- Pro Bild stabile Ursprung-ID (Instanz + Medien-ID), Dateihash und lokale Zuordnung
  speichern. Importierte Bilder behalten ihren Ursprung; kein Export-Import-Kreis.
- Beim Erstabgleich Legacy-ID-/URL-Marker uebernehmen; Hashvergleich pro Vorgang faengt
  bereits vorhandene identische Kopien ohne gemeinsamen Marker ab. Nie global ueber
  unterschiedliche Kunden/Vorgaenge deduplizieren oder verknuepfen.
- Fehlende Bilder ergaenzen; keine automatischen Loeschungen. Bearbeitung, Zuschneiden,
  Caption-Sync und automatische Loeschweitergabe sind nicht Bestandteil.
- Transfer in begrenzten Batches mit persistentem Cursor; auch mehr als 20 Bilder
  vollstaendig uebertragen. Kleine Manifeste separat von Dateiinhalten transportieren.
- Vorhandene Authentifizierung, Groessen-/MIME- und Zielhost-Pruefungen erhalten.
  ERP-Bilder authentifiziert uebertragen, keine generische oeffentliche DMS-URL einfuehren.
  In WP vorhandene Upload-/Thumbnail-Regeln wiederverwenden.
- Ein Bild gilt erst nach physischer Ablage, DB-Verknuepfung und Hashpruefung als fertig.
  Einzelbildfehler werden dauerhaft erfasst und gezielt wiederholt. Kein Erfolg nur
  aufgrund einer HTTP-200-Antwort fuer einen unvollstaendigen Gesamt-Push.

## Rueckwirkender und regelmaessiger Abgleich

- Alle WP-Reparaturanfragen und bereits verbundenen ERP-Reparaturtickets einbeziehen,
  einschliesslich abgeschlossener Vorgaenge. Identitaet ueber eindeutige request_number
  und bestehende Ticketverknuepfung; Mehrdeutigkeiten nicht automatisch zusammenfuehren.
- Fehlende ERP-Anlage ueber bestehenden Anlagepfad nachholen; keine privaten, rein lokalen
  ERP-Tickets automatisch als neue Kundenanfrage in WP veroeffentlichen.
- Zuerst lesender Vorschau-Lauf mit Zaehlern: gleich, Statusabweichung, fehlende Bilder,
  nicht verbunden, unklar, Fehler. Uebernahme wird anschliessend als resumierbarer Job gestartet.
- Historische Statusentscheidung aus letzter belegter echter Statusaenderung ableiten;
  importierte Echo-Timelineeintraege ausschliessen und Zeitzonen normalisieren. Fehlende,
  widerspruechliche oder zeitlich nicht unterscheidbare Historien als unklar ausweisen und
  einmalig einen Zielstand auswaehlen lassen. Kein allgemeines updated_at als Ersatzbeweis.
- Fuer bekannte gleiche Zustaende einen gemeinsamen Ausgangsstand initialisieren, ohne
  eine neue Benutzerhandlung vorzutaueschen. Rueckwirkender Lauf versendet keine Kundenmails.
- Alle fuenf Minuten resumierbarer, paginierter Abgleich mit persistiertem Vollscan-Cursor;
  Vollscan-Zyklen duerfen keine alten oder abgeschlossenen Vorgaenge dauerhaft auslassen.
  Bereits synchronisierte Bilder nicht erneut herunterladen. Wiederholbare manuelle Aktion
  fuer Vorschau und Abgleich; bestehende Sync-Statusseite zeigt Fortschritt und Teilfehler.
- Zeitgesteuerte Ausfuehrung ueber echte Cron-/Worker-Ausloesung pruefen; WP-Cron allein
  garantiert bei fehlendem Website-Traffic keinen Fuenf-Minuten-Takt.
- App aktualisiert Status und Bilder beim Oeffnen, bei Rueckkehr in den Vordergrund,
  nach eigenen Aenderungen und alle 30 Sekunden im sichtbaren Vorgang. Hintergrund,
  Editieren und Entsorgung des Screens respektieren; keine konkurrierenden Fetches.

## Verifikation und Umsetzung

- Zuerst Regressionen fuer WP-/App-Status am bestehenden Ticket und ERP-Gegenrichtung.
- Reihenfolge vertauschen, Retry nach neuerer Aenderung, Echo, doppelte Zustellung,
  gleichzeitige Aenderung und crashsichere Queue testen; aeltere Ereignisse verlieren.
- Bildtests: App-Upload loest ERP-Sync aus, ERP-Bild erscheint in WP/App, Bestand in beide
  Richtungen, Legacy-Marker, identische Datei, ueber 20 Bilder, Timeout/defekte Datei,
  Teilfehler, Wiederholung ohne Duplikate und keine automatischen Loeschungen.
- Historische Vorschau darf keine Daten oder Mails veraendern. Abgleich erhaelt Preise,
  Texte, Notizen, Belege sowie eindeutige Kunden-/Ticketzuordnung.
- App-Refresh mit gezielten Provider-/Lifecycle-Tests; flutter analyze nach Dart-Aenderungen.
  php -l fuer alle beruehrten PHP-Dateien sowie bestehende ERP-/WP-Suiten.
- Lokale gekoppelte Testinstanzen und echter Browser: unterschiedliche Ausgangsstaende
  angleichen, zwei Wiederholungen ohne weitere Aenderungen, danach gegenseitige Status-
  und Bildaenderungen pruefen. Lokale Test-Transportfreigaben strikt getrennt von Produktion.
- Implementierung in bestehenden Feature-Worktrees; App-Arbeit in isoliertem Feature-Worktree.
  Bestehende uncommittete Reparaturbeleg-Arbeit bleibt erhalten. Keine automatische
  Production-Aktivierung, kein Live-Bestandslauf und kein Commit/Push in dieser Freigabe.

## Noch nicht vom Nutzer festgelegte technische Entscheidungen

UTC-Ereignisschluessel und ERP-Revision, deterministische Gleichstandsregel,
Fuenf-Minuten-Abgleich, 30-Sekunden-App-Refresh, additive Bilduebernahme und
separate gezielte Sync-Endpunkte sind Vorschlaege dieses Entwurfs. Der Benutzer
hat bislang nur Ziele und fachlichen Umfang (Status/Bilder) festgelegt.
