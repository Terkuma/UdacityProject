<?php
/**
 * Dashboard page template.
 *
 * Variables injected by Dashboard::load_template():
 *
 * @var string  $plugin_version
 * @var string  $db_version
 * @var bool    $db_up_to_date
 * @var string  $activation_date
 * @var bool    $woocommerce_active
 * @var string  $woocommerce_version
 * @var string  $whatsapp_status
 * @var bool    $whatsapp_test_mode
 * @var int     $queue_pending
 * @var int     $queue_processing
 * @var int     $queue_sent
 * @var int     $queue_failed
 * @var int     $queue_total
 * @var int     $messages_sent_today
 * @var int     $messages_failed_today
 * @var int     $log_error_count
 * @var int     $log_warning_count
 * @var array   $recent_logs
 * @var array   $system_health
 * @var string  $url_settings
 * @var string  $url_queue
 * @var string  $url_logs
 *
 * @package TSH\WhatsAppNotify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap tsh-wa-wrap">

	<?php /* ── Page header ──────────────────────────────────────────────── */ ?>
	<div class="tsh-wa-page-header">
		<div class="tsh-wa-page-header__inner">
			<span class="tsh-wa-logo">
				<svg width="28" height="28" viewBox="0 0 28 28" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
					<circle cx="14" cy="14" r="14" fill="#25D366"/>
					<path d="M20.5 7.5C18.8 5.8 16.5 4.8 14 4.8C8.9 4.8 4.8 8.9 4.8 14C4.8 15.7 5.3 17.3 6.1 18.7L4.7 23.3L9.4 21.9C10.8 22.7 12.3 23.1 14 23.1C19.1 23.1 23.2 19 23.2 13.9C23.2 11.5 22.2 9.2 20.5 7.5ZM14 21.5C12.5 21.5 11 21.1 9.8 20.3L9.5 20.1L6.7 20.9L7.5 18.2L7.3 17.9C6.4 16.6 5.9 15.1 5.9 13.5C5.9 9.4 9.3 6 13.4 6C15.4 6 17.3 6.8 18.7 8.2C20.1 9.6 20.9 11.4 20.9 13.5C21.5 17.6 18.1 21.5 14 21.5ZM18.1 15.8C17.9 15.7 16.8 15.2 16.6 15.1C16.4 15 16.3 15 16.1 15.2C16 15.4 15.5 15.9 15.4 16C15.3 16.2 15.1 16.2 14.9 16.1C14.3 15.8 13.7 15.4 13.2 14.9C12.8 14.5 12.4 14 12.1 13.5C12 13.3 12.1 13.1 12.2 13C12.3 12.9 12.5 12.7 12.6 12.6C12.7 12.5 12.7 12.3 12.8 12.2C12.9 12.1 12.8 11.9 12.8 11.8C12.7 11.7 12.3 10.6 12.1 10.2C12 9.8 11.8 9.9 11.7 9.9H11.3C11.1 9.9 10.9 10 10.7 10.2C10.5 10.4 9.9 11 9.9 12.1C9.9 13.2 10.7 14.3 10.8 14.4C10.9 14.5 12.3 16.7 14.4 17.6C14.9 17.8 15.3 17.9 15.7 18C16.2 18.2 16.6 18.1 17 18.1C17.4 18 18.2 17.6 18.4 17.1C18.6 16.7 18.6 16.3 18.5 16.2C18.4 16.1 18.3 16 18.1 15.8Z" fill="white"/>
				</svg>
			</span>
			<div class="tsh-wa-page-header__text">
				<h1><?php esc_html_e( 'TSH WhatsApp Notify', 'tsh-whatsapp-notify' ); ?></h1>
				<p class="tsh-wa-page-header__subtitle">
					<?php
					printf(
						/* translators: %s: plugin version */
						esc_html__( 'Version %s', 'tsh-whatsapp-notify' ),
						esc_html( $plugin_version )
					);
					?>
				</p>
			</div>
		</div>
		<div class="tsh-wa-page-header__actions">
			<a href="<?php echo esc_url( $url_settings ); ?>" class="button button-primary">
				<?php esc_html_e( 'Settings', 'tsh-whatsapp-notify' ); ?>
			</a>
		</div>
	</div>

	<?php /* ── Summary cards ─────────────────────────────────────────────── */ ?>
	<div class="tsh-wa-cards">

		<?php /* Plugin version */ ?>
		<div class="tsh-wa-card">
			<div class="tsh-wa-card__icon tsh-wa-card__icon--blue">
				<span class="dashicons dashicons-plugins-checked"></span>
			</div>
			<div class="tsh-wa-card__body">
				<h3 class="tsh-wa-card__title"><?php esc_html_e( 'Plugin Version', 'tsh-whatsapp-notify' ); ?></h3>
				<p class="tsh-wa-card__value"><?php echo esc_html( $plugin_version ); ?></p>
				<p class="tsh-wa-card__meta">
					<?php
					printf(
						/* translators: %s: database schema version */
						esc_html__( 'DB Schema: %s', 'tsh-whatsapp-notify' ),
						esc_html( $db_version )
					);
					?>
					<?php if ( $db_up_to_date ) : ?>
						<span class="tsh-wa-badge tsh-wa-badge--green"><?php esc_html_e( 'Up to date', 'tsh-whatsapp-notify' ); ?></span>
					<?php else : ?>
						<span class="tsh-wa-badge tsh-wa-badge--red"><?php esc_html_e( 'Upgrade needed', 'tsh-whatsapp-notify' ); ?></span>
					<?php endif; ?>
				</p>
			</div>
		</div>

		<?php /* WooCommerce status */ ?>
		<div class="tsh-wa-card">
			<div class="tsh-wa-card__icon <?php echo $woocommerce_active ? 'tsh-wa-card__icon--green' : 'tsh-wa-card__icon--red'; ?>">
				<span class="dashicons dashicons-cart"></span>
			</div>
			<div class="tsh-wa-card__body">
				<h3 class="tsh-wa-card__title"><?php esc_html_e( 'WooCommerce', 'tsh-whatsapp-notify' ); ?></h3>
				<p class="tsh-wa-card__value">
					<?php echo $woocommerce_active ? esc_html__( 'Active', 'tsh-whatsapp-notify' ) : esc_html__( 'Inactive', 'tsh-whatsapp-notify' ); ?>
				</p>
				<p class="tsh-wa-card__meta">
					<?php
					if ( $woocommerce_active ) {
						printf(
							/* translators: %s: WooCommerce version */
							esc_html__( 'v%s', 'tsh-whatsapp-notify' ),
							esc_html( $woocommerce_version )
						);
					}
					?>
				</p>
			</div>
		</div>

		<?php /* WhatsApp API status */ ?>
		<div class="tsh-wa-card">
			<div class="tsh-wa-card__icon <?php echo 'connected' === $whatsapp_status ? 'tsh-wa-card__icon--green' : 'tsh-wa-card__icon--orange'; ?>">
				<span class="dashicons dashicons-share-alt2"></span>
			</div>
			<div class="tsh-wa-card__body">
				<h3 class="tsh-wa-card__title"><?php esc_html_e( 'WhatsApp API', 'tsh-whatsapp-notify' ); ?></h3>
				<p class="tsh-wa-card__value">
					<?php echo 'connected' === $whatsapp_status ? esc_html__( 'Connected', 'tsh-whatsapp-notify' ) : esc_html__( 'Not Configured', 'tsh-whatsapp-notify' ); ?>
				</p>
				<p class="tsh-wa-card__meta">
					<?php if ( $whatsapp_test_mode ) : ?>
						<span class="tsh-wa-badge tsh-wa-badge--orange"><?php esc_html_e( 'Test Mode', 'tsh-whatsapp-notify' ); ?></span>
					<?php elseif ( 'connected' !== $whatsapp_status ) : ?>
						<a href="<?php echo esc_url( $url_settings . '&tab=api' ); ?>">
							<?php esc_html_e( 'Configure now →', 'tsh-whatsapp-notify' ); ?>
						</a>
					<?php endif; ?>
				</p>
			</div>
		</div>

		<?php /* Queue status */ ?>
		<div class="tsh-wa-card">
			<div class="tsh-wa-card__icon tsh-wa-card__icon--purple">
				<span class="dashicons dashicons-list-view"></span>
			</div>
			<div class="tsh-wa-card__body">
				<h3 class="tsh-wa-card__title"><?php esc_html_e( 'Queue Status', 'tsh-whatsapp-notify' ); ?></h3>
				<p class="tsh-wa-card__value"><?php echo esc_html( number_format_i18n( $queue_total ) ); ?></p>
				<p class="tsh-wa-card__meta">
					<span class="tsh-wa-badge tsh-wa-badge--blue"><?php echo esc_html( $queue_pending ); ?> <?php esc_html_e( 'pending', 'tsh-whatsapp-notify' ); ?></span>
					<?php if ( $queue_failed > 0 ) : ?>
						<span class="tsh-wa-badge tsh-wa-badge--red"><?php echo esc_html( $queue_failed ); ?> <?php esc_html_e( 'failed', 'tsh-whatsapp-notify' ); ?></span>
					<?php endif; ?>
				</p>
			</div>
		</div>

		<?php /* Messages sent today */ ?>
		<div class="tsh-wa-card">
			<div class="tsh-wa-card__icon tsh-wa-card__icon--green">
				<span class="dashicons dashicons-yes-alt"></span>
			</div>
			<div class="tsh-wa-card__body">
				<h3 class="tsh-wa-card__title"><?php esc_html_e( 'Sent Today', 'tsh-whatsapp-notify' ); ?></h3>
				<p class="tsh-wa-card__value"><?php echo esc_html( number_format_i18n( $messages_sent_today ) ); ?></p>
				<p class="tsh-wa-card__meta"><?php esc_html_e( 'Successful messages', 'tsh-whatsapp-notify' ); ?></p>
			</div>
		</div>

		<?php /* Messages failed today */ ?>
		<div class="tsh-wa-card">
			<div class="tsh-wa-card__icon <?php echo $messages_failed_today > 0 ? 'tsh-wa-card__icon--red' : 'tsh-wa-card__icon--grey'; ?>">
				<span class="dashicons dashicons-dismiss"></span>
			</div>
			<div class="tsh-wa-card__body">
				<h3 class="tsh-wa-card__title"><?php esc_html_e( 'Failed Today', 'tsh-whatsapp-notify' ); ?></h3>
				<p class="tsh-wa-card__value"><?php echo esc_html( number_format_i18n( $messages_failed_today ) ); ?></p>
				<p class="tsh-wa-card__meta">
					<?php if ( $messages_failed_today > 0 ) : ?>
						<a href="<?php echo esc_url( $url_logs . '&level=error' ); ?>">
							<?php esc_html_e( 'View errors →', 'tsh-whatsapp-notify' ); ?>
						</a>
					<?php else : ?>
						<?php esc_html_e( 'No failures', 'tsh-whatsapp-notify' ); ?>
					<?php endif; ?>
				</p>
			</div>
		</div>

	</div><!-- .tsh-wa-cards -->

	<?php /* ── Lower panels ──────────────────────────────────────────────── */ ?>
	<div class="tsh-wa-panels">

		<?php /* Recent logs */ ?>
		<div class="tsh-wa-panel tsh-wa-panel--wide">
			<div class="tsh-wa-panel__header">
				<h2><?php esc_html_e( 'Recent Activity', 'tsh-whatsapp-notify' ); ?></h2>
				<a href="<?php echo esc_url( $url_logs ); ?>" class="tsh-wa-panel__link">
					<?php esc_html_e( 'View all logs', 'tsh-whatsapp-notify' ); ?>
				</a>
			</div>
			<div class="tsh-wa-panel__body">
				<?php if ( ! empty( $recent_logs ) ) : ?>
					<table class="tsh-wa-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Level', 'tsh-whatsapp-notify' ); ?></th>
								<th><?php esc_html_e( 'Source', 'tsh-whatsapp-notify' ); ?></th>
								<th><?php esc_html_e( 'Message', 'tsh-whatsapp-notify' ); ?></th>
								<th><?php esc_html_e( 'Time', 'tsh-whatsapp-notify' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $recent_logs as $log ) : ?>
								<tr>
									<td>
										<span class="tsh-wa-level-badge tsh-wa-level-badge--<?php echo esc_attr( $log->level ); ?>">
											<?php echo esc_html( strtoupper( $log->level ) ); ?>
										</span>
									</td>
									<td><code><?php echo esc_html( $log->source ); ?></code></td>
									<td><?php echo esc_html( wp_trim_words( $log->message, 12 ) ); ?></td>
									<td>
										<time datetime="<?php echo esc_attr( $log->created_at ); ?>">
											<?php echo esc_html( human_time_diff( strtotime( $log->created_at ), current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'tsh-whatsapp-notify' ) ); ?>
										</time>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else : ?>
					<p class="tsh-wa-empty">
						<span class="dashicons dashicons-info-outline"></span>
						<?php esc_html_e( 'No log entries yet.', 'tsh-whatsapp-notify' ); ?>
					</p>
				<?php endif; ?>
			</div>
		</div>

		<?php /* System health */ ?>
		<div class="tsh-wa-panel">
			<div class="tsh-wa-panel__header">
				<h2><?php esc_html_e( 'System Health', 'tsh-whatsapp-notify' ); ?></h2>
			</div>
			<div class="tsh-wa-panel__body">
				<ul class="tsh-wa-health-list">
					<?php foreach ( $system_health as $check ) : ?>
						<li class="tsh-wa-health-list__item tsh-wa-health-list__item--<?php echo esc_attr( $check['status'] ); ?>">
							<span class="tsh-wa-health-list__icon">
								<?php if ( 'ok' === $check['status'] ) : ?>
									<span class="dashicons dashicons-yes-alt"></span>
								<?php elseif ( 'warning' === $check['status'] ) : ?>
									<span class="dashicons dashicons-warning"></span>
								<?php else : ?>
									<span class="dashicons dashicons-dismiss"></span>
								<?php endif; ?>
							</span>
							<span class="tsh-wa-health-list__label"><?php echo esc_html( $check['label'] ); ?></span>
							<span class="tsh-wa-health-list__value"><?php echo esc_html( $check['value'] ); ?></span>
							<?php if ( ! empty( $check['note'] ) ) : ?>
								<span class="tsh-wa-health-list__note"><?php echo esc_html( $check['note'] ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>

	</div><!-- .tsh-wa-panels -->

</div><!-- .tsh-wa-wrap -->
