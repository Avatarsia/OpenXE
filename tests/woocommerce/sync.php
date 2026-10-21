<?php
require __DIR__ . '/bootstrap.php';

use Xentral\Components\WooCommerce\OrderReconciler;

class MemoryImportState
{
    public array $state = [];
    public array $stored = [];
    public function locked(callable $operation) { return $operation(); }
    public function load(): array { return $this->state; }
    public function save(array $state): void { $this->state = $state; }
    public function isStored(int $id): bool { return isset($this->stored[$id]); }
    public function holdDelivery(): bool { return true; }
    public function releaseDelivery(): void {}
}

class ChangedOrderApi
{
    public array $orders;
    public array $queries = [];
    public int $total = 0;
    public function __construct(array $orders) { $this->orders = $orders; }
    public function get($endpoint, $query = []) {
        $this->queries[] = [$endpoint, $query];
        if ($endpoint !== 'orders') {
            return $this->orders[(int) basename($endpoint)];
        }
        $orders = array_values(array_filter($this->orders, static function ($order) use ($query) {
            return $order->date_modified_gmt > $query['modified_after']
                && $order->date_modified_gmt < $query['modified_before']
                && !in_array($order->id, $query['exclude'] ?? [], true);
        }));
        usort($orders, static fn($a, $b) => strcmp($a->date_modified_gmt, $b->date_modified_gmt) ?: $a->id <=> $b->id);
        $this->total = count($orders);
        return array_slice($orders, ($query['page'] - 1) * $query['per_page'], $query['per_page']);
    }
    public function getLastResponse() { return new class($this->total) { public function __construct(public $total) {} public function getHeader($name) { return $this->total; } }; }
}

function changedOrder(int $id, string $status = 'processing', string $modified = '2026-10-21T09:00:00'): object
{
    return (object) ['id' => $id, 'status' => $status, 'date_created_gmt' => '2020-01-01T00:00:00', 'date_modified_gmt' => $modified];
}

function reconciler(array $orders, &$state = null, &$api = null): OrderReconciler
{
    $state = new MemoryImportState();
    $api = new ChangedOrderApi($orders);
    return new OrderReconciler($api, $state, static fn() => strtotime('2026-10-21T10:00:00Z'));
}

