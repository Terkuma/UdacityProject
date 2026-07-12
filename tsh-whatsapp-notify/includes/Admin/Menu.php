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
use TSH\WhatsAppNotify\Admin\Pages\Automation;
use TSH\WhatsAppNotify\Admin\Pages\Inbox;
use TSH\WhatsAppNotify\Admin\Pages\Marketing;

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
	public const SLUG_INBOX       = 'tsh-whatsapp-notify-inbox';
	public const SLUG_AUTOMATION  = 'tsh-whatsapp-notify-automation';
	public const SLUG_MARKETING   = 'tsh-whatsapp-notify-marketing';

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

	/** @var Inbox */
	private Inbox $inbox;

	/** @var Automation */
	private Automation $automation;

	/** @var Marketing */
	private Marketing $marketing;

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
		$this->inbox       = new Inbox();
		$this->automation  = new Automation();
		$this->marketing   = new Marketing();

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

		// Inbox (Phase 6).
		add_submenu_page(
			self::SLUG_DASHBOARD,
			__( 'Inbox — TSH WhatsApp Notify', 'tsh-whatsapp-notify' ),
			__( 'Inbox', 'tsh-whatsapp-notify' ),
			'manage_woocommerce',
			self::SLUG_INBOX,
			[ $this->inbox, 'render' ]
		);

		// Automation (Phase 7).
		add_submenu_page(
			self::SLUG_DASHBOARD,
			__( 'Automation — TSH WhatsApp Notify', 'tsh-whatsapp-notify' ),
			__( 'Automation', 'tsh-whatsapp-notify' ),
			'manage_woocommerce',
			self::SLUG_AUTOMATION,
			[ $this->automation, 'render' ]
		);

		// Marketing (Phase 8).
		add_submenu_page(
			self::SLUG_DASHBOARD,
			__( 'Marketing — TSH WhatsApp Notify', 'tsh-whatsapp-notify' ),
			__( 'Marketing', 'tsh-whatsapp-notify' ),
			'manage_woocommerce',
			self::SLUG_MARKETING,
			[ $this->marketing, 'render' ]
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

		// Phase 7 — Automation-specific assets (only on the automation page).
		if ( str_contains( $hook_suffix, self::SLUG_AUTOMATION ) ) {
			wp_enqueue_style(
				'tsh-wa-automation',
				TSH_WA_URL . 'assets/css/automation.css',
				[ 'tsh-wa-admin' ],
				TSH_WA_VERSION
			);
			wp_enqueue_script(
				'tsh-wa-automation',
				TSH_WA_URL . 'assets/js/automation.js',
				[ 'jquery', 'tsh-wa-admin' ],
				TSH_WA_VERSION,
				true
			);
		}

		// Phase 8 — Marketing-specific assets (only on the marketing page).
		if ( str_contains( $hook_suffix, self::SLUG_MARKETING ) ) {
			wp_enqueue_style(
				'tsh-wa-marketing',
				TSH_WA_URL . 'assets/css/marketing.css',
				[ 'tsh-wa-admin' ],
				TSH_WA_VERSION
			);
			wp_enqueue_script(
				'tsh-wa-marketing',
				TSH_WA_URL . 'assets/js/marketing.js',
				[ 'jquery', 'tsh-wa-admin' ],
				TSH_WA_VERSION,
				true
			);
			wp_localize_script(
				'tsh-wa-marketing',
				'tshWaMarketing',
				[
					'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
					'nonce'     => wp_create_nonce( \TSH\WhatsAppNotify\Admin\Ajax::NONCE_ACTION ),
					'pluginUrl' => TSH_WA_URL,
					'currency'  => get_woocommerce_currency_symbol(),
					'i18n'      => [
						'confirm_delete'   => __( 'Delete this campaign and all its data? This cannot be undone.', 'tsh-whatsapp-notify' ),
						'confirm_cancel'   => __( 'Cancel this campaign? Queued messages will still be sent.', 'tsh-whatsapp-notify' ),
						'confirm_archive'  => __( 'Archive this campaign?', 'tsh-whatsapp-notify' ),
						'launching'        => __( 'Launching…', 'tsh-whatsapp-notify' ),
						'saving'           => __( 'Saving…', 'tsh-whatsapp-notify' ),
						'saved'            => __( 'Campaign saved.', 'tsh-whatsapp-notify' ),
						'error'            => __( 'An error occurred. Please try again.', 'tsh-whatsapp-notify' ),
						'preview_loading'  => __( 'Calculating audience…', 'tsh-whatsapp-notify' ),
						'no_campaigns'     => __( 'No campaigns found.', 'tsh-whatsapp-notify' ),
					],
				]
			);
		}

		// Localise script data.
		wp_localize_script(
			'tsh-wa-admin',
			'tshWaAdmin',
			[
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( \TSH\WhatsAppNotify\Admin\Ajax::NONCE_ACTION ),
				'pluginUrl' => TSH_WA_URL,
				'i18n'      => [
					'confirm_clear'          => __( 'Are you sure? This action cannot be undone.', 'tsh-whatsapp-notify' ),
					'confirm_reset'          => __( 'Reset all API settings to defaults? The access token will be cleared. This cannot be undone.', 'tsh-whatsapp-notify' ),
					'saved'                  => __( 'Settings saved.', 'tsh-whatsapp-notify' ),
					'error'                  => __( 'An error occurred. Please try again.', 'tsh-whatsapp-notify' ),
					'verifying'              => __( 'Verifying…', 'tsh-whatsapp-notify' ),
					'verify_connection'      => __( 'Verify Connection', 'tsh-whatsapp-notify' ),
					'connected'              => __( 'Connected', 'tsh-whatsapp-notify' ),
					'disconnected'           => __( 'Disconnected', 'tsh-whatsapp-notify' ),
					'fill_required'          => __( 'Phone number and message are required.', 'tsh-whatsapp-notify' ),
					'message_too_long'       => __( 'Message exceeds the 4096-character limit.', 'tsh-whatsapp-notify' ),
					'phone_number'           => __( 'Phone', 'tsh-whatsapp-notify' ),
					'business'               => __( 'Business', 'tsh-whatsapp-notify' ),
					'quality'                => __( 'Quality Rating', 'tsh-whatsapp-notify' ),
					'api_version'            => __( 'API Version', 'tsh-whatsapp-notify' ),
					'latency'                => __( 'Latency', 'tsh-whatsapp-notify' ),
					'show_password'          => __( 'Show / hide', 'tsh-whatsapp-notify' ),
					// Phase 6 — Inbox.
					'inbox_sending'          => __( 'Sending…', 'tsh-whatsapp-notify' ),
					'inbox_send'             => __( 'Send', 'tsh-whatsapp-notify' ),
					'inbox_note_added'       => __( 'Note added.', 'tsh-whatsapp-notify' ),
					'inbox_assigned'         => __( 'Assigned.', 'tsh-whatsapp-notify' ),
					'inbox_status_updated'   => __( 'Status updated.', 'tsh-whatsapp-notify' ),
					'inbox_confirm_rebuild'  => __( 'This will permanently delete ALL conversation data. Continue?', 'tsh-whatsapp-notify' ),
					// Phase 5 — Template manager.
					'syncing'                => __( 'Syncing…', 'tsh-whatsapp-notify' ),
					'sync_complete'          => __( 'Sync complete.', 'tsh-whatsapp-notify' ),
					'sync_error'             => __( 'Sync failed.', 'tsh-whatsapp-notify' ),
					'confirm_full_sync'      => __( 'This will delete all locally synced templates and re-fetch everything from Meta. Continue?', 'tsh-whatsapp-notify' ),
					'template_assigned'      => __( 'Template assigned successfully.', 'tsh-whatsapp-notify' ),
					'template_unassigned'    => __( 'Assignment removed.', 'tsh-whatsapp-notify' ),
					'cache_flushed'          => __( 'Template cache cleared.', 'tsh-whatsapp-notify' ),
					'preview_loading'        => __( 'Loading preview…', 'tsh-whatsapp-notify' ),
					'no_templates_found'     => __( 'No templates found.', 'tsh-whatsapp-notify' ),
					'import_success'         => __( 'Import completed.', 'tsh-whatsapp-notify' ),
					'export_success'         => __( 'Templates exported.', 'tsh-whatsapp-notify' ),
					'select_event'           => __( '— Select Event —', 'tsh-whatsapp-notify' ),
					'assign_template'        => __( 'Assign Template', 'tsh-whatsapp-notify' ),
					'remove_assignment'      => __( 'Remove Assignment', 'tsh-whatsapp-notify' ),
					'no_variables'           => __( 'This template has no variable placeholders.', 'tsh-whatsapp-notify' ),
					'variable_label'         => __( 'Variable {{%d}}', 'tsh-whatsapp-notify' ),
					'refresh_preview'        => __( 'Refresh Preview', 'tsh-whatsapp-notify' ),
					'stats_loading'          => __( 'Loading stats…', 'tsh-whatsapp-notify' ),
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
		// Our own admin pages.
		if ( str_contains( $hook_suffix, 'tsh-whatsapp-notify' ) ) {
			return true;
		}

		// WooCommerce Classic order edit screen (post.php?post=<id>&action=edit, post-new.php).
		if ( str_contains( $hook_suffix, 'post.php' ) || 'post-new.php' === $hook_suffix ) {
			global $post_type;
			if ( 'shop_order' === ( $post_type ?? '' ) ) {
				return true;
			}
		}

		// WooCommerce HPOS order edit screen.
		if ( str_contains( $hook_suffix, 'wc-orders' ) ) {
			return true;
		}

		return false;
	}
}
