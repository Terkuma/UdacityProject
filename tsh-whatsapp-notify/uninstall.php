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
	$wpdb->prefix . 'tsh_wa_api_requests',    // Phase 2
	$wpdb->prefix . 'tsh_wa_notifications',   // Phase 3
	$wpdb->prefix . 'tsh_wa_delivery_events', // Phase 4
	$wpdb->prefix . 'tsh_wa_worker_log',      // Phase 4
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
	'tsh_wa_queue_worker_lock', // WorkerLock::LOCK_KEY — stored in options table
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
	'tsh_wa_cron_health_check',  // Phase 2 — HealthMonitor cron action.
	'tsh_wa_expire_queue',       // Phase 4 — hourly queue expiry.
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
