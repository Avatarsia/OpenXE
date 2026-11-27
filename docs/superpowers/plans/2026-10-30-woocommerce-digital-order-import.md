# WooCommerce digital order recovery

**Goal:** Recover order 9429 by its WooCommerce ID, import later status changes once, and preserve completed orders and their download access.

**Approved scope:** The user approved the diagnosed creation-date cursor fix and targeted recovery, then requested the Opus review follow-up, inclusion in the build manifest and starting the build. Commits and pushes to the fix and CI control branches are authorized. No live deployment or release is requested.

**Architecture:** Build on the existing modification feed in `OrderReconciler`. Add a WooCommerce-specific single-order command behind the existing single-import form. Hold the existing per-shop state and delivery locks across duplicate detection, source lookup and durable cart storage. Manual recovery never saves or acknowledges the modification checkpoint. Preserve the current completed status on acknowledgement. A new opt-in setting includes completed orders without changing existing shops' status selection automatically.

**Constraints:** PHP 8.1, no dependencies/schema/credential changes, preserve existing mixed line endings, leave other importers unchanged. The live installation remains at 6dc91a56 until separately deployed.

## Tasks

- [x] Run baseline: `php tests/woocommerce/run.php` (146 passed, 0 failed).
- [x] Add failing connector and form regression tests for direct ID lookup, old creation dates, unchanged checkpoints, duplicates, invalid IDs/statuses, failed storage and concurrent deliveries.
- [x] Add `Shopimporter_Woocommerce::ImportSingleOrder()` and route only WooCommerce through it from `Shopimport::importSingleOrder()`. Reuse `create_shopimport_cart_entry()` and verify durable storage before success. Never update the cursor during manual recovery.
- [x] Allow the WooCommerce single-import form in status mode, validate a session CSRF token before writes, and link it from the existing WooCommerce administration. Keep the legacy path for other shops.
- [x] Add the completed-import opt-in and preserve native/configured completed statuses in both automatic and manual feedback. Test an old completed order becoming eligible after newer imports, plus replay without duplicates.
- [x] Document the setting, manual queue/freigabe flow and deployment boundary.
- [x] Run the whole WooCommerce suite, any available root suite, PHP lint for every changed PHP file and `git diff --check`. Perform one final review, fix findings with tests, report exact verification and the remaining live deployment/import.

## Review focus

- Manual recovery and cron must not enqueue the same shop/order concurrently.
- Manual success, duplicate and failure paths must leave the automatic checkpoint unchanged and release locks.
- A terminal completed status must survive feedback, including source changes between selection and acknowledgement.
- Invalid IDs, cancelled/refunded source states and invalid CSRF tokens must cause no writes.
- Existing shops retain their configured status filters until completed-import is explicitly enabled; unrelated importers retain their legacy behavior.

## Execution record

- Base: feature/woocommerce-functional-parity, 5c5603bc. It already implements modification reconciliation; no second feed will be introduced.
- Baseline: 13 suites / 146 tests passed on PHP 8.5.1.
- Ruling: manual recovery always queues for explicit approval, matching the existing single-import workflow; it does not automatically create/release an ERP order.
- One read-only review confirmed a strict-mode storage defect in the reused cart writer (`trash` is NOT NULL without DEFAULT). Added a failing SQL-boundary test, supplied `trash=0`, then reran the suite. No second review.
- Final: `php tests/woocommerce/run.php`: 14 suites / 168 tests passed on PHP 8.5.1 and on PHP 8.1.34 in the existing pinned `php:8.1-cli-bookworm` Docker image with no external network access.
- Final: PHP lint passed for all six changed/new PHP files; `git diff --check` passed. The root PHPUnit binary and Unit/Integration directories referenced by phpunit.xml are absent in this checkout; the complete available WooCommerce suite was run.
- Boundaries: no live WooCommerce/database/race test, no commit/push/deploy, no live import of 9429. Existing connection-lock behavior is covered by the state suite; new recovery is tested against database/HTTP boundary doubles and the real cart writer.
- Requested second review: Opus 5.5, medium; no must-fix. Its should-fix (missing failure logging) was reproduced with a failing test, then fixed with sanitized shop/order/error logging. The GET/PUT race and legacy API status-filter semantics are documented; no status bypass was introduced.
- Post-review verification: the complete suite still passes 168/168 on PHP 8.1.34 and 8.5.1, all six PHP lint checks pass and `git diff --check` is clean. Integration will list the fix after `feature/woocommerce-functional-parity` with an explicit dependency.
