# Upgrader UI (Avatarsia Fork)

This document describes the modernized upgrader UI shipped in the
Avatarsia OpenXE fork. It is a drop-in replacement for the upstream
upgrader page that keeps the upstream upgrade engine untouched but
gives the operator a richer browser-based workflow.

## Architecture Overview

The Avatarsia upgrader is a modernized UI and diagnostics layer on top of the
upstream upgrade engine. The page controller (`www/pages/upgrade.php`) calls the
existing `upgrade_main()` function with named arguments and adds structured log
parsing, CSRF/admin protection, rollback tags, and detached-HEAD recovery. Two
targeted patches extend the upstream engine files (`upgrade/data/upgrade.php`
for post-upgrade schema diff logging and `vendor/mustal/mustal_mysql_upgrade_tool.php`
for default-value normalization) so remaining schema differences are surfaced
accurately in the UI.

## What is New Compared to Upstream

- Responsive CSS layout (Light & Dark Mode) with status banner, structured
  **Diagnose & Details** card, clarified action cards (**Komplett-Upgrade** vs.
  **Nur Datenbank**), and loading-state spinner / double-submit guard
- Structured Diagnostics Panel: parses SQL errors, remaining post-upgrade
  schema differences, and modified git files from the upgrade log and renders
  them in tables/lists directly below the status banner (with stale-log hint
  when the displayed log is older than the current state)
- Post-upgrade schema diff logging (`upgrade/data/upgrade.php`) and MUSTAL
  default-value normalization fix (`vendor/mustal/mustal_mysql_upgrade_tool.php`)
- Security hardening: CSRF token validation and admin-only authorization guard
  on POST actions as well as the log download endpoint
- Detached-HEAD recovery before upgrades and confirmation dialogs for both
  rollback and force mode (`-f`)
- Enhanced XSS-safe log viewer: quick-filter chips (`Alle`, `Fehler & Warnungen`,
  `DB-Differenzen`) with live match counts, substring search, syntax highlighting
  via DOM nodes (no `innerHTML`), one-click copy, and expand/collapse height toggle
- Rollback via git tags: before every upgrade run the controller
  creates a `pre-upgrade-YYYY-MM-DD-HH-MM-SS` tag in the local
  working tree and stores it in the session. A cleanup pass keeps
  only the 10 newest pre-upgrade tags. The rollback action is
  exposed in the UI and validates the supplied tag against a
  strict `^pre-upgrade-\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2}$`
  pattern. The same path runs for `do_db_upgrade` so a code-level
  rollback point exists even when only the DB action was triggered
- Log download: `?action=list&ajax=download_log` streams the raw
  upgrade log as `upgrade_log_YYYY-MM-DD_HH-MM.txt`. The endpoint
  refuses unauthenticated callers with HTTP 403 — only admin users
  pass the guard
- Local vs upgrade-source hash comparison: the page runs
  `git ls-remote` against the configured upgrade source and shows
  whether the local checkout is aligned with the configured
  remote branch
- Branch selection (upstream `branchupgrade` feature) rendered as a
  radio list inside the card layout. `upgrade/data/remote.json` is
  the upstream list format (`name`/`enabled`/`active`/`host`/
  `branch`/`check`/`migration`); the first entry with `active: true`
  is the upgrade source shown in the comparison card. Selecting a
  different branch and starting the upgrade runs the upstream
  migration path (`do_migrate` / `-m`). The former free-text remote
  editor was dropped because it wrote the pre-1.13 object format

## File Layout

The fork-specific files of this module are:

- `www/pages/upgrade.php`
  Page controller. Includes the engine via
  `include("../upgrade/data/upgrade.php")` and dispatches the
  AJAX endpoint, CSRF/admin checks, diagnostics parsing, detached-HEAD
  recovery, rollback action, and upgrade actions.
- `www/pages/content/upgrade.tpl`
  Smarty template for the upgrader page (including CSRF hidden field,
  diagnostics card, clarified step cards, and log toolbar). References the
  external CSS and JS assets below.
- `www/css/upgrade.css`
  Page styles (Light & Dark Mode, diagnostics tables, loading spinner, log
  toolbar and syntax highlighting). Follows the OpenXE convention for global
  pages (other pages link `./css/<page>.css`).
- `www/js/upgrade.js`
  Page scripts (submit guard, `-f`/rollback confirmations, loading state,
  XSS-safe log filtering/highlighting, copy & expand controls).
- `upgrader-ui-changelog.md`
  Short changelog kept inside the source branch for traceability.

The following upstream engine files carry minimal targeted fork patches:

- `upgrade/data/upgrade.php` (post-upgrade schema diff logging)
- `vendor/mustal/mustal_mysql_upgrade_tool.php` (default-value comparison fix)

`upgrade/data/db_schema.json` remains untouched upstream.

## Non-Standard: No Bootstrap.php

Other Avatarsia modules ship a `Bootstrap.php` that registers
services in the DI container. The upgrader is deliberately a pure
page feature: it has no domain services, no repositories and no
DTO layer. The integration point is `www/pages/upgrade.php`,
which is loaded by the upstream page router as soon as the file
exists. There is therefore nothing to bootstrap.

The upgrader also does not use `DatabaseService` or named
prepared statements. All side effects go through `git`,
`shell_exec()` and the existing upgrade engine. The page only
relies on the standard OpenXE controller surface
(`$this->app->Tpl->Set()`, `$this->app->Secure->Get…()`,
`$this->app->User->GetID()` / `GetType()`).

## Authorization

The page itself is reached through the regular OpenXE module
router and inherits its login check. In addition the controller
guards both POST upgrade/rollback actions and the AJAX download
endpoint via `isUpgraderAuthorized()` (requiring a logged-in
`admin` user) and validates a session-bound CSRF token
(`[CSRF_TOKEN]`) on all state-changing POST requests. Non-admin
callers get HTTP 403 before any action runs or log content is
emitted.

## Rollback Mechanism

Rollback is implemented as plain git tags, not as database
snapshots:

1. Before each upgrade action (`do_upgrade` or `do_db_upgrade`)
   the controller calls `createRollbackTag()`, which runs
   `git -C <git_root> tag pre-upgrade-<timestamp>` via `exec()`
   so the exit code can be checked. On success the tag name is
   written into `$_SESSION['last_rollback_tag']`.
2. After successful tag creation the controller lists all
   `pre-upgrade-*` tags sorted by `creatordate` and removes
   everything beyond the 10 newest entries.
3. The UI exposes the recent tags. When the operator triggers
   a rollback the supplied tag is matched against
   `^pre-upgrade-\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2}$` to make
   sure no arbitrary git ref can be checked out.
4. Database state is NOT rolled back; only the working tree is
   reverted. Operators must restore database backups manually
   if a schema migration ran during the upgrade. A tag is still
   created for the DB-only action so a subsequent manual
   `git reset --hard <tag>` can pin the code back to the state
   before the DB upgrade.

## Conflict Risk

Medium. Upstream actively maintains both `upgrade/data/upgrade.php`
and `www/pages/upgrade.php`. The engine file is left untouched, so
upstream changes there merge cleanly. The page controller and the
template are diverged heavily; expect manual conflict resolution
when pulling upstream changes that touch the upgrader page.

## License

See the OpenXE main license. This module follows the upstream
project license and copyright headers.
