<?php
/**
 * WhatsApp API connection tester.
 *
 * @package TSH\WhatsAppNotify\API
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ConnectionTester
 *
 * Runs a multi-step connection verification and optional test message send.
 *
 * Steps in verify_connection()
 * ─────────────────────────────
 * 1. Local credential check   — are the required fields non-empty?
 * 2. Internet connectivity    — can we reach graph.facebook.com?
 * 3. Token validation         — does the API accept our access token?
 * 4. Phone number check       — does the Phone Number ID resolve correctly?
 * 5. Business account check   — does the Business Account ID resolve correctly?
 *
 * Each step returns a status (ok | warning | error) plus a human-readable label,
 * a detail string, and whether the remaining steps should be skipped.
 */
final class ConnectionTester {

	/** @var TokenManager */
	private TokenManager $token_manager;

	/** @var ProviderInterface|null Provider instance (created lazily). */
	private ?ProviderInterface $provider = null;

	public function __construct() {
		$this->token_manager = new TokenManager();
	}

	// -------------------------------------------------------------------------
	// Connection verification
	// -------------------------------------------------------------------------

	/**
	 * Run all verification steps and return a comprehensive result.
	 *
	 * @return array{
	 *   connected: bool,
	 *   steps: array<int, array<string, mixed>>,
	 *   latency_ms: float,
	 *   phone_number: string,
	 *   display_name: string,
	 *   quality_rating: string,
	 *   api_version: string,
	 *   error: string,
	 *   error_code: string,
	 * }
	 */
	public function verify_connection(): array {
		$steps      = [];
		$start      = microtime( true );
		$connected  = false;
		$error      = '';
		$error_code = '';
		$phone_info = [
			'phone_number'   => '',
			'display_name'   => '',
			'quality_rating' => '',
			'api_version'    => $this->token_manager->get_credentials()['api_version'],
		];

		// Step 1 — Credential presence.
		$step1 = $this->step_check_credentials();
		$steps[] = $step1;

		if ( 'error' === $step1['status'] ) {
			$error = $step1['detail'];
			return $this->build_result( false, $steps, $start, $phone_info, $error, $error_code );
		}

		// Step 2 — Internet connectivity.
		$step2   = $this->step_check_internet();
		$steps[] = $step2;

		if ( 'error' === $step2['status'] ) {
			$error = $step2['detail'];
			return $this->build_result( false, $steps, $start, $phone_info, $error, $error_code );
		}

		// Steps 3–5 — API calls (require provider).
		try {
			$this->provider = new MetaCloudProvider();
		} catch ( ConfigurationException $e ) {
			$steps[] = [
				'key'    => 'provider',
				'label'  => __( 'Provider Initialisation', 'tsh-whatsapp-notify' ),
				'status' => 'error',
				'detail' => $e->getMessage(),
			];
			return $this->build_result( false, $steps, $start, $phone_info, $e->getMessage(), $e->get_meta_error_code() );
		}

		// Step 3 — Phone number ID.
		$step3   = $this->step_check_phone_info();
		$steps[] = $step3;

		if ( isset( $step3['phone_number'] ) ) {
			$phone_info['phone_number']   = $step3['phone_number'];
			$phone_info['display_name']   = $step3['display_name'];
			$phone_info['quality_rating'] = $step3['quality_rating'];
		}

		if ( 'error' === $step3['status'] ) {
			$error      = $step3['detail'];
			$error_code = $step3['error_code'] ?? '';
			return $this->build_result( false, $steps, $start, $phone_info, $error, $error_code );
		}

		// Step 4 — Business account ID.
		$step4   = $this->step_check_business_info();
		$steps[] = $step4;

		if ( 'error' === $step4['status'] ) {
			$error      = $step4['detail'];
			$error_code = $step4['error_code'] ?? '';
			// Non-fatal: phone info succeeded — mark as partially connected.
			$steps[] = [
				'key'    => 'summary',
				'label'  => __( 'Connection Summary', 'tsh-whatsapp-notify' ),
				'status' => 'warning',
				'detail' => __( 'Phone number verified, but Business Account ID check failed.', 'tsh-whatsapp-notify' ),
			];
			return $this->build_result( true, $steps, $start, $phone_info, $error, $error_code );
		}

		// All steps passed.
		$connected = true;
		$steps[]   = [
			'key'    => 'summary',
			'label'  => __( 'Connection Summary', 'tsh-whatsapp-notify' ),
			'status' => 'ok',
			'detail' => __( 'All checks passed. Your credentials are working correctly.', 'tsh-whatsapp-notify' ),
		];

		return $this->build_result( true, $steps, $start, $phone_info, '', '' );
	}

