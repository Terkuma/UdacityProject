<?php
/**
 * Queue admin page controller.
 *
 * @package TSH\WhatsAppNotify\Admin\Pages
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Admin\Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TSH\WhatsAppNotify\Queue\Queue as QueueService;

/**
 * Class Queue
 *
 * Displays the Queue page: a live view of pending, sent, and failed
 * message queue items with per-item retry / cancel actions.
 */
final class Queue {

	/**
	 * Render the Queue page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'tsh-whatsapp-notify' ) );
		}

		$this->handle_actions();

		$queue_service = new QueueService();
		$status        = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';

		if ( $status && ! in_array( $status, QueueService::ALL_STATUSES, true ) ) {
			$status = '';
		}

		$current_page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;

		$items = $queue_service->get_items( [
			'status'   => $status,
			'per_page' => 20,
			'page'     => $current_page,
			'orderby'  => 'scheduled_at',
			'order'    => 'ASC',
		] );

		$counts = $queue_service->count();

		$template = TSH_WA_PATH . 'templates/admin/queue.php';

		if ( file_exists( $template ) ) {
			// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
			extract( [
				'queue_items'  => $items['rows'],
				'total_items'  => $items['total'],
				'queue_counts' => $counts,
				'current_page' => $current_page,
				'active_status'=> $status,
			], EXTR_SKIP );
			include $template;
		}
	}

	// -------------------------------------------------------------------------
	// Action handler
	// -------------------------------------------------------------------------

	/**
	 * Handle queue management form actions (retry, remove, clear).
	 */
	private function handle_actions(): void {
		if ( empty( $_POST['tsh_wa_action'] ) ) {
			return;
		}

		if ( ! check_admin_referer( 'tsh_wa_queue_action', 'tsh_wa_queue_nonce' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'tsh-whatsapp-notify' ) );
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'tsh-whatsapp-notify' ) );
		}

		$action        = sanitize_key( wp_unslash( $_POST['tsh_wa_action'] ) );
		$queue_service = new QueueService();

		switch ( $action ) {
			case 'retry':
				$id = absint( $_POST['item_id'] ?? 0 );
				if ( $id ) {
					$queue_service->retry( $id );
				}
				break;

			case 'remove':
				$id = absint( $_POST['item_id'] ?? 0 );
				if ( $id ) {
					$queue_service->remove( $id );
				}
				break;

			case 'clear_failed':
				$queue_service->clear( QueueService::STATUS_FAILED );
				break;

			case 'clear_all':
				$queue_service->clear( 'all' );
				break;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=tsh-whatsapp-notify-queue&updated=1' ) );
		exit;
	}
}
