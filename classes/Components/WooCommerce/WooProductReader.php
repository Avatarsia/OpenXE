<?php
// SPDX-License-Identifier: AGPL-3.0-only

namespace Xentral\Components\WooCommerce;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class WooProductReader
{
    public const SKU_ZERO_REASON = 'SKU 0 kann nicht eindeutig gesucht werden - bitte manuell ausblenden';

    private const PAGE_SIZE = 100;
    private const SKU_LOOKUP_FAILED = 'SKU-Pruefung fehlgeschlagen';

    public function __construct(private $client, private DeliveryTimeMap $map)
    {
    }

    public function findBySku(string $sku): ?array
    {
        $sku = trim($sku);
        if ($sku === '') {
            return null;
        }
        $matches = [];
        $parentIds = [];
        $page = 1;
        do {
            [$products, $more] = $this->fetchPage('products', $page++, ['sku' => $sku]);
            foreach ($products as $product) {
                $product = (array) $product;
                if (trim((string) ($product['sku'] ?? '')) !== $sku) {
                    continue;
                }
                $matches[] = $product;
                if (empty($product['parent_id'])) {
                    $parentIds[(int) $product['id']] = true;
                }
            }
        } while ($more);

        $match = null;
        foreach ($matches as $product) {
            $parentId = (int) ($product['parent_id'] ?? 0);
            if (isset($parentIds[$parentId])) {
                continue;
            }
            if ($match !== null) {
                throw new RuntimeException('SKU mehrfach in WooCommerce: ' . $sku);
            }
            $match = $this->mapProduct($product, $parentId);
        }

        return $match;
    }

    /** @return array{status: 'unique', id: int, parent_id: int}|array{status: 'multiple'|'not_found'|'deferred'}|array{status: 'failed', reason: string, manualAction?: bool} */
    public function resolveSku(string $sku, ?callable $mayStartRequest = null): array
    {
        $failed = ['status' => 'failed', 'reason' => self::SKU_LOOKUP_FAILED];
        if ($sku === '') {
            return $failed;
        }
        if ($sku === '0') {
            // WooCommerce skips its SKU filter for PHP-empty values.
            return ['status' => 'failed', 'reason' => self::SKU_ZERO_REASON, 'manualAction' => true];
        }

        $match = null;
        $multiple = false;
        $total = null;
        $page = 1;
        try {
            // The wc/v3 SKU filter includes variations and also searches comma-separated terms.
            do {
                if ($mayStartRequest !== null && !$mayStartRequest()) {
                    return ['status' => 'deferred'];
                }
                $rows = $this->client->get('products', [
                    'sku' => $sku, 'status' => 'any', 'context' => 'edit', 'page' => $page,
                    'per_page' => self::PAGE_SIZE, '_fields' => 'id,parent_id,sku',
                    'orderby' => 'id', 'order' => 'asc',
                ]);
                $response = $this->client->getLastResponse();
                $rowTotal = (string) $response?->getHeader('X-WP-Total');
                $pageTotal = (string) $response?->getHeader('X-WP-TotalPages');
                if (!is_array($rows) || !ctype_digit($rowTotal) || !ctype_digit($pageTotal)) {
                    return $failed;
                }
                $total ??= (int) $rowTotal;
                $remaining = max(0, $total - ($page - 1) * self::PAGE_SIZE);
                if ((int) $rowTotal !== $total || (int) $pageTotal !== (int) ceil($total / self::PAGE_SIZE)
                    || count($rows) !== min(self::PAGE_SIZE, $remaining)) {
                    return $failed;
                }
                foreach ($rows as $row) {
                    $row = (array) $row;
                    if (!is_string($row['sku'] ?? null)) {
                        return $failed;
                    }
                    if ($row['sku'] !== $sku) {
                        continue;
                    }
                    if (!is_int($row['id'] ?? null) || $row['id'] <= 0
                        || !is_int($row['parent_id'] ?? null) || $row['parent_id'] < 0) {
                        return $failed;
                    }
                    if ($match === null) {
                        $match = ['status' => 'unique', 'id' => $row['id'], 'parent_id' => $row['parent_id']];
                    } else {
                        $multiple = true;
                    }
                }
            } while ($page++ < (int) $pageTotal);
        } catch (Throwable) {
            return $failed;
        }
        return $multiple ? ['status' => 'multiple'] : ($match ?? ['status' => 'not_found']);
    }

    /** A bulk pull requires a complete SKU lookup and the same identity as the product page. */
    public function skuIssue(array $identity): ?string
    {
        $match = $this->resolveSku($identity['sku']);
        return match ($match['status']) {
            'multiple' => 'SKU mehrfach',
            'failed' => $match['reason'],
            'unique' => $match['id'] === $identity['id'] && $match['parent_id'] === $identity['parent_id']
                ? null : self::SKU_LOOKUP_FAILED,
            default => self::SKU_LOOKUP_FAILED,
        };
    }

