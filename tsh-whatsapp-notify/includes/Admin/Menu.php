<?php
/**
 * Admin menu registration.
 *
 * @package TSH\WhatsAppNotify\Admin
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TSH\WhatsAppNotify\Admin\Pages\Orders;
use TSH\WhatsAppNotify\Admin\Pages\Templates;
use TSH\WhatsAppNotify\Admin\Pages\Queue;
use TSH\WhatsAppNotify\Admin\Pages\Logs;
use TSH\WhatsAppNotify\Admin\Pages\Tools;
use TSH\WhatsAppNotify\Admin\Pages\About;

/**
 * Class Menu
 *
 * Registers the plugin menu under WooCommerce in the WordPress admin sidebar.
 *
 * Menu structure:
 *  WooCommerce
 *   └─ TSH WhatsApp Notify            (Dashboard — top-level hook)
 *       ├─ Dashboard
 *       ├─ Orders
 *       ├─ Templates
 *       ├─ Queue
 *       ├─ Logs
 *       ├─ Settings
 *       ├─ Tools
 *       └─ About
 */
final class Menu {

	// -------------------------------------------------------------------------
	// Page slug constants
	// -------------------------------------------------------------------------

	public const SLUG_DASHBOARD  = 'tsh-whatsapp-notify';
	public const SLUG_ORDERS     = 'tsh-whatsapp-notify-orders';
	public const SLUG_TEMPLATES  = 'tsh-whatsapp-notify-templates';
	public const SLUG_QUEUE      = 'tsh-whatsapp-notify-queue';
	public const SLUG_LOGS       = 'tsh-whatsapp-notify-logs';
	public const SLUG_SETTINGS   = 'tsh-whatsapp-notify-settings';
	public const SLUG_TOOLS      = 'tsh-whatsapp-notify-tools';
	public const SLUG_ABOUT      = 'tsh-whatsapp-notify-about';

	/** @var Dashboard */
	private Dashboard $dashboard;

	/** @var Settings */
	private Settings $settings;

	/** @var Orders */
	private Orders $orders;

	/** @var Templates */
	private Templates $templates;

	/** @var Queue */
	private Queue $queue;

	/** @var Logs */
	private Logs $logs;

	/** @var Tools */
	private Tools $tools;

	/** @var About */
	private About $about;

