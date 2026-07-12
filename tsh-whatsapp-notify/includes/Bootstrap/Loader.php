<?php
/**
 * Plugin loader / bootstrap.
 *
 * @package TSH\WhatsAppNotify\Bootstrap
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Bootstrap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TSH\WhatsAppNotify\Admin\Ajax;
use TSH\WhatsAppNotify\Admin\Menu;
use TSH\WhatsAppNotify\Admin\OrderMetaBox;
use TSH\WhatsAppNotify\API\HealthMonitor;
use TSH\WhatsAppNotify\Cron\Scheduler;
use TSH\WhatsAppNotify\Database\Installer;
use TSH\WhatsAppNotify\Orders\OrderListener;
use TSH\WhatsAppNotify\Orders\OrderStatusListener;
use TSH\WhatsAppNotify\Queue\QueueProcessor;
use TSH\WhatsAppNotify\Inbox\InboxManager;
use TSH\WhatsAppNotify\Templates\TemplateManager;
use TSH\WhatsAppNotify\Templates\TemplateSync;

/**
 * Class Loader
 *
 * Entry point for all plugin initialisation:
 * - Verifies WooCommerce dependency.
 * - Lazily boots admin or front-end components.
 * - Registers WordPress/WooCommerce hooks.
 * - Loads the text domain.
 */
final class Loader {

	/** @var self|null Singleton instance. */
	private static ?self $instance = null;

	/** @var array<string, object> Registered service components. */
	private array $components = [];

	/**
	 * Private constructor — use ::instance().
	 */
	private function __construct() {
		$this->check_requirements();
	}

	// -------------------------------------------------------------------------
	// Singleton access
	// -------------------------------------------------------------------------

	/**
	 * Return the singleton instance (creates it on first call).
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	// -------------------------------------------------------------------------
	// Requirement check
	// -------------------------------------------------------------------------

	/**
	 * Verify WooCommerce is active; abort gracefully if not.
	 */
	private function check_requirements(): void {
		if ( ! $this->is_woocommerce_active() ) {
			add_action( 'admin_notices', [ $this, 'notice_missing_woocommerce' ] );
			return;
		}

		$this->init();
	}

	/**
	 * Check whether WooCommerce is loaded.
	 */
	private function is_woocommerce_active(): bool {
		return class_exists( 'WooCommerce' );
	}

	// -------------------------------------------------------------------------
	// Initialisation
	// -------------------------------------------------------------------------

	/**
	 * Boot all plugin components and register hooks.
	 */
	private function init(): void {
		$this->load_text_domain();
		$this->register_components();
		$this->register_hooks();
	}

	/**
	 * Instantiate all service classes.
	 * Admin classes are only loaded in the admin context (lazy loading).
	 */
	private function register_components(): void {
		// Always boot the cron scheduler (registers events on 'init').
		$this->components['scheduler'] = new Scheduler();

		// Health monitor — registers the cron health-check action hook.
		// Runs on every request so the cron callback is always registered.
		$this->components['health_monitor'] = new HealthMonitor();
		$this->components['health_monitor']->register_hooks();

		// Phase 3: Queue processor — hooks into cron actions to actually send messages.
		$this->components['queue_processor'] = new QueueProcessor();

		// Phase 3: Order status listener — fires the normalised tsh_wa_order_event action.
		$this->components['order_status_listener'] = new OrderStatusListener();

		// Phase 3: Order listener — hooks WC lifecycle events → OrderProcessor.
		$this->components['order_listener'] = new OrderListener();

		// Phase 5: Template manager — registers background sync hook and orchestrates all template services.
		$this->components['template_manager'] = new TemplateManager();

		// Phase 5: Template sync — ensure the scheduled and background cron hooks are registered.
		add_action( Scheduler::HOOK_SYNC_TEMPLATES,           [ new TemplateSync(), 'scheduled_sync' ] );
		add_action( Scheduler::HOOK_REFRESH_TEMPLATE_QUALITY, [ new TemplateSync(), 'manual_sync'    ] );

		// Phase 6: Inbox / Conversation hub — webhook receiver + media downloader.
		$this->components['inbox_manager'] = new InboxManager();
		$this->components['inbox_manager']->register_hooks();

		// Admin-only components — never loaded on the frontend.
		if ( is_admin() ) {
			$this->components['menu'] = new Menu();
			// AJAX handlers must be registered in admin context.
			$this->components['ajax'] = new Ajax();
			// Phase 3: Order meta box + order actions + bulk actions.
			$this->components['order_meta_box'] = new OrderMetaBox();
		}
	}

