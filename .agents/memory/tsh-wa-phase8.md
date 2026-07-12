---
name: TSH WA Phase 8 — Marketing & Broadcast Engine
description: Phase 8 implementation details — campaign engine, AJAX handlers, admin UI, assets.
---

## Summary
Phase 8 adds a full WhatsApp marketing platform on top of Phases 1–7.

## Architecture

- All campaign messages route through the existing `Queue\Queue::add()` — nothing bypasses the queue.
- Two-stage cron execution: Stage 1 resolves audience, Stage 2 loads queue in resumable slices (handles 100k-recipient campaigns without timeouts).
- `SegmentEngine` builds SQL against WooCommerce tables; uses `wp_posts` fallback for HPOS compatibility.
- A/B variant assigned during audience resolution; stored per-recipient in `tsh_wa_campaign_audience`.
- Unique coupons formatted as `{PREFIX}-{campaign_id}-{customer_id}-{random6}`.
- `DB_VERSION` = `8.0.0`; triggers auto-migration on `plugins_loaded`.

## New files

**`includes/Marketing/`** — 15 backend classes (all complete, no stubs):
AudienceBuilder, BroadcastEngine, CampaignAnalytics, CampaignExporter, CampaignImporter,
CampaignLogger, CampaignManager, CampaignQueue, CampaignRepository, CampaignRunner,
CampaignScheduler, CampaignTemplates, CampaignValidator, CouponEngine, SegmentEngine.

**`includes/Admin/Pages/Marketing.php`** — admin page controller; localises `tshWaMarketingData` to JS.

**`templates/admin/marketing.php`** — 5-view template: campaign list, dashboard stats, template library, saved segments, import/export; 6-step campaign builder wizard.

**`assets/css/marketing.css`** — full stylesheet (~31 KB).

**`assets/js/marketing.js`** — jQuery campaign builder, AJAX wiring, list view, analytics/logs modals (~44 KB).

## Modified files

- `Database/Installer.php` — added 4 tables: `tsh_wa_campaigns`, `tsh_wa_campaign_runs`, `tsh_wa_campaign_audience`, `tsh_wa_campaign_logs`.
- `Admin/Ajax.php` — added 24 `tsh_wa_mkt_*` action names + handler methods; added `make_campaign_manager()` private helper.
- `Admin/Menu.php` — added `SLUG_MARKETING`, submenu page, asset enqueue + `wp_localize_script` for marketing page.
- `Bootstrap/Loader.php` — instantiates and registers `CampaignScheduler` hooks on `plugins_loaded`.
- `Bootstrap/Activator.php` — seeds `tsh_wa_marketing_settings` defaults.

## 24 AJAX actions
`tsh_wa_mkt_list`, `tsh_wa_mkt_get`, `tsh_wa_mkt_create`, `tsh_wa_mkt_update`,
`tsh_wa_mkt_delete`, `tsh_wa_mkt_duplicate`, `tsh_wa_mkt_launch`, `tsh_wa_mkt_pause`,
`tsh_wa_mkt_resume`, `tsh_wa_mkt_cancel`, `tsh_wa_mkt_archive`, `tsh_wa_mkt_preview`,
`tsh_wa_mkt_analytics`, `tsh_wa_mkt_runs`, `tsh_wa_mkt_logs`, `tsh_wa_mkt_export`,
`tsh_wa_mkt_import`, `tsh_wa_mkt_templates`, `tsh_wa_mkt_import_template`,
`tsh_wa_mkt_segments`, `tsh_wa_mkt_save_segment`, `tsh_wa_mkt_delete_segment`,
`tsh_wa_mkt_estimate_audience`, `tsh_wa_mkt_dashboard`.

**Why:**  All AJAX uses the same `verify_request()` guard (nonce + `manage_woocommerce` cap check) as all prior phases.
