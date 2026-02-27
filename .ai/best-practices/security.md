# Sicherheitsrichtlinien — OpenXE

> Verbindliche Sicherheitsstandards für alle Entwickler und KI-Agenten.

## SQL Injection Prevention

### Regel #1: DatabaseService mit Named Parameters

Neuer Code MUSS `$this->app->DatabaseService` verwenden — NICHT `$this->app->DB->Select()`.

```php
// ✅ RICHTIG — Named Parameters (Standard!)
$row = $this->app->DatabaseService->selectRow(
    "SELECT belegnr, status FROM gutschrift WHERE id = :id LIMIT 1",
    ['id' => $id]
);

$this->app->DatabaseService->update(
    "UPDATE gutschrift SET zahlungsstatus = :status WHERE id = :id LIMIT 1",
    ['status' => $zahlungsstatus, 'id' => $id]
);

// ✅ Mehrere Parameter — immer benannt
$rows = $this->app->DatabaseService->select(
    "SELECT * FROM auftrag WHERE status = :status AND kunde = :kundeId",
    ['status' => 'offen', 'kundeId' => $kundeId]
);

// ❌ FALSCH — Positionale Parameter (nicht verwenden)
$this->app->DatabaseService->selectValue("SELECT status FROM gutschrift WHERE id = ?", [$id]);

// ❌ FALSCH — Legacy DB-Klasse mit Interpolation
$this->app->DB->Select("SELECT * FROM artikel WHERE id = $id");
$this->app->DB->Select(sprintf("SELECT * FROM artikel WHERE id = %d", $id));
$this->app->DB->Select("SELECT * FROM artikel WHERE name = '$name'");
```

### Regel #1a: Kein String-Concatenation in SQL

```php
// ❌ FALSCH — String-Verkettung
$sql = "SELECT * FROM artikel WHERE id = " . $id;
$sql = "SELECT * FROM artikel WHERE name = '" . $name . "'";

// ✅ RICHTIG — Alles über Named Parameters
$sql = "SELECT * FROM artikel WHERE id = :id";
```

### Verfügbare DatabaseService-Methoden

| Methode | Rückgabe | Verwendung |
|---------|----------|------------|
| `select($sql, $params)` | `array` | Alle Zeilen |
| `selectRow($sql, $params)` | `?array` | Erste Zeile oder null |
| `selectValue($sql, $params)` | `mixed` | Einzelwert |
| `selectColumn($sql, $params)` | `array` | Erste Spalte aller Zeilen |
| `selectPairs($sql, $params)` | `array` | col1 => col2 Map |
| `insert($sql, $params)` | `int` | Insert-ID |
| `update($sql, $params)` | `int` | Affected Rows |
| `delete($sql, $params)` | `int` | Affected Rows |
| `execute($sql, $params)` | `bool` | DDL/generisch |
| `insertArray($table, $data)` | `int` | Insert aus Array |
| `updateArray($table, $data, $pk, $pkVal)` | `int` | Update aus Array |
| `transactional(callable)` | `mixed` | Transaction-Wrapper |
| `validateIdentifier($name)` | `void` | Tabellen-/Spaltennamen prüfen |

### Regel #2: Kein User-Input in SQL-Strukturen

```php
// ❌ FALSCH — Table/Column-Namen können NICHT parametrisiert werden
$table = $_GET['table'];
$this->app->DatabaseService->select("SELECT * FROM $table WHERE id = :id", ['id' => $id]); // NEIN!

// ✅ RICHTIG — validateIdentifier() verwenden
$this->app->DatabaseService->validateIdentifier($table);
$this->app->DatabaseService->select("SELECT * FROM `$table` WHERE id = :id", ['id' => $id]);

// ✅ ALTERNATIV — Whitelist
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
