<?php
/**
 * Trigger definitions and WC/WP hook registration.
 *
 * @package TSH\WhatsAppNotify\Automation
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Automation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TriggerManager
 *
 * Knows about every supported trigger type and handles registering
 * the corresponding WordPress/WooCommerce action hooks so that
 * AutomationEngine can be notified when they fire.
 */
class TriggerManager {

	/**
	 * Return all supported trigger definitions.
	 *
	 * @return array<string, array{ label: string, group: string, fields: array, description: string }>
	 */
	public static function get_triggers(): array {
		return [
			// ---- WooCommerce Order ----
			'wc_order_new'            => [
				'label'       => 'New Order Created',
				'group'       => 'WooCommerce',
				'icon'        => '🛒',
				'description' => 'Fires when a new order is placed (any status).',
				'fields'      => [],
			],
			'wc_order_processing'     => [
				'label'       => 'Order Processing',
				'group'       => 'WooCommerce',
				'icon'        => '⚙️',
				'description' => 'Fires when an order moves to Processing status.',
				'fields'      => [],
			],
			'wc_order_completed'      => [
				'label'       => 'Order Completed',
				'group'       => 'WooCommerce',
				'icon'        => '✅',
				'description' => 'Fires when an order is marked Completed.',
				'fields'      => [],
			],
			'wc_order_refunded'       => [
				'label'       => 'Order Refunded',
				'group'       => 'WooCommerce',
				'icon'        => '↩️',
				'description' => 'Fires when a full or partial refund is issued.',
				'fields'      => [],
			],
			'wc_order_cancelled'      => [
				'label'       => 'Order Cancelled',
				'group'       => 'WooCommerce',
				'icon'        => '❌',
				'description' => 'Fires when an order is cancelled.',
				'fields'      => [],
			],
			'wc_order_failed'         => [
				'label'       => 'Order Payment Failed',
				'group'       => 'WooCommerce',
				'icon'        => '💳',
				'description' => 'Fires when order payment fails.',
				'fields'      => [],
			],
			'wc_order_on_hold'        => [
				'label'       => 'Order On Hold',
				'group'       => 'WooCommerce',
				'icon'        => '⏸️',
				'description' => 'Fires when an order is put on hold.',
				'fields'      => [],
			],
			'wc_order_note_added'     => [
				'label'       => 'Order Note Added',
				'group'       => 'WooCommerce',
				'icon'        => '📝',
				'description' => 'Fires when a note is added to an order.',
				'fields'      => [
					[ 'key' => 'note_type', 'label' => 'Note Type', 'type' => 'select',
					  'options' => [ 'any' => 'Any', 'customer' => 'Customer Note', 'internal' => 'Internal Note' ] ],
				],
			],
			'wc_product_purchased'    => [
				'label'       => 'Specific Product Purchased',
				'group'       => 'WooCommerce',
				'icon'        => '📦',
				'description' => 'Fires when a specific product is in the completed order.',
				'fields'      => [
					[ 'key' => 'product_ids', 'label' => 'Products', 'type' => 'product_select', 'multiple' => true ],
				],
			],
			'wc_category_purchased'   => [
				'label'       => 'Category Purchased',
				'group'       => 'WooCommerce',
				'icon'        => '🏷️',
				'description' => 'Fires when an order contains a product from a specific category.',
				'fields'      => [
					[ 'key' => 'category_ids', 'label' => 'Categories', 'type' => 'category_select', 'multiple' => true ],
				],
			],
			'wc_coupon_used'          => [
				'label'       => 'Coupon Used',
				'group'       => 'WooCommerce',
				'icon'        => '🎟️',
				'description' => 'Fires when an order uses a specific coupon code.',
				'fields'      => [
					[ 'key' => 'coupon_code', 'label' => 'Coupon Code', 'type' => 'text', 'placeholder' => 'Leave empty for any coupon' ],
				],
			],
			// ---- WooCommerce Stock ----
			'wc_low_stock'            => [
				'label'       => 'Low Stock Alert',
				'group'       => 'WooCommerce',
				'icon'        => '⚠️',
				'description' => 'Fires when a product drops below its low-stock threshold.',
				'fields'      => [],
			],
			'wc_out_of_stock'         => [
				'label'       => 'Out of Stock',
				'group'       => 'WooCommerce',
				'icon'        => '🚫',
				'description' => 'Fires when a product goes out of stock.',
				'fields'      => [],
			],
			'wc_back_in_stock'        => [
				'label'       => 'Back In Stock',
				'group'       => 'WooCommerce',
				'icon'        => '🔄',
				'description' => 'Fires when a previously out-of-stock product becomes available.',
				'fields'      => [],
			],
			// ---- Customer ----
			'customer_registered'     => [
				'label'       => 'Customer Registered',
				'group'       => 'Customer',
				'icon'        => '👤',
				'description' => 'Fires when a new WordPress user registers.',
				'fields'      => [],
			],
			'customer_login'          => [
				'label'       => 'Customer Login',
				'group'       => 'Customer',
				'icon'        => '🔑',
				'description' => 'Fires when a registered user logs in.',
				'fields'      => [],
			],
			// ---- Custom ----
			'custom_hook'             => [
				'label'       => 'Custom WordPress Hook',
				'group'       => 'Advanced',
				'icon'        => '🔧',
				'description' => 'Attach to any WordPress action hook by name.',
				'fields'      => [
					[ 'key' => 'hook_name', 'label' => 'Hook Name', 'type' => 'text', 'required' => true, 'placeholder' => 'e.g. my_plugin_event' ],
					[ 'key' => 'priority', 'label' => 'Priority', 'type' => 'number', 'default' => 10 ],
				],
			],
		];
	}

