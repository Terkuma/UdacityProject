<?php
/**
 * Variable resolver — replaces {{placeholders}} with real values from context.
 *
 * @package TSH\WhatsAppNotify\Automation
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Automation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class VariableResolver
 *
 * Resolves {{variable}} placeholders using the current execution context.
 *
 * Context shape (keys):
 *   order_id       int|null
 *   customer_id    int|null
 *   product_id     int|null
 *   trigger_data   array
 *   step_outputs   array<string, mixed>  keyed by node_id
 *   extra          array<string, mixed>  any ad-hoc values
 */
class VariableResolver {

	/** @var array<string, mixed> */
	private array $context;

	/** @var \WC_Order|null Lazy-loaded */
	private ?\WC_Order $order = null;

	/** @var \WP_User|null Lazy-loaded */
	private ?\WP_User $customer = null;

	public function __construct( array $context = [] ) {
		$this->context = $context;
	}

	// -------------------------------------------------------------------------
	// Main resolve
	// -------------------------------------------------------------------------

	/**
	 * Replace all {{variable}} tokens in a string with their resolved values.
	 *
	 * Supports dot-notation for nested arrays: {{trigger_data.order_id}}
	 * Supports step outputs: {{step.node_abc123.queue_id}}
	 *
	 * @param string $text
	 * @return string
	 */
	public function resolve( string $text ): string {
		if ( strpos( $text, '{{' ) === false ) {
			return $text;
		}

		return preg_replace_callback(
			'/\{\{([a-zA-Z0-9_.\-]+)\}\}/',
			function ( array $m ) {
				return (string) $this->get_value( $m[1] );
			},
			$text
		) ?? $text;
	}

	/**
	 * Resolve all {{placeholders}} in every string value of a nested array.
	 */
	public function resolve_array( array $data ): array {
		array_walk_recursive( $data, function ( &$value ) {
			if ( is_string( $value ) ) {
				$value = $this->resolve( $value );
			}
		} );

		return $data;
	}

	// -------------------------------------------------------------------------
	// Value lookup
	// -------------------------------------------------------------------------

	/**
	 * Get the value of a variable by its key.
	 *
	 * @param string $key  e.g. "order.total", "customer.email", "step.node123.result"
	 * @return mixed
	 */
	public function get_value( string $key ) {
		$parts = explode( '.', $key, 3 );

		switch ( $parts[0] ) {
			case 'order':
				return $this->resolve_order_var( $parts[1] ?? '' );

			case 'customer':
				return $this->resolve_customer_var( $parts[1] ?? '' );

			case 'site':
				return $this->resolve_site_var( $parts[1] ?? '' );

			case 'step':
				// {{step.NODE_ID.output_key}}
				$node_id    = $parts[1] ?? '';
				$output_key = $parts[2] ?? 'result';
				$outputs    = $this->context['step_outputs'] ?? [];

				return $outputs[ $node_id ][ $output_key ] ?? '';

			case 'trigger':
				$sub = $parts[1] ?? '';
				$td  = $this->context['trigger_data'] ?? [];
				return $this->nested_get( $td, $sub );

			case 'extra':
				$sub   = $parts[1] ?? '';
				$extra = $this->context['extra'] ?? [];
				return $extra[ $sub ] ?? '';

			default:
				// Flat lookup in trigger_data.
				$td = $this->context['trigger_data'] ?? [];
				return $td[ $key ] ?? ( $this->context['extra'][ $key ] ?? '' );
		}
	}

	// -------------------------------------------------------------------------
	// Order variables
	// -------------------------------------------------------------------------

