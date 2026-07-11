<?php
/**
 * Admin AJAX action handlers.
 *
 * @package TSH\WhatsAppNotify\Admin
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TSH\WhatsAppNotify\API\ConnectionTester;
use TSH\WhatsAppNotify\API\HealthMonitor;
use TSH\WhatsAppNotify\API\TokenManager;
use TSH\WhatsAppNotify\Database\Installer;
use TSH\WhatsAppNotify\Helpers\Helpers;

/**
 * Class Ajax
 *
 * Registers and handles all wp_ajax_{action} hooks for the plugin admin UI.
 *
 * Security contract for every handler:
 *  1. check_ajax_referer() with the shared plugin nonce.
 *  2. current_user_can( 'manage_woocommerce' ) capability check.
 *  3. All inputs sanitised before use.
 *  4. All outputs escaped before wp_send_json_*().
 *  5. Access token NEVER returned in any AJAX response.
 */
final class Ajax {

	/** @var string Shared nonce action. */
	public const NONCE_ACTION = 'tsh_wa_admin_nonce';

	/**
	 * Constructor — registers all wp_ajax_ hooks.
	 */
	public function __construct() {
		$actions = [
			'tsh_wa_verify_connection',
			'tsh_wa_send_test_message',
			'tsh_wa_run_diagnostics',
			'tsh_wa_refresh_health',
			'tsh_wa_export_api_settings',
			'tsh_wa_reset_api_settings',
		];

		foreach ( $actions as $action ) {
			add_action( 'wp_ajax_' . $action, [ $this, 'handle_' . $action ] );
		}
	}

	// -------------------------------------------------------------------------
	// Verify Connection
	// -------------------------------------------------------------------------

	/**
	 * AJAX: tsh_wa_verify_connection
	 * Runs the multi-step connection test and returns results.
	 */
	public function handle_tsh_wa_verify_connection(): void {
		$this->verify_request();

		$tester = new ConnectionTester();
		$result = $tester->verify_connection();

		// Sanitise all output.
		$safe_steps = [];
		foreach ( $result['steps'] as $step ) {
			$safe_steps[] = [
				'key'    => sanitize_key( $step['key'] ),
				'label'  => sanitize_text_field( $step['label'] ),
				'status' => sanitize_key( $step['status'] ),
				'detail' => sanitize_text_field( $step['detail'] ),
			];
		}

		wp_send_json_success( [
			'connected'      => (bool) $result['connected'],
			'steps'          => $safe_steps,
			'latency_ms'     => round( (float) $result['latency_ms'], 1 ),
			'phone_number'   => sanitize_text_field( $result['phone_number'] ),
			'display_name'   => sanitize_text_field( $result['display_name'] ),
			'quality_rating' => sanitize_key( $result['quality_rating'] ),
			'api_version'    => sanitize_text_field( $result['api_version'] ),
			'error'          => sanitize_text_field( $result['error'] ),
			'error_code'     => sanitize_text_field( $result['error_code'] ),
		] );
	}

	// -------------------------------------------------------------------------
	// Send Test Message
	// -------------------------------------------------------------------------

	/**
	 * AJAX: tsh_wa_send_test_message
	 * Sends a real WhatsApp message to the provided phone number.
	 */
	public function handle_tsh_wa_send_test_message(): void {
		$this->verify_request();

		$phone   = sanitize_text_field( wp_unslash( $_POST['phone']   ?? '' ) );
		$message = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );

		if ( empty( $phone ) ) {
			wp_send_json_error( [
				'message' => __( 'Phone number is required.', 'tsh-whatsapp-notify' ),
			] );
		}

		if ( empty( $message ) ) {
			wp_send_json_error( [
				'message' => __( 'Message body is required.', 'tsh-whatsapp-notify' ),
			] );
		}