	/**
	 * Get a single trigger definition.
	 */
	public static function get_trigger( string $type ): ?array {
		return self::get_triggers()[ $type ] ?? null;
	}

	// -------------------------------------------------------------------------
	// Hook registration
	// -------------------------------------------------------------------------

	/**
	 * Register all WC/WP action hooks.
	 *
	 * Each hook calls back $callback( string $trigger_type, array $trigger_data ).
	 *
	 * @param callable $callback
	 * @param array    $active_triggers  List of trigger types that have at least one active workflow.
	 */
	public function register_hooks( callable $callback, array $active_triggers = [] ): void {
		// If no active workflows, no hooks needed.
		if ( empty( $active_triggers ) ) {
			return;
		}

		$has = static fn( string $t ) => in_array( $t, $active_triggers, true );

		// WooCommerce order status transitions.
		$order_status_map = [
			'pending'    => 'wc_order_new',
			'processing' => 'wc_order_processing',
			'completed'  => 'wc_order_completed',
			'refunded'   => 'wc_order_refunded',
			'cancelled'  => 'wc_order_cancelled',
			'failed'     => 'wc_order_failed',
			'on-hold'    => 'wc_order_on_hold',
		];

		foreach ( $order_status_map as $status => $trigger_type ) {
			if ( $has( $trigger_type ) ) {
				add_action(
					'woocommerce_order_status_' . $status,
					function ( $order_id ) use ( $callback, $trigger_type ) {
						$callback( $trigger_type, [ 'order_id' => (int) $order_id ] );
					},
					20
				);
			}
		}

		// wc_order_new: also catches checkout-created orders with any initial status.
		if ( $has( 'wc_order_new' ) ) {
			add_action(
				'woocommerce_checkout_order_created',
				function ( $order ) use ( $callback ) {
					$callback( 'wc_order_new', [ 'order_id' => (int) $order->get_id() ] );
				},
				20
			);
		}

		// Order note added.
		if ( $has( 'wc_order_note_added' ) ) {
			add_action(
				'woocommerce_new_order_note',
				function ( $note_id, $note ) use ( $callback ) {
					$callback( 'wc_order_note_added', [
						'order_id'  => (int) $note->comment_post_ID,
						'note_id'   => (int) $note_id,
						'note_type' => $note->comment_type === 'order_note' ? 'internal' : 'customer',
						'note_text' => $note->comment_content,
					] );
				},
				20, 2
			);
		}

		// Product/category purchased (fires on completed).
		if ( $has( 'wc_product_purchased' ) || $has( 'wc_category_purchased' ) || $has( 'wc_coupon_used' ) ) {
			add_action(
				'woocommerce_order_status_completed',
				function ( $order_id ) use ( $callback, $has ) {
					$order = wc_get_order( $order_id );
					if ( ! $order ) { return; }

					$product_ids  = [];
					$category_ids = [];
					foreach ( $order->get_items() as $item ) {
						/** @var \WC_Order_Item_Product $item */
						$product_ids[] = $item->get_product_id();
						$terms         = get_the_terms( $item->get_product_id(), 'product_cat' );
						if ( $terms ) {
							$category_ids = array_merge( $category_ids, wp_list_pluck( $terms, 'term_id' ) );
						}
					}

					if ( $has( 'wc_product_purchased' ) ) {
						$callback( 'wc_product_purchased', [
							'order_id'    => (int) $order_id,
							'product_ids' => array_unique( $product_ids ),
						] );
					}

					if ( $has( 'wc_category_purchased' ) ) {
						$callback( 'wc_category_purchased', [
							'order_id'    => (int) $order_id,
							'category_ids'=> array_unique( $category_ids ),
						] );
					}

					if ( $has( 'wc_coupon_used' ) && $order->get_coupon_codes() ) {
						$callback( 'wc_coupon_used', [
							'order_id'     => (int) $order_id,
							'coupon_codes' => $order->get_coupon_codes(),
						] );
					}
				},
				25
			);
		}

		// Stock hooks.
		if ( $has( 'wc_low_stock' ) ) {
			add_action( 'woocommerce_low_stock', function ( $product ) use ( $callback ) {
				$callback( 'wc_low_stock', [ 'product_id' => $product->get_id(), 'stock' => $product->get_stock_quantity() ] );
			}, 20 );
		}

		if ( $has( 'wc_out_of_stock' ) ) {
			add_action( 'woocommerce_no_stock', function ( $product ) use ( $callback ) {
				$callback( 'wc_out_of_stock', [ 'product_id' => $product->get_id() ] );
			}, 20 );
		}

		if ( $has( 'wc_back_in_stock' ) ) {
			add_action( 'woocommerce_product_set_stock', function ( $product ) use ( $callback ) {
				if ( 'instock' === $product->get_stock_status() ) {
					$callback( 'wc_back_in_stock', [ 'product_id' => $product->get_id() ] );
				}
			}, 20 );
		}

		// Customer hooks.
		if ( $has( 'customer_registered' ) ) {
			add_action( 'user_register', function ( $user_id ) use ( $callback ) {
				$callback( 'customer_registered', [ 'customer_id' => (int) $user_id ] );
			}, 20 );
		}

		if ( $has( 'customer_login' ) ) {
			add_action( 'wp_login', function ( $user_login, $user ) use ( $callback ) {
				$callback( 'customer_login', [ 'customer_id' => (int) $user->ID, 'username' => $user_login ] );
			}, 20, 2 );
		}
	}

	/**
	 * Register a custom hook trigger.
	 */
	public function register_custom_hook( string $hook_name, int $priority, callable $callback ): void {
		add_action( $hook_name, function () use ( $callback, $hook_name ) {
			$callback( 'custom_hook', [
				'hook_name' => $hook_name,
				'args'      => func_get_args(),
			] );
		}, $priority );
	}
}
