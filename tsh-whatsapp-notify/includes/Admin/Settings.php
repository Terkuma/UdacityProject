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

		add_settings_section(
			'tsh_wa_api_section',
			__( 'Meta WhatsApp Cloud API Credentials', 'tsh-whatsapp-notify' ),
			static function (): void {
				echo '<p>' . wp_kses(
					__( 'Enter your <strong>Meta Business</strong> WhatsApp Cloud API credentials. These are required to send messages.', 'tsh-whatsapp-notify' ),
					[ 'strong' => [] ]
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
			[ $this, 'render_password_field' ], $option, 'tsh_wa_api_section',
			[ 'option_key' => $option, 'field' => 'access_token', 'desc' => __( 'Your Meta System User permanent access token. Stored encrypted.', 'tsh-whatsapp-notify' ) ]
		);

		add_settings_field( 'api_version',
			__( 'Graph API Version', 'tsh-whatsapp-notify' ),
			[ $this, 'render_text_field' ], $option, 'tsh_wa_api_section',
			[ 'option_key' => $option, 'field' => 'api_version', 'default' => 'v19.0', 'desc' => __( 'Meta Graph API version, e.g. v19.0.', 'tsh-whatsapp-notify' ) ]
		);

		add_settings_field( 'webhook_verify_token',
			__( 'Webhook Verify Token', 'tsh-whatsapp-notify' ),
			[ $this, 'render_password_field' ], $option, 'tsh_wa_api_section',
			[ 'option_key' => $option, 'field' => 'webhook_verify_token', 'desc' => __( 'Secret token you enter in the Meta webhook configuration. Auto-generated on activation.', 'tsh-whatsapp-notify' ) ]
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
			[ 'option_key' => $option, 'field' => 'retry_delay', 'default' => '5', 'min' => 1, 'max' => 1440, 'desc' => __( 'Minutes to wait before retrying a failed message.', 'tsh-whatsapp-notify' ) ]
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
		return [
			'phone_number_id'      => sanitize_text_field( $input['phone_number_id'] ?? '' ),
			'business_account_id'  => sanitize_text_field( $input['business_account_id'] ?? '' ),
			'access_token'         => sanitize_text_field( $input['access_token'] ?? '' ),
			'api_version'          => sanitize_text_field( $input['api_version'] ?? 'v19.0' ),
			'webhook_verify_token' => sanitize_text_field( $input['webhook_verify_token'] ?? '' ),
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
		// Show masked placeholder when a value already exists.
		$display = $value ? str_repeat( '•', min( strlen( $value ), 32 ) ) : '';
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
