# WooCommerce-Shopzuordnung: Spalte Lagerbestand (Design)

Datum: 2026-11-27 · Branch: `feature/woocommerce-artikeldaten`

## Intent
In der Uebersicht `Artikel -> WooCommerce` (`AssignmentPage`) den echten Lagerbestand je Artikel anzeigen und direkt korrigieren koennen — Ziel: im Worst Case Inventur-Abschlaege ohne Wechsel ins Lager-Modul.

## Ziele / Nicht-Ziele / Anti-Ziele
- Ziel: Spalte "Lagerbestand" = Summe `lager_platz_inhalt.menge` ueber alle Nicht-Sperrlager-Plaetze (Semantik wie `erpAPI::ArtikelAnzahlLager()`).
- Ziel: Korrektur als Zielbestand (gezaehlte Menge), System bucht die Differenz als Lagerbewegung.
- Nicht-Ziel: Pseudo-Lagerzahl, Umlagern, Einlagern auf neue/leere Lagerplaetze, Chargen/MHD/Seriennummern-Buchungen.
- Anti-Ziel: Bestandsaenderung ohne `lager_bewegung`-Eintrag; Buchung auf Basis eines veralteten Seitenstands; negativer Platzbestand.

## Approaches
- A) Inline-Feld, System verteilt Abschlag auf Plaetze — schnell, aber Platzwahl automatisch.
- B) Nur Anzeige + Popup pro Lagerplatz — exakt, mehr Klicks/UI.
- **C) Mischform (gewaehlt):** 0 oder 1 Lagerplatz mit Bestand -> Inline-Feld; mehrere Plaetze -> Popup mit Ist-Menge je Platz. Exakt wo noetig, schnell sonst.

## Verhalten
- **Anzeige** pro Zeile (auch Varianten, je eigener Bestand):
  - `lagerartikel = 0` -> "–", nicht editierbar.
  - `chargenverwaltung > 0` oder `mindesthaltbarkeitsdatum = 1` oder Seriennummernpflicht (`seriennummern` nicht leer/`keine`) -> Zahl, read-only, Hinweis "Korrektur im Lager-Modul".
  - Fehlendes Lager-Buchungsrecht -> Zahl, read-only.
  - sonst editierbar gemaess C.
- **0 Plaetze mit Bestand:** Inline-Feld (Wert 0). Zugang -> `LagerEinlagern` auf Standard-Lagerplatz (`artikel.lager_platz`). Kein Standard-Lagerplatz -> Fehlermeldung, keine Buchung.
- **1 Platz:** Inline-Feld. Zugang -> `LagerEinlagern` auf diesen Platz; Abschlag -> `LagerAuslagernRegal` von diesem Platz.
- **>1 Platz:** Zahl + Button "Korrigieren" -> Popup: Tabelle Lagerplatz (Kurzbezeichnung, Lager) | Bestand | Ist-Menge. Speichern bucht je geaenderter Zeile die Differenz.
- **Grund** in `lager_bewegung.referenz`: `Inventur-Korrektur (WooCommerce-Uebersicht)`; Bearbeiter = angemeldeter User (Standardverhalten der erpAPI-Funktionen).
- **Validierung:** Ist-Menge numerisch, >= 0. Optimistic Check: Client sendet den angezeigten Bestand (gesamt bzw. je Platz) mit; weicht der aktuelle DB-Stand ab -> Abbruch mit "Bestand wurde zwischenzeitlich geaendert, Seite neu laden".
- **Nach Erfolg:** Zelle zeigt neuen Bestand + Status "Gespeichert." (kein Reload). Shop-Sync laeuft ueber vorhandene Mechanik (`cache_lagerplatzinhaltmenge = -999`, `storage_cache = -999`, `laststorage_changed`), keine eigene Sync-Logik.
- **Rechte:** zusaetzlich zu `artikel/edit` + `onlineshops/edit`: `lager/bucheneinlagern` UND `lager/buchenauslagern`; ausserdem `UserProjektRecht` auf Artikelprojekt (wie heute).

## Constraints
- Buchung ausschliesslich ueber `erpAPI::LagerEinlagern` / `erpAPI::LagerAuslagernRegal` (Hooks, VPE, Zusammenfassen, Cache-Invalidierung bleiben erhalten). `LagerAuslagernRegal` wirft bei zu geringem Bestand — Exception als DomainException-Text an UI.
- erpAPI nutzt `$app->DB` (Legacy), `AssignmentPage` nutzt `Database` + `transactional()`. Lagerbuchung NICHT in `transactional()` einbetten, solange nicht verifiziert ist, dass beide dieselbe Verbindung teilen. Popup mit mehreren Plaetzen: erst alle Zeilen validieren, dann buchen; Teilfehler mit Liste gebuchter/nicht gebuchter Plaetze melden.
- DECIMAL-Werte kommen als String — Vergleiche normalisiert (float mit Toleranz bzw. bcmath), Tests mit DB-Shape-Strings.
- Bestehender Stil: HTML-String-Rendering in `AssignmentPage`, jQuery in `woocommerce-assignment.js`, Kommando-Dispatch ueber `handle()`/`data-command`, CSS in `woocommerce-admin.css`.
- Tests in `tests/woocommerce/assignment.php` (Fixture `fixtures/assignment.php`), `php -l` auf geaenderte PHP-Dateien.

## Dem Builder ueberlassen
- Konkrete Kommandonamen (Vorschlag `stock_set`, `stock_places`), Popup-Technik (vorhandenes Dialog-Pattern der Seite bzw. jQuery-UI-Dialog wie im OpenXE-Backend).
- Bestands-Abfrage als eine aggregierte Query fuer die 50 Zeilen der Seite (kein N+1).
- Erkennung Seriennummernpflicht anhand der tatsaechlichen Werte in `artikel.seriennummern` verifizieren.

## Meine Calls (bitte pruefen)
- Popup zeigt nur Nicht-Sperrlager-Plaetze (konsistent zur Summe).
- Kein Zugang auf neue Plaetze im Popup — dafuer Lager-Modul.
- Eingabe = Zielbestand, nicht Delta.
- Buchungsrecht = beide Lager-Rechte (ein- und auslagern).
