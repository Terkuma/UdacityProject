<?php
/**
 * Orders admin page controller.
 *
 * @package TSH\WhatsAppNotify\Admin\Pages
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Admin\Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Orders
 *
 * Displays the Orders page: a log of WhatsApp notifications
 * tied to WooCommerce orders. Full implementation in Phase 2 (Orders module).
 */
final class Orders {

	/**
	 * Render the Orders page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'tsh-whatsapp-notify' ) );
		}

		$template = TSH_WA_PATH . 'templates/admin/orders.php';

		if ( file_exists( $template ) ) {
			include $template;
		}
	}
}
