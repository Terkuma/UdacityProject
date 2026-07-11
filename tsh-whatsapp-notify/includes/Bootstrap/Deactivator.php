<?php
/**
 * Plugin deactivator.
 *
 * @package TSH\WhatsAppNotify\Bootstrap
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Bootstrap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Deactivator
 *
 * Handles clean-up on plugin deactivation.
 *
 * IMPORTANT: This class does NOT delete user data.
 * Data removal is handled exclusively by uninstall.php and only
 * when the user has opted in via the Advanced settings panel.
 */
final class Deactivator {

	/**
	 * Run deactivation routine.
	 *
	 * Hooked via register_deactivation_hook() in the main plugin file.
	 */
	public static function deactivate(): void {
		self::clear_cron_events();
		self::flush_rewrite_rules();
	}

	// -------------------------------------------------------------------------
	// Cron
	// -------------------------------------------------------------------------

	/**
	 * Unschedule all plugin cron events so they do not fire while inactive.
	 */
	private static function clear_cron_events(): void {
		$hooks = [
			'tsh_wa_process_queue',
			'tsh_wa_retry_failed',
			'tsh_wa_prune_logs',
			'tsh_wa_health_check',
		];

		foreach ( $hooks as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	// -------------------------------------------------------------------------
	// Rewrite rules
	// -------------------------------------------------------------------------

	/**
	 * Flush rewrite rules after deactivation.
	 */
	private static function flush_rewrite_rules(): void {
		flush_rewrite_rules();
	}
}
