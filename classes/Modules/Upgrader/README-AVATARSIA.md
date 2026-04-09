# Upgrader UI (Avatarsia Fork)

This document describes the modernized upgrader UI shipped in the
Avatarsia OpenXE fork. It is a drop-in replacement for the upstream
upgrader page that keeps the upstream upgrade engine untouched but
gives the operator a richer browser-based workflow.

## Architecture Overview

The Avatarsia upgrader is a thin UI layer on top of the upstream
upgrade engine. The engine itself (`upgrade/data/upgrade.php` and
`vendor/mustal/mustal_mysql_upgrade_tool.php`) is intentionally
left at upstream HEAD; only the page controller and the Smarty
template are extended. The new UI calls the existing
`upgrade_main()` function with named arguments, which keeps the
fork binary compatible with upstream backend bug fixes.

## What is New Compared to Upstream

- Responsive CSS layout with status banner, stepper and embedded
  action cards
- OpenXE logging integration via `app->erp->LogFile()` instead of
  pure echo to stdout, so upgrade events end up in the standard
  application log channel `upgrade`
- Lock-status display via `upgrade/data/.in_progress.flag`. The
  page reads the flag (and decoded JSON metadata about user and
  timestamp) so multiple admins can see whether someone else is
  currently running an upgrade
- Rollback via git tags: before every upgrade run the controller
  creates a `pre-upgrade-YYYY-MM-DD-HH-MM-SS` tag in the local
  working tree and stores it in the session. A cleanup pass keeps
  only the 10 newest pre-upgrade tags. The rollback action is
  exposed in the UI and validates the supplied tag against a
  strict `^pre-upgrade-\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2}$`
  pattern
- Live AJAX polling: `?action=list&ajax=get_log_status` returns
  the latest 100 log lines plus lock metadata as JSON, so the
  browser can stream upgrade output without a full page reload
- Log download: `?action=list&ajax=download_log` streams the raw
  upgrade log as `upgrade_log_YYYY-MM-DD_HH-MM.txt`
- Local vs upgrade-source hash comparison: the page runs
  `git ls-remote` against the configured upgrade source and shows
  whether the local checkout is aligned with the configured
  remote branch
- One-click reset to upgrade source and configurable remote
  host/branch via `upgrade/data/remote.json`

## File Layout

The fork-specific files of this module are:

- `www/pages/upgrade.php` (627 lines)
  Page controller. Includes the upstream engine via
  `include("../upgrade/data/upgrade.php")` and dispatches the
  AJAX endpoints, the rollback action and the upgrade actions.
- `www/pages/content/upgrade.tpl` (235 lines)
  Smarty template for the upgrader page. Defines the layout and
  the JavaScript that polls the AJAX endpoint.
- `upgrader-ui-changelog.md`
  Short changelog kept inside the source branch for traceability.

The following files are part of the upstream engine and are NOT
overridden by this module:

- `upgrade/data/upgrade.php`
- `upgrade/data/db_schema.json`
- `vendor/mustal/mustal_mysql_upgrade_tool.php`

## Non-Standard: No Bootstrap.php

Other Avatarsia modules ship a `Bootstrap.php` that registers
services in the DI container. The upgrader is deliberately a pure
page feature: it has no domain services, no repositories and no
DTO layer. The integration point is `www/pages/upgrade.php`,
which is loaded by the upstream page router as soon as the file
exists. There is therefore nothing to bootstrap.

The upgrader also does not use `DatabaseService` or named
prepared statements. All side effects go through `git`,
`shell_exec()` and the existing upgrade engine. The page does
read `$this->app->erp->LogFile()` and `$this->app->Tpl->Set()`,
which is the standard OpenXE controller surface.

## Rollback Mechanism

Rollback is implemented as plain git tags, not as database
snapshots:

1. Before each upgrade action the controller runs
   `git -C <git_root> tag pre-upgrade-<timestamp>` and writes
   the tag name into `$_SESSION['last_rollback_tag']`.
2. The tag creation is done via `exec()` so the exit code can
   be checked. Failures are written to `app->erp->LogFile()`.
3. After successful tag creation the controller lists all
   `pre-upgrade-*` tags sorted by `creatordate` and removes
   everything beyond the 10 newest entries.
4. The UI exposes the recent tags. When the operator triggers
   a rollback the supplied tag is matched against
   `^pre-upgrade-\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2}$` to make
   sure no arbitrary git ref can be checked out.
5. Database state is NOT rolled back; only the working tree is
   reverted. Operators must restore database backups manually
   if a schema migration ran during the upgrade.

## Conflict Risk

Medium. Upstream actively maintains both `upgrade/data/upgrade.php`
and `www/pages/upgrade.php`. The engine file is left untouched, so
upstream changes there merge cleanly. The page controller and the
template are diverged heavily; expect manual conflict resolution
when pulling upstream changes that touch the upgrader page.

## License

See the OpenXE main license. This module follows the upstream
project license and copyright headers.
