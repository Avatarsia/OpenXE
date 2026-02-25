<?php

declare(strict_types=1);

namespace Xentral\Services;

use RuntimeException;

/**
 * Secure prepared-statement database service.
 *
 * Wraps the existing legacy DB instance and reuses its mysqli connection.
 * Recommended for all new code instead of $app->DB->Select(sprintf(...)).
 *
 * Usage:
 *   $db = $app->DatabaseService;
 *   $rows = $db->select('SELECT * FROM artikel WHERE aktiv = ?', [1]);
 *   $row  = $db->selectRow('SELECT * FROM artikel WHERE id = ?', [$id]);
 */
final class DatabaseService
{
    private readonly \mysqli $mysqli;

    public function __construct(\DB $db)
    {
        if (empty($db->connection) || !($db->connection instanceof \mysqli)) {
            throw new RuntimeException('DatabaseService: no valid mysqli connection available on DB instance.');
        }
        $this->mysqli = $db->connection;
    }

    // -------------------------------------------------------------------------
    // Query methods
    // -------------------------------------------------------------------------

    /**
     * Returns all matching rows as an array of associative arrays.
     *
     * @param array<int|string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function select(string $sql, array $params = []): array
    {
        $result = $this->executeStatement($sql, $params);
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
        return $rows;
    }

    /**
     * Returns the first matching row, or null if no rows found.
     *
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function selectRow(string $sql, array $params = []): ?array
    {
        $result = $this->executeStatement($sql, $params);
        $row = $result->fetch_assoc();
        $result->free();
        return $row !== false ? $row : null;
    }

    /**
     * Returns the first column of the first row as a scalar, or null.
     *
     * @param array<int|string, mixed> $params
     */
    public function selectValue(string $sql, array $params = []): mixed
    {
        $result = $this->executeStatement($sql, $params);
        $row = $result->fetch_row();
        $result->free();
        return $row !== null ? $row[0] : null;
    }

    /**
     * Returns the first column of every row as a flat array.
     *
     * @param array<int|string, mixed> $params
     * @return array<int, mixed>
     */
    public function selectColumn(string $sql, array $params = []): array
    {
        $result = $this->executeStatement($sql, $params);
        $values = [];
        while ($row = $result->fetch_row()) {
            $values[] = $row[0];
        }
        $result->free();
        return $values;
    }

    /**
     * Returns a key=>value map from the first two columns of each row.
     *
     * @param array<int|string, mixed> $params
     * @return array<string|int, mixed>
     */
    public function selectPairs(string $sql, array $params = []): array
    {
        $result = $this->executeStatement($sql, $params);
        $pairs = [];
        while ($row = $result->fetch_row()) {
            $pairs[$row[0]] = $row[1] ?? null;
        }
        $result->free();
        return $pairs;
    }

