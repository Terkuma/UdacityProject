<?php
/**
 * Tools admin page controller.
 *
 * @package TSH\WhatsAppNotify\Admin\Pages
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Admin\Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TSH\WhatsAppNotify\API\ConnectionTester;
use TSH\WhatsAppNotify\Database\Installer;
use TSH\WhatsAppNotify\Helpers\Helpers;
use TSH\WhatsAppNotify\Logger\Logger;
use TSH\WhatsAppNotify\Queue\Queue;

/**
 * Class Tools
 *
 * Provides administrative utilities: DB repair, cache clearing,
 * test message dispatch (Phase 2), and system diagnostics.
 */
final class Tools {

	/**
	 * Render the Tools page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'tsh-whatsapp-notify' ) );
		}

		$notice = $this->handle_actions();

		$api_settings    = get_option( 'tsh_wa_api_settings', [] );
		$test_phone      = sanitize_text_field( $api_settings['test_phone_number'] ?? '' );
		$is_debug        = Helpers::is_debug_mode();

		$template = TSH_WA_PATH . 'templates/admin/tools.php';

		if ( file_exists( $template ) ) {
			$template_vars = [
				'tool_notice' => $notice,
				'test_phone'  => $test_phone,
				'is_debug'    => $is_debug,
			];
			// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
			extract( $template_vars, EXTR_SKIP );
			include $template;
		}
	}

	// -------------------------------------------------------------------------
	// Action handler
	// -------------------------------------------------------------------------

	/**
	 * Handle tool form submissions.
	 *
	 * @return array<string, string>|null Associative array with 'type' and 'message', or null.
	 */
	private function handle_actions(): ?array {
		if ( empty( $_POST['tsh_wa_tool'] ) ) {
			return null;
		}

		if ( ! check_admin_referer( 'tsh_wa_tools_action', 'tsh_wa_tools_nonce' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'tsh-whatsapp-notify' ) );
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'tsh-whatsapp-notify' ) );
		}

		$tool = sanitize_key( wp_unslash( $_POST['tsh_wa_tool'] ) );

		return match ( $tool ) {
			'repair_db'              => $this->tool_repair_db(),
			'clear_queue'            => $this->tool_clear_queue(),
			'clear_logs'             => $this->tool_clear_logs(),
			'clear_api_requests'     => $this->tool_clear_api_requests(),
			'bust_health_cache'      => $this->tool_bust_health_cache(),
			// Phase 3 — order tools.
			'clear_notifications'    => $this->tool_clear_notifications(),
			'retry_failed_queue'     => $this->tool_retry_failed_queue(),
			'verify_wc_hooks'        => $this->tool_verify_wc_hooks(),
			default                  => [
				'type'    => 'error',
				'message' => __( 'Unknown tool action.', 'tsh-whatsapp-notify' ),
			],
		};
	}

	/**
	 * Re-run the database installer to create/repair missing tables.
	 *
	 * @return array<string, string>
	 */
	private function tool_repair_db(): array {
		$installer = new Installer();
		$installer->run();

		return [
			'type'    => 'success',
			'message' => __( 'Database tables repaired / verified successfully.', 'tsh-whatsapp-notify' ),
		];
	}

	/**
	 * Clear all pending queue items.
	 *
	 * @return array<string, string>
	 */
	private function tool_clear_queue(): array {
		$queue   = new Queue();
		$cleared = $queue->clear( 'all' );

		return [
			'type'    => 'success',
			/* translators: %d: number of items cleared */
			'message' => sprintf( __( 'Queue cleared — %d item(s) removed.', 'tsh-whatsapp-notify' ), $cleared ),
		];
	}

	/**
	 * Clear all log entries.
	 *
	 * @return array<string, string>
	 */
	private function tool_clear_logs(): array {
		$logger = new Logger();
		$logger->clear_all();

		return [
			'type'    => 'success',
			'message' => __( 'All log entries cleared.', 'tsh-whatsapp-notify' ),
		];
	}

	/**
	 * Clear the API requests log table.
	 *
	 * @return array<string, string>
	 */
	private function tool_clear_api_requests(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'tsh_wa_api_requests';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE `{$table}`" );

		return [
			'type'    => 'success',
			'message' => __( 'API request log cleared.', 'tsh-whatsapp-notify' ),
		];
	}

	/**
	 * Delete the API health-status transient to force a fresh check.
	 *
	 * @return array<string, string>
	 */
	private function tool_bust_health_cache(): array {
		delete_transient( 'tsh_wa_api_health_status' );

		return [
			'type'    => 'success',
			'message' => __( 'API health cache cleared. The next dashboard load will trigger a fresh check.', 'tsh-whatsapp-notify' ),
		];
	}

	// -------------------------------------------------------------------------
	// Phase 3 — order tools
	// -------------------------------------------------------------------------

	/**
	 * Truncate the notifications log table.
	 *
	 * @return array<string, string>
	 */
	private function tool_clear_notifications(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'tsh_wa_notifications';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE `{$table}`" );

		return [
			'type'    => 'success',
			'message' => __( 'Notification log cleared.', 'tsh-whatsapp-notify' ),
		];
	}

	/**
	 * Reset all permanently-failed queue items for a retry.
	 *
	 * @return array<string, string>
	 */
	private function tool_retry_failed_queue(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'tsh_wa_queue';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = $wpdb->query(
			"UPDATE `{$table}` SET status = 'pending', attempts = 0, error_message = NULL WHERE status = 'failed'"
		);

		return [
			'type'    => 'success',
			/* translators: %d: count of items reset */
			'message' => sprintf( __( '%d failed queue item(s) reset for retry.', 'tsh-whatsapp-notify' ), (int) $count ),
		];
	}

	/**
	 * Verify that WooCommerce order hooks are registered.
	 *
	 * @return array<string, string>
	 */
	private function tool_verify_wc_hooks(): array {
		$hooks = [
			'woocommerce_checkout_order_created',
			'woocommerce_order_status_changed',
			'woocommerce_payment_complete',
			'woocommerce_new_order_note',
		];

		$results = [];
		foreach ( $hooks as $hook ) {
			$count       = has_action( $hook );
			$results[]   = sprintf( '%s: %s', $hook, $count ? '✓' : '✗' );
		}

		return [
			'type'    => 'info',
			'message' => implode( ' | ', $results ),
		];
	}
}
