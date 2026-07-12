<?php
/**
 * Orders admin page controller.
 *
 * @package TSH\WhatsAppNotify\Admin\Pages
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Admin\Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Orders
 *
 * Displays the Orders page — a searchable, paginated log of all
 * WhatsApp notifications dispatched for WooCommerce orders.
 */
final class Orders {

	/** @var int Items per page. */
	private const PER_PAGE = 25;

	/**
	 * Render the Orders page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'tsh-whatsapp-notify' ) );
		}

		$data = $this->build_template_data();

		$template = TSH_WA_PATH . 'templates/admin/orders.php';

		if ( file_exists( $template ) ) {
			// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
			extract( $data, EXTR_SKIP );
			include $template;
		}
	}

	// -------------------------------------------------------------------------
	// Data assembly
	// -------------------------------------------------------------------------

	/**
	 * Build all data required by the orders template.
	 *
	 * @return array<string, mixed>
	 */
	private function build_template_data(): array {
		global $wpdb;

		$table   = $wpdb->prefix . 'tsh_wa_notifications';
		$page    = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$offset  = ( $page - 1 ) * self::PER_PAGE;

		// Filters.
		$filter_status = sanitize_key( $_GET['status'] ?? '' );
		$filter_event  = sanitize_key( $_GET['event'] ?? '' );
		$filter_search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );

		// Build WHERE clause.
		$where  = '1=1';
		$params = [];

		if ( $filter_status ) {
			$where   .= ' AND status = %s';
			$params[] = $filter_status;
		}
		if ( $filter_event ) {
			$where   .= ' AND event = %s';
			$params[] = $filter_event;
		}
		if ( $filter_search ) {
			$where   .= ' AND (recipient_phone LIKE %s OR recipient_name LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $filter_search ) . '%';
			$params[] = $like;
			$params[] = $like;
		}

		// Total count.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = $params
			? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE {$where}", ...$params ) )
			: (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE {$where}" );

		// Fetch rows.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $params
			? $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
				...array_merge( $params, [ self::PER_PAGE, $offset ] )
			) )
			: $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM `{$table}` ORDER BY created_at DESC LIMIT %d OFFSET %d",
				self::PER_PAGE, $offset
			) );

		// Summary counts by status.
		$counts = $wpdb->get_results(
			"SELECT status, COUNT(*) AS cnt FROM `{$table}` GROUP BY status",
			OBJECT_K
		);

		return [
			'rows'           => $rows ?: [],
			'total'          => $total,
			'per_page'       => self::PER_PAGE,
			'current_page'   => $page,
			'total_pages'    => max( 1, (int) ceil( $total / self::PER_PAGE ) ),
			'filter_status'  => $filter_status,
			'filter_event'   => $filter_event,
			'filter_search'  => $filter_search,
			'status_counts'  => $counts,
			'all_events'     => \TSH\WhatsAppNotify\Orders\OrderStatusListener::ALL_EVENTS,
			'base_url'       => admin_url( 'admin.php?page=tsh-whatsapp-notify-orders' ),
		];
	}
}
