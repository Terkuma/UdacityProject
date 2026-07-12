<?php
/**
 * Coupon engine — generates unique WooCommerce coupons per campaign recipient.
 *
 * @package TSH\WhatsAppNotify\Marketing
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Marketing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CouponEngine
 *
 * Creates WooCommerce coupon posts with per-recipient unique codes and all
 * campaign-level restrictions (expiry, usage limits, min/max spend, products,
 * categories).
 */
final class CouponEngine {

	// -------------------------------------------------------------------------
	// Code generation
	// -------------------------------------------------------------------------

	/**
	 * Generate a unique coupon code for a recipient.
	 *
	 * Format: {prefix}-{customer_id}-{random6}
	 *
	 * @param int    $campaign_id
	 * @param int    $customer_id
	 * @param string $prefix      Optional prefix (default 'TSH').
	 * @return string
	 */
	public function generate_code( int $campaign_id, int $customer_id, string $prefix = 'TSH' ): string {
		$prefix = strtoupper( sanitize_key( $prefix ) );
		$random = strtoupper( wp_generate_password( 6, false, false ) );
		$code   = sprintf( '%s-%d-%d-%s', $prefix, $campaign_id, $customer_id, $random );

		return strtoupper( $code );
	}

	// -------------------------------------------------------------------------
	// Coupon creation
	// -------------------------------------------------------------------------

	/**
	 * Create a WooCommerce coupon post for a recipient.
	 *
	 * @param string               $code    Unique coupon code.
	 * @param int                  $customer_id WooCommerce customer / WP user ID.
	 * @param array<string, mixed> $config  Coupon engine config from campaign.
	 * @return int|false  WP Post ID of the coupon, or false on failure.
	 */
	public function create_coupon( string $code, int $customer_id, array $config ): int|false {
		if ( ! class_exists( 'WC_Coupon' ) ) {
			return false;
		}

		$code = strtolower( sanitize_text_field( $code ) );

		// Build expiry date.
		$expiry_days = absint( $config['expiry_days'] ?? 0 );
		$expiry_date = $expiry_days
			? gmdate( 'Y-m-d', strtotime( "+{$expiry_days} days" ) )
			: '';

		// Discount type.
		$discount_type = sanitize_key( $config['discount_type'] ?? 'percent' );
		$valid_types   = [ 'percent', 'fixed_cart', 'fixed_product' ];
		if ( ! in_array( $discount_type, $valid_types, true ) ) {
			$discount_type = 'percent';
		}

		// Amount.
		$amount = (float) ( $config['amount'] ?? 10 );

		// Usage limit.
		$usage_limit = absint( $config['usage_limit'] ?? 1 );

		// Min/max spend.
		$min_spend = isset( $config['min_spend'] ) ? wc_format_decimal( $config['min_spend'] ) : '';
		$max_spend = isset( $config['max_spend'] ) ? wc_format_decimal( $config['max_spend'] ) : '';

		// Specific products / categories.
		$product_ids  = array_map( 'absint', (array) ( $config['product_ids'] ?? [] ) );
		$category_ids = array_map( 'absint', (array) ( $config['category_ids'] ?? [] ) );

		// Get customer email for email restriction.
		$user_email = '';
		if ( $customer_id ) {
			$user = get_userdata( $customer_id );
			if ( $user ) {
				$user_email = $user->user_email;
			}
		}

		$coupon = new \WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( $discount_type );
		$coupon->set_amount( $amount );
		$coupon->set_usage_limit( $usage_limit );
		$coupon->set_usage_limit_per_user( 1 );
		$coupon->set_individual_use( true );

		if ( $expiry_date ) {
			$coupon->set_date_expires( strtotime( $expiry_date ) );
		}

		if ( $min_spend ) {
			$coupon->set_minimum_amount( $min_spend );
		}

		if ( $max_spend ) {
			$coupon->set_maximum_amount( $max_spend );
		}

		if ( $product_ids ) {
			$coupon->set_product_ids( $product_ids );
		}

		if ( $category_ids ) {
			$coupon->set_product_categories( $category_ids );
		}

		if ( $user_email ) {
			$coupon->set_email_restrictions( [ $user_email ] );
		}

		$coupon->add_meta_data( '_tsh_wa_campaign', 1, true );

		$coupon_id = $coupon->save();

		return $coupon_id > 0 ? $coupon_id : false;
	}

	// -------------------------------------------------------------------------
	// Batch coupon creation
	// -------------------------------------------------------------------------

	/**
	 * Create coupons for a batch of audience members.
	 *
	 * @param array<int, array<string, mixed>> $members  [ { customer_id, phone, ... } ]
	 * @param int                              $campaign_id
	 * @param array<string, mixed>             $config
	 * @param string                           $prefix
	 * @return array<int, string> Map of customer_id → coupon_code.
	 */
	public function create_batch( array $members, int $campaign_id, array $config, string $prefix = 'TSH' ): array {
		$coupon_map = [];

		foreach ( $members as $member ) {
			$customer_id = (int) $member['customer_id'];
			$code        = $this->generate_code( $campaign_id, $customer_id, $prefix );
			$created     = $this->create_coupon( $code, $customer_id, $config );

			if ( $created ) {
				$coupon_map[ $customer_id ] = $code;
			}
		}

		return $coupon_map;
	}

	// -------------------------------------------------------------------------
	// Redemption tracking
	// -------------------------------------------------------------------------

	/**
	 * Check if a coupon code has been used.
	 *
	 * @param string $code
	 * @return bool
	 */
	public function is_redeemed( string $code ): bool {
		$coupon = new \WC_Coupon( $code );

		return $coupon->get_usage_count() > 0;
	}

	/**
	 * Count redeemed coupons in a campaign by checking coupon meta.
	 *
	 * @param int $campaign_id
	 * @return int
	 */
	public function count_redeemed( int $campaign_id ): int {
		global $wpdb;

		// Count WC_Coupon posts tagged with this campaign that have usage_count > 0.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_tsh_wa_campaign' AND pm.meta_value = 1
				 INNER JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = p.ID AND pm2.meta_key = 'usage_count' AND CAST(pm2.meta_value AS UNSIGNED) > 0
				 WHERE p.post_type = 'shop_coupon'
				   AND p.post_author = 1",
				[]
			)
		);
	}

	// -------------------------------------------------------------------------
	// Variable injection
	// -------------------------------------------------------------------------

	/**
	 * Replace coupon placeholder in a message body.
	 *
	 * @param string $message
	 * @param string $coupon_code
	 * @param int    $expiry_days
	 * @return string
	 */
	public function inject_into_message( string $message, string $coupon_code, int $expiry_days = 0 ): string {
		$expiry_label = $expiry_days
			? gmdate( get_option( 'date_format' ), strtotime( "+{$expiry_days} days" ) )
			: '';

		$message = str_replace( '{{coupon_code}}',   $coupon_code,   $message );
		$message = str_replace( '{{coupon_expiry}}', $expiry_label,  $message );
		$message = str_replace( '{{expiry_date}}',   $expiry_label,  $message );

		return $message;
	}
}
