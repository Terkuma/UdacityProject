<?php
/**
 * Queue admin page controller — Phase 4.
 *
 * @package TSH\WhatsAppNotify\Admin\Pages
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Admin\Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TSH\WhatsAppNotify\Queue\DeadLetterQueue;
use TSH\WhatsAppNotify\Queue\DeliveryTracker;
use TSH\WhatsAppNotify\Queue\Queue as QueueService;
use TSH\WhatsAppNotify\Queue\QueueProcessor;
use TSH\WhatsAppNotify\Queue\QueueStats;

/**
 * Class Queue
 *
 * Renders the Phase 4 Queue dashboard: live counters, health monitor,
 * manual controls, dead letter queue section, and the full item table.
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
		$dlq           = new DeadLetterQueue();
		$stats         = new QueueStats();

		// Active status filter from URL.
		$active_status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		if ( $active_status && ! in_array( $active_status, QueueService::ALL_STATUSES, true ) ) {
			$active_status = '';
		}

		$current_page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;

		$items = $queue_service->get_items( [
			'status'   => $active_status,
			'per_page' => 25,
			'page'     => $current_page,
			'orderby'  => 'id',
			'order'    => 'DESC',
		] );

		$template = TSH_WA_PATH . 'templates/admin/queue.php';

		if ( file_exists( $template ) ) {
			// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
			extract(
				[
					'queue_items'   => $items['rows'],
					'total_items'   => $items['total'],
					'queue_stats'   => $stats->get_summary(),
					'queue_health'  => $stats->get_health(),
					'dlq_count'     => $dlq->count(),
					'dlq_items'     => $dlq->get_items( 10 )['rows'],
					'current_page'  => $current_page,
					'active_status' => $active_status,
					'per_page'      => 25,
				],
				EXTR_SKIP
			);
			include $template;
		}
	}

	// -------------------------------------------------------------------------
	// Action handler
	// -------------------------------------------------------------------------

	/**
	 * Handle queue management POST actions.
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
		$dlq           = new DeadLetterQueue();

		switch ( $action ) {
			// --- Legacy single-item actions --------------------------------.
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

			// --- Bulk queue controls ----------------------------------------.
			case 'clear_failed':
				$queue_service->clear( QueueService::STATUS_FAILED );
				break;

			case 'clear_all':
				$queue_service->clear( 'all' );
				break;

			// --- Phase 4 controls ------------------------------------------.
			case 'pause_queue':
				update_option( 'tsh_wa_queue_paused', '1', false );
				break;

			case 'resume_queue':
				delete_option( 'tsh_wa_queue_paused' );
				break;

			case 'process_now':
				$settings   = get_option( 'tsh_wa_queue_settings', [] );
				$batch_size = absint( $settings['batch_size'] ?? 10 );
				$processor  = new QueueProcessor();
				$processor->run( max( 1, $batch_size ) );
				break;

			case 'retry_all_failed':
				$dlq->retry_all();
				break;

			case 'clear_dead_letter':
				$dlq->clear();
				break;

			case 'dlq_retry':
				$id = absint( $_POST['item_id'] ?? 0 );
				if ( $id ) {
					$dlq->retry( $id );
				}
				break;

			case 'dlq_delete':
				$id = absint( $_POST['item_id'] ?? 0 );
				if ( $id ) {
					$dlq->delete( $id );
				}
				break;

			case 'export_dlq':
				$this->export_dlq();
				break;
		}

		wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'admin.php?page=tsh-whatsapp-notify-queue' ) ) );
		exit;
	}

	// -------------------------------------------------------------------------
	// Export
	// -------------------------------------------------------------------------

	/**
	 * Stream the dead letter queue as a JSON file download.
	 */
	private function export_dlq(): void {
		$dlq  = new DeadLetterQueue();
		$data = $dlq->export();

		$filename = 'tsh-wa-dead-letter-' . gmdate( 'Y-m-d-H-i-s' ) . '.json';

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		exit;
	}
}
