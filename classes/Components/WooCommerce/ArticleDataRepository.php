<?php
// SPDX-License-Identifier: AGPL-3.0-only

namespace Xentral\Components\WooCommerce;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ArticleDataRepository
{
    // Match the active shop foreign number selected for the export payload.
    public const SHOP_SKU_SQL = "COALESCE(NULLIF(TRIM((SELECT fn.nummer FROM artikelnummer_fremdnummern AS fn "
        . "WHERE fn.artikel = a.id AND fn.shopid = :shop AND fn.aktiv = 1 AND fn.nummer <> '' "
        . "ORDER BY fn.bezeichnung = 'SHOPID' DESC LIMIT 1)), ''), TRIM(a.nummer))";

    private const PRICE_GROUP = 'Shop nutzt eine Preisgruppe - Preis bitte unter Verkaufspreise pflegen';
    private const PRICE_AMBIGUOUS = 'Mehrere gueltige Preise ab Menge 1 - bitte unter Verkaufspreise pflegen';

    private const CONFIG_LIMIT = 65000;
    private const LOG_LIMIT = 500;

    // database/struktur.sql; shop_name_de is defined in the 1.13 upgrade schema.
    // The mirrored text columns have the same limits in artikel and artikel_onlineshops.
    private const VARCHAR_LIMITS = [
        'shop_name' => 255,
        'inactive' => 255,
        'pseudo_stock' => 255,
        'delivery_time' => 255,
        'weight' => 255,
        'ean' => 1024,
        'customs_tariff' => 64,
        'origin_country' => 64,
        'manufacturer' => 255,
    ];
    private const TEXT_FIELDS = ['description', 'short_description', 'seo_title', 'seo_description', 'seo_keyword'];
    private const TEXT_BYTE_LIMIT = 65535;

    private $database;
    private ShopCategoryStore $categoryStore;

    public function __construct(private $app, private int $shopId)
    {
        $this->database = $app->Container->get('Database');
        $this->categoryStore = new ShopCategoryStore($this->database, $shopId);
    }

    public function load(int $articleId): array
    {
        return $this->loadState($articleId, false)['fields'];
    }

    public static function priceBlockReason(?string $source): ?string
    {
        return match ($source) {
            'group_price' => self::PRICE_GROUP,
            'price_ambiguous' => self::PRICE_AMBIGUOUS,
            default => null,
        };
    }

    public function validate(array $values): void
    {
        $this->prepareUpdates($values, true);
    }

    private function prepareUpdates(array $values, bool $checkOriginalLength = false): array
    {
        $updates = [];
        foreach (ArticleFieldCatalog::fields() as $field) {
            $key = $field['key'];
            if (!$field['edit'] || !array_key_exists($key, $values)) {
                continue;
            }
            $new = $this->valueForStorage($key, $values[$key]);
            // The legacy widget writes its own input before the repository normalizes it.
            $valueForLength = $checkOriginalLength && is_string($values[$key]) ? $values[$key] : $new;
            $this->validateTextLength($key, $valueForLength);
            [$storage, $column] = array_pad(explode(':', $field['storage'], 2), 2, '');
            $updates[$key] = [$new, $storage, $column];
        }
        return $updates;
    }

    public static function applyCsvShopFields($app, int $articleId, array $values): void
    {
        $database = $app->Container->get('Database');
        $database->beginTransaction();
        try {
            // Lock the article before discovering and locking its overrides.
            $database->fetchValue('SELECT id FROM artikel WHERE id = :article FOR UPDATE', ['article' => $articleId]);
            $overrides = $database->fetchAll(
                'SELECT a.id, a.shop FROM artikel_onlineshops AS a'
                . ' INNER JOIN shopexport AS s ON s.id = a.shop'
                . ' WHERE a.artikel = :article AND a.aktiv = 1 AND a.ausartikel = 0'
                . " AND s.geloescht = 0 AND s.modulename = 'shopimporter_woocommerce' ORDER BY a.shop, a.id FOR UPDATE",
                ['article' => $articleId]
            );
            // With no override, there is only the article value to update.
            foreach ($overrides ?: [['shop' => 0, 'id' => 0]] as $override) {
                (new self($app, (int) $override['shop']))->apply($articleId, $values, 'csv_import', (int) $override['id']);
            }
            $database->commit();
        } catch (Throwable $error) {
            $database->rollBack();
            throw $error;
        }
    }

