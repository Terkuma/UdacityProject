<?php
/**
 * Settings page controller and Settings API registration.
 *
 * @package TSH\WhatsAppNotify\Admin
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Settings
 *
 * Manages all plugin settings using the WordPress Settings API.
 *
 * Tabs:
 *   general | api | admin_notifications | customer_notifications
 *   templates | queue | logging | advanced
 */
final class Settings {

	// -------------------------------------------------------------------------
	// Tab definitions
	// -------------------------------------------------------------------------

	/** @var array<string, string> */
	private const TABS = [
		'general'                => 'General',
		'api'                    => 'WhatsApp API',
		'wc_events'              => 'WooCommerce Events',
		'admin_notifications'    => 'Admin Notifications',
		'customer_notifications' => 'Customer Notifications',
		'templates'              => 'Templates',
		'queue'                  => 'Queue',
		'logging'                => 'Logging',
		'advanced'               => 'Advanced',
	];

	/** @var array<string, string> Maps tab slug → wp_options key. */
	private const OPTION_KEYS = [
		'general'                => 'tsh_wa_general_settings',
		'api'                    => 'tsh_wa_api_settings',
		'wc_events'              => 'tsh_wa_wc_events_settings',
		'admin_notifications'    => 'tsh_wa_admin_notification_settings',
		'customer_notifications' => 'tsh_wa_customer_notification_settings',
		'templates'              => 'tsh_wa_template_settings',
		'queue'                  => 'tsh_wa_queue_settings',
		'logging'                => 'tsh_wa_logging_settings',
		'advanced'               => 'tsh_wa_advanced_settings',
	];

