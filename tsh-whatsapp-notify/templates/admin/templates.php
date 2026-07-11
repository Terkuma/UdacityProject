<?php
/**
 * Templates page template.
 *
 * @package TSH\WhatsAppNotify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;
$table = $wpdb->prefix . 'tsh_wa_templates';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$templates = $wpdb->get_results( "SELECT id, name, type, status, language, trigger_event, created_at FROM `{$table}` ORDER BY created_at DESC LIMIT 50" );
?>
<div class="wrap tsh-wa-wrap">

	<div class="tsh-wa-page-header">
		<div class="tsh-wa-page-header__inner">
			<h1><?php esc_html_e( 'Message Templates', 'tsh-whatsapp-notify' ); ?></h1>
			<p class="tsh-wa-page-header__subtitle">
				<?php esc_html_e( 'Create and manage WhatsApp message templates.', 'tsh-whatsapp-notify' ); ?>
			</p>
		</div>
		<div class="tsh-wa-page-header__actions">
			<button class="button button-primary" disabled title="<?php esc_attr_e( 'Available in Phase 2', 'tsh-whatsapp-notify' ); ?>">
				<?php esc_html_e( '+ New Template', 'tsh-whatsapp-notify' ); ?>
			</button>
		</div>
	</div>

	<div class="tsh-wa-panel">
		<div class="tsh-wa-panel__body">
			<?php if ( ! empty( $templates ) ) : ?>
				<table class="tsh-wa-table wp-list-table widefat">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Name', 'tsh-whatsapp-notify' ); ?></th>
							<th><?php esc_html_e( 'Type', 'tsh-whatsapp-notify' ); ?></th>
							<th><?php esc_html_e( 'Trigger', 'tsh-whatsapp-notify' ); ?></th>
							<th><?php esc_html_e( 'Language', 'tsh-whatsapp-notify' ); ?></th>
							<th><?php esc_html_e( 'Status', 'tsh-whatsapp-notify' ); ?></th>
							<th><?php esc_html_e( 'Created', 'tsh-whatsapp-notify' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $templates as $tmpl ) : ?>
							<tr>
								<td><?php echo esc_html( $tmpl->name ); ?></td>
								<td><?php echo esc_html( $tmpl->type ); ?></td>
								<td><?php echo esc_html( $tmpl->trigger_event ?? '–' ); ?></td>
								<td><?php echo esc_html( strtoupper( $tmpl->language ) ); ?></td>
								<td>
									<span class="tsh-wa-badge tsh-wa-badge--<?php echo 'active' === $tmpl->status ? 'green' : 'grey'; ?>">
										<?php echo esc_html( $tmpl->status ); ?>
									</span>
								</td>
								<td><?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $tmpl->created_at ) ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<div class="tsh-wa-coming-soon">
					<span class="dashicons dashicons-media-text" aria-hidden="true"></span>
					<h2><?php esc_html_e( 'No Templates Yet', 'tsh-whatsapp-notify' ); ?></h2>
					<p>
						<?php esc_html_e( 'Template creation, editing, and variable substitution will be available in Phase 2.', 'tsh-whatsapp-notify' ); ?>
					</p>
				</div>
			<?php endif; ?>
		</div>
	</div>

</div>
