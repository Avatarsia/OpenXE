---
name: prepared-statements
description: Convert legacy sprintf/interpolated SQL queries to PDO prepared statements in OpenXE
---

# Skill: SQL Prepared Statements Migration

## When to Use
Use this skill when you encounter any of these patterns:
- `sprintf("SELECT ... WHERE id = %d", $variable)`
- `"SELECT ... WHERE name = '$variable'"`
- `$app->DB->Select("... {$variable} ...")`
- Any SQL query with PHP variable interpolation

## Step-by-Step Process

### 1. Identify the Query Pattern
Look for the query source: Is it in a Page (`www/pages/`), Widget (`www/widgets/`), or Service (`classes/`)?

### 2. Determine the Input Source
Check where the variables come from:
- `$app->Secure->GetGET('param')` → User input (HIGH RISK)
- `$app->Secure->GetPOST('param')` → User input (HIGH RISK)
- `$app->DB->Select(...)` result → Database sourced (MEDIUM RISK)
- Hardcoded/computed value → Internal (LOW RISK)

### 3. Convert to Prepared Statement

**Before (unsafe):**
```php
$id = $this->app->Secure->GetGET('id');
$result = $this->app->DB->Select(
    sprintf("SELECT * FROM artikel WHERE id = %d AND aktiv = '%s'", $id, $status)
);
```

**After (safe):**
```php
$id = $this->app->Secure->GetGET('id');
$result = $this->app->DB->preparedSelect(
    "SELECT * FROM artikel WHERE id = :id AND aktiv = :status",
    ['id' => $id, 'status' => $status]
);
```

### 4. Handle Different Query Types

**SELECT:**
```php
$this->app->DB->preparedSelect($sql, $params);      // Single value
$this->app->DB->preparedSelectArr($sql, $params);    // Array result
$this->app->DB->preparedSelectRow($sql, $params);    // Single row
```

**INSERT:**
```php
$this->app->DB->preparedInsert(
    "INSERT INTO artikel (nummer, bezeichnung) VALUES (:nr, :bez)",
    ['nr' => $nummer, 'bez' => $bezeichnung]
);
```

**UPDATE:**
```php
$this->app->DB->preparedUpdate(
    "UPDATE artikel SET bezeichnung = :bez WHERE id = :id",
    ['bez' => $bezeichnung, 'id' => $id]
);
```

**DELETE:**
```php
$this->app->DB->preparedDelete(
    "DELETE FROM eigenschaften WHERE id = :id AND artikel = :artikel_id",
    ['id' => $id, 'artikel_id' => $artikelId]
);
```

### 5. Verify

- Run `php -l <file>` to check syntax
- Test the affected page/widget manually
- Run `./vendor/bin/phpunit` if tests exist

## Priority Order

1. **First:** Queries using `GetGET()`/`GetPOST()` variables (direct user input)
2. **Then:** Queries in API endpoints (`www/objectapi/`)
3. **Then:** Queries in Pages and Widgets
4. **Last:** Internal queries with computed values

## Common Pitfalls

- ⚠️ Don't use named parameters (`:name`) in LIKE clauses without wrapping the `%` in the parameter value, not in the SQL
- ⚠️ Don't forget to update both the query AND the variable assignments
- ⚠️ IN clauses (`WHERE id IN (...)`) require special handling — build the placeholder list dynamically
