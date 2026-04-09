# Ticket Portal Module (Avatarsia Fork)

Customer-facing ticket portal for OpenXE. Two-part feature:

1. **WordPress plugin** - hosted on the customer-facing website,
   renders a WhatsApp-style chat UI with ticket status, message
   history, offer acceptance and media downloads.
2. **OpenXE backend endpoints** - new `ticket_portal_*` actions on
   `www/pages/ticket.php` plus three templates, exposing a small HTTP
   API consumed by the WP plugin.

This module is maintained in the Avatarsia fork and not part of
upstream OpenXE.

## Architecture

```
+-----------------------------+         +------------------------------+
|   Customer (browser)        |  HTTPS  |   WordPress site             |
|   PLZ + email or magic link | <-----> |   openxe-ticket-portal       |
+-----------------------------+         |   (WP plugin, this dir)      |
                                        +--------------+---------------+
                                                       |
                                                       | shared secret
                                                       | over HTTPS
                                                       v
                                        +------------------------------+
                                        |   OpenXE backend             |
                                        |   index.php?module=ticket    |
                                        |   &action=ticket_portal_*    |
                                        +--------------+---------------+
                                                       |
                                                       v
                                        +------------------------------+
                                        |   MariaDB / MySQL            |
                                        |   ticket_portal_access       |
                                        |   ticket_portal_message      |
                                        |   ticket_customer_status     |
                                        |   ticket_status_log          |
                                        |   ticket_offer_confirmation  |
                                        |   ticket_notification_pref   |
                                        +------------------------------+
```

The WP plugin never talks to the OpenXE database directly. All access
goes through the `ticket_portal_*` HTTP endpoints, authenticated with
a shared secret per request and a per-customer session token.

## File layout

```
wp-plugin/openxe-ticket-portal/
  openxe-ticket-portal.php          plugin bootstrap
  README.md                          end-user / install README
  README-AVATARSIA.md                <-- this file
  install/
    schema.sql                       OpenXE-side DB schema (run once)
  assets/
    portal.css
    portal.js
  includes/
    class-ajax-handlers.php
    class-remote-api.php             HTTP client to OpenXE
    class-settings.php
    class-shortcode.php
    class-theme-manager.php
    class-updater.php
    functions-utility.php
    views/
      settings-page.php

www/pages/
  ticket.php                         core touch: ~2553 added lines,
                                     ~50 portal* helpers and 20+
                                     ticket_portal_* actions
  content/
    ticket_edit.tpl                  modified: portal buttons
    ticket_nachricht.tpl             modified: chat-bubble layout
    ticket_portal_print.tpl          new
    ticket_portal_settings.tpl       new
    ticket_portal_staff.tpl          new

doc/
  ticket-portal-README.md
  ticket-portal-concept.md
  ticket-portal-setup.md
  ticket-portal-api-reference.md
  ticket-portal-user-guide.md
```

## Installation

### OpenXE backend

1. Apply the database schema once:
   ```bash
   mysql -u openxe -p openxe < wp-plugin/openxe-ticket-portal/install/schema.sql
   ```
   The required tables are NOT created by `Ticket::Install()` (the
   method is intentionally empty in upstream). The schema script uses
   `CREATE TABLE IF NOT EXISTS`, so it is safe to re-run.

2. Configure shared secret, mail templates and customer-status mapping
   under **Einstellungen -> Ticket Portal** (rendered via
   `content/ticket_portal_settings.tpl`).

3. Make sure the OpenXE host is reachable from the WordPress site over
   HTTPS. Endpoints used by the WP plugin live at:
   ```
   POST  index.php?module=ticket&action=ticket_portal_session
   POST  index.php?module=ticket&action=ticket_portal_token
   POST  index.php?module=ticket&action=ticket_portal_magic
   GET   index.php?module=ticket&action=ticket_portal_status
   GET   index.php?module=ticket&action=ticket_portal_messages
   POST  index.php?module=ticket&action=ticket_portal_message
   GET   index.php?module=ticket&action=ticket_portal_offers
   POST  index.php?module=ticket&action=ticket_portal_offer
   POST  index.php?module=ticket&action=ticket_portal_offer_confirm
   GET   index.php?module=ticket&action=ticket_portal_media
   GET   index.php?module=ticket&action=ticket_portal_media_download
   GET   index.php?module=ticket&action=ticket_portal_notifications
   POST  index.php?module=ticket&action=ticket_portal_notification
   GET   index.php?module=ticket&action=ticket_portal_print
   ```
   See `doc/ticket-portal-api-reference.md` for full details.

### WordPress plugin

1. From the OpenXE admin, download the plugin ZIP via
   **Ticket Portal -> Download WordPress Plugin** (route
   `ticket_portal_plugin_download`), or zip the directory manually:
   ```bash
   cd wp-plugin
   zip -r openxe-ticket-portal.zip openxe-ticket-portal/
   ```
2. In WordPress: **Plugins -> Add New -> Upload Plugin**, upload the
   ZIP, activate.
3. Configure under **Settings -> OpenXE Ticket Portal**:
   - OpenXE base URL
   - Shared secret (must match the OpenXE-side setting)
4. Place the shortcode `[openxe_ticket_portal]` on a WordPress page.

## Usage

Customers visit the WordPress page, enter their ticket number plus
PLZ+email (or follow a magic-link from a status notification mail),
and see ticket status, messages, offers and downloadable media. They
can reply with messages and accept or reject offers (with
double-opt-in for binding offers).

Internally, every accepted offer is mirrored back into the OpenXE
ticket as a `verfasser='Portal'` outgoing message and writes to
`ticket_offer_confirmation`.

## Upstream path

- The WordPress plugin under `wp-plugin/openxe-ticket-portal/` could
  be split into a separate repo at any time. It has no compile-time
  dependency on the OpenXE PHP source; it only talks HTTP.
- The OpenXE-side `ticket_portal_*` actions currently live as a core
  touch inside `www/pages/ticket.php`. A future refactor should move
  them into either:
  - a dedicated page module, e.g. `www/pages/ticketportal.php`, or
  - a `TicketPortalService` under `classes/Modules/TicketPortal/`.
  Until that refactor lands, every upstream merge of `ticket.php`
  will require a manual conflict resolution.

## License

Same as OpenXE (AGPL-3.0).
