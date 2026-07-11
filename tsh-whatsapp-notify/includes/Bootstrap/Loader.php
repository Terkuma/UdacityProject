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

use TSH\WhatsAppNotify\Admin\Menu;
use TSH\WhatsAppNotify\Cron\Scheduler;

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

		// Admin-only components — never loaded on the frontend.
		if ( is_admin() ) {
			$this->components['menu'] = new Menu();
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
