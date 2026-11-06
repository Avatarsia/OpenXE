<?php
/*
 **** COPYRIGHT & LICENSE NOTICE *** DO NOT REMOVE ****
 *
 * Xentral (c) Xentral ERP Sorftware GmbH, Fuggerstrasse 11, D-86150 Augsburg, * Germany 2019
 *
 * This file is licensed under the Embedded Projects General Public License *Version 3.1.
 *
 * You should have received a copy of this license from your vendor and/or *along with this file; If not, please visit www.wawision.de/Lizenzhinweis
 * to obtain the text of the corresponding license version.
 *
 **** END OF COPYRIGHT & LICENSE NOTICE *** DO NOT REMOVE ****
 */
?>
<?php
use Xentral\Components\Http\JsonResponse;
use Xentral\Modules\Onlineshop\Data\ArticleExportResult;
use Xentral\Modules\Onlineshop\Data\OrderStatus;
use Xentral\Modules\Onlineshop\Data\OrderStatusUpdateRequest;
use Xentral\Components\Logger\Logger;
use Xentral\Components\WooCommerce\ClientWrapper;
use Xentral\Components\WooCommerce\ImportStateStore;
use Xentral\Components\WooCommerce\OrderReconciler;
use Xentral\Components\WooCommerce\RefundStateStore;
use Xentral\Components\WooCommerce\RefundSynchronizer;
use Xentral\Components\WooCommerce\RefundPreflight;
use Xentral\Components\WooCommerce\RefundRetryQueue;
use Xentral\Components\WooCommerce\ProductSynchronizer;
use Xentral\Components\WooCommerce\CategorySynchronizer;
use Xentral\Components\WooCommerce\VariationMedia;
use Xentral\Components\WooCommerce\DownloadSettings;
use Automattic\WooCommerce\HttpClient\HttpClientException;

class Shopimporter_Woocommerce extends ShopimporterBase
{
  // protected $canexport = false;

  public $intern = false;
  public $shopid;
  public $data;

  const TIMEOUT = 30;

  /**
   * @var ClientWrapper $client
   */
  public $client;
  public $url;

  /** These variables hold the status strings WooCommerce is using to represent
   * the order status. They can easily be adjusted by the user via the GUI.
   */
  public $statusPending;
  public $statusProcessing;
  public $statusCompleted;
  public $priceType;
  public $feeArticleNumber = '';
  private $refundSync = false;
  private $categorySyncMode = 'additive';
  private $exportDownloads = false;
  private $importModifiedFrom = '';

  /**
   * Stores the preferences that the user can change when editing this shop importer
   * TODO: Looks like this should be moved to ShopimporterBase
   * Unlike the other shop importers this is stored as an object variable - we dont
   * want every single function that needs some prefrences to init a new SQL query
   * @var $preferences array
   */
  protected $preferences;

  private $protokoll;
  /**
   * @var Application
   */
  protected $app;
  protected $dump;

  private $downloadSettings;

  /*
   * Variables for wordpress API
   */
  private $ImportWordpressUserName;
  private $ImportWordpressApplicationPassword;
  private $wordpress_api_path = "wp-json/wp/v2/";
  private $dateienuebertragen;

/** @var Logger $logger */
  public $logger;

  public $backorders_array = array("no","notify","yes");

  /** @var bool $ssl_ignore Whether to ignore SSL certificate validation */
  public $ssl_ignore = false;

  /** @var string $lastImportTimestamp ISO-8601 UTC timestamp of the last successful import */
  public $lastImportTimestamp;

  /** @var bool $lastImportTimestampIsFallback True when lastImportTimestamp was computed as 30-day fallback */
  public $lastImportTimestampIsFallback = false;

  /** @var int[] $lastImportOrderIds WooCommerce order IDs within the current timestamp bucket */
  public $lastImportOrderIds = [];

  public function __construct($app, $intern = false)
  {
    $this->app = $app;
    $this->intern = true;
    $this->logger = $app->Container->get('Logger');
  }

  public function ImportList()
  {
    $msg = $this->app->erp->base64_url_encode('<div class="info">Sie k&ouml;nnen hier die Shops einstellen</div>');
    header('Location: index.php?module=onlineshops&action=list&msg=' . $msg);
    exit;
  }

  /** Number of remaining modification events, excluding already processed timestamp peers. */
  public function ImportGetAuftraegeAnzahl()
  {
    $this->migrateAbNummerIfNeeded();
    $count = $this->getOrderReconciler()->count();
    if ($this->refundSync && $this->getRefundRetryQueue()->hasDue()) {
      $count = max(1, $count);
    }
    return $count;
  }

  /**
   * Returns at most one unimported order from the modification feed.
   * Delivery is fenced until the caller stores the cart and acknowledges it.
   * The durable checkpoint only advances after storage is verified.
   */
  public function ImportGetAuftrag()
  {
    $this->migrateAbNummerIfNeeded();
    $refundQueue = $this->refundSync ? $this->getRefundRetryQueue() : null;
    if ($refundQueue !== null) {
      $this->drainRefundQueue($refundQueue);
    }
    $statuses = array_values(array_filter(array_map('trim', explode(';', (string) $this->statusPending))));
    $wcOrder = $this->getOrderReconciler()->next($statuses, function ($order) use ($refundQueue) {
      if ($refundQueue !== null && !empty($order->refunds)) {
        // Durably queue before advancing the modification checkpoint. Booking errors are isolated.
        $refundQueue->enqueue((string) $order->id);
      }
    });
    if ($wcOrder === null) {
      return null;
    }
    $order = $this->parseOrder($wcOrder);
    return [[
      'id' => $order['auftrag'],
      'sessionid' => '',
      'logdatei' => '',
      'warenkorb' => base64_encode(serialize($order)),
    ]];
  }

  /**
   * Resolves a legacy WooCommerce order ID (ab_nummer) to the order's
   * date_created_gmt timestamp for the one-shot transition from cursor-
   * based to timestamp-based import.
   *
   * @param int $abNummer WooCommerce order ID
   * @return string|null ISO-8601 UTC timestamp or null on failure
   */
  private function resolveAbNummerToTimestamp($abNummer)
  {
    try {
      $order = $this->client->get('orders/' . $abNummer);
    } catch (Exception $e) {
      $this->logger->warning('WooCommerce resolveAbNummerToTimestamp(' . $abNummer . '): ' . $e->getMessage());
      return null;
    }

    if (empty($order->date_created_gmt)) {
      $this->logger->warning('WooCommerce resolveAbNummerToTimestamp(' . $abNummer . '): date_created_gmt missing');
      return null;
    }

    $ts = strtotime((string) $order->date_created_gmt);
    if ($ts === false) {
      return null;
    }
    return gmdate('Y-m-d\TH:i:s', $ts - 1);
  }

  /**
   * Runs the one-shot legacy ab_nummer -> timestamp migration when the stored
   * cursor is still the 30-day fallback and the caller passes an ab_nummer.
   * Idempotent: once migrated, lastImportTimestampIsFallback flips to false
   * and subsequent calls become no-ops.
   */
  private function migrateAbNummerIfNeeded()
  {
    if (!$this->lastImportTimestampIsFallback) {
      return;
    }
    $data = $this->CatchRemoteCommand('data');
    if (empty($data['ab_nummer'])) {
      return;
    }
    $resolved = $this->resolveAbNummerToTimestamp((int) $data['ab_nummer']);
    if ($resolved !== null) {
      $this->persistLastImportTimestamp($resolved);
      return;
    }
    // Resolution failed (order deleted, 404, missing date_created_gmt). Persist the
    // current 30-day fallback so subsequent runs use a stable lower bound
    // instead of sliding the window on every cron cycle.
    $this->logger->warning(
      sprintf(
        'WooCommerce ab_nummer=%d konnte nicht aufgeloest werden; persistiere 30-Tage-Fallback als Cursor',
        (int) $data['ab_nummer']
      )
    );
    $this->persistLastImportTimestamp($this->lastImportTimestamp);
  }