    public function page(int $page): array
    {
        if ($page < 1) {
            throw new InvalidArgumentException('Die WooCommerce-Seitennummer muss positiv sein.');
        }
        [$products, $more] = $this->fetchPage('products', $page);
        $items = $unmatched = $firstSeen = [];
        foreach ($products as $product) {
            $product = (array) $product;
            $this->collect($product, (int) ($product['parent_id'] ?? 0), $items, $unmatched, $firstSeen);
            if (($product['type'] ?? '') !== 'variable') {
                continue;
            }
            $parentId = (int) $product['id'];
            $parentSku = trim((string) ($product['sku'] ?? ''));
            $variationPage = 1;
            do {
                [$variations, $moreVariations] = $this->fetchPage("products/$parentId/variations", $variationPage++);
                foreach ($variations as $variation) {
                    $this->collect((array) $variation, $parentId, $items, $unmatched, $firstSeen, $parentSku);
                }
            } while ($moreVariations);
        }

        return ['items' => $items, 'unmatched' => $unmatched, 'more' => $more];
    }

    private function fetchPage(string $endpoint, int $page, array $filters = []): array
    {
        $rows = $this->client->get($endpoint, $filters + [
            'page' => $page,
            'per_page' => self::PAGE_SIZE,
            'context' => 'edit',
            'orderby' => 'id',
            'order' => 'asc',
        ]);
        if (!is_array($rows)) {
            throw new RuntimeException('Ungueltige WooCommerce-Produktliste: ' . $endpoint);
        }
        $totalPages = is_callable([$this->client, 'getLastResponse'])
            ? $this->client->getLastResponse()?->getHeader('X-WP-TotalPages')
            : null;
        $more = $rows !== [] && ($totalPages !== null && ctype_digit((string) $totalPages)
            ? $page < (int) $totalPages
            : count($rows) === self::PAGE_SIZE);
        return [$rows, $more];
    }

    private function collect(array $product, int $parentId, array &$items, array &$unmatched, array &$firstSeen, ?string $parentSku = null): void
    {
        $sku = trim((string) ($product['sku'] ?? ''));
        $entry = ['id' => (int) $product['id'], 'name' => (string) ($product['name'] ?? $sku)];
        if ($sku === '' || $sku === $parentSku) {
            $unmatched[] = $entry + ['reason' => $parentId > 0 ? 'keine eigene SKU' : 'keine SKU'];
            return;
        }
        if (isset($firstSeen[$sku])) {
            if (isset($items[$sku])) {
                $unmatched[] = $firstSeen[$sku] + ['reason' => 'SKU mehrfach'];
                unset($items[$sku]);
            }
            $unmatched[] = $entry + ['reason' => 'SKU mehrfach'];
            return;
        }

        $firstSeen[$sku] = $entry;
        $items[$sku] = $this->mapProduct($product, $parentId);
    }

    private function mapProduct(array $product, int $parentId): array
    {
        $meta = [];
        foreach ($product['meta_data'] ?? [] as $entry) {
            $entry = (array) $entry;
            if (isset($entry['key']) && is_scalar($entry['value'] ?? null) && !isset($meta[$entry['key']])) {
                $meta[$entry['key']] = (string) $entry['value'];
            }
        }

        $regular = (string) ($product['regular_price'] ?? '');
        $sale = (string) ($product['sale_price'] ?? '');
        $ean = '';
        foreach ([$product['global_unique_id'] ?? '', $meta['_gm_gtin'] ?? '', $meta['_rank_math_gtin_code'] ?? ''] as $candidate) {
            $ean = trim((string) $candidate);
            if ($ean !== '') {
                break;
            }
        }
        $dimensions = (array) ($product['dimensions'] ?? []);
        $deliveryId = $meta['_lieferzeit'] ?? '-1';
        $termId = filter_var($deliveryId, FILTER_VALIDATE_INT);
        $deliveryTime = $termId === false ? '' : $this->map->textFor($termId);
        $reasons = [];
        if ($termId !== -1 && $deliveryTime === '') {
            $deliveryTime = null;
            $reasons['delivery_time'] = 'Lieferzeit-ID ' . $deliveryId . ' unbekannt';
        }
        $values = [];
        foreach (ArticleFieldCatalog::fields() as $field) {
            $key = $field['key'];
            if (!$field['pull']) {
                continue;
            }
            if ($parentId > 0 && in_array($key, ['weight', 'length', 'width', 'height'], true)) {
                // The REST API returns parent values when the variation does not set its own.
                $values[$key] = null;
                continue;
            }
            // The local repository further narrows matrix-only fields for ordinary variants.
            if ($parentId > 0 && !ArticleFieldCatalog::supportsVariantField($key, true)) {
                continue;
            }
            // A variable parent has no independently editable price in WooCommerce.
            if (($product['type'] ?? '') === 'variable' && $regular === '' && $sale === ''
                && in_array($key, ['price_net', 'pseudo_price'], true)) {
                continue;
            }
            $target = $field['wc'];
            $values[$key] = match ($key) {
                'price_net' => $sale !== '' ? $sale : $regular,
                'pseudo_price' => $sale !== '' ? $regular : '',
                'inactive' => ($product['status'] ?? 'publish') === 'publish' ? '0' : '1',
                'delivery_time' => $deliveryTime,
                'ean' => $ean,
                default => match (true) {
                    str_starts_with($target, 'meta:') => $meta[substr($target, 5)] ?? '',
                    str_starts_with($target, 'dimensions.') => (string) ($dimensions[substr($target, 11)] ?? ''),
                    default => (string) ($product[$target] ?? ''),
                },
            };
        }

        $mapped = ['id' => (int) $product['id'], 'parent_id' => $parentId, 'values' => $values];
        if ($reasons !== []) {
            $mapped['reasons'] = $reasons;
        }
        return $mapped;
    }
}
