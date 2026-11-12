<?php
// SPDX-License-Identifier: AGPL-3.0-only

namespace Xentral\Components\WooCommerce;

use InvalidArgumentException;
use UnexpectedValueException;
use Throwable;

/** Per-shop article download overrides; absent entries preserve the existing Smarty source. */
final class DownloadSettings
{
    private $database;
    private $shopId;

    public function __construct($database, $shopId)
    {
        $this->database = $database;
        $this->shopId = $this->id($shopId);
    }

    public function load($articleId): ?array
    {
        $value = $this->database->fetchValue(
            'SELECT wert FROM konfiguration WHERE name = :name',
            ['name' => $this->key($articleId)]
        );
        if ($value === false || $value === null) {
            return null;
        }
        $settings = json_decode($value, true);
        if (!is_array($settings) || !array_key_exists('downloadable', $settings)
            || !array_key_exists('virtual', $settings) || !array_key_exists('downloads', $settings)) {
            throw new UnexpectedValueException('WooCommerce Download-Einstellungen sind ungueltig. Bitte erneut speichern.');
        }
        try {
            return $this->validate($settings);
        } catch (InvalidArgumentException $error) {
            throw new UnexpectedValueException('WooCommerce Download-Einstellungen sind ungueltig. Bitte erneut speichern.', 0, $error);
        }
    }

    public function save($articleId, array $settings): array
    {
        $key = $this->key($articleId);
        $settings = $this->validate($settings);
        $json = json_encode($settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > 65000) {
            throw new InvalidArgumentException('Die Download-Einstellungen sind zu gross. Bitte weniger Downloads verwenden.');
        }
        $this->mutate($articleId, function () use ($key, $json) {
            $this->database->perform(
                'INSERT INTO konfiguration (name, wert, adresse, firma) VALUES (:name, :wert, 0, 0) '
                . 'ON DUPLICATE KEY UPDATE wert = VALUES(wert)',
                ['name' => $key, 'wert' => $json]
            );
        });
        return $settings;
    }

    public function clear($articleId): void
    {
        $key = $this->key($articleId);
        $this->mutate($articleId, function () use ($key) {
            $this->database->perform('DELETE FROM konfiguration WHERE name = :name', ['name' => $key]);
        });
    }

    /** Apply after Smarty mapping and before product/variation mapping; no URLs are fetched here. */
    public function apply(array $article): array
    {
        $article = $this->applyArticle($article);
        foreach ($article['artikel_varianten'] ?? [] as $index => $variant) {
            $article['artikel_varianten'][$index] = $this->applyArticle($variant);
        }
        foreach ($article['matrix_varianten']['artikel'] ?? [] as $id => $rows) {
            if (isset($rows[0]) && is_array($rows[0])) {
                $article['matrix_varianten']['artikel'][$id][0] = $this->applyArticle($rows[0], $id);
            }
        }
        return $article;
    }

    public function validate(array $settings): array
    {
        $downloads = $settings['downloads'] ?? [];
        if (!is_array($downloads) || count($downloads) > 50) {
            throw new InvalidArgumentException('Maximal 50 Download-Zeilen sind erlaubt.');
        }
        $result = [
            'downloadable' => $this->flag($settings['downloadable'] ?? false),
            'virtual' => $this->flag($settings['virtual'] ?? false),
            'downloads' => [],
        ];
        foreach ($downloads as $download) {
            if (!is_array($download) || !is_string($download['name'] ?? null) || !is_string($download['file'] ?? null)) {
                throw new InvalidArgumentException('Jeder Download benoetigt einen Namen und eine HTTP/HTTPS-URL.');
            }
            $name = trim($download['name']);
            $file = trim($download['file']);
            if ($name === '' && $file === '') {
                continue;
            }
            if ($name === '' || ($nameLength = preg_match_all('/./us', $name)) === false || $nameLength > 255 || $file === '' || strlen($file) > 2048
                || preg_match('/[\x00-\x1f\x7f]/', $download['name'] . $download['file'])
                || !filter_var($file, FILTER_VALIDATE_URL)) {
                throw new InvalidArgumentException('Downloadname (max. 255 Zeichen) und gueltige URL (max. 2048 Bytes) angeben.');
            }
            $parts = parse_url($file);
            if (!in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
                || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
                throw new InvalidArgumentException('Download-URLs muessen HTTP/HTTPS verwenden und duerfen keine Zugangsdaten enthalten.');
            }
            $result['downloads'][] = ['name' => $name, 'file' => $file];
        }
        return $result;
    }

    private function applyArticle(array $article, $fallbackId = null): array
    {
        $id = $article['artikel'] ?? $article['artikelid'] ?? $fallbackId;
        if ($id === null) {
            return $article;
        }
        $settings = $this->load($id);
        if ($settings !== null && !$settings['downloadable']) {
            $settings['downloads'] = [];
        }
        return $settings === null ? $article : array_replace($article, $settings);
    }

    private function mutate($articleId, callable $write): void
    {
        $articleId = $this->id($articleId);
        $ownTransaction = !$this->database->inTransaction();
        if ($ownTransaction) {
            $this->database->beginTransaction();
        }
        try {
            $write();
            // Both normal and matrix variants are exported through artikel.variante_von.
            $this->database->perform(
                "UPDATE artikel_onlineshops SET last_article_hash = '' WHERE shop = :shop "
                . 'AND (artikel = :article OR artikel = (SELECT variante_von FROM artikel WHERE id = :source))',
                ['shop' => $this->shopId, 'article' => $articleId, 'source' => $articleId]
            );
            if ($ownTransaction) {
                $this->database->commit();
            }
        } catch (Throwable $error) {
            if ($ownTransaction && $this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }
    }

    private function flag($value): bool
    {
        if (!in_array($value, [true, false, 1, 0, '1', '0', ''], true)) {
            throw new InvalidArgumentException('Ungueltiger Download-Schalter.');
        }
        return $value === true || $value === 1 || $value === '1';
    }

    private function key($articleId): string
    {
        return 'woocommerce_downloads:' . $this->shopId . ':' . $this->id($articleId);
    }

    private function id($value): string
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string) $value)) {
            throw new InvalidArgumentException('Ungueltige Shop- oder Artikel-ID.');
        }
        return (string) $value;
    }
}