  // This function searches the wcOrder for the specified WC Meta key
  // and returns it if found, null otherise
  public function get_wc_meta($wcOrder, $meta_key)
  {
    $value = null;
    foreach ($wcOrder->meta_data as $meta) {
      if ($meta->key == $meta_key) {
        $value = $meta->value;
        break;
      }
    }
    return $value;
  }

  // Parse the given WooCommerce order, return a Xentral array-represented order.
  // Overload this method whenever additional attributes are required.
  public function parseOrder($wcOrder)
  {


    $order = array();
    $order['auftragsdaten'] = $wcOrder;

    $isBillingCompany = !self::emptyString(
      $wcOrder->billing->company
    );

    $isShippingCompany = !self::emptyString(
      $wcOrder->shipping->company
    );

    $seperateShippingAddress = !self::compareObjects(
      $wcOrder->billing,
      $wcOrder->shipping,
      [
        'first_name',
        'second_name',
        'company',
        'address_1',
        'address_2',
        'city',
        'state',
        'postcode',
        'country'
      ]
    );

    if ($isBillingCompany) {
      $order['name'] = $wcOrder->billing->company;
      $order['anrede'] = 'firma';
      $order['ansprechpartner'] = $wcOrder->billing->first_name . ' ' . $wcOrder->billing->last_name;
    } else {
      $order['name'] = $wcOrder->billing->first_name . ' ' . $wcOrder->billing->last_name;

      // Retrieve title from meta data
      // This is not a standard WC Feature! Should work with the very popuplar "WooCommerce germanized" plugin though
      $meta_title = $this->get_wc_meta($wcOrder, "_billing_title");
      if (!is_null($meta_title)) {
        $order['anrede'] = ($meta_title == 'mrs') ? 'frau' : 'herr';
      }
    }

    if (!empty($wcOrder->subshop)) {
      $order['subshop'] = $wcOrder->subshop;
    }

    // General order properties and billing address
    $order['auftrag'] = $wcOrder->id;
    $order['order'] = json_decode(json_encode($wcOrder), true);
    $order['strasse'] = $wcOrder->billing->address_1;
    if (!empty($wcOrder->billing->address_2)) {
      $order['adresszusatz'] = $wcOrder->billing->address_2;
    }
    $order['plz'] = $wcOrder->billing->postcode;
    $order['ort'] = $wcOrder->billing->city;
    $order['land'] = $wcOrder->billing->country;
    $order['email'] = $wcOrder->billing->email;
    $order['telefon'] = $wcOrder->billing->phone;
    $order['bestelldatum'] = $wcOrder->date_created;
    $order['gesamtsumme'] = $wcOrder->total;
    $order['transaktionsnummer'] = $wcOrder->transaction_id;
    $order['onlinebestellnummer'] = $wcOrder->number;
    $order['versandkostenbrutto'] = $wcOrder->shipping_total + $wcOrder->shipping_tax;
    $order['versandkostennetto'] = (float) $wcOrder->shipping_total;
    $order['versandkostensteuersatz'] = (float) $wcOrder->shipping_total != 0.0
      ? round(100 * (float) $wcOrder->shipping_tax / (float) $wcOrder->shipping_total, 6) : 0.0;
    $order['internebemerkung'] = $wcOrder->customer_note;

    if (!empty((string) $wcOrder->currency)) {
      $order['waehrung'] = (string) $wcOrder->currency;
    }
    // Coupon amounts are already included in each WooCommerce line total.
    $order['coupon_codes'] = [];
    foreach ($wcOrder->coupon_lines ?? [] as $couponLine) {
      if (isset($couponLine->code) && (string) $couponLine->code !== '') {
        $order['coupon_codes'][] = (string) $couponLine->code;
      }
    }
    if (!empty($order['coupon_codes'])) {
      $order['rabattname'] = implode(', ', $order['coupon_codes']);
    }

    $seperateShippingAddress = !self::compareObjects(
      $wcOrder->billing,
      $wcOrder->shipping,
      [
        'first_name',
        'second_name',
        'company',
        'address_1',
        'address_2',
        'city',
        'state',
        'postcode',
        'country'
      ]
    );

    if ($seperateShippingAddress) {
      $order['abweichendelieferadresse'] = '1';

      if ($isShippingCompany) {
        $order['lieferadresse_name'] = $wcOrder->shipping->company;
        $order['lieferadresse_ansprechpartner'] = $wcOrder->shipping->first_name . ' ' . $wcOrder->shipping->last_name;
      } else {
        $order['lieferadresse_name'] = $wcOrder->shipping->first_name . ' ' . $wcOrder->shipping->last_name;
      }

      $order['lieferadresse_strasse'] = $wcOrder->shipping->address_1;
      if (!empty($wcOrder->shipping->address_2)) {
        $order['lieferadresse_adresszusatz'] = $wcOrder->shipping->address_2;
      }
      $order['lieferadresse_plz'] = $wcOrder->shipping->postcode;
      $order['lieferadresse_ort'] = $wcOrder->shipping->city;
      $order['lieferadresse_land'] = $wcOrder->shipping->country;
    }

    // VAT stuff

    $vatId = $this->get_wc_meta($wcOrder, "_billing_ustid");
    if (!is_null($vatId) && !self::emptyString($vatId)) {
      $order['ustid'] = $vatId;
    }

    $taxRates = [];
    foreach ($wcOrder->tax_lines ?? [] as $taxLine) {
      if (isset($taxLine->rate_id, $taxLine->rate_percent)) {
        $taxRates[$taxLine->rate_id] = [
          'rate' => (float) $taxLine->rate_percent,
          'compound' => !empty($taxLine->compound),
        ];
      }
    }
    $order['articlelist'] = [];
    foreach ($wcOrder->line_items as $wcOrderItem) {
      $order['articlelist'][] = $this->parseItem($wcOrderItem, $taxRates);
    }
    foreach ($wcOrder->fee_lines ?? [] as $feeLine) {
      if (empty($this->feeArticleNumber)) {
        throw new RuntimeException('WooCommerce feeArticleNumber muss einer vorhandenen ERP-Artikelnummer entsprechen.');
      }
      $feeItem = (object) [
        'id' => $feeLine->id,
        'sku' => $this->feeArticleNumber,
        'name' => $feeLine->name,
        'quantity' => 1,
        'subtotal' => $feeLine->total,
        'subtotal_tax' => $feeLine->total_tax,
        'total' => $feeLine->total,
        'total_tax' => $feeLine->total_tax,
        'taxes' => $feeLine->taxes ?? [],
      ];
      $position = $this->parseItem($feeItem, $taxRates);
      $position['wc_line_type'] = 'fee';
      $order['articlelist'][] = $position;
    }
    $order['woocommerce_position_taxes'] = true;

    $order['zahlungsweise'] = $wcOrder->payment_method;
    $order['lieferung'] = $wcOrder->shipping_lines[0]->method_id ?? '';

    return $order;
  }

