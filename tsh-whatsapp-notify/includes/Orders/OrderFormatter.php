<?php
/**
 * Order data formatter.
 *
 * Converts raw WooCommerce order data into human-readable strings
 * suitable for WhatsApp messages.
 *
 * @package TSH\WhatsAppNotify\Orders
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Orders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class OrderFormatter
 *
 * All methods are pure: given an order they return a string.
 * No side effects; no state.
 */
final class OrderFormatter {

	// -------------------------------------------------------------------------
	// Product list
	// -------------------------------------------------------------------------

	/**
	 * Format all order line items into a WhatsApp-friendly product list.
	 *
	 * Example output:
	 *   • Navy Suit × 2 — ₦85,000
	 *     Variation: Size XL, Color Navy
	 *     SKU: NST-XL-NVY
	 *
	 * @param \WC_Order $order
	 * @return string
	 */
	public function format_products( \WC_Order $order ): string {
		$lines = [];

		foreach ( $order->get_items() as $item ) {
			/** @var \WC_Order_Item_Product $item */
			$name      = $item->get_name();
			$qty       = $item->get_quantity();
			$subtotal  = $this->format_price( (float) $item->get_subtotal(), $order->get_currency() );

			$line = sprintf( '• %s × %d — %s', $name, $qty, $subtotal );

			// Variation attributes.
			if ( $item->get_variation_id() ) {
				$product = $item->get_product();
				if ( $product instanceof \WC_Product_Variation ) {
					$attributes = $product->get_variation_attributes();
					$attr_parts = [];
					foreach ( $attributes as $attr_key => $attr_val ) {
						$label        = wc_attribute_label( str_replace( 'attribute_', '', $attr_key ), $product );
						$attr_parts[] = $label . ': ' . $attr_val;
					}
					if ( $attr_parts ) {
						$line .= "\n  " . implode( ', ', $attr_parts );
					}
				}
			}

			// SKU.
			$product = $item->get_product();
			if ( $product instanceof \WC_Product ) {
				$sku = $product->get_sku();
				if ( $sku ) {
					$line .= "\n  SKU: " . $sku;
				}
			}

			$lines[] = $line;
		}

		return implode( "\n\n", $lines );
	}

	/**
	 * Return a simple count of unique line items in the order.
	 *
	 * @param \WC_Order $order
	 * @return int
	 */
	public function get_product_count( \WC_Order $order ): int {
		return count( $order->get_items() );
	}

	// -------------------------------------------------------------------------
	// Addresses
	// -------------------------------------------------------------------------

	/**
	 * Format the billing address as a single string.
	 *
	 * @param \WC_Order $order
	 * @return string
	 */
	public function format_billing_address( \WC_Order $order ): string {
		return $this->flatten_address( $order->get_formatted_billing_address() );
	}

	/**
	 * Format the shipping address as a single string.
	 * Falls back to billing address if shipping address is empty.
	 *
	 * @param \WC_Order $order
	 * @return string
	 */
	public function format_shipping_address( \WC_Order $order ): string {
		$shipping = $order->get_formatted_shipping_address();
		if ( ! $shipping ) {
			$shipping = $order->get_formatted_billing_address();
		}
		return $this->flatten_address( $shipping );
	}

	// -------------------------------------------------------------------------
	// Monetary amounts
	// -------------------------------------------------------------------------

	/**
	 * Format a monetary amount with the order's currency symbol.
	 *
	 * @param float  $amount
	 * @param string $currency ISO 4217 code.
	 * @return string
	 */
	public function format_price( float $amount, string $currency = '' ): string {
		if ( function_exists( 'wc_price' ) ) {
			$opts = $currency ? [ 'currency' => $currency ] : [];
			return wp_strip_all_tags( html_entity_decode( wc_price( $amount, $opts ), ENT_QUOTES, 'UTF-8' ) );
		}
		return number_format( $amount, 2 ) . ( $currency ? ' ' . strtoupper( $currency ) : '' );
	}

	/**
	 * Format order subtotal (before discounts).
	 *
	 * @param \WC_Order $order
	 * @return string
	 */
	public function format_subtotal( \WC_Order $order ): string {
		return $this->format_price( (float) $order->get_subtotal(), $order->get_currency() );
	}

	/**
	 * Format order discount amount.
	 *
	 * @param \WC_Order $order
	 * @return string
	 */
	public function format_discount( \WC_Order $order ): string {
		return $this->format_price( (float) $order->get_discount_total(), $order->get_currency() );
	}

	/**
	 * Format order shipping total.
	 *
	 * @param \WC_Order $order
	 * @return string
	 */
	public function format_shipping( \WC_Order $order ): string {
		return $this->format_price( (float) $order->get_shipping_total(), $order->get_currency() );
	}

	/**
	 * Format order tax total.
	 *
	 * @param \WC_Order $order
	 * @return string
	 */
	public function format_tax( \WC_Order $order ): string {
		return $this->format_price( (float) $order->get_total_tax(), $order->get_currency() );
	}

	/**
	 * Format order grand total.
	 *
	 * @param \WC_Order $order
	 * @return string
	 */
	public function format_total( \WC_Order $order ): string {
		return $this->format_price( (float) $order->get_total(), $order->get_currency() );
	}

	// -------------------------------------------------------------------------
	// Dates
	// -------------------------------------------------------------------------

	/**
	 * Format the order date for message display.
	 *
	 * @param \WC_Order $order
	 * @param string    $format PHP date format. Defaults to human-friendly.
	 * @return string
	 */
	public function format_order_date( \WC_Order $order, string $format = 'F j, Y g:i A' ): string {
		$date = $order->get_date_created();
		if ( ! $date ) {
			return current_time( $format );
		}
		return $date->date_i18n( $format );
	}

	// -------------------------------------------------------------------------
	// Payment / shipping methods
	// -------------------------------------------------------------------------

	/**
	 * Return the human-readable payment method title.
	 *
	 * @param \WC_Order $order
	 * @return string
	 */
	public function format_payment_method( \WC_Order $order ): string {
		return wp_strip_all_tags( $order->get_payment_method_title() ?: __( 'N/A', 'tsh-whatsapp-notify' ) );
	}

	/**
	 * Return the human-readable shipping method name(s).
	 *
	 * @param \WC_Order $order
	 * @return string
	 */
	public function format_shipping_method( \WC_Order $order ): string {
		$methods = [];
		foreach ( $order->get_shipping_methods() as $method ) {
			/** @var \WC_Order_Item_Shipping $method */
			$methods[] = $method->get_method_title();
		}
		return $methods ? implode( ', ', $methods ) : __( 'N/A', 'tsh-whatsapp-notify' );
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Convert an HTML-formatted address string to plain text.
	 *
	 * @param string $formatted WC formatted address (may contain <br> tags).
	 * @return string
	 */
	private function flatten_address( string $formatted ): string {
		// Replace <br> variants with newlines, then strip all tags.
		$plain = preg_replace( '/<br\s*\/?>/i', "\n", $formatted );
		$plain = wp_strip_all_tags( (string) $plain );
		return trim( (string) $plain );
	}
}
