<?php
// SPDX-License-Identifier: AGPL-3.0-only

namespace Xentral\Components\WooCommerce;

/** Uses the existing configuration table; no schema changes or shop credentials in the journal. */
class ImportStateStore
{
    private $database;
    private int $shopId;
    private string $key;

    public function __construct($database, int $shopId)
    {
        if ($shopId < 1) {
            throw new \InvalidArgumentException('WooCommerce shop id must be positive');
        }
        $this->database = $database;
        $this->shopId = $shopId;
        $this->key = 'woocommerce_import_' . $shopId;
    }

    public function locked(callable $operation)
    {
        $lock = 'openxe_' . $this->key;
        if ((int) $this->database->fetchValue('SELECT GET_LOCK(:name, 0)', ['name' => $lock]) !== 1) {
            throw new \RuntimeException('WooCommerce import is already running for this shop');
        }
        try {
            return $operation();
        } finally {
            $this->database->fetchValue('SELECT RELEASE_LOCK(:name)', ['name' => $lock]);
        }
    }

    public function load(): array
    {
        $value = $this->database->fetchValue('SELECT wert FROM konfiguration WHERE name = :name', ['name' => $this->key]);
        if ($value === false || $value === null || $value === '') {
            return [];
        }
        $state = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($state)) {
            throw new \UnexpectedValueException('WooCommerce import journal is not an object');
        }
        return $state;
    }

    public function holdDelivery(): bool
    {
        $name = 'openxe_woocommerce_delivery_' . $this->shopId;
        $owner = $this->database->fetchValue('SELECT IS_USED_LOCK(:name) = CONNECTION_ID()', ['name' => $name]);
        if ((int) $owner === 1) {
            return true;
        }
        if ((int) $this->database->fetchValue('SELECT GET_LOCK(:name, 0)', ['name' => $name]) !== 1) {
            return false;
        }
        // The caller writes the queue outside the connector. Keep the lock through that boundary,
        // including slow callers; an expiring lease alone cannot fence a still-running writer.
        register_shutdown_function(function () { $this->releaseDelivery(); });
        return true;
    }

    public function releaseDelivery(): void
    {
        $this->database->fetchValue('SELECT RELEASE_LOCK(:name)', ['name' => 'openxe_woocommerce_delivery_' . $this->shopId]);
    }

    public function save(array $state): void
    {
        $value = json_encode($state, JSON_THROW_ON_ERROR);
        if (strlen($value) > 65000) {
            throw new \LengthException('WooCommerce import timestamp bucket exceeds journal capacity');
        }
        $this->database->perform(
            'INSERT INTO konfiguration (name, wert, adresse, firma) VALUES (:name, :wert, 0, 0) '
            . 'ON DUPLICATE KEY UPDATE wert = VALUES(wert)',
            ['name' => $this->key, 'wert' => $value]
        );
    }

    public function isStored(int $orderId): bool
    {
        $parameters = ['shop' => $this->shopId, 'id' => (string) $orderId];
        if ($this->database->fetchValue(
            'SELECT id FROM shopimport_auftraege WHERE shopid = :shop AND extid = :id AND trash = 0 LIMIT 1',
            $parameters
        )) {
            return true;
        }
        return (bool) $this->database->fetchValue(
            'SELECT id FROM auftrag WHERE shop = :shop AND shopextid = :id LIMIT 1',
            $parameters
        );
    }
}
