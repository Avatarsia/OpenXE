# Upgrader UI Changelog

## 2026-09-08
- Rebase auf upstream/master (`ac294993`, enthält Upstream `branchupgrade`):
  - `remote.json` wird im Upstream-Listenformat gelesen (erster Eintrag mit
    `active: true` ist die Upgrade-Quelle)
  - Zweigauswahl (Radio-Liste) in eigener Karte, `do_upgrade` mit abweichendem
    Zweig läuft als Upstream-Migration (`do_migrate`/`upgrade_branch`)
  - Remote-Editor (`save_remote`/`reset_remote_origin`) entfernt — schrieb das
    alte Objektformat und hätte die Zweigliste zerstört
  - Engine: Objekt-Validierung durch `is_array`-Guard auf die Liste ersetzt

## 2026-05-20
- Code-Review-Refaktorierung umgesetzt (6 Commits):
  - Admin-Auth-Guard am AJAX-Download-Endpunkt; tote `get_log_status`-Route
    samt 2s-Polling und Lock-Banner entfernt
  - Engine-Aktionen über Lookup-Tabelle dispatcht; `createRollbackTag()` ist
    jetzt eigener Helper und wird auch beim DB-Upgrade aufgerufen
  - Validation-Pattern und Engine-Result-Strings als Klassen-Konstanten
  - CSS und JS in `www/css/upgrade.css` und `www/js/upgrade.js` ausgelagert
  - XSS-Härtung: zentrale `esc()`-Helper-Methode, `OUTPUT_FROM_CLI` wird
    escaped statt nl2br, Log-Box mit `white-space:pre-wrap` für Umbrüche
  - Diverse Tote-Code-Cleanups (doppelte Tpl->Set, ungenutzte Session-
    Variable, Hidden-Input mit unerreichbarem Default, gemischte Emoji-
    Encoding) und Whitespace-Normalisierung
- README-AVATARSIA: veraltete LogFile()-Verweise und Lock-Feature-
  Dokumentation entfernt, Asset-Layout dokumentiert

## 2025-12-15
- Branch `upgrader-ui` neu von `master` erstellt, bestehende `local_test_branch` unverändert gelassen.
- Upgrade-UI umgebaut: Statuskarte (Deutsch) mit letzter Aktion/Zeit/Version, klarerer Log-Viewer, Aktionen zusammengefasst.
- Backend erweitert: Remote-URL und Branch können über die Oberfläche gesetzt werden (Validierung, Schreibvorgang nach `upgrade/data/remote.json`).
- Ergebnisanzeige pro Lauf (erfolgreich/Fehler/alles aktuell), Log-Fallback wenn noch kein Protokoll existiert.
- Checkboxen für Details und Erzwingen bleiben nach Requests erhalten.
- Nächster Schritt: Änderungen nach `local_test_branch` kopieren (Cherry-Pick geplant).
- Upgrade-Abläufe laufen im selben Tab (keine neuen Fenster).
- Versionsvergleich hinzugefügt (Installiert, lokaler Branch/Commit, Upgrade-Ziel).
- Status-Banner und farbige Karten je Ergebniszustand ergänzen; geführte Hinweise mit nächstem Schritt abhängig vom Lauf (z.B. „Upgrade empfohlen“ bei Differenzen, „Alles aktuell“ bei 0 Differenzen).
- Guided-Hinweise aus eigenem Feld entfernt; Hinweise erscheinen direkt im farbigen Statusbereich (Banner + Karte).