	private function resolve_order_var( string $key ) {
		$order = $this->get_order();

		if ( ! $order ) {
			return '';
		}

		switch ( $key ) {
			case 'id':
			case 'order_id':
				return $order->get_id();
			case 'number':
				return $order->get_order_number();
			case 'status':
				return $order->get_status();
			case 'status_label':
				return wc_get_order_status_name( $order->get_status() );
			case 'total':
				return $order->get_formatted_order_total();
			case 'total_raw':
				return $order->get_total();
			case 'subtotal':
				return wc_price( $order->get_subtotal() );
			case 'currency':
				return $order->get_currency();
			case 'payment_method':
				return $order->get_payment_method_title();
			case 'shipping_method':
				$methods = $order->get_shipping_methods();
				$method  = reset( $methods );
				return $method ? $method->get_method_title() : '';
			case 'billing_first_name':
				return $order->get_billing_first_name();
			case 'billing_last_name':
				return $order->get_billing_last_name();
			case 'billing_full_name':
				return $order->get_formatted_billing_full_name();
			case 'billing_email':
				return $order->get_billing_email();
			case 'billing_phone':
				return $order->get_billing_phone();
			case 'billing_address':
				return $order->get_formatted_billing_address();
			case 'billing_city':
				return $order->get_billing_city();
			case 'billing_country':
				return $order->get_billing_country();
			case 'shipping_address':
				return $order->get_formatted_shipping_address();
			case 'shipping_city':
				return $order->get_shipping_city();
			case 'shipping_tracking':
				return $order->get_meta( '_tracking_number' ) ?: '';
			case 'items_summary':
				return $this->get_items_summary( $order );
			case 'item_count':
				return $order->get_item_count();
			case 'date':
				return wc_format_datetime( $order->get_date_created() );
			case 'date_created':
				return $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d' ) : '';
			case 'coupon_codes':
				return implode( ', ', $order->get_coupon_codes() );
			case 'view_url':
				return $order->get_view_order_url();
			case 'admin_url':
				return get_admin_url( null, 'post.php?post=' . $order->get_id() . '&action=edit' );
			case 'note':
				$notes = wc_get_order_notes( [ 'order_id' => $order->get_id(), 'type' => 'customer', 'limit' => 1 ] );
				return $notes ? $notes[0]->content : '';
			default:
				return $order->get_meta( $key ) ?: '';
		}
	}

	private function get_items_summary( \WC_Order $order ): string {
		$items = [];
		foreach ( $order->get_items() as $item ) {
			/** @var \WC_Order_Item_Product $item */
			$items[] = $item->get_name() . ' × ' . $item->get_quantity();
		}
		return implode( ', ', $items );
	}

	// -------------------------------------------------------------------------
	// Customer variables
	// -------------------------------------------------------------------------

	private function resolve_customer_var( string $key ) {
		$user = $this->get_customer();

		// Fall back to order billing fields if no WP user.
		$order = $this->get_order();

		switch ( $key ) {
			case 'id':
				return $user ? $user->ID : 0;
			case 'first_name':
				return $user ? $user->first_name : ( $order ? $order->get_billing_first_name() : '' );
			case 'last_name':
				return $user ? $user->last_name : ( $order ? $order->get_billing_last_name() : '' );
			case 'full_name':
				if ( $user ) {
					return trim( $user->first_name . ' ' . $user->last_name ) ?: $user->display_name;
				}
				return $order ? $order->get_formatted_billing_full_name() : '';
			case 'email':
				return $user ? $user->user_email : ( $order ? $order->get_billing_email() : '' );
			case 'phone':
				return $order ? $order->get_billing_phone() : '';
			case 'role':
				return $user ? implode( ', ', $user->roles ) : 'guest';
			case 'registered':
				return $user ? $user->user_registered : '';
			default:
				return $user ? get_user_meta( $user->ID, $key, true ) : '';
		}
	}

	// -------------------------------------------------------------------------
	// Site variables
	// -------------------------------------------------------------------------

	private function resolve_site_var( string $key ) {
		switch ( $key ) {
			case 'name':
				return get_bloginfo( 'name' );
			case 'url':
				return get_bloginfo( 'url' );
			case 'admin_email':
				return get_bloginfo( 'admin_email' );
			case 'date':
				return current_time( 'd/m/Y' );
			case 'time':
				return current_time( 'H:i' );
			case 'datetime':
				return current_time( 'd/m/Y H:i' );
			default:
				return get_bloginfo( $key );
		}
	}

	// -------------------------------------------------------------------------
	// Lazy loaders
	// -------------------------------------------------------------------------

	private function get_order(): ?\WC_Order {
		if ( $this->order instanceof \WC_Order ) {
			return $this->order;
		}

		$order_id = $this->context['order_id'] ?? ( $this->context['trigger_data']['order_id'] ?? null );

		if ( $order_id && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( (int) $order_id );
			if ( $order instanceof \WC_Order ) {
				$this->order = $order;
			}
		}

		return $this->order;
	}