	// -------------------------------------------------------------------------
	// Test message
	// -------------------------------------------------------------------------

	/**
	 * Send a real WhatsApp test message.
	 *
	 * @param string $phone   E.164-formatted recipient (with or without +).
	 * @param string $message Message body.
	 * @return array{
	 *   success: bool,
	 *   http_status: int,
	 *   meta_error_code: string,
	 *   meta_error_message: string,
	 *   message_id: string,
	 *   latency_ms: float,
	 *   message: string,
	 *   retry: bool,
	 *   raw_body: string,
	 * }
	 */
	public function send_test_message( string $phone, string $message ): array {
		if ( ! $this->token_manager->has_required_credentials() ) {
			return [
				'success'            => false,
				'http_status'        => 0,
				'meta_error_code'    => 'CONFIGURATION_ERROR',
				'meta_error_message' => __( 'Please configure your API credentials first.', 'tsh-whatsapp-notify' ),
				'message_id'         => '',
				'latency_ms'         => 0.0,
				'message'            => __( 'Missing API credentials.', 'tsh-whatsapp-notify' ),
				'retry'              => false,
				'raw_body'           => '',
			];
		}

		try {
			$provider = new MetaCloudProvider();
			$result   = $provider->sendMessage( $phone, $message );
		} catch ( ApiException $e ) {
			return [
				'success'            => false,
				'http_status'        => $e->get_http_status(),
				'meta_error_code'    => $e->get_meta_error_code(),
				'meta_error_message' => $e->get_meta_error_message(),
				'message_id'         => '',
				'latency_ms'         => 0.0,
				'message'            => $e->getMessage(),
				'retry'              => $e->is_retry_recommended(),
				'raw_body'           => '',
			];
		}

		return [
			'success'            => $result['success'],
			'http_status'        => $result['http_status'],
			'meta_error_code'    => $result['error_code']    ?? '',
			'meta_error_message' => $result['error_message'] ?? '',
			'message_id'         => $result['message_id']    ?? '',
			'latency_ms'         => $result['latency_ms']    ?? 0.0,
			'message'            => $result['message']       ?? '',
			'retry'              => $result['retry']          ?? false,
			'raw_body'           => $result['raw_body']       ?? '',
		];
	}

	// -------------------------------------------------------------------------
	// Individual steps
	// -------------------------------------------------------------------------

	/**
	 * Step 1: Verify that all required credentials are non-empty.
	 *
	 * @return array<string, mixed>
	 */
	private function step_check_credentials(): array {
		$creds = $this->token_manager->get_credentials();
		$has   = $this->token_manager->has_required_credentials();

		$missing = [];
		if ( ! $creds['phone_number_id'] )     { $missing[] = __( 'Phone Number ID', 'tsh-whatsapp-notify' ); }
		if ( ! $creds['business_account_id'] )  { $missing[] = __( 'Business Account ID', 'tsh-whatsapp-notify' ); }
		if ( ! $this->token_manager->has_token() ) { $missing[] = __( 'Access Token', 'tsh-whatsapp-notify' ); }

		if ( ! $has ) {
			return [
				'key'    => 'credentials',
				'label'  => __( 'Credential Presence', 'tsh-whatsapp-notify' ),
				'status' => 'error',
				/* translators: %s: comma-separated list of missing fields */
				'detail' => sprintf( __( 'Missing: %s', 'tsh-whatsapp-notify' ), implode( ', ', $missing ) ),
			];
		}

		return [
			'key'    => 'credentials',
			'label'  => __( 'Credential Presence', 'tsh-whatsapp-notify' ),
			'status' => 'ok',
			'detail' => __( 'All required credentials are present.', 'tsh-whatsapp-notify' ),
		];
	}

	/**
	 * Step 2: Verify internet connectivity to Meta's API endpoint.
	 *
	 * @return array<string, mixed>
	 */
	private function step_check_internet(): array {
		$response = wp_remote_head( 'https://graph.facebook.com/', [
			'timeout'   => 10,
			'sslverify' => true,
		] );

		if ( is_wp_error( $response ) ) {
			return [
				'key'    => 'internet',
				'label'  => __( 'Internet / DNS', 'tsh-whatsapp-notify' ),
				'status' => 'error',
				'detail' => sprintf(
					/* translators: %s: WP_Error message */
					__( 'Cannot reach graph.facebook.com: %s', 'tsh-whatsapp-notify' ),
					$response->get_error_message()
				),
			];
		}

		return [
			'key'    => 'internet',
			'label'  => __( 'Internet / DNS', 'tsh-whatsapp-notify' ),
			'status' => 'ok',
			'detail' => __( 'graph.facebook.com is reachable.', 'tsh-whatsapp-notify' ),
		];
	}

