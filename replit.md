# TSH WhatsApp Notify

WordPress/WooCommerce plugin — professional WhatsApp notification system.

## Plugin location
`tsh-whatsapp-notify/` — deploy this folder to `wp-content/plugins/tsh-whatsapp-notify/` on any WordPress site.

## Stack
- PHP 8.1+, WordPress 6.3+, WooCommerce 7.0+
- PSR-4 autoloading (`TSH\WhatsAppNotify\` → `includes/`)
- No build tools required; no npm, no Webpack
- Composer optional (fallback autoloader included in main plugin file)

## Current phase: Phase 7 — Complete (Workflow Automation Engine)
All seven phases are built. The plugin is feature-complete through Phase 7:
- Phase 1: Plugin skeleton, DB schema, admin UI scaffold
- Phase 2: WhatsApp Cloud API engine (ApiClient, MetaCloudProvider, ConnectionTester, HealthMonitor)
- Phase 3: WooCommerce order hooks → queue → send (Orders/, QueueProcessor, RetryEngine)
- Phase 4: Full delivery engine (DeadLetterQueue, WorkerLock, RateLimiter, DeliveryTracker, QueueStats, OrderMetaBox)
- Phase 5: Meta Template Management (16 classes, 2 DB tables, full Templates page + modal)
- Phase 6: Inbox / Conversation Hub (17 classes, 2 DB tables, WhatsApp-style chat UI, webhook receiver)
- Phase 7: Workflow Automation Engine (16 classes, 3 DB tables, node-based visual builder, full AJAX layer)

## Phase 7 assets
- `tsh-whatsapp-notify/includes/Automation/` — 16 PHP classes
- `tsh-whatsapp-notify/assets/css/automation.css` — visual builder CSS (23 KB)
- `tsh-whatsapp-notify/assets/js/automation.js` — workflow builder JS (53 KB)
- `tsh-whatsapp-notify/templates/admin/automation.php` — admin page template
- DB tables: `tsh_wa_workflows`, `tsh_wa_workflow_runs`, `tsh_wa_workflow_logs`

## User preferences
- Commercial-grade code only — no prototypes, no MVPs, no cut corners
- One class = one responsibility
- OOP throughout; no God classes
- WordPress Coding Standards + WooCommerce Extension Best Practices
- Every method must be complete — no TODOs, no stubs that need rebuilding later
- Do not begin Phase 2 automatically
