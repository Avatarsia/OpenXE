<?php
// SPDX-License-Identifier: AGPL-3.0-only

namespace Xentral\Components\WooCommerce;

use InvalidArgumentException;
use RuntimeException;
use Shopimporter_Woocommerce;
use Throwable;

final class ArticleTab
{
    private const CHECKBOXES = ['inactive', 'stock_sync', 'rest_quantity', 'sold_out', 'on_request'];
    private const TEXTAREAS = ['description', 'short_description', 'seo_title', 'seo_description', 'seo_keyword', 'pseudo_stock'];

    private $database;

    public function __construct(private $app)
    {
        $this->database = $app->Container->get('Database');
    }

    public function shopsFor(int $articleId): array
    {
        if ($articleId <= 0) {
            return [];
        }
        $rows = $this->database->fetchAll(
            "SELECT s.id, s.bezeichnung, s.projekt, a.projekt AS article_project
             FROM artikel a
             JOIN shopexport s ON s.aktiv = 1 AND s.geloescht = 0 AND s.modulename = 'shopimporter_woocommerce'
             WHERE a.id = :article AND a.geloescht = 0
             AND (s.id IN (a.shop, a.shop2, a.shop3) OR EXISTS (
                 SELECT 1 FROM artikel_onlineshops ao WHERE ao.artikel = a.id AND ao.shop = s.id AND ao.aktiv = 1
             ))
             ORDER BY s.id",
            ['article' => $articleId]
        );
        return array_values(array_filter($rows, fn (array $shop): bool =>
            $this->app->erp->UserProjektRecht((int) $shop['article_project'])
            && $this->app->erp->UserProjektRecht((int) $shop['projekt'])
        ));
    }

    public function render(int $articleId, int $shopId, string $token): string
    {
        $shop = $this->assignedShop($articleId, $shopId);
        $values = (new ArticleDataRepository($this->app, $shopId))->load($articleId);
        // Where a shop override exists, the form still edits the original article field.
        $article = in_array('shop', array_column($values, 'source'), true) ? $this->articleValues($articleId) : [];
        $compareUrl = "index.php?module=artikel&action=edit&id=$articleId&cmd=woocommerce_compare&shop=$shopId";
        $html = '<div class="wc-admin wc-article-tab" data-compare-url="' . AdminView::e($compareUrl) . '">'
            . '<input type="hidden" name="woo_shop" value="' . $shopId . '">'
            . '<input type="hidden" name="woo_csrf_token" value="' . AdminView::e($token) . '">'
            . '<p>WooCommerce-Shop: <strong>' . AdminView::e($shop['bezeichnung']) . '</strong></p>'
            . '<div class="wc-admin-actions"><button type="submit" name="speichern" value="Speichern">Speichern</button>'
            . '<button type="button" data-woo-compare>Mit WooCommerce vergleichen</button></div>'
            . '<p>&Uuml;bernommene Werte werden erst mit Speichern gesichert.</p>'
            . '<p role="status" data-woo-status hidden></p><div data-woo-results hidden></div>'
            . '<div class="wc-article-fields">';

        $groups = [];
        foreach (ArticleFieldCatalog::fields() as $field) {
            $key = $field['key'];
            $groups[$field['group']][] = $this->renderField($field, $values[$key], $article);
        }
        foreach ($groups as $group => $fields) {
            $html .= '<fieldset><legend>' . AdminView::e($group) . '</legend>' . implode('', $fields) . '</fieldset>';
        }
        return $html . '</div></div>';
    }

    public function compare(int $articleId, int $shopId): array
    {
        try {
            $this->assignedShop($articleId, $shopId);
            $openxe = (new ArticleDataRepository($this->app, $shopId))->load($articleId);
            if (!class_exists(Shopimporter_Woocommerce::class, false)) {
                require_once dirname(__DIR__, 3) . '/www/pages/shopimporter_woocommerce.php';
            }
            $connector = Shopimporter_Woocommerce::clientForShop($this->app, $shopId);
            $reader = new WooProductReader($connector->client, $connector->deliveryTimeMap());
            $sku = $openxe['sku']['value'];
            $product = $reader->findBySku($sku);
            if ($product === null) {
                return ['rows' => [], 'productId' => null, 'error' => "Produkt mit SKU $sku nicht in WooCommerce gefunden"];
            }
            $values = $product['values'];
            foreach (['description', 'short_description'] as $key) {
                if (isset($values[$key])) {
                    // The browser can insert comparison values into the legacy rich-text editor.
                    $values[$key] = $this->app->Secure->CleanString($values[$key], 'nojs', 1);
                }
            }
            $rows = FieldDiff::compare($openxe, $values);
            return ['rows' => $rows, 'productId' => $product['id'], 'error' => null];
        } catch (Throwable $error) {
            $message = RefundStatusService::sanitizeError($error->getMessage());
            $this->app->Container->get('Logger')->error('WooCommerce-Artikelvergleich fehlgeschlagen: ' . $message);
            return ['rows' => [], 'productId' => null, 'error' => $error instanceof InvalidArgumentException
                ? $message : 'WooCommerce-Vergleich fehlgeschlagen.'];
        }
    }

