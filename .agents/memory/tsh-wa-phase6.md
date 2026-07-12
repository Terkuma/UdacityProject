---
name: TSH WA Phase 6 — Inbox / Conversation Hub
description: Architecture decisions and durable constraints for Phase 6 (two-way WhatsApp inbox). Complete as of July 2026.
---

## What Phase 6 adds
- Two new DB tables: `tsh_wa_conversations` (UNIQUE KEY on `phone`) and `tsh_wa_messages`.
- DB_VERSION bumped to `6.0.0` in `Database/Installer.php`.
- REST webhook endpoint: `/wp-json/tsh-wa/v1/webhook` (GET = Meta challenge, POST = events).
- Media stored in WP uploads at `tsh-wa-inbox/YYYY/MM/type/`; protected by `.htaccess deny from all`.
- 17 AJAX actions registered in `Admin/Ajax.php` — all prefixed `tsh_wa_inbox_`.
- `InboxManager` registered in `Bootstrap/Loader.php` → `register_hooks()` runs for all request types (not admin-only) so the webhook receiver is always active.
- `Admin/Menu.php` adds `SLUG_INBOX = 'tsh-whatsapp-notify-inbox'` submenu.
- `Bootstrap/Activator.php` seeds `tsh_wa_inbox_settings` option on activation.
- `uninstall.php` drops both Phase 6 tables, deletes options/transients, recursively removes `tsh-wa-inbox/` upload directory, and clears Phase 6 cron hooks.
- `templates/admin/inbox.php` — WhatsApp-style two-panel chat UI with customer sidebar and analytics stats row; localises `tshWaInbox` JS object.
- `assets/css/admin.css` — Phase 6 styles appended (≈ 650 lines after existing 2493).
- `assets/js/admin.js` — Phase 6 `TshWaAdmin.inbox` module appended (≈ 500 lines after existing 1619).

## Key decisions

**Outgoing replies go through the queue** (`Queue::add()`), not a direct API call — inherits all Phase 4 retry/rate-limit/delivery-tracking logic automatically.

**Why:** Avoids a parallel send path; ensures all delivery events are tracked in `tsh_wa_delivery_events` and visible in the Queue dashboard.

**How to apply:** Any new "send from inbox" feature must call `InboxManager::send_reply()`, which delegates to `Queue::add()`.

---

**Webhook returns HTTP 200 even on invalid signatures** — logs the failure but does not return 4xx.

**Why:** Meta retries on non-200 responses, creating retry storms when e.g. the `app_secret` is temporarily misconfigured. Failing silently with a log is safer for production.

**How to apply:** `WebhookReceiver::handle_post()` always returns 200; signature validation errors are written to the plugin logger with source prefix `inbox`.

---

**Signature validation uses `app_secret` field** added to `tsh_wa_api_settings`. The `webhook_verify_token` (for GET challenge) already existed in Phase 1.

---

**Async webhook processing** is opt-in via `tsh_wa_inbox_settings['async_webhook']` (default `'0'`). Default is synchronous for reliability.

---

**`tsh_wa_conversations` UNIQUE KEY on `phone`** — one conversation row per phone number; `ConversationRepository::upsert_conversation()` uses `INSERT … ON DUPLICATE KEY UPDATE`.

---

## Files (all new in Phase 6)

`includes/Inbox/` — 17 PHP classes  
`includes/Admin/Pages/Inbox.php` — page controller  
`templates/admin/inbox.php` — HTML template  

## Files modified in Phase 6

`Database/Installer.php`, `Bootstrap/Loader.php`, `Admin/Menu.php`, `Admin/Ajax.php`, `Bootstrap/Activator.php`, `uninstall.php`, `assets/css/admin.css`, `assets/js/admin.js`