    /**
     * Executes an INSERT and returns the auto-increment ID.
     *
     * @param array<int|string, mixed> $params
     */
    public function insert(string $sql, array $params = []): int
    {
        $stmt = $this->prepareAndBind($sql, $params);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException("DatabaseService INSERT failed: {$error}");
        }
        $insertId = (int) $stmt->insert_id;
        $stmt->close();
        return $insertId;
    }

    /**
     * Executes an UPDATE and returns the number of affected rows.
     *
     * @param array<int|string, mixed> $params
     */
    public function update(string $sql, array $params = []): int
    {
        return $this->executeWrite($sql, $params);
    }

    /**
     * Executes a DELETE and returns the number of affected rows.
     *
     * @param array<int|string, mixed> $params
     */
    public function delete(string $sql, array $params = []): int
    {
        return $this->executeWrite($sql, $params);
    }

    /**
     * Generic execute for DDL or statements where the result is not needed.
     *
     * @param array<int|string, mixed> $params
     */
    public function execute(string $sql, array $params = []): bool
    {
        $stmt = $this->prepareAndBind($sql, $params);
        $ok = $stmt->execute();
        if (!$ok) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException("DatabaseService execute failed: {$error}");
        }
        $stmt->close();
        return true;
    }

    // -------------------------------------------------------------------------
    // Convenience array-based helpers
    // -------------------------------------------------------------------------

    /**
     * Builds and executes an INSERT from an associative array.
     *
     * @param array<string, mixed> $data  column => value pairs
     */
    public function insertArray(string $table, array $data): int
    {
        $this->validateIdentifier($table);
        if (empty($data)) {
            throw new RuntimeException('DatabaseService::insertArray() called with empty data array.');
        }

        $columns = array_keys($data);
        foreach ($columns as $col) {
            $this->validateIdentifier($col);
        }

        $colList = implode(', ', array_map(fn(string $c): string => "`{$c}`", $columns));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql = "INSERT INTO `{$table}` ({$colList}) VALUES ({$placeholders})";

        return $this->insert($sql, array_values($data));
    }

    /**
     * Builds and executes an UPDATE from an associative array.
     *
     * @param array<string, mixed>  $data      column => value pairs to update
     * @param string                $pkColumn  primary key column name
     * @param int|string            $pkValue   primary key value
     */
    public function updateArray(
        string $table,
        array $data,
        string $pkColumn,
        int|string $pkValue
    ): int {
        $this->validateIdentifier($table);
        $this->validateIdentifier($pkColumn);
        if (empty($data)) {
            throw new RuntimeException('DatabaseService::updateArray() called with empty data array.');
        }

        $columns = array_keys($data);
        foreach ($columns as $col) {
            $this->validateIdentifier($col);
        }

        $setClauses = implode(', ', array_map(fn(string $c): string => "`{$c}` = ?", $columns));
        $sql = "UPDATE `{$table}` SET {$setClauses} WHERE `{$pkColumn}` = ?";

        $params = array_values($data);
        $params[] = $pkValue;

        return $this->update($sql, $params);
    }

    // -------------------------------------------------------------------------
    // Transaction support
    // -------------------------------------------------------------------------

    /**
     * Wraps a callable in a BEGIN/COMMIT block.
     * Rolls back and re-throws on any exception.
     */
    public function transactional(callable $callback): mixed
    {
        $this->mysqli->begin_transaction();
        try {
            $result = $callback($this);
            $this->mysqli->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->mysqli->rollback();
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Identifier validation
    // -------------------------------------------------------------------------

    /**
     * Validates a table or column name against a strict whitelist.
     * Only allows letters, digits, underscores, and dots (schema.table notation).
     *
     * @throws RuntimeException if the identifier contains disallowed characters.
     */
    public function validateIdentifier(string $identifier): void
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $identifier)) {
            throw new RuntimeException(
                "DatabaseService: invalid identifier '{$identifier}'. " .
                "Only alphanumeric characters and underscores are allowed."
            );
        }
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Prepares a statement, binds parameters, executes it, and returns the result set.
     *
     * @param array<int|string, mixed> $params
     */
    private function executeStatement(string $sql, array $params): \mysqli_result
    {
        $stmt = $this->prepareAndBind($sql, $params);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException("DatabaseService query failed: {$error}");
        }
        $result = $stmt->get_result();
        $stmt->close();
        if ($result === false) {
            throw new RuntimeException(
                "DatabaseService: get_result() failed — ensure the mysqlnd driver is installed. " .
                "Error: {$this->mysqli->error}"
            );
        }
        return $result;
    }

    /**
     * Executes a write statement (UPDATE/DELETE) and returns affected row count.
     *
     * @param array<int|string, mixed> $params
     */
    private function executeWrite(string $sql, array $params): int
    {
        $stmt = $this->prepareAndBind($sql, $params);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException("DatabaseService write failed: {$error}");
        }
        $affected = $stmt->affected_rows;
        $stmt->close();
        return $affected;
    }

    /**
     * Prepares a statement and binds the provided parameters.
     *
     * Supports:
     *   - Positional placeholders:  WHERE id = ?
     *   - Named placeholders:       WHERE id = :id  (converted to positional internally)
     *
     * Type detection is automatic: null→null, int/bool→integer, float→double, else→string.
     *
     * @param array<int|string, mixed> $params
     */
    private function prepareAndBind(string $sql, array $params): \mysqli_stmt
    {
        [$sql, $params] = $this->normalizeNamedParams($sql, $params);

        $stmt = $this->mysqli->prepare($sql);
        if ($stmt === false) {
            throw new RuntimeException(
                "DatabaseService: failed to prepare statement. " .
                "Error: {$this->mysqli->error}. SQL: {$sql}"
            );
        }

        if (!empty($params)) {
            $types = '';
            $values = [];
            foreach ($params as $param) {
                if ($param === null) {
                    $types .= 's'; // null bound as string with null value
                    $values[] = null;
                } elseif (is_int($param) || is_bool($param)) {
                    $types .= 'i';
                    $values[] = (int) $param;
                } elseif (is_float($param)) {
                    $types .= 'd';
                    $values[] = $param;
                } else {
                    $types .= 's';
                    $values[] = (string) $param;
                }
            }
            $stmt->bind_param($types, ...$values);
        }

        return $stmt;
    }

    /**
     * Converts named placeholders (:name) to positional (?) and reorders params.
     * If no named placeholders are detected, returns sql and params unchanged.
     *
     * @param array<int|string, mixed> $params
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function normalizeNamedParams(string $sql, array $params): array
    {
        if (empty($params) || array_is_list($params)) {
            return [$sql, $params];
        }

        $orderedValues = [];
        $convertedSql = preg_replace_callback(
            '/:([a-zA-Z_][a-zA-Z0-9_]*)/',
            function (array $matches) use ($params, &$orderedValues): string {
                $key = $matches[1];
                if (!array_key_exists($key, $params)) {
                    throw new RuntimeException(
                        "DatabaseService: named parameter ':{$key}' not found in params array."
                    );
                }
                $orderedValues[] = $params[$key];
                return '?';
            },
            $sql
        );

        return [$convertedSql, $orderedValues];
    }
}
