<?php
/**
 * Inbox admin page controller.
 *
 * @package TSH\WhatsAppNotify\Admin\Pages
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Admin\Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TSH\WhatsAppNotify\Inbox\InboxManager;
use TSH\WhatsAppNotify\Inbox\WebhookReceiver;

/**
 * Class Inbox
 *
 * Controller for the Inbox admin page.
 * Builds the initial page data then delegates rendering to the template.
 */
final class Inbox {

	/**
	 * Render the Inbox admin page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'tsh-whatsapp-notify' ) );
		}

		$manager = new InboxManager();

		// Initial page data (first page of conversations, 30 per page).
		$initial_data  = $manager->get_conversations( [
			'status'      => 'open',
			'page'        => 1,
			'per_page'    => 30,
			'order_by'    => 'last_message_at',
			'order'       => 'DESC',
			'pinned_first'=> true,
		] );

		$analytics    = $manager->get_analytics();
		$webhook_url  = $manager->get_webhook_url();
		$labels       = $manager->get_labels();
		$agents       = ( new \TSH\WhatsAppNotify\Inbox\ConversationAssignment() )->get_available_agents();

		$api_settings = get_option( 'tsh_wa_api_settings', [] );
		$webhook_token = $api_settings['webhook_verify_token'] ?? '';

		$template_vars = compact(
			'initial_data',
			'analytics',
			'webhook_url',
			'labels',
			'agents',
			'webhook_token'
		);

		// Render template.
		$template_path = TSH_WA_PATH . 'templates/admin/inbox.php';
		if ( file_exists( $template_path ) ) {
			extract( $template_vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
			require $template_path;
		} else {
			echo '<div class="wrap"><h1>' . esc_html__( 'Inbox', 'tsh-whatsapp-notify' ) . '</h1>';
			echo '<p class="notice notice-error">' . esc_html__( 'Inbox template not found.', 'tsh-whatsapp-notify' ) . '</p></div>';
		}
	}
}
