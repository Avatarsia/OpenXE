<?php
// SPDX-License-Identifier: AGPL-3.0-only

use Xentral\Components\Http\Session\Session;

$root = dirname(__DIR__, 3);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'Xentral\\Components\\')) {
        $path = $root . '/classes/' . str_replace('\\', '/', substr($class, 8)) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
});

final class AssignmentFixtureDatabase
{
    public array $config = [];
    public array $shop = ['id' => '7', 'bezeichnung' => 'WooCommerce Testshop', 'projekt' => '3',
        'einstellungen_json' => '{"felder":{"visibilitySync":true,"shopCategoryTree":true}}'];
    public array $article = ['id' => '9', 'nummer' => 'ERP-ARTICLE', 'name_de' => 'Heizblock V1', 'projekt' => '4',
        'ausverkauft' => 0, 'variante' => 0, 'variante_von' => 0, 'shop' => 0, 'shop2' => 0, 'shop3' => 0];
    public array $catalog = [];
    public array $batchArticleIds = [];
    public bool $rejectAssignmentWritesAfterTreeLock = false;
    public bool $failAssignmentWrites = false;
    public ?Closure $concurrentConfigWrite = null;
    public array $advisoryLocks = [];
    private bool $treeLocked = false;
    public ?array $assignment = null;
    private ?array $snapshot = null;

    public function inTransaction(): bool { return $this->snapshot !== null; }
    public function beginTransaction(): void { $this->snapshot = [$this->config, $this->article, $this->assignment]; }
    public function commit(): void { $this->snapshot = null; $this->treeLocked = false; }
    public function rollBack(): void
    {
        [$this->config, $this->article, $this->assignment] = $this->snapshot;
        $this->commit();
    }

    public function fetchRow(string $sql, array $params): array|false
    {
        if (str_contains($sql, 'FROM artikel_onlineshops')) {
            return $this->assignment === null ? false : $this->assignment + ['id' => 10];
        }
        if (str_contains($sql, 'FROM artikel ')) {
            if (str_contains($sql, 'SELECT CASE WHEN variante')) {
                if ((int) $this->article['id'] !== $params['article'] && $params['article'] !== 10) {
                    throw new LogicException('Unexpected product/variant identity.');
                }
                if (!$this->inTransaction() || !str_ends_with($sql, 'FOR UPDATE')) {
                    throw new LogicException('Product owner must be locked.');
                }
            }
            return $this->article;
        }
        throw new LogicException($sql);
    }

    public function fetchValue(string $sql, array $params): int|string|false
    {
        if (str_contains($sql, 'GET_LOCK')) {
            if (isset($this->advisoryLocks[$params['name']])) {
                return 0;
            }
            $this->advisoryLocks[$params['name']] = true;
            return 1;
        }
        if (str_contains($sql, 'RELEASE_LOCK')) {
            unset($this->advisoryLocks[$params['name']]);
            return 1;
        }
        if (str_contains($sql, 'FROM konfiguration')) {
            if (str_contains($sql, 'FOR UPDATE') && str_starts_with($params['name'], 'woocommerce_category_tree_')) {
                $this->treeLocked = true;
            }
            return $this->config[$params['name']] ?? false;
        }
        if (str_starts_with($sql, 'SELECT COUNT(*) FROM artikel AS a')) {
            return count($this->filteredCatalog($sql, $params));
        }
        if (str_contains($sql, 'FROM artikel_onlineshops')) {
            return !empty($this->assignment['aktiv']) ? 10 : false;
        }
        if (str_starts_with($sql, 'SELECT ausverkauft FROM artikel')) {
            return (int) $this->article['id'] === $params['article'] ? $this->article['ausverkauft'] : 0;
        }
        if (str_contains($sql, 'FROM artikelnummer_fremdnummern')) {
            return 'WC-ARTICLE';
        }
        throw new LogicException($sql);
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        if (str_contains($sql, 'FROM shopexport')) {
            return [$this->shop];
        }
        if ($sql === 'SELECT id FROM projekt') {
            return [['id' => 3], ['id' => 4], ['id' => 5]];
        }
        if (str_contains($sql, 'FROM artikel ')) {
            if (preg_match('/LIMIT (\d+) OFFSET (\d+)$/', $sql, $page)) {
                return array_slice($this->filteredCatalog($sql, $params), (int) $page[2], (int) $page[1]);
            }
            return $this->catalog ?: [array_merge($this->article, ['in_shop' => $this->assignment['aktiv'] ?? 0])];
        }
        if (str_contains($sql, 'FROM konfiguration WHERE name IN (')) {
            $this->batchArticleIds = array_map(static fn (string $name): int => (int) substr($name, strrpos($name, '_') + 1), array_values($params));
            $rows = [];
            foreach ($params as $name) {
                if (isset($this->config[$name])) {
                    $rows[] = ['name' => $name, 'wert' => $this->config[$name]];
                }
            }
            return $rows;
        }
        if (str_contains($sql, 'FROM konfiguration')) {
            $prefix = str_replace('!_', '_', substr($params['prefix'], 0, -1));
            $rows = [];
            foreach ($this->config as $name => $value) {
                if (str_starts_with($name, $prefix)) {
                    $rows[] = ['name' => $name, 'wert' => $value];
                }
            }
            return $rows;
        }
        throw new LogicException($sql);
    }

