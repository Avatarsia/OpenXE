---
name: repository-pattern
description: Replace ObjectAPI generated CRUD classes with typed Repository classes using prepared statements
---

# Skill: ObjectAPI → Repository Pattern

## When to Use
Use this skill when:
- You need to replace a `www/objectapi/mysql/_gen/object.gen.{entity}.php` file
- You're creating data access for a new entity
- You need type-safe database operations

## Step-by-Step Process

### 1. Analyze the Existing ObjectAPI Class

Open `www/objectapi/mysql/_gen/object.gen.{entity}.php` and note:
- All private properties (= database columns)
- The table name
- Any custom SQL in `Select()`, `Create()`, `Update()`, `Delete()`

### 2. Create an Entity Class (DTO)

**File:** `classes/Entity/{EntityName}.php`

```php
<?php

declare(strict_types=1);

namespace Xentral\Entity;

final readonly class Article
{
    public function __construct(
        public ?int $id,
        public string $typ,
        public string $nummer,
        public string $bezeichnung,
        public bool $aktiv = true,
        // ... mapped from ObjectAPI properties
    ) {}

    /**
     * Create from database row array
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            typ: $row['typ'] ?? '',
            nummer: $row['nummer'] ?? '',
            bezeichnung: $row['bezeichnung'] ?? '',
            aktiv: (bool) ($row['aktiv'] ?? true),
        );
    }
}
```

### 3. Create the Repository Class

**File:** `classes/Repository/{EntityName}Repository.php`

```php
<?php

declare(strict_types=1);

namespace Xentral\Repository;

use Xentral\Core\Database\DatabaseInterface;
use Xentral\Entity\Article;

final class ArticleRepository
{
    private const TABLE = 'artikel';

    public function __construct(
        private readonly DatabaseInterface $db,
    ) {}

    public function findById(int $id): ?Article
    {
        $row = $this->db->preparedSelectRow(
            "SELECT * FROM `" . self::TABLE . "` WHERE id = :id",
            ['id' => $id]
        );
        return $row ? Article::fromRow($row) : null;
    }

    public function findAll(int $limit = 100, int $offset = 0): array
    {
        $rows = $this->db->preparedSelectArr(
            "SELECT * FROM `" . self::TABLE . "` LIMIT :limit OFFSET :offset",
            ['limit' => $limit, 'offset' => $offset]
        );
        return array_map(Article::fromRow(...), $rows);
    }

    public function save(Article $article): int
    {
        if ($article->id !== null) {
            return $this->update($article);
        }
        return $this->insert($article);
    }

    public function delete(int $id): bool
    {
        return $this->db->preparedDelete(
            "DELETE FROM `" . self::TABLE . "` WHERE id = :id",
            ['id' => $id]
        ) > 0;
    }

    private function insert(Article $article): int
    {
        $this->db->preparedInsert(
            "INSERT INTO `" . self::TABLE . "` (typ, nummer, bezeichnung, aktiv)
             VALUES (:typ, :nr, :bez, :aktiv)",
            [
                'typ' => $article->typ,
                'nr' => $article->nummer,
                'bez' => $article->bezeichnung,
                'aktiv' => (int) $article->aktiv,
            ]
        );
        return $this->db->getInsertId();
    }

    private function update(Article $article): int
    {
        return $this->db->preparedUpdate(
            "UPDATE `" . self::TABLE . "` SET
                typ = :typ, nummer = :nr, bezeichnung = :bez, aktiv = :aktiv
             WHERE id = :id",
            [
                'typ' => $article->typ,
                'nr' => $article->nummer,
                'bez' => $article->bezeichnung,
                'aktiv' => (int) $article->aktiv,
                'id' => $article->id,
            ]
        );
    }
}
```

### 4. Migration Strategy

1. Create Entity + Repository alongside existing ObjectAPI class
2. Update callers to use Repository (one at a time)
3. Once all callers migrated, mark ObjectAPI class as `@deprecated`
4. Eventually remove ObjectAPI class

## Rules

- ✅ One Repository per database table/entity
- ✅ Readonly Entity classes (immutable data)
- ✅ Named constructors (`fromRow()`) for hydration
- ✅ All queries parametrized
- ❌ No business logic in Repositories (that goes in Services)
- ❌ No HTML/JS generation in Repositories