    public static function applyCsvPrice($app, int $articleId, string $price): void
    {
        $database = $app->Container->get('Database');
        $database->beginTransaction();
        try {
            $database->fetchValue('SELECT id FROM artikel WHERE id = :article FOR UPDATE', ['article' => $articleId]);
            $shops = $database->fetchAll(
                'SELECT s.id AS shop FROM shopexport AS s'
                . ' INNER JOIN artikel AS art ON art.id = :article'
                . ' LEFT JOIN artikel_onlineshops AS a ON a.artikel = art.id AND a.shop = s.id AND a.aktiv = 1'
                . ' WHERE (a.id IS NOT NULL OR s.id IN (art.shop, art.shop2, art.shop3))'
                . " AND s.aktiv = 1 AND s.geloescht = 0 AND s.modulename = 'shopimporter_woocommerce' ORDER BY s.id FOR UPDATE",
                ['article' => $articleId]
            );
            if ($shops === []) {
                throw new InvalidArgumentException('Keine aktive WooCommerce-Shopzuordnung vorhanden.');
            }
            $repositories = [];
            foreach (array_unique(array_column($shops, 'shop')) as $shopId) {
                $repository = new self($app, (int) $shopId);
                // All assigned shops share the standard row. Check every price group before changing it.
                if ($repository->priceGroup(true)) {
                    throw new InvalidArgumentException(self::PRICE_GROUP);
                }
                $repositories[] = $repository;
            }
            foreach ($repositories as $repository) {
                $repository->apply($articleId, ['price_net' => $price], 'csv_import');
            }
            $database->commit();
        } catch (Throwable $error) {
            $database->rollBack();
            throw $error;
        }
    }

