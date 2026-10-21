<?php
// SPDX-License-Identifier: AGPL-3.0-only

require __DIR__ . '/bootstrap.php';

use Xentral\Components\WooCommerce\RefundRetryQueue;

final class RefundRetryDatabaseFake
{
    public $values = [];
    public $locks = [];
    public $failInsert = false;
    public $failUpdate = false;
    public $transaction = false;
    public function inTransaction() { return $this->transaction; }
    public function fetchValue($sql, array $params)
    {
        if (strpos($sql, 'GET_LOCK') !== false) {
            if (isset($this->locks[$params['lock']])) { return 0; }
            $this->locks[$params['lock']] = true;
            return 1;
        }
        if (strpos($sql, 'RELEASE_LOCK') !== false) {
            unset($this->locks[$params['lock']]);
            return 1;
        }
        return $this->values[$params['name']] ?? false;
    }
    public function fetchAll($sql, array $params)
    {
        $prefix = substr($params['prefix'], 0, -1);
        $result = [];
        foreach ($this->values as $name => $value) {
            if (strpos($name, $prefix) === 0) { $result[] = ['name' => $name, 'wert' => $value]; }
        }
        return $result;
    }
    public function perform($sql, array $params)
    {
        if (strpos($sql, 'INSERT') === 0 && $this->failInsert) { throw new RuntimeException('queue storage failed'); }
        if (strpos($sql, 'UPDATE') === 0 && $this->failUpdate) { throw new RuntimeException('backoff storage failed'); }
        if (strpos($sql, 'DELETE') === 0) { unset($this->values[$params['name']]); return; }
        if (strpos($sql, 'INSERT') === 0 && isset($this->values[$params['name']])) { throw new RuntimeException('duplicate'); }
        $this->values[$params['name']] = $params['value'];
    }
}

function retryQueue($db, &$now, $shop = 5)
{
    return new RefundRetryQueue($db, $shop, static function () use (&$now) { return $now; });
}

