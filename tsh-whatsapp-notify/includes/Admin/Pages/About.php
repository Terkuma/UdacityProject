<?php
/**
 * About admin page controller.
 *
 * @package TSH\WhatsAppNotify\Admin\Pages
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Admin\Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class About
 *
 * Displays plugin version information, credits, and links.
 */
final class About {

	/**
	 * Render the About page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'tsh-whatsapp-notify' ) );
		}

		$template = TSH_WA_PATH . 'templates/admin/about.php';

		if ( file_exists( $template ) ) {
			include $template;
		}
	}
}