	/**
	 * Constructor — registers settings hooks.
	 */
	public function __construct() {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	// -------------------------------------------------------------------------
	// Settings API registration
	// -------------------------------------------------------------------------

	/**
	 * Register all settings groups, sections, and fields.
	 */
	public function register_settings(): void {
		$this->register_general_settings();
		$this->register_api_settings();
		$this->register_wc_events_settings();
		$this->register_admin_notification_settings();
		$this->register_customer_notification_settings();
		$this->register_template_settings();
		$this->register_queue_settings();
		$this->register_logging_settings();
		$this->register_advanced_settings();
	}

	// -------------------------------------------------------------------------
	// General
	// -------------------------------------------------------------------------

	private function register_general_settings(): void {
		$option = self::OPTION_KEYS['general'];

		register_setting( $option, $option, [
			'sanitize_callback' => [ $this, 'sanitize_general' ],
		] );

		add_settings_section(
			'tsh_wa_general_section',
			__( 'General Configuration', 'tsh-whatsapp-notify' ),
			static function (): void {
				echo '<p>' . esc_html__( 'Configure the basic plugin settings.', 'tsh-whatsapp-notify' ) . '</p>';
			},
			$option
		);

		add_settings_field(
			'plugin_name',
			__( 'Plugin Display Name', 'tsh-whatsapp-notify' ),
			[ $this, 'render_text_field' ],
			$option,
			'tsh_wa_general_section',
			[
				'option_key' => $option,
				'field'      => 'plugin_name',
				'desc'       => __( 'Display name shown in notification footers.', 'tsh-whatsapp-notify' ),
				'default'    => 'TSH WhatsApp Notify',
			]
		);

		add_settings_field(
			'store_phone',
			__( 'Store WhatsApp Number', 'tsh-whatsapp-notify' ),
			[ $this, 'render_text_field' ],
			$option,
			'tsh_wa_general_section',
			[
				'option_key'  => $option,
				'field'       => 'store_phone',
				'placeholder' => '+2348012345678',
				'desc'        => __( 'E.164 format — e.g. +2348012345678. Used for admin notifications.', 'tsh-whatsapp-notify' ),
			]
		);

		add_settings_field(
			'test_mode',
			__( 'Test / Sandbox Mode', 'tsh-whatsapp-notify' ),
			[ $this, 'render_checkbox_field' ],
			$option,
			'tsh_wa_general_section',
			[
				'option_key' => $option,
				'field'      => 'test_mode',
				'label'      => __( 'Enable test mode — messages will not be sent to real numbers.', 'tsh-whatsapp-notify' ),
			]
		);

		add_settings_field(
			'send_test_to',
			__( 'Test Phone Number', 'tsh-whatsapp-notify' ),
			[ $this, 'render_text_field' ],
			$option,
			'tsh_wa_general_section',
			[
				'option_key'  => $option,
				'field'       => 'send_test_to',
				'placeholder' => '+2348012345678',
				'desc'        => __( 'In test mode, all messages will be redirected to this number.', 'tsh-whatsapp-notify' ),
			]
		);
	}

	// -------------------------------------------------------------------------
	// WhatsApp API
	// -------------------------------------------------------------------------

	private function register_api_settings(): void {
		$option = self::OPTION_KEYS['api'];

		register_setting( $option, $option, [
			'sanitize_callback' => [ $this, 'sanitize_api' ],
		] );

		// -- Section: Enable / disable API integration ----------------------

		add_settings_section(
			'tsh_wa_api_enable_section',
			__( 'API Integration', 'tsh-whatsapp-notify' ),
			static function (): void {
				echo '<p>' . esc_html__( 'Enable the WhatsApp Cloud API integration to start sending messages.', 'tsh-whatsapp-notify' ) . '</p>';
			},
			$option
		);

		add_settings_field( 'enable_api',
			__( 'Enable API', 'tsh-whatsapp-notify' ),
			[ $this, 'render_checkbox_field' ], $option, 'tsh_wa_api_enable_section',
			[ 'option_key' => $option, 'field' => 'enable_api', 'label' => __( 'Enable the Meta WhatsApp Cloud API. Must be on to send any messages.', 'tsh-whatsapp-notify' ) ]
		);

		// -- Section: Credentials -------------------------------------------

		add_settings_section(
			'tsh_wa_api_section',
			__( 'Meta WhatsApp Cloud API Credentials', 'tsh-whatsapp-notify' ),
			static function (): void {
				echo '<p>' . wp_kses(
					__( 'Enter your <strong>Meta Business</strong> WhatsApp Cloud API credentials. Find these in the <a href="https://developers.facebook.com/apps/" target="_blank" rel="noopener">Meta for Developers</a> portal.', 'tsh-whatsapp-notify' ),
					[ 'strong' => [], 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ]
				) . '</p>';
			},
			$option
		);

		add_settings_field( 'phone_number_id',
			__( 'Phone Number ID', 'tsh-whatsapp-notify' ),
			[ $this, 'render_text_field' ], $option, 'tsh_wa_api_section',
			[ 'option_key' => $option, 'field' => 'phone_number_id', 'placeholder' => '123456789012345', 'desc' => __( 'Your WhatsApp Business Phone Number ID from Meta.', 'tsh-whatsapp-notify' ) ]
		);

		add_settings_field( 'business_account_id',
			__( 'Business Account ID', 'tsh-whatsapp-notify' ),
			[ $this, 'render_text_field' ], $option, 'tsh_wa_api_section',
			[ 'option_key' => $option, 'field' => 'business_account_id', 'placeholder' => '123456789012345', 'desc' => __( 'Your WhatsApp Business Account ID (WABA ID).', 'tsh-whatsapp-notify' ) ]
		);

		add_settings_field( 'access_token',
			__( 'Permanent Access Token', 'tsh-whatsapp-notify' ),
			[ $this, 'render_token_field' ], $option, 'tsh_wa_api_section',
			[ 'option_key' => $option, 'field' => 'access_token', 'desc' => __( 'Your Meta System User permanent access token. Never logged or exposed.', 'tsh-whatsapp-notify' ) ]
		);

		add_settings_field( 'api_version',
			__( 'Graph API Version', 'tsh-whatsapp-notify' ),
			[ $this, 'render_text_field' ], $option, 'tsh_wa_api_section',
			[ 'option_key' => $option, 'field' => 'api_version', 'default' => 'v23.0', 'placeholder' => 'v23.0', 'desc' => __( 'Meta Graph API version. Default: v23.0.', 'tsh-whatsapp-notify' ) ]
		);

		add_settings_field( 'webhook_verify_token',
			__( 'Webhook Verify Token', 'tsh-whatsapp-notify' ),
			[ $this, 'render_password_field' ], $option, 'tsh_wa_api_section',
			[ 'option_key' => $option, 'field' => 'webhook_verify_token', 'desc' => __( 'Secret token you enter in the Meta webhook configuration.', 'tsh-whatsapp-notify' ) ]
		);

		// -- Section: Connection & reliability ---------------------------------

		add_settings_section(
			'tsh_wa_api_connection_section',
			__( 'Connection & Reliability', 'tsh-whatsapp-notify' ),
			static function (): void {
				echo '<p>' . esc_html__( 'Configure request timeout and automatic retry behaviour for API calls.', 'tsh-whatsapp-notify' ) . '</p>';
			},
			$option
		);

		add_settings_field( 'test_phone_number',
			__( 'Test Phone Number', 'tsh-whatsapp-notify' ),
			[ $this, 'render_text_field' ], $option, 'tsh_wa_api_connection_section',
			[ 'option_key' => $option, 'field' => 'test_phone_number', 'placeholder' => '+2348012345678', 'desc' => __( 'Default phone number used by the Connection Tester and Message Sandbox.', 'tsh-whatsapp-notify' ) ]
		);

		add_settings_field( 'request_timeout',
			__( 'Request Timeout (seconds)', 'tsh-whatsapp-notify' ),
			[ $this, 'render_number_field' ], $option, 'tsh_wa_api_connection_section',
			[ 'option_key' => $option, 'field' => 'request_timeout', 'default' => '30', 'min' => 5, 'max' => 120, 'desc' => __( 'Maximum seconds to wait for a Meta API response (5–120).', 'tsh-whatsapp-notify' ) ]
		);

		add_settings_field( 'retry_attempts',
			__( 'HTTP Retry Attempts', 'tsh-whatsapp-notify' ),
			[ $this, 'render_number_field' ], $option, 'tsh_wa_api_connection_section',
			[ 'option_key' => $option, 'field' => 'retry_attempts', 'default' => '3', 'min' => 0, 'max' => 10, 'desc' => __( 'Retries on timeout or 5xx/429 errors per API call (0–10).', 'tsh-whatsapp-notify' ) ]
		);

		add_settings_field( 'retry_delay',
			__( 'Base Retry Delay (seconds)', 'tsh-whatsapp-notify' ),
			[ $this, 'render_number_field' ], $option, 'tsh_wa_api_connection_section',
			[ 'option_key' => $option, 'field' => 'retry_delay', 'default' => '5', 'min' => 1, 'max' => 120, 'desc' => __( 'Base seconds between retries — doubles each attempt (exponential back-off).', 'tsh-whatsapp-notify' ) ]
		);
	}

	// -------------------------------------------------------------------------
	// Admin Notifications
	// -------------------------------------------------------------------------

	private function register_admin_notification_settings(): void {
		$option = self::OPTION_KEYS['admin_notifications'];

		register_setting( $option, $option, [
			'sanitize_callback' => [ $this, 'sanitize_toggle_array' ],
		] );

		add_settings_section(
			'tsh_wa_admin_notif_section',
			__( 'Admin Notification Events', 'tsh-whatsapp-notify' ),
			static function (): void {
				echo '<p>' . esc_html__( 'Choose which events trigger a WhatsApp notification to the store admin number.', 'tsh-whatsapp-notify' ) . '</p>';
			},
			$option
		);

		$admin_events = [
			'new_order'       => __( 'New order placed', 'tsh-whatsapp-notify' ),
			'payment_failed'  => __( 'Payment failed', 'tsh-whatsapp-notify' ),
			'order_cancelled' => __( 'Order cancelled', 'tsh-whatsapp-notify' ),
			'low_stock'       => __( 'Product low stock', 'tsh-whatsapp-notify' ),
			'out_of_stock'    => __( 'Product out of stock', 'tsh-whatsapp-notify' ),
			'refund_issued'   => __( 'Refund issued', 'tsh-whatsapp-notify' ),
		];

		foreach ( $admin_events as $event_key => $event_label ) {
			add_settings_field(
				$event_key,
				$event_label,
				[ $this, 'render_checkbox_field' ],
				$option,
				'tsh_wa_admin_notif_section',
				[ 'option_key' => $option, 'field' => $event_key ]
			);
		}
	}

	// -------------------------------------------------------------------------
	// Customer Notifications
	// -------------------------------------------------------------------------

	private function register_customer_notification_settings(): void {
		$option = self::OPTION_KEYS['customer_notifications'];

		register_setting( $option, $option, [
			'sanitize_callback' => [ $this, 'sanitize_toggle_array' ],
		] );

		add_settings_section(
			'tsh_wa_customer_notif_section',
			__( 'Customer Notification Events', 'tsh-whatsapp-notify' ),
			static function (): void {
				echo '<p>' . esc_html__( 'Choose which order events send a WhatsApp message to the customer.', 'tsh-whatsapp-notify' ) . '</p>';
			},
			$option
		);

		$customer_events = [
			'order_placed'        => __( 'Order placed (pending payment)', 'tsh-whatsapp-notify' ),
			'order_processing'    => __( 'Payment received / order processing', 'tsh-whatsapp-notify' ),
			'order_on_hold'       => __( 'Order on hold', 'tsh-whatsapp-notify' ),
			'order_completed'     => __( 'Order completed', 'tsh-whatsapp-notify' ),
			'order_cancelled'     => __( 'Order cancelled', 'tsh-whatsapp-notify' ),
			'order_refunded'      => __( 'Order refunded', 'tsh-whatsapp-notify' ),
			'order_shipped'       => __( 'Order shipped', 'tsh-whatsapp-notify' ),
			'order_out_delivery'  => __( 'Order out for delivery', 'tsh-whatsapp-notify' ),
			'payment_failed'      => __( 'Payment failed', 'tsh-whatsapp-notify' ),
		];

		foreach ( $customer_events as $event_key => $event_label ) {
			add_settings_field(
				$event_key,
				$event_label,
				[ $this, 'render_checkbox_field' ],
				$option,
				'tsh_wa_customer_notif_section',
				[ 'option_key' => $option, 'field' => $event_key ]
			);
		}
	}

	// -------------------------------------------------------------------------
	// Templates
	// -------------------------------------------------------------------------

	private function register_template_settings(): void {
		$option = self::OPTION_KEYS['templates'];

		register_setting( $option, $option, [
			'sanitize_callback' => [ $this, 'sanitize_template_settings' ],
		] );

		add_settings_section(
			'tsh_wa_template_section',
			__( 'Template Defaults', 'tsh-whatsapp-notify' ),
			static function (): void {
				echo '<p>' . esc_html__( 'Set global template behaviour. Individual templates are managed in the Templates page.', 'tsh-whatsapp-notify' ) . '</p>';
			},
			$option
		);

		add_settings_field( 'default_language',
			__( 'Default Template Language', 'tsh-whatsapp-notify' ),
			[ $this, 'render_select_field' ], $option, 'tsh_wa_template_section',
			[
				'option_key' => $option,
				'field'      => 'default_language',
				'default'    => 'en',
				'options'    => [
					'en'    => __( 'English', 'tsh-whatsapp-notify' ),
					'fr'    => __( 'French', 'tsh-whatsapp-notify' ),
					'ar'    => __( 'Arabic', 'tsh-whatsapp-notify' ),
					'es'    => __( 'Spanish', 'tsh-whatsapp-notify' ),
					'pt_BR' => __( 'Portuguese (Brazil)', 'tsh-whatsapp-notify' ),
					'ha'    => __( 'Hausa', 'tsh-whatsapp-notify' ),
					'yo'    => __( 'Yoruba', 'tsh-whatsapp-notify' ),
					'ig'    => __( 'Igbo', 'tsh-whatsapp-notify' ),
				],
			]
		);

		add_settings_field( 'enable_emoji',
			__( 'Allow Emoji in Templates', 'tsh-whatsapp-notify' ),
			[ $this, 'render_checkbox_field' ], $option, 'tsh_wa_template_section',
			[ 'option_key' => $option, 'field' => 'enable_emoji', 'label' => __( 'Allow emoji characters in message bodies.', 'tsh-whatsapp-notify' ) ]
		);

		// Phase 5 — Meta template sync settings registered under their own option key.
		$sync_option = 'tsh_wa_sync_settings';

		register_setting( $sync_option, $sync_option, [
			'sanitize_callback' => [ $this, 'sanitize_sync_settings' ],
		] );

		add_settings_section(
			'tsh_wa_template_sync_section',
			__( 'Meta Template Sync', 'tsh-whatsapp-notify' ),
			static function (): void {
				echo '<p>' . esc_html__( 'Configure automatic synchronisation of approved templates from your Meta WhatsApp Business Account.', 'tsh-whatsapp-notify' ) . '</p>';
			},
			$sync_option
		);

		add_settings_field( 'auto_sync',
			__( 'Auto Sync', 'tsh-whatsapp-notify' ),
			[ $this, 'render_checkbox_field' ], $sync_option, 'tsh_wa_template_sync_section',
			[ 'option_key' => $sync_option, 'field' => 'auto_sync', 'label' => __( 'Automatically sync Meta templates on the configured interval.', 'tsh-whatsapp-notify' ) ]
		);

		add_settings_field( 'sync_interval',
			__( 'Sync Interval', 'tsh-whatsapp-notify' ),
			[ $this, 'render_select_field' ], $sync_option, 'tsh_wa_template_sync_section',
			[
				'option_key' => $sync_option,
				'field'      => 'sync_interval',
				'default'    => 'hourly',
				'options'    => [
					'hourly'    => __( 'Hourly', 'tsh-whatsapp-notify' ),
					'twicedaily' => __( 'Twice Daily', 'tsh-whatsapp-notify' ),
					'daily'     => __( 'Daily', 'tsh-whatsapp-notify' ),
				],
				'desc' => __( 'How often to fetch updated templates from Meta.', 'tsh-whatsapp-notify' ),
			]
		);

		add_settings_field( 'background_sync',
			__( 'Background Sync', 'tsh-whatsapp-notify' ),
			[ $this, 'render_checkbox_field' ], $sync_option, 'tsh_wa_template_sync_section',
			[ 'option_key' => $sync_option, 'field' => 'background_sync', 'label' => __( 'Run syncs in the background via WP-Cron so they do not block the admin UI.', 'tsh-whatsapp-notify' ) ]
		);

		add_settings_field( 'cache_duration',
			__( 'Cache Duration (minutes)', 'tsh-whatsapp-notify' ),
			[ $this, 'render_number_field' ], $sync_option, 'tsh_wa_template_sync_section',
			[ 'option_key' => $sync_option, 'field' => 'cache_duration', 'default' => '60', 'min' => 5, 'max' => 1440, 'desc' => __( 'How long to cache template data before a fresh DB query (5–1440 minutes).', 'tsh-whatsapp-notify' ) ]
		);

		add_settings_field( 'max_templates',
			__( 'Max Templates to Sync', 'tsh-whatsapp-notify' ),
			[ $this, 'render_number_field' ], $sync_option, 'tsh_wa_template_sync_section',
			[ 'option_key' => $sync_option, 'field' => 'max_templates', 'default' => '500', 'min' => 50, 'max' => 5000, 'desc' => __( 'Maximum number of templates to fetch per sync run (50–5000).', 'tsh-whatsapp-notify' ) ]
		);

		add_settings_field( 'retry_failed_sync',
			__( 'Retry Failed Sync', 'tsh-whatsapp-notify' ),
			[ $this, 'render_checkbox_field' ], $sync_option, 'tsh_wa_template_sync_section',
			[ 'option_key' => $sync_option, 'field' => 'retry_failed_sync', 'label' => __( 'Automatically retry the sync if it fails.', 'tsh-whatsapp-notify' ) ]
		);

		add_settings_field( 'fallback_language',
			__( 'Fallback Language', 'tsh-whatsapp-notify' ),
			[ $this, 'render_text_field' ], $sync_option, 'tsh_wa_template_sync_section',
			[
				'option_key'  => $sync_option,
				'field'       => 'fallback_language',
				'default'     => 'en',
				'placeholder' => 'en',
				'desc'        => __( 'Language code used when no matching template is found for the customer\'s language.', 'tsh-whatsapp-notify' ),
			]
		);
	}

	/**
	 * Sanitise Phase 5 sync settings.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public function sanitize_sync_settings( array $input ): array {
		$valid_intervals = [ 'hourly', 'twicedaily', 'daily' ];

		return [
			'auto_sync'          => ! empty( $input['auto_sync'] )          ? '1' : '0',
			'sync_interval'      => in_array( $input['sync_interval'] ?? '', $valid_intervals, true ) ? $input['sync_interval'] : 'hourly',
			'background_sync'    => ! empty( $input['background_sync'] )    ? '1' : '0',
			'cache_duration'     => max( 5, min( 1440, absint( $input['cache_duration'] ?? 60 ) ) ),
			'max_templates'      => max( 50, min( 5000, absint( $input['max_templates'] ?? 500 ) ) ),
			'retry_failed_sync'  => ! empty( $input['retry_failed_sync'] )  ? '1' : '0',
			'fallback_language'  => sanitize_text_field( $input['fallback_language'] ?? 'en' ),
		];
	}

	// -------------------------------------------------------------------------
	// Queue
	// -------------------------------------------------------------------------

	private function register_queue_settings(): void {
		$option = self::OPTION_KEYS['queue'];

		register_setting( $option, $option, [
			'sanitize_callback' => [ $this, 'sanitize_queue' ],
		] );

		add_settings_section(
			'tsh_wa_queue_section',
			__( 'Queue Configuration', 'tsh-whatsapp-notify' ),
			static function (): void {
				echo '<p>' . esc_html__( 'Configure how the message queue is processed.', 'tsh-whatsapp-notify' ) . '</p>';
			},
			$option
		);

		add_settings_field( 'queue_enabled',
			__( 'Enable Queue', 'tsh-whatsapp-notify' ),
			[ $this, 'render_checkbox_field' ], $option, 'tsh_wa_queue_section',
			[ 'option_key' => $option, 'field' => 'queue_enabled', 'label' => __( 'Process messages through the queue (recommended).', 'tsh-whatsapp-notify' ) ]
		);

		add_settings_field( 'batch_size',
			__( 'Batch Size', 'tsh-whatsapp-notify' ),
			[ $this, 'render_number_field' ], $option, 'tsh_wa_queue_section',
			[ 'option_key' => $option, 'field' => 'batch_size', 'default' => '10', 'min' => 1, 'max' => 100, 'desc' => __( 'Messages processed per cron run (1–100).', 'tsh-whatsapp-notify' ) ]
		);

		add_settings_field( 'retry_attempts',
			__( 'Retry Attempts', 'tsh-whatsapp-notify' ),
			[ $this, 'render_number_field' ], $option, 'tsh_wa_queue_section',
			[ 'option_key' => $option, 'field' => 'retry_attempts', 'default' => '3', 'min' => 0, 'max' => 10, 'desc' => __( 'Number of times to retry a failed send before marking as permanently failed.', 'tsh-whatsapp-notify' ) ]
		);

		add_settings_field( 'retry_delay',
			__( 'Retry Delay (minutes)', 'tsh-whatsapp-notify' ),
			[ $this, 'render_number_field' ], $option, 'tsh_wa_queue_section',
			[ 'option_key' => $option, 'field' => 'retry_delay', 'default' => '5', 'min' => 1, 'max' => 1440, 'desc' => __( 'Base delay in minutes before retrying a failed message. Phase 4 applies exponential backoff on top of this value.', 'tsh-whatsapp-notify' ) ]
		);

		add_settings_field( 'messages_per_minute',
			__( 'Rate Limit (msg/min)', 'tsh-whatsapp-notify' ),
			[ $this, 'render_number_field' ], $option, 'tsh_wa_queue_section',
			[ 'option_key' => $option, 'field' => 'messages_per_minute', 'default' => '80', 'min' => 0, 'max' => 1000, 'desc' => __( 'Maximum WhatsApp messages per minute (0 = unlimited). Meta Cloud API Standard tier cap is ~80/min. Tier 1 businesses may set up to 1000.', 'tsh-whatsapp-notify' ) ]
		);
	}

	// -------------------------------------------------------------------------
	// Logging
	// -------------------------------------------------------------------------

	private function register_logging_settings(): void {
		$option = self::OPTION_KEYS['logging'];

		register_setting( $option, $option, [
			'sanitize_callback' => [ $this, 'sanitize_logging' ],
		] );

		add_settings_section(
			'tsh_wa_logging_section',
			__( 'Logging Configuration', 'tsh-whatsapp-notify' ),
			static function (): void {
				echo '<p>' . esc_html__( 'Configure how plugin activity is logged.', 'tsh-whatsapp-notify' ) . '</p>';
			},
			$option
		);

		add_settings_field( 'log_enabled',
			__( 'Enable Logging', 'tsh-whatsapp-notify' ),
			[ $this, 'render_checkbox_field' ], $option, 'tsh_wa_logging_section',
			[ 'option_key' => $option, 'field' => 'log_enabled', 'label' => __( 'Enable activity and error logging.', 'tsh-whatsapp-notify' ) ]
		);

		add_settings_field( 'log_level',
			__( 'Minimum Log Level', 'tsh-whatsapp-notify' ),
			[ $this, 'render_select_field' ], $option, 'tsh_wa_logging_section',
			[
				'option_key' => $option,
				'field'      => 'log_level',
				'default'    => 'info',
				'options'    => [
					'debug'   => __( 'Debug (verbose)', 'tsh-whatsapp-notify' ),
					'info'    => __( 'Info', 'tsh-whatsapp-notify' ),
					'warning' => __( 'Warning', 'tsh-whatsapp-notify' ),
					'error'   => __( 'Error only', 'tsh-whatsapp-notify' ),
				],
			]
		);

		add_settings_field( 'log_retention',
			__( 'Log Retention (days)', 'tsh-whatsapp-notify' ),
			[ $this, 'render_number_field' ], $option, 'tsh_wa_logging_section',
			[ 'option_key' => $option, 'field' => 'log_retention', 'default' => '30', 'min' => 1, 'max' => 365, 'desc' => __( 'Logs older than this many days are automatically deleted.', 'tsh-whatsapp-notify' ) ]
		);

		add_settings_field( 'log_to_db',
			__( 'Log to Database', 'tsh-whatsapp-notify' ),
			[ $this, 'render_checkbox_field' ], $option, 'tsh_wa_logging_section',
			[ 'option_key' => $option, 'field' => 'log_to_db', 'label' => __( 'Persist log entries to the database (enables filtering and search in the Logs page).', 'tsh-whatsapp-notify' ) ]
		);

		add_settings_field( 'log_to_file',
			__( 'Log to File', 'tsh-whatsapp-notify' ),
			[ $this, 'render_checkbox_field' ], $option, 'tsh_wa_logging_section',
			[ 'option_key' => $option, 'field' => 'log_to_file', 'label' => __( 'Mirror log entries to daily flat files in /logs/.', 'tsh-whatsapp-notify' ) ]
		);
	}

	// -------------------------------------------------------------------------
	// Advanced
	// -------------------------------------------------------------------------

	private function register_advanced_settings(): void {
		$option = self::OPTION_KEYS['advanced'];

		register_setting( $option, $option, [
			'sanitize_callback' => [ $this, 'sanitize_advanced' ],
		] );

		add_settings_section(
			'tsh_wa_advanced_section',
			__( 'Advanced Settings', 'tsh-whatsapp-notify' ),
			static function (): void {
				echo '<p class="description">' . esc_html__( 'Caution — advanced configuration options. Only change these if you know what you are doing.', 'tsh-whatsapp-notify' ) . '</p>';
			},
			$option
		);

		add_settings_field( 'debug_mode',
			__( 'Debug Mode', 'tsh-whatsapp-notify' ),
			[ $this, 'render_checkbox_field' ], $option, 'tsh_wa_advanced_section',
			[ 'option_key' => $option, 'field' => 'debug_mode', 'label' => __( 'Enable debug output (not for production use).', 'tsh-whatsapp-notify' ) ]
		);

		add_settings_field( 'remove_data_on_uninstall',
			__( 'Remove All Data on Uninstall', 'tsh-whatsapp-notify' ),
			[ $this, 'render_checkbox_field' ], $option, 'tsh_wa_advanced_section',
			[ 'option_key' => $option, 'field' => 'remove_data_on_uninstall', 'label' => __( 'Delete all plugin tables, options, and logs when the plugin is deleted. WARNING: irreversible.', 'tsh-whatsapp-notify' ) ]
		);
	}

	// -------------------------------------------------------------------------
	// Sanitization callbacks
	// -------------------------------------------------------------------------

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public function sanitize_general( array $input ): array {
		return [
			'plugin_name'  => sanitize_text_field( $input['plugin_name'] ?? '' ),
			'store_phone'  => sanitize_text_field( $input['store_phone'] ?? '' ),
			'test_mode'    => isset( $input['test_mode'] ) ? '1' : '0',
			'send_test_to' => sanitize_text_field( $input['send_test_to'] ?? '' ),
		];
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public function sanitize_api( array $input ): array {
		// Validate API version format (must match vNN.N pattern).
		$api_version = sanitize_text_field( $input['api_version'] ?? 'v23.0' );
		if ( ! preg_match( '/^v\d+\.\d+$/', $api_version ) ) {
			$api_version = 'v23.0';
		}

		return [
			'enable_api'           => isset( $input['enable_api'] ) ? '1' : '0',
			'phone_number_id'      => sanitize_text_field( $input['phone_number_id']     ?? '' ),
			'business_account_id'  => sanitize_text_field( $input['business_account_id'] ?? '' ),
			'access_token'         => sanitize_text_field( $input['access_token']         ?? '' ),
			'api_version'          => $api_version,
			'webhook_verify_token' => sanitize_text_field( $input['webhook_verify_token'] ?? '' ),
			'test_phone_number'    => sanitize_text_field( $input['test_phone_number']    ?? '' ),
			'request_timeout'      => (string) min( max( absint( $input['request_timeout'] ?? 30 ), 5 ), 120 ),
			'retry_attempts'       => (string) min( max( absint( $input['retry_attempts']  ?? 3  ), 0 ), 10 ),
			'retry_delay'          => (string) min( max( absint( $input['retry_delay']     ?? 5  ), 1 ), 120 ),
		];
	}

	/**
	 * Sanitise an array of toggle (checkbox) settings.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, string>
	 */
	public function sanitize_toggle_array( array $input ): array {
		$sanitized = [];
		foreach ( $input as $key => $value ) {
			$sanitized[ sanitize_key( $key ) ] = '1';
		}
		return $sanitized;
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public function sanitize_template_settings( array $input ): array {
		return [
			'default_language' => sanitize_key( $input['default_language'] ?? 'en' ),
			'enable_emoji'     => isset( $input['enable_emoji'] ) ? '1' : '0',
		];
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public function sanitize_queue( array $input ): array {
		return [
			'queue_enabled'  => isset( $input['queue_enabled'] ) ? '1' : '0',
			'batch_size'     => (string) min( max( absint( $input['batch_size'] ?? 10 ), 1 ), 100 ),
			'retry_attempts' => (string) min( max( absint( $input['retry_attempts'] ?? 3 ), 0 ), 10 ),
			'retry_delay'    => (string) min( max( absint( $input['retry_delay'] ?? 5 ), 1 ), 1440 ),
		];
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public function sanitize_logging( array $input ): array {
		$allowed_levels = [ 'debug', 'info', 'warning', 'error' ];

		return [
			'log_enabled'   => isset( $input['log_enabled'] ) ? '1' : '0',
			'log_level'     => in_array( $input['log_level'] ?? 'info', $allowed_levels, true ) ? $input['log_level'] : 'info',
			'log_retention' => (string) min( max( absint( $input['log_retention'] ?? 30 ), 1 ), 365 ),
			'log_to_db'     => isset( $input['log_to_db'] ) ? '1' : '0',
			'log_to_file'   => isset( $input['log_to_file'] ) ? '1' : '0',
		];
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public function sanitize_advanced( array $input ): array {
		return [
			'debug_mode'               => isset( $input['debug_mode'] ) ? '1' : '0',
			'remove_data_on_uninstall' => isset( $input['remove_data_on_uninstall'] ) ? '1' : '0',
		];
	}

	// -------------------------------------------------------------------------
	// WooCommerce Events
	// -------------------------------------------------------------------------

	private function register_wc_events_settings(): void {
		$option = self::OPTION_KEYS['wc_events'];

		register_setting( $option, $option, [
			'sanitize_callback' => [ $this, 'sanitize_wc_events' ],
		] );

		// --- Section: Per-event configuration table -------------------------

		add_settings_section(
			'tsh_wa_wc_events_section',
			__( 'Order Notification Events', 'tsh-whatsapp-notify' ),
			static function (): void {
				echo '<p>' . esc_html__( 'Configure which WooCommerce order events trigger WhatsApp notifications. Enable each event independently for admin and customer notifications.', 'tsh-whatsapp-notify' ) . '</p>';
			},
			$option
		);

		add_settings_field(
			'events_table',
			'',
			[ $this, 'render_wc_events_table' ],
			$option,
			'tsh_wa_wc_events_section'
		);

		// --- Section: Country code default ----------------------------------

		add_settings_section(
			'tsh_wa_wc_country_section',
			__( 'Customer Phone Settings', 'tsh-whatsapp-notify' ),
			static function (): void {
				echo '<p>' . esc_html__( 'Configure how customer phone numbers are resolved from WooCommerce billing data.', 'tsh-whatsapp-notify' ) . '</p>';
			},
			$option
		);

		add_settings_field(
			'default_country',
			__( 'Default Country Code', 'tsh-whatsapp-notify' ),
			[ $this, 'render_select_field' ],
			$option,
			'tsh_wa_wc_country_section',
			[
				'option_key' => $option,
				'field'      => 'default_country',
				'default'    => 'NG',
				'desc'       => __( 'Applied when the billing phone has no international prefix (+).', 'tsh-whatsapp-notify' ),
				'options'    => [
					'NG' => 'Nigeria (+234)',
					'GH' => 'Ghana (+233)',
					'KE' => 'Kenya (+254)',
					'ZA' => 'South Africa (+27)',
					'GB' => 'United Kingdom (+44)',
					'US' => 'United States (+1)',
				],
			]
		);
	}

	/**
	 * Render the per-event configuration table for the WooCommerce Events tab.
	 */
	public function render_wc_events_table(): void {
		$option  = self::OPTION_KEYS['wc_events'];
		$current = get_option( $option, [] );

		// Fetch available templates from the DB.
		global $wpdb;
		$templates_raw = $wpdb->get_results(
			"SELECT slug, name FROM `{$wpdb->prefix}tsh_wa_templates` WHERE status = 'active' ORDER BY name ASC"
		) ?: [];
		$template_opts = [ '' => __( '— Default —', 'tsh-whatsapp-notify' ) ];
		foreach ( $templates_raw as $t ) {
			$template_opts[ $t->slug ] = $t->name;
		}

		$events = \TSH\WhatsAppNotify\Orders\OrderStatusListener::ALL_EVENTS;

		echo '<div class="tsh-wa-events-table-wrap">';
		echo '<table class="widefat tsh-wa-events-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Event', 'tsh-whatsapp-notify' ) . '</th>';
		echo '<th>' . esc_html__( 'Enabled', 'tsh-whatsapp-notify' ) . '</th>';
		echo '<th>' . esc_html__( 'Notify Admin', 'tsh-whatsapp-notify' ) . '</th>';
		echo '<th>' . esc_html__( 'Notify Customer', 'tsh-whatsapp-notify' ) . '</th>';
		echo '<th>' . esc_html__( 'Admin Template', 'tsh-whatsapp-notify' ) . '</th>';
		echo '<th>' . esc_html__( 'Customer Template', 'tsh-whatsapp-notify' ) . '</th>';
		echo '<th>' . esc_html__( 'Delay (sec)', 'tsh-whatsapp-notify' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $events as $key => $label ) {
			$ev = $current[ $key ] ?? [];
			$enabled       = ! empty( $ev['enabled'] )          && '1' === (string) $ev['enabled'];
			$notify_admin  = ! empty( $ev['notify_admin'] )     && '1' === (string) $ev['notify_admin'];
			$notify_cust   = ! empty( $ev['notify_customer'] )  && '1' === (string) $ev['notify_customer'];
			$admin_tpl     = $ev['admin_template']    ?? '';
			$cust_tpl      = $ev['customer_template'] ?? '';
			$delay         = absint( $ev['delay_seconds'] ?? 0 );

			$row_class = $enabled ? 'tsh-wa-event-row enabled' : 'tsh-wa-event-row disabled';
			echo '<tr class="' . esc_attr( $row_class ) . '">';

			// Event label.
			echo '<td><strong>' . esc_html( $label ) . '</strong><br><code class="tsh-wa-event-key">' . esc_html( $key ) . '</code></td>';

			// Enabled.
			printf(
				'<td class="tsh-wa-cb-cell"><input type="checkbox" name="%s[%s][enabled]" value="1" %s></td>',
				esc_attr( $option ), esc_attr( $key ), checked( $enabled, true, false )
			);

			// Notify admin.
			printf(
				'<td class="tsh-wa-cb-cell"><input type="checkbox" name="%s[%s][notify_admin]" value="1" %s></td>',
				esc_attr( $option ), esc_attr( $key ), checked( $notify_admin, true, false )
			);

			// Notify customer.
			printf(
				'<td class="tsh-wa-cb-cell"><input type="checkbox" name="%s[%s][notify_customer]" value="1" %s></td>',
				esc_attr( $option ), esc_attr( $key ), checked( $notify_cust, true, false )
			);

			// Admin template select.
			echo '<td>';
			printf( '<select name="%s[%s][admin_template]">', esc_attr( $option ), esc_attr( $key ) );
			foreach ( $template_opts as $val => $tlabel ) {
				printf( '<option value="%s" %s>%s</option>', esc_attr( $val ), selected( $admin_tpl, $val, false ), esc_html( $tlabel ) );
			}
			echo '</select></td>';

			// Customer template select.
			echo '<td>';
			printf( '<select name="%s[%s][customer_template]">', esc_attr( $option ), esc_attr( $key ) );
			foreach ( $template_opts as $val => $tlabel ) {
				printf( '<option value="%s" %s>%s</option>', esc_attr( $val ), selected( $cust_tpl, $val, false ), esc_html( $tlabel ) );
			}
			echo '</select></td>';

			// Delay.
			printf(
				'<td><input type="number" name="%s[%s][delay_seconds]" value="%d" min="0" max="3600" class="small-text"> sec</td>',
				esc_attr( $option ), esc_attr( $key ), $delay
			);

			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * Sanitise the WooCommerce events settings.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public function sanitize_wc_events( array $input ): array {
		$clean  = [];
		$events = \TSH\WhatsAppNotify\Orders\OrderStatusListener::ALL_EVENTS;

		foreach ( $events as $key => $label ) {
			$ev = $input[ $key ] ?? [];
			$clean[ $key ] = [
				'enabled'          => ! empty( $ev['enabled'] ) ? '1' : '0',
				'notify_admin'     => ! empty( $ev['notify_admin'] ) ? '1' : '0',
				'notify_customer'  => ! empty( $ev['notify_customer'] ) ? '1' : '0',
				'admin_template'   => sanitize_key( $ev['admin_template'] ?? '' ),
				'customer_template'=> sanitize_key( $ev['customer_template'] ?? '' ),
				'delay_seconds'    => (string) min( max( absint( $ev['delay_seconds'] ?? 0 ), 0 ), 3600 ),
				'queue_immediately'=> '1',
			];
		}

		// Also persist the country code (from a separate field, same option key).
		$clean['default_country'] = sanitize_text_field( $input['default_country'] ?? 'NG' );

		return $clean;
	}

	// -------------------------------------------------------------------------
	// Field renderers
	// -------------------------------------------------------------------------

	/**
	 * Render a text <input>.
	 *
	 * @param array<string, mixed> $args
	 */
	public function render_text_field( array $args ): void {
		$opts  = get_option( $args['option_key'], [] );
		$value = $opts[ $args['field'] ] ?? ( $args['default'] ?? '' );
		printf(
			'<input type="text" name="%s[%s]" id="%s_%s" value="%s" placeholder="%s" class="regular-text">',
			esc_attr( $args['option_key'] ),
			esc_attr( $args['field'] ),
			esc_attr( $args['option_key'] ),
			esc_attr( $args['field'] ),
			esc_attr( $value ),
			esc_attr( $args['placeholder'] ?? '' )
		);
		if ( ! empty( $args['desc'] ) ) {
			echo '<p class="description">' . esc_html( $args['desc'] ) . '</p>';
		}
	}

	/**
	 * Render a password <input> (value replaced with placeholder on display).
	 *
	 * @param array<string, mixed> $args
	 */
	public function render_password_field( array $args ): void {
		$opts  = get_option( $args['option_key'], [] );
		$value = $opts[ $args['field'] ] ?? '';
		printf(
			'<input type="password" name="%s[%s]" id="%s_%s" value="%s" class="regular-text" autocomplete="new-password">',
			esc_attr( $args['option_key'] ),
			esc_attr( $args['field'] ),
			esc_attr( $args['option_key'] ),
			esc_attr( $args['field'] ),
			esc_attr( $value )
		);
		if ( ! empty( $args['desc'] ) ) {
			echo '<p class="description">' . esc_html( $args['desc'] ) . '</p>';
		}
	}

	/**
	 * Render a token field: password input + reveal + copy buttons.
	 * The raw token value is sent as a form value but never echoed as text.
	 *
	 * @param array<string, mixed> $args
	 */
	public function render_token_field( array $args ): void {
		$opts  = get_option( $args['option_key'], [] );
		$value = $opts[ $args['field'] ] ?? '';
		$id    = esc_attr( $args['option_key'] ) . '_' . esc_attr( $args['field'] );

		printf(
			'<div class="tsh-wa-token-field" style="display:flex;align-items:center;gap:6px;">' .
			'<input type="password" name="%s[%s]" id="%s" value="%s" class="regular-text" autocomplete="new-password" style="font-family:monospace;">' .
			'<button type="button" class="button tsh-wa-pw-toggle" data-target="%s" aria-label="%s"><span class="dashicons dashicons-visibility"></span></button>' .
			'</div>',
			esc_attr( $args['option_key'] ),
			esc_attr( $args['field'] ),
			esc_attr( $id ),
			esc_attr( $value ),
			esc_attr( $id ),
			esc_attr__( 'Show / hide token', 'tsh-whatsapp-notify' )
		);

		if ( ! empty( $args['desc'] ) ) {
			echo '<p class="description">' . esc_html( $args['desc'] ) . '</p>';
		}

		if ( $value ) {
			echo '<p class="description" style="color:var(--tsh-wa-green-dark);">'
				. esc_html__( '✓ Token is set. Leave blank to keep the existing value.', 'tsh-whatsapp-notify' )
				. '</p>';
		}
	}

	/**
	 * Render a checkbox <input>.
	 *
	 * @param array<string, mixed> $args
	 */
	public function render_checkbox_field( array $args ): void {
		$opts    = get_option( $args['option_key'], [] );
		$checked = ! empty( $opts[ $args['field'] ] ) && '1' === (string) $opts[ $args['field'] ];
		printf(
			'<label><input type="checkbox" name="%s[%s]" id="%s_%s" value="1" %s> %s</label>',
			esc_attr( $args['option_key'] ),
			esc_attr( $args['field'] ),
			esc_attr( $args['option_key'] ),
			esc_attr( $args['field'] ),
			checked( $checked, true, false ),
			esc_html( $args['label'] ?? '' )
		);
	}

	/**
	 * Render a number <input>.
	 *
	 * @param array<string, mixed> $args
	 */
	public function render_number_field( array $args ): void {
		$opts  = get_option( $args['option_key'], [] );
		$value = $opts[ $args['field'] ] ?? ( $args['default'] ?? '' );
		printf(
			'<input type="number" name="%s[%s]" id="%s_%s" value="%s" min="%d" max="%d" class="small-text">',
			esc_attr( $args['option_key'] ),
			esc_attr( $args['field'] ),
			esc_attr( $args['option_key'] ),
			esc_attr( $args['field'] ),
			esc_attr( (string) $value ),
			absint( $args['min'] ?? 0 ),
			absint( $args['max'] ?? 9999 )
		);
		if ( ! empty( $args['desc'] ) ) {
			echo '<p class="description">' . esc_html( $args['desc'] ) . '</p>';
		}
	}

	/**
	 * Render a <select> dropdown.
	 *
	 * @param array<string, mixed> $args
	 */
	public function render_select_field( array $args ): void {
		$opts     = get_option( $args['option_key'], [] );
		$current  = $opts[ $args['field'] ] ?? ( $args['default'] ?? '' );
		$options  = $args['options'] ?? [];

		printf(
			'<select name="%s[%s]" id="%s_%s">',
			esc_attr( $args['option_key'] ),
			esc_attr( $args['field'] ),
			esc_attr( $args['option_key'] ),
			esc_attr( $args['field'] )
		);

		foreach ( $options as $val => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $val ),
				selected( $current, $val, false ),
				esc_html( $label )
			);
		}

		echo '</select>';
	}

	// -------------------------------------------------------------------------
	// Page renderer
	// -------------------------------------------------------------------------

	/**
	 * Render the settings page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'tsh-whatsapp-notify' ) );
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';

		if ( ! array_key_exists( $active_tab, self::TABS ) ) {
			$active_tab = 'general';
		}

		$option_key = self::OPTION_KEYS[ $active_tab ];

		$template = TSH_WA_PATH . 'templates/admin/settings.php';

		if ( file_exists( $template ) ) {
			include $template;
		}
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Return the full tab list.
	 *
	 * @return array<string, string>
	 */
	public static function get_tabs(): array {
		return self::TABS;
	}

	/**
	 * Return the option key for a given tab slug.
	 *
	 * @param string $tab
	 * @return string
	 */
	public static function get_option_key( string $tab ): string {
		return self::OPTION_KEYS[ $tab ] ?? '';
	}
}