  function parseItem($wcOrderItem, array $taxRates = [])
  {
    $quantity = (float) $wcOrderItem->quantity;
    if ($quantity <= 0) {
      throw new UnexpectedValueException('WooCommerce order line quantity must be greater than zero.');
    }

    // WooCommerce totals are authoritative and already include line discounts.
    $total = (float) $wcOrderItem->total;
    $tax = (float) $wcOrderItem->total_tax;
    $subtotal = (float) $wcOrderItem->subtotal;
    $subtotalTax = (float) $wcOrderItem->subtotal_tax;
    $taxRate = $subtotal != 0.0 ? round(100 * $subtotalTax / $subtotal, 2)
      : ($total != 0.0 ? round(100 * $tax / $total, 2) : 0.0);
    $mappedRates = [];
    $allRatesMapped = true;
    foreach ($wcOrderItem->taxes ?? [] as $lineTax) {
      if (isset($taxRates[$lineTax->id])) {
        $mappedRates[$lineTax->id] = $taxRates[$lineTax->id];
      } else {
        $allRatesMapped = false;
      }
    }
    if (!empty($mappedRates) && $allRatesMapped) {
      $taxRate = 0.0;
      foreach ($mappedRates as $rate) {
        if (!$rate['compound']) {
          $taxRate += $rate['rate'];
        }
      }
      foreach ($mappedRates as $rate) {
        if ($rate['compound']) {
          $taxRate = (100 + $taxRate) * (100 + $rate['rate']) / 100 - 100;
        }
      }
      $taxRate = round($taxRate, 6);
    }

    $orderItem = array();
    $orderItem['articleid'] = $wcOrderItem->sku;
    $orderItem['name'] = $wcOrderItem->name;
    $orderItem['price_netto'] = $total / $quantity;
    if ($this->priceType === 'grosscalculated') {
      $orderItem['price'] = ($total + $tax) / $quantity;
    }
    $orderItem['quantity'] = $wcOrderItem->quantity;
    $orderItem['rabatt'] = 0;
    $orderItem['steuersatz'] = $taxRate;
    $orderItem['webid'] = (string) $wcOrderItem->id;
    $orderItem['wc_line_type'] = 'product';

    // The item could be a variable product in which case we have to retrieve the sku of the variation product
    if (!empty($wcOrderItem->variation_id)) {
      $variation_product_sku = $this->getSKUByShopId($wcOrderItem->product_id, $wcOrderItem->variation_id);
      if (!empty($variation_product_sku)) {
        $orderItem['articleid'] = $variation_product_sku;
      }
    }

    return $orderItem;
  }

  /**
   * Sets the Order status to processing, meaning we've successfully imported
   * the order into our DB. This prevents the order from beeing imported again.
   */
  public function ImportDeleteAuftrag()
  {
    $orderId = (int) ($this->CatchRemoteCommand('data')['auftrag'] ?? 0);
    if ($orderId <= 0) {
      return 'failed';
    }
    $store = new ImportStateStore($this->app->Container->get('Database'), (int) $this->shopid);
    if (!$store->isStored($orderId)) {
      throw new LogicException('WooCommerce order is not durably stored; acknowledgement refused');
    }
    $this->client->put('orders/' . $orderId, ['status' => $this->statusProcessing]);
    $this->getOrderReconciler()->acknowledge($orderId);
    return 'ok';
  }

  /**
   * Updates the order status once payment and shipping are set to ok.
   * Also updates the order with the shipping tracking code
   * @return string
   * @throws HttpClientException
   */
  public function ImportUpdateAuftrag()
  {
    /** @var OrderStatusUpdateRequest $data */
    $data = $this->CatchRemoteCommand('data');

    if ($data->orderStatus !== OrderStatus::Completed)
      return;

    $shipments = [];
    foreach ($data->shipments as $shipment) {
      $shipments[] = [
        'tracking_code' => $shipment->trackingNumber ?? '',
        'shipping_carrier' => $shipment->shippingMethod ?? '',
        'tracking_url' => $shipment->trackingUrl ?? '',
      ];
    }

    $updateData = ['status' => $this->statusCompleted];
    if (!empty($shipments)) {
      $remoteOrder = $this->client->get('orders/' . $data->shopOrderId);
      $previousShipments = $this->get_wc_meta($remoteOrder, 'openxe_shipments') ?? [];
      $previousCodes = [];
      foreach ($previousShipments as $shipment) {
        $shipment = (array) $shipment;
        if (!empty($shipment['tracking_code'])) {
          $previousCodes[] = (string) $shipment['tracking_code'];
        }
      }
      // Older connector versions stored only one tracking number.
      $previousCode = $this->get_wc_meta($remoteOrder, 'tracking_code');
      if (!empty($previousCode)) {
        $previousCodes[] = (string) $previousCode;
      }
      foreach ($shipments as $shipment) {
        $trackingCode = (string) $shipment['tracking_code'];
        if ($trackingCode !== '' && !in_array($trackingCode, $previousCodes, true)) {
          $this->client->post('orders/' . $data->shopOrderId . '/notes', [
            'note' => 'Tracking Code: ' . $trackingCode
          ]);
          $previousCodes[] = $trackingCode;
          $this->logger->info(
            "WooCommerce Tracking Code Rueckmeldung fuer Auftrag: " . $data->orderId,
            ['orderId' => $data->shopOrderId, 'trackingCode' => $trackingCode]
          );
        }
      }
      $updateData['meta_data'] = [
        ['key' => 'tracking_code', 'value' => $shipments[0]['tracking_code']],
        ['key' => 'shipping_carrier', 'value' => $shipments[0]['shipping_carrier']],
        ['key' => 'openxe_shipments', 'value' => $shipments],
      ];
    }
    $this->client->put('orders/' . $data->shopOrderId, $updateData);

    $this->logger->info(
      "WooCommerce Statusrückmeldung 'completed' für Auftrag: " . $data->orderId,
      [
        'orderId' => $data->shopOrderId,
        'status' => $this->statusCompleted
      ]
    );
    return 'ok';
  }

  /**
   * This function syncs the current stock to the remote WooCommerce shop.
   * Uses WC REST v3 batch endpoints to reduce HTTP round-trips from 2n to
   * roughly ceil(n/100) + ceil(n/100) requests.
   *
   * @return int Number of articles successfully synced
   * @throws HttpClientException
   */
  public function ImportSendListLager()
  {
    $tmp = $this->CatchRemoteCommand('data');
    $anzahl = 0;
    $ctmp = (!empty($tmp) ? count($tmp) : 0);
    $this->logger->debug("WooCommerce ImportSendListLager ".$ctmp." entries");

    // --- Step 1: Collect all SKUs and compute desired stock params ---

    // $pendingUpdates: sku => ['lageranzahl' => int, 'status' => string]
    $pendingUpdates = [];

    for ($i = 0; $i < $ctmp; $i++) {
      $artikel = $tmp[$i]['artikel'];
      if ($artikel === 'ignore') {
        continue;
      }
      $nummer = $tmp[$i]['nummer'];
      if (!empty($tmp[$i]['artikelnummer_fremdnummern'][0]['nummer'])) {
        $nummer = $tmp[$i]['artikelnummer_fremdnummern'][0]['nummer'];
      }
      $lageranzahl = $tmp[$i]['anzahl_lager'];
      $pseudolager = trim($tmp[$i]['pseudolager']);
      $inaktiv = $tmp[$i]['inaktiv'];
      $status = 'publish';

      if ($pseudolager !== '') {
        $lageranzahl = $pseudolager;
      }
      if ($tmp[$i]['ausverkauft']) {
        $lageranzahl = 0;
      }
      if ($inaktiv) {
        $status = 'private';
      }

      $pendingUpdates[$nummer] = [
        'lageranzahl' => $lageranzahl,
        'status' => $status,
      ];
    }

    if (empty($pendingUpdates)) {
      return 0;
    }

    // --- Step 2: Bulk-resolve SKUs to WC product IDs ---
    // WC REST v3 accepts a comma-separated list in the ?sku= parameter.
    // We fetch in chunks of 100 to stay within per_page limits.

    // $skuMap: sku => ['id' => int, 'parent' => int, 'isvariant' => bool]
    $skuMap = [];
    $skuChunks = array_chunk(array_keys($pendingUpdates), 100);

    foreach ($skuChunks as $skuChunk) {
      $skuCsv = implode(',', $skuChunk);
      try {
        $products = $this->client->get('products', [
          'sku' => $skuCsv,
          'per_page' => 100,
        ]);
      } catch (\Throwable $e) {
        $this->logger->error(
          'WooCommerce SKU-Lookup-Chunk fehlgeschlagen: ' . $e->getMessage(),
          ['chunk_size' => count($skuChunk)]
        );
        continue;
      }
      if (!is_array($products)) {
        continue;
      }
      foreach ($products as $product) {
        if (!isset($product->sku)) {
          continue;
        }
        $skuMap[$product->sku] = [
          'id' => $product->id,
          'parent' => $product->parent_id ?? 0,
          'isvariant' => !empty($product->parent_id),
        ];
      }
    }

    // --- Step 3: Split into simple products and variations ---
    // simpleItems: list of batch-update items for POST products/batch
    // variationItems: parent_id => list of batch-update items for POST products/{parent}/variations/batch

    $simpleItems = [];
    $variationItems = [];

    foreach ($pendingUpdates as $sku => $params) {
      if (!isset($skuMap[$sku])) {
        $this->logger->error(
          "WooCommerce Artikel $sku wurde im Online-Shop nicht gefunden! Falsche Artikelnummer im Shop hinterlegt?"
        );
        continue;
      }

      $info = $skuMap[$sku];
      $item = [
        'id' => $info['id'],
        'manage_stock' => true,
        'stock_quantity' => $params['lageranzahl'],
        'status' => $params['status'],
      ];

      if ($info['isvariant']) {
        $variationItems[$info['parent']][] = $item;
      } else {
        $simpleItems[] = $item;
      }
    }

    // --- Step 4: Send batch updates in chunks of 100, handle partial errors ---

    // Simple products
    foreach (array_chunk($simpleItems, 100) as $chunk) {
      try {
        $response = $this->client->post('products/batch', ['update' => $chunk]);
        $anzahl += $this->processBatchResponse($response, 'products/batch');
      } catch (\Throwable $e) {
        $this->logger->error('WooCommerce Batch-Request fehlgeschlagen fuer products/batch: ' . $e->getMessage());
      }
    }

    // Variations (one batch endpoint per parent product)
    foreach ($variationItems as $parentId => $items) {
      foreach (array_chunk($items, 100) as $chunk) {
        $endpoint = 'products/' . $parentId . '/variations/batch';
        try {
          $response = $this->client->post($endpoint, ['update' => $chunk]);
          $anzahl += $this->processBatchResponse($response, $endpoint);
        } catch (\Throwable $e) {
          $this->logger->error('WooCommerce Batch-Request fehlgeschlagen fuer ' . $endpoint . ': ' . $e->getMessage());
        }
      }
    }

    return $anzahl;
  }

