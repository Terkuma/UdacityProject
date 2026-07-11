<?php
/**
 * Meta WhatsApp Cloud API provider.
 *
 * @package TSH\WhatsAppNotify\API
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MetaCloudProvider
 *
 * Official implementation of ProviderInterface for the Meta WhatsApp
 * Business Cloud API (Graph API).
 *
 * Documentation: https://developers.facebook.com/docs/whatsapp/cloud-api
 *
 * Base URL pattern:
 *   https://graph.facebook.com/{api_version}/{resource}
 *
 * All HTTP communication is delegated to ApiClient. This class only builds
 * request payloads and interprets response data.
 */
final class MetaCloudProvider implements ProviderInterface {

	/** @var string Meta Graph API base URL (without version). */
	private const GRAPH_BASE = 'https://graph.facebook.com/';

	/** @var ApiClient The shared HTTP client. */
	private ApiClient $client;

	/** @var TokenManager Credential accessor. */
	private TokenManager $token_manager;

	/** @var string Configured Phone Number ID. */
	private string $phone_number_id;

	/** @var string Configured Business Account ID (WABA ID). */
	private string $business_account_id;

	/** @var string Graph API version string, e.g. 'v23.0'. */
	private string $api_version;

	/** @var bool Whether debug mode is active. */
	private bool $debug_mode;

	/**
	 * Initialise the provider from stored settings.
	 *
	 * @throws ConfigurationException When required credentials are absent.
	 */
	public function __construct() {
		$this->token_manager = new TokenManager();
		$creds               = $this->token_manager->get_credentials();

		$this->phone_number_id      = $creds['phone_number_id'];
		$this->business_account_id  = $creds['business_account_id'];
		$this->api_version          = $creds['api_version'] ?: 'v23.0';
		$this->debug_mode           = $this->resolve_debug_mode();

		$base_url     = self::GRAPH_BASE . $this->api_version . '/';
		$this->client = new ApiClient(
			$base_url,
			$this->token_manager,
			$creds['request_timeout'],
			$creds['retry_attempts'],
			$creds['retry_delay'],
			$this->debug_mode
		);
	}

	// -------------------------------------------------------------------------
	// ProviderInterface — Lifecycle
	// -------------------------------------------------------------------------

