# PHP Coding Standards — OpenXE

> Verbindliche Standards für allen neuen PHP-Code im OpenXE-Projekt.

## PHP Version

**Minimum: PHP 8.5** — Alle neuen Features nutzen.

## Namespacing

```php
namespace Xentral\{Layer}\{Domain};
// Beispiele:
namespace Xentral\Services;        // Domain-Services
namespace Xentral\Repository;      // Daten-Zugriff
namespace Xentral\Entity;          // Daten-Objekte
namespace Xentral\Components\UI;   // UI-Komponenten
```

## Klassen-Richtlinien

```php
<?php

declare(strict_types=1);

namespace Xentral\Services;

// Imports: sortiert, keine unused imports
use Xentral\Entity\Article;
use Xentral\Repository\ArticleRepository;

/**
 * Beschreibung der Klasse.
 */
final class ArticleService
{
    // Constructor Injection, readonly properties
    public function __construct(
        private readonly ArticleRepository $repository,
    ) {}

    /**
     * Beschreibung der Methode.
     *
     * @throws ArticleNotFoundException Wenn Artikel nicht existiert
     */
    public function findActiveArticle(int $id): Article
    {
        return $this->repository->findById($id)
            ?? throw new ArticleNotFoundException($id);
    }
}
```

## Regeln

### MUSS (Fehler bei Verstoß)
- `declare(strict_types=1)` in jeder Datei
- Typed Parameters und Return Types für alle Methoden
- Readonly Properties wo immer möglich
- `final` für Klassen die nicht zur Vererbung gedacht sind
- Prepared Statements für ALLE SQL-Queries
- Namespace unter `Xentral\`

### SOLL (Best Practice)
- `match()` statt `switch/case` für Wert-Zuordnungen
- Named Arguments bei Funktionen mit >3 Parametern
- Enums statt Magic Strings/Numbers
- First-Class Callable Syntax (`$fn(...)` statt `Closure::fromCallable`)
- `readonly class` für reine Datenobjekte (DTOs/Entities)
- Methoden max. 50 Zeilen, Klassen max. 300 Zeilen

### DARF NICHT (Verboten in neuem Code)
- `sprintf()` in SQL-Queries
- String-Interpolation in SQL
- `global $variable`
- `extract()`
- `eval()`
- Neue Methoden in `class.erpapi.php` oder `class.yui.php`
- HTML in SQL (CONCAT + HTML-Tags)
- Inline-JavaScript-Generierung in PHP

## Namenskonventionen

| Element | Konvention | Beispiel |
|---------|-----------|---------|
| Klassen | PascalCase | `ArticleService` |
| Methoden | camelCase | `findActiveArticle()` |
| Properties | camelCase | `$articleNumber` |
| Konstanten | UPPER_SNAKE | `MAX_RETRY_COUNT` |
| Dateien | PascalCase (= Klassenname) | `ArticleService.php` |
| DB-Tabellen | snake_case | `artikel_eigenschaften` |
| DB-Spalten | snake_case | `created_at` |

## Error Handling

```php
// Eigene Exceptions statt generischer
throw new ArticleNotFoundException(
    sprintf('Artikel mit ID %d nicht gefunden', $id)
);

// Niemals Exceptions schlucken
try {
    $this->processOrder($order);
} catch (OrderException $e) {
    $this->logger->error('Order failed', ['exception' => $e]);
    throw $e; // Re-throw oder spezifische Behandlung
}
```
