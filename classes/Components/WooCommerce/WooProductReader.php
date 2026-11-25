<?php
// SPDX-License-Identifier: AGPL-3.0-only

namespace Xentral\Components\WooCommerce;

use InvalidArgumentException;
use RuntimeException;

final class WooProductReader
{
    private const PAGE_SIZE = 100;

    public function __construct(private $client, private DeliveryTimeMap $map)
    {
    }

    public function findBySku(string $sku): ?array
    {
        $sku = trim($sku);
        if ($sku === '') {
            return null;
        }
        $match = null;
        $page = 1;
        do {
            [$products, $more] = $this->fetchPage('products', $page++, ['sku' => $sku]);
            foreach ($products as $product) {
                $product = (array) $product;
                if (trim((string) ($product['sku'] ?? '')) !== $sku) {
                    continue;
                }
                if ($match !== null) {
                    throw new RuntimeException('SKU mehrfach in WooCommerce: ' . $sku);
                }
                $match = $product;
            }
        } while ($more);

        return $match === null ? null : $this->mapProduct($match, (int) ($match['parent_id'] ?? 0));
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
            $variationPage = 1;
            do {
                [$variations, $moreVariations] = $this->fetchPage("products/$parentId/variations", $variationPage++);
                foreach ($variations as $variation) {
                    $this->collect((array) $variation, $parentId, $items, $unmatched, $firstSeen);
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

    private function collect(array $product, int $parentId, array &$items, array &$unmatched, array &$firstSeen): void
    {
        $sku = trim((string) ($product['sku'] ?? ''));
        $entry = ['id' => (int) $product['id'], 'name' => (string) ($product['name'] ?? $sku)];
        if ($sku === '') {
            $unmatched[] = $entry + ['reason' => 'keine SKU'];
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
        $values = [];
        foreach (ArticleFieldCatalog::fields() as $field) {
            $key = $field['key'];
            if (!$field['pull'] || ($parentId > 0 && in_array($key, ['shop_name', 'short_description'], true))) {
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
                'delivery_time' => $this->map->textFor((int) ($meta['_lieferzeit'] ?? -1)),
                'ean' => $ean,
                default => match (true) {
                    str_starts_with($target, 'meta:') => $meta[substr($target, 5)] ?? '',
                    str_starts_with($target, 'dimensions.') => (string) ($dimensions[substr($target, 11)] ?? ''),
                    default => (string) ($product[$target] ?? ''),
                },
            };
        }

        return ['id' => (int) $product['id'], 'parent_id' => $parentId, 'values' => $values];
    }
}
