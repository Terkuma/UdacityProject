<?php
/**
 * Customer Orders — WooCommerce order data for CRM customers.
 *
 * @package TSH\WhatsAppNotify\CRM
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\CRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CustomerOrders
 *
 * Pulls WooCommerce order history for a CRM customer.
 * HPOS-compatible: uses wc_get_orders() with customer email/phone filters.
 */
final class CustomerOrders {

	private CustomerRepository $repo;

	public function __construct( CustomerRepository $repo ) {
		$this->repo = $repo;
	}

	/**
	 * Get order stats for a WC customer ID (used when syncing from WooCommerce).
	 */
	public function get_stats_for_wc_customer( int $wc_customer_id ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) return [];

		$orders = wc_get_orders( [
			'customer_id' => $wc_customer_id,
			'limit'       => -1,
			'status'      => array_keys( wc_get_order_statuses() ),
			'return'      => 'objects',
		] );

		return $this->compute_stats( $orders );
	}

	/**
	 * Get order stats for a CRM customer array.
	 */
	public function get_stats( array $customer ): array {
		$orders = $this->fetch_orders( $customer );
		return $this->compute_stats( $orders );
	}

	/**
	 * Get recent orders for the profile view.
	 */
	public function get_recent( array $customer, int $limit = 5 ): array {
		$orders  = $this->fetch_orders( $customer, $limit );
		$results = [];
		foreach ( $orders as $order ) {
			$results[] = [
				'id'           => $order->get_id(),
				'number'       => $order->get_order_number(),
				'status'       => $order->get_status(),
				'status_label' => wc_get_order_status_name( $order->get_status() ),
				'total'        => $order->get_total(),
				'currency'     => $order->get_currency(),
				'date'         => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : null,
				'items_count'  => $order->get_item_count(),
				'view_url'     => get_edit_post_link( $order->get_id() ),
			];
		}
		return $results;
	}

	/**
	 * Timeline events for orders.
	 */
	public function get_timeline_events( array $customer ): array {
		$orders = $this->fetch_orders( $customer, 100 );
		$events = [];
		foreach ( $orders as $order ) {
			$date = $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : null;
			if ( ! $date ) continue;
			$events[] = [
				'id'          => 'order_' . $order->get_id(),
				'type'        => 'order',
				'label'       => __( 'Order', 'tsh-whatsapp-notify' ),
				'icon'        => '🛒',
				'subject'     => sprintf( __( 'Order #%s — %s', 'tsh-whatsapp-notify' ), $order->get_order_number(), wc_price( $order->get_total() ) ),
				'description' => sprintf( __( 'Status: %s', 'tsh-whatsapp-notify' ), wc_get_order_status_name( $order->get_status() ) ),
				'data'        => [
					'order_id' => $order->get_id(),
					'total'    => $order->get_total(),
					'status'   => $order->get_status(),
				],
				'created_at'  => $date,
				'source'      => 'order',
			];
		}
		return $events;
	}

	// =========================================================================
	// Internal
	// =========================================================================

	private function fetch_orders( array $customer, int $limit = -1 ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) return [];

		$args = [
			'limit'  => $limit,
			'status' => array_keys( wc_get_order_statuses() ),
			'return' => 'objects',
		];

		// Prefer WC customer ID
		if ( ! empty( $customer['wc_customer_id'] ) ) {
			$args['customer_id'] = (int) $customer['wc_customer_id'];
		} elseif ( ! empty( $customer['email'] ) ) {
			$args['billing_email'] = $customer['email'];
		} else {
			return [];
		}

		return wc_get_orders( $args ) ?: [];
	}

	private function compute_stats( array $orders ): array {
		$total_orders    = 0;
		$completed       = 0;
		$cancelled       = 0;
		$refunded        = 0;
		$pending         = 0;
		$lifetime_value  = 0.0;
		$first_order_at  = null;
		$last_order_at   = null;

		foreach ( $orders as $order ) {
			++$total_orders;
			$status = $order->get_status();
			if ( $status === 'completed' )  ++$completed;
			if ( $status === 'cancelled' )  ++$cancelled;
			if ( $status === 'refunded'  )  ++$refunded;
			if ( $status === 'pending'   )  ++$pending;

			if ( in_array( $status, [ 'completed', 'processing' ], true ) ) {
				$lifetime_value += (float) $order->get_total();
			}

			$date = $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : null;
			if ( $date ) {
				if ( ! $first_order_at || $date < $first_order_at ) $first_order_at = $date;
				if ( ! $last_order_at  || $date > $last_order_at  ) $last_order_at  = $date;
			}
		}

		return [
			'total_orders'    => $total_orders,
			'completed_orders'=> $completed,
			'cancelled_orders'=> $cancelled,
			'refunded_orders' => $refunded,
			'pending_orders'  => $pending,
			'lifetime_value'  => round( $lifetime_value, 4 ),
			'avg_order_value' => $total_orders > 0 ? round( $lifetime_value / $total_orders, 4 ) : 0.0,
			'first_order_at'  => $first_order_at,
			'last_order_at'   => $last_order_at,
		];
	}
}
