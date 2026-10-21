<?php
// SPDX-License-Identifier: AGPL-3.0-only

namespace Xentral\Components\WooCommerce;

/** Modification feed with a durable delivery lease and acknowledgement after queue storage. */
class OrderReconciler
{
    private $client;
    private $store;
    private $clock;
    private string $initialTimestamp;

    public function __construct($client, $store, ?callable $clock = null, string $initialTimestamp = '1970-01-01T00:00:00')
    {
        $this->client = $client;
        $this->store = $store;
        $this->clock = $clock ?? static fn() => time();
        $this->validateTimestamp($initialTimestamp);
        $this->initialTimestamp = $initialTimestamp;
    }

    public function next(array $statuses, ?callable $onExisting = null): ?object
    {
        return $this->store->locked(function () use ($statuses, $onExisting) {
            $state = $this->store->load();
            $now = ($this->clock)();
            if (!empty($state['pending'])) {
                $pending = $state['pending'];
                if ($this->store->isStored((int) $pending['id'])) {
                    $state = $this->advance($state, $pending['timestamp'], (int) $pending['id']);
                    $this->store->save($state);
                    $this->store->releaseDelivery();
                } elseif (($pending['lease_until'] ?? 0) > $now) {
                    return null;
                } else {
                    $order = $this->client->get('orders/' . (int) $pending['id']);
                    $this->timestamp($order);
                    if (in_array($order->status ?? '', $statuses, true)) {
                        // The source may have changed during the lease. Acknowledge the original
                        // feed boundary so intervening orders remain reachable on the next read.
                        if (!$this->store->holdDelivery()) {
                            return null;
                        }
                        $state['pending']['lease_until'] = $now + 120;
                        $this->store->save($state);
                        return $order;
                    }
                    // A cancelled reservation is re-read in the modification feed below.
                    unset($state['pending']);
                    $this->store->save($state);
                }
            }

            $processed = 0;
            do {
                $orders = $this->fetchBatch($state);
                if (!is_array($orders)) {
                    throw new \UnexpectedValueException('WooCommerce orders response must be an array');
                }
                foreach ($orders as $order) {
                    $timestamp = $this->timestamp($order);
                    $id = (int) $order->id;
                    // Excluding IDs in the API query would also exclude later changes to those IDs.
                    if (($state['timestamp'] ?? null) === $timestamp && in_array($id, $state['ids'] ?? [], true)) {
                        continue;
                    }
                    if ($this->store->isStored($id)) {
                        if ($onExisting !== null) {
                            $onExisting($order);
                        }
                    } elseif (in_array($order->status ?? '', $statuses, true)) {
                        return $this->reserve($state, $order, $now);
                    }
                    $state = $this->advance($state, $timestamp, $id);
                    $this->store->save($state);
                    $processed++;
                }
            } while ($orders !== [] && $processed < 1000);
            return null;
        });
    }

    public function acknowledge(int $orderId): void
    {
        $this->store->locked(function () use ($orderId) {
            if (!$this->store->isStored($orderId)) {
                throw new \LogicException('WooCommerce order is not durably stored; cursor was not advanced');
            }
            $state = $this->store->load();
            if (!empty($state['pending']) && (int) $state['pending']['id'] !== $orderId) {
                return;
            }
            try {
                if ((int) ($state['pending']['id'] ?? 0) !== $orderId) {
                    return;
                }
                $this->store->save($this->advance($state, $state['pending']['timestamp'], $orderId));
            } finally {
                $this->store->releaseDelivery();
            }
        });
    }

