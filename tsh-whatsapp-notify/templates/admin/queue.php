<?php
/**
 * Queue page template.
 *
 * Variables injected by Pages\Queue::render():
 *
 * @var array  $queue_items
 * @var int    $total_items
 * @var array  $queue_counts
 * @var int    $current_page
 * @var string $active_status
 *
 * @package TSH\WhatsAppNotify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TSH\WhatsAppNotify\Queue\Queue;

$per_page    = 20;
$total_pages = $total_items > 0 ? (int) ceil( $total_items / $per_page ) : 1;
?>
<div class="wrap tsh-wa-wrap">

	<div class="tsh-wa-page-header">
		<div class="tsh-wa-page-header__inner">
			<h1><?php esc_html_e( 'Message Queue', 'tsh-whatsapp-notify' ); ?></h1>
			<p class="tsh-wa-page-header__subtitle">
				<?php esc_html_e( 'View and manage the outbound WhatsApp message queue.', 'tsh-whatsapp-notify' ); ?>
			</p>
		</div>
	</div>

	<?php if ( isset( $_GET['updated'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Queue updated.', 'tsh-whatsapp-notify' ); ?></p></div>
	<?php endif; ?>

	<?php /* Status filter tabs */ ?>
	<ul class="tsh-wa-status-tabs">
		<li>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=tsh-whatsapp-notify-queue' ) ); ?>"
			   class="<?php echo '' === $active_status ? 'current' : ''; ?>">
				<?php esc_html_e( 'All', 'tsh-whatsapp-notify' ); ?>
				<span class="count">(<?php echo esc_html( number_format_i18n( array_sum( $queue_counts ) ) ); ?>)</span>
			</a>
		</li>
		<?php foreach ( Queue::ALL_STATUSES as $status ) : ?>
			<li>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=tsh-whatsapp-notify-queue&status=' . $status ) ); ?>"
				   class="<?php echo $active_status === $status ? 'current' : ''; ?>">
					<?php echo esc_html( ucfirst( $status ) ); ?>
					<span class="count">(<?php echo esc_html( number_format_i18n( $queue_counts[ $status ] ?? 0 ) ); ?>)</span>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>

	<?php /* Bulk actions form */ ?>
	<form method="post" action="" class="tsh-wa-bulk-form">
		<?php wp_nonce_field( 'tsh_wa_queue_action', 'tsh_wa_queue_nonce' ); ?>

		<div class="tsh-wa-bulk-actions">
			<button type="submit" name="tsh_wa_action" value="clear_failed"
				class="button"
				onclick="return confirm('<?php esc_attr_e( 'Delete all failed items?', 'tsh-whatsapp-notify' ); ?>')">
				<?php esc_html_e( 'Clear Failed', 'tsh-whatsapp-notify' ); ?>
			</button>
			<button type="submit" name="tsh_wa_action" value="clear_all"
				class="button"
				onclick="return confirm('<?php esc_attr_e( 'Clear the entire queue? This cannot be undone.', 'tsh-whatsapp-notify' ); ?>')">
				<?php esc_html_e( 'Clear All', 'tsh-whatsapp-notify' ); ?>
			</button>
		</div>
	</form>

	<div class="tsh-wa-panel">
		<div class="tsh-wa-panel__body">
			<?php if ( ! empty( $queue_items ) ) : ?>
				<table class="tsh-wa-table wp-list-table widefat">
					<thead>
						<tr>
							<th><?php esc_html_e( 'ID', 'tsh-whatsapp-notify' ); ?></th>
							<th><?php esc_html_e( 'Phone', 'tsh-whatsapp-notify' ); ?></th>
							<th><?php esc_html_e( 'Message', 'tsh-whatsapp-notify' ); ?></th>
							<th><?php esc_html_e( 'Status', 'tsh-whatsapp-notify' ); ?></th>
							<th><?php esc_html_e( 'Attempts', 'tsh-whatsapp-notify' ); ?></th>
							<th><?php esc_html_e( 'Scheduled', 'tsh-whatsapp-notify' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'tsh-whatsapp-notify' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $queue_items as $item ) : ?>
							<tr>
								<td><?php echo esc_html( $item->id ); ?></td>
								<td><?php echo esc_html( \TSH\WhatsAppNotify\Helpers\Helpers::mask_phone( $item->phone ) ); ?></td>
								<td><?php echo esc_html( wp_trim_words( $item->message, 10 ) ); ?></td>
								<td>
									<span class="tsh-wa-badge tsh-wa-badge--<?php
										echo match ( $item->status ) {
											'sent'       => 'green',
											'failed'     => 'red',
											'processing' => 'orange',
											'cancelled'  => 'grey',
											default      => 'blue',
										};
									?>">
										<?php echo esc_html( $item->status ); ?>
									</span>
								</td>
								<td><?php echo esc_html( $item->attempts . ' / ' . $item->max_attempts ); ?></td>
								<td>
									<time datetime="<?php echo esc_attr( $item->scheduled_at ); ?>">
										<?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $item->scheduled_at ) ) ); ?>
									</time>
								</td>
								<td>
									<?php if ( in_array( $item->status, [ Queue::STATUS_FAILED, Queue::STATUS_CANCELLED ], true ) ) : ?>
										<form method="post" style="display:inline">
											<?php wp_nonce_field( 'tsh_wa_queue_action', 'tsh_wa_queue_nonce' ); ?>
											<input type="hidden" name="item_id" value="<?php echo esc_attr( $item->id ); ?>">
											<button type="submit" name="tsh_wa_action" value="retry" class="button button-small">
												<?php esc_html_e( 'Retry', 'tsh-whatsapp-notify' ); ?>
											</button>
										</form>
									<?php endif; ?>
									<form method="post" style="display:inline">
										<?php wp_nonce_field( 'tsh_wa_queue_action', 'tsh_wa_queue_nonce' ); ?>
										<input type="hidden" name="item_id" value="<?php echo esc_attr( $item->id ); ?>">
										<button type="submit" name="tsh_wa_action" value="remove" class="button button-small"
											onclick="return confirm('<?php esc_attr_e( 'Remove this item?', 'tsh-whatsapp-notify' ); ?>')">
											<?php esc_html_e( 'Remove', 'tsh-whatsapp-notify' ); ?>
										</button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( $total_pages > 1 ) : ?>
					<div class="tsh-wa-pagination">
						<?php
						echo paginate_links( [
							'base'      => add_query_arg( 'paged', '%#%' ),
							'format'    => '',
							'prev_text' => '&laquo;',
							'next_text' => '&raquo;',
							'total'     => $total_pages,
							'current'   => $current_page,
						] );
						?>
					</div>
				<?php endif; ?>

			<?php else : ?>
				<p class="tsh-wa-empty">
					<span class="dashicons dashicons-list-view"></span>
					<?php esc_html_e( 'No queue items found.', 'tsh-whatsapp-notify' ); ?>
				</p>
			<?php endif; ?>
		</div>
	</div>

</div>
