<?php
// SPDX-License-Identifier: AGPL-3.0-only

namespace Xentral\Components\WooCommerce;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ArticleDataRepository
{
    private const CONFIG_LIMIT = 65000;
    private const LOG_LIMIT = 500;

    private $database;

    public function __construct(private $app, private int $shopId)
    {
        $this->database = $app->Container->get('Database');
    }

    public function load(int $articleId): array
    {
        return $this->loadState($articleId, false)['fields'];
    }

    public function apply(int $articleId, array $values, string $origin): array
    {
        $ownTransaction = !$this->database->inTransaction();
        if ($ownTransaction) {
            $this->database->beginTransaction();
        }
        try {
            $state = $this->loadState($articleId, true);
            $changes = [];
            foreach (ArticleFieldCatalog::fields() as $field) {
                $key = $field['key'];
                if (!$field['edit'] || !array_key_exists($key, $values)) {
                    continue;
                }
                $new = $this->valueForStorage($key, $values[$key]);
                $effective = $state['fields'][$key]['value'];
                [$storage, $column] = array_pad(explode(':', $field['storage'], 2), 2, '');
                $old = $effective;
                if (FieldDiff::equivalent($key, $effective, $new)) {
                    $old = $storage === 'shop' ? (string) ($state['article'][$column] ?? '') : $effective;
                    if (FieldDiff::equivalent($key, $old, $new)) {
                        continue;
                    }
                }

                if ($storage === 'price') {
                    if ($state['fields'][$key]['source'] === 'group_price') {
                        throw new RuntimeException('Der Gruppenpreis des Shops kann hier nicht geaendert werden.');
                    }
                    $this->writeStandardPrice($articleId, $state['price'], $new);
                } elseif ($storage === 'onrequest') {
                    $name = $this->articleConfigKey($articleId);
                    $config = $this->lockConfig($name);
                    $config['onRequest'] = $new === '1';
                    $this->writeConfig($name, $this->encodeConfig($config));
                } else {
                    $this->database->perform(
                        "UPDATE artikel SET `$column` = :value WHERE id = :article",
                        ['value' => $new, 'article' => $articleId]
                    );
                    if ($storage === 'shop' && $state['override']) {
                        $this->database->perform(
                            "UPDATE artikel_onlineshops SET `$column` = :value WHERE id = :id",
                            ['value' => $new, 'id' => (int) $state['override']['id']]
                        );
                    }
                }
                $changes[$key] = ['old' => $old, 'new' => $new];
            }

            if ($changes !== []) {
                $this->appendLog($articleId, $changes, $origin);
            }
            if ($ownTransaction) {
                $this->database->commit();
            }
            return $changes;
        } catch (Throwable $error) {
            if ($ownTransaction && $this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }
    }

    private function loadState(int $articleId, bool $lock): array
    {
        $articleParams = ['article' => $articleId];
        $shopParams = $articleParams + ['shop' => $this->shopId];
        $lockSql = $lock ? ' FOR UPDATE' : '';
        $article = $this->database->fetchRow('SELECT * FROM artikel WHERE id = :article' . $lockSql, $articleParams);
        if (!$article) {
            throw new InvalidArgumentException('OpenXE-Artikel ' . $articleId . ' wurde nicht gefunden.');
        }
        $override = $this->database->fetchRow(
            'SELECT * FROM artikel_onlineshops WHERE artikel = :article AND shop = :shop AND aktiv = 1'
            . ' AND ausartikel = 0 LIMIT 1' . $lockSql,
            $shopParams
        );
        $foreign = $this->database->fetchValue(
            'SELECT nummer FROM artikelnummer_fremdnummern WHERE artikel = :article AND shopid = :shop'
            . " AND aktiv = 1 AND TRIM(nummer) <> '' ORDER BY bezeichnung = 'SHOPID' DESC LIMIT 1",
            $shopParams
        );
        $sku = trim($foreign === false || $foreign === null ? (string) $article['nummer'] : (string) $foreign);
        $group = (int) $this->database->fetchValue(
            'SELECT preisgruppe FROM shopexport WHERE id = :shop' . $lockSql,
            ['shop' => $this->shopId]
        );
        $price = $group > 0 ? [] : $this->standardPrice($articleId, $lock);
        $priceValue = $group > 0 ? $this->app->erp->GetVerkaufspreisGruppe($articleId, 1, $group) : ($price['preis'] ?? '');
        $config = $this->readConfig($this->articleConfigKey($articleId));

        $fields = [];
        foreach (ArticleFieldCatalog::fields() as $field) {
            [$storage, $column] = array_pad(explode(':', $field['storage'], 2), 2, '');
            [$value, $source] = match ($storage) {
                'price' => [$priceValue, $group > 0 ? 'group_price' : 'price'],
                'foreign_number' => [$sku, 'foreign_number'],
                'onrequest' => [($config['onRequest'] ?? false) === true ? '1' : '0', 'onrequest'],
                'shop' => $override ? [$override[$column] ?? '', 'shop'] : [$article[$column] ?? '', 'artikel'],
                default => [$article[$column] ?? '', 'artikel'],
            };
            $fields[$field['key']] = ['value' => (string) $value, 'source' => $source];
        }

        return ['fields' => $fields, 'article' => $article, 'override' => $override, 'price' => $price];
    }

    private function valueForStorage(string $key, $input): string
    {
        if ($input !== null && !is_scalar($input)) {
            throw new InvalidArgumentException('Ungueltiger Wert fuer WooCommerce-Artikelfeld ' . $key);
        }
        $input = (string) $input;
        if (in_array($key, ['description', 'short_description'], true)) {
            // The article editor saves cleaned HTML; the export adds its own escaping.
            return $this->app->Secure->CleanString($input, 'nojs', 1);
        }

        $value = FieldDiff::normalize($key, $input);
        $numericLimits = [
            'price_net' => [10, 8], 'pseudo_price' => [8, 2],
            'length' => [8, 2], 'width' => [8, 2], 'height' => [8, 2],
            'weight' => [null, null], 'pseudo_stock' => [null, null],
        ];
        if (isset($numericLimits[$key])) {
            if ($input === '' && in_array($key, ['weight', 'pseudo_stock'], true)) {
                return '';
            }
            if ($value === '' && $key === 'price_net') {
                return '';
            }
            [$maxInteger, $maxDecimal] = $numericLimits[$key];
            [$integer, $decimal] = array_pad(explode('.', $value, 2), 2, '');
            if (!preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D', $value)
                || ($maxInteger !== null && (strlen(ltrim($integer, '-')) > $maxInteger || strlen($decimal) > $maxDecimal))) {
                throw new InvalidArgumentException('Ungueltiger Zahlenwert fuer WooCommerce-Artikelfeld ' . $key);
            }
        }

        return $value;
    }

    private function standardPrice(int $articleId, bool $lock): array
    {
        return $this->database->fetchRow(
            'SELECT v.id, v.preis, v.waehrung FROM verkaufspreise AS v'
            . " WHERE v.artikel = :article AND v.ab_menge = 1 AND v.geloescht = 0 AND v.art != 'Gruppe'"
            . " AND (v.objekt = 'Standard' OR v.objekt = '') AND (v.adresse = '0' OR v.adresse = '')"
            . " AND (v.gueltig_bis >= NOW() OR v.gueltig_bis = '0000-00-00')"
            . ' ORDER BY v.preis DESC LIMIT 1' . ($lock ? ' FOR UPDATE' : ''),
            ['article' => $articleId]
        ) ?: [];
    }

    private function writeStandardPrice(int $articleId, array $old, string $value): void
    {
        // The legacy price helper uses another connection and selects customer rows by different criteria.
        if ($old) {
            $this->database->perform(
                'UPDATE verkaufspreise SET gueltig_bis = DATE_SUB(CURDATE(), INTERVAL 1 DAY), apichange = 1 WHERE id = :id',
                ['id' => (int) $old['id']]
            );
        }
        if ($value !== '') {
            $this->database->perform(
                'INSERT INTO verkaufspreise (artikel, objekt, projekt, adresse, preis, waehrung, ab_menge, angelegt_am,'
                . ' gueltig_bis, bemerkung, bearbeiter, logdatei, firma, geloescht, art, apichange)'
                . " VALUES (:article, 'Standard', '', '0', :value, :currency, 1, CURDATE(),"
                . " '0000-00-00', '', '', NOW(), 1, 0, 'Kunde', 1)",
                ['article' => $articleId, 'value' => $value, 'currency' => ($old['waehrung'] ?? '') ?: 'EUR']
            );
        }
        $effectivePrice = $this->standardPrice($articleId, true);
        if (FieldDiff::normalize('price_net', (string) ($effectivePrice['preis'] ?? '')) !== $value) {
            throw new RuntimeException('Ein weiterer aktiver Standardpreis verhindert die Preisaenderung fuer Artikel ' . $articleId . '.');
        }
    }

    private function articleConfigKey(int $articleId): string
    {
        return 'woocommerce_article_' . $this->shopId . '_' . $articleId;
    }

    private function readConfig(string $name, bool $lock = false): array
    {
        $json = $this->database->fetchValue(
            'SELECT wert FROM konfiguration WHERE name = :name' . ($lock ? ' FOR UPDATE' : ''),
            ['name' => $name]
        );
        return $json === false || $json === null ? [] : json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    private function lockConfig(string $name): array
    {
        $this->database->perform(
            "INSERT INTO konfiguration (name, wert, adresse, firma) VALUES (:name, '[]', 0, 0)"
            . ' ON DUPLICATE KEY UPDATE name = VALUES(name)',
            ['name' => $name]
        );
        return $this->readConfig($name, true);
    }

    private function writeConfig(string $name, string $json): void
    {
        if (strlen($json) > self::CONFIG_LIMIT) {
            throw new RuntimeException('WooCommerce-Konfiguration ' . $name . ' ist zu gross (max. 65000 Bytes).');
        }
        $this->database->perform(
            'INSERT INTO konfiguration (name, wert, adresse, firma) VALUES (:name, :wert, 0, 0)'
            . ' ON DUPLICATE KEY UPDATE wert = VALUES(wert)',
            ['name' => $name, 'wert' => $json]
        );
    }

    private function appendLog(int $articleId, array $changes, string $origin): void
    {
        $name = 'woocommerce_pull_log_' . $this->shopId;
        $log = $this->lockConfig($name);
        $time = gmdate('Y-m-d\TH:i:s\Z');
        $user = (int) (($this->app->User ?? null)?->GetID() ?? 0);
        $entries = [];
        foreach ($changes as $field => $change) {
            $entries[] = ['time' => $time, 'user' => $user, 'article' => $articleId, 'field' => $field]
                + $change + ['origin' => $origin];
        }
        $new = $this->encodeConfig($entries);
        if (strlen($new) > self::CONFIG_LIMIT) {
            throw new RuntimeException('Die WooCommerce-Aenderungen sind zu gross fuer das Protokoll (max. 65000 Bytes).');
        }

        // Trim old entries without discarding any of the current changes.
        $log = array_slice($log, -self::LOG_LIMIT + count($entries));
        $json = $this->encodeConfig(array_merge($log, $entries));
        while ($log && strlen($json) > self::CONFIG_LIMIT) {
            array_shift($log);
            $json = $this->encodeConfig(array_merge($log, $entries));
        }
        $this->writeConfig($name, $json);
    }

    private function encodeConfig(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
