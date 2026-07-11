<?php
/**
 * Orders page template.
 *
 * @package TSH\WhatsAppNotify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap tsh-wa-wrap">

	<div class="tsh-wa-page-header">
		<div class="tsh-wa-page-header__inner">
			<h1><?php esc_html_e( 'Orders', 'tsh-whatsapp-notify' ); ?></h1>
			<p class="tsh-wa-page-header__subtitle">
				<?php esc_html_e( 'WhatsApp notification history linked to WooCommerce orders.', 'tsh-whatsapp-notify' ); ?>
			</p>
		</div>
	</div>

	<div class="tsh-wa-panel">
		<div class="tsh-wa-panel__body">
			<div class="tsh-wa-coming-soon">
				<span class="dashicons dashicons-cart" aria-hidden="true"></span>
				<h2><?php esc_html_e( 'Order Notifications', 'tsh-whatsapp-notify' ); ?></h2>
				<p>
					<?php esc_html_e( 'Per-order WhatsApp notification history and manual send controls will be available in Phase 2 (Orders module).', 'tsh-whatsapp-notify' ); ?>
				</p>
				<p class="tsh-wa-coming-soon__meta">
					<?php esc_html_e( 'The foundation is ready — notifications, triggers, and message templates are coming next.', 'tsh-whatsapp-notify' ); ?>
				</p>
			</div>
		</div>
	</div>

</div>
