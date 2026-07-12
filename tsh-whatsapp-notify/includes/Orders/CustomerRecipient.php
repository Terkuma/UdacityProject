<?php
/**
 * Customer recipient resolver.
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
 * Class CustomerRecipient
 *
 * Extracts, normalises, and validates the customer's phone number
 * from a WooCommerce order. Respects test-mode redirects.
 */
final class CustomerRecipient {

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Get the validated, normalised E.164 phone number for the order customer.
	 *
	 * If test mode is active, returns the test-override number instead.
	 * Returns null if no valid phone can be resolved.
	 *
	 * @param \WC_Order $order
	 * @return string|null E.164 phone or null on failure.
	 */
	public function get_phone( \WC_Order $order ): ?string {
		// Test mode override.
		if ( Helpers::is_test_mode() ) {
			return $this->get_test_mode_phone();
		}

		$raw_phone = $order->get_billing_phone();

		if ( empty( $raw_phone ) ) {
			return null;
		}

		return $this->normalise_and_validate( $raw_phone );
	}

	/**
	 * Check whether the customer should receive a notification for this event.
	 * Always returns true as long as a valid phone exists — event enablement
	 * is checked higher up in OrderProcessor.
	 *
	 * @param \WC_Order $order
	 * @return bool
	 */
	public function should_notify( \WC_Order $order ): bool {
		return null !== $this->get_phone( $order );
	}

	/**
	 * Return the raw (unvalidated) billing phone from the order.
	 * Useful for displaying in admin UI without normalisation.
	 *
	 * @param \WC_Order $order
	 * @return string
	 */
	public function get_raw_phone( \WC_Order $order ): string {
		return sanitize_text_field( $order->get_billing_phone() );
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Normalise a phone string and validate the result.
	 *
	 * @param string $raw_phone
	 * @return string|null
	 */
	private function normalise_and_validate( string $raw_phone ): ?string {
		// Read default country from general settings.
		$general  = get_option( 'tsh_wa_general_settings', [] );
		$country  = sanitize_text_field( $general['default_country'] ?? 'NG' );

		$normalised = Helpers::format_phone( $raw_phone, $country );

		if ( ! $normalised || ! Helpers::is_valid_phone( $normalised ) ) {
			return null;
		}

		return $normalised;
	}

	/**
	 * Return the test-mode override phone number.
	 * Returns null if the override is not configured.
	 *
	 * @return string|null
	 */
	private function get_test_mode_phone(): ?string {
		$general    = get_option( 'tsh_wa_general_settings', [] );
		$test_phone = sanitize_text_field( $general['send_test_to'] ?? '' );

		if ( ! $test_phone ) {
			// Fall back to API settings test phone.
			$api       = get_option( 'tsh_wa_api_settings', [] );
			$test_phone = sanitize_text_field( $api['test_phone_number'] ?? '' );
		}

		if ( ! $test_phone ) {
			return null;
		}

		return Helpers::is_valid_phone( $test_phone ) ? $test_phone : null;
	}
}
