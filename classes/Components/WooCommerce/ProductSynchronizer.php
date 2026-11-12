<?php
// SPDX-License-Identifier: AGPL-3.0-only
namespace Xentral\Components\WooCommerce;

use InvalidArgumentException;
use RuntimeException;

/** Core product/variation export. Uploading source Dateien remains the connector's responsibility. */
class ProductSynchronizer
{
    private $client;

    public function __construct($client)
    {
        $this->client = $client;
    }

    /**
     * $payload contains already prepared fields (e.g. uploaded images/attachment metadata).
     * Options: backorders (no/notify/yes or 0/1/2), metadataprefix, priceType (net/gross),
     * variationPayloads keyed by source article ID. Source properties use local WC attributes.
     * @return array{id:int,variations:array}
     */
    public function sync(array $article, array $payload = [], array $options = []): array
    {
        $mapped = array_replace($this->map($article, $options), $payload);
        $sku = $this->sku($article);
        if ($sku === '') {
            throw new InvalidArgumentException('Product SKU must not be empty.');
        }
        $variants = $this->variants($article);
        $groups = [];
        foreach ($variants as &$variant) {
            $selection = $variant['selection'];
            if (empty($selection)) {
                // Ordinary non-matrix variants may have no source option information.
                $selection = [['name' => 'Artikelnummer', 'option' => $this->sku($variant['source'])]];
                $variant['selection'] = $selection;
            }
            foreach ($selection as $attribute) {
                $groups[$attribute['name']][] = $attribute['option'];
            }
        }
        unset($variant);
        if (!empty($variants)) {
            $mapped['type'] = 'variable';
            $attributes = [];
            foreach ($mapped['attributes'] ?? [] as $attribute) {
                if (!isset($groups[$attribute['name'] ?? ''])) {
                    $attributes[] = $attribute;
                }
            }
            foreach ($article['matrix_varianten']['gruppen'] ?? [] as $name => $values) {
                foreach ($values as $value => $enabled) {
                    if ($enabled) {
                        $groups[$name][] = (string) $value;
                    }
                }
            }
            foreach ($groups as $name => $values) {
                $attributes[] = ['name' => (string) $name, 'visible' => true, 'variation' => true, 'options' => array_values(array_unique($values))];
            }
            $mapped['attributes'] = $attributes;
            unset($mapped['regular_price'], $mapped['sale_price'], $mapped['stock_quantity'], $mapped['stock_status']);
            $mapped['manage_stock'] = false;
        }
        $existing = $this->client->get('products', ['sku' => $sku, 'per_page' => 100]);
        $existing = $this->findSku($existing, $sku);
        $parentId = (int) ($existing['parent_id'] ?? 0);
        if ($parentId > 0) {
            if (!empty($variants)) {
                throw new RuntimeException('A variable parent SKU already belongs to a WooCommerce variation.');
            }
            $id = (int) $existing['id'];
            $this->client->put('products/' . $parentId . '/variations/' . $id, $this->variationPayload($mapped));
            return ['id' => $id, 'variations' => []];
        }
        if ($existing !== null) {
            $id = (int) $existing['id'];
            $this->client->put('products/' . $id, $mapped);
        } else {
            $created = (array) $this->client->post('products', array_merge(['type' => 'simple'], $mapped, ['sku' => $sku]));
            $id = (int) ($created['id'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('WooCommerce product creation returned no ID.');
            }
        }
        $result = ['id' => $id, 'variations' => []];
        if (empty($variants)) {
            return $result;
        }
        $endpoint = 'products/' . $id . '/variations';
        $bySku = [];
        for ($page = 1; ; $page++) {
            $rows = $this->client->get($endpoint, ['per_page' => 100, 'page' => $page]);
            if (!is_array($rows)) {
                throw new RuntimeException('Invalid WooCommerce variation response.');
            }
            foreach ($rows as $row) {
                $row = (array) $row;
                $bySku[(string) $row['sku']] = (int) $row['id'];
            }
            if (count($rows) < 100) {
                break;
            }
        }
        foreach ($variants as $sourceId => $variant) {
            $variationSku = $this->sku($variant['source']);
            $variation = $this->map($variant['source'], $options);
            $override = $options['variationPayloads'][$sourceId] ?? [];
            if (isset($override['meta_data'])) {
                $override['meta_data'] = array_values(array_column(array_merge($variation['meta_data'] ?? [], $override['meta_data']), null, 'key'));
            }
            $variation = array_replace($variation, $override);
            $variation['attributes'] = $variant['selection'];
            $variation['sku'] = $variationSku;
            $variation = $this->variationPayload($variation);
            if (isset($bySku[$variationSku])) {
                $variationId = $bySku[$variationSku];
                $this->client->put($endpoint . '/' . $variationId, $variation);
            } else {
                $created = (array) $this->client->post($endpoint, $variation);
                $variationId = (int) ($created['id'] ?? 0);
                if ($variationId <= 0) {
                    throw new RuntimeException('WooCommerce variation creation returned no ID.');
                }
                $bySku[$variationSku] = $variationId;
            }
            $result['variations'][$sourceId] = $variationId;
        }
        return $result;
    }

    /** Maps actual remote export keys; absent optional fields do not erase shop values. */
    public function map(array $article, array $options = []): array
    {
        $payload = ['sku' => $this->sku($article)];
        foreach (['name_de' => 'name', 'uebersicht_de' => 'description', 'kurztext_de' => 'short_description', 'ean' => 'global_unique_id'] as $source => $target) {
            if (array_key_exists($source, $article)) {
                $payload[$target] = html_entity_decode((string) $article[$source], ENT_QUOTES, 'UTF-8');
            }
        }
        if (isset($article['inaktiv']) || isset($article['gesperrt'])) {
            $payload['status'] = $this->flag($article['inaktiv'] ?? false) || $this->flag($article['gesperrt'] ?? false) ? 'private' : 'publish';
        }
        if (isset($article['preis']) && $article['preis'] !== '') {
            $net = (float) $article['preis'];
            $gross = ($options['priceType'] ?? 'net') === 'gross';
            $price = $gross ? (float) ($article['bruttopreis'] ?? $net) : $net;
            $factor = $gross && $net != 0 ? $price / $net : 1;
            $regular = max($price, (float) ($article['pseudopreis'] ?? 0) * $factor);
            $payload['regular_price'] = number_format($regular, 2, '.', '');
            $payload['sale_price'] = $regular > $price ? number_format($price, 2, '.', '') : '';
        }
        if (array_key_exists('gewicht', $article)) {
            $payload['weight'] = (string) ($article['gewicht'] ?? '');
        }
        $dimensions = [];
        foreach (['laenge' => 'length', 'breite' => 'width', 'hoehe' => 'height'] as $source => $target) {
            if (array_key_exists($source, $article)) {
                $dimensions[$target] = (string) ($article[$source] ?? '');
            }
        }
        if ($dimensions !== []) {
            $payload['dimensions'] = $dimensions;
        }
        $backorders = $options['backorders'] ?? $article['backorders'] ?? 'no';
        if (is_numeric($backorders)) {
            $backorders = ['no', 'notify', 'yes'][(int) $backorders] ?? 'no';
        }
        if (!in_array($backorders, ['no', 'notify', 'yes'], true)) {
            throw new InvalidArgumentException('Invalid backorders setting.');
        }
        if ($this->flag($article['restmenge'] ?? false) || $this->flag($article['ausverkauft'] ?? false)) {
            $backorders = 'no';
        }
        $payload['backorders'] = $backorders;
        if (array_key_exists('anzahl_lager', $article) || array_key_exists('lag', $article) || $this->flag($article['ausverkauft'] ?? false)) {
            $stock = $article['anzahl_lager'] ?? $article['lag'] ?? 0;
            if (isset($article['pseudolager']) && is_numeric($article['pseudolager']) && (float) $article['pseudolager'] > 0) {
                $stock = $article['pseudolager'];
            }
            if ($this->flag($article['ausverkauft'] ?? false)) {
                $stock = 0;
            }
            $payload['manage_stock'] = $stock !== '' && $stock !== null;
            if ($payload['manage_stock']) {
                $payload['stock_quantity'] = max(0, (int) $stock);
                $payload['stock_status'] = $payload['stock_quantity'] > 0 ? 'instock' : ($backorders === 'no' ? 'outofstock' : 'onbackorder');
            } else {
                $payload['stock_status'] = 'instock';
            }
        }
        $meta = [];
        foreach (['metadescription_de' => '_yoast_wpseo_metadesc', 'metatitle_de' => '_yoast_wpseo_title'] as $source => $key) {
            if (array_key_exists($source, $article)) {
                $meta[] = ['key' => $key, 'value' => $article[$source]];
            }
        }
        foreach ($article['meta_data'] ?? [] as $key => $value) {
            if (is_array($value) && isset($value['key'])) {
                $meta[] = $value;
            } else {
                $meta[] = ['key' => (string) ($options['metadataprefix'] ?? '') . $key, 'value' => $value];
            }
        }
        if ($meta !== []) {
            $payload['meta_data'] = $meta;
        }
        $attributes = [];
        foreach ($article['eigenschaften'] ?? [] as $attribute) {
            $name = (string) ($attribute['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $values = is_array($attribute['values'] ?? null) ? $attribute['values'] : [$attribute['values'] ?? ''];
            foreach ($values as $value) {
                $attributes[$name][] = (string) $value;
            }
        }
        foreach ($attributes as $name => $values) {
            $payload['attributes'][] = ['name' => $name, 'visible' => true, 'variation' => false, 'options' => array_values(array_unique($values))];
        }
        foreach (['images', 'image'] as $field) {
            if (array_key_exists($field, $article)) {
                $payload[$field] = $article[$field];
            }
        }
        foreach (['downloadable', 'virtual'] as $field) {
            if (array_key_exists($field, $article)) {
                $payload[$field] = $this->flag($article[$field]);
            }
        }
        if (isset($article['downloads']) && (!empty($payload['downloadable']) || $article['downloads'] === [])) {
            $payload['downloads'] = array_values($article['downloads']);
        }
        return $payload;
    }

    private function variants(array $article): array
    {
        $variants = [];
        foreach ($article['artikel_varianten'] ?? [] as $source) {
            $sourceId = $source['artikel'] ?? null;
            if ($sourceId === null) {
                throw new InvalidArgumentException('Variant has no source article ID.');
            }
            $variants[$sourceId] = ['source' => $source, 'selection' => []];
        }
        foreach ($article['matrix_varianten']['artikel'] ?? [] as $sourceId => $rows) {
            $source = array_replace($rows[0] ?? [], $variants[$sourceId]['source'] ?? []);
            $selection = [];
            foreach ($rows as $row) {
                if (isset($row['name'], $row['values'])) {
                    $selection[] = ['name' => (string) $row['name'], 'option' => (string) $row['values']];
                }
            }
            $variants[$sourceId] = ['source' => $source, 'selection' => $selection];
        }
        $skus = [];
        foreach ($variants as &$variant) {
            $sku = $this->sku($variant['source']);
            if ($sku === '' || isset($skus[$sku]) || $sku === $this->sku($article)) {
                throw new InvalidArgumentException('Variant SKUs must be non-empty and unique.');
            }
            $skus[$sku] = true;
            if (empty($variant['selection'])) {
                foreach ($variant['source']['eigenschaften'] ?? [] as $attribute) {
                    if (isset($attribute['name'], $attribute['values']) && is_scalar($attribute['values'])) {
                        $variant['selection'][] = ['name' => (string) $attribute['name'], 'option' => (string) $attribute['values']];
                    }
                }
            }
        }
        unset($variant);
        $selections = [];
        $duplicateSelection = false;
        foreach ($variants as $variant) {
            $selection = [];
            foreach ($variant['selection'] as $attribute) {
                $selection[$attribute['name']] = $attribute['option'];
            }
            ksort($selection);
            $signature = json_encode($selection);
            $duplicateSelection = $duplicateSelection || isset($selections[$signature]);
            $selections[$signature] = true;
        }
        if ($duplicateSelection) {
            foreach ($variants as &$variant) {
                $variant['selection'][] = ['name' => 'Artikelnummer', 'option' => $this->sku($variant['source'])];
            }
            unset($variant);
        }
        return $variants;
    }

    private function sku(array $article): string
    {
        $foreign = trim((string) ($article['artikelnummer_fremdnummern'][0]['nummer'] ?? ''));
        return $foreign !== '' ? $foreign : trim((string) ($article['nummer'] ?? ''));
    }

    private function flag($value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'yes', 'on'], true);
    }

    private function findSku($rows, string $sku): ?array
    {
        if (!is_array($rows)) {
            throw new RuntimeException('Invalid WooCommerce product response.');
        }
        foreach ($rows as $row) {
            $row = (array) $row;
            if ((string) ($row['sku'] ?? '') === $sku) {
                return $row;
            }
        }
        return null;
    }

    private function variationPayload(array $payload): array
    {
        if (isset($payload['images'][0])) {
            $payload['image'] = $payload['images'][0];
        }
        return array_intersect_key($payload, array_flip(['sku', 'description', 'status', 'regular_price', 'sale_price', 'weight', 'dimensions', 'global_unique_id', 'meta_data', 'backorders', 'stock_status', 'manage_stock', 'stock_quantity', 'attributes', 'image', 'downloadable', 'virtual', 'downloads']));
    }
}
