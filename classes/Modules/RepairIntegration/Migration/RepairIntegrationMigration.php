<?php
declare(strict_types=1);

namespace Xentral\Modules\RepairIntegration\Migration;

use Xentral\Components\Database\Database;

final class RepairIntegrationMigration
{
    private const CONFIG_NAMESPACE = 'repair_integration'; // @php83: add type string
    private const SCHEMA_VERSION_KEY = 'schema_version'; // @php83: add type string
    private const SCHEMA_VERSION = '1.1.0'; // @php83: add type string

    /**
     * Explizite Upgrade-Kette: gespeicherte Version => auszufuehrender Schritt.
     *
     * Jeder Eintrag beschreibt genau einen Sprung (`sql` relativ zu sql/,
     * `to` = danach zu schreibende Version). upgrade() haengelt sich so lange
     * von Schritt zu Schritt, bis fuer die aktuelle Version kein Eintrag mehr
     * existiert. Damit fuehrt eine kuenftige Versionserhoehung nicht mehr
     * versehentlich das falsche (immer dasselbe) SQL-File aus.
     *
     * @var array<string, array{sql: string, to: string}>
     */
    private const UPGRADE_STEPS = [
        '1.0.0' => ['sql' => '003_status_config_upgrade.sql', 'to' => '1.1.0'],
    ];

    public function __construct(
        private readonly Database $db,
    ) {}

    public function install(): void
    {
        $this->executeSqlFile(__DIR__ . '/sql/001_create_tables.sql');
        $this->executeSqlFile(__DIR__ . '/sql/002_seed_status_config.sql');
        $this->setVersion(self::SCHEMA_VERSION);
    }

    /**
     * Bringt eine bereits installierte Datenbank auf SCHEMA_VERSION.
     *
     * Wird bei jedem Install-Aufruf ausgefuehrt und ist ein No-Op, sobald
     * die gespeicherte Version aktuell ist. Die Upgrade-Statements selbst
     * sind zusaetzlich idempotent (INSERT IGNORE / eng gefasste UPDATEs).
     *
     * Laeuft die Kette aus UPGRADE_STEPS ab: jeder Schritt schreibt seine
     * Zielversion, bevor der naechste gesucht wird. Bricht ein Schritt ab,
     * bleibt die zuletzt erfolgreich erreichte Version stehen und der Rest
     * wird beim naechsten Aufruf wiederholt. Eine unbekannte oder die
     * finale Version beendet die Schleife.
     */
    public function upgrade(): void
    {
        $current = $this->getCurrentVersion();
        if ($current === null) {
            return;
        }

        while (isset(self::UPGRADE_STEPS[$current])) {
            $step = self::UPGRADE_STEPS[$current];
            $this->executeSqlFile(__DIR__ . '/sql/' . $step['sql']);
            $this->setVersion($step['to']);
            $current = $step['to'];
        }

        if ($current !== self::SCHEMA_VERSION) {
            error_log('RepairIntegration upgrade: no upgrade path from version ' . $current . ' to ' . self::SCHEMA_VERSION);
        }
    }

    public function needsInstall(): bool
    {
        return $this->getCurrentVersion() === null;
    }

    /**
     * True, wenn installiert, aber auf einer aelteren Schema-Version.
     */
    public function needsUpgrade(): bool
    {
        $current = $this->getCurrentVersion();

        return $current !== null && version_compare($current, self::SCHEMA_VERSION, '<');
    }

    public function getTargetVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function getCurrentVersion(): ?string
    {
        try {
            $value = $this->db->fetchValue(
                "SELECT `value` FROM `systemconfig` WHERE `namespace` = :ns AND `key` = :key",
                ['ns' => self::CONFIG_NAMESPACE, 'key' => self::SCHEMA_VERSION_KEY]
            );
            return $value !== false ? (string)$value : null;
        } catch (\Exception) {
            return null;
        }
    }

    private function setVersion(string $version): void
    {
        $this->db->perform(
            "INSERT INTO `systemconfig` (`namespace`, `key`, `value`)
             VALUES (:ns, :key, :val)
             ON DUPLICATE KEY UPDATE `value` = :val2",
            [
                'ns' => self::CONFIG_NAMESPACE,
                'key' => self::SCHEMA_VERSION_KEY,
                'val' => $version,
                'val2' => $version,
            ]
        );
    }

    private function executeSqlFile(string $path): void
    {
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new \RuntimeException("Cannot read SQL file: {$path}");
        }
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            static fn(string $s): bool => $s !== ''
        );
        foreach ($statements as $statement) {
            $this->db->perform($statement);
        }
    }
}
