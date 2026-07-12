# TSH WhatsApp Notify

WordPress/WooCommerce plugin — professional WhatsApp notification system.

## Plugin location
`tsh-whatsapp-notify/` — deploy this folder to `wp-content/plugins/tsh-whatsapp-notify/` on any WordPress site.

## Stack
- PHP 8.1+, WordPress 6.3+, WooCommerce 7.0+
- PSR-4 autoloading (`TSH\WhatsAppNotify\` → `includes/`)
- No build tools required; no npm, no Webpack
- Composer optional (fallback autoloader included in main plugin file)

## Current phase: Phase 4 — Complete (Queue delivery engine + Order meta box)
All four phases are built. The plugin is feature-complete through Phase 4:
- Phase 1: Plugin skeleton, DB schema, admin UI scaffold
- Phase 2: WhatsApp Cloud API engine (ApiClient, MetaCloudProvider, ConnectionTester, HealthMonitor)
- Phase 3: WooCommerce order hooks → queue → send (Orders/, QueueProcessor, RetryEngine)
- Phase 4: Full delivery engine (DeadLetterQueue, WorkerLock, RateLimiter, DeliveryTracker, QueueStats, OrderMetaBox)

## User preferences
- Commercial-grade code only — no prototypes, no MVPs, no cut corners
- One class = one responsibility
- OOP throughout; no God classes
- WordPress Coding Standards + WooCommerce Extension Best Practices
- Every method must be complete — no TODOs, no stubs that need rebuilding later
- Do not begin Phase 2 automatically