    public function saveExtras(int $articleId, int $shopId, array $post): array
    {
        $token = $post['woo_csrf_token'] ?? null;
        if (!is_string($token) || !$this->app->Container->get('Session')->isCsrfTokenValid('woocommerce_admin', $token)) {
            throw new RuntimeException('Formular abgelaufen. Seite neu laden und erneut speichern.');
        }
        $this->assignedShop($articleId, $shopId);
        $woo = $post['woo'] ?? [];
        if (!is_array($woo)) {
            throw new InvalidArgumentException('Ungueltige WooCommerce-Artikeldaten.');
        }
        $repository = new ArticleDataRepository($this->app, $shopId);
        $stored = $repository->load($articleId);
        $values = array_intersect_key($woo, ['price_net' => true, 'on_request' => true]);
        if (($values['price_net'] ?? null) === '' && $stored['price_net']['value'] === '') {
            unset($values['price_net']);
        }
        if (in_array('shop', array_column($stored, 'source'), true)) {
            $article = $this->articleValues($articleId);
            foreach (ArticleFieldCatalog::fields() as $field) {
                if ($stored[$field['key']]['source'] === 'shop') {
                    $column = substr($field['storage'], strlen('shop:'));
                    $values[$field['key']] = (string) $article[$column];
                }
            }
        }
        return $values === [] ? [] : $repository->apply($articleId, $values, 'article_tab');
    }

    private function assignedShop(int $articleId, int $shopId): array
    {
        foreach ($this->shopsFor($articleId) as $shop) {
            if ((int) $shop['id'] === $shopId) {
                return $shop;
            }
        }
        throw new InvalidArgumentException('Artikel ist diesem WooCommerce-Shop nicht zugeordnet oder nicht freigegeben.');
    }

    private function articleValues(int $articleId): array
    {
        return $this->database->fetchRow('SELECT * FROM artikel WHERE id = :article', ['article' => $articleId]);
    }

    private function renderField(array $field, array $data, array $article): string
    {
        $key = $field['key'];
        [, $mirror] = array_pad(explode(':', $field['storage'], 2), 2, '');
        $override = $data['source'] === 'shop';
        $value = $override ? (string) $article[$mirror] : $data['value'];
        $id = 'wc-article-' . $key;
        $readonly = !$field['edit'] || $data['source'] === 'group_price';
        $attributes = ' id="' . $id . '" name="woo[' . $key . ']" data-woo-key="' . $key . '"'
            . ($mirror !== '' ? ' data-mirror="' . $mirror . '"' : '')
            . ($readonly ? ' readonly' : '');

        if (in_array($key, self::CHECKBOXES, true)) {
            $input = ($key === 'on_request' ? '<input type="hidden" name="woo[on_request]" value="0">' : '')
                . '<input type="checkbox"' . $attributes . ' value="1"'
                . (FieldDiff::normalize($key, $value) === '1' ? ' checked' : '') . '>';
        } elseif (in_array($key, self::TEXTAREAS, true)) {
            $input = '<textarea' . $attributes . ' rows="3">' . AdminView::e($value) . '</textarea>';
        } else {
            $input = '<input type="text"' . $attributes . ' value="' . AdminView::e($value) . '">';
        }
        return '<div class="wc-article-field"><label for="' . $id . '">' . AdminView::e($field['label'])
            . '</label><code>' . AdminView::e($field['wc']) . '</code>' . $input
            . ($override ? '<small>wird auch in der Shop-Zuordnung gesetzt</small>' : '')
            . ($data['source'] === 'group_price' ? '<small>Gruppenpreis des Shops</small>' : '') . '</div>';
    }
}
