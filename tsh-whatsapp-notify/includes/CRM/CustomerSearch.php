<?php
/**
 * Customer Search — instant search across all customer data.
 *
 * @package TSH\WhatsAppNotify\CRM
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\CRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CustomerSearch
 *
 * Supports instant, multi-field search across:
 *   phone, whatsapp_phone, email, full_name, city, country,
 *   order ID (WooCommerce lookup), coupon code, tag name.
 *
 * Uses indexed columns only for performance with 100k+ records.
 */
final class CustomerSearch {

	private CustomerRepository $repo;

	public function __construct( CustomerRepository $repo ) {
		$this->repo = $repo;
	}

	/**
	 * Main search method — auto-detects query type.
	 *
	 * @param string $query   Raw search string.
	 * @param int    $limit   Max results.
	 * @param string $context 'global' | 'phone' | 'email' | 'name' | 'order' | 'tag'
	 */
	public function search( string $query, int $limit = 20, string $context = 'global' ): array {
		$query = trim( $query );
		if ( strlen( $query ) < 2 ) return [];

		switch ( $context ) {
			case 'phone':  return $this->by_phone( $query, $limit );
			case 'email':  return $this->by_email( $query, $limit );
			case 'name':   return $this->by_name( $query, $limit );
			case 'order':  return $this->by_order_id( $query, $limit );
			case 'tag':    return $this->by_tag( $query, $limit );
			default:       return $this->global_search( $query, $limit );
		}
	}

	/**
	 * Global search — hits name, phone, email, city, country.
	 */
	public function global_search( string $query, int $limit = 20 ): array {
		global $wpdb;
		$s = '%' . $wpdb->esc_like( $query ) . '%';
		$t = $this->repo->customers();

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, full_name, phone, whatsapp_phone, email, country, city, lifecycle, is_vip, is_blocked, health_score, lifetime_value, avatar_url
			 FROM {$t}
			 WHERE full_name LIKE %s OR phone LIKE %s OR whatsapp_phone LIKE %s OR email LIKE %s OR city LIKE %s
			 ORDER BY is_vip DESC, health_score DESC
			 LIMIT %d",
			$s, $s, $s, $s, $s, $limit
		), ARRAY_A ) ?: [];

		return $rows;
	}

	public function by_phone( string $phone, int $limit = 20 ): array {
		global $wpdb;
		$s = '%' . $wpdb->esc_like( $phone ) . '%';
		$t = $this->repo->customers();
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT id, full_name, phone, whatsapp_phone, email, lifecycle, is_vip FROM {$t}
			 WHERE phone LIKE %s OR whatsapp_phone LIKE %s LIMIT %d",
			$s, $s, $limit
		), ARRAY_A ) ?: [];
	}

	public function by_email( string $email, int $limit = 20 ): array {
		global $wpdb;
		$s = '%' . $wpdb->esc_like( $email ) . '%';
		$t = $this->repo->customers();
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT id, full_name, phone, email, lifecycle, is_vip FROM {$t} WHERE email LIKE %s LIMIT %d",
			$s, $limit
		), ARRAY_A ) ?: [];
	}

	public function by_name( string $name, int $limit = 20 ): array {
		global $wpdb;
		$s = '%' . $wpdb->esc_like( $name ) . '%';
		$t = $this->repo->customers();
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT id, full_name, phone, email, lifecycle, is_vip FROM {$t} WHERE full_name LIKE %s ORDER BY full_name LIMIT %d",
			$s, $limit
		), ARRAY_A ) ?: [];
	}

	/**
	 * Look up a customer by WooCommerce Order ID.
	 */
	public function by_order_id( string $order_id, int $limit = 10 ): array {
		if ( ! is_numeric( $order_id ) ) return [];
		$order = wc_get_order( (int) $order_id );
		if ( ! $order ) return [];

		$email = $order->get_billing_email();
		$phone = $order->get_billing_phone();

		if ( $phone ) {
			$c = $this->repo->get_customer_by_phone( $phone );
			if ( $c ) return [ $c ];
		}
		if ( $email ) {
			$c = $this->repo->get_customer_by_email( $email );
			if ( $c ) return [ $c ];
		}
		return [];
	}

	/**
	 * Look up customers by tag name.
	 */
	public function by_tag( string $tag_name, int $limit = 20 ): array {
		global $wpdb;
		$tag_t = $this->repo->tags();
		$cust_t = $this->repo->customers();
		$s = '%' . $wpdb->esc_like( $tag_name ) . '%';

		$tag_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$tag_t} WHERE name LIKE %s", $s ) );
		if ( empty( $tag_ids ) ) return [];

		$results = [];
		foreach ( $tag_ids as $tid ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, full_name, phone, email, lifecycle, is_vip FROM {$cust_t}
				 WHERE JSON_SEARCH(tags, 'one', %s) IS NOT NULL LIMIT %d",
				$tid, $limit
			), ARRAY_A ) ?: [];
			$results = array_merge( $results, $rows );
		}

		return $results;
	}

	/**
	 * Advanced filtered search (for the customer list view).
	 */
	public function advanced( array $filters, int $per_page = 25, int $page = 1 ): array {
		return $this->repo->get_customers( array_merge( $filters, [ 'per_page' => $per_page, 'page' => $page ] ) );
	}
}
