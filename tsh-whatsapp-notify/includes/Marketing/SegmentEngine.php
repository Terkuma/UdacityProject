<?php
/**
 * Segment engine — evaluates segment rules against WooCommerce customer data.
 *
 * @package TSH\WhatsAppNotify\Marketing
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Marketing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SegmentEngine
 *
 * Translates the declarative audience_config rule set into SQL WHERE clauses
 * that run against WooCommerce customers and orders.
 *
 * All queries are HPOS-compatible; they target wc_orders or posts depending on
 * which storage engine WooCommerce is using.
 */
final class SegmentEngine {

	// -------------------------------------------------------------------------
	// Main entry point
	// -------------------------------------------------------------------------

	/**
	 * Build the SQL query that returns matching customer IDs + phone + email + name.
	 *
	 * @param array<string, mixed> $audience_config
	 * @return string  Complete SQL query string (ready for $wpdb->get_results).
	 */
	public function build_query( array $audience_config ): string {
		global $wpdb;

		$type  = $audience_config['type'] ?? 'all_customers';
		$rules = $audience_config['rules'] ?? [];
		$logic = strtoupper( $audience_config['logic'] ?? 'AND' );
		if ( ! in_array( $logic, [ 'AND', 'OR' ], true ) ) {
			$logic = 'AND';
		}

		$base  = $this->base_customer_query();
		$where = $this->type_to_where( $type, $audience_config );
		$rule_clauses = $this->rules_to_clauses( $rules );

		if ( $rule_clauses ) {
			$combined = '(' . implode( " {$logic} ", $rule_clauses ) . ')';
			$where    = $where ? "({$where}) AND {$combined}" : $combined;
		}

		$sql = $base;
		if ( $where ) {
			$sql .= " AND ({$where})";
		}

		$sql .= ' GROUP BY u.ID ORDER BY u.ID ASC';

		return $sql;
	}

	// -------------------------------------------------------------------------
	// Base query — selects customers with billing phone
	// -------------------------------------------------------------------------

	private function base_customer_query(): string {
		global $wpdb;

		return "
			SELECT DISTINCT
				u.ID AS customer_id,
				MAX(CASE WHEN um.meta_key = 'billing_phone' THEN um.meta_value END) AS phone,
				u.user_email AS email,
				CONCAT(
					COALESCE(MAX(CASE WHEN um.meta_key = 'first_name' THEN um.meta_value END), ''),
					' ',
					COALESCE(MAX(CASE WHEN um.meta_key = 'last_name'  THEN um.meta_value END), '')
				) AS name
			FROM {$wpdb->users} u
			INNER JOIN {$wpdb->usermeta} um ON um.user_id = u.ID
			WHERE u.ID > 0
		";
	}

	// -------------------------------------------------------------------------
	// Named audience types → WHERE clauses
	// -------------------------------------------------------------------------

