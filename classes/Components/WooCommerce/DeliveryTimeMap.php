<?php
// SPDX-License-Identifier: AGPL-3.0-only

namespace Xentral\Components\WooCommerce;

final class DeliveryTimeMap
{
    private array $namesById = [];
    private array $idsByName = [];

    /** @param callable(): array<int, string> $fetchTerms */
    public function __construct(callable $fetchTerms, string $fallbackSetting)
    {
        try {
            $terms = $fetchTerms();
        } catch (\Throwable $error) {
            $terms = self::parseFallback($fallbackSetting);
        }

        foreach ($terms as $id => $name) {
            $name = trim(html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($name === '') {
                continue;
            }
            $this->namesById[$id] = $name;
            $this->idsByName[mb_strtolower($name, 'UTF-8')] = $id;
        }
    }

    public function idFor(string $text): ?int
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        return $text === '' ? -1 : ($this->idsByName[$text] ?? null);
    }

    public function textFor(int $id): string
    {
        return $id === -1 ? '' : ($this->namesById[$id] ?? '');
    }

    private static function parseFallback(string $setting): array
    {
        $terms = [];
        foreach (preg_split('/\r\n|\n|\r/', $setting) as $line) {
            [$name, $rawId] = array_pad(explode('=', $line, 2), 2, '');
            $id = filter_var(trim($rawId), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id !== false && trim($name) !== '') {
                $terms[$id] = $name;
            }
        }
        return $terms;
    }
}
