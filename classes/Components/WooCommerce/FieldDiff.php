<?php
// SPDX-License-Identifier: AGPL-3.0-only

namespace Xentral\Components\WooCommerce;

final class FieldDiff
{
    public static function compare(array $openxe, array $woo): array
    {
        $rows = [];
        foreach (ArticleFieldCatalog::fields() as $field) {
            $key = $field['key'];
            if (!$field['pull'] || !array_key_exists($key, $woo)) {
                continue;
            }
            $old = (string) ($openxe[$key]['value'] ?? '');
            $new = (string) $woo[$key];
            $rows[] = [
                'key' => $key,
                'label' => $field['label'],
                'openxe' => $old,
                'woocommerce' => $new,
                'differs' => !self::equivalent($key, $old, $new),
            ];
        }
        return $rows;
    }

    public static function equivalent(string $key, string $openxe, string $new): bool
    {
        $normalized = self::normalize($key, $new);
        if (self::normalize($key, $openxe) === $normalized) {
            return true;
        }

        // OpenXE can contain descriptions with the extra escaping used by the old export.
        return in_array($key, ['description', 'short_description'], true)
            && !preg_match('/<[\/!]?[a-z]/i', $openxe)
            && preg_match('/&lt;\/?[a-z]/i', $openxe)
            && self::normalize($key, html_entity_decode($openxe, ENT_QUOTES | ENT_HTML5, 'UTF-8')) === $normalized;
    }

    public static function normalize(string $key, string $value): string
    {
        $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
        if (in_array($key, ['description', 'short_description'], true)) {
            return preg_replace_callback(
                '/&(?:#[0-9]+|#[xX][0-9a-fA-F]+|[a-zA-Z][a-zA-Z0-9]+);|&/',
                static function (array $entity): string {
                    $decoded = html_entity_decode($entity[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    return htmlspecialchars($decoded, ENT_QUOTES, 'UTF-8');
                },
                $value
            );
        }
        if (in_array($key, ['inactive', 'stock_sync', 'rest_quantity', 'sold_out', 'on_request'], true)) {
            return in_array($value, ['1', 'true', 'yes', 'on'], true) ? '1' : '0';
        }
        if (!in_array($key, ['price_net', 'pseudo_price', 'pseudo_stock', 'weight', 'length', 'width', 'height'], true)) {
            return $value;
        }
        if ($value === '' && $key !== 'price_net') {
            return '0';
        }
        if (!preg_match('/^([+-]?)([0-9]*)(?:[.,]([0-9]*))?$/D', $value, $parts)
            || ($parts[2] === '' && ($parts[3] ?? '') === '')) {
            return $value;
        }

        $integer = ltrim($parts[2], '0');
        $decimal = rtrim($parts[3] ?? '', '0');
        $sign = $parts[1] === '-' && ($integer !== '' || $decimal !== '') ? '-' : '';
        return $sign . ($integer === '' ? '0' : $integer) . ($decimal === '' ? '' : '.' . $decimal);
    }
}
