<?php
// SPDX-License-Identifier: AGPL-3.0-only
require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/www/lib/ShopimporterBase.php';
require_once dirname(__DIR__, 2) . '/www/pages/shopimporter_woocommerce.php';

use Xentral\Modules\Onlineshop\Data\OrderStatus;
use Xentral\Modules\Onlineshop\Data\OrderStatusUpdateRequest;
use Xentral\Modules\Onlineshop\Data\Shipment;

class OrderTransport
{
    public array $puts = [];
    public array $notes = [];
    public array $meta = [];
    public bool $failPut = false;
    public function get($endpoint) { return (object) ['meta_data' => $this->meta]; }
    public function post($endpoint, $body) { $this->notes[] = $body['note']; }
    public function put($endpoint, $body)
    {
        if ($this->failPut) { throw new RuntimeException('transport failed'); }
        $this->puts[] = $body;
        if (isset($body['meta_data'])) {
            $this->meta = array_map(static fn($row) => (object) $row, $body['meta_data']);
        }
    }
}

function connector(): Shopimporter_Woocommerce
{
    $connector = (new ReflectionClass(Shopimporter_Woocommerce::class))->newInstanceWithoutConstructor();
    $connector->client = new OrderTransport();
    $connector->logger = new class { public function info(...$arguments) {} };
    $connector->statusCompleted = 'fulfilled';
    return $connector;
}

function item(array $changes = []): object
{
    return (object) array_replace([
        'id' => 81, 'sku' => 'BOOK', 'name' => 'Book', 'quantity' => 2,
        'subtotal' => '100.00', 'subtotal_tax' => '7.00',
        'total' => '80.00', 'total_tax' => '5.60',
        'taxes' => [(object) ['id' => 2, 'subtotal' => '7.00', 'total' => '5.60']],
        'variation_id' => 0, 'product_id' => 9,
    ], $changes);
}

function order(array $changes = []): object
{
    $address = (object) [
        'company' => '', 'first_name' => 'Ada', 'last_name' => 'Test', 'address_1' => 'Teststr. 1',
        'address_2' => '', 'city' => 'Berlin', 'postcode' => '10000', 'country' => 'DE',
        'state' => '', 'email' => 'test@example.invalid', 'phone' => '',
    ];
    return (object) array_replace([
        'id' => 12, 'billing' => $address, 'shipping' => clone $address,
        'date_created' => '2026-10-21T10:00:00', 'total' => '208.60', 'transaction_id' => '',
        'number' => 'WC12', 'shipping_total' => '0', 'shipping_tax' => '0', 'currency' => 'CHF',
        'customer_note' => '', 'meta_data' => [], 'discount_total' => '40', 'discount_tax' => '5.20',
        'coupon_lines' => [(object) ['code' => 'first'], (object) ['code' => 'second']],
        'line_items' => [item(), item(['id' => 82, 'sku' => 'TOOL', 'subtotal_tax' => '19', 'total_tax' => '15.20', 'taxes' => [(object) ['id' => 1, 'subtotal' => '19', 'total' => '15.20']]])],
        'fee_lines' => [], 'tax_lines' => [(object) ['rate_id' => 1, 'rate_percent' => 19], (object) ['rate_id' => 2, 'rate_percent' => 7]],
        'payment_method' => 'bacs', 'shipping_lines' => [(object) ['method_id' => 'flat_rate']],
    ], $changes);
}

function feedback(array $shipments): OrderStatusUpdateRequest
{
    $request = new OrderStatusUpdateRequest();
    $request->orderId = 3;
    $request->shopOrderId = '12';
    $request->orderStatus = OrderStatus::Completed;
    foreach ($shipments as [$number, $carrier, $url]) {
        $shipment = new Shipment();
        $shipment->id = count($request->shipments) + 1;
        $shipment->trackingNumber = $number;
        $shipment->shippingMethod = $carrier;
        $shipment->trackingUrl = $url;
        $request->shipments[] = $shipment;
    }
    return $request;
}

