<?php
// SPDX-License-Identifier: AGPL-3.0-only

final class CategoryStoreDatabase
{
    public array $config = [];
    private ?array $snapshot = null;
    private ?string $lockedTree = null;

    public function inTransaction(): bool { return $this->snapshot !== null; }
    public function beginTransaction(): void
    {
        check(!$this->inTransaction());
        $this->snapshot = $this->config;
    }
    public function commit(): void
    {
        $this->snapshot = null;
        $this->lockedTree = null;
    }
    public function rollBack(): void
    {
        $this->config = $this->snapshot;
        $this->commit();
    }

    public function fetchValue(string $sql, array $params)
    {
        $name = $params['name'];
        if (str_ends_with($sql, ' FOR UPDATE')) {
            check($this->inTransaction());
            if (str_starts_with($name, 'woocommerce_category_tree_')) {
                check(isset($this->config[$name]), 'Lock must cover the first write.');
                $this->lockedTree = $name;
            }
        }
        return $this->config[$name] ?? false;
    }

    public function fetchAll(string $sql, array $params): array
    {
        check(str_contains($sql, "LIKE :prefix ESCAPE '!'"));
        if (str_ends_with($sql, ' FOR UPDATE')) {
            check($this->lockedTree !== null);
        }
        $prefix = str_replace('!_', '_', substr($params['prefix'], 0, -1));
        $rows = [];
        foreach ($this->config as $name => $value) {
            if (str_starts_with($name, $prefix)) {
                $rows[] = ['name' => $name, 'wert' => $value];
            }
        }
        return $rows;
    }

    public function perform(string $sql, array $params): void
    {
        check($this->inTransaction());
        check(str_starts_with($sql, 'INSERT INTO konfiguration (name, wert, adresse, firma)'));
        $name = $params['name'];
        if (str_contains($sql, 'ON DUPLICATE KEY UPDATE name = VALUES(name)')) {
            $this->config[$name] ??= $params['wert'];
            return;
        }
        check(str_contains($sql, 'ON DUPLICATE KEY UPDATE wert = VALUES(wert)'));
        $prefix = preg_replace('/^woocommerce_article_(\d+)_\d+$/D', 'woocommerce_category_tree_$1', $name);
        same($prefix, $this->lockedTree);
        $this->config[$name] = $params['wert'];
    }
}
