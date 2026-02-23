# Test-Strategie — OpenXE

> Richtlinien für automatisierte Tests im OpenXE-Projekt.

## Test-Pyramide

```
         ╱ E2E Tests ╲          ← Wenige, kritische Workflows
        ╱  Integration  ╲       ← Service + Repository zusammen
       ╱   Unit Tests     ╲     ← Viel, einzelne Klassen
      ╱─────────────────────╲
```

## Unit Tests

**Für:** Services, Entities, Value Objects, Utilities

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Xentral\Services\TaxService;

final class TaxServiceTest extends TestCase
{
    public function testCalculateVat(): void
    {
        $service = new TaxService();
        $result = $service->calculateVat(100.00, 19.0);

        $this->assertSame(19.00, $result);
    }

    public function testZeroRateReturnsZero(): void
    {
        $service = new TaxService();
        $this->assertSame(0.00, $service->calculateVat(100.00, 0.0));
    }
}
```

**Verzeichnis-Struktur:**
```
tests/
├── Unit/
│   ├── Services/
│   │   └── TaxServiceTest.php
│   ├── Entity/
│   │   └── ArticleTest.php
│   └── Repository/
│       └── ArticleRepositoryTest.php
├── Integration/
│   └── ...
└── phpunit.xml
```

## Namenskonventionen

- Test-Klassen: `{Klassenname}Test.php`
- Test-Methoden: `test{Beschreibung}()` — beschreibe das erwartete Verhalten
- Data-Provider: `provide{Beschreibung}(): iterable`

## Was testen?

### MUSS getestet werden
- Neue Service-Klassen (alle öffentlichen Methoden)
- Repository-Methoden (mit Test-Datenbank oder Mocks)
- Entity-Validierung und Factory-Methoden
- Sicherheitskritischer Code

### SOLL getestet werden
- Edge-Cases (leere Arrays, null, Grenzwerte)
- Fehler-Fälle (Exceptions)
- Berechtigungsprüfungen

### Testausführung

```bash
# Alle Tests
./vendor/bin/phpunit

# Einzelne Test-Suite
./vendor/bin/phpunit --testsuite unit

# Einzelne Datei
./vendor/bin/phpunit tests/Unit/Services/TaxServiceTest.php

# Mit Coverage
./vendor/bin/phpunit --coverage-html coverage/
```
