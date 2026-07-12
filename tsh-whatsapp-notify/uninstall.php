<?php
/**
 * Uninstall TSH WhatsApp Notify.
 *
 * Runs when the plugin is deleted from the WordPress admin.
 * Only removes data when the "Remove data on uninstall" option is enabled.
 * Never deletes data on deactivation — only on explicit uninstall + user opt-in.
 *
 * @package TSH\WhatsAppNotify
 */

declare( strict_types=1 );

// Security: only run when WordPress core calls this file directly.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Only remove data if the user explicitly opted in via the plugin settings.
$remove_data = get_option( 'tsh_wa_remove_data_on_uninstall', false );

if ( ! $remove_data ) {
	return;
}

// ---------------------------------------------------------------------------
// Drop plugin tables
// ---------------------------------------------------------------------------

$tables = [
	$wpdb->prefix . 'tsh_wa_logs',
	$wpdb->prefix . 'tsh_wa_queue',
	$wpdb->prefix . 'tsh_wa_templates',
	$wpdb->prefix . 'tsh_wa_settings',
	$wpdb->prefix . 'tsh_wa_api_requests',        // Phase 2
	$wpdb->prefix . 'tsh_wa_notifications',        // Phase 3
	$wpdb->prefix . 'tsh_wa_delivery_events',      // Phase 4
	$wpdb->prefix . 'tsh_wa_worker_log',           // Phase 4
	$wpdb->prefix . 'tsh_wa_template_assignments', // Phase 5
	$wpdb->prefix . 'tsh_wa_meta_templates',       // Phase 5
	$wpdb->prefix . 'tsh_wa_messages',             // Phase 6
	$wpdb->prefix . 'tsh_wa_conversations',        // Phase 6
];

foreach ( $tables as $table ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
}

// ---------------------------------------------------------------------------
// Delete plugin options from wp_options
// ---------------------------------------------------------------------------

$options = [
	'tsh_wa_version',
	'tsh_wa_db_version',
	'tsh_wa_general_settings',
	'tsh_wa_api_settings',
	'tsh_wa_admin_notification_settings',
	'tsh_wa_customer_notification_settings',
	'tsh_wa_template_settings',
	'tsh_wa_queue_settings',
	'tsh_wa_logging_settings',
	'tsh_wa_advanced_settings',
	'tsh_wa_remove_data_on_uninstall',
	'tsh_wa_activation_date',
	// Phase 2 — API health history.
	'tsh_wa_api_health_history',
	// Phase 3 — WooCommerce order integration.
	'tsh_wa_wc_events_settings',
	'tsh_wa_admin_recipients',
	// Phase 4 — Queue delivery engine.
	'tsh_wa_queue_paused',
	'tsh_wa_queue_worker_lock',          // WorkerLock::LOCK_KEY — stored in options table
	// Phase 5 — Template management.
	'tsh_wa_sync_settings',
	'tsh_wa_template_last_sync',         // TemplateSync::OPTION_LAST_SYNC
	'tsh_wa_template_sync_status',       // TemplateSync::OPTION_SYNC_STATUS
	'tsh_wa_template_sync_last_error',   // TemplateSync::OPTION_LAST_ERROR
	'tsh_wa_tmpl_cache_keys',            // TemplateCache::REGISTRY_OPTION
	// Phase 6 — Inbox / Conversation Hub.
	'tsh_wa_inbox_settings',             // InboxManager settings
	'tsh_wa_inbox_cache_keys',           // ConversationCache::REGISTRY_OPTION
];

foreach ( $options as $option ) {
	delete_option( $option );
}

// ---------------------------------------------------------------------------
// Delete plugin transients
// ---------------------------------------------------------------------------

$transients = [
	'tsh_wa_api_health_status',   // Phase 2 — 10-min cached health check.
	'tsh_wa_rate_bucket',         // Phase 4 — rate limiter sliding window.
	'tsh_wa_queue_worker_lock',   // Phase 4 — worker mutex (stored in options, cleared here too).
];

// Phase 5 — bulk-delete all template cache transients by prefix.
$like_pattern = $wpdb->esc_like( '_transient_tsh_wa_tmpl_' ) . '%';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( $wpdb->prepare( "DELETE FROM `{$wpdb->options}` WHERE option_name LIKE %s", $like_pattern ) );

// Phase 6 — bulk-delete all inbox cache transients by prefix.
$inbox_pattern = $wpdb->esc_like( '_transient_tsh_wa_inbox_' ) . '%';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( $wpdb->prepare( "DELETE FROM `{$wpdb->options}` WHERE option_name LIKE %s", $inbox_pattern ) );

// Phase 6 — delete any downloaded media files from WP uploads.
$upload_dir  = wp_upload_dir();
$inbox_media = trailingslashit( $upload_dir['basedir'] ) . 'tsh-wa-inbox/';
if ( is_dir( $inbox_media ) ) {
	// Recursively remove directory tree.
	$iter = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $inbox_media, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iter as $entry ) {
		if ( $entry->isFile() ) {
			wp_delete_file( $entry->getPathname() );
		} elseif ( $entry->isDir() ) {
			@rmdir( $entry->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}
	@rmdir( $inbox_media ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
}

foreach ( $transients as $transient ) {
	delete_transient( $transient );
}

// ---------------------------------------------------------------------------
// Remove scheduled cron events
// ---------------------------------------------------------------------------

$cron_hooks = [
	'tsh_wa_process_queue',
	'tsh_wa_retry_failed',
	'tsh_wa_prune_logs',
	'tsh_wa_health_check',
	'tsh_wa_cron_health_check',            // Phase 2 — HealthMonitor cron action.
	'tsh_wa_expire_queue',                 // Phase 4 — hourly queue expiry.
	'tsh_wa_sync_templates',               // Phase 5 — hourly template sync.
	'tsh_wa_refresh_template_quality',     // Phase 5 — daily quality refresh.
	'tsh_wa_background_template_sync',     // Phase 5 — one-shot background sync.
	'tsh_wa_download_media',               // Phase 6 — media downloader cron.
	'tsh_wa_archive_conversations',        // Phase 6 — auto-archive stale conversations.
];

foreach ( $cron_hooks as $hook ) {
	$timestamp = wp_next_scheduled( $hook );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, $hook );
	}
	wp_clear_scheduled_hook( $hook );
}

// ---------------------------------------------------------------------------
// Remove log files from the logs directory
// ---------------------------------------------------------------------------

$log_dir = WP_PLUGIN_DIR . '/tsh-whatsapp-notify/logs/';

if ( is_dir( $log_dir ) ) {
	$files = glob( $log_dir . '*.log' );
	if ( is_array( $files ) ) {
		foreach ( $files as $file ) {
			if ( is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
	}
}