  /**
   * Evaluates a WC batch response object, logs per-item errors, and returns
   * the count of successfully updated items.
   *
   * @param object $response Decoded JSON response from the batch endpoint.
   * @param string $endpoint Endpoint label used in log messages.
   * @return int Number of items reported as updated without error.
   */
  private function processBatchResponse($response, $endpoint)
  {
    $successCount = 0;

    if (!is_object($response) && !is_array($response)) {
      $this->logger->error("WooCommerce Batch-Response ungueltig fuer $endpoint");
      return 0;
    }

    // Normalise object and array responses to a single array shape so that
    // the is_array() branch above is actually evaluated instead of silently
    // reporting zero updates.
    $payload = is_object($response) ? (array) $response : $response;

    // Successful updates are in response->update
    $updated = $payload['update'] ?? [];
    if (!is_iterable($updated)) {
      $updated = [];
    }
    foreach ($updated as $item) {
      $itemId = $item->id ?? '?';
      // WC embeds per-item errors inside the update array when an item fails
      if (isset($item->error)) {
        $code = $item->error->code ?? '';
        $message = $item->error->message ?? '';
        $this->logger->error(
          "WooCommerce Batch-Fehler ($endpoint) fuer ID {$itemId}: [$code] $message"
        );
      } else {
        $this->logger->info(
          "WooCommerce Lagerzahlenuebertragung (Batch) fuer Artikel-ID {$itemId} erfolgreich",
          ['endpoint' => $endpoint]
        );
        $successCount++;
      }
    }

    // Top-level errors array (some WC versions use this)
    $errors = $payload['errors'] ?? [];
    if (!is_iterable($errors)) {
      $errors = [];
    }
    foreach ($errors as $err) {
      $code = $err->code ?? '';
      $message = $err->message ?? '';
      $this->logger->error(
        "WooCommerce Batch-Fehler ($endpoint): [$code] $message"
      );
    }

    return $successCount;
  }

  public function ImportStorniereAuftrag()
  {
    $orderId = $this->CatchRemoteCommand('data')['auftrag'];
    if (!empty($orderId)) {
      $this->client->put('orders/' . $orderId, [
        'status' => 'cancelled',
      ]);
    } else {
      return 'failed';
    }
    return 'ok';
  }