    private function filteredCatalog(string $sql, array $params): array
    {
        $legacyOnly = !$this->assignment && str_contains($sql, 'assignment_owner.shop2')
            && in_array(7, [$this->article['shop'], $this->article['shop2'], $this->article['shop3']], true);
        $catalog = $this->catalog ?: [array_merge($this->article,
            ['in_shop' => ($this->assignment['aktiv'] ?? 0) || $legacyOnly, 'legacy_only' => $legacyOnly])];
        preg_match('/a\.projekt IN \(([0-9,]+)\)/', $sql, $projects);
        return array_values(array_filter($catalog, function (array $article) use ($sql, $params, $projects): bool {
            if ($article['projekt'] > 0 && !in_array((string) $article['projekt'], explode(',', $projects[1] ?? ''), true)) {
                return false;
            }
            if (str_contains($sql, 'AND NOT (EXISTS (SELECT 1 FROM artikel_onlineshops') && $article['in_shop']) {
                return false;
            }
            $key = $params['article_prefix'] . $article['id'];
            $categories = json_decode($this->config[$key] ?? '{}', true)['categories'] ?? [];
            return isset($params['category_id'])
                ? in_array((int) $params['category_id'], $categories, true) : $categories === [];
        }));
    }

    public function perform(string $sql, array $params): void
    {
        if (str_starts_with($sql, 'DELETE FROM konfiguration')) {
            if (($this->config[$params['name']] ?? null) === $params['revision']) {
                unset($this->config[$params['name']]);
            }
            return;
        }
        if (!$this->inTransaction() && !str_starts_with($params['name'] ?? '', 'woocommerce_category_sync_budget_')) {
            throw new LogicException('Writes require a transaction.');
        }
        if (str_starts_with($sql, 'INSERT INTO konfiguration') || str_starts_with($sql, 'UPDATE konfiguration')) {
            if (!str_contains($sql, 'UPDATE name = VALUES(name)') || !isset($this->config[$params['name']])) {
                $this->config[$params['name']] = $params['wert'];
            }
        } elseif (str_starts_with($sql, 'INSERT INTO artikel_onlineshops')) {
            if (!preg_match("/pseudolager, lieferzeitmanuell, last_article_hash\) VALUES \(:article, :shop, :enabled, '', '', ''\)/", $sql)) {
                throw new LogicException('Missing or uninitialized NOT NULL columns');
            }
            $this->assignment = ['aktiv' => $params['enabled'], 'pseudolager' => '', 'lieferzeitmanuell' => '', 'last_article_hash' => ''];
        } elseif (str_starts_with($sql, 'UPDATE artikel_onlineshops')) {
            if ($this->concurrentConfigWrite !== null) {
                ($this->concurrentConfigWrite)();
                // A committed write on a second connection survives our transaction rollback.
                $this->snapshot[0] = $this->config;
            }
            if ($this->failAssignmentWrites) {
                throw new RuntimeException('Assignment writes unavailable.');
            }
            if ($this->rejectAssignmentWritesAfterTreeLock && $this->treeLocked) {
                throw new LogicException('Assignment write after acquiring the tree lock.');
            }
            if ($this->assignment !== null) {
                if (isset($params['enabled'])) {
                    $this->assignment['aktiv'] = $params['enabled'];
                }
                if (str_contains($sql, "last_article_hash = ''")) {
                    $this->assignment['last_article_hash'] = '';
                }
            }
        } elseif (str_starts_with($sql, 'UPDATE artikel SET shop =')) {
            foreach (['shop', 'shop2', 'shop3'] as $slot) {
                if ($this->article[$slot] === $params[$slot]) {
                    $this->article[$slot] = 0;
                }
            }
        } elseif (str_starts_with($sql, 'UPDATE artikel SET ausverkauft')) {
            $this->article['ausverkauft'] = $params['enabled'];
        } else {
            throw new LogicException($sql);
        }
    }
}

function assignmentFixtureApp(AssignmentFixtureDatabase $db, array $get = []): object
{
    return (object) [
        'Container' => new class ($db) {
            public function __construct(private $db) {}
            public function get(string $key): object
            {
                return match ($key) {
                    'Database' => $this->db,
                    'Session' => new class extends Session {
                        public function isCsrfTokenValid($tokenKey, $tokenValue, $remove = false)
                        {
                            return $tokenKey === 'woocommerce_admin' && $tokenValue === 'assignment-token';
                        }
                        public function createCsrfToken($tokenKey) { return 'assignment-token'; }
                    },
                    'Logger' => new class {
                        public function error(string $message): void { error_log($message); }
                    },
                };
            }
        },
        'erp' => new class {
            public array $projects = [3, 4];
            public array $rights = ['artikel', 'onlineshops'];
            public function RechteVorhanden(string $module, string $action): bool
            {
                return $action === 'edit' && in_array($module, $this->rights, true);
            }
            public function UserProjektRecht(int $project): bool { return in_array($project, $this->projects, true); }
        },
        'Secure' => new class ($get) {
            public function __construct(private array $get) {}
            public function GetGET(string $key): string { return (string) ($this->get[$key] ?? ''); }
        },
    ];
}
