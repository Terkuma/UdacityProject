# TSH WhatsApp Notify

WordPress/WooCommerce plugin — professional WhatsApp notification system.

## Plugin location
`tsh-whatsapp-notify/` — deploy this folder to `wp-content/plugins/tsh-whatsapp-notify/` on any WordPress site.

## Stack
- PHP 8.1+, WordPress 6.3+, WooCommerce 7.0+
- PSR-4 autoloading (`TSH\WhatsAppNotify\` → `includes/`)
- No build tools required; no npm, no Webpack
- Composer optional (fallback autoloader included in main plugin file)

## Current phase: Phase 1 — Foundation
Plugin architecture only. No API calls, no message sending — those come in Phase 2.

## User preferences
- Commercial-grade code only — no prototypes, no MVPs, no cut corners
- One class = one responsibility
- OOP throughout; no God classes
- WordPress Coding Standards + WooCommerce Extension Best Practices
- Every method must be complete — no TODOs, no stubs that need rebuilding later
- Do not begin Phase 2 automatically
