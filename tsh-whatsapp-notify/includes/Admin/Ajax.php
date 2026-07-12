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
use TSH\WhatsAppNotify\Orders\AdminRecipient;
use TSH\WhatsAppNotify\Orders\OrderMessageBuilder;
use TSH\WhatsAppNotify\Orders\OrderProcessor;
use TSH\WhatsAppNotify\Orders\OrderQueueDispatcher;
use TSH\WhatsAppNotify\Queue\DeadLetterQueue;
use TSH\WhatsAppNotify\Queue\QueueProcessor;
use TSH\WhatsAppNotify\Queue\QueueStats;
use TSH\WhatsAppNotify\Queue\RateLimiter;
use TSH\WhatsAppNotify\Templates\TemplateAssignment;
use TSH\WhatsAppNotify\Templates\TemplateExporter;
use TSH\WhatsAppNotify\Templates\TemplateImporter;
use TSH\WhatsAppNotify\Templates\TemplateManager;
use TSH\WhatsAppNotify\Templates\TemplateSync;

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
			// Phase 2 — API engine.
			'tsh_wa_verify_connection',
			'tsh_wa_send_test_message',
			'tsh_wa_run_diagnostics',
			'tsh_wa_refresh_health',
			'tsh_wa_export_api_settings',
			'tsh_wa_reset_api_settings',
			// Phase 3 — Order integration.
			'tsh_wa_get_order_preview',
			'tsh_wa_queue_order_notification',
			'tsh_wa_resend_order_notification',
			'tsh_wa_save_admin_recipients',
			'tsh_wa_get_order_notifications',
			'tsh_wa_delete_admin_recipient',
			// Phase 4 — Queue delivery engine.
			'tsh_wa_queue_pause',
			'tsh_wa_queue_resume',
			'tsh_wa_queue_process_now',
			'tsh_wa_get_queue_stats',
			'tsh_wa_get_queue_health',
			'tsh_wa_dlq_retry',
			'tsh_wa_dlq_delete',
			'tsh_wa_dlq_clear',
			'tsh_wa_queue_export',
			'tsh_wa_queue_retry_all',
			// Phase 5 — Template management.
			'tsh_wa_sync_templates',
			'tsh_wa_force_full_sync',
			'tsh_wa_get_template_preview',
			'tsh_wa_assign_template',
			'tsh_wa_unassign_template',
			'tsh_wa_get_template_assignments',
			'tsh_wa_search_templates',
			'tsh_wa_get_templates_page',
			'tsh_wa_validate_template',
			'tsh_wa_test_template',
			'tsh_wa_import_templates',
			'tsh_wa_export_templates',
			'tsh_wa_flush_template_cache',
			'tsh_wa_get_template_analytics',
			'tsh_wa_get_template_stats',
		];

		foreach ( $actions as $action ) {
			add_action( 'wp_ajax_' . $action, [ $this, 'handle_' . $action ] );
		}
	}

	// -------------------------------------------------------------------------
	// Phase 3 — Order integration handlers
	// -------------------------------------------------------------------------

	/**
	 * AJAX: tsh_wa_get_order_preview
	 * Returns rendered message previews for a given order + event.
	 */
	public function handle_tsh_wa_get_order_preview(): void {
		$this->verify_request();

		$order_id  = absint( $_POST['order_id'] ?? 0 );
		$event_key = sanitize_key( $_POST['event_key'] ?? 'processing' );

		if ( ! $order_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid order ID.', 'tsh-whatsapp-notify' ) ] );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			wp_send_json_error( [ 'message' => __( 'Order not found.', 'tsh-whatsapp-notify' ) ] );
		}

		$builder       = new OrderMessageBuilder();
		$event_settings = get_option( 'tsh_wa_wc_events_settings', [] );
		$ev             = $event_settings[ $event_key ] ?? [];

		// Resolve templates.
		$customer_tpl = $this->resolve_preview_template( $ev['customer_template'] ?? '', $event_key, 'customer' );
		$admin_tpl    = $this->resolve_preview_template( $ev['admin_template'] ?? '', $event_key, 'admin' );

		$customer_preview = $builder->build( $customer_tpl, $order );
		$admin_preview    = $builder->build( $admin_tpl, $order );

		wp_send_json_success( [
			'customer_message' => $customer_preview,
			'admin_message'    => $admin_preview,
			'char_count'       => mb_strlen( $customer_preview ),
			'placeholders'     => $builder->get_placeholder_values( $order ),
		] );
	}

	/**
	 * AJAX: tsh_wa_queue_order_notification
	 * Manually queues a notification for an order (duplicate protection active).
	 */
	public function handle_tsh_wa_queue_order_notification(): void {
		$this->verify_request();

		$order_id       = absint( $_POST['order_id'] ?? 0 );
		$event_key      = sanitize_key( $_POST['event_key'] ?? 'status_changed' );
		$recipient_type = sanitize_key( $_POST['recipient_type'] ?? 'all' );

		if ( ! in_array( $recipient_type, [ 'customer', 'admin', 'all' ], true ) ) {
			$recipient_type = 'all';
		}

		$processor = new OrderProcessor();
		$result    = $processor->force_queue( $event_key, $order_id, $recipient_type );

		if ( ! empty( $result['errors'] ) && 0 === $result['queued'] ) {
			wp_send_json_error( [ 'message' => implode( ' ', $result['errors'] ) ] );
		}

		wp_send_json_success( [
			'queued'  => $result['queued'],
			'errors'  => $result['errors'],
			/* translators: %d: number of notifications queued */
			'message' => sprintf( _n( '%d notification queued.', '%d notifications queued.', $result['queued'], 'tsh-whatsapp-notify' ), $result['queued'] ),
		] );
	}

	/**
	 * AJAX: tsh_wa_resend_order_notification
	 * Force-resends, bypassing duplicate protection.
	 */
	public function handle_tsh_wa_resend_order_notification(): void {
		$this->verify_request();

		$order_id       = absint( $_POST['order_id'] ?? 0 );
		$event_key      = sanitize_key( $_POST['event_key'] ?? 'status_changed' );
		$recipient_type = sanitize_key( $_POST['recipient_type'] ?? 'all' );

		$processor = new OrderProcessor();
		$result    = $processor->force_queue( $event_key, $order_id, $recipient_type );

		wp_send_json_success( [
			'queued'  => $result['queued'],
			'errors'  => $result['errors'],
			'message' => sprintf( _n( '%d notification re-queued.', '%d notifications re-queued.', $result['queued'], 'tsh-whatsapp-notify' ), $result['queued'] ),
		] );
	}

	/**
	 * AJAX: tsh_wa_save_admin_recipients
	 * Saves the full list of admin recipient numbers.
	 */
	public function handle_tsh_wa_save_admin_recipients(): void {
		$this->verify_request();

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw        = wp_unslash( $_POST['recipients'] ?? '[]' );
		$recipients = json_decode( $raw, true );

		if ( ! is_array( $recipients ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid recipient data.', 'tsh-whatsapp-notify' ) ] );
		}

		$mgr   = new AdminRecipient();
		$saved = $mgr->save_recipients( $recipients );

		if ( $saved ) {
			wp_send_json_success( [
				'count'   => count( $mgr->get_enabled_recipients() ),
				'message' => __( 'Admin recipients saved.', 'tsh-whatsapp-notify' ),
			] );
		} else {
			wp_send_json_error( [ 'message' => __( 'Failed to save recipients.', 'tsh-whatsapp-notify' ) ] );
		}
	}

	/**
	 * AJAX: tsh_wa_get_order_notifications
	 * Returns the notification log for a specific order.
	 */
	public function handle_tsh_wa_get_order_notifications(): void {
		$this->verify_request();

		$order_id = absint( $_POST['order_id'] ?? 0 );

		if ( ! $order_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid order ID.', 'tsh-whatsapp-notify' ) ] );
		}

		$dispatcher    = new OrderQueueDispatcher();
		$notifications = $dispatcher->get_order_notifications( $order_id, 20 );

		wp_send_json_success( [ 'notifications' => $notifications ] );
	}

	/**
	 * AJAX: tsh_wa_delete_admin_recipient
	 * Removes a single admin recipient by ID.
	 */
	public function handle_tsh_wa_delete_admin_recipient(): void {
		$this->verify_request();

		$recipient_id = sanitize_key( $_POST['recipient_id'] ?? '' );

		if ( ! $recipient_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid recipient ID.', 'tsh-whatsapp-notify' ) ] );
		}

		$mgr    = new AdminRecipient();
		$deleted = $mgr->delete_recipient( $recipient_id );

		if ( $deleted ) {
			wp_send_json_success( [ 'message' => __( 'Recipient removed.', 'tsh-whatsapp-notify' ) ] );
		} else {
			wp_send_json_error( [ 'message' => __( 'Recipient not found.', 'tsh-whatsapp-notify' ) ] );
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
	// Phase 3 — template resolution helper
	// -------------------------------------------------------------------------

	/**
	 * Resolve a template slug to its body for preview purposes.
	 * Falls back to a built-in default if the slug is empty or not found.
	 *
	 * @param string $slug      Template slug.
	 * @param string $event_key Plugin event key.
	 * @param string $type      'customer' | 'admin'.
	 * @return string
	 */
	private function resolve_preview_template( string $slug, string $event_key, string $type ): string {
		if ( $slug ) {
			global $wpdb;
			$table = $wpdb->prefix . 'tsh_wa_templates';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$body = $wpdb->get_var(
				$wpdb->prepare( "SELECT message_body FROM `{$table}` WHERE slug = %s AND status = 'active' LIMIT 1", $slug )
			);
			if ( $body ) {
				return (string) $body;
			}
		}

		$event_label = \TSH\WhatsAppNotify\Orders\OrderStatusListener::event_label( $event_key );

		if ( 'admin' === $type ) {
			return sprintf(
				"🛒 *New Order Notification*\n\n*Event:* %s\n*Order:* #{order_number}\n*Customer:* {customer_name}\n*Phone:* {customer_phone}\n*Total:* {total}\n*Payment:* {payment_method}\n\n*Products:*\n{products}\n\n🔗 {admin_order_url}",
				$event_label
			);
		}

		return "Hello {customer_name}! 👋\n\nThank you for your order at *{store_name}*.\n\n*Order #{order_number}* — {$event_label}\n\n*Items:*\n{products}\n\n*Total:* {total}\n*Payment:* {payment_method}\n\nTrack your order: {customer_order_url}";
	}

	// -------------------------------------------------------------------------
	// Phase 4 — Queue delivery engine handlers
	// -------------------------------------------------------------------------

	/**
	 * AJAX: tsh_wa_queue_pause — manually pause queue processing.
	 */
	public function handle_tsh_wa_queue_pause(): void {
		$this->verify_request();

		update_option( 'tsh_wa_queue_paused', '1', false );

		wp_send_json_success( [
			'message' => __( 'Queue paused. No new messages will be sent until resumed.', 'tsh-whatsapp-notify' ),
			'paused'  => true,
		] );
	}

	/**
	 * AJAX: tsh_wa_queue_resume — resume a paused queue.
	 */
	public function handle_tsh_wa_queue_resume(): void {
		$this->verify_request();

		delete_option( 'tsh_wa_queue_paused' );

		wp_send_json_success( [
			'message' => __( 'Queue resumed. Processing will start on the next cron tick.', 'tsh-whatsapp-notify' ),
			'paused'  => false,
		] );
	}

	/**
	 * AJAX: tsh_wa_queue_process_now — run one batch immediately.
	 */
	public function handle_tsh_wa_queue_process_now(): void {
		$this->verify_request();

		$settings   = get_option( 'tsh_wa_queue_settings', [] );
		$batch_size = absint( $settings['batch_size'] ?? 10 );

		$processor = new QueueProcessor();
		$processed = $processor->run( max( 1, $batch_size ) );

		wp_send_json_success( [
			/* translators: %d: number of items processed */
			'message' => sprintf( __( '%d item(s) processed.', 'tsh-whatsapp-notify' ), $processed ),
			'count'   => $processed,
		] );
	}

	/**
	 * AJAX: tsh_wa_get_queue_stats — live dashboard stat counters.
	 */
	public function handle_tsh_wa_get_queue_stats(): void {
		$this->verify_request();

		$stats = new QueueStats();

		wp_send_json_success( $stats->get_summary() );
	}

	/**
	 * AJAX: tsh_wa_get_queue_health — health monitor panel.
	 */
	public function handle_tsh_wa_get_queue_health(): void {
		$this->verify_request();

		$stats = new QueueStats();

		wp_send_json_success( [ 'health' => $stats->get_health() ] );
	}

	/**
	 * AJAX: tsh_wa_dlq_retry — re-queue one dead letter item.
	 */
	public function handle_tsh_wa_dlq_retry(): void {
		$this->verify_request();

		$id = absint( $_POST['queue_id'] ?? 0 );
		if ( ! $id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid item ID.', 'tsh-whatsapp-notify' ) ] );
			return;
		}

		$dlq    = new DeadLetterQueue();
		$result = $dlq->retry( $id );

		if ( $result ) {
			wp_send_json_success( [
				/* translators: %d: queue item ID */
				'message' => sprintf( __( 'Item #%d re-queued for retry.', 'tsh-whatsapp-notify' ), $id ),
			] );
		} else {
			wp_send_json_error( [ 'message' => __( 'Item not found in dead letter queue.', 'tsh-whatsapp-notify' ) ] );
		}
	}

	/**
	 * AJAX: tsh_wa_dlq_delete — permanently delete one dead letter item.
	 */
	public function handle_tsh_wa_dlq_delete(): void {
		$this->verify_request();

		$id = absint( $_POST['queue_id'] ?? 0 );
		if ( ! $id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid item ID.', 'tsh-whatsapp-notify' ) ] );
			return;
		}

		$dlq    = new DeadLetterQueue();
		$result = $dlq->delete( $id );

		if ( $result ) {
			wp_send_json_success( [
				/* translators: %d: queue item ID */
				'message' => sprintf( __( 'Item #%d permanently deleted.', 'tsh-whatsapp-notify' ), $id ),
			] );
		} else {
			wp_send_json_error( [ 'message' => __( 'Item not found.', 'tsh-whatsapp-notify' ) ] );
		}
	}

	/**
	 * AJAX: tsh_wa_dlq_clear — delete all dead letter items at once.
	 */
	public function handle_tsh_wa_dlq_clear(): void {
		$this->verify_request();

		$dlq   = new DeadLetterQueue();
		$count = $dlq->clear();

		wp_send_json_success( [
			/* translators: %d: number of items deleted */
			'message' => sprintf( __( '%d dead letter item(s) permanently deleted.', 'tsh-whatsapp-notify' ), $count ),
			'count'   => $count,
		] );
	}

	/**
	 * AJAX: tsh_wa_queue_export — export DLQ items as JSON.
	 */
	public function handle_tsh_wa_queue_export(): void {
		$this->verify_request();

		$dlq  = new DeadLetterQueue();
		$data = $dlq->export();

		wp_send_json_success( [
			'data'  => $data,
			'count' => count( $data ),
		] );
	}

	/**
	 * AJAX: tsh_wa_queue_retry_all — re-queue all dead letter items.
	 */
	public function handle_tsh_wa_queue_retry_all(): void {
		$this->verify_request();

		$dlq   = new DeadLetterQueue();
		$count = $dlq->retry_all();

		wp_send_json_success( [
			/* translators: %d: number of items re-queued */
			'message' => sprintf( __( '%d item(s) re-queued for retry.', 'tsh-whatsapp-notify' ), $count ),
			'count'   => $count,
		] );
	}

	// -------------------------------------------------------------------------
	// Phase 5 — Template management handlers
	// -------------------------------------------------------------------------

	/**
	 * AJAX: tsh_wa_sync_templates — run a manual sync.
	 */
	public function handle_tsh_wa_sync_templates(): void {
		$this->verify_request();
		$manager = new TemplateManager();
		$result  = $manager->sync( 'manual' );
		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( [ 'message' => $result['message'], 'stats' => $result['stats'] ] );
		}
	}

	/**
	 * AJAX: tsh_wa_force_full_sync — truncate + re-fetch all templates.
	 */
	public function handle_tsh_wa_force_full_sync(): void {
		$this->verify_request();
		$manager = new TemplateManager();
		$result  = $manager->sync( 'full' );
		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( [ 'message' => $result['message'], 'stats' => $result['stats'] ] );
		}
	}

	/**
	 * AJAX: tsh_wa_get_template_preview — render template preview data.
	 */
	public function handle_tsh_wa_get_template_preview(): void {
		$this->verify_request();

		$template_id = absint( $_POST['template_id'] ?? 0 );
		if ( ! $template_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid template ID.', 'tsh-whatsapp-notify' ) ] );
		}

		$variables = [];
		if ( ! empty( $_POST['variables'] ) && is_array( $_POST['variables'] ) ) {
			foreach ( $_POST['variables'] as $k => $v ) {
				$variables[ (string) absint( $k ) ] = sanitize_text_field( $v );
			}
		}

		$manager = new TemplateManager();
		$result  = $manager->preview( $template_id, $variables );

		if ( $result['success'] ) {
			wp_send_json_success( $result['data'] );
		} else {
			wp_send_json_error( [ 'message' => $result['message'] ] );
		}
	}

	/**
	 * AJAX: tsh_wa_assign_template — assign a template to a WC event.
	 */
	public function handle_tsh_wa_assign_template(): void {
		$this->verify_request();

		$event          = sanitize_key( $_POST['event'] ?? '' );
		$template_id    = absint( $_POST['template_id'] ?? 0 );
		$recipient_type = sanitize_text_field( $_POST['recipient_type'] ?? 'customer' );

		if ( ! $event ) {
			wp_send_json_error( [ 'message' => __( 'Event name is required.', 'tsh-whatsapp-notify' ) ] );
		}

		$manager = new TemplateManager();
		$result  = $manager->assign( $event, $template_id, $recipient_type );

		if ( $result ) {
			wp_send_json_success( [ 'message' => __( 'Template assigned.', 'tsh-whatsapp-notify' ) ] );
		} else {
			wp_send_json_error( [ 'message' => __( 'Failed to assign template.', 'tsh-whatsapp-notify' ) ] );
		}
	}

	/**
	 * AJAX: tsh_wa_unassign_template — remove a template assignment.
	 */
	public function handle_tsh_wa_unassign_template(): void {
		$this->verify_request();

		$event          = sanitize_key( $_POST['event'] ?? '' );
		$recipient_type = sanitize_text_field( $_POST['recipient_type'] ?? 'customer' );

		if ( ! $event ) {
			wp_send_json_error( [ 'message' => __( 'Event name is required.', 'tsh-whatsapp-notify' ) ] );
		}

		$manager = new TemplateManager();
		$result  = $manager->unassign( $event, $recipient_type );

		if ( $result ) {
			wp_send_json_success( [ 'message' => __( 'Template unassigned.', 'tsh-whatsapp-notify' ) ] );
		} else {
			wp_send_json_error( [ 'message' => __( 'Failed to remove assignment.', 'tsh-whatsapp-notify' ) ] );
		}
	}

	/**
	 * AJAX: tsh_wa_get_template_assignments — return all current assignments.
	 */
	public function handle_tsh_wa_get_template_assignments(): void {
		$this->verify_request();

		$manager     = new TemplateManager();
		$assignments = $manager->get_assignments();

		wp_send_json_success( [ 'assignments' => $assignments ] );
	}

	/**
	 * AJAX: tsh_wa_search_templates — instant search.
	 */
	public function handle_tsh_wa_search_templates(): void {
		$this->verify_request();

		$query    = sanitize_text_field( $_POST['query'] ?? '' );
		$category = sanitize_text_field( $_POST['category'] ?? '' );
		$language = sanitize_text_field( $_POST['language'] ?? '' );
		$status   = sanitize_text_field( $_POST['status'] ?? '' );
		$per_page = absint( $_POST['per_page'] ?? 25 );
		$page     = absint( $_POST['page'] ?? 1 );

		$filters = array_filter( compact( 'category', 'language', 'status', 'per_page', 'page' ) );

		$manager = new TemplateManager();
		$result  = $manager->search( $query, $filters );

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: tsh_wa_get_templates_page — paginated template list.
	 */
	public function handle_tsh_wa_get_templates_page(): void {
		$this->verify_request();

		$args = [
			'page'     => absint( $_POST['page'] ?? 1 ),
			'per_page' => absint( $_POST['per_page'] ?? 25 ),
			'orderby'  => sanitize_key( $_POST['orderby'] ?? 'created_at' ),
			'order'    => strtoupper( sanitize_key( $_POST['order'] ?? 'DESC' ) ),
		];

		foreach ( [ 'status', 'category', 'language', 'quality' ] as $filter ) {
			if ( ! empty( $_POST[ $filter ] ) ) {
				$args[ $filter ] = sanitize_text_field( $_POST[ $filter ] );
			}
		}

		if ( ! empty( $_POST['search'] ) ) {
			$args['search'] = sanitize_text_field( $_POST['search'] );
		}

		$manager = new TemplateManager();
		$result  = $manager->get_templates( $args );

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: tsh_wa_validate_template — validate template field data.
	 */
	public function handle_tsh_wa_validate_template(): void {
		$this->verify_request();

		$data = [
			'template_name' => sanitize_text_field( $_POST['template_name'] ?? '' ),
			'category'      => sanitize_text_field( $_POST['category'] ?? '' ),
			'language'      => sanitize_text_field( $_POST['language'] ?? '' ),
			'body'          => wp_kses_post( $_POST['body'] ?? '' ),
			'footer'        => sanitize_text_field( $_POST['footer'] ?? '' ),
		];

		$manager = new TemplateManager();
		$result  = $manager->validate( $data );

		if ( $result['valid'] ) {
			wp_send_json_success( [ 'message' => __( 'Template data is valid.', 'tsh-whatsapp-notify' ) ] );
		} else {
			wp_send_json_error( [ 'errors' => $result['errors'] ] );
		}
	}

	/**
	 * AJAX: tsh_wa_test_template — send a test message using a template.
	 *
	 * Builds a preview of the template body with example variable values and
	 * enqueues it directly without requiring a WooCommerce order.
	 */
	public function handle_tsh_wa_test_template(): void {
		$this->verify_request();

		$template_id = absint( $_POST['template_id'] ?? 0 );
		$phone       = sanitize_text_field( $_POST['phone'] ?? '' );

		if ( ! $template_id || ! $phone ) {
			wp_send_json_error( [ 'message' => __( 'Template ID and phone number are required.', 'tsh-whatsapp-notify' ) ] );
		}

		if ( ! Helpers::is_plugin_ready() ) {
			wp_send_json_error( [ 'message' => __( 'WhatsApp API is not configured.', 'tsh-whatsapp-notify' ) ] );
		}

		// Build rendered preview text using example variable values.
		$manager     = new TemplateManager();
		$preview     = $manager->preview( $template_id );

		if ( ! $preview['success'] ) {
			wp_send_json_error( [ 'message' => $preview['message'] ?? __( 'Template not found.', 'tsh-whatsapp-notify' ) ] );
		}

		$rendered_body = $preview['data']['body_rendered'] ?? $preview['data']['body'] ?? '';

		if ( ! $rendered_body ) {
			wp_send_json_error( [ 'message' => __( 'Template body is empty.', 'tsh-whatsapp-notify' ) ] );
		}

		// Enqueue via the generic Queue class — no WC order ID required for tests.
		$queue = new \TSH\WhatsAppNotify\Queue\Queue();
		$queue_id = $queue->add( [
			'phone'        => $phone,
			'message'      => $rendered_body,
			'order_id'     => null,
			'priority'     => 1,
			'scheduled_at' => current_time( 'mysql' ),
			'meta'         => [
				'event_key'      => 'template_test',
				'recipient_type' => 'admin',
				'template_id'    => $template_id,
				'is_test'        => true,
			],
		] );

		if ( $queue_id ) {
			wp_send_json_success( [
				/* translators: %d: queue item ID */
				'message' => sprintf( __( 'Test message queued (ID #%d).', 'tsh-whatsapp-notify' ), $queue_id ),
			] );
		} else {
			wp_send_json_error( [ 'message' => __( 'Failed to queue test message.', 'tsh-whatsapp-notify' ) ] );
		}
	}

	/**
	 * AJAX: tsh_wa_import_templates — import templates from JSON or CSV.
	 */
	public function handle_tsh_wa_import_templates(): void {
		$this->verify_request();

		$format = sanitize_key( $_POST['format'] ?? 'json' );
		$mode   = sanitize_key( $_POST['mode'] ?? 'merge' );
		$data   = wp_unslash( $_POST['data'] ?? '' );

		if ( empty( $data ) ) {
			wp_send_json_error( [ 'message' => __( 'No import data provided.', 'tsh-whatsapp-notify' ) ] );
		}

		$manager = new TemplateManager();
		$result  = $manager->import( $data, $format, $mode );

		if ( $result['errors'] > 0 && 0 === $result['imported'] ) {
			wp_send_json_error( $result );
		} else {
			wp_send_json_success( $result );
		}
	}

	/**
	 * AJAX: tsh_wa_export_templates — export templates as JSON or CSV.
	 */
	public function handle_tsh_wa_export_templates(): void {
		$this->verify_request();

		$format      = sanitize_key( $_POST['format'] ?? 'json' );
		$ids_raw     = $_POST['template_ids'] ?? [];
		$template_ids = is_array( $ids_raw ) ? array_map( 'absint', $ids_raw ) : [];

		$manager = new TemplateManager();
		$output  = $manager->export( $format, $template_ids );

		wp_send_json_success( [
			'data'     => $output,
			'format'   => $format,
			'filename' => 'tsh-wa-templates-' . gmdate( 'Y-m-d' ) . '.' . $format,
		] );
	}

	/**
	 * AJAX: tsh_wa_flush_template_cache — flush all template transients.
	 */
	public function handle_tsh_wa_flush_template_cache(): void {
		$this->verify_request();

		$manager = new TemplateManager();
		$manager->flush_cache();

		wp_send_json_success( [ 'message' => __( 'Template cache cleared.', 'tsh-whatsapp-notify' ) ] );
	}

	/**
	 * AJAX: tsh_wa_get_template_analytics — return full analytics dataset.
	 */
	public function handle_tsh_wa_get_template_analytics(): void {
		$this->verify_request();

		$manager = new TemplateManager();
		wp_send_json_success( $manager->get_analytics() );
	}

	/**
	 * AJAX: tsh_wa_get_template_stats — lightweight stats for the dashboard widget.
	 */
	public function handle_tsh_wa_get_template_stats(): void {
		$this->verify_request();

		$manager = new TemplateManager();
		wp_send_json_success( $manager->get_dashboard_overview() );
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