function meta(array $body): array
{
    return array_column($body['meta_data'] ?? [], 'value', 'key');
}

runTests([
    'currency is returned in ERP order' => static function () {
        same('CHF', connector()->parseOrder(order())['waehrung'] ?? null);
    },
    'all coupon codes survive without global discount duplication' => static function () {
        $result = connector()->parseOrder(order());
        same(['first', 'second'], $result['coupon_codes'] ?? null);
        same('first, second', $result['rabattname'] ?? null);
        check(!isset($result['rabattbrutto']) && !isset($result['rabattnetto']));
    },
    'mixed tax discounts retain final net amounts and Woo line IDs' => static function () {
        $result = connector()->parseOrder(order());
        same(40.0, $result['articlelist'][0]['price_netto']);
        same(7.0, $result['articlelist'][0]['steuersatz'] ?? null);
        same(19.0, $result['articlelist'][1]['steuersatz'] ?? null);
        same('81', $result['articlelist'][0]['webid'] ?? null);
        same(0, $result['articlelist'][0]['rabatt'] ?? null);
    },
    'gross price preference preserves final gross and final net' => static function () {
        $connector = connector();
        $connector->priceType = 'grosscalculated';
        $line = $connector->parseItem(item());
        same(42.8, $line['price']);
        same(40.0, $line['price_netto'] ?? null);
    },
    'free discounted line retains source tax rate' => static function () {
        $line = connector()->parseItem(item(['total' => '0', 'total_tax' => '0']));
        same(0.0, $line['price_netto']);
        same(7.0, $line['steuersatz'] ?? null);
    },
    'source tax IDs avoid rate errors from rounded low-value tax amounts' => static function () {
        $result = connector()->parseOrder(order(['line_items' => [item([
            'subtotal' => '0.99', 'subtotal_tax' => '0.07', 'total' => '0.49', 'total_tax' => '0.03',
        ])]]));
        same(7.0, $result['articlelist'][0]['steuersatz']);
        same(0.245, $result['articlelist'][0]['price_netto']);
        same(true, $result['woocommerce_position_taxes'] ?? null);
    },
    'compound taxes retain the effective source rate' => static function () {
        $result = connector()->parseOrder(order([
            'line_items' => [item(['subtotal_tax' => '15.50', 'total_tax' => '12.40', 'taxes' => [
                (object) ['id' => 1, 'subtotal' => '10', 'total' => '8'],
                (object) ['id' => 2, 'subtotal' => '5.50', 'total' => '4.40'],
            ]])],
            'tax_lines' => [(object) ['rate_id' => 1, 'rate_percent' => 10, 'compound' => false], (object) ['rate_id' => 2, 'rate_percent' => 5, 'compound' => true]],
        ]));
        same(15.5, $result['articlelist'][0]['steuersatz']);
    },
    'tax-exempt order has zero position tax' => static function () {
        $result = connector()->parseOrder(order(['line_items' => [item(['subtotal_tax' => '0', 'total_tax' => '0', 'taxes' => []])]]));
        same(0.0, $result['articlelist'][0]['steuersatz']);
    },
    'partially known tax IDs use the complete source tax amounts' => static function () {
        $result = connector()->parseOrder(order([
            'line_items' => [item(['subtotal_tax' => '15', 'total_tax' => '12', 'taxes' => [
                (object) ['id' => 1, 'subtotal' => '10', 'total' => '8'],
                (object) ['id' => 999, 'subtotal' => '5', 'total' => '4'],
            ]])],
            'tax_lines' => [(object) ['rate_id' => 1, 'rate_percent' => 10]],
        ]));
        same(15.0, $result['articlelist'][0]['steuersatz']);
    },
    'order without shipping lines has an empty delivery mapping' => static function () {
        same('', connector()->parseOrder(order(['shipping_lines' => []]))['lieferung']);
    },
    'shipping preserves its own source net amount and effective tax rate' => static function () {
        $result = connector()->parseOrder(order(['shipping_total' => '10.00', 'shipping_tax' => '0.70']));
        same(10.0, $result['versandkostennetto'] ?? null);
        same(7.0, $result['versandkostensteuersatz'] ?? null);
        same(10.7, $result['versandkostenbrutto']);
    },
    'negative fees become ERP article positions with taxes' => static function () {
        $connector = connector();
        $connector->feeArticleNumber = 'FEE-EXISTING';
        $result = $connector->parseOrder(order(['fee_lines' => [(object) [
            'id' => 83, 'name' => 'Adjustment', 'total' => '-10', 'total_tax' => '-1.90',
            'taxes' => [(object) ['id' => 1, 'total' => '-1.90']],
        ]]]));
        $fee = $result['articlelist'][2] ?? [];
        same('FEE-EXISTING', $fee['articleid'] ?? null);
        same(-10.0, $fee['price_netto'] ?? null);
        same(19.0, $fee['steuersatz'] ?? null);
        same('fee', $fee['wc_line_type'] ?? null);
    },
    'fee without configured article fails explicitly' => static function () {
        try {
            connector()->parseOrder(order(['fee_lines' => [(object) ['id' => 83, 'name' => 'Fee', 'total' => '10', 'total_tax' => '0', 'taxes' => []]] ]));
        } catch (RuntimeException $error) { check(strpos($error->getMessage(), 'feeArticleNumber') !== false); return; }
        throw new RuntimeException('unmapped fee silently accepted');
    },
    'zero quantity fails explicitly' => static function () {
        try { connector()->parseItem(item(['quantity' => 0])); }
        catch (UnexpectedValueException $error) { return; }
        throw new RuntimeException('zero quantity accepted');
    },
    'multi-package feedback preserves legacy and complete tracking fields' => static function () {
        $connector = connector();
        $connector->data = feedback([['A', 'ups', 'https://example.invalid/A'], ['B', 'dhl', 'https://example.invalid/B']]);
        same('ok', $connector->ImportUpdateAuftrag());
        $result = meta($connector->client->puts[0]);
        same('A', $result['tracking_code']);
        same('ups', $result['shipping_carrier']);
        same([
            ['tracking_code' => 'A', 'shipping_carrier' => 'ups', 'tracking_url' => 'https://example.invalid/A'],
            ['tracking_code' => 'B', 'shipping_carrier' => 'dhl', 'tracking_url' => 'https://example.invalid/B'],
        ], $result['openxe_shipments'] ?? null);
        same('fulfilled', $connector->client->puts[0]['status']);
        check(strpos(implode(' ', $connector->client->notes), 'B') !== false);
    },
    'empty shipment feedback has no warnings or erased tracking metadata' => static function () {
        $connector = connector();
        $connector->data = feedback([]);
        same('ok', $connector->ImportUpdateAuftrag());
        same([], meta($connector->client->puts[0]));
        same([], $connector->client->notes);
    },
    'replayed feedback does not create duplicate notes' => static function () {
        $connector = connector();
        $connector->data = feedback([['A', 'ups', 'https://example.invalid/A']]);
        $connector->ImportUpdateAuftrag();
        $connector->ImportUpdateAuftrag();
        same(1, count($connector->client->notes));
    },
    'new shipment after replay produces only one new note' => static function () {
        $connector = connector();
        $connector->data = feedback([['A', 'ups', 'https://example.invalid/A']]);
        $connector->ImportUpdateAuftrag();
        $connector->data = feedback([['A', 'ups', 'https://example.invalid/A'], ['B', 'dhl', 'https://example.invalid/B']]);
        $connector->ImportUpdateAuftrag();
        same(2, count($connector->client->notes));
        same('Tracking Code: B', $connector->client->notes[1]);
    },
    'non-completed status remains untouched' => static function () {
        $connector = connector();
        $connector->data = feedback([]);
        $connector->data->orderStatus = OrderStatus::InProgress;
        $connector->ImportUpdateAuftrag();
        same([], $connector->client->puts);
        same([], $connector->client->notes);
    },
]);
