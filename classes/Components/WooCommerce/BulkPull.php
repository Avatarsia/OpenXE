<?php
// SPDX-License-Identifier: AGPL-3.0-only

namespace Xentral\Components\WooCommerce;

use Closure;
use InvalidArgumentException;
use Throwable;

final class BulkPull
{
    public const MAX_SELECTION = 200;

    private Closure $articleIdForSku;

    /** The resolver returns an authorized article ID, or a row with id, nummer and name_de. */
    public function __construct(
        private ArticleDataRepository $repo,
        private WooProductReader $reader,
        callable $articleIdForSku
    ) {
        $this->articleIdForSku = Closure::fromCallable($articleIdForSku);
    }

    public function compare(int $page): array
    {
        [$matches, $unmatched, $more] = $this->matchPage($page);
        $articles = [];
        foreach ($matches as $id => [$article, $sku, $product]) {
            $openxe = $this->repo->load($id);
            $rows = array_values(array_filter(FieldDiff::compare($openxe, $product['values']),
                static fn (array $row): bool => $row['differs']));
            if ($rows === []) {
                continue;
            }
            $identity = $this->identity($sku, $product);
            if (in_array(true, array_column($rows, 'pullable'), true)) {
                $issue = $this->reader->skuIssue($identity);
                if ($issue !== null) {
                    $unmatched[] = ['id' => $product['id'], 'name' => $product['values']['shop_name'] ?? $sku,
                        'reason' => $issue];
                    continue;
                }
            }
            $articles[$id] = [
                'nummer' => $article['nummer'] ?? $sku,
                'name' => $article['name_de'] ?? $openxe['shop_name']['value'],
                'rows' => $rows,
                'identity' => $identity,
            ];
        }
        return ['articles' => $articles, 'unmatched' => $unmatched, 'more' => $more];
    }

    /** In shop administration, identities are supplied from the confirmed preview. */
    public function apply(array $selection, string $origin, int $page = 1, array $identities = []): array
    {
        if ($selection === []) {
            return [];
        }
        // Read the selected page again, including current SKU assignments and duplicate detection.
        [$matches] = $this->matchPage($page);
        $results = [];
        foreach ($selection as $id => $keys) {
            try {
                [, $sku, $product] = $matches[$id] ?? [null, null, null];
                $current = $product === null ? null : $this->identity($sku, $product);
                $expected = $identities[$id] ?? null;
                $identity = $current ?? $expected;
                $issue = $identity === null ? null : $this->reader->skuIssue($identity);
                if ($expected !== null && $current !== $expected) {
                    throw new InvalidArgumentException('Shop-Produkt hat sich geaendert - bitte neu vergleichen');
                }
                if ($product === null || $issue !== null) {
                    throw new InvalidArgumentException($issue ?? 'Artikel auf dieser Seite nicht mehr eindeutig zugeordnet.');
                }
                $openxe = $this->repo->load($id);
                $rows = array_column(FieldDiff::compare($openxe, $product['values']), null, 'key');
                $values = [];
                foreach ($keys as $key) {
                    $row = $rows[$key] ?? null;
                    if ($row === null) {
                        throw new InvalidArgumentException('Ausgewaehltes Feld ist nicht mehr vergleichbar.');
                    }
                    if (!$row['differs']) {
                        continue;
                    }
                    if (!$row['pullable']) {
                        throw new InvalidArgumentException($row['reason']);
                    }
                    $values[$key] = $row['woocommerce'];
                }
                $this->repo->validate($values);
                $results[$id] = $values === [] ? [] : $this->repo->apply($id, $values, $origin);
            } catch (Throwable $error) {
                $results[$id] = ['error' => RefundStatusService::sanitizeError($error->getMessage())];
            }
        }
        return $results;
    }

    private function identity(string $sku, array $product): array
    {
        return ['id' => $product['id'], 'parent_id' => $product['parent_id'], 'sku' => $sku];
    }

    private function matchPage(int $page): array
    {
        $data = $this->reader->page($page);
        $matches = $duplicateArticles = [];
        $unmatched = $data['unmatched'];
        foreach ($data['items'] as $sku => $product) {
            $article = ($this->articleIdForSku)((string) $sku);
            $article = is_array($article) ? $article : ['id' => $article];
            $id = (int) $article['id'];
            $entry = ['id' => $product['id'], 'name' => $product['values']['shop_name'] ?? (string) $sku];
            if ($id <= 0) {
                $unmatched[] = $entry + ['reason' => 'kein OpenXE-Artikel'];
                continue;
            }
            if (isset($matches[$id])) {
                [, $previousSku, $previousProduct] = $matches[$id];
                $unmatched[] = ['id' => $previousProduct['id'],
                    'name' => $previousProduct['values']['shop_name'] ?? (string) $previousSku,
                    'reason' => 'OpenXE-Artikel mehrfach zugeordnet'];
                unset($matches[$id]);
                $duplicateArticles[$id] = true;
            }
            if (isset($duplicateArticles[$id])) {
                $unmatched[] = $entry + ['reason' => 'OpenXE-Artikel mehrfach zugeordnet'];
                continue;
            }
            $matches[$id] = [$article, (string) $sku, $product];
        }
        return [$matches, $unmatched, $data['more']];
    }
}
