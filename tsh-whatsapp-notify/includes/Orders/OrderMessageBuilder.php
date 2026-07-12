<?php
/**
 * Message builder — fills {placeholder} tokens with live order data.
 *
 * @package TSH\WhatsAppNotify\Orders
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Orders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class OrderMessageBuilder
 *
 * Takes a template body string (from the DB or a default) and an order,
 * and returns the fully-rendered message ready for the queue.
 *
 * Supported tokens:
 *
 *   {store_name}         — Blog/site name
 *   {order_number}       — WC order number
 *   {customer_name}      — Full billing name
 *   {customer_phone}     — Billing phone
 *   {customer_email}     — Billing email
 *   {payment_method}     — Payment gateway title
 *   {shipping_method}    — Shipping method title
 *   {subtotal}           — Order subtotal
 *   {discount}           — Total discount
 *   {shipping}           — Shipping cost
 *   {tax}                — Total tax
 *   {total}              — Grand total
 *   {currency}           — ISO currency code
 *   {order_date}         — Formatted order date
 *   {billing_address}    — Full billing address
 *   {shipping_address}   — Full shipping address
 *   {customer_note}      — Note left by customer at checkout
 *   {products}           — Formatted product list
 *   {product_count}      — Number of line items
 *   {admin_order_url}    — Admin edit-order URL
 *   {customer_order_url} — Customer view-order URL
 */
final class OrderMessageBuilder {

	/** @var OrderFormatter */
	private OrderFormatter $formatter;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->formatter = new OrderFormatter();
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Build a fully-rendered message for the given order.
	 *
	 * @param string    $template Template body with {placeholder} tokens.
	 * @param \WC_Order $order    WooCommerce order.
	 * @return string             Rendered message, max 4096 chars.
	 */
	public function build( string $template, \WC_Order $order ): string {
		$values  = $this->get_placeholder_values( $order );
		$tokens  = array_map( static fn( string $k ) => '{' . $k . '}', array_keys( $values ) );
		$message = str_replace( $tokens, array_values( $values ), $template );

		// Enforce WhatsApp message length limit.
		return mb_substr( $message, 0, 4096 );
	}

	/**
	 * Return all available placeholder keys (without braces) and their current
	 * labels, for display in the template editor.
	 *
	 * @return array<string, string> key → description
	 */
	public function get_available_placeholders(): array {
		return [
			'store_name'         => __( 'Store name', 'tsh-whatsapp-notify' ),
			'order_number'       => __( 'Order number', 'tsh-whatsapp-notify' ),
			'customer_name'      => __( 'Customer full name', 'tsh-whatsapp-notify' ),
			'customer_phone'     => __( 'Customer billing phone', 'tsh-whatsapp-notify' ),
			'customer_email'     => __( 'Customer email address', 'tsh-whatsapp-notify' ),
			'payment_method'     => __( 'Payment method title', 'tsh-whatsapp-notify' ),
			'shipping_method'    => __( 'Shipping method title', 'tsh-whatsapp-notify' ),
			'subtotal'           => __( 'Order subtotal', 'tsh-whatsapp-notify' ),
			'discount'           => __( 'Total discount', 'tsh-whatsapp-notify' ),
			'shipping'           => __( 'Shipping cost', 'tsh-whatsapp-notify' ),
			'tax'                => __( 'Tax total', 'tsh-whatsapp-notify' ),
			'total'              => __( 'Grand total', 'tsh-whatsapp-notify' ),
			'currency'           => __( 'Currency code', 'tsh-whatsapp-notify' ),
			'order_date'         => __( 'Order creation date', 'tsh-whatsapp-notify' ),
			'billing_address'    => __( 'Full billing address', 'tsh-whatsapp-notify' ),
			'shipping_address'   => __( 'Full shipping address', 'tsh-whatsapp-notify' ),
			'customer_note'      => __( 'Note left by customer at checkout', 'tsh-whatsapp-notify' ),
			'products'           => __( 'Formatted product list', 'tsh-whatsapp-notify' ),
			'product_count'      => __( 'Number of order line items', 'tsh-whatsapp-notify' ),
			'admin_order_url'    => __( 'Admin order edit URL', 'tsh-whatsapp-notify' ),
			'customer_order_url' => __( 'Customer order view URL', 'tsh-whatsapp-notify' ),
		];
	}

	/**
	 * Resolve all placeholder values for a given order.
	 *
	 * @param \WC_Order $order
	 * @return array<string, string>
	 */
	public function get_placeholder_values( \WC_Order $order ): array {
		return [
			'store_name'         => wp_strip_all_tags( get_bloginfo( 'name' ) ),
			'order_number'       => (string) $order->get_order_number(),
			'customer_name'      => wp_strip_all_tags( $order->get_formatted_billing_full_name() ),
			'customer_phone'     => sanitize_text_field( $order->get_billing_phone() ),
			'customer_email'     => sanitize_email( $order->get_billing_email() ),
			'payment_method'     => $this->formatter->format_payment_method( $order ),
			'shipping_method'    => $this->formatter->format_shipping_method( $order ),
			'subtotal'           => $this->formatter->format_subtotal( $order ),
			'discount'           => $this->formatter->format_discount( $order ),
			'shipping'           => $this->formatter->format_shipping( $order ),
			'tax'                => $this->formatter->format_tax( $order ),
			'total'              => $this->formatter->format_total( $order ),
			'currency'           => strtoupper( $order->get_currency() ),
			'order_date'         => $this->formatter->format_order_date( $order ),
			'billing_address'    => $this->formatter->format_billing_address( $order ),
			'shipping_address'   => $this->formatter->format_shipping_address( $order ),
			'customer_note'      => wp_strip_all_tags( $order->get_customer_note() ),
			'products'           => $this->formatter->format_products( $order ),
			'product_count'      => (string) $this->formatter->get_product_count( $order ),
			'admin_order_url'    => admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' ),
			'customer_order_url' => $order->get_view_order_url(),
		];
	}

	/**
	 * Preview a rendered message for a given order and template.
	 * Same as build() but also returns the placeholder map for debugging.
	 *
	 * @param string    $template
	 * @param \WC_Order $order
	 * @return array{ message: string, placeholders: array<string, string> }
	 */
	public function preview( string $template, \WC_Order $order ): array {
		$values = $this->get_placeholder_values( $order );
		return [
			'message'      => $this->build( $template, $order ),
			'placeholders' => $values,
		];
	}
}
