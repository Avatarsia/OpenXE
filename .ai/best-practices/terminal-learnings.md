# Terminal-Learnings — OpenXE

> Gesammelte Do's und Don'ts aus Terminal-Interaktionen.
> Wird von KI-Agenten nach jedem Terminal-Schritt ergänzt, wenn neue Erkenntnisse entstehen.

---

## Allgemeine Regeln

### ✅ DO

- **Output immer prüfen** — Auch bei Exit-Code 0 kann der Output Warnungen enthalten
- **Fehlermeldungen vollständig lesen** — Nicht nur die letzte Zeile, sondern den gesamten Stacktrace
- **Vor destruktiven Befehlen `--dry-run`** nutzen, wenn verfügbar
- **Pfade mit Leerzeichen** immer in Anführungszeichen setzen (Windows-spezifisch: `"C:\Users\3D Partner\..."`)
- **PowerShell-Syntax** verwenden, nicht Unix-Bash (Projekt läuft auf Windows)
- **`git status`** vor und nach Git-Operationen prüfen

### ❌ DON'T

- Nie `rm -rf` oder `Remove-Item -Recurse -Force` ohne vorherige Bestätigung
- Nie `git push --force` auf `master` oder `development`
- Nie Composer/npm install ohne vorherige Prüfung der bestehenden `lock`-Datei
- Nie lang laufende Befehle ohne Timeout starten

---

## PHP-spezifisch

### ✅ DO
- `php -l <datei>` nach jeder PHP-Datei-Änderung
- `composer validate` nach `composer.json` Änderungen
- `./vendor/bin/phpunit` nach Code-Änderungen

### ❌ DON'T
- Nicht `php artisan` verwenden — OpenXE nutzt kein Laravel
- Nicht `composer update` ohne explizite Anweisung (nur `composer install`)

---

## Git-spezifisch

### ✅ DO
- `git diff --stat` vor dem Commit prüfen (sind die richtigen Dateien staged?)
- `git log -1` nach dem Commit prüfen (ist die Message korrekt?)
- Branch-Name vor Push verifizieren

### ❌ DON'T
- Nie `git add .` wenn `modern/` oder `node_modules/` nicht in `.gitignore` wäre
- Nie ohne Branch-Check committen (`git branch --show-current`)

---

## Gelernte Lektionen

<!-- KI-Agenten: Fügt hier neue Erkenntnisse hinzu, die aus Terminal-Fehlern entstanden sind -->
<!-- Format: ### YYYY-MM-DD — [Agent] \n Beschreibung des Problems und der Lösung -->

*Noch keine Einträge.*
