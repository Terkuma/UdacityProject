<?php
/**
 * Condition definitions and evaluation engine.
 *
 * @package TSH\WhatsAppNotify\Automation
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Automation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ConditionManager
 *
 * Defines every supported condition and evaluates them against the
 * current execution context.
 */
class ConditionManager {

	/**
	 * Return all supported condition definitions.
	 *
	 * @return array<string, array>
	 */
	public static function get_conditions(): array {
		return [
			'order_total'        => [ 'label' => 'Order Total',            'group' => 'Order',    'operators' => [ 'gt', 'gte', 'lt', 'lte', 'eq' ], 'value_type' => 'number' ],
			'order_status'       => [ 'label' => 'Order Status',           'group' => 'Order',    'operators' => [ 'is', 'is_not' ], 'value_type' => 'order_status' ],
			'item_count'         => [ 'label' => 'Order Item Count',       'group' => 'Order',    'operators' => [ 'gt', 'gte', 'lt', 'lte', 'eq' ], 'value_type' => 'number' ],
			'payment_method'     => [ 'label' => 'Payment Method',         'group' => 'Order',    'operators' => [ 'is', 'is_not' ], 'value_type' => 'text' ],
			'shipping_method'    => [ 'label' => 'Shipping Method',        'group' => 'Order',    'operators' => [ 'contains', 'not_contains' ], 'value_type' => 'text' ],
			'coupon_used'        => [ 'label' => 'Coupon Code Used',       'group' => 'Order',    'operators' => [ 'is', 'is_not', 'contains' ], 'value_type' => 'text' ],
			'product_in_order'   => [ 'label' => 'Product In Order',       'group' => 'Order',    'operators' => [ 'is', 'is_not' ], 'value_type' => 'product_ids' ],
			'category_in_order'  => [ 'label' => 'Product Category In Order', 'group' => 'Order', 'operators' => [ 'is', 'is_not' ], 'value_type' => 'category_ids' ],
			'sku_in_order'       => [ 'label' => 'SKU In Order',           'group' => 'Order',    'operators' => [ 'contains', 'not_contains' ], 'value_type' => 'text' ],
			'billing_country'    => [ 'label' => 'Billing Country',        'group' => 'Order',    'operators' => [ 'is', 'is_not' ], 'value_type' => 'country' ],
			'billing_state'      => [ 'label' => 'Billing State',          'group' => 'Order',    'operators' => [ 'is', 'is_not', 'contains' ], 'value_type' => 'text' ],
			'customer_role'      => [ 'label' => 'Customer Role',          'group' => 'Customer', 'operators' => [ 'is', 'is_not' ], 'value_type' => 'user_role' ],
			'customer_ltv'       => [ 'label' => 'Customer Lifetime Value','group' => 'Customer', 'operators' => [ 'gt', 'gte', 'lt', 'lte' ], 'value_type' => 'number' ],
			'purchase_count'     => [ 'label' => 'Total Purchase Count',   'group' => 'Customer', 'operators' => [ 'gt', 'gte', 'lt', 'lte', 'eq' ], 'value_type' => 'number' ],
			'customer_tag'       => [ 'label' => 'Customer Tag',           'group' => 'Customer', 'operators' => [ 'contains', 'not_contains' ], 'value_type' => 'text' ],
			'current_date'       => [ 'label' => 'Current Date',           'group' => 'Date/Time','operators' => [ 'before', 'after', 'eq' ], 'value_type' => 'date' ],
			'current_time'       => [ 'label' => 'Current Time',           'group' => 'Date/Time','operators' => [ 'before', 'after' ], 'value_type' => 'time' ],
			'day_of_week'        => [ 'label' => 'Day of Week',            'group' => 'Date/Time','operators' => [ 'is', 'is_not' ], 'value_type' => 'weekday' ],
		];
	}

	/**
	 * Evaluate a single condition node against the context.
	 *
	 * @param array $config {
	 *   @type string  $condition_type  Key from get_conditions()
	 *   @type string  $operator        'gt'|'gte'|'lt'|'lte'|'eq'|'is'|'is_not'|'contains'|'not_contains'|'before'|'after'
	 *   @type mixed   $value           The value to compare against
	 *   @type string  $logic           'AND'|'OR'  (for groups)
	 *   @type array   $conditions      Sub-conditions (for groups)
	 * }
	 * @param array $context  Execution context.
	 * @return bool
	 */
	public function evaluate( array $config, array $context ): bool {
		// Group of conditions.
		if ( ! empty( $config['conditions'] ) && is_array( $config['conditions'] ) ) {
			return $this->evaluate_group( $config['conditions'], $config['logic'] ?? 'AND', $context );
		}

		$type     = $config['condition_type'] ?? '';
		$operator = $config['operator'] ?? 'eq';
		$value    = $config['value'] ?? null;

		return $this->evaluate_single( $type, $operator, $value, $context );
	}

