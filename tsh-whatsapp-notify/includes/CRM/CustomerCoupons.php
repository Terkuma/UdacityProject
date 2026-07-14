<?php
/**
 * Customer Coupons — coupon usage history for CRM customers.
 *
 * @package TSH\WhatsAppNotify\CRM
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\CRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CustomerCoupons
 *
 * Reads WooCommerce coupon usage data for a given CRM customer.
 */
final class CustomerCoupons {

	private CustomerRepository $repo;

	public function __construct( CustomerRepository $repo ) {
		$this->repo = $repo;
	}

	/**
	 * Summary for profile sidebar.
	 */
	public function get_summary( array $customer ): array {
		global $wpdb;

		$email = $customer['email'] ?? '';
		if ( ! $email ) return [ 'total' => 0 ];

		// Query woocommerce coupon usage from order items meta
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT oim.order_item_id)
			 FROM {$wpdb->prefix}woocommerce_order_itemmeta oim
			 INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_item_id = oim.order_item_id
			 INNER JOIN {$wpdb->posts} p ON p.ID = oi.order_id
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_billing_email' AND pm.meta_value = %s
			 WHERE oi.order_item_type = 'coupon'",
			$email
		) );

		return [ 'total' => $count ];
	}

	/**
	 * Timeline events from coupon usage.
	 */
	public function get_timeline_events( array $customer ): array {
		global $wpdb;

		$email = $customer['email'] ?? '';
		if ( ! $email ) return [];

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT oi.order_item_name as coupon_code, p.post_date as used_at, p.ID as order_id
			 FROM {$wpdb->prefix}woocommerce_order_items oi
			 INNER JOIN {$wpdb->posts} p ON p.ID = oi.order_id
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_billing_email' AND pm.meta_value = %s
			 WHERE oi.order_item_type = 'coupon'
			 ORDER BY p.post_date DESC LIMIT 50",
			$email
		), ARRAY_A ) ?: [];

		$events = [];
		foreach ( $rows as $r ) {
			$events[] = [
				'id'          => 'coupon_' . $r['order_id'] . '_' . md5( $r['coupon_code'] ),
				'type'        => CustomerActivity::TYPE_COUPON_REDEEMED,
				'label'       => __( 'Coupon Redeemed', 'tsh-whatsapp-notify' ),
				'icon'        => '🎟️',
				'subject'     => sprintf( __( 'Coupon used: %s', 'tsh-whatsapp-notify' ), $r['coupon_code'] ),
				'description' => sprintf( __( 'On order #%d', 'tsh-whatsapp-notify' ), $r['order_id'] ),
				'data'        => [ 'coupon_code' => $r['coupon_code'], 'order_id' => $r['order_id'] ],
				'created_at'  => $r['used_at'],
				'source'      => 'coupon',
			];
		}
		return $events;
	}
}
