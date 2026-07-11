<?php
/**
 * Plugin Name:       TSH WhatsApp Notify
 * Plugin URI:        https://example.com/tsh-whatsapp-notify
 * Description:       Professional WhatsApp notification system for WooCommerce. Send automated order and customer WhatsApp messages via the Meta Cloud API.
 * Version:           1.0.0
 * Requires at least: 6.3
 * Requires PHP:      8.1
 * Author:            TSH
 * Author URI:        https://example.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tsh-whatsapp-notify
 * Domain Path:       /languages
 *
 * WC requires at least: 7.0
 * WC tested up to:      9.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// Plugin constants
// ---------------------------------------------------------------------------

define( 'TSH_WA_VERSION',  '1.0.0' );
define( 'TSH_WA_PATH',     plugin_dir_path( __FILE__ ) );
define( 'TSH_WA_URL',      plugin_dir_url( __FILE__ ) );
define( 'TSH_WA_BASENAME', plugin_basename( __FILE__ ) );
define( 'TSH_WA_LOG_DIR',  TSH_WA_PATH . 'logs' . DIRECTORY_SEPARATOR );

// ---------------------------------------------------------------------------
// Autoloader (Composer first; fallback PSR-4 when vendor/ is absent)
// ---------------------------------------------------------------------------

if ( file_exists( TSH_WA_PATH . 'vendor/autoload.php' ) ) {
	require_once TSH_WA_PATH . 'vendor/autoload.php';
} else {
	spl_autoload_register( static function ( string $class ): void {
		$prefix   = 'TSH\\WhatsAppNotify\\';
		$base_dir = TSH_WA_PATH . 'includes/';

		if ( str_starts_with( $class, $prefix ) ) {
			$relative = substr( $class, strlen( $prefix ) );
			$file     = $base_dir . str_replace( '\\', DIRECTORY_SEPARATOR, $relative ) . '.php';

			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}
	} );
}

// ---------------------------------------------------------------------------
// Activation / deactivation hooks
// ---------------------------------------------------------------------------

register_activation_hook(
	__FILE__,
	[ 'TSH\\WhatsAppNotify\\Bootstrap\\Activator', 'activate' ]
);

register_deactivation_hook(
	__FILE__,
	[ 'TSH\\WhatsAppNotify\\Bootstrap\\Deactivator', 'deactivate' ]
);

// ---------------------------------------------------------------------------
// Boot on plugins_loaded so all plugins (inc. WooCommerce) are available
// ---------------------------------------------------------------------------

add_action( 'plugins_loaded', static function (): void {
	TSH\WhatsAppNotify\Bootstrap\Loader::instance();
} );