    public function apply(int $articleId, array $values, string $origin, ?int $overrideId = null): array
    {
        $updates = $this->prepareUpdates($values);
        $ownTransaction = !$this->database->inTransaction();
        if ($ownTransaction) {
            $this->database->beginTransaction();
        }
        try {
            $state = $this->loadState($articleId, true, $overrideId, isset($updates['on_request']));
            $price = $state['fields']['price_net'];
            $reason = self::priceBlockReason($price['source']);
            if ($reason !== null && isset($updates['price_net'])
                && ($origin === 'csv_import' || !FieldDiff::equivalent('price_net', $price['value'], $updates['price_net'][0]))) {
                throw new InvalidArgumentException($reason);
            }
            $changes = [];
            $audit = [];
            foreach ($updates as $key => [$new, $storage, $column]) {
                $effective = $state['fields'][$key]['value'];
                // CSV mirroring preserves the actual column value, including empty pseudolager.
                $exact = $origin === 'csv_import' && $storage === 'shop' && $key !== 'pseudo_price';
                $effectiveChanged = $exact ? $effective !== $new : !FieldDiff::equivalent($key, $effective, $new);
                $articleOld = (string) ($state['article'][$column] ?? '');
                $articleChanged = $storage === 'shop'
                    ? ($exact ? $articleOld !== $new : !FieldDiff::equivalent($key, $articleOld, $new))
                    : $effectiveChanged;
                if (!$effectiveChanged && !$articleChanged) {
                    continue;
                }

                if ($storage === 'price') {
                    $this->writePrice($articleId, $state['price'], $new);
                    $audit[] = ['field' => $key, 'target' => 'price', 'old' => $effective, 'new' => $new];
                } elseif ($storage === 'onrequest') {
                    $this->categoryStore->setOnRequest($articleId, $new === '1');
                    $audit[] = ['field' => $key, 'target' => 'onrequest', 'old' => $effective, 'new' => $new];
                } else {
                    if ($articleChanged) {
                        $this->database->perform(
                            "UPDATE artikel SET `$column` = :value WHERE id = :article",
                            ['value' => $new, 'article' => $articleId]
                        );
                        $audit[] = ['field' => $key, 'target' => 'artikel', 'old' => $articleOld, 'new' => $new];
                    }
                    if ($storage === 'shop' && $state['override'] && $effectiveChanged) {
                        $this->database->perform(
                            "UPDATE artikel_onlineshops SET `$column` = :value WHERE id = :id",
                            ['value' => $new, 'id' => (int) $state['override']['id']]
                        );
                        $audit[] = ['field' => $key, 'target' => 'shop', 'old' => $effective, 'new' => $new];
                    }
                }
                $changes[$key] = ['old' => $effectiveChanged ? $effective : $articleOld, 'new' => $new];
            }

            if ($audit !== []) {
                $this->appendLog($articleId, $audit, $origin);
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

    private function loadState(
        int $articleId,
        bool $lock,
        ?int $overrideId = null,
        bool $lockOnRequest = false
    ): array {
        $articleParams = ['article' => $articleId];
        $shopParams = $articleParams + ['shop' => $this->shopId];
        $lockSql = $lock ? ' FOR UPDATE' : '';
        $article = $this->database->fetchRow('SELECT * FROM artikel WHERE id = :article' . $lockSql, $articleParams);
        if (!$article) {
            throw new InvalidArgumentException('OpenXE-Artikel ' . $articleId . ' wurde nicht gefunden.');
        }
        $override = $overrideId === 0 ? false : $this->database->fetchRow(
            'SELECT * FROM artikel_onlineshops WHERE artikel = :article AND shop = :shop AND aktiv = 1'
            . ' AND ausartikel = 0' . ($overrideId === null ? '' : ' AND id = :override') . ' LIMIT 1' . $lockSql,
            $overrideId === null ? $shopParams : $shopParams + ['override' => $overrideId]
        );
        $foreign = $this->database->fetchValue(
            'SELECT nummer FROM artikelnummer_fremdnummern WHERE artikel = :article AND shopid = :shop'
            . " AND aktiv = 1 AND TRIM(nummer) <> '' ORDER BY bezeichnung = 'SHOPID' DESC LIMIT 1",
            $shopParams
        );
        $sku = trim($foreign === false || $foreign === null ? (string) $article['nummer'] : (string) $foreign);
        $group = $this->priceGroup($lock);
        $candidates = $this->standardPriceCandidates($articleId, $lock);
        $priceValue = $candidates[0]['preis'] ?? '';
        $priceSource = 'price';
        if ($group) {
            $priceSource = 'group_price';
            // The export resolves the group price only after a hit in the standard query.
            if ($candidates !== [] && method_exists($this->app->erp, 'GetVerkaufspreisGruppe')) {
                $priceValue = $this->app->erp->GetVerkaufspreisGruppe($articleId, 1, $group);
            }
        } elseif (count($candidates) > 1) {
            $priceSource = 'price_ambiguous';
        }
        $onRequest = $this->categoryStore->isOnRequest($articleId, $lockOnRequest);

        $fields = [];
        foreach (ArticleFieldCatalog::fields() as $field) {
            [$storage, $column] = array_pad(explode(':', $field['storage'], 2), 2, '');
            [$value, $source] = match ($storage) {
                'price' => [$priceValue, $priceSource],
                'foreign_number' => [$sku, 'foreign_number'],
                'onrequest' => [$onRequest ? '1' : '0', 'onrequest'],
                'shop' => $override ? [$override[$column] ?? '', 'shop'] : [$article[$column] ?? '', 'artikel'],
                default => [$article[$column] ?? '', 'artikel'],
            };
            $fields[$field['key']] = ['value' => (string) $value, 'source' => $source];
        }

        return ['fields' => $fields, 'article' => $article, 'override' => $override,
            'price' => $candidates[0] ?? []];
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
                throw new InvalidArgumentException('Ein leerer Wert fuer price_net ist nicht zulaessig.');
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

    private function validateTextLength(string $key, string $value): void
    {
        $maxCharacters = self::VARCHAR_LIMITS[$key] ?? null;
        if ($maxCharacters !== null && mb_strlen($value, 'UTF-8') > $maxCharacters) {
            throw new InvalidArgumentException('Wert fuer ' . $key . ' ist zu lang (max. ' . $maxCharacters . ' Zeichen).');
        }
        if (in_array($key, self::TEXT_FIELDS, true) && strlen($value) > self::TEXT_BYTE_LIMIT) {
            throw new InvalidArgumentException('Wert fuer ' . $key . ' ist zu lang (max. ' . self::TEXT_BYTE_LIMIT . ' Bytes).');
        }
    }

    private function priceGroup(bool $lock): int
    {
        return (int) $this->database->fetchValue(
            'SELECT s.preisgruppe FROM shopexport s INNER JOIN gruppen g ON s.preisgruppe = g.id AND g.aktiv = 1'
            . ' WHERE s.id = :shop LIMIT 1' . ($lock ? ' FOR UPDATE' : ''),
            ['shop' => $this->shopId]
        );
    }

    private function standardPriceCandidates(int $articleId, bool $lock): array
    {
        // Same filters and sort order as class.remote.php. A second row detects ambiguity.
        return $this->database->fetchAll(
            'SELECT v.id, v.preis FROM verkaufspreise AS v'
            . " WHERE v.artikel = :article AND v.ab_menge = 1 AND v.geloescht = 0 AND v.art != 'Gruppe'"
            . " AND (v.objekt = 'Standard' OR v.objekt = '') AND (v.adresse = '0' OR v.adresse = '')"
            . " AND (v.gueltig_bis >= NOW() OR v.gueltig_bis = '0000-00-00')"
            . ' ORDER BY v.preis DESC LIMIT 2' . ($lock ? ' FOR UPDATE' : ''),
            ['article' => $articleId]
        );
    }

    private function writePrice(int $articleId, array $old, string $value): void
    {
        if ($old) {
            $this->database->perform(
                'UPDATE verkaufspreise SET gueltig_bis = DATE_SUB(CURDATE(), INTERVAL 1 DAY), apichange = 1 WHERE id = :id',
                ['id' => (int) $old['id']]
            );
            $this->database->perform(
                'INSERT INTO verkaufspreise (artikel, objekt, projekt, adresse, preis, waehrung, ab_menge,'
                . ' vpe, vpe_menge, angelegt_am, gueltig_bis, bemerkung, bearbeiter, logdatei, firma, geloescht,'
                . ' kundenartikelnummer, art, gruppe, apichange, nichtberechnet, inbelegausblenden, gueltig_ab, kurs, kursdatum)'
                . " SELECT artikel, objekt, projekt, adresse, :value, waehrung, ab_menge, vpe, vpe_menge, CURDATE(),"
                . " '0000-00-00', bemerkung, bearbeiter, NOW(), firma, geloescht, kundenartikelnummer, art, gruppe, 1,"
                . ' nichtberechnet, inbelegausblenden, gueltig_ab, kurs, kursdatum FROM verkaufspreise WHERE id = :id',
                ['id' => (int) $old['id'], 'value' => $value]
            );
        } else {
            $this->database->perform(
                'INSERT INTO verkaufspreise (artikel, objekt, projekt, adresse, preis, waehrung, ab_menge, angelegt_am,'
                . ' gueltig_bis, bemerkung, bearbeiter, logdatei, firma, geloescht, art, gruppe, apichange)'
                . " VALUES (:article, 'Standard', '', '0', :value, 'EUR', 1, CURDATE(),"
                . " '0000-00-00', '', '', NOW(), 1, 0, 'Kunde', NULL, 1)",
                ['article' => $articleId, 'value' => $value]
            );
        }
        $candidates = $this->standardPriceCandidates($articleId, true);
        if (count($candidates) !== 1 || FieldDiff::normalize('price_net', (string) $candidates[0]['preis']) !== $value) {
            throw new RuntimeException('Eine weitere aktive Preiszeile verhindert die Preisaenderung fuer Artikel ' . $articleId . '.');
        }
    }

    private function lockConfig(string $name): array
    {
        $this->database->perform(
            "INSERT INTO konfiguration (name, wert, adresse, firma) VALUES (:name, '[]', 0, 0)"
            . ' ON DUPLICATE KEY UPDATE name = VALUES(name)',
            ['name' => $name]
        );
        $json = $this->database->fetchValue(
            'SELECT wert FROM konfiguration WHERE name = :name FOR UPDATE',
            ['name' => $name]
        );
        return $json === false || $json === null ? [] : json_decode($json, true, 512, JSON_THROW_ON_ERROR);
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
        foreach ($changes as $change) {
            $entries[] = ['time' => $time, 'user' => $user, 'article' => $articleId]
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