runTests([
    'late payment uses modification date and does not checkpoint before storage' => static function () {
        $sync = reconciler([1 => changedOrder(1)], $state, $api);
        same(1, $sync->next(['processing'])->id);
        same(null, $state->state['timestamp'] ?? null);
        same('modified', $api->queries[0][1]['orderby']);
        check(!isset($api->queries[0][1]['after']));
    },
    'unacknowledged pending order is leased rather than delivered twice' => static function () {
        $sync = reconciler([1 => changedOrder(1)], $state);
        $sync->next(['processing']);
        same(null, $sync->next(['processing']));
    },
    'failed persistence cannot be acknowledged or advance cursor' => static function () {
        $sync = reconciler([1 => changedOrder(1)], $state);
        $sync->next(['processing']);
        try { $sync->acknowledge(1); throw new RuntimeException('ack accepted'); }
        catch (LogicException $error) { check(strpos($error->getMessage(), 'stored') !== false); }
        same(null, $state->state['timestamp'] ?? null);
    },
    'same-second peers and restart preserve cursor bucket' => static function () {
        $sync = reconciler([1 => changedOrder(1), 2 => changedOrder(2)], $state, $api);
        $sync->next(['processing']);
        $state->stored[1] = true;
        $sync->acknowledge(1);
        $sync = new OrderReconciler($api, $state, static fn() => strtotime('2026-10-21T10:00:00Z'));
        same(2, $sync->next(['processing'])->id);
        $state->stored[2] = true;
        $sync->acknowledge(2);
        same(null, $sync->next(['processing']));
        same([1, 2], $state->state['ids']);
    },
    'stored orders call reconciliation once without being reimported' => static function () {
        $sync = reconciler([1 => changedOrder(1)], $state);
        $state->stored[1] = true;
        $seen = [];
        same(null, $sync->next(['processing'], static function ($order) use (&$seen) { $seen[] = $order->id; }));
        same(null, $sync->next(['processing']));
        same([1], $seen);
    },
    'failed existing-order reconciliation remains retryable' => static function () {
        $sync = reconciler([1 => changedOrder(1)], $state);
        $state->stored[1] = true;
        try { $sync->next(['processing'], static function () { throw new RuntimeException('refund failed'); }); }
        catch (RuntimeException $error) { same('refund failed', $error->getMessage()); }
        same(null, $state->state['timestamp'] ?? null);
        same(null, $sync->next(['processing'], static function () {}));
        same([1], $state->state['ids']);
    },
    'ineligible status does not hide later eligible status change' => static function () {
        $sync = reconciler([1 => changedOrder(1, 'on-hold')], $state, $api);
        same(null, $sync->next(['processing']));
        $api->orders[1] = changedOrder(1, 'processing', '2026-10-21T09:05:00');
        same(1, $sync->next(['processing'])->id);
    },
    'expired lease redelivers after restart without skipping source' => static function () {
        $sync = reconciler([1 => changedOrder(1)], $state, $api);
        $sync->next(['processing']);
        $state->state['pending']['lease_until'] = 0;
        $sync = new OrderReconciler($api, $state, static fn() => strtotime('2026-10-21T10:00:00Z'));
        same(1, $sync->next(['processing'])->id);
    },
    'durable queue write survives crash before acknowledgement' => static function () {
        $sync = reconciler([1 => changedOrder(1), 2 => changedOrder(2)], $state);
        $sync->next(['processing']);
        $state->stored[1] = true;
        same(2, $sync->next(['processing'])->id);
    },
    'source without modification timestamp fails before checkpoint' => static function () {
        $order = changedOrder(1); unset($order->date_modified_gmt);
        $state = new MemoryImportState();
        $api = new class($order) { public function __construct(public $order) {} public function get($ep, $query = []) { return [$this->order]; } };
        try { (new OrderReconciler($api, $state))->next(['processing']); throw new RuntimeException('invalid accepted'); }
        catch (UnexpectedValueException $error) { check(strpos($error->getMessage(), 'timestamp') !== false); }
        same([], $state->state);
    },
    'changed pending order cannot skip intervening orders after lease recovery' => static function () {
        $sync = reconciler([1 => changedOrder(1), 2 => changedOrder(2, 'processing', '2026-10-21T09:01:00')], $state, $api);
        $sync->next(['processing']);
        $state->state['pending']['lease_until'] = 0;
        $api->orders[1] = changedOrder(1, 'processing', '2026-10-21T09:05:00');
        same(1, $sync->next(['processing'])->id);
        $state->stored[1] = true;
        $sync->acknowledge(1);
        same(2, $sync->next(['processing'])->id);
    },
    'more than one hundred same-second peers remain reachable' => static function () {
        $orders = [];
        for ($id = 1; $id <= 101; $id++) { $orders[$id] = changedOrder($id); }
        $sync = reconciler($orders, $state, $api);
        for ($id = 1; $id <= 100; $id++) { $state->stored[$id] = true; }
        same(101, $sync->next(['processing'])->id);
    },
    'source edits between batches do not shift an offset past an unprocessed order' => static function () {
        $orders = [];
        for ($id = 1; $id <= 100; $id++) { $orders[$id] = changedOrder($id, 'on-hold'); }
        $orders[101] = changedOrder(101, 'processing', '2026-10-21T09:01:00');
        $orders[102] = changedOrder(102, 'on-hold', '2026-10-21T09:02:00');
        $api = new class($orders) extends ChangedOrderApi {
            public function get($endpoint, $query = []) {
                $rows = parent::get($endpoint, $query);
                if (count($this->queries) === 1) { $this->orders[1] = changedOrder(1, 'on-hold', '2026-10-21T10:01:00'); }
                return $rows;
            }
        };
        $sync = new OrderReconciler($api, new MemoryImportState(), static fn() => strtotime('2026-10-21T10:00:00Z'));
        same(101, $sync->next(['processing'])->id);
        foreach ($api->queries as [$endpoint, $query]) { same(1, $query['page']); }
    },
    'active delivery owner fences redelivery even after lease expiry' => static function () {
        $store = new class extends MemoryImportState {
            public bool $otherOwner = false;
            public function holdDelivery(): bool { return !$this->otherOwner; }
        };
        $sync = new OrderReconciler(new ChangedOrderApi([1 => changedOrder(1)]), $store, static fn() => strtotime('2026-10-21T10:00:00Z'));
        same(1, $sync->next(['processing'])->id);
        $store->state['pending']['lease_until'] = 0; $store->otherOwner = true;
        same(null, $sync->next(['processing']));
        same(null, $store->state['timestamp'] ?? null);
    },
    'count excludes processed peers but includes newer modifications of the same ID' => static function () {
        $sync = reconciler([1 => changedOrder(1)], $state, $api);
        $state->stored[1] = true;
        $sync->next(['processing']);
        same(0, $sync->count());
        $api->orders[1] = changedOrder(1, 'processing', '2026-10-21T09:05:00');
        same(1, $sync->count());
    },
    'late acknowledgement cannot release the current order delivery fence' => static function () {
        $store = new class extends MemoryImportState {
            public int $releases = 0;
            public function releaseDelivery(): void { $this->releases++; }
        };
        $store->stored[1] = true;
        $store->state = ['pending' => ['id' => 2, 'timestamp' => '2026-10-21T09:00:00', 'lease_until' => 0]];
        $sync = new OrderReconciler(new ChangedOrderApi([]), $store);
        $sync->acknowledge(1);
        same(0, $store->releases);
    },
]);
