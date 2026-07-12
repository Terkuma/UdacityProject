# TSH WhatsApp Notify — Complete Project Reference

> **Purpose of this document:** A comprehensive, session-persistent reference for any agent or developer picking up this project. Read this before touching any code. It covers architecture, every phase built, every file, every decision, and the exact state of the codebase as of the end of Phase 2.

---

## Table of Contents

1. [Project Overview](#1-project-overview)
2. [Requirements & Constraints](#2-requirements--constraints)
3. [Repository Structure](#3-repository-structure)
4. [Plugin Bootstrap & Constants](#4-plugin-bootstrap--constants)
5. [Database Schema](#5-database-schema)
6. [WordPress Options (wp_options keys)](#6-wordpress-options-wp_options-keys)
7. [Transients & Cron Hooks](#7-transients--cron-hooks)
8. [Class Reference — every class, its responsibility, public API](#8-class-reference)
9. [Admin Menu & Pages](#9-admin-menu--pages)
10. [Assets (CSS / JS)](#10-assets-css--js)
11. [Phase 1 — What Was Built](#11-phase-1--what-was-built)
12. [Phase 2 — What Was Built](#12-phase-2--what-was-built)
13. [Phase 3 — What Comes Next (NOT yet started)](#13-phase-3--what-comes-next-not-yet-started)
14. [Standing Rules — Must Follow in Every Phase](#14-standing-rules--must-follow-in-every-phase)
15. [Key Architectural Decisions & Why](#15-key-architectural-decisions--why)
16. [Security Model](#16-security-model)
17. [Development Toolchain](#17-development-toolchain)

---

## 1. Project Overview

**Plugin name:** TSH WhatsApp Notify  
**Plugin file:** `tsh-whatsapp-notify/tsh-whatsapp-notify.php`  
**Text domain:** `tsh-whatsapp-notify`  
**Plugin version constant:** `TSH_WA_VERSION = '1.0.0'`  
**Namespace root:** `TSH\WhatsAppNotify\`  
**PSR-4 base directory:** `includes/`  
**License:** GPL-2.0-or-later  

**What it does:** A commercial-grade WordPress/WooCommerce plugin that sends automated WhatsApp messages to customers via the Meta WhatsApp Cloud API. When a WooCommerce order changes status, a message (using a configurable template) is dispatched through a reliable outbound queue.

**Target platform:**
- WordPress 6.3+
- WooCommerce 7.0+ (tested to 9.0)
- PHP 8.1+
- MySQL 5.7+ / MariaDB 10.3+

---

## 2. Requirements & Constraints

- **One class = one file = one responsibility.** No God classes.
- **OOP throughout.** No procedural PHP outside the main plugin file.
- **ABSPATH guard** on every PHP file (first line after `<?php`).
- **Nonce + capability check** on every form action and AJAX handler.
- **Token never in logs, never in AJAX responses, never in transients.** Only `TokenManager` reads the raw access token.
- **All HTTP to Meta goes through `ApiClient`.** Never call `wp_remote_request()` directly against the Meta API from anywhere else.
- **Everything communicates through `ProviderInterface`.** Future providers (Twilio, 360dialog) drop in without touching business logic.
- **WordPress Coding Standards** + **WooCommerce Extension Best Practices** throughout.
- **No Composer `vendor/` directory checked in** — the autoloader falls back to a custom `spl_autoload_register` in the main plugin file. `composer.json` is present for dev tooling (`phpcs`, `phpunit`).
- **Do not start Phase 3 automatically.** Phase 3 must be explicitly requested.

---

## 3. Repository Structure

```
tsh-whatsapp-notify/
├── tsh-whatsapp-notify.php          # Plugin entry point; constants; autoloader; hooks
├── uninstall.php                    # Data removal on plugin delete (opt-in only)
├── composer.json                    # Dev tooling (PHPCS, PHPUnit) — no vendor/ committed
├── readme.txt                       # WordPress.org readme format
│
├── includes/
│   ├── index.php                    # Directory silence file
│   │
│   ├── API/                         # Phase 2 — WhatsApp Cloud API engine
│   │   ├── index.php
│   │   ├── ProviderInterface.php    # Contract all providers must implement
│   │   ├── Exceptions.php           # ApiException + 5 subtypes
│   │   ├── TokenManager.php         # Sole reader of the raw access token
│   │   ├── ResponseParser.php       # Normalises wp_remote_request() results
│   │   ├── RequestLogger.php        # Writes to tsh_wa_api_requests table
│   │   ├── ApiClient.php            # Core HTTP client with retry back-off
│   │   ├── MetaCloudProvider.php    # Implements ProviderInterface for Meta
│   │   ├── ConnectionTester.php     # 4-step connection verify + test send
│   │   └── HealthMonitor.php        # Transient-cached health status
│   │
│   ├── Admin/                       # Admin UI components
│   │   ├── index.php
│   │   ├── Ajax.php                 # Phase 2 — 6 AJAX handlers
│   │   ├── Dashboard.php            # Dashboard data aggregator
│   │   ├── Menu.php                 # Admin menu registration + asset enqueue
│   │   ├── Settings.php             # Settings registration + sanitisation
│   │   └── Pages/
│   │       ├── index.php
│   │       ├── About.php
│   │       ├── Logs.php
│   │       ├── Orders.php
│   │       ├── Queue.php
│   │       ├── Templates.php
│   │       └── Tools.php
│   │
│   ├── Bootstrap/
│   │   ├── index.php
│   │   ├── Activator.php            # Activation routine (tables, defaults, version stamps)
│   │   ├── Deactivator.php          # Deactivation routine (clear cron, flush rewrites)
│   │   └── Loader.php               # Plugin bootstrap singleton; registers all components
│   │
│   ├── Cron/
│   │   ├── index.php
│   │   └── Scheduler.php            # WP-Cron event registration + handlers
│   │
│   ├── Database/
│   │   ├── index.php
│   │   └── Installer.php            # dbDelta table creation; DB_VERSION = '2.0.0'
│   │
│   ├── Helpers/
│   │   ├── index.php
│   │   └── Helpers.php              # Static utility methods (phone, template, currency)
│   │
│   ├── Logger/
│   │   ├── index.php
│   │   └── Logger.php               # Central logging service (DB + optional file)
│   │
│   └── Queue/
│       ├── index.php
│       └── Queue.php                # Outbound message queue data-access layer
│
├── templates/
│   ├── index.php
│   └── admin/
│       ├── index.php
│       ├── about.php
│       ├── dashboard.php            # Phase 2: WhatsApp Cloud health panel added
│       ├── logs.php
│       ├── orders.php
│       ├── queue.php
│       ├── settings.php             # Phase 2: full rewrite with Connection Tester
│       ├── templates.php
│       └── tools.php                # Phase 2: full rewrite with Diagnostics + Sandbox
│
├── assets/
│   ├── index.php
│   ├── css/
│   │   ├── index.php
│   │   └── admin.css                # Phase 2: health panel, diagnostics, sandbox styles appended
│   ├── js/
│   │   ├── index.php
│   │   └── admin.js                 # Phase 2: full rewrite with all AJAX modules
│   ├── icons/index.php
│   └── images/index.php
│
├── languages/
│   └── index.php
│
└── logs/
    ├── index.php
    └── .htaccess                    # deny from all — blocks direct HTTP access to log files
```

---

## 4. Plugin Bootstrap & Constants

### Constants (defined in `tsh-whatsapp-notify.php`)

| Constant | Value / Description |
|---|---|
| `TSH_WA_VERSION` | `'1.0.0'` — plugin version string |
| `TSH_WA_PATH` | Absolute filesystem path to the plugin root (trailing slash) |
| `TSH_WA_URL` | Public URL to the plugin root (trailing slash) |
| `TSH_WA_BASENAME` | `plugin_basename(__FILE__)` — used for plugin action links |
| `TSH_WA_LOG_DIR` | `TSH_WA_PATH . 'logs' . DIRECTORY_SEPARATOR` |

### Boot sequence

```
plugins_loaded (priority 10)
  └─ Loader::instance()
       ├─ check_requirements()          checks WooCommerce is active
       ├─ load_text_domain()
       ├─ register_components()
       │    ├─ new Scheduler()           registers cron intervals + events on 'init'
       │    ├─ new HealthMonitor()       registers tsh_wa_cron_health_check action
       │    ├─ new HealthMonitor()->register_hooks()
       │    └─ if is_admin():
       │         ├─ new Menu()           registers admin menu + enqueues assets
       │         └─ new Ajax()           registers all AJAX wp_ajax_* hooks
       └─ register_hooks()
            ├─ before_woocommerce_init  HPOS compatibility declaration
            ├─ plugin_action_links_*    Settings link on plugins screen
            └─ plugins_loaded (prio 5)  maybe_upgrade_db() — auto-migrate schema
```

### Autoloader

Composer `vendor/autoload.php` is used if present. Otherwise a fallback `spl_autoload_register` maps `TSH\WhatsAppNotify\Foo\Bar` → `includes/Foo/Bar.php`.

### Activation / Deactivation

| Hook | Class | What it does |
|---|---|---|
| `register_activation_hook` | `Activator::activate()` | Checks PHP/WC versions; creates directories; runs `Installer::run()`; seeds default settings; stores version stamps |
| `register_deactivation_hook` | `Deactivator::deactivate()` | Clears all cron events; flushes rewrite rules. **Does NOT delete data.** |
| Uninstall (`uninstall.php`) | — | Drops all tables; deletes all options and transients; clears cron. Only runs when user has enabled "Remove data on uninstall" in Advanced settings. |

---

## 5. Database Schema

`Installer::DB_VERSION = '2.0.0'`

All tables use the `$wpdb->prefix` prefix. `dbDelta()` is used — safe to run repeatedly.

### `{prefix}tsh_wa_logs`

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED PK AUTO_INCREMENT | |
| `level` | VARCHAR(20) | `success\|info\|warning\|error\|debug` |
| `source` | VARCHAR(100) | Originating class/hook identifier |
| `message` | TEXT | Human-readable message |
| `context` | LONGTEXT NULL | JSON-encoded contextual data |
| `order_id` | BIGINT UNSIGNED NULL | WooCommerce order ID |
| `phone` | VARCHAR(30) NULL | Recipient phone number |
| `created_at` | DATETIME | DEFAULT CURRENT_TIMESTAMP |

Indexes: `level`, `source`, `order_id`, `created_at`

### `{prefix}tsh_wa_queue`

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED PK | |
| `phone` | VARCHAR(30) | E.164 format |
| `message` | LONGTEXT | Message body |
| `template_id` | BIGINT UNSIGNED NULL | FK to templates table |
| `order_id` | BIGINT UNSIGNED NULL | FK to WC orders |
| `status` | VARCHAR(20) | `pending\|processing\|sent\|failed\|cancelled` |
| `priority` | TINYINT UNSIGNED | 1 (high) – 10 (low) |
| `attempts` | TINYINT UNSIGNED | Send attempts made |
| `max_attempts` | TINYINT UNSIGNED | Default 3 |
| `scheduled_at` | DATETIME | When to send |
| `processed_at` | DATETIME NULL | When last processed |
| `error_message` | TEXT NULL | Last error detail |
| `meta` | LONGTEXT NULL | JSON extra data |
| `created_at` | DATETIME | |
| `updated_at` | DATETIME | ON UPDATE CURRENT_TIMESTAMP |

Indexes: `status`, `phone`, `order_id`, `scheduled_at`, `priority`

### `{prefix}tsh_wa_templates`

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED PK | |
| `name` | VARCHAR(200) | Display name |
| `slug` | VARCHAR(200) UNIQUE | Machine identifier |
| `type` | VARCHAR(50) | `custom\|order\|system` |
| `trigger_event` | VARCHAR(100) NULL | WC hook or custom event name |
| `language` | VARCHAR(10) | Default `en` |
| `message_body` | LONGTEXT | Template body with `{{variable}}` placeholders |
| `variables` | LONGTEXT NULL | JSON array of supported variable names |
| `status` | VARCHAR(20) | `active\|inactive\|draft` |
| `meta` | LONGTEXT NULL | JSON extra metadata |
| `created_at` | DATETIME | |
| `updated_at` | DATETIME | ON UPDATE CURRENT_TIMESTAMP |

Indexes: `status`, `type`, `trigger_event`; UNIQUE: `slug`

### `{prefix}tsh_wa_settings`

Key/value store for future fine-grained per-option settings. Not used by current phases.

| Column | Type |
|---|---|
| `id` | BIGINT UNSIGNED PK |
| `option_key` | VARCHAR(200) UNIQUE |
| `option_value` | LONGTEXT NULL |
| `autoload` | VARCHAR(3) DEFAULT 'yes' |
| `created_at` / `updated_at` | DATETIME |

### `{prefix}tsh_wa_api_requests` *(Phase 2)*

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED PK | |
| `endpoint` | VARCHAR(500) | Relative path e.g. `123456/messages` |
| `method` | VARCHAR(10) | HTTP verb, default `POST` |
| `latency_ms` | DECIMAL(10,3) | Round-trip milliseconds |
| `http_status` | SMALLINT UNSIGNED | HTTP response code |
| `success` | TINYINT(1) | 0/1 |
| `retry_count` | TINYINT UNSIGNED | Retries before success/failure |
| `error_code` | VARCHAR(100) NULL | Meta error code |
| `response_size` | INT UNSIGNED | Byte length of response body |
| `response_body` | LONGTEXT NULL | Raw JSON — only stored in debug mode |
| `created_at` | DATETIME | DEFAULT CURRENT_TIMESTAMP |

Indexes: `success`, `created_at`, `http_status`

---

## 6. WordPress Options (wp_options keys)

### Plugin meta

| Key | Type | Description |
|---|---|---|
| `tsh_wa_version` | string | Plugin version at last activation |
| `tsh_wa_db_version` | string | DB schema version (currently `2.0.0`) |
| `tsh_wa_activation_date` | string | MySQL datetime of first activation; never overwritten |

### Settings groups

#### `tsh_wa_general_settings`
| Field | Default | Description |
|---|---|---|
| `plugin_name` | `'TSH WhatsApp Notify'` | Display name |
| `store_phone` | `''` | Store's WhatsApp number |
| `test_mode` | `'0'` | `'1'` = sandbox mode — messages do not actually send |
| `send_test_to` | `''` | Override recipient in test mode |

#### `tsh_wa_api_settings`
| Field | Default | Description |
|---|---|---|
| `enable_api` | `'0'` | Master API on/off switch |
| `phone_number_id` | `''` | Meta WhatsApp Phone Number ID |
| `business_account_id` | `''` | Meta WABA ID |
| `access_token` | `''` | Meta permanent access token — never logged |
| `api_version` | `'v23.0'` | Graph API version |
| `webhook_verify_token` | *(32-char random)* | Secret for Meta webhook verification |
| `test_phone_number` | `''` | Default phone for Connection Tester / Sandbox |
| `request_timeout` | `'30'` | HTTP timeout in seconds (5–120) |
| `retry_attempts` | `'3'` | HTTP-level retries per call (0–10) |
| `retry_delay` | `'5'` | Base retry delay in seconds; doubles per attempt |

#### `tsh_wa_queue_settings`
| Field | Default | Description |
|---|---|---|
| `batch_size` | `'10'` | Items processed per cron run |
| `retry_attempts` | `'3'` | Queue-level retries before marking failed |
| `retry_delay` | `'5'` | Minutes between queue retries |
| `queue_enabled` | `'1'` | Enable/disable queue processing |

#### `tsh_wa_logging_settings`
| Field | Default | Description |
|---|---|---|
| `log_enabled` | `'1'` | Enable logging |
| `log_level` | `'info'` | Minimum level: `debug\|info\|warning\|error` |
| `log_retention` | `'30'` | Days before pruning old log rows |
| `log_to_db` | `'1'` | Persist to `tsh_wa_logs` table |
| `log_to_file` | `'0'` | Mirror to daily flat-file in `/logs/` |

#### `tsh_wa_advanced_settings`
| Field | Default | Description |
|---|---|---|
| `remove_data_on_uninstall` | `'0'` | Delete all data when plugin is deleted |
| `debug_mode` | `'0'` | Show raw API responses in Tools sandbox |

### Phase 2 persistent option

| Key | Description |
|---|---|
| `tsh_wa_api_health_history` | Last success/failure timestamps + last error info |

---

## 7. Transients & Cron Hooks

### Transients

| Key | TTL | Description |
|---|---|---|
| `tsh_wa_api_health_status` | 600 s (10 min) | Cached result of the last API health check. Dashboard reads this — never makes a live API call on page load. |

### Cron hooks (WP-Cron)

| Hook | Interval | Handler | Status |
|---|---|---|---|
| `tsh_wa_process_queue` | Every minute (`tsh_wa_every_minute`) | `Scheduler::handle_process_queue()` → fires `tsh_wa_cron_process_queue` action | **Stub** — Phase 3 hooks in |
| `tsh_wa_retry_failed` | Every 5 min (`tsh_wa_every_five_minutes`) | `Scheduler::handle_retry_failed()` → fires `tsh_wa_cron_retry_failed` | **Stub** — Phase 3 hooks in |
| `tsh_wa_prune_logs` | Daily | `Scheduler::handle_prune_logs()` → calls `Logger::prune()` | **Live** |
| `tsh_wa_health_check` | Hourly | `Scheduler::handle_health_check()` → fires `tsh_wa_cron_health_check` | **Live** — `HealthMonitor::handle_cron_health_check()` listens |

### Custom cron intervals added

| Slug | Interval |
|---|---|
| `tsh_wa_every_minute` | 60 seconds |
| `tsh_wa_every_five_minutes` | 300 seconds |

---

## 8. Class Reference

### `Bootstrap\Loader`
**Singleton bootstrap.** `Loader::instance()` is called on `plugins_loaded`. Checks WooCommerce, boots all components, registers hooks. Runs `maybe_upgrade_db()` on `plugins_loaded` (priority 5) to auto-migrate schema for existing installs without requiring deactivate/reactivate.

### `Bootstrap\Activator`
One-time setup on plugin activation: PHP/WC version checks, directory creation, `Installer::run()`, seed default settings, store version stamps.

### `Bootstrap\Deactivator`
On deactivation: clears all cron events, flushes rewrite rules. **Does not delete data.**

### `Database\Installer`
Creates/upgrades all tables via `dbDelta()`. `DB_VERSION = '2.0.0'`. Safe to call repeatedly — idempotent.

### `Cron\Scheduler`
Registers custom cron intervals and four scheduled events. Handlers fire WordPress actions that other classes hook into (so Phase 3 code attaches without modifying Scheduler).

### `Queue\Queue`
Full data-access layer for `tsh_wa_queue`. Status constants: `STATUS_PENDING`, `STATUS_PROCESSING`, `STATUS_SENT`, `STATUS_FAILED`, `STATUS_CANCELLED`. Methods: `add()`, `remove()`, `retry()`, `process()` *(stub — Phase 3)*, `clear()`, `count()`, `get()`, `get_items()`.

### `Logger\Logger`
Central logging service. Levels: `LEVEL_DEBUG`, `LEVEL_INFO`, `LEVEL_SUCCESS`, `LEVEL_WARNING`, `LEVEL_ERROR`. Methods: `log()`, `debug()`, `info()`, `success()`, `warning()`, `error()`, `get_logs()`, `get_counts_by_level()`, `count_today()`, `prune()`, `clear_all()`. Reads `tsh_wa_logging_settings` at construction time.

### `Helpers\Helpers`
Static utility class. Methods:
- `format_phone( $phone, $country = 'NG' )` — normalise to E.164 (default country: Nigeria)
- `is_valid_phone( $phone )` — validates E.164 format
- `mask_phone( $phone )` — masks middle digits for safe display
- `sanitize_message( $message )` — strips control chars, enforces 4096-char limit
- `interpolate_template( $template, $variables )` — replaces `{{key}}` placeholders
- `format_currency( $amount, $currency )` — WooCommerce-aware currency formatting
- `get_order_items_summary( $order, $max_items )` — readable order item list
- `mask_token( $token, $start, $end )` — masks a sensitive token for display
- `is_plugin_ready()` — true if phone_number_id + access_token + business_account_id all set
- `is_test_mode()` — reads `tsh_wa_general_settings.test_mode`
- `is_debug_mode()` — reads `tsh_wa_advanced_settings.debug_mode`
- `asset_url( $path )` — returns full plugin asset URL
- `version()` — returns `TSH_WA_VERSION`

---

### API Layer (Phase 2)

### `API\ProviderInterface`
Interface all messaging providers must implement:
```
connect(): bool
verify(): array
sendMessage( string $phone, string $message ): array
sendTemplate( string $phone, string $template_name, string $language, array $components ): array
uploadMedia( string $file_path, string $mime_type ): array   // stub in Phase 2
getPhoneInfo(): array
getBusinessInfo(): array
healthCheck(): array
```

### `API\Exceptions`
Exception hierarchy — all in `TSH\WhatsAppNotify\API\`:
- `ApiException` (base) — `getMessage()`, `getHttpStatus()`, `getMetaErrorCode()`, `isRetryable()`
- `ConfigurationException extends ApiException` — missing/invalid credentials
- `ConnectionException extends ApiException` — network-level failure
- `AuthException extends ApiException` — 401/403 from Meta
- `RateLimitException extends ApiException` — 429; always `isRetryable() = true`
- `InvalidResponseException extends ApiException` — malformed/unexpected response

### `API\TokenManager`
**The only class that reads the raw access token from `tsh_wa_api_settings.access_token`.** Methods: `get_token()`, `set_token()`, `delete_token()`, `has_token()`, `get_masked()`, `get_credentials()`, `has_required_credentials()`, `export_settings()`. Token is never logged, never returned in AJAX responses, never stored in transients.

### `API\ResponseParser`
Normalises `wp_remote_request()` results into a consistent shape. Classifies retryable errors by Meta error code and HTTP status (retryable: 429, 500, 502, 503, 504; also Meta codes 130429, 131056, 131057). Returns structured array with keys: `success`, `http_status`, `body`, `data`, `error_message`, `error_code`, `retryable`, `latency_ms`.

### `API\RequestLogger`
Writes to `tsh_wa_api_requests`. Methods: `log_request()`, `get_recent()`, `get_stats()`, `get_success_rate()`, `get_last_successful()`, `get_last_failed()`, `clear()`. Response body stored only when debug mode is on.

### `API\ApiClient`
Core HTTP client. Wraps `wp_remote_request()`. Injects Bearer token header automatically (reads via `TokenManager`). Implements exponential back-off retry (`retry_attempts` × `retry_delay` from settings, doubling each time). Delegates to `ResponseParser` and `RequestLogger`. Methods: `get()`, `post()`, `put()`, `delete()`.

### `API\MetaCloudProvider implements ProviderInterface`
Implements all provider methods against `https://graph.facebook.com/{version}/`. Base URL: `https://graph.facebook.com/`. Uses `TokenManager` for all credentials. `uploadMedia()` is a stub returning `['success' => false, 'message' => 'Media upload coming in Phase 3']`. Normalises phones by stripping the `+` prefix before sending to Meta. `healthCheck()` calls both `getPhoneInfo()` and `getBusinessInfo()` and returns a merged result.

### `API\ConnectionTester`
4-step connection verification (credentials check → internet check → phone info → business info). Methods:
- `verify_connection(): array` — runs all 4 steps, returns `connected`, `steps[]`, phone/business info, latency
- `send_test_message( string $phone, string $message ): array` — real live send

### `API\HealthMonitor`
Caches health status in transient `tsh_wa_api_health_status` (10 min TTL). Registers `tsh_wa_cron_health_check` action hook. Methods:
- `register_hooks()` — call once at boot
- `get_status( bool $force = false ): array`
- `refresh(): array` — force live check + update cache
- `get_cached_status(): ?array`
- `get_history(): array` — last success/failure timestamps
- `get_dashboard_status(): array` — enriched data for dashboard template (includes today's request counts from `RequestLogger`)
- `handle_cron_health_check()` — cron callback

---

### Admin Layer

### `Admin\Menu`
Registers plugin submenu under **WooCommerce** in the admin sidebar. Enqueues `assets/css/admin.css` and `assets/js/admin.js` on plugin pages only. Localises `tshWaAdmin` JS object with `ajaxUrl`, `nonce` (via `Ajax::NONCE_ACTION`), `pluginUrl`, and `i18n` strings.

**Page slugs:**

| Constant | Slug | Renders |
|---|---|---|
| `SLUG_DASHBOARD` | `tsh-whatsapp-notify` | `Dashboard::render()` |
| `SLUG_ORDERS` | `tsh-whatsapp-notify-orders` | `Pages\Orders::render()` |
| `SLUG_TEMPLATES` | `tsh-whatsapp-notify-templates` | `Pages\Templates::render()` |
| `SLUG_QUEUE` | `tsh-whatsapp-notify-queue` | `Pages\Queue::render()` |
| `SLUG_LOGS` | `tsh-whatsapp-notify-logs` | `Pages\Logs::render()` |
| `SLUG_SETTINGS` | `tsh-whatsapp-notify-settings` | `Settings::render()` |
| `SLUG_TOOLS` | `tsh-whatsapp-notify-tools` | `Pages\Tools::render()` |
| `SLUG_ABOUT` | `tsh-whatsapp-notify-about` | `Pages\About::render()` |

### `Admin\Ajax` *(Phase 2)*
`NONCE_ACTION = 'tsh_wa_admin_nonce'` — must match `Menu.php` nonce creation. All handlers call `check_ajax_referer( self::NONCE_ACTION )` and `current_user_can( 'manage_woocommerce' )`.

| AJAX action | Handler | Description |
|---|---|---|
| `tsh_wa_verify_connection` | `handle_tsh_wa_verify_connection()` | Runs `ConnectionTester::verify_connection()` |
| `tsh_wa_send_test_message` | `handle_tsh_wa_send_test_message()` | Runs `ConnectionTester::send_test_message()` |
| `tsh_wa_run_diagnostics` | `handle_tsh_wa_run_diagnostics()` | Runs full system diagnostics (PHP, OpenSSL, cURL, WP-Cron, DB tables, API ping) |
| `tsh_wa_refresh_health` | `handle_tsh_wa_refresh_health()` | Calls `HealthMonitor::refresh()` — busts transient + live check |
| `tsh_wa_export_api_settings` | `handle_tsh_wa_export_api_settings()` | Returns sanitised settings JSON (token redacted) |
| `tsh_wa_reset_api_settings` | `handle_tsh_wa_reset_api_settings()` | Resets `tsh_wa_api_settings` to defaults + clears health cache |

### `Admin\Dashboard`
Aggregates data from `Queue`, `Logger`, `Installer`, `Helpers`, and `HealthMonitor` then passes it to `templates/admin/dashboard.php`. Key template variables include all queue counts, log counts, system health checks, and `api_health` (from `HealthMonitor::get_dashboard_status()`).

### `Admin\Settings`
Registers five settings tabs: `general`, `api`, `notifications`, `queue`, `logging`, `advanced`. Uses WP Settings API (`register_setting`, `add_settings_section`, `add_settings_field`). Provides render methods: `render_text_field()`, `render_password_field()`, `render_token_field()` *(Phase 2)*, `render_checkbox_field()`, `render_number_field()`, `render_select_field()`.

---

## 9. Admin Menu & Pages

All pages require `manage_woocommerce` capability. The menu is nested under **WooCommerce** in the sidebar.

| Page | Template | Current state |
|---|---|---|
| Dashboard | `templates/admin/dashboard.php` | ✅ Full — summary cards, WhatsApp Cloud health panel, queue/log stats, system health |
| Orders | `templates/admin/orders.php` | 🔲 Placeholder — Phase 3 |
| Templates | `templates/admin/templates.php` | 🔲 Placeholder — Phase 3 |
| Queue | `templates/admin/queue.php` | 🔲 Placeholder — Phase 3 |
| Logs | `templates/admin/logs.php` | ✅ Basic — log table with filters |
| Settings | `templates/admin/settings.php` | ✅ Full — all 5 tabs; Phase 2 Connection Tester on API tab |
| Tools | `templates/admin/tools.php` | ✅ Full — Diagnostics, Message Sandbox, DB tools, Log tools, System Info |
| About | `templates/admin/about.php` | ✅ Static info page |

---

## 10. Assets (CSS / JS)

### `assets/css/admin.css`
Single stylesheet for all admin pages. Uses CSS custom properties (variables) for the design system (colours, spacing, shadows). Phase 2 additions appended at the bottom: health list styles, connection tester, AJAX result boxes, diagnostic card grid, code block, token field toggle.

### `assets/js/admin.js`
Vanilla JS + jQuery (WP-bundled). No build step. Structured as `window.TSHWaAdmin` namespace with sub-modules. Reads global `tshWaAdmin` object injected via `wp_localize_script()`.

**Modules:**
- `initDismissNotices()` — dismiss admin notices
- `initPasswordReveal()` — show/hide password fields; auto-adds toggle buttons
- `initConfirmForms()` — `data-tsh-wa-confirm` attribute confirmation dialogs
- `initTabMemory()` — scroll active settings tab into view
- `initLogContextToggle()` — auto-close sibling `<details>` elements
- `initCopyToClipboard()` — `data-tsh-wa-copy` attribute clipboard handler
- `initConnectionTester()` — AJAX verify + step-list rendering *(Phase 2)*
- `initTestMessageSender()` — AJAX send on settings page *(Phase 2)*
- `initMessageSandbox()` — AJAX send + char counter on tools page *(Phase 2)*
- `initDiagnostics()` — AJAX run + card grid + JSON download *(Phase 2)*
- `initApiSettingsActions()` — Export + Reset AJAX buttons *(Phase 2)*
- `initHealthRefresh()` — Refresh health AJAX + page reload *(Phase 2)*
- `ajax( action, data, callback, onError )` — shared AJAX helper (auto-injects nonce)
- `showAjaxResult( $container, success, message )` — shared result display
- `esc( str )` — HTML-escape utility

---

## 11. Phase 1 — What Was Built

**Goal:** Complete plugin skeleton with no live API calls.

**Deliverables:**
- Plugin entry point (`tsh-whatsapp-notify.php`) with all constants and autoloader
- Bootstrap (`Activator`, `Deactivator`, `Loader`) 
- Database (`Installer`) — 4 tables at schema version `1.0.0`
- `Queue\Queue` — full data-access layer (no sending yet)
- `Logger\Logger` — full logging service
- `Helpers\Helpers` — all static utilities
- `Cron\Scheduler` — cron events registered; all handlers are stubs firing actions
- Admin UI — `Menu`, `Dashboard`, `Settings` (5 tabs), and all page stubs
- Templates — all 8 admin templates (mostly placeholder content)
- `assets/css/admin.css` — complete design system
- `assets/js/admin.js` — base JS utilities (no AJAX calls yet)
- `composer.json`, `readme.txt`, `uninstall.php`
- All `index.php` directory-silence files and `logs/.htaccess`

---

## 12. Phase 2 — What Was Built

**Goal:** WhatsApp Cloud API communication engine. No WooCommerce order hooks. No customer notifications. API engine only.

### New files created

| File | Purpose |
|---|---|
| `includes/API/index.php` | Directory silence |
| `includes/API/ProviderInterface.php` | Contract for all providers |
| `includes/API/Exceptions.php` | `ApiException` base + 5 typed subtypes |
| `includes/API/TokenManager.php` | Sole reader of the raw access token |
| `includes/API/ResponseParser.php` | Normalises HTTP responses; classifies retryable errors |
| `includes/API/RequestLogger.php` | Writes to `tsh_wa_api_requests` table |
| `includes/API/ApiClient.php` | Core HTTP client with exponential back-off |
| `includes/API/MetaCloudProvider.php` | `ProviderInterface` against Meta Graph API |
| `includes/API/ConnectionTester.php` | 4-step verify + real test send |
| `includes/API/HealthMonitor.php` | Transient-cached health status + cron hook |
| `includes/Admin/Ajax.php` | 6 AJAX handlers |

### Files modified

| File | Changes |
|---|---|
| `includes/Database/Installer.php` | `DB_VERSION` → `'2.0.0'`; added `tsh_wa_api_requests` table |
| `includes/Admin/Settings.php` | API tab: `enable_api`, `test_phone_number`, `request_timeout`, `retry_attempts`, `retry_delay`; `api_version` default → `v23.0`; added `render_token_field()` |
| `includes/Admin/Dashboard.php` | Imports `HealthMonitor`; adds `api_health` to template data |
| `includes/Admin/Pages/Tools.php` | Added `clear_api_requests` + `bust_health_cache` tools; injects sandbox template vars |
| `includes/Admin/Menu.php` | Nonce created via `Ajax::NONCE_ACTION`; all Phase 2 i18n strings added |
| `includes/Bootstrap/Loader.php` | Registers `Ajax` + `HealthMonitor`; `maybe_upgrade_db()` on `plugins_loaded` |
| `includes/Bootstrap/Activator.php` | `tsh_wa_api_settings` defaults include all Phase 2 fields; `api_version` → `v23.0` |
| `uninstall.php` | Drops `tsh_wa_api_requests`; clears `tsh_wa_api_health_status` transient + `tsh_wa_api_health_history` option; adds `tsh_wa_cron_health_check` to cron clear list |
| `templates/admin/dashboard.php` | Added WhatsApp Cloud status panel: connection state, phone, business name, API version, latency badge, today's request counts, success rate, last request timestamps, Refresh button |
| `templates/admin/settings.php` | **Full rewrite** — tab nav, settings form, Connection Tester panel (step list + test message form), Export/Reset buttons on API tab |
| `templates/admin/tools.php` | **Full rewrite** — API Diagnostics panel, Message Sandbox (with debug JSON), DB tools, queue tools, log tools, system info table |
| `assets/js/admin.js` | **Full rewrite** — all Phase 2 AJAX modules added |
| `assets/css/admin.css` | Phase 2 styles appended |

---

## 13. Phase 3 — What Comes Next (NOT yet started)

**Goal:** WooCommerce order events → queue → send.

Planned work (do not start without explicit user instruction):

1. **Order event hooks** — Listen to WooCommerce order status transition hooks (e.g. `woocommerce_order_status_processing`, `woocommerce_order_status_completed`) and push items onto the queue via `Queue::add()`.

2. **Queue processor** — Implement `Queue::process()`. Lock items (`status = 'processing'`), call `MetaCloudProvider::sendMessage()` or `sendTemplate()`, update status to `sent` or `failed`, log the result via `Logger`.

3. **Retry logic** — Hook into `tsh_wa_cron_retry_failed` to reprocess `failed` items that have not exceeded `max_attempts`.

4. **Template engine** — Build out `Pages\Templates` with CRUD UI. Templates stored in `tsh_wa_templates`. `Helpers::interpolate_template()` replaces `{{variable}}` placeholders with WC order data.

5. **Customer notification settings** — UI to map order status → template (currently placeholder `templates/admin/orders.php`).

6. **Webhook receiver** — Endpoint to receive Meta delivery status callbacks and update queue item status accordingly.

7. **Media upload** — Implement `MetaCloudProvider::uploadMedia()` (currently returns a stub error).

8. **`Queue` page** — Build out `templates/admin/queue.php` with a live queue table, retry/cancel actions.

9. **`Orders` page** — Build out `templates/admin/orders.php` showing WhatsApp message history per order.

**Phase 3 must not be started automatically. Wait for explicit instruction.**

---

## 14. Standing Rules — Must Follow in Every Phase

1. **One class = one responsibility.** Never merge two concerns into one class.
2. **ABSPATH guard** on every PHP file. First executable line after `<?php`.
3. **Nonce + capability** on every form POST and AJAX handler. Use `Ajax::NONCE_ACTION` for AJAX. Use `manage_woocommerce` capability.
4. **Token never exposed.** Raw access token: never log it, never return it in AJAX responses, never store it in transients or session data. `TokenManager` is the single gatekeeper.
5. **All HTTP through `ApiClient`.** Never call `wp_remote_request()` directly against the Meta API from any class other than `ApiClient`.
6. **Use `ProviderInterface`.** Business logic always depends on the interface, not the concrete `MetaCloudProvider`. This keeps provider-swapping clean.
7. **Idempotent installer.** `Installer::run()` must always be safe to call repeatedly.
8. **Templates are dumb.** No business logic in template files. All data is prepared by the PHP class and `extract()`-ed in.
9. **WordPress Coding Standards.** All PHP must pass `phpcs --standard=WordPress`. All output must be escaped. All SQL must be prepared.
10. **No direct DB queries with interpolated user input.** Always use `$wpdb->prepare()`.
11. **Phase isolation.** Do not implement Phase 3 work while doing Phase 2, or mix future work into current phase classes.

---

## 15. Key Architectural Decisions & Why

| Decision | Why |
|---|---|
| `ApiClient` is the sole HTTP caller for Meta | Centralises retry logic, logging, token injection, and timeout config. Changing any of these only requires touching one class. |
| `ProviderInterface` contract | Future providers (Twilio, 360dialog, Vonage) implement the same interface. All business logic that sends messages stays untouched. |
| `TokenManager` as single token reader | Security boundary. If the token ever leaks, the investigation starts and ends in one class. |
| Health status cached in transient (10 min) | Every admin page load would otherwise make a live API call. With transient caching, only cron (hourly) and explicit Refresh requests trigger live checks. |
| `maybe_upgrade_db()` on `plugins_loaded` (prio 5) | Existing installs updating via file copy get the Phase 2 table without needing to deactivate and reactivate. |
| `api_version` default changed to `v23.0` | v19.0 is outdated. v23.0 was current at time of Phase 2 build (July 2026). Update this as Meta releases new versions. |
| Exponential back-off in `ApiClient` | Meta API can return 429 and 5xx. Doubling the delay per retry (base × 2^n) avoids hammering a temporarily-down endpoint. |
| `Ajax::NONCE_ACTION = 'tsh_wa_admin_nonce'` | Matches the nonce action already present in the Phase 1 `Menu.php` localisation. Changing it would break existing admin sessions. |
| All AJAX handlers in one `Ajax` class | Single entry point for all admin AJAX. Nonce verification happens in a shared private method — cannot be accidentally omitted. |
| `Queue::process()` is a stub | The queue data layer is fully usable in Phase 1/2. The actual sending is wired in Phase 3. Keeps phases clean. |
| `Scheduler` fires WordPress actions, not direct calls | `do_action( 'tsh_wa_cron_process_queue' )` means Phase 3 can hook in without modifying `Scheduler`. Open/closed principle. |

---

## 16. Security Model

### Access control
- All admin pages: `manage_woocommerce` capability
- All AJAX handlers: `check_ajax_referer( Ajax::NONCE_ACTION )` + `manage_woocommerce`
- Nonce is created fresh on every page load via `wp_create_nonce()`

### Token protection
- Raw token only ever lives in `tsh_wa_api_settings.access_token` (wp_options)
- `TokenManager` is the only class with a `get_option()` call for that key
- Token is never: logged, printed, included in AJAX responses, stored in transients, readable via export (`export_settings()` returns it masked)
- Settings page uses a password input with show/hide toggle — never pre-filled as plain text in HTML attributes

### SQL
- All queries use `$wpdb->prepare()` with typed placeholders
- Column/table names are whitelisted before interpolation (never user-supplied)
- `TRUNCATE` and `DROP` operations are never user-triggered without a nonce + confirmation

### Log files
- `/logs/.htaccess` blocks direct HTTP access (`deny from all`)
- `/logs/index.php` prevents directory listing
- Response bodies only stored in the `tsh_wa_api_requests` table when `debug_mode = '1'`

### Uninstall
- Data deletion requires two conditions: plugin deleted (not just deactivated) AND `remove_data_on_uninstall = '1'` in Advanced settings
- Deactivation is always safe — no data removed

---

## 17. Development Toolchain

### Composer (dev only — no `vendor/` committed)

```bash
composer install          # install dev deps
composer run phpcs        # run PHPCS against includes/ (WordPress standard)
composer run phpcbf       # auto-fix PHPCS issues
composer run test         # run PHPUnit (tests/ directory, not yet populated)
```

### Dev dependencies
- `squizlabs/php_codesniffer ^3.7`
- `wp-coding-standards/wpcs ^3.0`
- `phpunit/phpunit ^10.0`
- `brain/monkey ^2.6` (WP function mocks)
- `mockery/mockery ^1.6`

### Test namespace
`TSH\WhatsAppNotify\Tests\` → `tests/` (test files not yet created)

### No build step for assets
`assets/css/admin.css` and `assets/js/admin.js` are plain CSS and vanilla JS + jQuery. No Webpack, Vite, or npm required. Edit directly.

### File naming convention
- PHP classes: `PascalCase.php` matching the class name exactly
- Templates: `lowercase-hyphenated.php`
- Assets: `lowercase-hyphenated.css/.js`

---

*Last updated: Phase 2 complete. Phase 3 not yet started.*  
*DB_VERSION: 2.0.0 | Plugin version: 1.0.0 | API version default: v23.0*
