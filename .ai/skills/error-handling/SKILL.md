---
name: error-handling
description: Implement consistent exception handling and error recovery patterns in OpenXE
---

# Skill: Error Handling Patterns

## When to Use
Use this skill when:
- Creating new Service or Repository classes
- Refactoring methods from `class.erpapi.php`
- Handling user input validation
- Working with external APIs (Shop imports, payment providers)

## Exception Hierarchy

Create domain-specific exceptions under `classes/Exceptions/`:

```php
<?php

declare(strict_types=1);

namespace Xentral\Exceptions;

// Base Exception for all OpenXE domain errors
class OpenXEException extends \RuntimeException {}

// Domain-specific exceptions
class EntityNotFoundException extends OpenXEException
{
    public function __construct(
        public readonly string $entityType,
        public readonly int|string $entityId,
    ) {
        parent::__construct(
            sprintf('%s with ID %s not found', $entityType, $entityId)
        );
    }
}

class ValidationException extends OpenXEException
{
    /** @param array<string, string[]> $errors field => messages */
    public function __construct(
        public readonly array $errors,
    ) {
        $count = array_sum(array_map('count', $errors));
        parent::__construct(sprintf('%d validation error(s)', $count));
    }
}

class InsufficientStockException extends OpenXEException {}
class DuplicateEntryException extends OpenXEException {}
class ShopConnectionException extends OpenXEException {}
class PermissionDeniedException extends OpenXEException {}
```

## Usage in Services

```php
final class ArticleService
{
    public function findOrFail(int $id): Article
    {
        return $this->repository->findById($id)
            ?? throw new EntityNotFoundException('Article', $id);
    }

    public function updatePrice(int $id, float $price): Article
    {
        if ($price < 0) {
            throw new ValidationException([
                'price' => ['Price must be >= 0'],
            ]);
        }

        $article = $this->findOrFail($id);
        // ... update logic
        return $article;
    }
}
```

## Usage in Controllers/Pages

```php
// In www/pages/artikel.php or future Controller:
public function editAction(): void
{
    try {
        $id = (int) $this->app->Secure->GetGET('id');
        $article = $this->articleService->findOrFail($id);
        $this->app->Tpl->Set('ARTIKEL', $article);
    } catch (EntityNotFoundException $e) {
        $this->app->Location->goBack("Artikel nicht gefunden");
    } catch (PermissionDeniedException $e) {
        $this->app->Location->goBack("Keine Berechtigung");
    }
}
```

## Logging Errors

```php
// In Services — log but re-throw:
try {
    $this->shopApi->syncArticle($article);
} catch (ShopConnectionException $e) {
    $this->logger->error('Shop sync failed', [
        'shop_id' => $shopId,
        'article_id' => $article->id,
        'exception' => $e,
    ]);
    throw $e; // Let the caller decide how to handle
}
```

## Rules

- ✅ One exception class per error type
- ✅ Meaningful exception messages with context
- ✅ Catch at the appropriate level (Controller, not Repository)
- ✅ Log with context (IDs, entity types)
- ❌ Never catch `\Exception` broadly (catch specific types)
- ❌ Never silence exceptions (`catch (...) { /* empty */ }`)
- ❌ Never log passwords, tokens, or sensitive data in error context
