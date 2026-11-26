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
        'ausverkauft' => 0];
    public ?array $assignment = null;
    private ?array $snapshot = null;

    public function inTransaction(): bool { return $this->snapshot !== null; }
    public function beginTransaction(): void { $this->snapshot = [$this->config, $this->article, $this->assignment]; }
    public function commit(): void { $this->snapshot = null; }
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
            return $this->article;
        }
        throw new LogicException($sql);
    }

    public function fetchValue(string $sql, array $params): string|false
    {
        if (str_contains($sql, 'FROM konfiguration')) {
            return $this->config[$params['name']] ?? false;
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
        if (str_contains($sql, 'FROM artikel ')) {
            return [array_merge($this->article, ['in_shop' => $this->assignment['aktiv'] ?? 0])];
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

    public function perform(string $sql, array $params): void
    {
        if (!$this->inTransaction()) {
            throw new LogicException('Writes require a transaction.');
        }
        if (str_starts_with($sql, 'INSERT INTO konfiguration') || str_starts_with($sql, 'UPDATE konfiguration')) {
            if (!str_contains($sql, 'UPDATE name = VALUES(name)') || !isset($this->config[$params['name']])) {
                $this->config[$params['name']] = $params['wert'];
            }
        } elseif (str_starts_with($sql, 'INSERT INTO artikel_onlineshops')) {
            if (!preg_match("/pseudolager, lieferzeitmanuell, last_article_hash\) VALUES \(:article, :shop, 1, '', '', ''\)/", $sql)) {
                throw new LogicException('Missing or uninitialized NOT NULL columns');
            }
            $this->assignment = ['aktiv' => 1, 'pseudolager' => '', 'lieferzeitmanuell' => '', 'last_article_hash' => ''];
        } elseif (str_starts_with($sql, 'UPDATE artikel_onlineshops')) {
            if ($this->assignment !== null) {
                if (isset($params['enabled'])) {
                    $this->assignment['aktiv'] = $params['enabled'];
                }
                if (str_contains($sql, "last_article_hash = ''")) {
                    $this->assignment['last_article_hash'] = '';
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
