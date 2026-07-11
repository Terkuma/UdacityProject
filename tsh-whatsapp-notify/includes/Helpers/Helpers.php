<?php
/**
 * General-purpose helper functions.
 *
 * @package TSH\WhatsAppNotify\Helpers
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Helpers
 *
 * Static utility methods shared across all plugin components.
 * No side effects — pure functions only.
 */
final class Helpers {

	// -------------------------------------------------------------------------
	// Phone number helpers
	// -------------------------------------------------------------------------

	/**
	 * Normalise a phone number to E.164 format.
	 *
	 * Strips spaces, dashes, parentheses, and a leading zero.
	 * Prepends the default country code if no '+' prefix is present.
	 *
	 * @param string $phone            Raw phone number.
	 * @param string $default_country  ISO-3166-1 two-letter country code (used
	 *                                  only when no international prefix exists).
	 *                                  NOTE: full libphonenumber-style parsing
	 *                                  is deferred to a later phase.
	 * @return string E.164-formatted number, or empty string on failure.
	 */
	public static function format_phone( string $phone, string $default_country = 'NG' ): string {
		// Strip everything except digits and the leading +.
		$cleaned = preg_replace( '/[^\d+]/', '', $phone );

		if ( '' === (string) $cleaned ) {
			return '';
		}

		// Already has an international prefix.
		if ( str_starts_with( $cleaned, '+' ) ) {
			return $cleaned;
		}

		// Strip a leading 0 (local format).
		$cleaned = ltrim( $cleaned, '0' );

		// Country-code lookup (extend as needed in Phase 2).
		$country_codes = [
			'NG' => '234',
			'GB' => '44',
			'US' => '1',
			'GH' => '233',
			'KE' => '254',
			'ZA' => '27',
		];

		$code = $country_codes[ strtoupper( $default_country ) ] ?? '234';

		return '+' . $code . $cleaned;
	}

	/**
	 * Validate whether a string looks like a plausible E.164 phone number.
	 *
	 * Checks for + followed by 7–15 digits (ITU-T E.164 spec).
	 *
	 * @param string $phone
	 * @return bool
	 */
	public static function is_valid_phone( string $phone ): bool {
		return (bool) preg_match( '/^\+\d{7,15}$/', $phone );
	}

	/**
	 * Mask a phone number for safe display (e.g. in logs).
	 *
	 * Example: +2348012345678 → +234XXXXXX5678
	 *
	 * @param string $phone
	 * @return string
	 */
	public static function mask_phone( string $phone ): string {
		$len = strlen( $phone );
		if ( $len < 8 ) {
			return str_repeat( 'X', $len );
		}

		return substr( $phone, 0, 4 ) . str_repeat( 'X', $len - 8 ) . substr( $phone, -4 );
	}

	// -------------------------------------------------------------------------
	// Message / template helpers
	// -------------------------------------------------------------------------

	/**
	 * Sanitise a WhatsApp message body before storage or dispatch.
	 *
	 * Trims whitespace, removes NUL bytes, and enforces the 4096-character
	 * limit imposed by the Meta Cloud API.
	 *
	 * @param string $message
	 * @return string
	 */
	public static function sanitize_message( string $message ): string {
		// Remove NUL bytes and control characters (keep newlines/tabs).
		$clean = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $message );
		$clean = trim( (string) $clean );

