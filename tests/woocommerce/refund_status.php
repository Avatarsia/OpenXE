<?php
// SPDX-License-Identifier: AGPL-3.0-only

require __DIR__ . '/bootstrap.php';

use Xentral\Components\WooCommerce\RefundStatusService;

final class RefundStatusDatabaseFake
{
    public $values = [];
    public $queries = [];
    public function inTransaction() { return false; }
    public function perform($sql, array $params)
    {
        check(strpos($sql, 'UPDATE konfiguration SET wert') === 0);
        $this->values[$params['name']] = $params['value'];
    }
    public function fetchValue($sql, array $params)
    {
        $this->queries[] = [$sql, $params];
        if (strpos($sql, 'GET_LOCK') !== false || strpos($sql, 'RELEASE_LOCK') !== false) { return 1; }
        if (isset($params['name'])) { return $this->values[$params['name']] ?? false; }
        check(strpos($sql, 'COUNT(*)') !== false);
        return count($this->matching($params));
    }
    public function fetchAll($sql, array $params)
    {
        $this->queries[] = [$sql, $params];
        check(strpos($sql, 'LIMIT :limit OFFSET :offset') !== false);
        return array_slice($this->matching($params), $params['offset'], $params['limit']);
    }
    private function matching(array $params)
    {
        $result = [];
        ksort($this->values);
        foreach ($this->values as $name => $value) {
            foreach (['journal', 'queue'] as $kind) {
                $prefix = str_replace('!_', '_', substr($params[$kind], 0, -1));
                if (strpos($name, $prefix) === 0) {
                    $result[] = ['name' => $name, 'wert' => $value]; break;
                }
            }
        }
        return $result;
    }
}

runTests([
    'status combines only current shop journal and queue with bounded SQL pagination' => static function () {
        $db = new RefundStatusDatabaseFake();
        $db->values = [
            'woocommerce_refund:5:41:9' => '{"status":"pending"}',
            'woocommerce_refund:5:42:10' => '{"status":"complete","credit_note_id":123}',
            'woocommerce_refund_retry:5:41' => '{"attempts":2,"next_attempt":1200,"last_attempt":1000,"last_error":"invoice missing"}',
            'woocommerce_refund:6:41:9' => '{"status":"pending"}',
            'woocommerceXrefund:5:41:9' => '{"status":"pending"}',
        ];
        $service = new RefundStatusService($db, 5, static function () { return 1100; });
        $first = $service->page(1, 2);
        same(3, $first['total']); same(2, $first['pages']); same(1, $first['page']); same(2, count($first['rows']));
        same('pending', $first['rows'][0]['status']); same('9', $first['rows'][0]['refund_id']);
        same(123, $first['rows'][1]['credit_note_id']);
        $last = $service->page(2, 2)['rows'][0];
        same('queue', $last['type']); same('41', $last['order_id']); same(2, $last['attempts']);
        same(1200, $last['next_attempt']); same(1000, $last['last_attempt']);
        same('invoice missing', $last['last_error']); same(true, $last['retry_available']); same(false, $last['due']);
        foreach ($db->queries as [$sql, $params]) {
            check(strpos($sql, "ESCAPE '!'") !== false);
            same('woocommerce!_refund:5:%', $params['journal']);
        }
    },
    'old queue JSON is supported and malformed journal cannot look completed or offer retry' => static function () {
        $db = new RefundStatusDatabaseFake();
        $db->values = [
            'woocommerce_refund:5:41:9' => '{"status":"complete","credit_note_id":0}',
            'woocommerce_refund_retry:5:41' => '{"attempts":0,"next_attempt":0}',
        ];
        $rows = (new RefundStatusService($db, 5))->page()['rows'];
        same('invalid', $rows[0]['status']); same(false, $rows[0]['retry_available']);
        same('', $rows[1]['last_error']); same(null, $rows[1]['last_attempt']); same(true, $rows[1]['due']);
    },
    'page limits and source IDs are validated before database queries' => static function () {
        $db = new RefundStatusDatabaseFake(); $service = new RefundStatusService($db, 5);
        $page = $service->page(-20, 5000); same(1, $page['page']); same(100, $page['per_page']); same(1, $page['pages']);
        try { new RefundStatusService($db, '5:6'); } catch (RuntimeException $error) { return; }
        throw new RuntimeException('Invalid shop accepted');
    },
    'stored errors are sanitized again when read and limited for display' => static function () {
        $db = new RefundStatusDatabaseFake();
        $db->values['woocommerce_refund_retry:5:41'] = json_encode([
            'attempts' => 1, 'next_attempt' => 0,
            'last_error' => 'HTTP https://user:password@example.org/a?token=abc api_key=private Authorization: Basic hidden',
        ]);
        $error = (new RefundStatusService($db, 5))->page()['rows'][0]['last_error'];
        foreach (['password', 'example.org', 'private', 'hidden'] as $secret) { check(strpos($error, $secret) === false, $secret); }
        foreach (['{"consumer_secret":"private"}', '{"api_key": "private"}', 'password=private', 'Bearer private'] as $message) {
            check(strpos(RefundStatusService::sanitizeError($message), 'private') === false, $message);
        }
        same(500, strlen(RefundStatusService::sanitizeError(str_repeat('x ', 500))));
        same('Fehlerdetails nicht verfuegbar.', RefundStatusService::sanitizeError("\xff"));
    },
    'status retry delegates only to an existing marker in the selected shop' => static function () {
        $db = new RefundStatusDatabaseFake();
        $db->values = [
            'woocommerce_refund_retry:5:41' => '{"attempts":2,"next_attempt":1200}',
            'woocommerce_refund_retry:6:41' => '{"attempts":2,"next_attempt":1200}',
            'woocommerce_refund:5:41:9' => '{"status":"pending"}',
        ];
        $service = new RefundStatusService($db, 5);
        same(true, $service->retry(41)); same(false, $service->retry(42));
        same(0, json_decode($db->values['woocommerce_refund_retry:5:41'], true)['next_attempt']);
        same(1200, json_decode($db->values['woocommerce_refund_retry:6:41'], true)['next_attempt']);
        same('{"status":"pending"}', $db->values['woocommerce_refund:5:41:9']);
        same(3, count($db->values));
    },
    'damaged source suffixes are visible as invalid without allowing retry' => static function () {
        $db = new RefundStatusDatabaseFake();
        $db->values['woocommerce_refund_retry:5:broken'] = '{}';
        $row = (new RefundStatusService($db, 5))->page()['rows'][0];
        same('invalid', $row['status']); same('', $row['order_id']); same(false, $row['retry_available']);
    },
]);