	/**
	 * @param string               $type
	 * @param array<string, mixed> $config
	 * @return string  SQL fragment (no leading WHERE keyword).
	 */
	private function type_to_where( string $type, array $config ): string {
		global $wpdb;

		switch ( $type ) {
			case 'all_customers':
				return "EXISTS (SELECT 1 FROM {$wpdb->usermeta} um2 WHERE um2.user_id = u.ID AND um2.meta_key = 'wc_order_count' AND CAST(um2.meta_value AS UNSIGNED) >= 0)";

			case 'previous_buyers':
				return $this->has_orders_clause( 1 );

			case 'first_purchase':
				return $this->has_orders_clause( 1 ) . ' AND ' . $this->max_orders_clause( 1 );

			case 'repeat_buyers':
				return $this->has_orders_clause( 2 );

			case 'never_purchased':
				return $this->has_orders_clause( 0, false );

			case 'vip_customers':
				$threshold = (float) ( $config['vip_threshold'] ?? 1000 );
				return $this->lifetime_value_clause( $threshold, '>=' );

			case 'high_spend':
				$threshold = (float) ( $config['spend_min'] ?? 500 );
				return $this->lifetime_value_clause( $threshold, '>=' );

			case 'low_spend':
				$max = (float) ( $config['spend_max'] ?? 100 );
				return $this->has_orders_clause( 1 ) . ' AND ' . $this->lifetime_value_clause( $max, '<=' );

			case 'by_product':
				$product_ids = array_map( 'absint', (array) ( $config['product_ids'] ?? [] ) );
				return $product_ids ? $this->bought_product_clause( $product_ids ) : '1=1';

			case 'by_category':
				$cat_ids = array_map( 'absint', (array) ( $config['category_ids'] ?? [] ) );
				return $cat_ids ? $this->bought_category_clause( $cat_ids ) : '1=1';

			case 'by_country':
				$countries = array_map( 'sanitize_text_field', (array) ( $config['countries'] ?? [] ) );
				return $countries ? $this->meta_in_clause( 'billing_country', $countries ) : '1=1';

			case 'by_state':
				$states = array_map( 'sanitize_text_field', (array) ( $config['states'] ?? [] ) );
				return $states ? $this->meta_in_clause( 'billing_state', $states ) : '1=1';

			case 'by_city':
				$cities = array_map( 'sanitize_text_field', (array) ( $config['cities'] ?? [] ) );
				return $cities ? $this->meta_in_clause( 'billing_city', $cities ) : '1=1';

			case 'by_payment_method':
				$methods = array_map( 'sanitize_key', (array) ( $config['payment_methods'] ?? [] ) );
				return $methods ? $this->order_meta_in_clause( '_payment_method', $methods ) : '1=1';

			case 'by_role':
				$roles = array_map( 'sanitize_key', (array) ( $config['roles'] ?? [] ) );
				return $roles ? $this->role_clause( $roles ) : '1=1';

			case 'by_registration':
				$days = (int) ( $config['registration_days'] ?? 30 );
				return $wpdb->prepare(
					'u.user_registered >= DATE_SUB(NOW(), INTERVAL %d DAY)',
					$days
				);

			case 'by_last_purchase':
				$days = (int) ( $config['last_purchase_days'] ?? 30 );
				return $this->last_purchase_within_clause( $days );

			case 'by_lifetime_value':
				$min = (float) ( $config['ltv_min'] ?? 0 );
				$max = (float) ( $config['ltv_max'] ?? PHP_FLOAT_MAX );
				return $this->lifetime_value_clause( $min, '>=' ) . ' AND ' . $this->lifetime_value_clause( $max, '<=' );

			case 'saved_segment':
			case 'custom':
			default:
				return '1=1';
		}
	}

	// -------------------------------------------------------------------------
	// Generic rule conditions
	// -------------------------------------------------------------------------

	/**
	 * Convert a rules array to SQL clause strings.
	 *
	 * @param array<int, array<string, mixed>> $rules
	 * @return array<string>
	 */
	private function rules_to_clauses( array $rules ): array {
		$clauses = [];
		foreach ( $rules as $rule ) {
			$clause = $this->rule_to_clause( $rule );
			if ( $clause ) {
				$clauses[] = $clause;
			}
		}

		return $clauses;
	}