  public function ImportSendList()
  {
    $tmp = $this->catchRemoteCommand('data');
    $anzahl = 0;
    $return = [];
    $ctmp = (!empty($tmp) ? count($tmp) : 0);
    $this->logger->debug("WooCommerce ImportSendList ".$ctmp." entries");

    // Prepare files
    $alle_dateien = array(); // pfad, dateiname
    if ($this->dateienuebertragen) {
        for ($i = 0; $i < $ctmp; $i++) {
            $dateien_artikel = array();
            if (isset($tmp[$i]['Dateien'])) {
                // apply naming
                foreach ($tmp[$i]['Dateien'] as $key => $datei) {
                    if (!empty($this->filenamesmarty)) {
                        $smarty = new Smarty;
                        $directory = $this->app->erp->GetTMP().'/smarty/templates';
                        $smarty->setCompileDir($directory);
                        $dateidata = $datei;
                        $dateidata['dateiname'] = pathinfo($dateidata['filename'] , PATHINFO_FILENAME);
                        $dateidata['endung'] = pathinfo($dateidata['filename'], PATHINFO_EXTENSION);
                        $dateidata['artikel'] = (object) $datei['artikeldata'];
                        $smarty->assign('artikel', (object) $tmp[$i]);
                        $smarty->assign('datei', (object) $dateidata);
                        $transformed = $smarty->fetch('string:'.$this->filenamesmarty);
                        if (!empty($transformed)) {
                            $basename = pathinfo($transformed, PATHINFO_FILENAME);
                            $ext = pathinfo($transformed, PATHINFO_EXTENSION);
                            if (empty($ext)) {
                                $ext = $datei['dateiendung'];
                            }
                            $datei['filename'] = $basename.".".$ext;
                        }
                    }
                    // Ensure unique names
                    if (in_array($datei['filename'],array_column($dateien_artikel,'filename'))) {
                        $filecount = 0;
                        $base = pathinfo($datei['filename'], PATHINFO_FILENAME);
                        $extension = pathinfo($datei['filename'], PATHINFO_EXTENSION);
                        do {
                            $filecount++;
                            $filename = $base.$this->fileunique.$filecount.'.'.$extension;
                        } while (in_array($filename,array_column($dateien_artikel,'filename')));
                        $datei['filename'] = $filename;
                    }
                    unset($datei['artikeldata']);
                    $dateien_artikel[] = $datei;
                }
                $alle_dateien = array_merge($alle_dateien,$dateien_artikel);
            }
        } // Artikel loop files

        // Each filename only once
        $unique_filename = array_unique(array_column($alle_dateien, 'filename'));
        $alle_dateien = array_intersect_key($alle_dateien, $unique_filename);
        $this->logger->debug("WooCommerce ImportSendList ".count($alle_dateien)." files", $alle_dateien);

        // Upload all the files and save wordpress id
        $uploaded_files = $this->uploadFiles($alle_dateien);
        if ($uploaded_files['success']) {
            $this->logger->debug("WooCommerce ImportSendList uploaded " .count($uploaded_files['files'])." files", $uploaded_files);
        } else {
            $this->logger->error("WooCommerce ImportSendList failed", $uploaded_files);
            throw new Exception("WooCommerce ImportSendList upload failed"); // For now, better to fail only the affected articles
        }
    }
    // Files

    for ($i = 0; $i < $ctmp; $i++) {
      $return[$i] = new ArticleExportResult();
      $return[$i]->success = true;
      $artikel = $tmp[$i]['artikel'];

        if (!empty($this->productsmarty)) {
            $smarty = new Smarty;
            $directory = $this->app->erp->GetTMP().'/smarty/templates';
            $smarty->setCompileDir($directory);
            $smarty->assign('artikel', (object) $tmp[$i]);
            $smarty_array = json_decode($this->productsmarty, true);
            if (empty($smarty_array)) {
                throw new Exception("Article smarty template JSON decode error! ".print_r(json_last_error_msg(),true));
            }
            $transformed = json_decode($smarty->fetch('string:'.$this->productsmarty),true);
            $tmp[$i] = array_merge((array) $tmp[$i], $transformed);
        }

      $return[$i]->articleId = intval($artikel);
      $nummer = $tmp[$i]['nummer'];
      if (!empty($tmp[$i]['artikelnummer_fremdnummern'][0]['nummer'])) {
        $nummer = $tmp[$i]['artikelnummer_fremdnummern'][0]['nummer'];
      }
      $laststock = $tmp[$i]['restmenge'];
      $inaktiv = $tmp[$i]['inaktiv'];
      $shippingtime = $tmp[$i]['lieferzeitmanuell'];

      $hersteller = $tmp[$i]['hersteller'];
      $herstellerlink = $tmp[$i]['herstellerlink'];

      $name_de = html_entity_decode($tmp[$i]['name_de']);
      $name_en = html_entity_decode($tmp[$i]['name_en']);

//        print_r($tmp[$i]); exit();

      $description = html_entity_decode($tmp[$i]['uebersicht_de']);
      $description_en = html_entity_decode($tmp[$i]['uebersicht_en']);
      $preis = $tmp[$i]['preis'];

      $kurzbeschreibung = $tmp[$i]['kurztext_de'];

      $weight_kg = $tmp[$i]['gewicht'];
      $dim_length = $tmp[$i]['laenge'];
      $dim_width = $tmp[$i]['breite'];
      $dim_height = $tmp[$i]['hoehe'];

      // Sanitize dimensions
      if (self::emptyString($weight_kg))
        $weight_kg = null;

      if (self::emptyString($dim_length))
        $dim_length = null;

      if (self::emptyString($dim_width))
        $dim_width = null;

      if (self::emptyString($dim_height))
        $dim_height = null;

      $pseudopreis = $tmp[$i]['pseudopreis'];// *1.19;
      if ($pseudopreis <= $preis)
        $pseudopreis = $preis;
      $steuersatz = $tmp[$i]['steuersatz'];
      if ($steuersatz > 1.10) {
        $steuersatz = 'normal';
      } else {
        $steuersatz = 'ermaessigt';
      }

      $lageranzahl = $tmp[$i]['anzahl_lager'];
      $pseudolager = trim($tmp[$i]['pseudolager']);

      if ($pseudolager > 0)
        $lageranzahl = $pseudolager;
      if ($tmp[$i]['ausverkauft'] == "1") {
        $lageranzahl = 0;
        $laststock = "1";
      }

      if ($inaktiv)
        $aktiv = 0;
      else
        $aktiv = 1;

      $product_id = 0;
      if ($laststock != "1")
        $laststock = 0;

      $remoteIdInformation = $this->getShopIdBySKU($nummer);
      $product_id = $remoteIdInformation['id'] ?? null;
      $parent_id = $remoteIdInformation['parent'] ?? null;
      $isVariant = $remoteIdInformation['isvariant'] ?? false;

      $commonMetaData = array();

        if (!empty($this->metadatasmarty)) {
            $smarty = new Smarty;
            $directory = $this->app->erp->GetTMP().'/smarty/templates';
            $smarty->setCompileDir($directory);
            $smarty->assign('artikel', (object) $tmp[$i]);
            $smarty_array = json_decode($this->metadatasmarty, true);
            if (empty($smarty_array)) {
                throw new Exception("Metadata smarty template JSON decode error! ".print_r(json_last_error_msg(),true));
            }
            $transformed = json_decode($smarty->fetch('string:'.$this->metadatasmarty),true);
            foreach ($transformed as $key => $value) {
                $commonMetaData[] = array('key' => $key, 'value' => $value);
            }
        }

      $productSync = new ProductSynchronizer($this->client);
      $productOptions = ['backorders' => $this->backorders];
      $productData = $tmp[$i];
      if ($this->exportDownloads && $this->downloadSettings !== null) {
        $productData = $this->downloadSettings->apply($productData);
      }
      if (!$this->exportDownloads) {
        unset($productData['downloadable'], $productData['downloads'], $productData['virtual']);
        foreach ($productData['artikel_varianten'] ?? [] as $key => $variant) {
          unset($productData['artikel_varianten'][$key]['downloadable'], $productData['artikel_varianten'][$key]['downloads'], $productData['artikel_varianten'][$key]['virtual']);
        }
        foreach ($productData['matrix_varianten']['artikel'] ?? [] as $key => $rows) {
          unset($productData['matrix_varianten']['artikel'][$key][0]['downloadable'], $productData['matrix_varianten']['artikel'][$key][0]['downloads'], $productData['matrix_varianten']['artikel'][$key][0]['virtual']);
        }
      }
      $commonProductAtts = $productSync->map($productData, $productOptions);
      $commonProductAtts['meta_data'] = $commonMetaData;

      if (isset($tmp[$i]['Dateien'])) {
        if (!empty($uploaded_files['files'])) {
            $attachments = array();

            foreach ($tmp[$i]['Dateien'] as $datei) {
                // find file in uploaded_files
                $key = array_search($datei['dateipfad'], array_column($uploaded_files['files'], 'dateipfad'));
                if ($key !== false) {
                    $uploaded_file = $uploaded_files['files'][$key];
                    if ($uploaded_file['type'] == 'shopbild') {
                        $commonProductAtts['images'][] = [
                            'id' => $uploaded_file['wordpressid']
                        ];
                    }
                    else if (in_array(strtolower($uploaded_file['type']),$this->dateienuebertragen)) {
                        $attachments[] = array('name' => $uploaded_file['name'], 'wp_media_id' => $uploaded_file['wordpressid'], 'typ' => $uploaded_file['type']);
                    }
                } else {
                    $return[$i]->message .= "Datei wurde nicht exportiert: ".$datei['filename'];
                }
            }

            array_multisort(
                array_column($attachments, 'name'),
                SORT_ASC,
                SORT_NATURAL,
                $attachments
            );

            if (!empty($attachments)) {
                $commonProductAtts['meta_data'][] = array('key' => 'openxe_product_attachments', 'value' => $attachments);
            }
        }
      }

      $productOptions['variationPayloads'] = (new VariationMedia())->upload(
        $productData,
        array_values(array_filter($this->dateienuebertragen)),
        function ($files) { return $this->uploadFiles($files); }
      );
      $productResult = $productSync->sync($productData, $commonProductAtts, $productOptions);
      $product_id = $productResult['id'];
      $this->logger->info("WooCommerce Artikel synchronisiert: $nummer / $product_id");

      if ($this->kategorienuebertragen && !$isVariant) {
        $categories = $tmp[$i]['kategorien'] ?? [];
        if ($this->categorySyncMode !== 'replace' && empty($categories) && !empty($tmp[$i]['kategoriename'])) {
          $categories = [['name' => $tmp[$i]['kategoriename']]];
        }
        $categorySync = new CategorySynchronizer($this->client, $this->shopid);
        $categoryIds = $categorySync->sync($categories, $tmp[$i]['kompletter_kategorienbaum'] ?? []);
        if ($categoryIds !== [] || $this->categorySyncMode === 'replace') {
          $categorySync->assign($product_id, $categoryIds, $this->categorySyncMode);
        }
      }
    }
    return $return;
    // return array($product_id,$anzahl,$nummer,$steuersatz, $preis);
  }

  /**
   * Checks the connection to the WooCommerce API by trying a simple API request
   *
   * @return string
   */
  public function ImportAuth()
  {
    try {
      $orders = $this->client->get('orders', ['per_page' => '1']);
    } catch (Exception $e) {
      return 'failed: Keine Verbindung zur API - ' . $e->getMessage();
    }

    if (!empty($this->ImportWordpressUserName)) {
        $result = $this->wordpress_request('users/me', debug: true, ssl_ignore: $this->ssl_ignore);
        if ($result['status'] != 1) {
            return 'failed: Verbindung zur Wordpress API '.$result['message'];
        }
    }
    return 'success';
  }

