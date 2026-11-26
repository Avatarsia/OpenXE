<?php
// SPDX-License-Identifier: AGPL-3.0-only

namespace Xentral\Components\WooCommerce;

/** Variants follow the assignment of the parent that exports them. */
final class ShopAssignment
{
    private const LEGACY_COLUMNS = ['shop', 'shop2', 'shop3'];

    public static function ownerIdSql(string $alias = ''): string
    {
        $prefix = $alias === '' ? '' : $alias . '.';
        return "CASE WHEN {$prefix}variante = 1 OR {$prefix}variante_von > 0"
            . " THEN {$prefix}variante_von ELSE {$prefix}id END";
    }

    public static function ownerJoinSql(string $article = 'a'): string
    {
        return ' LEFT JOIN artikel AS assignment_owner ON assignment_owner.id = (' . self::ownerIdSql($article) . ')';
    }

    public static function legacySql(string $owner, string $shop): string
    {
        $columns = implode(', ', array_map(static fn (string $column): string => "$owner.$column", self::LEGACY_COLUMNS));
        return "COALESCE($shop IN ($columns), 0)";
    }

    public static function activeSql(string $owner, string $shop): string
    {
        return 'EXISTS (SELECT 1 FROM artikel_onlineshops AS ao'
            . " WHERE ao.artikel = $owner.id AND ao.shop = $shop AND ao.aktiv = 1)";
    }

    public static function assignedSql(string $owner, string $shop): string
    {
        return '(' . self::activeSql($owner, $shop) . ' OR ' . self::legacySql($owner, $shop) . ')';
    }

    public static function hasLegacyReference(array $owner, int $shopId): bool
    {
        foreach (self::LEGACY_COLUMNS as $column) {
            if ((int) ($owner[$column] ?? 0) === $shopId) {
                return true;
            }
        }
        return false;
    }

    /** Call within the transaction that needs a stable assignment decision. */
    public static function isAssigned($database, int $articleId, int $shopId): bool
    {
        $owner = $database->fetchRow(
            'SELECT id, shop, shop2, shop3 FROM artikel WHERE id = (SELECT ' . self::ownerIdSql()
            . ' FROM artikel WHERE id = :article AND geloescht = 0) FOR UPDATE',
            ['article' => $articleId]
        );
        if (!$owner) {
            return false;
        }
        // Acquire article then assignment locks before touching the visibility queue.
        return self::hasLegacyReference($owner, $shopId) || (bool) $database->fetchValue(
            'SELECT id FROM artikel_onlineshops WHERE artikel = :article AND shop = :shop AND aktiv = 1 LIMIT 1 FOR UPDATE',
            ['article' => (int) $owner['id'], 'shop' => $shopId]
        );
    }
}
