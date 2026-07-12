---
name: TSH WA Phase 5 — Meta Template Management
description: Phase 5 complete — Meta WhatsApp Template Management System; all classes, DB tables, AJAX handlers, CSS, JS, and integration wiring.
---

## Status
Phase 5 fully implemented. Phases 1–5 are complete.

## What was built

### New directory: `includes/Templates/` (16 files)
- `index.php` — silence file
- `TemplateCategory.php` — UTILITY/MARKETING/AUTHENTICATION constants + badge helpers
- `TemplateLanguage.php` — 60+ supported language codes, `detect_from_order()`, locale→code
- `TemplateLogger.php` — wraps Logger; source prefix `template`
- `TemplateCache.php` — transient cache with key registry; `flush()`, `bust_template()`, `flush_lists()`
- `TemplateRepository.php` — full DAL for `tsh_wa_meta_templates`; upsert, count_by_*, soft-delete
- `TemplateValidator.php` — body/footer/button/header/category/language validation; extract_variables()
- `TemplateSearch.php` — multi-field search + `suggest()` + grouped queries
- `TemplateSync.php` — manual/incremental/full/background/scheduled sync from Meta Graph API; pagination; detects new/updated/deleted/unchanged
- `TemplatePreview.php` — render() builds full preview with variable substitution
- `TemplateAssignment.php` — event+recipient_type → template_id in `tsh_wa_template_assignments`
- `TemplateUsage.php` — record(), get_stats(), get_most_used(), get_overall_stats()
- `TemplateAnalytics.php` — overview, status/category/language/quality breakdowns, most-used, success rates, quality warnings
- `TemplateImporter.php` — JSON + CSV import; merge/replace/skip modes
- `TemplateExporter.php` — JSON + CSV export
- `TemplateManager.php` — main orchestrator; composes all 12 services; registers background sync hook

### New DB tables (Installer DB_VERSION: 5.0.0)
- `tsh_wa_meta_templates` — synced templates with all Meta fields + variable_mapping, send_success, send_failed, raw_data
- `tsh_wa_template_assignments` — event+recipient_type → template_id

### Cron hooks (Scheduler)
- `tsh_wa_sync_templates` — hourly/configurable (HOOK_SYNC_TEMPLATES)
- `tsh_wa_refresh_template_quality` — daily (HOOK_REFRESH_TEMPLATE_QUALITY)

### Settings
- `tsh_wa_sync_settings` option key (separate from `tsh_wa_template_settings`)
- Fields: auto_sync, sync_interval, background_sync, cache_duration, max_templates, retry_failed_sync, fallback_language
- sanitize_sync_settings() registered in Settings.php

### AJAX (15 new handlers in Ajax.php)
tsh_wa_sync_templates, tsh_wa_force_full_sync, tsh_wa_get_template_preview,
tsh_wa_assign_template, tsh_wa_unassign_template, tsh_wa_get_template_assignments,
tsh_wa_search_templates, tsh_wa_get_templates_page, tsh_wa_validate_template,
tsh_wa_test_template, tsh_wa_import_templates, tsh_wa_export_templates,
tsh_wa_flush_template_cache, tsh_wa_get_template_analytics, tsh_wa_get_template_stats

### Views
- `includes/Admin/Pages/Templates.php` — full controller with pagination/filtering/stats
- `templates/admin/templates.php` — full UI: stats bar, filter bar, sortable table, pagination
- `templates/admin/template-preview-modal.php` — preview modal + variable inspector + assignment panel + import modal

### Assets
- `assets/css/admin.css` — 703 lines of Phase 5 styles appended (total: 2493 lines)
- `assets/js/admin.js` — 472 lines of Phase 5 JS appended (total: 1619 lines)
  Modules: initTemplateManager, initTemplatePreviewModal, initTemplateAssignment, initTemplateSearch, initTemplateImportModal, initTemplateAnalytics

### Integration wires
- `Loader.php` — registers TemplateManager, hooks TemplateSync to cron actions
- `Dashboard.php` — get_template_stats() widget data via TemplateManager
- `Menu.php` — 20+ Phase 5 i18n strings in tshWaAdmin.i18n
- `uninstall.php` — drops both Phase 5 tables, clears all Phase 5 options/transients/cron hooks

## Key decisions
- `tsh_wa_meta_templates` is a **separate** table from the existing `tsh_wa_templates` (Phase 3 custom templates). Do NOT merge them.
- `tsh_wa_sync_settings` is a **separate** option key from `tsh_wa_template_settings` (Phase 1).
- `TemplateSync::upsert_template()` returns 'added'|'updated'|'unchanged'|'errors' — these strings are the stat keys.
- Test template handler (`tsh_wa_test_template`) uses `Queue::add()` directly with no WC order_id (order_id = null).
- Background sync fires `HOOK_BACKGROUND_SYNC = 'tsh_wa_background_template_sync'` as a single-event cron.

**Why:** existing `tsh_wa_templates` schema is incompatible with Meta API fields; merging would break Phase 3 OrderMessageBuilder.
