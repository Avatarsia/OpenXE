# WooCommerce connector extensions

Branch: `feature/woocommerce-functional-parity`, based on `refactor/woocommerce-composer-sdk` at `bc46b52b`.

## Data ownership and behavior

- WooCommerce supplies orders, their final discounted line amounts, explicit line tax rates, shipping amounts, currency and source line IDs. Coupon codes remain in the saved cart; their amounts are not deducted a second time.
- OpenXE supplies product content, prices, inventory and explicitly selected category assignments. Existing ERP orders are not rewritten automatically after import.
- Modification reconciliation replaces the creation-date-only feed. It catches old orders becoming eligible after a later payment/status change. Existing import-status settings still determine which new orders enter the ERP.
- A separate per-shop journal in the existing `konfiguration` table records progress. The initial lower bound is the existing import cursor (including its existing 30-day fallback), or the optional explicit UTC start date. Existing legacy `ab_nummer` migration remains supported. Changing the optional start date after journal creation does not reset progress.
- A MySQL connection-scoped delivery lock spans source selection, the legacy caller's cart storage and acknowledgement. The cursor advances only after the cart or ERP order is found for the same shop/source ID. A failed delivery becomes eligible for recovery after 120 seconds once its previous connection has released the lock. Named locks require a stable database connection through acknowledgement.
- Reconciliation uses timestamp-scoped keyset queries, not shifting page offsets. Each call processes at most 1000 already-known/ineligible events before yielding; later calls continue from durable progress.
- Status feedback preserves the legacy first tracking number/carrier and adds all packages in `openxe_shipments`. Successful replays avoid duplicate tracking notes. A timeout between the non-atomic Woo note POST and metadata PUT can still duplicate a note.
- Refund processing uses a separate persistent retry queue, so unsupported or temporarily unavailable refunds cannot block later orders. Missing invoices and source prerequisites are checked before a booking intent is recorded. Retries use exponential backoff and remain discoverable even when the Woo modification feed is empty.
- No public webhook receiver is introduced. Regular reconciliation remains the delivery mechanism.

## Shop settings

- **ERP-Artikelnummer fuer WooCommerce-Gebuehren** (`feeArticleNumber`): existing ERP article number used for fee positions, including negative fees. An order containing fees fails explicitly when this mapping is empty.
- **WooCommerce-Erstattungen als Gutschriften uebernehmen** (`refundSync`): disabled by default. This is WooCommerce to OpenXE only: no gateway refund, shop restock or automatic invoice creation. An existing invoice and imported source line IDs are required. See [refund behavior and recovery](woocommerce-refunds.md).
- **Kategoriezuordnung** (`categorySyncMode`): `additive` preserves existing shop assignments; `replace` explicitly makes the exported OpenXE category list authoritative, including an empty list.
- **Explizite WooCommerce-Downloadfelder exportieren** (`exportDownloads`): disabled by default for parent products and variants. When enabled, explicitly supplied `downloadable`, `virtual` and `downloads` fields are mapped. Public document attachments are never inferred to be paid downloads. The WooCommerce shop administration now provides per-article download names and HTTP/HTTPS file URLs. Articles without a UI override retain their existing Smarty JSON transformation; download file URLs must be provisioned appropriately on the shop.
- **Erster Aenderungsabgleich ab UTC** (`importModifiedFrom`): optional `YYYY-MM-DDTHH:MM:SS` used only before the first modification journal is created.

Existing shop flags for categories, variants, properties, pictures and file types still control the source export. Newly implemented capabilities are enabled in the capability matrix and source filtering.

## Product identity

Product/variant identity continues to use the configured SKU (foreign number when present, otherwise the ERP article number). Matrix options become selectable WooCommerce attributes; ordinary variants without distinct options use an article-number option. Remote variation/category lists are paginated. Existing category names are adopted only when unambiguous under the same parent. Categories use shop/source-specific slugs for persistent identity; adopting an existing category can change its slug, so review existing category URLs in staging.

Variant image data is adapted from the actual remote matrix export and cleaned up after upload. The existing WordPress application-password/media upload mechanism and Smarty product/file configuration remain in use. The WordPress HTTP adapter now honors SSL verification settings, handles empty media searches and uses the actual POST response when a new image is created.

## SDK audit

`automattic/woocommerce` is locked to **3.1.1**, the latest stable version found on 2026-10-21. Its PHP requirements and existing wrapper interfaces are compatible with this project. No dependency or vendor changes were needed. Sources: [release 3.1.1](https://github.com/woocommerce/wc-api-php/releases/tag/3.1.1), [Packagist](https://packagist.org/packages/automattic/woocommerce).

## Verification

Run `php tests/woocommerce/run.php`. The suite uses real connector/services with transport/database boundary doubles, plus a local PHP HTTP fixture for actual cURL requests. Tests cover mapping, tax rates, mixed discounts, delivery fencing, crash/restart, pagination changes, source identities, refunds, parent/variant export, category hierarchy, uploads, downloads and existing Smarty transformations.

The tracked checkout has no installed PHPUnit runner or pre-existing tests. The regression runner introduces no dependencies. PHP syntax checks are required for every modified/new PHP file. No live ERP, database or WooCommerce was changed during development; database/HPOS and actual shop-extension compatibility still require a staging run before deployment.

Verified on 2026-10-21 with PHP 8.5.1: 110/110 regression tests across nine suites; 25/25 PHP syntax checks passed. Independent review findings were fixed and confirmed. PHP 8.1/actual shop/HPOS execution is not claimed by these local results.

## WooCommerce administration

Open a WooCommerce shop, then choose **WooCommerce** in its menu. Both views reuse the existing shop-edit permission and enforce shop/article project rights; every write requires a persisted session CSRF token.

- **Downloads:** search assigned articles by number/name; edit downloadable/virtual and up to 50 name/URL rows. Only absolute HTTP/HTTPS URLs without embedded credentials are accepted; no file is uploaded or fetched by this form. Saving creates a shop/article override; disabling downloadable sends an empty download list while keeping the rows available for later editing. **Smarty-Vorgabe verwenden** removes the override. Save/reset invalidates the article and variant-parent export hash for this shop, so the existing automatic article export can detect the change. The global exportDownloads setting remains required.
- **Refund-Status:** paginated queue/journal state, Woo order/refund IDs, credit-note ID, attempts, sanitized last error and UTC attempt times. **Erneut versuchen** advances only the existing retry schedule; it never clears pending financial markers, creates gateway refunds or triggers restocking. Uncertain pending states require manual recovery.

Local rendering uses the real AdminView with original OpenXE styles and synthetic fixtures; it does not establish live ERP/shop integration.
