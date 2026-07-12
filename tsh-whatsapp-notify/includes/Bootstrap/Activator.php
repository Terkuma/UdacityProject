<?php
/**
 * Plugin activator.
 *
 * @package TSH\WhatsAppNotify\Bootstrap
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Bootstrap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TSH\WhatsAppNotify\Database\Installer;

/**
 * Class Activator
 *
 * Handles all one-time setup tasks on plugin activation:
 * - Verifies minimum PHP / WooCommerce requirements.
 * - Creates required filesystem directories.
 * - Runs the database installer.
 * - Seeds default settings.
 * - Stores plugin and DB version stamps.
 */
final class Activator {

	/**
	 * Run activation routine.
	 *
	 * Hooked via register_activation_hook() in the main plugin file.
	 *
	 * @param bool $network_wide Whether the plugin is being network-activated.
	 */
	public static function activate( bool $network_wide = false ): void {
		self::check_php_version();
		self::check_woocommerce();
		self::create_directories();

		$installer = new Installer();
		$installer->run();

		self::seed_default_settings();
		self::store_version();
		self::record_activation_date();

		// Flush rewrite rules after activation.
		flush_rewrite_rules();
	}

	// -------------------------------------------------------------------------
	// Requirement checks
	// -------------------------------------------------------------------------

	/**
	 * Abort activation if PHP version does not meet the minimum requirement.
	 */
	private static function check_php_version(): void {
		if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
			wp_die(
				sprintf(
					/* translators: %s: required PHP version */
					esc_html__( 'TSH WhatsApp Notify requires PHP %s or higher. Please upgrade PHP before activating this plugin.', 'tsh-whatsapp-notify' ),
					'8.1'
				),
				esc_html__( 'Plugin Activation Error', 'tsh-whatsapp-notify' ),
				[ 'back_link' => true ]
			);
		}
	}

	/**
	 * Abort activation if WooCommerce is not active.
	 */
	private static function check_woocommerce(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			wp_die(
				esc_html__( 'TSH WhatsApp Notify requires WooCommerce to be installed and active. Please install WooCommerce before activating this plugin.', 'tsh-whatsapp-notify' ),
				esc_html__( 'Plugin Activation Error', 'tsh-whatsapp-notify' ),
				[ 'back_link' => true ]
			);
		}
	}

	// -------------------------------------------------------------------------
	// Filesystem setup
	// -------------------------------------------------------------------------

	/**
	 * Create required plugin directories with proper security files.
	 */
	private static function create_directories(): void {
		$dirs = [
			TSH_WA_LOG_DIR,
			TSH_WA_PATH . 'assets/css',
			TSH_WA_PATH . 'assets/js',
			TSH_WA_PATH . 'assets/images',
			TSH_WA_PATH . 'assets/icons',
			TSH_WA_PATH . 'templates/admin',
			TSH_WA_PATH . 'languages',
		];

		foreach ( $dirs as $dir ) {
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}

			// Drop an index.php to prevent directory listing.
			$index = $dir . '/index.php';
			if ( ! file_exists( $index ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( $index, "<?php\n// Silence is golden.\n" );
			}
		}

		// Drop an .htaccess in the logs directory to block direct HTTP access.
		$htaccess = TSH_WA_LOG_DIR . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $htaccess, "deny from all\n" );
		}
	}

	// -------------------------------------------------------------------------
	// Default settings
	// -------------------------------------------------------------------------

	/**
	 * Seed default option values (only on first activation; never overwrites).
	 */
	private static function seed_default_settings(): void {
		$defaults = [
			'tsh_wa_general_settings' => [
				'plugin_name'    => 'TSH WhatsApp Notify',
				'store_phone'    => '',
				'test_mode'      => '0',
				'send_test_to'   => '',
			],
			'tsh_wa_api_settings' => [
				'enable_api'           => '0',
				'phone_number_id'      => '',
				'business_account_id'  => '',
				'access_token'         => '',
				'api_version'          => 'v23.0',
				'webhook_verify_token' => wp_generate_password( 32, false ),
				'test_phone_number'    => '',
				'request_timeout'      => '30',
				'retry_attempts'       => '3',
				'retry_delay'          => '5',
			],
			'tsh_wa_queue_settings' => [
				'batch_size'       => '10',
				'retry_attempts'   => '3',
				'retry_delay'      => '5',
				'queue_enabled'    => '1',
			],
			'tsh_wa_logging_settings' => [
				'log_enabled'      => '1',
				'log_level'        => 'info',
				'log_retention'    => '30',
				'log_to_db'        => '1',
				'log_to_file'      => '0',
			],
			'tsh_wa_advanced_settings' => [
				'remove_data_on_uninstall' => '0',
				'debug_mode'               => '0',
			],
		];

		// Phase 3 — WooCommerce Events + Admin Recipients.
		$event_default = [
			'enabled'          => '0',
			'notify_admin'     => '1',
			'notify_customer'  => '0',
			'admin_template'   => '',
			'customer_template'=> '',
			'delay_seconds'    => '0',
			'queue_immediately'=> '1',
		];

		$defaults['tsh_wa_wc_events_settings'] = array_fill_keys(
			array_keys( \TSH\WhatsAppNotify\Orders\OrderStatusListener::ALL_EVENTS ),
			$event_default
		);

		$defaults['tsh_wa_admin_recipients'] = [];

		// Phase 6 — Inbox / Conversation Hub settings.
		$defaults['tsh_wa_inbox_settings'] = [
			'auto_download_media'   => '1',
			'media_retention_days'  => '90',
			'polling_interval'      => '15',
			'async_webhook'         => '0',
			'auto_assign_enabled'   => '0',
			'auto_assign_user_id'   => '',
			'auto_archive_days'     => '30',
			'max_download_size_mb'  => '25',
			'custom_labels'         => [],
		];

		foreach ( $defaults as $option_name => $option_value ) {
			if ( false === get_option( $option_name ) ) {
				add_option( $option_name, $option_value, '', 'no' );
			}
		}
	}

	// -------------------------------------------------------------------------
	// Version stamps
	// -------------------------------------------------------------------------

	/**
	 * Store the plugin version and DB schema version in wp_options.
	 */
	private static function store_version(): void {
		update_option( 'tsh_wa_version', TSH_WA_VERSION, false );
		update_option( 'tsh_wa_db_version', Installer::DB_VERSION, false );
	}

	/**
	 * Record the activation timestamp (only set once — never overwritten).
	 */
	private static function record_activation_date(): void {
		if ( ! get_option( 'tsh_wa_activation_date' ) ) {
			add_option( 'tsh_wa_activation_date', current_time( 'mysql' ), '', 'no' );
		}
	}
}
