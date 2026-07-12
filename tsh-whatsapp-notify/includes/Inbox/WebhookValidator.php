<?php
/**
 * Webhook signature validator.
 *
 * @package TSH\WhatsAppNotify\Inbox
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Inbox;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WebhookValidator
 *
 * Validates incoming Meta webhook requests using HMAC-SHA256 signature
 * verification. The App Secret is read from the plugin's API settings.
 *
 * Meta signs every webhook POST with:
 *   X-Hub-Signature-256: sha256=<hex>
 * where hex = HMAC-SHA256( app_secret, raw_body ).
 */
final class WebhookValidator {

	/**
	 * Validate the HMAC-SHA256 signature on an incoming webhook request.
	 *
	 * @param string $raw_body         Raw request body (before JSON decoding).
	 * @param string $signature_header Value of the X-Hub-Signature-256 header.
	 * @return bool True if the signature is valid.
	 */
	public function validate_signature( string $raw_body, string $signature_header ): bool {
		$app_secret = $this->get_app_secret();

		// If no app secret is configured, fail closed — never open.
		if ( empty( $app_secret ) ) {
			return false;
		}

		// The header format is "sha256=<hex>".
		if ( ! str_starts_with( $signature_header, 'sha256=' ) ) {
			return false;
		}

		$provided_hash  = substr( $signature_header, 7 );
		$expected_hash  = hash_hmac( 'sha256', $raw_body, $app_secret );

		// Timing-safe comparison.
		return hash_equals( $expected_hash, $provided_hash );
	}

	/**
	 * Verify a GET challenge for webhook subscription verification.
	 *
	 * Meta sends:
	 *   GET ?hub.mode=subscribe&hub.verify_token=<token>&hub.challenge=<string>
	 *
	 * @param string $mode         hub.mode parameter.
	 * @param string $verify_token hub.verify_token parameter.
	 * @param string $challenge    hub.challenge parameter.
	 * @return string|null The challenge string if valid, null otherwise.
	 */
	public function verify_challenge( string $mode, string $verify_token, string $challenge ): ?string {
		if ( 'subscribe' !== $mode ) {
			return null;
		}

		$stored_token = $this->get_verify_token();
		if ( empty( $stored_token ) || ! hash_equals( $stored_token, $verify_token ) ) {
			return null;
		}

		return $challenge;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Read the App Secret from API settings.
	 * We reuse the access_token field's paired secret if set; otherwise we fall
	 * back to a dedicated app_secret field in API settings.
	 *
	 * @return string
	 */
	private function get_app_secret(): string {
		$settings = get_option( 'tsh_wa_api_settings', [] );
		$secret   = $settings['app_secret'] ?? '';
		return is_string( $secret ) ? trim( $secret ) : '';
	}

	/**
	 * Read the webhook verify token from API settings.
	 *
	 * @return string
	 */
	private function get_verify_token(): string {
		$settings = get_option( 'tsh_wa_api_settings', [] );
		$token    = $settings['webhook_verify_token'] ?? '';
		return is_string( $token ) ? trim( $token ) : '';
	}
}