	/**
	 * Evaluate a group of conditions.
	 *
	 * @param array  $conditions
	 * @param string $logic 'AND'|'OR'
	 * @param array  $context
	 * @return bool
	 */
	public function evaluate_group( array $conditions, string $logic, array $context ): bool {
		$logic = strtoupper( $logic ) === 'OR' ? 'OR' : 'AND';

		foreach ( $conditions as $condition ) {
			$result = $this->evaluate( $condition, $context );

			if ( 'OR' === $logic && $result ) {
				return true;
			}
			if ( 'AND' === $logic && ! $result ) {
				return false;
			}
		}

		return 'AND' === $logic;
	}

	// -------------------------------------------------------------------------
	// Single condition evaluators
	// -------------------------------------------------------------------------

	private function evaluate_single( string $type, string $operator, $value, array $context ): bool {
		$order_id    = (int) ( $context['order_id'] ?? $context['trigger_data']['order_id'] ?? 0 );
		$customer_id = (int) ( $context['customer_id'] ?? $context['trigger_data']['customer_id'] ?? 0 );

		switch ( $type ) {
			case 'order_total':
				if ( ! $order_id ) { return false; }
				$order = wc_get_order( $order_id );
				if ( ! $order ) { return false; }
				return $this->compare_numeric( (float) $order->get_total(), $operator, (float) $value );

			case 'order_status':
				if ( ! $order_id ) { return false; }
				$order = wc_get_order( $order_id );
				if ( ! $order ) { return false; }
				return $this->compare_string( $order->get_status(), $operator, (string) $value );

			case 'item_count':
				if ( ! $order_id ) { return false; }
				$order = wc_get_order( $order_id );
				if ( ! $order ) { return false; }
				return $this->compare_numeric( (float) $order->get_item_count(), $operator, (float) $value );

			case 'payment_method':
				if ( ! $order_id ) { return false; }
				$order = wc_get_order( $order_id );
				if ( ! $order ) { return false; }
				return $this->compare_string( $order->get_payment_method(), $operator, (string) $value );

			case 'shipping_method':
				if ( ! $order_id ) { return false; }
				$order = wc_get_order( $order_id );
				if ( ! $order ) { return false; }
				$methods = $order->get_shipping_methods();
				$method  = reset( $methods );
				$title   = $method ? $method->get_method_id() : '';
				return $this->compare_string( $title, $operator, (string) $value );

			case 'coupon_used':
				if ( ! $order_id ) { return false; }
				$order = wc_get_order( $order_id );
				if ( ! $order ) { return false; }
				$codes = implode( ',', $order->get_coupon_codes() );
				return $this->compare_string( $codes, $operator, (string) $value );

			case 'billing_country':
				if ( ! $order_id ) { return false; }
				$order = wc_get_order( $order_id );
				if ( ! $order ) { return false; }
				return $this->compare_string( $order->get_billing_country(), $operator, (string) $value );

			case 'billing_state':
				if ( ! $order_id ) { return false; }
				$order = wc_get_order( $order_id );
				if ( ! $order ) { return false; }
				return $this->compare_string( $order->get_billing_state(), $operator, (string) $value );

			case 'product_in_order':
				if ( ! $order_id ) { return false; }
				$order = wc_get_order( $order_id );
				if ( ! $order ) { return false; }
				$ids = (array) $value;
				foreach ( $order->get_items() as $item ) {
					/** @var \WC_Order_Item_Product $item */
					if ( in_array( $item->get_product_id(), $ids, false ) ) {
						return 'is_not' !== $operator;
					}
				}
				return 'is_not' === $operator;

			case 'category_in_order':
				if ( ! $order_id ) { return false; }
				$order = wc_get_order( $order_id );
				if ( ! $order ) { return false; }
				$cat_ids = (array) $value;
				foreach ( $order->get_items() as $item ) {
					/** @var \WC_Order_Item_Product $item */
					$terms = get_the_terms( $item->get_product_id(), 'product_cat' );
					if ( $terms ) {
						foreach ( $terms as $term ) {
							if ( in_array( $term->term_id, $cat_ids, false ) ) {
								return 'is_not' !== $operator;
							}
						}
					}
				}
				return 'is_not' === $operator;

			case 'sku_in_order':
				if ( ! $order_id ) { return false; }
				$order = wc_get_order( $order_id );
				if ( ! $order ) { return false; }
				$skus = [];
				foreach ( $order->get_items() as $item ) {
					/** @var \WC_Order_Item_Product $item */
					$product = $item->get_product();
					if ( $product ) {
						$skus[] = $product->get_sku();
					}
				}
				$skus_str = implode( ',', $skus );
				return $this->compare_string( $skus_str, $operator, (string) $value );

			case 'customer_role':
				$uid  = $customer_id ?: ( $order_id ? ( wc_get_order( $order_id )?->get_customer_id() ?: 0 ) : 0 );
				$user = $uid ? get_user_by( 'id', $uid ) : null;
				if ( ! $user ) {
					return 'is_not' === $operator;
				}
				$roles = implode( ',', $user->roles );
				return $this->compare_string( $roles, $operator, (string) $value );

			case 'customer_ltv':
				$uid = $customer_id ?: ( $order_id ? ( wc_get_order( $order_id )?->get_customer_id() ?: 0 ) : 0 );
				if ( ! $uid ) { return false; }
				$customer = new \WC_Customer( $uid );
				return $this->compare_numeric( (float) $customer->get_total_spent(), $operator, (float) $value );

			case 'purchase_count':
				$uid = $customer_id ?: ( $order_id ? ( wc_get_order( $order_id )?->get_customer_id() ?: 0 ) : 0 );
				if ( ! $uid ) { return false; }
				$customer = new \WC_Customer( $uid );
				return $this->compare_numeric( (float) $customer->get_order_count(), $operator, (float) $value );

			case 'customer_tag':
				$uid  = $customer_id ?: ( $order_id ? ( wc_get_order( $order_id )?->get_customer_id() ?: 0 ) : 0 );
				if ( ! $uid ) { return false; }
				$tags = implode( ',', wp_list_pluck( wp_get_object_terms( $uid, 'user_tag' ) ?: [], 'name' ) );
				return $this->compare_string( $tags, $operator, (string) $value );

			case 'current_date':
				$now  = (int) current_time( 'timestamp' );
				$comp = $value ? (int) strtotime( (string) $value ) : 0;
				return $comp ? $this->compare_numeric( (float) $now, $operator, (float) $comp ) : false;

			case 'current_time':
				$now_ts = (int) current_time( 'timestamp' );
				$today  = current_time( 'Y-m-d' );
				$comp   = $value ? (int) strtotime( $today . ' ' . (string) $value ) : 0;
				return $comp ? $this->compare_numeric( (float) $now_ts, $operator, (float) $comp ) : false;

			case 'day_of_week':
				// value: 0=Sunday … 6=Saturday.
				$dow = (int) gmdate( 'w', (int) current_time( 'timestamp' ) );
				return $this->compare_string( (string) $dow, $operator, (string) $value );

			default:
				return true; // Unknown condition — pass through.
		}
	}

