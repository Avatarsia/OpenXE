# WooCommerce Functional Parity Implementation Plan

> **For agentic workers:** Use test-driven implementation and independent review. Do not commit or push without the user's later approval.

**Goal:** Extend the existing WooCommerce connector without losing existing stock, media, metadata or import functionality.

**Architecture:** Keep the SDK transport and legacy connector entry points. Put new synchronization and product logic in focused PHP services where useful. Reuse durable OpenXE import records and the existing Refund service; retries must not create duplicate orders or credit notes.

**Tech Stack:** PHP compatible with the existing project, WooCommerce REST v3, existing Automattic Composer SDK, prepared database queries.

**Spec:** Approved conversation: currency, fees/coupons, reliable import, multi-package tracking, refunds, category hierarchy, attributes and variant creation. Download mapping is an explicit opt-in extension. Webhooks remain optional if reliable reconciliation works without an additional public endpoint.

## Global Constraints

- Base: refactor/woocommerce-composer-sdk at bc46b52b; preserve the existing pagination and batch stock stack.
- No commit, push, production changes, live financial operations or credential output.
- Preserve existing line endings and limit edits to the requested behavior.
- Run php -l on every changed PHP file; use standalone PHP tests because this checkout contains no PHPUnit installation or tracked test suite.
- SDK dependency updates only when the delegated audit establishes a need.
- Newly introduced database queries use named parameters.

## Review Focus

- Replayed/failed imports must not skip or duplicate orders.
- Mixed tax rates, discounts, zero-quantity and negative fee lines must preserve Woo totals.
- Multiple shipments, empty shipment lists and repeated status notifications remain safe.
- Category names reused under different parents and more than 100 remote records remain distinguishable.
- Partial refunds must preserve quantities, amounts and source IDs and must never trigger a second gateway payment.

### Task 1: Import mapping and shipment feedback

**Files:** www/pages/shopimporter_woocommerce.php (parseOrder, parseItem, ImportUpdateAuftrag), tests/woocommerce/orders.php.
- [x] Add failing fixture tests for currency, net/gross discounted lines, fees, all coupon codes, line IDs, multiple/empty shipments and replayed feedback.
- [x] Correct mapping and preserve the existing order contract; test explicit tax handling through the ERP mapping.
- [x] Run the order regression tests and lint changed files.

### Task 2: Durable import reconciliation

**Files:** WooCommerce connector, relevant import persistence boundary, focused synchronization service, tests/woocommerce/sync.php.
- [x] Add failing tests for an older order entering an importable status later, same-second peers, failed storage, restart and shop isolation.
- [x] Reconcile modifications with stable source identity; advance only after durable storage, preserve legacy cursor migration.
- [x] Verify retry and duplicate handling without changing unrelated connectors.

### Task 3: Refund synchronization

**Files:** focused WooCommerce refund service, connector wiring, tests/woocommerce/refunds.php.
- [x] Test partial/full refunds, line IDs, duplicate refund IDs, failed processing and unsupported source rows.
- [x] Connect Woo refunds to Shopimport::Refund with durable per-shop/refund idempotency and explicit one-way financial behavior.
- [x] Preserve source refund identity on the credit note and retry failures without marking them successful.

### Task 4: Product capabilities

**Files:** focused product/category services, connector export wiring, tests/woocommerce/products.php.
- [x] Test hierarchy, category pagination, stable identity, attribute mapping and variant upsert with existing export-shaped fixtures.
- [x] Implement parent-first categories, attributes and variable parent/variant creation without removing existing media/SEO/stock support.
- [x] Test opt-in virtual/downloadable products separately from public document attachments.

### Task 5: Verification and handback

- [x] Run all standalone regression tests and lint every changed PHP file.
- [x] Check whitespace and line-ending preservation against the branch base.
- [x] Obtain and resolve independent review findings.
- [x] Document configuration, migration behavior, SDK audit and remaining live-test limitations; do not commit or push.

## Verification progress

- SDK audit: installed/latest 3.1.1, no update required.
- Initial independent review found three Important issues (refund/feed coupling, preflight after pending, empty replace-category fallback). All have dedicated regression coverage and are fixed. The reviewer confirmed all three ADDRESSED and independently ran five relevant regressions successfully.
- No commits or pushes were made. Live staging remains a deployment prerequisite.

## Final evidence

- `php tests/woocommerce/run.php`: 110/110 tests, 9 suites, zero failures on PHP 8.5.1.
- PHP lint: 25/25 changed/new PHP files passed.
- Added/changed lines and new files: zero trailing whitespace; original CRLF preserved in existing files.
- `composer.json` / `composer.lock`: unchanged.
- Independent final review: three findings addressed; no blocking residual findings in the targeted completion check.
- No live/staging execution, commits, pushes or manifest changes. Refunds without supported position quantities and uncertain booking interruptions require the documented manual handling.

## Authorized UI and production integration follow-up

- [x] Add shop/article Download form with validated URLs, explicit disable and Smarty fallback.
- [x] Add paginated Refund status, sanitized errors and scheduling-only retry with CSRF and project rights.
- [x] Verify UI desktop/mobile and controller/export regressions; independent review.
- [x] Prepare CI manifest and PHP 8.1 suite, then present concrete commit/push approval request per AGENTS.

## UI follow-up verification (supersedes earlier counts)

- 146/146 WooCommerce tests in13 suites pass on PHP8.5.1 and pinned Docker php:8.1-cli-bookworm.
- 35/35 changed/new PHP files lint successfully; JavaScript syntax and whitespace checks pass.
- Original OpenXE styles, real AdminView, synthetic data: desktop1440 and mobile390 inspected; mobile document width390, inner scrollable download table560. Add/remove1->2->1; reset bypasses invalid-URL validation while save retains validation.
- Independent UI review resolved; independent code review export-hash finding addressed and independently verified (18 download tests,6 controller tests). No residual targeted findings.
- CI worktree manifest/dependency/workflow prepared;18/18 CI tests pass. Feature follows SDK; production override remains last. Validator awaits publication of new branch.
- git fetch --all completed; origin/production6dc91a56. Tracked patch applies cleanly against production index; no new-file collisions.
- No commits, pushes, workflow dispatch or live ERP/shop test performed. Commit/push approval remains required by user AGENTS.md.