	private function get_customer(): ?\WP_User {
		if ( $this->customer instanceof \WP_User ) {
			return $this->customer;
		}

		$customer_id = $this->context['customer_id']
			?? ( $this->context['trigger_data']['customer_id'] ?? null );

		if ( ! $customer_id ) {
			$order = $this->get_order();
			if ( $order ) {
				$customer_id = $order->get_customer_id();
			}
		}

		if ( $customer_id ) {
			$user = get_user_by( 'id', (int) $customer_id );
			if ( $user instanceof \WP_User ) {
				$this->customer = $user;
			}
		}

		return $this->customer;
	}

	// -------------------------------------------------------------------------
	// Utilities
	// -------------------------------------------------------------------------

	private function nested_get( array $data, string $key ) {
		$parts = explode( '.', $key );
		$val   = $data;

		foreach ( $parts as $p ) {
			if ( is_array( $val ) && isset( $val[ $p ] ) ) {
				$val = $val[ $p ];
			} else {
				return '';
			}
		}

		return $val;
	}

	/**
	 * Return a flat list of all supported variable tokens with descriptions.
	 *
	 * @return array<array{ token: string, label: string, group: string }>
	 */
	public static function get_available_variables(): array {
		return [
			// Order
			[ 'token' => '{{order.id}}',               'label' => 'Order ID',               'group' => 'Order' ],
			[ 'token' => '{{order.number}}',            'label' => 'Order Number',           'group' => 'Order' ],
			[ 'token' => '{{order.status}}',            'label' => 'Order Status (slug)',     'group' => 'Order' ],
			[ 'token' => '{{order.status_label}}',      'label' => 'Order Status (label)',   'group' => 'Order' ],
			[ 'token' => '{{order.total}}',             'label' => 'Order Total (formatted)', 'group' => 'Order' ],
			[ 'token' => '{{order.total_raw}}',         'label' => 'Order Total (number)',   'group' => 'Order' ],
			[ 'token' => '{{order.items_summary}}',     'label' => 'Items Summary',          'group' => 'Order' ],
			[ 'token' => '{{order.item_count}}',        'label' => 'Item Count',             'group' => 'Order' ],
			[ 'token' => '{{order.date}}',              'label' => 'Order Date',             'group' => 'Order' ],
			[ 'token' => '{{order.payment_method}}',    'label' => 'Payment Method',         'group' => 'Order' ],
			[ 'token' => '{{order.shipping_method}}',   'label' => 'Shipping Method',        'group' => 'Order' ],
			[ 'token' => '{{order.billing_phone}}',     'label' => 'Billing Phone',          'group' => 'Order' ],
			[ 'token' => '{{order.billing_email}}',     'label' => 'Billing Email',          'group' => 'Order' ],
			[ 'token' => '{{order.billing_address}}',   'label' => 'Billing Address',        'group' => 'Order' ],
			[ 'token' => '{{order.shipping_address}}',  'label' => 'Shipping Address',       'group' => 'Order' ],
			[ 'token' => '{{order.coupon_codes}}',      'label' => 'Coupon Codes',           'group' => 'Order' ],
			[ 'token' => '{{order.view_url}}',          'label' => 'Order View URL',         'group' => 'Order' ],
			// Customer
			[ 'token' => '{{customer.first_name}}',     'label' => 'First Name',             'group' => 'Customer' ],
			[ 'token' => '{{customer.last_name}}',      'label' => 'Last Name',              'group' => 'Customer' ],
			[ 'token' => '{{customer.full_name}}',      'label' => 'Full Name',              'group' => 'Customer' ],
			[ 'token' => '{{customer.email}}',          'label' => 'Email',                  'group' => 'Customer' ],
			[ 'token' => '{{customer.phone}}',          'label' => 'Phone',                  'group' => 'Customer' ],
			[ 'token' => '{{customer.role}}',           'label' => 'Customer Role',          'group' => 'Customer' ],
			// Site
			[ 'token' => '{{site.name}}',               'label' => 'Site Name',              'group' => 'Site' ],
			[ 'token' => '{{site.url}}',                'label' => 'Site URL',               'group' => 'Site' ],
			[ 'token' => '{{site.date}}',               'label' => 'Current Date',           'group' => 'Site' ],
			[ 'token' => '{{site.time}}',               'label' => 'Current Time',           'group' => 'Site' ],
		];
	}
}