    public function count(): int
    {
        return $this->store->locked(function () {
            $state = $this->store->load();
            $query = $this->query($state);
            $queries = [];
            if (!empty($state['timestamp']) && !empty($state['ids'])) {
                $peers = $query;
                $peers['modified_before'] = min($query['modified_before'], gmdate('Y-m-d\TH:i:s', strtotime($state['timestamp'] . 'Z') + 1));
                $peers['exclude'] = $state['ids'];
                $queries[] = $peers;
                $query['modified_after'] = $state['timestamp'];
            }
            $queries[] = $query;
            $count = 0;
            foreach ($queries as $parameters) {
                $rows = $this->client->get('orders', $parameters);
                if (!is_array($rows)) {
                    throw new \UnexpectedValueException('WooCommerce orders response must be an array');
                }
                $response = $this->client->getLastResponse();
                $total = $response === null ? null : $response->getHeader('x-wp-total');
                $count += is_numeric($total) ? max(0, (int) $total) : count($rows);
            }
            return $count;
        });
    }

    public function query(array $state, int $perPage = 1): array
    {
        $timestamp = $state['timestamp'] ?? $this->initialTimestamp;
        $this->validateTimestamp($timestamp);
        return [
            'status' => 'any',
            'modified_after' => gmdate('Y-m-d\TH:i:s', max(0, strtotime($timestamp . 'Z') - 1)),
            // Only consume completed timestamp buckets, including during concurrent shop writes.
            'modified_before' => gmdate('Y-m-d\TH:i:s', ($this->clock)() - 2),
            'dates_are_gmt' => true,
            'orderby' => 'modified',
            'order' => 'asc',
            'per_page' => $perPage,
            'page' => 1,
        ];
    }

    private function fetchBatch(array $state): array
    {
        $query = $this->query($state, 100);
        if (!empty($state['timestamp']) && !empty($state['ids'])) {
            // ID exclusion is safe ONLY inside the exact, already completed timestamp bucket.
            $peers = $query;
            $peers['modified_before'] = min($query['modified_before'], gmdate('Y-m-d\TH:i:s', strtotime($state['timestamp'] . 'Z') + 1));
            $peers['exclude'] = $state['ids'];
            $rows = $this->client->get('orders', $peers);
            if (!is_array($rows)) {
                throw new \UnexpectedValueException('WooCommerce orders response must be an array');
            }
            if ($rows !== []) {
                return $rows;
            }
            // Keyset pagination avoids offsets shifting when a shop edits an earlier order.
            $query['modified_after'] = $state['timestamp'];
        }
        $rows = $this->client->get('orders', $query);
        if (!is_array($rows)) {
            throw new \UnexpectedValueException('WooCommerce orders response must be an array');
        }
        return $rows;
    }

    private function reserve(array $state, object $order, int $now): ?object
    {
        if (!$this->store->holdDelivery()) {
            return null;
        }
        $state['pending'] = [
            'id' => (int) $order->id,
            'timestamp' => $this->timestamp($order),
            'lease_until' => $now + 120,
        ];
        $this->store->save($state);
        return $order;
    }

    private function advance(array $state, string $timestamp, int $id): array
    {
        $this->validateTimestamp($timestamp);
        if (isset($state['timestamp']) && strcmp($timestamp, $state['timestamp']) < 0) {
            throw new \UnexpectedValueException('WooCommerce modification timestamp moved backwards');
        }
        $ids = ($state['timestamp'] ?? null) === $timestamp ? ($state['ids'] ?? []) : [];
        $ids[] = $id;
        $state['timestamp'] = $timestamp;
        $state['ids'] = array_values(array_unique($ids));
        unset($state['pending']);
        return $state;
    }

    private function timestamp($order): string
    {
        if (!is_object($order) || empty($order->id) || empty($order->date_modified_gmt)) {
            throw new \UnexpectedValueException('WooCommerce order has no valid id/modification timestamp');
        }
        $this->validateTimestamp($order->date_modified_gmt);
        return $order->date_modified_gmt;
    }

    private function validateTimestamp(string $timestamp): void
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $timestamp, new \DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d\TH:i:s') !== $timestamp) {
            throw new \UnexpectedValueException('Invalid WooCommerce modification timestamp');
        }
    }
}
