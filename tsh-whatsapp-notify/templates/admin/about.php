<?php
/**
 * About page template.
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
			<h1><?php esc_html_e( 'About TSH WhatsApp Notify', 'tsh-whatsapp-notify' ); ?></h1>
		</div>
	</div>

	<div class="tsh-wa-panels">

		<div class="tsh-wa-panel tsh-wa-panel--wide">
			<div class="tsh-wa-panel__body tsh-wa-about-hero">
				<div class="tsh-wa-about-hero__logo">
					<svg width="64" height="64" viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
						<circle cx="32" cy="32" r="32" fill="#25D366"/>
						<path d="M46.5 17.5C43.2 14.1 38.7 12.2 34 12.2C24.2 12.2 16.2 20.2 16.2 30C16.2 33.4 17.1 36.7 18.9 39.6L16 48L24.6 45.1C27.4 46.7 30.6 47.6 34 47.6C43.8 47.6 51.8 39.6 51.8 29.8C51.8 25.1 49.8 20.9 46.5 17.5ZM34 44.7C30.9 44.7 27.9 43.9 25.2 42.3L24.6 42L19.7 43.6L21.3 38.8L21 38.2C19.2 35.3 18.2 32 18.2 28.5C18.2 21.3 24.1 15.4 31.3 15.4C34.8 15.4 38 16.8 40.5 19.3C43 21.8 44.4 25.1 44.4 28.6C45.6 35.9 39.7 44.7 34 44.7ZM42 35.4C41.6 35.2 39.5 34.2 39.1 34.1C38.7 33.9 38.4 33.9 38.1 34.3C37.8 34.7 37 35.7 36.7 36.1C36.4 36.4 36.1 36.5 35.7 36.3C34.6 35.8 33.3 35.1 32.3 34.2C31.4 33.3 30.6 32.3 30 31.2C29.8 30.8 30 30.5 30.1 30.2C30.4 29.9 30.7 29.6 30.9 29.3C31.1 29 31.2 28.8 31.3 28.5C31.4 28.2 31.3 27.9 31.2 27.7C31 27.4 30.2 25.3 29.9 24.5C29.6 23.7 29.3 23.9 29 23.9H28.3C28 23.9 27.5 24 27.1 24.5C26.7 24.9 25.5 26 25.5 28.1C25.5 30.2 27.1 32.3 27.3 32.6C27.5 32.9 30.1 37 34.3 38.8C35.3 39.2 36.1 39.5 36.7 39.7C37.8 40.1 38.7 40 39.5 39.9C40.5 39.7 42 38.7 42.3 37.6C42.7 36.5 42.7 35.7 42.5 35.5C42.3 35.3 42.2 35.5 42 35.4Z" fill="white"/>
					</svg>
				</div>
				<div class="tsh-wa-about-hero__content">
					<h2><?php esc_html_e( 'TSH WhatsApp Notify', 'tsh-whatsapp-notify' ); ?></h2>
					<p class="tsh-wa-about-hero__tagline">
						<?php esc_html_e( 'Professional WhatsApp notification system for WooCommerce powered by the Meta Cloud API.', 'tsh-whatsapp-notify' ); ?>
					</p>
					<ul class="tsh-wa-about-hero__meta">
						<li>
							<strong><?php esc_html_e( 'Version:', 'tsh-whatsapp-notify' ); ?></strong>
							<?php echo esc_html( TSH_WA_VERSION ); ?>
						</li>
						<li>
							<strong><?php esc_html_e( 'License:', 'tsh-whatsapp-notify' ); ?></strong>
							GPL-2.0-or-later
						</li>
						<li>
							<strong><?php esc_html_e( 'Requires PHP:', 'tsh-whatsapp-notify' ); ?></strong>
							8.1+
						</li>
						<li>
							<strong><?php esc_html_e( 'Requires WooCommerce:', 'tsh-whatsapp-notify' ); ?></strong>
							7.0+
						</li>
					</ul>
				</div>
			</div>
		</div>

		<div class="tsh-wa-panel">
			<div class="tsh-wa-panel__header">
				<h2><?php esc_html_e( 'Phase Roadmap', 'tsh-whatsapp-notify' ); ?></h2>
			</div>
			<div class="tsh-wa-panel__body">
				<ul class="tsh-wa-roadmap">
					<li class="tsh-wa-roadmap__item tsh-wa-roadmap__item--done">
						<span class="dashicons dashicons-yes-alt"></span>
						<div>
							<strong><?php esc_html_e( 'Phase 1 — Foundation', 'tsh-whatsapp-notify' ); ?></strong>
							<p><?php esc_html_e( 'Plugin architecture, admin interface, settings framework, logger, queue, and cron scheduler.', 'tsh-whatsapp-notify' ); ?></p>
						</div>
					</li>
					<li class="tsh-wa-roadmap__item">
						<span class="dashicons dashicons-clock"></span>
						<div>
							<strong><?php esc_html_e( 'Phase 2 — WhatsApp API & Order Notifications', 'tsh-whatsapp-notify' ); ?></strong>
							<p><?php esc_html_e( 'Meta Cloud API integration, webhook receiver, and automated order status notifications.', 'tsh-whatsapp-notify' ); ?></p>
						</div>
					</li>
					<li class="tsh-wa-roadmap__item">
						<span class="dashicons dashicons-clock"></span>
						<div>
							<strong><?php esc_html_e( 'Phase 3 — Templates & Personalisation', 'tsh-whatsapp-notify' ); ?></strong>
							<p><?php esc_html_e( 'Full template editor, variable substitution, and multi-language support.', 'tsh-whatsapp-notify' ); ?></p>
						</div>
					</li>
					<li class="tsh-wa-roadmap__item">
						<span class="dashicons dashicons-clock"></span>
						<div>
							<strong><?php esc_html_e( 'Phase 4 — AI & Advanced Features', 'tsh-whatsapp-notify' ); ?></strong>
							<p><?php esc_html_e( 'AI-assisted message drafting, smart send-time optimisation, and analytics.', 'tsh-whatsapp-notify' ); ?></p>
						</div>
					</li>
				</ul>
			</div>
		</div>

	</div>

</div>
