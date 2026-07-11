---
name: TSH WA Phase 2 — API Layer
description: Everything built and modified in Phase 2 of the TSH WhatsApp Notify plugin; standing rules for Phase 3.
---

## Status: COMPLETE

## New classes (all in includes/API/)
- `ProviderInterface` — contract for all providers
- `Exceptions.php` — 6 exception classes (ApiException base + 5 subtypes)
- `TokenManager` — sole reader of the raw access token; never logs it
- `ResponseParser` — normalises wp_remote_request() results; classifies retryable errors
- `RequestLogger` — writes to tsh_wa_api_requests table
- `ApiClient` — core HTTP client with exponential back-off retry
- `MetaCloudProvider` — implements ProviderInterface against graph.facebook.com
- `ConnectionTester` — 4-step verify + real test-message send
- `HealthMonitor` — transient-cached (10 min) health status; registers cron hook

## New class (includes/Admin/)
- `Ajax` — 6 AJAX handlers; all use `check_ajax_referer(Ajax::NONCE_ACTION)` + capability check

## Modified files
- `Database/Installer.php` — DB_VERSION bumped to `2.0.0`; added `tsh_wa_api_requests` table
- `Admin/Settings.php` — API tab: `enable_api`, `test_phone_number`, `request_timeout`, `retry_attempts`, `retry_delay`; api_version default → v23.0; `render_token_field()` added
- `Admin/Dashboard.php` — imports HealthMonitor; `api_health` passed to template
- `Admin/Pages/Tools.php` — `clear_api_requests` + `bust_health_cache` tools; sandbox vars injected
- `Admin/Menu.php` — nonce uses `Ajax::NONCE_ACTION`; full i18n strings for Phase 2 JS
- `Bootstrap/Loader.php` — registers Ajax + HealthMonitor; `maybe_upgrade_db()` on `plugins_loaded`
- `Bootstrap/Activator.php` — api_settings defaults include all Phase 2 fields
- `uninstall.php` — drops tsh_wa_api_requests; deletes tsh_wa_api_health_history; clears transient
- `templates/admin/dashboard.php` — WhatsApp Cloud status panel with health data + Refresh button
- `templates/admin/settings.php` — full rewrite; Connection Tester + Export/Reset on API tab
- `templates/admin/tools.php` — full rewrite; Diagnostics panel + Message Sandbox panel
- `assets/js/admin.js` — full rewrite with Phase 2 AJAX modules
- `assets/css/admin.css` — appended Phase 2 styles

## Key decisions
- `ApiClient` is the only class that calls wp_remote_request(). Never call Meta directly outside it.
- `TokenManager` is the only place the raw token is read. Never log or echo it.
- Health status cached in transient `tsh_wa_api_health_status` (10 min TTL) — dashboard never makes a live API call on page load.
- AJAX nonce action: `Ajax::NONCE_ACTION = 'tsh_wa_admin_nonce'` — must match Menu.php localization.
- DB auto-upgrade runs on `plugins_loaded` (priority 5) via `Loader::maybe_upgrade_db()` — idempotent, safe on every request.
- api_version default: `v23.0` (changed from v19.0).

**Why:** All HTTP through ApiClient ensures consistent retry, logging, and token injection. HealthMonitor caching avoids live API calls on every admin page load (latency + quota).

## Standing rules for Phase 3
- Do NOT start Phase 3 (WooCommerce order events, queue processing, customer notifications) automatically.
- Phase 3 must be explicitly requested.
