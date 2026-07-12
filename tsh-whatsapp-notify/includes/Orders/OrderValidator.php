<?php
/**
 * Order and event validation.
 *
 * @package TSH\WhatsAppNotify\Orders
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Orders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TSH\WhatsAppNotify\Helpers\Helpers;

/**
 * Class OrderValidator
 *
 * Performs lightweight validation of an order and an event before
 * the processing pipeline continues. All checks are cheap (no API calls).
 */
final class OrderValidator {

	/**
	 * Check that the WhatsApp API is configured and enabled.
	 *
	 * @return bool
	 */
	public function is_api_enabled(): bool {
		$api_settings = get_option( 'tsh_wa_api_settings', [] );
		$enabled      = ! empty( $api_settings['enable_api'] ) && '1' === (string) $api_settings['enable_api'];
		$ready        = Helpers::is_plugin_ready();

		return $enabled && $ready;
	}

	/**
	 * Check that an event key is in the supported list.
	 *
	 * @param string $event_key
	 * @return bool
	 */
	public function is_event_supported( string $event_key ): bool {
		return array_key_exists( $event_key, OrderStatusListener::ALL_EVENTS );
	}

	/**
	 * Validate that an order object is usable for notification.
	 *
	 * Checks:
	 * - Order is a WC_Order instance.
	 * - Order has a positive ID.
	 * - Order is not a refund sub-object.
	 *
	 * @param \WC_Order $order
	 * @return bool
	 */
	public function is_valid_order( \WC_Order $order ): bool {
		if ( ! ( $order instanceof \WC_Order ) ) {
			return false;
		}

		if ( (int) $order->get_id() <= 0 ) {
			return false;
		}

		// Skip WC_Order_Refund objects (they are a subtype of WC_Order).
		if ( $order instanceof \WC_Order_Refund ) {
			return false;
		}

		return true;
	}

	/**
	 * Validate a phone number string.
	 *
	 * @param string $phone
	 * @return bool
	 */
	public function is_valid_phone( string $phone ): bool {
		return Helpers::is_valid_phone( $phone );
	}
}