  /**
   * This is called by class.remote.php, initializes some class variables from the DB
   * @param  [type] $shopid [description]
   * @param  [type] $data   [description]
   * @return [type]         [description]
   * @throws HttpClientException
   */
  public function getKonfig($shopid, $data)
  {
    $this->shopid = $shopid;
    $this->data = $data;

    $preferences_json = $this->app->DB->Select("SELECT einstellungen_json FROM shopexport WHERE id = '$this->shopid' LIMIT 1");
    $preferences = [];
    if ($preferences_json) {
      $preferences = json_decode($preferences_json, true) ?? [];
    }

    $felder = $preferences['felder'] ?? [];

    $this->protokoll = $felder['protokoll'] ?? null;
    $this->ssl_ignore = $felder['ssl_ignore'] ?? false;
    $ImportWooCommerceApiSecret = $felder['ImportWoocommerceApiSecret'] ?? '';
    $ImportWooCommerceApiKey = $felder['ImportWoocommerceApiKey'] ?? '';
    $ImportWooCommerceApiUrl = $felder['ImportWoocommerceApiUrl'] ?? '';

    $this->ImportWordpressUserName = $felder['ImportWordpressUserName'];
    $this->ImportWordpressApplicationPassword = $felder['ImportWordpressApplicationPassword'];

    $this->filenamesmarty = $felder['filenamesmarty'];
    $this->productsmarty = $felder['productsmarty'];
    $this->metadatasmarty = $felder['metadatasmarty'];
    $this->fileunique = $felder['fileunique'];

    $shopexportArr =  $this->app->DB->SelectRow("SELECT * FROM shopexport WHERE id = '$this->shopid' LIMIT 1");

    $dateienuebertragen = $shopexportArr['dateienuebertragen'];
    $this->kategorienuebertragen = $shopexportArr['kategorienuebertragen'];

    $this->dateienuebertragen = explode(',',str_replace(' ','',strtolower($dateienuebertragen)));

    $this->statusPending = $felder['statusPending'] ?? 'pending';
    $this->statusProcessing = $felder['statusProcessing'] ?? 'processing';
    $this->statusCompleted = $felder['statusCompleted'] ?? 'completed';
    $this->timeout = (int) $felder['timeout'] ?? SELF::TIMEOUT;

    $this->priceType = $felder['priceType'] ?? null;
    $this->feeArticleNumber = trim((string) ($felder['feeArticleNumber'] ?? ''));
    $this->refundSync = !empty($felder['refundSync']);
    $this->categorySyncMode = ($felder['categorySyncMode'] ?? '') === 'replace' ? 'replace' : 'additive';
    $this->exportDownloads = !empty($felder['exportDownloads']);
    $this->downloadSettings = $this->exportDownloads
      ? new DownloadSettings($this->app->Container->get('Database'), (int) $this->shopid)
      : null;
    $this->importModifiedFrom = trim((string) ($felder['importModifiedFrom'] ?? ''));

    $this->backorders = $felder['backorders'];

    $this->url = $ImportWooCommerceApiUrl;
    $this->client = new ClientWrapper(
      //URL des WooCommerce Rest Servers
      $ImportWooCommerceApiUrl,
      //WooCommerce API Key
      $ImportWooCommerceApiKey,
      //WooCommerce API Secret
      $ImportWooCommerceApiSecret,
      ["query_string_auth" => true, 'timeout' => $this->timeout],
      $this->logger,
      $this->ssl_ignore
    );

    $storedTimestamp = $preferences['felder']['letzter_import_timestamp'] ?? null;
    if (!empty($storedTimestamp)) {
      $this->lastImportTimestamp = $storedTimestamp;
      $this->lastImportTimestampIsFallback = false;
    } else {
      $this->lastImportTimestamp = gmdate('Y-m-d\TH:i:s', strtotime('-30 days'));
      $this->lastImportTimestampIsFallback = true;
    }

    $storedIds = $preferences['felder']['letzter_import_order_ids'] ?? null;
    $this->lastImportOrderIds = is_array($storedIds)
      ? array_values(array_filter(array_map('intval', $storedIds)))
      : [];

  }

  /**
   * Backwards-compatible wrapper: persists timestamp only (order id cleared).
   * Use persistLastImportCursor() when both timestamp and order id are available.
   *
   * @param string $isoUtcDate ISO-8601 UTC timestamp, e.g. '2026-04-20T12:34:56'
   * @return void
   */
  private function getRefundRetryQueue()
  {
    return new RefundRetryQueue($this->app->Container->get('Database'), (int) $this->shopid);
  }

  private function drainRefundQueue($queue)
  {
    $result = $queue->drain(function ($orderId) {
      $database = $this->app->Container->get('Database');
      $shopimport = $this->app->erp->LoadModul('shopimport');
      $preflight = new RefundPreflight($database, (int) $this->shopid);
      $refunds = new RefundSynchronizer(
        $this->client,
        (int) $this->shopid,
        new RefundStateStore($database),
        function ($data) use ($shopimport) { return $shopimport->Refund((int) $this->shopid, $data); },
        [$preflight, 'validate']
      );
      $refunds->sync($this->client->get('orders/' . $orderId));
    });
    foreach ($result['failed'] as $orderId => $message) {
      $this->logger->error('WooCommerce refund deferred', ['shop' => $this->shopid, 'order' => $orderId, 'reason' => $message]);
    }
  }

  private function getOrderReconciler()
  {
    return new OrderReconciler(
      $this->client,
      new ImportStateStore($this->app->Container->get('Database'), (int) $this->shopid),
      null,
      $this->importModifiedFrom !== '' ? $this->importModifiedFrom : $this->lastImportTimestamp
    );
  }

  public function persistLastImportTimestamp($isoUtcDate)
  {
    $this->persistLastImportCursor($isoUtcDate, null);
  }

  /**
   * Persists the tuple cursor (timestamp + accumulated order-id bucket) to
   * shopexport.einstellungen_json. Does a read-modify-write to preserve all
   * other fields.
   *
   * Bucket logic:
   *  - $orderId === null  → migration path; ids list is cleared.
   *  - same timestamp as stored → append $orderId to the ids list.
   *  - new timestamp → reset ids list to [$orderId].
   *
   * @param string   $isoUtcDate ISO-8601 UTC timestamp, e.g. '2026-04-20T12:34:56'
   * @param int|null $orderId    WooCommerce order ID, or null (migration path)
   * @return void
   */
  public function persistLastImportCursor($isoUtcDate, $orderId = null)
  {
    $shopid = (int)$this->shopid;
    $einstellungen_json = $this->app->DB->Select(
      "SELECT einstellungen_json FROM shopexport WHERE id = '$shopid' LIMIT 1"
    );
    $current = [];
    if (!empty($einstellungen_json)) {
      $current = json_decode($einstellungen_json, true) ?: [];
    }
    if (!isset($current['felder']) || !is_array($current['felder'])) {
      $current['felder'] = [];
    }

    $previousTs  = $current['felder']['letzter_import_timestamp'] ?? null;
    $previousIds = $current['felder']['letzter_import_order_ids'] ?? [];
    if (!is_array($previousIds)) {
      $previousIds = [];
    }

    // Determine ids list for the new state.
    if ($orderId === null) {
      // Migration path — timestamp without a concrete order-id anchor.
      $newIds = [];
    } elseif ($previousTs !== null && $isoUtcDate === $previousTs) {
      // Same timestamp bucket — append id if not already present.
      $newIds = $previousIds;
      if (!in_array((int) $orderId, $newIds, true)) {
        $newIds[] = (int) $orderId;
      }
    } else {
      // New timestamp bucket — reset list to only this id.
      $newIds = [(int) $orderId];
    }

    $current['felder']['letzter_import_timestamp']  = $isoUtcDate;
    $current['felder']['letzter_import_order_ids']  = $newIds;

    $jsonEncoded = $this->app->DB->real_escape_string(json_encode($current));
    $this->app->DB->Update(
      "UPDATE shopexport SET einstellungen_json = '$jsonEncoded' WHERE id = '$shopid'"
    );
    $this->lastImportTimestamp = $isoUtcDate;
    $this->lastImportOrderIds  = $newIds;
  }

