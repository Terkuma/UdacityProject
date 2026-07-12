<?php
/**
 * Order matching — finds WooCommerce orders linked to a conversation.
 *
 * @package TSH\WhatsAppNotify\Inbox
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Inbox;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class OrderMatcher
 *
 * Finds all WooCommerce orders that can be linked to a phone number or
 * customer account, and formats them for display in the customer sidebar.
 */
final class OrderMatcher {

	/**
	 * Find orders for a phone number or customer.
	 *
	 * @param string   $phone       E.164 phone.
	 * @param int|null $customer_id WP user ID, or null for guests.
	 * @param int      $limit       Maximum orders to return.
	 * @return array<int, array<string, mixed>>
	 */
	public function find_orders( string $phone, ?int $customer_id, int $limit = 10 ): array {
		$orders = [];

		if ( $customer_id ) {
			// Registered customer — fetch by user ID (most reliable).
			$wc_orders = wc_get_orders( [
				'customer_id' => $customer_id,
				'limit'       => $limit,
				'orderby'     => 'date',
				'order'       => 'DESC',
			] );
			foreach ( (array) $wc_orders as $order ) {
				if ( $order instanceof \WC_Order ) {
					$orders[] = $this->format_order( $order );
				}
			}
		}

		if ( count( $orders ) < $limit ) {
			// Also search by billing phone to catch guest orders or orders
			// placed before the customer created an account.
			$normalised  = ltrim( $phone, '+' );
			$phone_orders = wc_get_orders( [
				'billing_phone' => $phone,
				'limit'         => $limit - count( $orders ),
				'orderby'       => 'date',
				'order'         => 'DESC',
			] );

			// De-duplicate by order ID.
			$existing_ids = array_column( $orders, 'id' );
			foreach ( (array) $phone_orders as $order ) {
				if ( ! $order instanceof \WC_Order ) {
					continue;
				}
				if ( in_array( $order->get_id(), $existing_ids, true ) ) {
					continue;
				}
				$orders[]      = $this->format_order( $order );
				$existing_ids[] = $order->get_id();
			}

			// Try normalised phone too.
			if ( $normalised !== $phone && count( $orders ) < $limit ) {
				$norm_orders = wc_get_orders( [
					'billing_phone' => $normalised,
					'limit'         => $limit - count( $orders ),
					'orderby'       => 'date',
					'order'         => 'DESC',
				] );
				foreach ( (array) $norm_orders as $order ) {
					if ( ! $order instanceof \WC_Order ) {
						continue;
					}
					if ( in_array( $order->get_id(), $existing_ids, true ) ) {
						continue;
					}
					$orders[] = $this->format_order( $order );
					$existing_ids[] = $order->get_id();
				}
			}
		}

		// Sort by date descending.
		usort( $orders, static fn( $a, $b ) => strcmp( (string) $b['date_created'], (string) $a['date_created'] ) );

		return array_slice( $orders, 0, $limit );
	}

	/**
	 * Format a single WC_Order into a display-safe array.
	 *
	 * @param \WC_Order $order
	 * @return array<string, mixed>
	 */
	private function format_order( \WC_Order $order ): array {
		$status_label = wc_get_order_status_name( $order->get_status() );

		return [
			'id'           => $order->get_id(),
			'number'       => $order->get_order_number(),
			'status'       => $order->get_status(),
			'status_label' => $status_label,
			'total'        => $order->get_formatted_order_total(),
			'currency'     => $order->get_currency(),
			'items_count'  => count( $order->get_items() ),
			'date_created' => $order->get_date_created()?->date( 'Y-m-d H:i:s' ),
			'date_human'   => $order->get_date_created()?->date_i18n( get_option( 'date_format' ) ),
			'payment'      => $order->get_payment_method_title(),
			'edit_url'     => $order->get_edit_order_url(),
			'view_url'     => $order->get_view_order_url(),
		];
	}
}
