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

use TSH\WhatsAppNotify\Database\Installer;
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

		$template = TSH_WA_PATH . 'templates/admin/tools.php';

		if ( file_exists( $template ) ) {
			// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
			extract( [ 'tool_notice' => $notice ], EXTR_SKIP );
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
			'repair_db'   => $this->tool_repair_db(),
			'clear_queue' => $this->tool_clear_queue(),
			'clear_logs'  => $this->tool_clear_logs(),
			default       => [
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
		$logger  = new Logger();
		$cleared = $logger->clear_all();

		return [
			'type'    => 'success',
			'message' => __( 'All log entries cleared.', 'tsh-whatsapp-notify' ),
		];
	}
}