  /**
   * @param array $shopArr
   * @param array $postData
   *
   * @return array
   */
  public function updateShopexportArr($shopArr, $postData)
  {
    $shopArr['demomodus'] = 0;
    $shopArr['anzgleichzeitig'] = 1;
    $shopArr['cronjobaktiv'] = 1;
    $shopArr['positionsteuersaetzeerlauben'] = 1;

    return $shopArr;
  }

  /**
   * @return JsonResponse|null
   */
  public function AuthByAssistent()
  {
    $ImportWooCommerceApiKey = $this->app->Secure->GetPOST('ImportWoocommerceApiKey');
    $ImportWooCommerceApiSecret = $this->app->Secure->GetPOST('ImportWoocommerceApiSecret');
    $ImportWooCommerceApiUrl = $this->app->Secure->GetPOST('ImportWoocommerceApiUrl');

    if (empty($ImportWooCommerceApiUrl)) {
      return new JsonResponse(['error' => 'Bitte die API-Url angeben'], JsonResponse::HTTP_BAD_REQUEST);
    }
    if (empty($ImportWooCommerceApiKey)) {
      return new JsonResponse(['error' => 'Bitte den API-Key angeben'], JsonResponse::HTTP_BAD_REQUEST);
    }
    if (empty($ImportWooCommerceApiSecret)) {
      return new JsonResponse(['error' => 'Bitte das API-Secret angeben'], JsonResponse::HTTP_BAD_REQUEST);
    }
    $this->client = new ClientWrapper(
      $ImportWooCommerceApiUrl,
      $ImportWooCommerceApiKey,
      $ImportWooCommerceApiSecret,
      ['query_string_auth' => true],
      $this->logger,
      $this->ssl_ignore
    );
    $auth = $this->ImportAuth();

    if ($auth !== 'success') {
      return new JsonResponse(['error' => $auth], JsonResponse::HTTP_BAD_REQUEST);
    }

    return null;
  }

  /**
   * @return array[]
   */
  public function getCreateForm()
  {
    return [
      [
        'id' => 0,
        'name' => 'urls',
        'inputs' => [
          [
            'label' => 'API Url',
            'type' => 'text',
            'name' => 'ImportWoocommerceApiUrl',
            'validation' => true,
          ],

        ],
      ],
      [
        'id' => 1,
        'name' => 'username',
        'inputs' => [
          [
            'label' => 'API Key',
            'type' => 'text',
            'name' => 'ImportWoocommerceApiKey',
            'validation' => true,
          ],
        ],
      ],
      [
        'id' => 2,
        'name' => 'password',
        'inputs' => [
          [
            'label' => 'API Secret',
            'type' => 'password',
            'name' => 'ImportWoocommerceApiSecret',
            'validation' => true,
          ],
        ],
      ],
    ];
  }

  /**
   * Returns the WooCommerce Product Id of a product given the SKU (= Xentral arikelnummer)
   *
   * @param string $sku Artikelnummer
   *
   * @return array|null The WooCommerce product id of the given product, null if such a product does not exist
   * @throws HttpClientException
   */
  private function getShopIdBySKU($sku)
  {
    // Retrieve the product with the given sku.
    // Note: We limit the result set to 1 (per_page=1), so this doesnt work
    // if there are multiple products with the same sku. should not happen in practice anyway
    $product = $this->client->get('products', ['sku' => $sku, 'per_page' => 1]);

    // We look at the first product in the array.
    // We may get an empty array, in that case null is returned
    if (isset($product[0])) {
      return [
        'id' => $product[0]->id,
        'parent' => $product[0]->parent_id,
        'isvariant' => !empty($product[0]->parent_id)
      ];
    }

    return null;
  }

  private function getSKUByShopId($articleid, $variationid)
  {
    $product = $this->client->get("products/$articleid/variations/$variationid");

    // We look at the first product in the array.
    // We may get an empty array, in that case null is returned
    if (!empty($product))
      return $product->sku;
    return null;
  }

  public function EinstellungenStruktur()
  {
    return
      array(
        'ausblenden' => array('abholmodus' => array('zeitbereich')),
        'archiv' => array('ab_nummer'),
        'felder' => array(
        //          'protokoll'=>array('typ'=>'checkbox','bezeichnung'=>'Protokollierung im Logfile:'),
        'ssl_ignore' => array('typ' => 'checkbox', 'bezeichnung' => 'SSL-Prüfung abschalten:', 'info' => 'Nur für Testzwecke!'),
        'ImportWoocommerceApiKey' => array('typ' => 'text', 'bezeichnung' => '{|API Key:', 'size' => 60),
        'ImportWoocommerceApiSecret' => array('typ' => 'text', 'bezeichnung' => '{|API Secret|}:', 'size' => 60),
        'ImportWoocommerceApiUrl' => array('typ' => 'text', 'bezeichnung' => '{|API Url|}:', 'size' => 40),
        'ImportWordpressUserName' => array('typ' => 'text', 'bezeichnung' => '{|Wordpress Benutzername:', 'size' => 60, 'info' => 'Für Dateiuploads'),
        'ImportWordpressApplicationPassword' => array('typ' => 'text', 'bezeichnung' => '{|Wordpress Anwendungspasswort:', 'size' => 60, 'info' => 'Für Dateiuploads'),
        'statusPending' => array('typ' => 'text', 'bezeichnung' => '{|Statusname Bestellung offen|}:', 'size' => 40, 'default' => 'pending', 'info' => '({|ggfs. getrennt durch ";": pending;on-hold|})'),
        'statusProcessing' => array('typ' => 'text', 'bezeichnung' => '{|Statusname Bestellung in Bearbeitung|}:', 'size' => 10, 'default' => 'processing'),
        'statusCompleted' => array('typ' => 'text', 'bezeichnung' => '{|Statusname Bestellung fertig|}:', 'size' => 10, 'default' => 'completed'),
        'feeArticleNumber' => ['typ' => 'text', 'bezeichnung' => '{|ERP-Artikelnummer fuer WooCommerce-Gebuehren|}:', 'size' => 40],
        'refundSync' => ['typ' => 'checkbox', 'bezeichnung' => '{|WooCommerce-Erstattungen als Gutschriften uebernehmen|}:', 'info' => 'Erfordert vorhandene Rechnung und importierte Woo-Positions-IDs. Keine Zahlung oder Lagerbuchung im Shop.'],
        'categorySyncMode' => ['typ' => 'select', 'bezeichnung' => '{|Kategoriezuordnung|}:', 'optionen' => ['additive' => 'Ergaenzen', 'replace' => 'Durch OpenXE-Zuordnung ersetzen']],
        'exportDownloads' => ['typ' => 'checkbox', 'bezeichnung' => '{|Explizite WooCommerce-Downloadfelder exportieren|}:'],
        'importModifiedFrom' => ['typ' => 'text', 'bezeichnung' => '{|Erster Aenderungsabgleich ab UTC|}:', 'info' => 'Optional YYYY-MM-DDTHH:MM:SS; wirkt nur vor dem ersten Abgleich. Sonst bisheriger Importcursor.'],
        'priceType' => array('typ' => 'select', 'bezeichnung' => '{|Preisberechnungsgrundlage bei Auftragsimport|}', 'optionen' => array('netcalculated' => '{|Nettopreis zurückrechnen (Standard)|}', 'grosscalculated' => '{|Bruttopreis zurückrechnen|}')),
        'timeout' => array('typ' => 'text', 'bezeichnung' => '{|Timeout in Sekunden|}:', 'size' => 40, 'default' => '30', 'info' => ''),
        'backorders' => array('typ' => 'select', 'bezeichnung' => '{|Einstellungen für Backorders|}:', 'optionen' => $this->backorders_array, 'info' => ''),
        'productsmarty' => [
            'typ' => 'textarea',
            'cols' => 80,
            'rows' => 5,
            'bezeichnung' => '{|Artikeldaten Smarty-Template JSON|}:',
            'info' => 'Beispiel:<br>{<br />&nbsp; &nbsp; "name_en": "{$artikel-&gt;hersteller} {$artikel-&gt;name_en}"<br />}',
            'size' => 120,
        ],
        'metadatasmarty' => [
            'typ' => 'textarea',
            'cols' => 80,
            'rows' => 5,
            'bezeichnung' => '{|Metadaten Smarty-Template JSON|}:',
            'info' => 'Beispiel:<br>{<br />&nbsp; &nbsp; "openxe_meta": {<br />&nbsp; &nbsp; &nbsp; &nbsp; "mpn": "{$artikel-&gt;herstellernummer}",<br />&nbsp; &nbsp; &nbsp; &nbsp; "manufacturer": "{$artikel-&gt;hersteller}"<br />&nbsp; &nbsp; }<br />}',
            'size' => 120,
        ],
        'filenamesmarty' => [
            'typ' => 'textarea',
            'cols' => 80,
            'rows' => 5,
            'bezeichnung' => '{|Smarty-Template f&uuml;r Benennung der Dateien|}:',
            'info' => 'Beispiel:{$artikel->hersteller}-{$artikel->herstellernummer}-{$artikel->stichwort}-{$datei->titel}.{$datei->endung}<br>Dateifelder: dateiname, endung, mimetype, titel, beschreibung, id, version, stichwort, extid',
            'size' => 120,
        ],
        'fileunique' => array('typ' => 'text', 'bezeichnung' => '{|Trennzeichen für Mehrfachdateinamen|}:', 'size' => 11, 'default' => '-'),
        'dateienuebertragen' => array('typ' => 'info', 'bezeichnung' => '{|Datei&uuml;bertragung|}', 'info' => 'Dateien werden &uuml;ber Wordpress API hochgeladen und im Produkt in den Metadaten als Array "product_attachments" mit Präfix (s.o.) hinterlegt. Diese k&ouml;nnen dann z.B. mit einem Snippet angezeigt werden. Felder: "typ", "wp_media_id", "name". Der URL kann mit wp_get_attachment_url($wp_media_id) ermittelt werden.'),
        )
      );
  }