	// -------------------------------------------------------------------------
	// Comparison helpers
	// -------------------------------------------------------------------------

	private function compare_numeric( float $actual, string $operator, float $expected ): bool {
		switch ( $operator ) {
			case 'gt':    return $actual >  $expected;
			case 'gte':   return $actual >= $expected;
			case 'lt':    return $actual <  $expected;
			case 'lte':   return $actual <= $expected;
			case 'eq':
			case 'is':    return abs( $actual - $expected ) < 0.001;
			case 'is_not':return abs( $actual - $expected ) >= 0.001;
			case 'before':return $actual < $expected;
			case 'after': return $actual > $expected;
			default:      return false;
		}
	}

	private function compare_string( string $actual, string $operator, string $expected ): bool {
		switch ( $operator ) {
			case 'is':
			case 'eq':
				return strtolower( $actual ) === strtolower( $expected );
			case 'is_not':
				return strtolower( $actual ) !== strtolower( $expected );
			case 'contains':
				return str_contains( strtolower( $actual ), strtolower( $expected ) );
			case 'not_contains':
				return ! str_contains( strtolower( $actual ), strtolower( $expected ) );
			case 'before':
			case 'after':
				// String date comparison.
				$ts_actual   = strtotime( $actual ) ?: 0;
				$ts_expected = strtotime( $expected ) ?: 0;
				return 'before' === $operator ? $ts_actual < $ts_expected : $ts_actual > $ts_expected;
			default:
				return false;
		}
	}
}