	/**
	 * Attach global WordPress / WooCommerce hooks.
	 */
	private function register_hooks(): void {
		// HPOS (High-Performance Order Storage) compatibility declaration.
		add_action( 'before_woocommerce_init', [ $this, 'declare_hpos_compatibility' ] );

		// Plugin action links on the Plugins screen.
		add_filter(
			'plugin_action_links_' . TSH_WA_BASENAME,
			[ $this, 'add_plugin_action_links' ]
		);

		// Auto-upgrade the database schema when the stored version is behind
		// the current DB_VERSION constant (e.g. after a plugin file update).
		add_action( 'plugins_loaded', [ $this, 'maybe_upgrade_db' ], 5 );
	}

	/**
	 * Run the DB installer if the stored schema version is behind the current
	 * version constant. Safe to call on every request — dbDelta is idempotent.
	 */
	public function maybe_upgrade_db(): void {
		$stored_version = get_option( 'tsh_wa_db_version', '0' );

		if ( version_compare( $stored_version, Installer::DB_VERSION, '<' ) ) {
			$installer = new Installer();
			$installer->run();
			update_option( 'tsh_wa_db_version', Installer::DB_VERSION, false );
		}
	}

	// -------------------------------------------------------------------------
	// Hooks / callbacks
	// -------------------------------------------------------------------------

	/**
	 * Declare compatibility with WooCommerce HPOS.
	 */
	public function declare_hpos_compatibility(): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				TSH_WA_BASENAME,
				true
			);
		}
	}

	/**
	 * Add Settings link to the plugin action links.
	 *
	 * @param array<int, string> $links Existing action links.
	 * @return array<int, string>
	 */
	public function add_plugin_action_links( array $links ): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=tsh-whatsapp-notify-settings' ) ),
			esc_html__( 'Settings', 'tsh-whatsapp-notify' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Display an admin notice when WooCommerce is not active.
	 */
	public function notice_missing_woocommerce(): void {
		?>
		<div class="notice notice-error is-dismissible">
			<p>
				<?php
				printf(
					/* translators: %s: plugin name */
					esc_html__( '%s requires WooCommerce to be installed and active.', 'tsh-whatsapp-notify' ),
					'<strong>TSH WhatsApp Notify</strong>'
				);
				?>
			</p>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Text domain
	// -------------------------------------------------------------------------

	/**
	 * Load plugin text domain for internationalisation.
	 */
	private function load_text_domain(): void {
		load_plugin_textdomain(
			'tsh-whatsapp-notify',
			false,
			dirname( TSH_WA_BASENAME ) . '/languages'
		);
	}

	// -------------------------------------------------------------------------
	// Component access
	// -------------------------------------------------------------------------

	/**
	 * Retrieve a registered component by key.
	 *
	 * @param string $key Component identifier.
	 * @return object|null
	 */
	public function get_component( string $key ): ?object {
		return $this->components[ $key ] ?? null;
	}

	// -------------------------------------------------------------------------
	// Prevent misuse of the singleton
	// -------------------------------------------------------------------------

	private function __clone(): void {}

	/**
	 * @throws \LogicException Always — singleton cannot be unserialized.
	 */
	public function __wakeup(): never {
		throw new \LogicException( 'TSH WhatsApp Notify Loader cannot be unserialized.' );
	}
}