  /**
   * Compares two Objects and returns true if every variable in items
   * is the same in $a and $b
   * @param  Obj $a     [description]
   * @param  Obj $b     [description]
   * @param  array $items [description]
   * @return Bool        [description]
   */
  protected static function compareObjects($a, $b, $items)
  {
    foreach ($items as $v) {
      if (property_exists($a, $v) && property_exists($b, $v)) {
        if ($a->$v != $b->$v) {
          //print_r($v);
          return false;
        }
      }
    }
    return true;
  }

  /**
   * Returns true when the string entered is empty, after stripping whitespace
   * @param  String $string input
   * @return String         output
   */
  protected static function emptyString($string)
  {
    return (strlen(trim($string)) == 0);
  }

  function wordpress_request(string $endpoint, $postdata = null, $method = null, $getdata = null, string $content_disposition = null, string $content_type = null, $debug = false, $debugurl = null, bool $ssl_ignore = false, int $timeout_seconds = 30) {
    $baseUrl = rtrim((string) ($debugurl ?: $this->url), '/');
    $query = !empty($getdata) ? '?' . http_build_query($getdata, '', '&', PHP_QUERY_RFC3986) : '';
    $ch = curl_init($baseUrl . '/' . $this->wordpress_api_path . ltrim($endpoint, '/') . $query);
    $headers = [];
    $options = [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_USERPWD => (string) $this->ImportWordpressUserName . ':' . (string) $this->ImportWordpressApplicationPassword,
      CURLOPT_SSL_VERIFYPEER => !$ssl_ignore,
      CURLOPT_SSL_VERIFYHOST => $ssl_ignore ? 0 : 2,
      CURLOPT_CONNECTTIMEOUT => $timeout_seconds,
      CURLOPT_TIMEOUT => $timeout_seconds,
    ];
    if ($postdata !== null) {
      $options[CURLOPT_CUSTOMREQUEST] = $method ?: 'POST';
      $options[CURLOPT_POSTFIELDS] = $postdata;
      $headers[] = 'Content-Type: ' . ($content_type ?: 'application/octet-stream');
      if ($content_disposition !== null) {
        $headers[] = 'Content-Disposition: ' . $content_disposition;
      }
      $options[CURLOPT_HTTPHEADER] = $headers;
    }
    curl_setopt_array($ch, $options);
    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $information = curl_getinfo($ch);
    curl_close($ch);
    $response = $raw === false ? null : json_decode($raw, true);
    $result = [
      'status' => $error === '' && $information['http_code'] >= 200 && $information['http_code'] <= 299 && is_array($response),
      'response' => $response,
      'message' => $error !== '' ? $error : (is_array($response) ? ($response['message'] ?? '') : 'Invalid WordPress JSON response'),
      'http_code' => $information['http_code'],
    ];
    if ($debug) {
      unset($information['request_header']);
      $result['curl_getinfo'] = $information;
      $this->logger->debug('WooCommerce WordPress API request', $result);
    }
    return $result;
  }

      /*
     * Upload files to wordpress API
     * Returns success, files (list of files with url)
     */

    function uploadFiles($dateien) {

        $uploadFilesResult = array('success' => true, 'files' => array());

        foreach ($dateien as $datei) {

            $fileResult = [
                            'success' => true,
                            'name'=> $datei['filename'],
                            'type' => strtolower($datei['stichwort']),
                            'dateipfad' => $datei['dateipfad']
                        ];

            $postdata = file_get_contents($datei['dateipfad']);

            $result = $this->wordpress_request(
                    endpoint: 'media',
                    getdata: [
                        'search' => $datei['filename']
                    ],
                    debug: true,
                    ssl_ignore: $this->ssl_ignore
            );

            if ($result['status'] == 1) {
                $response = $result['response'];

                $file_wordpress_id = $response[0]['id'] ?? null;

                if (is_numeric($file_wordpress_id)) { // File exists -> update
                    $result = $this->wordpress_request(
                            endpoint: 'media/' . $file_wordpress_id,
                            postdata: $postdata,
                            method: 'POST',
                            content_disposition: 'attachment; filename="' . $datei['filename'] . '"',
                            content_type: $datei['mimetype'] ?: 'application:octet-stream',
                            debug: true,
                            ssl_ignore: $this->ssl_ignore
                    );

                    $response = $result['response'];

                    if ($result['status'] != 1 || $response['id'] != $file_wordpress_id) {
                        $uploadFilesResult['success'] = false;
                        $fileResult['success'] = false;
                        $fileResult['status'] = 'file update failed';
                    } else {
                        $fileResult['status'] = 'updated';
                        $fileResult['wordpressid'] = $response['id'];
                        $fileResult['url'] = $response['guid']['rendered'];
                    }
                } else { // New file
                    $result = $this->wordpress_request(
                            endpoint: 'media',
                            postdata: $postdata,
                            method: 'POST',
                            content_disposition: 'attachment; filename="' . $datei['filename'] . '"',
                            content_type: $datei['mimetype'] ?: 'application:octet-stream',
                            debug: true,
                            ssl_ignore: $this->ssl_ignore
                    );
                    $response = $result['response'] ?? [];
                    if (!empty($result['status']) && !empty($response['id'])) {
                        $fileResult['status'] = 'created';
                        $fileResult['wordpressid'] = $response['id'];
                        $fileResult['url'] = $response['guid']['rendered'];
                    } else {
                        $uploadFilesResult['success'] = false;
                        $fileResult['success'] = false;
                        $fileResult['status'] = 'file creation failed'.(!empty($result['http_code'])?(" http code".$result['http_code']):'');
                    }
                }
            } else {
                $uploadFilesResult['success'] = false;
                $fileResult['success'] = false;
                $fileResult['status'] = 'media search failed';
            }
            $uploadFilesResult['files'][] = $fileResult;
        }
        $this->logger->debug(
                'WooCommerce upload files',
                $uploadFilesResult
        );

        return($uploadFilesResult);
    }
}
