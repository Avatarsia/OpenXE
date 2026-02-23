# Dependency Injection & Container — OpenXE

> Dokumentation des DI-/Container-Systems für Service-Registrierung und -Nutzung.

## Aktueller Stand

OpenXE verwendet einen einfachen Service-Container, der über `$app->Container` erreichbar ist.

### Container-Zugriff

```php
// In Page-Modulen (www/pages/*.php):
$service = $this->app->Container->get(ArticleService::class);

// In Widgets (www/widgets/*.php):
$service = $this->app->Container->get(ArticleService::class);

// In Services (classes/Services/*.php):
// → Constructor Injection bevorzugt, NICHT Container direkt nutzen
```

## Service registrieren

### Methode 1: Container-Provider (empfohlen für neue Services)

In `classes/` eine Provider-Klasse erstellen:

```php
<?php

declare(strict_types=1);

namespace Xentral\Providers;

use Xentral\Core\DependencyInjection\ServiceContainer;
use Xentral\Services\ArticleService;
use Xentral\Repository\ArticleRepository;

final class ArticleServiceProvider
{
    public static function register(ServiceContainer $container): void
    {
        $container->singleton(ArticleRepository::class, function ($c) {
            return new ArticleRepository($c->get('Database'));
        });

        $container->singleton(ArticleService::class, function ($c) {
            return new ArticleService(
                $c->get(ArticleRepository::class),
            );
        });
    }
}
```

### Methode 2: Direkte Registrierung (Legacy-Kompatibilität)

```php
// In Bootstrap/Init-Code:
$app->Container->singleton(MyService::class, function ($container) {
    return new MyService(
        $container->get('Database'),
        $container->get('ErpApi'),
    );
});
```

## Vorhandene Container-Services

| Service-Key | Klasse/Typ | Beschreibung |
|-------------|-----------|--------------|
| `'Database'` | `DatabaseInterface` | MySQL-Verbindung (`$app->DB`) |
| `'ErpApi'` | `erpAPI` | erpAPI-Instanz (`$app->erp`) — Legacy |
| `'Template'` | Template Engine | Template-System (`$app->Tpl`) |
| `'YUI'` | YUI | UI-Generierung (`$app->YUI`) — Legacy |
| `'Secure'` | Secure | Input-Sanitierung (`$app->Secure`) |

## Best Practices

### ✅ DO: Constructor Injection

```php
final class OrderService
{
    public function __construct(
        private readonly ArticleRepository $articleRepo,
        private readonly TaxService $taxService,
    ) {}

    public function calculateTotal(int $orderId): float
    {
        // Services über Properties verfügbar
        $article = $this->articleRepo->findById($orderId);
        return $this->taxService->addVat($article->preis);
    }
}
```

### ❌ DON'T: Service Locator Anti-Pattern

```php
// FALSCH — Container im Service verwenden:
final class OrderService
{
    public function calculateTotal(int $orderId): float
    {
        // Versteckte Abhängigkeit, nicht testbar!
        $repo = $this->container->get(ArticleRepository::class);
    }
}
```

### ✅ DO: Interface-basierte Abstraktion (Zukunft)

```php
// Ermöglicht einfaches Testen mit Mocks
interface ArticleRepositoryInterface
{
    public function findById(int $id): ?Article;
}

final class ArticleRepository implements ArticleRepositoryInterface { ... }

// Im Container:
$container->singleton(ArticleRepositoryInterface::class, function ($c) {
    return $c->get(ArticleRepository::class);
});
```

## Migration von Legacy-Code

Alter Code nutzt `$app->erp->...` direkt. Bei der Migration:

1. **Service registrieren** im Container
2. **In Page-Modulen**: `$this->app->Container->get(ServiceClass::class)` verwenden
3. **In neuen Services**: Constructor Injection nutzen
4. **erpAPI Facade**: Delegiert intern an den neuen Service (siehe [Service-Extraction Skill](../skills/service-extraction/SKILL.md))
