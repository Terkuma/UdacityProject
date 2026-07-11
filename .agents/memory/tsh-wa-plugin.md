---
name: TSH WhatsApp Notify — Plugin Foundation
description: Phase 1 architecture decisions, namespace, structure, and conventions for the TSH WA plugin.
---

## Plugin location
`tsh-whatsapp-notify/` at repo root — deploy this entire folder to `wp-content/plugins/`.

## Namespace
`TSH\WhatsAppNotify\` → `includes/` (PSR-4, fallback autoloader in main plugin file; Composer vendor/ not yet present).

## Constants
- `TSH_WA_VERSION`, `TSH_WA_PATH`, `TSH_WA_URL`, `TSH_WA_BASENAME`, `TSH_WA_LOG_DIR`

## DB tables (prefix + tsh_wa_*)
- `logs` — level / source / message / context / order_id / phone
- `queue` — phone / message / status / priority / attempts / scheduled_at
- `templates` — name / slug / type / trigger_event / message_body / variables
- `settings` — option_key / option_value (key-value store, future use)

`Database\Installer::DB_VERSION = '1.0.0'` — increment on any schema change.

## Settings option keys (wp_options)
- `tsh_wa_general_settings`, `tsh_wa_api_settings`
- `tsh_wa_admin_notification_settings`, `tsh_wa_customer_notification_settings`
- `tsh_wa_template_settings`, `tsh_wa_queue_settings`
- `tsh_wa_logging_settings`, `tsh_wa_advanced_settings`

## Admin menu slugs
Parent: `tsh-whatsapp-notify` → sub-pages: `-orders`, `-templates`, `-queue`, `-logs`, `-settings`, `-tools`, `-about`

## Phase boundary
Phase 1 = foundation only. Queue::process(), API calls, order hooks, template CRUD — all deferred to Phase 2+.
Cron events are registered and fire; handlers emit `do_action('tsh_wa_cron_*')` for Phase 2 listeners to hook.

**Why:** keeps Phase 1 deployable and testable without API credentials, while every hook point is already in place.
