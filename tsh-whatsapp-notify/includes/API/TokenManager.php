<?php
/**
 * Credential management for the WhatsApp API.
 *
 * @package TSH\WhatsAppNotify\API
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TokenManager
 *
 * Centralises all access to sensitive API credentials.
 *
 * Design rules
 * ─────────────
 * • The raw token is NEVER written to any log, error message, or transient.
 * • All reads go through this class — no direct get_option() for the token
 *   elsewhere in the API layer.
 * • Encryption is prepared for (placeholder XOR obfuscation at the option
 *   storage layer) and will be upgraded to openssl_encrypt in a later phase
 *   once a site-unique secret key strategy is confirmed.
 */
final class TokenManager {

	/** @var string The wp_options key that holds all API settings. */
	private const OPTION_KEY = 'tsh_wa_api_settings';

	// -------------------------------------------------------------------------
	// Token operations
	// -------------------------------------------------------------------------

	/**
	 * Retrieve the raw access token.
	 *
	 * @return string Empty string when not configured.
	 */
	public function get_token(): string {
		$settings = get_option( self::OPTION_KEY, [] );
		return isset( $settings['access_token'] ) ? (string) $settings['access_token'] : '';
	}

	/**
	 * Persist a new access token, merging it into the existing settings array.
	 *
	 * @param string $token Raw token value.
	 * @return bool True on success.
	 */
	public function set_token( string $token ): bool {
		$settings                 = get_option( self::OPTION_KEY, [] );
		$settings['access_token'] = sanitize_text_field( $token );
		return update_option( self::OPTION_KEY, $settings, false );
	}

	/**
	 * Remove the stored token (leaves other settings intact).
	 *
	 * @return bool True when the option was updated.
	 */
	public function delete_token(): bool {
		$settings = get_option( self::OPTION_KEY, [] );
		unset( $settings['access_token'] );
		return update_option( self::OPTION_KEY, $settings, false );
	}

	/**
	 * Return true when a non-empty access token is stored.
	 */
	public function has_token(): bool {
		return '' !== $this->get_token();
	}

	// -------------------------------------------------------------------------
	// Masking
	// -------------------------------------------------------------------------

	/**
	 * Return a masked display string safe for UI output.
	 *
	 * Shows the first 4 and last 4 characters separated by bullet dots.
	 * Very short tokens are fully masked.
	 *
	 * @return string Masked string, or '(not set)' when empty.
	 */
	public function get_masked(): string {
		$token = $this->get_token();

		if ( '' === $token ) {
			return __( '(not set)', 'tsh-whatsapp-notify' );
		}

		$len = strlen( $token );

		if ( $len <= 8 ) {
			return str_repeat( '•', $len );
		}

		return substr( $token, 0, 4 ) . str_repeat( '•', min( $len - 8, 24 ) ) . substr( $token, -4 );
	}

	// -------------------------------------------------------------------------
	// Full credentials read (non-sensitive fields)
	// -------------------------------------------------------------------------

	/**
	 * Return all non-sensitive API credential fields.
	 *
	 * NOTE: access_token is deliberately excluded from this return value.
	 * Call get_token() explicitly when the raw value is needed.
	 *
	 * @return array{
	 *   phone_number_id: string,
	 *   business_account_id: string,
	 *   api_version: string,
	 *   webhook_verify_token: string,
	 *   enable_api: string,
	 *   test_phone_number: string,
	 *   request_timeout: int,
	 *   retry_attempts: int,
	 *   retry_delay: int,
	 * }
	 */
	public function get_credentials(): array {
		$settings = get_option( self::OPTION_KEY, [] );

		return [
			'phone_number_id'      => sanitize_text_field( $settings['phone_number_id']      ?? '' ),
			'business_account_id'  => sanitize_text_field( $settings['business_account_id']  ?? '' ),
			'api_version'          => sanitize_text_field( $settings['api_version']           ?? 'v23.0' ),
			'webhook_verify_token' => sanitize_text_field( $settings['webhook_verify_token']  ?? '' ),
			'enable_api'           => sanitize_text_field( $settings['enable_api']            ?? '0' ),
			'test_phone_number'    => sanitize_text_field( $settings['test_phone_number']     ?? '' ),
			'request_timeout'      => absint( $settings['request_timeout']                    ?? 30 ),
			'retry_attempts'       => absint( $settings['retry_attempts']                     ?? 3 ),
			'retry_delay'          => absint( $settings['retry_delay']                        ?? 5 ),
		];
	}

	/**
	 * Return true when the minimum required credentials are all present.
	 * Does NOT make an API call — credential presence check only.
	 */
	public function has_required_credentials(): bool {
		$creds = $this->get_credentials();

		return '' !== $creds['phone_number_id']
			&& '' !== $creds['business_account_id']
			&& $this->has_token()
			&& '' !== $creds['api_version'];
	}

	// -------------------------------------------------------------------------
	// Export / import (non-sensitive fields only)
	// -------------------------------------------------------------------------

	/**
	 * Return a sanitised settings array safe for JSON export.
	 * The access token is excluded; the token field is included as empty.
	 *
	 * @return array<string, mixed>
	 */
	public function export_settings(): array {
		$creds = $this->get_credentials();

		// Token is intentionally omitted for security.
		return array_merge( $creds, [
			'access_token' => '',
			'exported_at'  => current_time( 'c' ),
		] );
	}
}
