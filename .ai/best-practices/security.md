# Sicherheitsrichtlinien — OpenXE

> Verbindliche Sicherheitsstandards für alle Entwickler und KI-Agenten.

## SQL Injection Prevention

### Regel #1: Prepared Statements für alles

```php
// ✅ RICHTIG
$this->app->DB->preparedSelect(
    "SELECT * FROM artikel WHERE id = :id AND aktiv = :aktiv",
    ['id' => $id, 'aktiv' => 1]
);

// ❌ FALSCH — NIEMALS:
$this->app->DB->Select("SELECT * FROM artikel WHERE id = $id");
$this->app->DB->Select(sprintf("SELECT * FROM artikel WHERE id = %d", $id));
$this->app->DB->Select("SELECT * FROM artikel WHERE name = '$name'");
```

### Regel #2: Kein User-Input in SQL-Strukturen

```php
// ❌ FALSCH — Table/Column-Namen können NICHT parametrisiert werden
$table = $_GET['table'];
$this->app->DB->preparedSelect("SELECT * FROM $table WHERE ..."); // NEIN!

// ✅ RICHTIG — Whitelist verwenden
$allowedTables = ['artikel', 'auftrag', 'rechnung'];
if (!in_array($table, $allowedTables, true)) {
    throw new InvalidArgumentException("Unknown table: $table");
}
```

## XSS Prevention

```php
// ✅ Output-Escaping
$this->app->Tpl->Set('NAME', htmlspecialchars($userInput, ENT_QUOTES, 'UTF-8'));

// ❌ NIEMALS unescapten User-Input ausgeben
$this->app->Tpl->Set('NAME', $userInput);
```

## Authentifizierung & Autorisierung

- Jede Seite MUSS Berechtigungen prüfen bevor Daten angezeigt/verarbeitet werden
- Ownership-Checks: `WHERE id = :id AND firma = :firma`
- API-Endpoints müssen Token/Session validieren

## Datei-Uploads

- Dateityp serverseitig prüfen (nicht nur Extension)
- Upload-Verzeichnis außerhalb des Web-Roots
- Generierte Dateinamen (keine User-Eingabe im Pfad)

## Logging

- ❌ NIEMALS Passwörter, API-Keys, Tokens loggen
- ✅ Fehlgeschlagene Login-Versuche loggen
- ✅ Kritische Datenänderungen loggen (Audit-Trail)
