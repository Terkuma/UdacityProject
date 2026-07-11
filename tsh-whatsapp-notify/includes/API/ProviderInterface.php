<?php
/**
 * WhatsApp provider contract.
 *
 * Every provider (Meta Cloud, Twilio, 360Dialog, …) must implement this
 * interface. The rest of the plugin communicates exclusively through this
 * contract — never through a concrete provider class directly.
 *
 * Return shape conventions
 * ─────────────────────────
 * All methods return an associative array with at least:
 *   bool        success        Whether the operation succeeded.
 *   string      message        Human-readable summary.
 *   string      dev_message    Technical detail for logs / debug mode.
 *   int         http_status    HTTP status code (0 when no request was made).
 *   string      error_code     Provider-specific error code, or '' on success.
 *   bool        retry          Whether a retry is recommended.
 *
 * Additional keys are documented per method.
 *
 * @package TSH\WhatsAppNotify\API
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface ProviderInterface
 *
 * Defines the communication contract for WhatsApp messaging providers.
 */
interface ProviderInterface {

	// -------------------------------------------------------------------------
	// Lifecycle
	// -------------------------------------------------------------------------

	/**
	 * Attempt to establish a connection using the stored credentials.
	 *
	 * @return bool True when credentials are present and the API responds.
	 */
	public function connect(): bool;

	/**
	 * Verify credentials and return account details.
	 *
	 * Additional keys in the return array:
	 *   string  phone_number      Verified display phone number, or ''.
	 *   string  display_name      Business display name, or ''.
	 *   string  quality_rating    e.g. 'GREEN', 'YELLOW', 'RED', or ''.
	 *   float   latency_ms        Round-trip time in milliseconds.
	 *
	 * @return array<string, mixed>
	 */
	public function verify(): array;

	// -------------------------------------------------------------------------
	// Messaging
	// -------------------------------------------------------------------------

	/**
	 * Send a plain-text WhatsApp message to a phone number.
	 *
	 * Additional keys:
	 *   string  message_id   Meta message ID on success, or ''.
	 *   float   latency_ms
	 *
	 * @param string $phone   E.164-formatted recipient phone number.
	 * @param string $message Message body (max 4096 chars per Meta spec).
	 * @return array<string, mixed>
	 */
	public function sendMessage( string $phone, string $message ): array;

	/**
	 * Send a pre-approved WhatsApp template message.
	 *
	 * Additional keys:
	 *   string  message_id
	 *   float   latency_ms
	 *
	 * @param string               $phone          E.164 recipient.
	 * @param string               $template_name  Approved template name.
	 * @param string               $language       BCP-47 language code, e.g. 'en_US'.
	 * @param array<string, mixed> $components     Template body/header/button components.
	 * @return array<string, mixed>
	 */
	public function sendTemplate(
		string $phone,
		string $template_name,
		string $language,
		array $components = []
	): array;

	// -------------------------------------------------------------------------
	// Media
	// -------------------------------------------------------------------------

	/**
	 * Upload a media file and return the provider's media ID.
	 *
	 * Additional keys:
	 *   string  media_id   Provider media ID on success, or ''.
	 *
	 * @param string $file_path  Absolute path to the local file.
	 * @param string $mime_type  MIME type, e.g. 'image/jpeg'.
	 * @return array<string, mixed>
	 */
	public function uploadMedia( string $file_path, string $mime_type ): array;

	// -------------------------------------------------------------------------
	// Account info
	// -------------------------------------------------------------------------

	/**
	 * Retrieve information about the configured WhatsApp phone number.
	 *
	 * Additional keys:
	 *   string  id               Phone Number ID.
	 *   string  display_phone    Human-readable number.
	 *   string  verified_name    Business name associated with the number.
	 *   string  quality_rating   GREEN | YELLOW | RED | UNKNOWN.
	 *   string  status           CONNECTED | DISCONNECTED | etc.
	 *
	 * @return array<string, mixed>
	 */
	public function getPhoneInfo(): array;

	/**
	 * Retrieve information about the configured WhatsApp Business Account.
	 *
	 * Additional keys:
	 *   string  id             WABA ID.
	 *   string  name           Account name.
	 *   string  currency       Billing currency.
	 *   string  timezone_id    Timezone string.
	 *
	 * @return array<string, mixed>
	 */
	public function getBusinessInfo(): array;

	// -------------------------------------------------------------------------
	// Health
	// -------------------------------------------------------------------------

	/**
	 * Run a lightweight health check (typically getPhoneInfo with timeout).
	 *
	 * Additional keys:
	 *   float   latency_ms
	 *   string  phone_number
	 *   string  display_name
	 *   string  quality_rating
	 *   string  api_version
	 *
	 * @return array<string, mixed>
	 */
	public function healthCheck(): array;
}
