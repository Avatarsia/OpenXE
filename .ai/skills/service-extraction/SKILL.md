---
name: service-extraction
description: Extract methods from class.erpapi.php into dedicated domain Service classes
---

# Skill: erpAPI Service Extraction

## When to Use
Use this skill when you need to:
- Move business logic out of `class.erpapi.php` into a dedicated Service
- Create a new domain Service class
- Add a facade method in erpAPI that delegates to the new Service

## Step-by-Step Process

### 1. Identify the Method(s) to Extract

In `www/lib/class.erpapi.php`, find the method(s) to extract. Look for:
- Methods with clear domain affiliation (e.g., `ArtikelStueckliste()` → ArticleService)
- Methods with `@refactor` or `@deprecated` annotations
- Methods called from only one or two modules

### 2. Create the Service Class

**Target directory:** `classes/Services/`

```php
<?php

declare(strict_types=1);

namespace Xentral\Services;

use Xentral\Core\Database\DatabaseInterface;

final class ArticleService
{
    public function __construct(
        private readonly DatabaseInterface $db,
    ) {}

    /**
     * [Beschreibung der Methode]
     *
     * Extracted from: erpAPI::{OriginalMethodName}()
     */
    public function calculateBom(int $articleId): array
    {
        // Migrated logic from erpAPI, using prepared statements
        return $this->db->preparedSelectArr(
            "SELECT * FROM stueckliste WHERE artikel = :id",
            ['id' => $articleId]
        );
    }
}
```

### 3. Keep the Facade in erpAPI

**Never break existing callers.** Add a delegation wrapper:

```php
// In class.erpapi.php — marks the old method as delegating
/** @deprecated Use ArticleService::calculateBom() directly */
public function ArtikelStueckliste($artikelId)
{
    // Delegate to new Service
    return $this->app->Container->get(ArticleService::class)
        ->calculateBom((int) $artikelId);
}
```

### 4. Update Direct Callers (Gradually)

In files that call `$app->erp->ArtikelStueckliste(...)`, update to:
```php
$articleService = $this->app->Container->get(ArticleService::class);
$articleService->calculateBom($artikelId);
```

### 5. Verify
- `php -l classes/Services/{ServiceName}.php`
- `php -l www/lib/class.erpapi.php`
- Test the affected module in the browser
- Run `./vendor/bin/phpunit`

## Domain Mapping

| erpAPI Method Pattern | Target Service |
|----------------------|----------------|
| `Artikel*`, `Stueckliste*` | `ArticleService` |
| `Auftrag*`, `Rechnung*`, `Lieferschein*`, `Gutschrift*` | `DocumentService` |
| `Lager*`, `Inventur*` | `WarehouseService` |
| `Adresse*`, `Kunde*`, `Lieferant*` | `ContactService` |
| `Shop*`, `Shopexport*` | `ShopIntegrationService` |
| `Steuer*`, `Ust*`, `Kontorahmen*` | `TaxService` |
| `Navigation*`, `Hook*` | `NavigationService` |
| `Pdf*`, `Brief*`, `Drucker*` | `PdfService` |
| `Cronjob*`, `Prozess*` | `JobService` |

## Rules

- ✅ One Service per bounded domain context
- ✅ All new queries use prepared statements
- ✅ Typed parameters and return types
- ✅ Constructor injection for dependencies
- ❌ Never add new methods to erpAPI
- ❌ Never remove old erpAPI methods (deprecate + delegate)