function retryFailure(callable $callback, $text)
{
    try { $callback(); } catch (RuntimeException $error) {
        check(strpos($error->getMessage(), $text) !== false, $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected ' . $text);
}

runTests([
    'durable queue restart processes only IDs and removes successful markers' => static function () {
        $db = new RefundRetryDatabaseFake(); $now = 1000;
        $queue = retryQueue($db, $now);
        same(false, $queue->hasDue());
        $queue->enqueue(41); $queue->enqueue('41');
        same(1, count($db->values));
        same(true, $queue->hasDue());
        same(['attempts' => 0, 'next_attempt' => 0], json_decode(array_values($db->values)[0], true));
        $seen = [];
        $result = retryQueue($db, $now)->drain(static function ($orderId) use (&$seen) { $seen[] = $orderId; });
        same(['41'], $seen);
        same(['processed' => ['41'], 'failed' => []], $result);
        same([], $db->values);
        same(false, $queue->hasDue());
    },
    'failed refund isolates next order and keeps durable backoff across restarts' => static function () {
        $db = new RefundRetryDatabaseFake(); $now = 1000;
        $queue = retryQueue($db, $now);
        $queue->enqueue(41); $queue->enqueue(42);
        $seen = [];
        $result = $queue->drain(static function ($id) use (&$seen) {
            $seen[] = $id;
            if ($id === '41') { throw new RuntimeException('invoice missing'); }
        });
        same(['41', '42'], $seen);
        same(['processed' => ['42'], 'failed' => [41 => 'invoice missing']], $result);
        same(false, retryQueue($db, $now)->hasDue());
        $queue->enqueue(41);
        same(false, $queue->hasDue());
        $now = 1059; same(false, $queue->hasDue());
        $now = 1060; same(true, $queue->hasDue());
        same(['processed' => ['41'], 'failed' => []], retryQueue($db, $now)->drain(static function () {}));
        same(false, $queue->hasDue());
    },
    'one run processes at most ten due orders and leaves the rest for next run' => static function () {
        $db = new RefundRetryDatabaseFake(); $now = 1000;
        $queue = retryQueue($db, $now);
        for ($id = 1; $id <= 12; $id++) { $queue->enqueue($id); }
        $seen = [];
        $queue->drain(static function ($id) use (&$seen) { $seen[] = $id; }, 100);
        same(10, count($seen)); same(2, count($db->values)); same(true, $queue->hasDue());
        $queue->drain(static function () {});
        same(false, $queue->hasDue());
    },
    'shops isolate identical source order IDs' => static function () {
        $db = new RefundRetryDatabaseFake(); $now = 1000;
        $first = retryQueue($db, $now, 5); $second = retryQueue($db, $now, 6);
        $first->enqueue(41); $second->enqueue(41);
        $first->drain(static function () {});
        same(false, $first->hasDue()); same(true, $second->hasDue());
    },
    'exponential backoff is bounded and callback Errors do not block other orders' => static function () {
        $db = new RefundRetryDatabaseFake(); $now = 1000;
        $queue = retryQueue($db, $now); $queue->enqueue(41);
        foreach ([60, 120, 240, 480, 960, 1920, 3600, 3600] as $delay) {
            $result = $queue->drain(static function () { throw new Error('adapter failed'); });
            same([41 => 'adapter failed'], $result['failed']);
            same(false, $queue->hasDue());
            $now += $delay - 1; same(false, $queue->hasDue());
            $now++; same(true, $queue->hasDue());
        }
    },
    'concurrent drains do not execute a second worker callback' => static function () {
        $db = new RefundRetryDatabaseFake(); $now = 1000;
        $queue = retryQueue($db, $now); $queue->enqueue(41);
        $second = retryQueue($db, $now);
        $queue->drain(static function () use ($second) {
            retryFailure(static function () use ($second) { $second->drain(static function () { throw new LogicException('must not process'); }); }, 'locked');
        });
        same([], $db->locks); same(false, $queue->hasDue());
    },
    'failed enqueue reports storage failure before feed checkpoint can advance' => static function () {
        $db = new RefundRetryDatabaseFake(); $now = 1000; $db->failInsert = true;
        $queue = retryQueue($db, $now);
        retryFailure(static function () use ($queue) { $queue->enqueue(41); }, 'storage');
        same([], $db->values); same([], $db->locks);
    },
    'backoff write failure retains original marker while next order still completes' => static function () {
        $db = new RefundRetryDatabaseFake(); $now = 1000;
        $queue = retryQueue($db, $now); $queue->enqueue(41); $queue->enqueue(42);
        $db->failUpdate = true;
        $result = $queue->drain(static function ($id) {
            if ($id === '41') { throw new RuntimeException('invoice missing'); }
        });
        same(['42'], $result['processed']);
        check(strpos($result['failed'][41], 'backoff storage failed') !== false);
        same(1, count($db->values)); same(true, $queue->hasDue());
        $db->failUpdate = false;
        same(['processed' => ['41'], 'failed' => []], retryQueue($db, $now)->drain(static function () {}));
    },
    'failure details are durable bounded and credential safe' => static function () {
        $db = new RefundRetryDatabaseFake(); $now = 1000;
        $queue = retryQueue($db, $now); $queue->enqueue(41);
        $queue->drain(static function () {
            throw new RuntimeException('invoice missing https://user:secret@example.org/refunds?consumer_key=ck_secret Authorization: Bearer private-token');
        });
        $state = json_decode($db->values['woocommerce_refund_retry:5:41'], true);
        same(1000, $state['last_attempt']);
        check(strpos($state['last_error'], 'invoice missing') !== false);
        foreach (['secret', 'private-token', 'example.org', 'ck_'] as $secret) {
            check(strpos($state['last_error'], $secret) === false, 'Leaked ' . $secret);
        }
        check(strlen($state['last_error']) <= 2000);
    },
    'manual retry changes only an existing schedule without resetting attempts or refund journal' => static function () {
        $db = new RefundRetryDatabaseFake(); $now = 1000;
        $queue = retryQueue($db, $now); $queue->enqueue(41);
        $queue->drain(static function () { throw new RuntimeException('invoice missing'); });
        $db->values['woocommerce_refund:5:41:9'] = '{"status":"pending"}';
        $before = $db->values['woocommerce_refund:5:41:9'];
        same(false, $queue->hasDue());
        same(true, $queue->retry(41)); same(true, $queue->hasDue());
        same(false, $queue->retry(42)); same(2, count($db->values));
        $state = json_decode($db->values['woocommerce_refund_retry:5:41'], true);
        same(1, $state['attempts']); same(0, $state['next_attempt']); same('invoice missing', $state['last_error']);
        same($before, $db->values['woocommerce_refund:5:41:9']);
        same([], $db->locks);
    },
    'outer transactions cannot silently defer the durable queue insertion' => static function () {
        $db = new RefundRetryDatabaseFake(); $now = 1000; $db->transaction = true;
        $queue = retryQueue($db, $now);
        retryFailure(static function () use ($queue) { $queue->enqueue(41); }, 'autocommit');
        same([], $db->values); same([], $db->locks);
    },
]);