	/**
	 * @param array<string, mixed> $rule
	 * @return string|null
	 */
	private function rule_to_clause( array $rule ): ?string {
		global $wpdb;

		$field    = $rule['field']    ?? '';
		$operator = $rule['operator'] ?? 'equals';
		$value    = $rule['value']    ?? '';

		switch ( $field ) {
			case 'order_count':
				$count = (int) $value;
				$op    = $this->map_numeric_operator( $operator );
				return $wpdb->prepare(
					"(SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_order_items oi
					  INNER JOIN {$wpdb->prefix}posts o ON o.ID = oi.order_id
					  WHERE o.post_status IN ('wc-completed','wc-processing')
					    AND o.post_author = u.ID) {$op} %d",
					$count
				);

			case 'lifetime_value':
				$amount = (float) $value;
				$op     = $this->map_numeric_operator( $operator );
				return $wpdb->prepare(
					"COALESCE((SELECT SUM(pm.meta_value) FROM {$wpdb->postmeta} pm
					  INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					  WHERE pm.meta_key = '_order_total'
					    AND p.post_type = 'shop_order'
					    AND p.post_status IN ('wc-completed','wc-processing')
					    AND p.post_author = u.ID), 0) {$op} %f",
					$amount
				);

			case 'registration_date':
				return $this->date_rule_clause( 'u.user_registered', $operator, $value );

			case 'last_purchase':
				return $this->date_rule_clause(
					"(SELECT MAX(p.post_date) FROM {$wpdb->posts} p WHERE p.post_type='shop_order' AND p.post_status IN ('wc-completed','wc-processing') AND p.post_author=u.ID)",
					$operator,
					$value
				);

			case 'first_order_date':
				return $this->date_rule_clause(
					"(SELECT MIN(p.post_date) FROM {$wpdb->posts} p WHERE p.post_type='shop_order' AND p.post_status IN ('wc-completed','wc-processing') AND p.post_author=u.ID)",
					$operator,
					$value
				);

			case 'billing_country':
			case 'billing_state':
			case 'billing_city':
				$meta_key = sanitize_key( $field );
				$meta_val = sanitize_text_field( (string) $value );
				if ( 'equals' === $operator ) {
					return $wpdb->prepare(
						"EXISTS (SELECT 1 FROM {$wpdb->usermeta} umx WHERE umx.user_id = u.ID AND umx.meta_key = %s AND umx.meta_value = %s)",
						$meta_key,
						$meta_val
					);
				}
				return null;

			default:
				return null;
		}
	}

	// -------------------------------------------------------------------------
	// Reusable SQL clause builders
	// -------------------------------------------------------------------------

	private function has_orders_clause( int $min, bool $at_least = true ): string {
		global $wpdb;

		$op  = $at_least ? '>=' : '=';
		$cmp = $at_least ? $wpdb->prepare( '>= %d', $min ) : '= 0';

		return "
			(SELECT COUNT(*) FROM {$wpdb->posts} p
			 WHERE p.post_type = 'shop_order'
			   AND p.post_status IN ('wc-completed','wc-processing')
			   AND p.post_author = u.ID) {$cmp}
		";
	}

	private function max_orders_clause( int $max ): string {
		global $wpdb;

		return $wpdb->prepare(
			"(SELECT COUNT(*) FROM {$wpdb->posts} p
			  WHERE p.post_type = 'shop_order'
			    AND p.post_status IN ('wc-completed','wc-processing')
			    AND p.post_author = u.ID) <= %d",
			$max
		);
	}

	private function lifetime_value_clause( float $amount, string $op = '>=' ): string {
		global $wpdb;

		$safe_op = in_array( $op, [ '>=', '<=', '>', '<', '=' ], true ) ? $op : '>=';
		return $wpdb->prepare(
			"COALESCE((SELECT SUM(pm.meta_value)
			   FROM {$wpdb->postmeta} pm
			   INNER JOIN {$wpdb->posts} p2 ON p2.ID = pm.post_id
			   WHERE pm.meta_key = '_order_total'
			     AND p2.post_type = 'shop_order'
			     AND p2.post_status IN ('wc-completed','wc-processing')
			     AND p2.post_author = u.ID), 0) {$safe_op} %f",
			$amount
		);
	}

	private function bought_product_clause( array $product_ids ): string {
		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->prepare(
			"EXISTS (
				SELECT 1 FROM {$wpdb->prefix}woocommerce_order_items oi
				INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oim.order_item_id = oi.order_item_id
				INNER JOIN {$wpdb->posts} p ON p.ID = oi.order_id
				WHERE p.post_author = u.ID
				  AND p.post_status IN ('wc-completed','wc-processing')
				  AND oim.meta_key = '_product_id'
				  AND oim.meta_value IN ({$placeholders})
			)",
			...$product_ids
		);
	}

	private function bought_category_clause( array $cat_ids ): string {
		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $cat_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->prepare(
			"EXISTS (
				SELECT 1 FROM {$wpdb->prefix}woocommerce_order_items oi
				INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oim.order_item_id = oi.order_item_id
				INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = oim.meta_value
				INNER JOIN {$wpdb->posts} p ON p.ID = oi.order_id
				WHERE p.post_author = u.ID
				  AND p.post_status IN ('wc-completed','wc-processing')
				  AND oim.meta_key = '_product_id'
				  AND tr.term_taxonomy_id IN ({$placeholders})
			)",
			...$cat_ids
		);
	}

	private function meta_in_clause( string $meta_key, array $values ): string {
		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $values ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->prepare(
			"EXISTS (SELECT 1 FROM {$wpdb->usermeta} umx WHERE umx.user_id = u.ID AND umx.meta_key = %s AND umx.meta_value IN ({$placeholders}))",
			array_merge( [ $meta_key ], $values )
		);
	}

	private function order_meta_in_clause( string $meta_key, array $values ): string {
		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $values ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->prepare(
			"EXISTS (
				SELECT 1 FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				WHERE p.post_author = u.ID
				  AND p.post_type = 'shop_order'
				  AND p.post_status IN ('wc-completed','wc-processing')
				  AND pm.meta_key = %s
				  AND pm.meta_value IN ({$placeholders})
			)",
			array_merge( [ $meta_key ], $values )
		);
	}

	private function role_clause( array $roles ): string {
		global $wpdb;

		$clauses = [];
		foreach ( $roles as $role ) {
			$clauses[] = $wpdb->prepare(
				"EXISTS (SELECT 1 FROM {$wpdb->usermeta} umx WHERE umx.user_id = u.ID AND umx.meta_key = %s AND umx.meta_value LIKE %s)",
				$wpdb->prefix . 'capabilities',
				'%"' . $role . '"%'
			);
		}

		return '(' . implode( ' OR ', $clauses ) . ')';
	}

	private function last_purchase_within_clause( int $days ): string {
		global $wpdb;

		return $wpdb->prepare(
			"EXISTS (
				SELECT 1 FROM {$wpdb->posts} p
				WHERE p.post_author = u.ID
				  AND p.post_type = 'shop_order'
				  AND p.post_status IN ('wc-completed','wc-processing')
				  AND p.post_date >= DATE_SUB(NOW(), INTERVAL %d DAY)
			)",
			$days
		);
	}

	private function date_rule_clause( string $date_expr, string $operator, mixed $value ): ?string {
		global $wpdb;

		switch ( $operator ) {
			case 'within_days':
				return $wpdb->prepare( "({$date_expr}) >= DATE_SUB(NOW(), INTERVAL %d DAY)", (int) $value );
			case 'more_than_days_ago':
				return $wpdb->prepare( "({$date_expr}) < DATE_SUB(NOW(), INTERVAL %d DAY)", (int) $value );
			case 'before':
				return $wpdb->prepare( "({$date_expr}) < %s", sanitize_text_field( (string) $value ) );
			case 'after':
				return $wpdb->prepare( "({$date_expr}) > %s", sanitize_text_field( (string) $value ) );
			default:
				return null;
		}
	}

	private function map_numeric_operator( string $operator ): string {
		return match ( $operator ) {
			'greater_than'         => '>',
			'less_than'            => '<',
			'greater_than_or_equal'=> '>=',
			'less_than_or_equal'   => '<=',
			'equals'               => '=',
			'not_equals'           => '!=',
			default                => '=',
		};
	}
}
