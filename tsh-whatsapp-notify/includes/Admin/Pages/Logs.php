<?php
/**
 * Logs admin page controller.
 *
 * @package TSH\WhatsAppNotify\Admin\Pages
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Admin\Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TSH\WhatsAppNotify\Logger\Logger;

/**
 * Class Logs
 *
 * Displays the Logs page: a filterable, searchable, paginated
 * view of all plugin log entries stored in the database.
 */
final class Logs {

	/**
	 * Render the Logs page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'tsh-whatsapp-notify' ) );
		}

		$this->handle_actions();

		$logger = new Logger();

		$args = [
			'level'     => isset( $_GET['level'] )    ? sanitize_key( wp_unslash( $_GET['level'] ) )        : '',
			'source'    => isset( $_GET['source'] )   ? sanitize_text_field( wp_unslash( $_GET['source'] ) ) : '',
			'search'    => isset( $_GET['s'] )         ? sanitize_text_field( wp_unslash( $_GET['s'] ) )      : '',
			'date_from' => isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '',
			'date_to'   => isset( $_GET['date_to'] )   ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) )   : '',
			'per_page'  => 25,
			'page'      => isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1,
			'orderby'   => 'created_at',
			'order'     => 'DESC',
		];

		$result = $logger->get_logs( $args );
		$counts = $logger->get_counts_by_level();

		$template = TSH_WA_PATH . 'templates/admin/logs.php';

		if ( file_exists( $template ) ) {
			// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
			extract( [
				'log_rows'    => $result['rows'],
				'total_logs'  => $result['total'],
				'log_counts'  => $counts,
				'filter_args' => $args,
			], EXTR_SKIP );
			include $template;
		}
	}

	// -------------------------------------------------------------------------
	// Action handler
	// -------------------------------------------------------------------------

	/**
	 * Handle log management form actions (clear, prune).
	 */
	private function handle_actions(): void {
		if ( empty( $_POST['tsh_wa_log_action'] ) ) {
			return;
		}

		if ( ! check_admin_referer( 'tsh_wa_log_action', 'tsh_wa_log_nonce' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'tsh-whatsapp-notify' ) );
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'tsh-whatsapp-notify' ) );
		}

		$action = sanitize_key( wp_unslash( $_POST['tsh_wa_log_action'] ) );
		$logger = new Logger();

		switch ( $action ) {
			case 'clear_all':
				$logger->clear_all();
				break;

			case 'prune_old':
				$settings  = get_option( 'tsh_wa_logging_settings', [] );
				$retention = absint( $settings['log_retention'] ?? 30 );
				$logger->prune( $retention );
				break;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=tsh-whatsapp-notify-logs&updated=1' ) );
		exit;
	}
}