	/**
	 * Constructor — registers menu hooks.
	 */
	public function __construct() {
		$this->dashboard = new Dashboard();
		$this->settings  = new Settings();
		$this->orders    = new Orders();
		$this->templates = new Templates();
		$this->queue     = new Queue();
		$this->logs      = new Logs();
		$this->tools     = new Tools();
		$this->about     = new About();

		add_action( 'admin_menu', [ $this, 'register_menus' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	// -------------------------------------------------------------------------
	// Menu registration
	// -------------------------------------------------------------------------

	/**
	 * Register all admin pages under WooCommerce.
	 */
	public function register_menus(): void {
		// Parent (appears under WooCommerce) — renders the Dashboard page.
		add_submenu_page(
			'woocommerce',
			__( 'TSH WhatsApp Notify', 'tsh-whatsapp-notify' ),
			__( 'TSH WhatsApp Notify', 'tsh-whatsapp-notify' ),
			'manage_woocommerce',
			self::SLUG_DASHBOARD,
			[ $this->dashboard, 'render' ]
		);

		// Dashboard (explicit duplicate so the submenu label is "Dashboard").
		add_submenu_page(
			self::SLUG_DASHBOARD,
			__( 'Dashboard — TSH WhatsApp Notify', 'tsh-whatsapp-notify' ),
			__( 'Dashboard', 'tsh-whatsapp-notify' ),
			'manage_woocommerce',
			self::SLUG_DASHBOARD,
			[ $this->dashboard, 'render' ]
		);

		// Orders.
		add_submenu_page(
			self::SLUG_DASHBOARD,
			__( 'Orders — TSH WhatsApp Notify', 'tsh-whatsapp-notify' ),
			__( 'Orders', 'tsh-whatsapp-notify' ),
			'manage_woocommerce',
			self::SLUG_ORDERS,
			[ $this->orders, 'render' ]
		);

		// Templates.
		add_submenu_page(
			self::SLUG_DASHBOARD,
			__( 'Templates — TSH WhatsApp Notify', 'tsh-whatsapp-notify' ),
			__( 'Templates', 'tsh-whatsapp-notify' ),
			'manage_woocommerce',
			self::SLUG_TEMPLATES,
			[ $this->templates, 'render' ]
		);

		// Queue.
		add_submenu_page(
			self::SLUG_DASHBOARD,
			__( 'Queue — TSH WhatsApp Notify', 'tsh-whatsapp-notify' ),
			__( 'Queue', 'tsh-whatsapp-notify' ),
			'manage_woocommerce',
			self::SLUG_QUEUE,
			[ $this->queue, 'render' ]
		);

		// Logs.
		add_submenu_page(
			self::SLUG_DASHBOARD,
			__( 'Logs — TSH WhatsApp Notify', 'tsh-whatsapp-notify' ),
			__( 'Logs', 'tsh-whatsapp-notify' ),
			'manage_woocommerce',
			self::SLUG_LOGS,
			[ $this->logs, 'render' ]
		);

		// Settings.
		add_submenu_page(
			self::SLUG_DASHBOARD,
			__( 'Settings — TSH WhatsApp Notify', 'tsh-whatsapp-notify' ),
			__( 'Settings', 'tsh-whatsapp-notify' ),
			'manage_woocommerce',
			self::SLUG_SETTINGS,
			[ $this->settings, 'render' ]
		);

		// Tools.
		add_submenu_page(
			self::SLUG_DASHBOARD,
			__( 'Tools — TSH WhatsApp Notify', 'tsh-whatsapp-notify' ),
			__( 'Tools', 'tsh-whatsapp-notify' ),
			'manage_woocommerce',
			self::SLUG_TOOLS,
			[ $this->tools, 'render' ]
		);

		// About.
		add_submenu_page(
			self::SLUG_DASHBOARD,
			__( 'About — TSH WhatsApp Notify', 'tsh-whatsapp-notify' ),
			__( 'About', 'tsh-whatsapp-notify' ),
			'manage_woocommerce',
			self::SLUG_ABOUT,
			[ $this->about, 'render' ]
		);
	}

	// -------------------------------------------------------------------------
	// Assets
	// -------------------------------------------------------------------------

	/**
	 * Enqueue plugin admin CSS and JS only on plugin pages.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		// Only load on our own pages.
		if ( ! $this->is_plugin_page( $hook_suffix ) ) {
			return;
		}

		// Admin stylesheet.
		wp_enqueue_style(
			'tsh-wa-admin',
			TSH_WA_URL . 'assets/css/admin.css',
			[],
			TSH_WA_VERSION
		);

		// Admin JavaScript.
		wp_enqueue_script(
			'tsh-wa-admin',
			TSH_WA_URL . 'assets/js/admin.js',
			[ 'jquery' ],
			TSH_WA_VERSION,
			true
		);

		// Localise script data.
		wp_localize_script(
			'tsh-wa-admin',
			'tshWaAdmin',
			[
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( \TSH\WhatsAppNotify\Admin\Ajax::NONCE_ACTION ),
				'pluginUrl' => TSH_WA_URL,
				'i18n'      => [
					'confirm_clear'      => __( 'Are you sure? This action cannot be undone.', 'tsh-whatsapp-notify' ),
					'confirm_reset'      => __( 'Reset all API settings to defaults? The access token will be cleared. This cannot be undone.', 'tsh-whatsapp-notify' ),
					'saved'              => __( 'Settings saved.', 'tsh-whatsapp-notify' ),
					'error'              => __( 'An error occurred. Please try again.', 'tsh-whatsapp-notify' ),
					'verifying'          => __( 'Verifying…', 'tsh-whatsapp-notify' ),
					'verify_connection'  => __( 'Verify Connection', 'tsh-whatsapp-notify' ),
					'connected'          => __( 'Connected', 'tsh-whatsapp-notify' ),
					'disconnected'       => __( 'Disconnected', 'tsh-whatsapp-notify' ),
					'fill_required'      => __( 'Phone number and message are required.', 'tsh-whatsapp-notify' ),
					'message_too_long'   => __( 'Message exceeds the 4096-character limit.', 'tsh-whatsapp-notify' ),
					'phone_number'       => __( 'Phone', 'tsh-whatsapp-notify' ),
					'business'           => __( 'Business', 'tsh-whatsapp-notify' ),
					'quality'            => __( 'Quality Rating', 'tsh-whatsapp-notify' ),
					'api_version'        => __( 'API Version', 'tsh-whatsapp-notify' ),
					'latency'            => __( 'Latency', 'tsh-whatsapp-notify' ),
					'show_password'      => __( 'Show / hide', 'tsh-whatsapp-notify' ),
				],
			]
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Check whether the current admin page belongs to this plugin.
	 *
	 * WordPress passes hook suffixes like:
	 *   'woocommerce_page_tsh-whatsapp-notify'
	 *   'tsh-whatsapp-notify_page_tsh-whatsapp-notify-settings'
	 *
	 * @param string $hook_suffix
	 * @return bool
	 */
	private function is_plugin_page( string $hook_suffix ): bool {
		return str_contains( $hook_suffix, 'tsh-whatsapp-notify' );
	}
}