	/**
	 * {@inheritdoc}
	 */
	public function connect(): bool {
		if ( ! $this->token_manager->has_required_credentials() ) {
			return false;
		}

		try {
			$result = $this->getPhoneInfo();
			return $result['success'];
		} catch ( ApiException ) {
			return false;
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function verify(): array {
		$start = microtime( true );

		try {
			$phone_result = $this->getPhoneInfo();
		} catch ( ApiException $e ) {
			return $this->failure_result( $e, microtime( true ) - $start );
		}

		if ( ! $phone_result['success'] ) {
			return array_merge( $phone_result, [
				'phone_number'   => '',
				'display_name'   => '',
				'quality_rating' => '',
				'latency_ms'     => round( ( microtime( true ) - $start ) * 1000, 1 ),
			] );
		}

		$data = $phone_result['data'];

		return [
			'success'        => true,
			'message'        => __( 'Credentials verified successfully.', 'tsh-whatsapp-notify' ),
			'dev_message'    => '',
			'http_status'    => $phone_result['http_status'],
			'error_code'     => '',
			'retry'          => false,
			'phone_number'   => sanitize_text_field( $data['display_phone_number'] ?? '' ),
			'display_name'   => sanitize_text_field( $data['verified_name']        ?? '' ),
			'quality_rating' => sanitize_key( $data['quality_rating']              ?? '' ),
			'latency_ms'     => round( ( microtime( true ) - $start ) * 1000, 1 ),
		];
	}

	// -------------------------------------------------------------------------
	// ProviderInterface — Messaging
	// -------------------------------------------------------------------------

	/**
	 * {@inheritdoc}
	 */
	public function sendMessage( string $phone, string $message ): array {
		if ( ! $this->token_manager->has_required_credentials() ) {
			throw new ConfigurationException();
		}

		$start = microtime( true );

		$payload = [
			'messaging_product' => 'whatsapp',
			'recipient_type'    => 'individual',
			'to'                => $this->normalise_phone( $phone ),
			'type'              => 'text',
			'text'              => [
				'preview_url' => false,
				'body'        => $message,
			],
		];

		try {
			$response = $this->client->post( $this->phone_number_id . '/messages', $payload );
		} catch ( ApiException $e ) {
			return $this->failure_result( $e, microtime( true ) - $start );
		}

		$message_id = $response['data']['messages'][0]['id'] ?? '';

		return array_merge( $response, [
			'message'    => $response['success']
				? __( 'Message sent successfully.', 'tsh-whatsapp-notify' )
				: ( $response['error_message'] ?? __( 'Message send failed.', 'tsh-whatsapp-notify' ) ),
			'dev_message' => '',
			'message_id' => sanitize_text_field( $message_id ),
			'latency_ms' => round( ( microtime( true ) - $start ) * 1000, 1 ),
		] );
	}

	/**
	 * {@inheritdoc}
	 */
	public function sendTemplate(
		string $phone,
		string $template_name,
		string $language,
		array $components = []
	): array {
		if ( ! $this->token_manager->has_required_credentials() ) {
			throw new ConfigurationException();
		}

		$start = microtime( true );

		$payload = [
			'messaging_product' => 'whatsapp',
			'recipient_type'    => 'individual',
			'to'                => $this->normalise_phone( $phone ),
			'type'              => 'template',
			'template'          => [
				'name'       => $template_name,
				'language'   => [ 'code' => $language ],
				'components' => $components,
			],
		];

		try {
			$response = $this->client->post( $this->phone_number_id . '/messages', $payload );
		} catch ( ApiException $e ) {
			return $this->failure_result( $e, microtime( true ) - $start );
		}

		$message_id = $response['data']['messages'][0]['id'] ?? '';

		return array_merge( $response, [
			'message'     => $response['success']
				? __( 'Template message sent successfully.', 'tsh-whatsapp-notify' )
				: ( $response['error_message'] ?? __( 'Template send failed.', 'tsh-whatsapp-notify' ) ),
			'dev_message' => '',
			'message_id'  => sanitize_text_field( $message_id ),
			'latency_ms'  => round( ( microtime( true ) - $start ) * 1000, 1 ),
		] );
	}

	// -------------------------------------------------------------------------
	// ProviderInterface — Media
	// -------------------------------------------------------------------------

	/**
	 * {@inheritdoc}
	 */
	public function uploadMedia( string $file_path, string $mime_type ): array {
		// Media upload requires multipart/form-data — full implementation Phase 3+.
		return [
			'success'     => false,
			'message'     => __( 'Media upload is available in a future phase.', 'tsh-whatsapp-notify' ),
			'dev_message' => 'uploadMedia() is a Phase 3+ feature.',
			'http_status' => 0,
			'error_code'  => 'NOT_IMPLEMENTED',
			'retry'       => false,
			'media_id'    => '',
			'latency_ms'  => 0.0,
		];
	}

	// -------------------------------------------------------------------------
	// ProviderInterface — Account info
	// -------------------------------------------------------------------------

	/**
	 * {@inheritdoc}
	 */
	public function getPhoneInfo(): array {
		if ( ! $this->phone_number_id ) {
			throw new ConfigurationException(
				__( 'Phone Number ID is not configured.', 'tsh-whatsapp-notify' )
			);
		}

		$response = $this->client->get( $this->phone_number_id, [
			'fields' => 'id,display_phone_number,verified_name,quality_rating,platform_type,throughput,webhook_configuration',
		] );

		return array_merge( $response, [
			'id'             => sanitize_text_field( $response['data']['id']                   ?? '' ),
			'display_phone'  => sanitize_text_field( $response['data']['display_phone_number'] ?? '' ),
			'verified_name'  => sanitize_text_field( $response['data']['verified_name']        ?? '' ),
			'quality_rating' => sanitize_key( $response['data']['quality_rating']              ?? '' ),
			'status'         => sanitize_key( $response['data']['platform_type']               ?? '' ),
		] );
	}

	/**
	 * {@inheritdoc}
	 */
	public function getBusinessInfo(): array {
		if ( ! $this->business_account_id ) {
			throw new ConfigurationException(
				__( 'Business Account ID is not configured.', 'tsh-whatsapp-notify' )
			);
		}

		$response = $this->client->get( $this->business_account_id, [
			'fields' => 'id,name,currency,timezone_id,message_template_namespace',
		] );

		return array_merge( $response, [
			'id'          => sanitize_text_field( $response['data']['id']          ?? '' ),
			'name'        => sanitize_text_field( $response['data']['name']         ?? '' ),
			'currency'    => sanitize_text_field( $response['data']['currency']     ?? '' ),
			'timezone_id' => sanitize_text_field( $response['data']['timezone_id'] ?? '' ),
		] );
	}

	// -------------------------------------------------------------------------
	// ProviderInterface — Health
	// -------------------------------------------------------------------------

	/**
	 * {@inheritdoc}
	 */
	public function healthCheck(): array {
		$start = microtime( true );

		try {
			$phone = $this->getPhoneInfo();
		} catch ( ApiException $e ) {
			return array_merge(
				$this->failure_result( $e, microtime( true ) - $start ),
				[
					'phone_number'   => '',
					'display_name'   => '',
					'quality_rating' => '',
					'api_version'    => $this->api_version,
				]
			);
		}

		$elapsed = round( ( microtime( true ) - $start ) * 1000, 1 );

		return [
			'success'        => $phone['success'],
			'message'        => $phone['success']
				? __( 'API health check passed.', 'tsh-whatsapp-notify' )
				: ( $phone['error_message'] ?? __( 'Health check failed.', 'tsh-whatsapp-notify' ) ),
			'dev_message'    => '',
			'http_status'    => $phone['http_status'],
			'error_code'     => $phone['error_code']    ?? '',
			'retry'          => $phone['retry']          ?? false,
			'phone_number'   => sanitize_text_field( $phone['data']['display_phone_number'] ?? '' ),
			'display_name'   => sanitize_text_field( $phone['data']['verified_name']        ?? '' ),
			'quality_rating' => sanitize_key( $phone['data']['quality_rating']              ?? '' ),
			'api_version'    => $this->api_version,
			'latency_ms'     => $elapsed,
		];
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Normalise a phone number for Meta API consumption.
	 * Meta requires digits only (no + prefix) in the 'to' field.
	 *
	 * @param string $phone E.164 or local format.
	 * @return string Digits-only string.
	 */
	private function normalise_phone( string $phone ): string {
		// Strip everything except digits.
		$digits = (string) preg_replace( '/\D/', '', $phone );

		// Meta accepts the number with a country code, no leading +.
		// If caller passed an E.164 with leading '+', it's already stripped.
		return $digits;
	}

	/**
	 * Build a structured failure result from an ApiException.
	 *
	 * @param ApiException $e
	 * @param float        $elapsed_seconds Elapsed time since start.
	 * @return array<string, mixed>
	 */
	private function failure_result( ApiException $e, float $elapsed_seconds ): array {
		return [
			'success'     => false,
			'message'     => $e->getMessage(),
			'dev_message' => $e->get_developer_message(),
			'http_status' => $e->get_http_status(),
			'error_code'  => $e->get_meta_error_code(),
			'retry'       => $e->is_retry_recommended(),
			'data'        => [],
			'latency_ms'  => round( $elapsed_seconds * 1000, 1 ),
		];
	}

	/**
	 * Determine whether debug mode is active.
	 *
	 * Checks both the plugin advanced setting and WP_DEBUG.
	 */
	private function resolve_debug_mode(): bool {
		$advanced = get_option( 'tsh_wa_advanced_settings', [] );
		return '1' === ( $advanced['debug_mode'] ?? '0' )
			|| ( defined( 'WP_DEBUG' ) && WP_DEBUG );
	}
}