	/**
	 * Step 3: Validate Phone Number ID by calling getPhoneInfo().
	 *
	 * @return array<string, mixed>
	 */
	private function step_check_phone_info(): array {
		try {
			$result = $this->provider->getPhoneInfo();
		} catch ( ApiException $e ) {
			return [
				'key'        => 'phone_number',
				'label'      => __( 'Phone Number ID', 'tsh-whatsapp-notify' ),
				'status'     => 'error',
				'detail'     => $e->getMessage(),
				'error_code' => $e->get_meta_error_code(),
			];
		}

		if ( ! $result['success'] ) {
			return [
				'key'        => 'phone_number',
				'label'      => __( 'Phone Number ID', 'tsh-whatsapp-notify' ),
				'status'     => 'error',
				'detail'     => $result['error_message'] ?? __( 'Phone Number ID validation failed.', 'tsh-whatsapp-notify' ),
				'error_code' => $result['error_code']    ?? '',
			];
		}

		$display_phone  = sanitize_text_field( $result['data']['display_phone_number'] ?? '' );
		$verified_name  = sanitize_text_field( $result['data']['verified_name']        ?? '' );
		$quality_rating = sanitize_key( $result['data']['quality_rating']              ?? '' );

		return [
			'key'            => 'phone_number',
			'label'          => __( 'Phone Number ID', 'tsh-whatsapp-notify' ),
			'status'         => 'ok',
			'detail'         => sprintf(
				/* translators: 1: display phone, 2: verified name */
				__( 'Phone: %1$s — Business: %2$s', 'tsh-whatsapp-notify' ),
				$display_phone,
				$verified_name
			),
			'phone_number'   => $display_phone,
			'display_name'   => $verified_name,
			'quality_rating' => $quality_rating,
		];
	}

	/**
	 * Step 4: Validate Business Account ID by calling getBusinessInfo().
	 *
	 * @return array<string, mixed>
	 */
	private function step_check_business_info(): array {
		try {
			$result = $this->provider->getBusinessInfo();
		} catch ( ApiException $e ) {
			return [
				'key'        => 'business_account',
				'label'      => __( 'Business Account ID', 'tsh-whatsapp-notify' ),
				'status'     => 'error',
				'detail'     => $e->getMessage(),
				'error_code' => $e->get_meta_error_code(),
			];
		}

		if ( ! $result['success'] ) {
			return [
				'key'        => 'business_account',
				'label'      => __( 'Business Account ID', 'tsh-whatsapp-notify' ),
				'status'     => 'error',
				'detail'     => $result['error_message'] ?? __( 'Business Account ID validation failed.', 'tsh-whatsapp-notify' ),
				'error_code' => $result['error_code']    ?? '',
			];
		}

		$name     = sanitize_text_field( $result['data']['name']     ?? '' );
		$currency = sanitize_text_field( $result['data']['currency']  ?? '' );

		return [
			'key'    => 'business_account',
			'label'  => __( 'Business Account ID', 'tsh-whatsapp-notify' ),
			'status' => 'ok',
			'detail' => sprintf(
				/* translators: 1: business name, 2: currency */
				__( 'Account: %1$s (%2$s)', 'tsh-whatsapp-notify' ),
				$name,
				$currency
			),
		];
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Assemble the final verify_connection() return array.
	 *
	 * @param bool                 $connected
	 * @param array<int, mixed>    $steps
	 * @param float                $start         microtime(true) at test start.
	 * @param array<string, mixed> $phone_info
	 * @param string               $error
	 * @param string               $error_code
	 * @return array<string, mixed>
	 */
	private function build_result(
		bool $connected,
		array $steps,
		float $start,
		array $phone_info,
		string $error,
		string $error_code
	): array {
		return [
			'connected'      => $connected,
			'steps'          => $steps,
			'latency_ms'     => round( ( microtime( true ) - $start ) * 1000, 1 ),
			'phone_number'   => $phone_info['phone_number'],
			'display_name'   => $phone_info['display_name'],
			'quality_rating' => $phone_info['quality_rating'],
			'api_version'    => $phone_info['api_version'],
			'error'          => $error,
			'error_code'     => $error_code,
		];
	}
}