		// Enforce Meta Cloud API character limit.
		return mb_substr( $clean, 0, 4096 );
	}

	/**
	 * Replace template variable placeholders with actual values.
	 *
	 * Variables use the format {{variable_name}}.
	 *
	 * @param string               $template  Message template body.
	 * @param array<string, mixed> $variables Key-value replacement map.
	 * @return string
	 */
	public static function interpolate_template( string $template, array $variables ): string {
		foreach ( $variables as $key => $value ) {
			$template = str_replace(
				'{{' . $key . '}}',
				(string) $value,
				$template
			);
		}

		return $template;
	}

	// -------------------------------------------------------------------------
	// Currency / order helpers
	// -------------------------------------------------------------------------

	/**
	 * Format an amount as a WooCommerce currency string.
	 *
	 * Requires WooCommerce to be active.
	 *
	 * @param float|string $amount   Raw amount.
	 * @param string       $currency Optional ISO 4217 currency code. Falls back
	 *                               to the store's configured currency.
	 * @return string HTML-escaped formatted price string.
	 */
	public static function format_currency( float|string $amount, string $currency = '' ): string {
		if ( ! function_exists( 'wc_price' ) ) {
			return esc_html( number_format( (float) $amount, 2 ) );
		}

		$args = [];
		if ( $currency ) {
			$args['currency'] = strtoupper( $currency );
		}

		return wp_strip_all_tags( wc_price( (float) $amount, $args ) );
	}

	/**
	 * Build a human-readable summary of WooCommerce order items.
	 *
	 * @param \WC_Order $order
	 * @param int       $max_items Maximum number of items to include (0 = all).
	 * @return string
	 */
	public static function get_order_items_summary( \WC_Order $order, int $max_items = 0 ): string {
		$lines = [];
		$count = 0;

		foreach ( $order->get_items() as $item ) {
			/** @var \WC_Order_Item_Product $item */
			$lines[] = sprintf( '%s × %d', $item->get_name(), $item->get_quantity() );
			++$count;

			if ( $max_items > 0 && $count >= $max_items ) {
				$remaining = count( $order->get_items() ) - $count;
				if ( $remaining > 0 ) {
					$lines[] = sprintf(
						/* translators: %d: number of additional items not shown */
						__( '…and %d more item(s)', 'tsh-whatsapp-notify' ),
						$remaining
					);
				}
				break;
			}
		}

		return implode( "\n", $lines );
	}

	// -------------------------------------------------------------------------
	// Security helpers
	// -------------------------------------------------------------------------

	/**
	 * Mask a sensitive token for safe display (e.g. in settings or logs).
	 *
	 * Example: EAAbcdef...XYZ → EAAb****XYZ
	 *
	 * @param string $token
	 * @param int    $visible_start Characters to show at the start.
	 * @param int    $visible_end   Characters to show at the end.
	 * @return string
	 */
	public static function mask_token( string $token, int $visible_start = 4, int $visible_end = 4 ): string {
		$len = strlen( $token );
		if ( $len <= ( $visible_start + $visible_end ) ) {
			return str_repeat( '*', $len );
		}

		return substr( $token, 0, $visible_start )
			. str_repeat( '*', $len - $visible_start - $visible_end )
			. substr( $token, -$visible_end );
	}

	// -------------------------------------------------------------------------
	// Plugin state helpers
	// -------------------------------------------------------------------------

	/**
	 * Determine whether the plugin is fully configured and ready to send messages.
	 *
	 * Returns false in Phase 1 because the API credentials do not exist yet.
	 * Phase 2 will populate this with real API readiness checks.
	 *
	 * @return bool
	 */
	public static function is_plugin_ready(): bool {
		$api_settings = get_option( 'tsh_wa_api_settings', [] );

		return ! empty( $api_settings['phone_number_id'] )
			&& ! empty( $api_settings['access_token'] )
			&& ! empty( $api_settings['business_account_id'] );
	}

	/**
	 * Check whether test/sandbox mode is enabled.
	 *
	 * @return bool
	 */
	public static function is_test_mode(): bool {
		$settings = get_option( 'tsh_wa_general_settings', [] );

		return ! empty( $settings['test_mode'] ) && '1' === (string) $settings['test_mode'];
	}

	/**
	 * Check whether debug mode is enabled.
	 *
	 * @return bool
	 */
	public static function is_debug_mode(): bool {
		$settings = get_option( 'tsh_wa_advanced_settings', [] );

		return ! empty( $settings['debug_mode'] ) && '1' === (string) $settings['debug_mode'];
	}

	/**
	 * Return a fully-qualified URL to a plugin asset.
	 *
	 * @param string $relative_path Path relative to the plugin root (e.g. 'assets/css/admin.css').
	 * @return string
	 */
	public static function asset_url( string $relative_path ): string {
		return TSH_WA_URL . ltrim( $relative_path, '/' );
	}

	/**
	 * Return the plugin version string.
	 *
	 * @return string
	 */
	public static function version(): string {
		return TSH_WA_VERSION;
	}
}
