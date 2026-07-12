<?php
/**
 * Customer matching — links a phone number to a WooCommerce customer.
 *
 * @package TSH\WhatsAppNotify\Inbox
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Inbox;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CustomerMatcher
 *
 * Attempts to find a WooCommerce customer (WP user) for a given phone number.
 * Checks:
 *  1. Billing phone in user meta (exact match and normalised).
 *  2. WooCommerce order billing phone (guest orders).
 *  3. Returns null when no match is found — callers must handle guest flows.
 */
final class CustomerMatcher {

	/**
	 * Find a WP user ID for the given phone number.
	 *
	 * @param string $phone E.164 phone number, e.g. +2348012345678.
	 * @return int|null WP user ID, or null if not found.
	 */
	public function find_user_id( string $phone ): ?int {
		// Normalise input — strip leading + for loose matching.
		$normalised = ltrim( $phone, '+' );

		// 1. Search by billing_phone user meta (exact).
		$users = get_users( [
			'meta_key'   => 'billing_phone', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value' => $phone,           // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'number'     => 1,
			'fields'     => 'ID',
		] );

		if ( ! empty( $users ) ) {
			return (int) $users[0];
		}

		// 2. Attempt with leading plus stripped.
		if ( $normalised !== $phone ) {
			$users = get_users( [
				'meta_key'   => 'billing_phone', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $normalised,      // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'     => 1,
				'fields'     => 'ID',
			] );
			if ( ! empty( $users ) ) {
				return (int) $users[0];
			}
		}

		// 3. Search via WC order billing phone (catches guest customers who
		//    later created an account, or if user meta wasn't saved).
		$customer_id = $this->find_user_via_orders( $phone, $normalised );
		if ( $customer_id ) {
			return $customer_id;
		}

		return null;
	}

	/**
	 * Build a customer profile array from a WP user ID.
	 *
	 * @param int $user_id
	 * @return array<string, mixed>
	 */
	public function get_customer_profile( int $user_id ): array {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return [];
		}

		$customer = new \WC_Customer( $user_id );

		return [
			'user_id'       => $user_id,
			'display_name'  => $customer->get_display_name(),
			'first_name'    => $customer->get_billing_first_name() ?: $user->first_name,
			'last_name'     => $customer->get_billing_last_name()  ?: $user->last_name,
			'email'         => $customer->get_billing_email()      ?: $user->user_email,
			'phone'         => $customer->get_billing_phone(),
			'city'          => $customer->get_billing_city(),
			'country'       => $customer->get_billing_country(),
			'total_orders'  => wc_get_customer_order_count( $user_id ),
			'total_spent'   => (float) wc_get_customer_total_spent( $user_id ),
			'avatar_url'    => get_avatar_url( $user_id, [ 'size' => 48 ] ),
			'registered'    => $user->user_registered,
			'edit_url'      => admin_url( 'user-edit.php?user_id=' . $user_id ),
		];
	}

	/**
	 * Build a minimal guest profile from a phone number.
	 *
	 * @param string $phone
	 * @return array<string, mixed>
	 */
	public function get_guest_profile( string $phone ): array {
		return [
			'user_id'      => null,
			'display_name' => __( 'Guest Customer', 'tsh-whatsapp-notify' ),
			'first_name'   => '',
			'last_name'    => '',
			'email'        => '',
			'phone'        => $phone,
			'total_orders' => 0,
			'total_spent'  => 0.0,
			'avatar_url'   => get_avatar_url( 0, [ 'size' => 48 ] ),
		];
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Search WC orders for a billing phone that matches.
	 *
	 * @param string $phone      E.164 phone.
	 * @param string $normalised Phone without leading +.
	 * @return int|null
	 */
	private function find_user_via_orders( string $phone, string $normalised ): ?int {
		$args = [
			'limit'      => 1,
			'orderby'    => 'date',
			'order'      => 'DESC',
			'return'     => 'ids',
			'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'OR',
				[
					'key'   => '_billing_phone',
					'value' => $phone,
				],
				[
					'key'   => '_billing_phone',
					'value' => $normalised,
				],
			],
		];

		$orders = wc_get_orders( $args );
		if ( empty( $orders ) ) {
			return null;
		}

		$order = wc_get_order( $orders[0] );
		if ( ! $order ) {
			return null;
		}

		$customer_id = $order->get_customer_id();
		return $customer_id > 0 ? $customer_id : null;
	}
}