		if ( ! Helpers::is_valid_phone( $phone ) ) {
			// Attempt auto-format before rejecting.
			$formatted = Helpers::format_phone( $phone );
			if ( Helpers::is_valid_phone( $formatted ) ) {
				$phone = $formatted;
			} else {
				wp_send_json_error( [
					'message' => __( 'Invalid phone number format. Use E.164, e.g. +2348012345678.', 'tsh-whatsapp-notify' ),
				] );
			}
		}

		$tester = new ConnectionTester();
		$result = $tester->send_test_message( $phone, $message );

		if ( $result['success'] ) {
			wp_send_json_success( [
				'message'            => sanitize_text_field( $result['message'] ),
				'message_id'         => sanitize_text_field( $result['message_id'] ),
				'http_status'        => (int) $result['http_status'],
				'meta_error_code'    => '',
				'meta_error_message' => '',
				'latency_ms'         => round( (float) $result['latency_ms'], 1 ),
				'raw_body'           => Helpers::is_debug_mode() ? wp_kses_post( $result['raw_body'] ) : '',
			] );
		} else {
			wp_send_json_error( [
				'message'            => sanitize_text_field( $result['message'] ),
				'http_status'        => (int) $result['http_status'],
				'meta_error_code'    => sanitize_text_field( $result['meta_error_code'] ),
				'meta_error_message' => sanitize_text_field( $result['meta_error_message'] ),
				'latency_ms'         => round( (float) $result['latency_ms'], 1 ),
				'retry'              => (bool) $result['retry'],
				'raw_body'           => Helpers::is_debug_mode() ? wp_kses_post( $result['raw_body'] ) : '',
			] );
		}
	}

	// -------------------------------------------------------------------------
	// Run Diagnostics
	// -------------------------------------------------------------------------

	/**
	 * AJAX: tsh_wa_run_diagnostics
	 * Runs all system diagnostic checks and returns a structured report.
	 */
	public function handle_tsh_wa_run_diagnostics(): void {
		$this->verify_request();

		$checks = $this->run_all_diagnostic_checks();

		wp_send_json_success( [
			'checks'       => $checks,
			'generated_at' => current_time( 'c' ),
			'site_url'     => esc_url( get_site_url() ),
			'plugin_ver'   => TSH_WA_VERSION,
		] );
	}

	// -------------------------------------------------------------------------
	// Refresh Health
	// -------------------------------------------------------------------------

	/**
	 * AJAX: tsh_wa_refresh_health
	 * Forces a fresh API health check and returns the new status.
	 */
	public function handle_tsh_wa_refresh_health(): void {
		$this->verify_request();

		$monitor = new HealthMonitor();
		$status  = $monitor->refresh();

		// Never return the token.
		wp_send_json_success( [
			'connected'               => (bool) $status['success'],
			'message'                 => sanitize_text_field( $status['message']     ?? '' ),
			'phone_number'            => sanitize_text_field( $status['phone_number']   ?? '' ),
			'display_name'            => sanitize_text_field( $status['display_name']   ?? '' ),
			'quality_rating'          => sanitize_key( $status['quality_rating']         ?? '' ),
			'api_version'             => sanitize_text_field( $status['api_version']    ?? '' ),
			'latency_ms'              => round( (float) ( $status['latency_ms'] ?? 0 ), 1 ),
			'checked_at'              => sanitize_text_field( $status['checked_at']     ?? '' ),
			'messages_today'          => (int) ( $status['messages_today']  ?? 0 ),
			'errors_today'            => (int) ( $status['errors_today']    ?? 0 ),
			'success_rate'            => round( (float) ( $status['success_rate'] ?? 0 ), 1 ),
			'last_successful_request' => sanitize_text_field( $status['last_successful_request'] ?? '' ),
			'last_failed_request'     => sanitize_text_field( $status['last_failed_request']     ?? '' ),
		] );
	}

	// -------------------------------------------------------------------------
	// Export API Settings
	// -------------------------------------------------------------------------

	/**
	 * AJAX: tsh_wa_export_api_settings
	 * Returns a JSON-safe export of non-sensitive API settings.
	 * Access token is intentionally excluded.
	 */
	public function handle_tsh_wa_export_api_settings(): void {
		$this->verify_request();

		$tokens  = new TokenManager();
		$export  = $tokens->export_settings();

		wp_send_json_success( [
			'settings' => $export,
			'filename' => 'tsh-wa-api-settings-' . gmdate( 'Y-m-d' ) . '.json',
		] );
	}

	// -------------------------------------------------------------------------
	// Reset API Settings
	// -------------------------------------------------------------------------

	/**
	 * AJAX: tsh_wa_reset_api_settings
	 * Resets the WhatsApp API settings to defaults.
	 */
	public function handle_tsh_wa_reset_api_settings(): void {
		$this->verify_request();

		$defaults = [
			'enable_api'           => '0',
			'phone_number_id'      => '',
			'business_account_id'  => '',
			'access_token'         => '',
			'api_version'          => 'v23.0',
			'webhook_verify_token' => '',
			'test_phone_number'    => '',
			'request_timeout'      => '30',
			'retry_attempts'       => '3',
			'retry_delay'          => '5',
		];

		update_option( 'tsh_wa_api_settings', $defaults, false );

		// Bust the health-status transient.
		delete_transient( 'tsh_wa_api_health_status' );

		wp_send_json_success( [
			'message' => __( 'API settings have been reset to defaults. Access token cleared.', 'tsh-whatsapp-notify' ),
		] );
	}

	// -------------------------------------------------------------------------
	// Diagnostic checks implementation
	// -------------------------------------------------------------------------

	/**
	 * Run all diagnostic checks and return a structured array.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function run_all_diagnostic_checks(): array {
		global $wpdb, $wp_version;

		$checks = [];

		// PHP version.
		$checks['php'] = [
			'label'   => 'PHP Version',
			'value'   => PHP_VERSION,
			'status'  => version_compare( PHP_VERSION, '8.1', '>=' ) ? 'ok' : 'error',
			'detail'  => 'Minimum required: 8.1',
		];

		// WooCommerce.
		$wc_version = defined( 'WC_VERSION' ) ? WC_VERSION : null;
		$checks['woocommerce'] = [
			'label'  => 'WooCommerce',
			'value'  => $wc_version ?? 'Not active',
			'status' => $wc_version ? 'ok' : 'error',
			'detail' => $wc_version ? 'WooCommerce ' . $wc_version . ' is active.' : 'WooCommerce is required.',
		];

		// OpenSSL.
		$ssl_ok = extension_loaded( 'openssl' ) && defined( 'OPENSSL_VERSION_TEXT' );
		$checks['openssl'] = [
			'label'  => 'OpenSSL',
			'value'  => $ssl_ok ? ( OPENSSL_VERSION_TEXT ) : 'Not available',
			'status' => $ssl_ok ? 'ok' : 'error',
			'detail' => 'Required for HTTPS communication with Meta.',
		];

		// cURL.
		$curl_ok      = extension_loaded( 'curl' );
		$curl_version = $curl_ok && function_exists( 'curl_version' ) ? curl_version()['version'] : '';
		$checks['curl'] = [
			'label'  => 'cURL',
			'value'  => $curl_ok ? ( $curl_version ?: 'Available' ) : 'Not available',
			'status' => $curl_ok ? 'ok' : 'warning',
			'detail' => 'Used by WordPress HTTP API for outbound requests.',
		];

		// JSON extension.
		$json_ok = extension_loaded( 'json' ) && function_exists( 'json_encode' );
		$checks['json'] = [
			'label'  => 'JSON Extension',
			'value'  => $json_ok ? 'Available' : 'Not available',
			'status' => $json_ok ? 'ok' : 'error',
			'detail' => 'Required for API payload encoding/decoding.',
		];

		// WordPress REST API.
		$rest_url = get_rest_url();
		$rest_ok  = ! empty( $rest_url );
		$checks['rest_api'] = [
			'label'  => 'WP REST API',
			'value'  => $rest_ok ? $rest_url : 'Unavailable',
			'status' => $rest_ok ? 'ok' : 'warning',
			'detail' => 'Required for webhook endpoint registration (Phase 3).',
		];

		// WP-Cron.
		$cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$checks['wp_cron'] = [
			'label'  => 'WP-Cron',
			'value'  => $cron_disabled ? 'Disabled (server cron recommended)' : 'Enabled',
			'status' => $cron_disabled ? 'warning' : 'ok',
			'detail' => $cron_disabled
				? 'DISABLE_WP_CRON is true. Ensure a server-level cron runs wp-cron.php.'
				: 'WP-Cron is enabled. Queue processing fires every minute.',
		];

		// Message queue DB table.
		$queue_table  = $wpdb->prefix . 'tsh_wa_queue';
		$queue_exists = $wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SHOW TABLES LIKE '{$queue_table}'"
		) === $queue_table;
		$checks['queue_table'] = [
			'label'  => 'Queue Table',
			'value'  => $queue_exists ? 'Exists' : 'Missing',
			'status' => $queue_exists ? 'ok' : 'error',
			'detail' => 'Table: ' . $queue_table,
		];

		// DB schema version.
		$db_version = get_option( 'tsh_wa_db_version', '0' );
		$db_ok      = version_compare( $db_version, Installer::DB_VERSION, '>=' );
		$checks['db_version'] = [
			'label'  => 'DB Schema Version',
			'value'  => $db_version . ' (current: ' . Installer::DB_VERSION . ')',
			'status' => $db_ok ? 'ok' : 'warning',
			'detail' => $db_ok ? 'Schema is up to date.' : 'Run Tools → Repair Database to upgrade.',
		];

		// API credentials configured.
		$tokens      = new TokenManager();
		$creds_ok    = $tokens->has_required_credentials();
		$checks['api_credentials'] = [
			'label'  => 'API Credentials',
			'value'  => $creds_ok ? 'Configured' : 'Not configured',
			'status' => $creds_ok ? 'ok' : 'warning',
			'detail' => $creds_ok
				? 'Phone Number ID, Business Account ID, and Access Token are set.'
				: 'Enter credentials in Settings → WhatsApp API.',
		];

		// Internet connectivity.
		$internet_response = wp_remote_head( 'https://graph.facebook.com/', [ 'timeout' => 8, 'sslverify' => true ] );
		$internet_ok       = ! is_wp_error( $internet_response );
		$checks['internet'] = [
			'label'  => 'Internet / Meta Connectivity',
			'value'  => $internet_ok ? 'Reachable' : 'Unreachable',
			'status' => $internet_ok ? 'ok' : 'error',
			'detail' => $internet_ok
				? 'graph.facebook.com responded successfully.'
				: ( is_wp_error( $internet_response ) ? $internet_response->get_error_message() : 'Unknown error' ),
		];

		// WhatsApp API (live check — only if credentials present).
		if ( $creds_ok ) {
			$tester     = new ConnectionTester();
			$conn       = $tester->verify_connection();
			$checks['whatsapp_api'] = [
				'label'  => 'WhatsApp Cloud API',
				'value'  => $conn['connected'] ? 'Connected' : 'Disconnected',
				'status' => $conn['connected'] ? 'ok' : 'error',
				'detail' => $conn['connected']
					? sprintf( 'Phone: %s | Latency: %sms', $conn['phone_number'], $conn['latency_ms'] )
					: $conn['error'],
			];
		} else {
			$checks['whatsapp_api'] = [
				'label'  => 'WhatsApp Cloud API',
				'value'  => 'Not tested',
				'status' => 'warning',
				'detail' => 'Configure credentials to enable API connectivity check.',
			];
		}

		return $checks;
	}

	// -------------------------------------------------------------------------
	// Security helper
	// -------------------------------------------------------------------------

	/**
	 * Verify nonce and capability. Dies on failure.
	 */
	private function verify_request(): void {
		check_ajax_referer( self::NONCE_ACTION, '_ajax_nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'tsh-whatsapp-notify' ) ], 403 );
		}
	}
}
