<?php
/**
 * Queue admin page template — Phase 4.
 *
 * Variables injected by Pages\Queue::render():
 *
 * @var array  $queue_items    Current page queue rows.
 * @var int    $total_items    Total matching rows.
 * @var array  $queue_stats    From QueueStats::get_summary().
 * @var array  $queue_health   From QueueStats::get_health().
 * @var int    $dlq_count      Dead letter item count.
 * @var array  $dlq_items      First 10 dead letter items.
 * @var int    $current_page   Current pagination page.
 * @var string $active_status  Active status filter.
 * @var int    $per_page       Items per page.
 *
 * @package TSH\WhatsAppNotify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TSH\WhatsAppNotify\Helpers\Helpers;
use TSH\WhatsAppNotify\Queue\DeliveryTracker;
use TSH\WhatsAppNotify\Queue\Queue;

$total_pages    = $total_items > 0 ? (int) ceil( $total_items / $per_page ) : 1;
$queue_counts   = $queue_stats['counts'] ?? [];
$is_paused      = (bool) ( $queue_stats['is_paused'] ?? false );
$queue_enabled  = (bool) ( $queue_stats['queue_enabled'] ?? true );
$nonce_url      = admin_url( 'admin.php?page=tsh-whatsapp-notify-queue' );

/**
 * Helper: render a health badge.
 *
 * @param array $check
 */
function tsh_wa_health_badge( array $check ): void {
	$icon = match ( $check['status'] ?? 'ok' ) {
		'ok'      => 'yes-alt',
		'warning' => 'warning',
		'error'   => 'dismiss',
		default   => 'info',
	};
	printf(
		'<span class="tsh-wa-health-badge tsh-wa-health-badge--%1$s"><span class="dashicons dashicons-%2$s"></span> %3$s</span>',
		esc_attr( $check['status'] ?? 'ok' ),
		esc_attr( $icon ),
		esc_html( $check['message'] ?? '' )
	);
}
?>
<div class="wrap tsh-wa-wrap">

	<div class="tsh-wa-page-header">
		<div class="tsh-wa-page-header__inner">
			<h1>
				<?php esc_html_e( 'Message Queue', 'tsh-whatsapp-notify' ); ?>
				<?php if ( $is_paused ) : ?>
					<span class="tsh-wa-badge tsh-wa-badge--orange tsh-wa-badge--large"><?php esc_html_e( 'Paused', 'tsh-whatsapp-notify' ); ?></span>
				<?php elseif ( ! $queue_enabled ) : ?>
					<span class="tsh-wa-badge tsh-wa-badge--grey tsh-wa-badge--large"><?php esc_html_e( 'Disabled', 'tsh-whatsapp-notify' ); ?></span>
				<?php endif; ?>
			</h1>
			<p class="tsh-wa-page-header__subtitle">
				<?php esc_html_e( 'Delivery engine — monitor throughput, manage retries, and inspect failed messages.', 'tsh-whatsapp-notify' ); ?>
			</p>
		</div>
		<div class="tsh-wa-page-header__actions" id="tsh-wa-queue-live-controls">
			<form method="post" style="display:inline">
				<?php wp_nonce_field( 'tsh_wa_queue_action', 'tsh_wa_queue_nonce' ); ?>
				<?php if ( $is_paused ) : ?>
					<button type="submit" name="tsh_wa_action" value="resume_queue" class="button button-primary">
						<span class="dashicons dashicons-controls-play"></span>
						<?php esc_html_e( 'Resume Queue', 'tsh-whatsapp-notify' ); ?>
					</button>
				<?php else : ?>
					<button type="submit" name="tsh_wa_action" value="pause_queue" class="button">
						<span class="dashicons dashicons-controls-pause"></span>
						<?php esc_html_e( 'Pause Queue', 'tsh-whatsapp-notify' ); ?>
					</button>
				<?php endif; ?>
			</form>
			<form method="post" style="display:inline">
				<?php wp_nonce_field( 'tsh_wa_queue_action', 'tsh_wa_queue_nonce' ); ?>
				<button type="submit" name="tsh_wa_action" value="process_now" class="button"
					title="<?php esc_attr_e( 'Run one batch right now without waiting for the next cron tick', 'tsh-whatsapp-notify' ); ?>">
					<span class="dashicons dashicons-update"></span>
					<?php esc_html_e( 'Process Now', 'tsh-whatsapp-notify' ); ?>
				</button>
			</form>
		</div>
	</div>

	<?php if ( isset( $_GET['updated'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Queue updated.', 'tsh-whatsapp-notify' ); ?></p></div>
	<?php endif; ?>

	<?php /* ============================================================
		   STATS CARDS
	   ============================================================ */ ?>
	<div class="tsh-wa-stats-grid tsh-wa-stats-grid--6" id="tsh-wa-queue-stats">

		<div class="tsh-wa-stat-card tsh-wa-stat-card--blue">
			<div class="tsh-wa-stat-card__value"><?php echo esc_html( number_format_i18n( $queue_counts[ Queue::STATUS_PENDING ] ?? 0 ) ); ?></div>
			<div class="tsh-wa-stat-card__label"><?php esc_html_e( 'Pending', 'tsh-whatsapp-notify' ); ?></div>
		</div>

		<div class="tsh-wa-stat-card tsh-wa-stat-card--orange">
			<div class="tsh-wa-stat-card__value"><?php echo esc_html( number_format_i18n( $queue_counts[ Queue::STATUS_PROCESSING ] ?? 0 ) ); ?></div>
			<div class="tsh-wa-stat-card__label"><?php esc_html_e( 'Processing', 'tsh-whatsapp-notify' ); ?></div>
		</div>

		<div class="tsh-wa-stat-card tsh-wa-stat-card--yellow">
			<div class="tsh-wa-stat-card__value"><?php echo esc_html( number_format_i18n( $queue_stats['retrying'] ?? 0 ) ); ?></div>
			<div class="tsh-wa-stat-card__label"><?php esc_html_e( 'Retrying', 'tsh-whatsapp-notify' ); ?></div>
		</div>

		<div class="tsh-wa-stat-card tsh-wa-stat-card--green">
			<div class="tsh-wa-stat-card__value"><?php echo esc_html( number_format_i18n( $queue_stats['sent_today'] ?? 0 ) ); ?></div>
			<div class="tsh-wa-stat-card__label"><?php esc_html_e( 'Sent Today', 'tsh-whatsapp-notify' ); ?></div>
		</div>

		<div class="tsh-wa-stat-card tsh-wa-stat-card--red">
			<div class="tsh-wa-stat-card__value"><?php echo esc_html( number_format_i18n( $queue_stats['dead_letter'] ?? 0 ) ); ?></div>
			<div class="tsh-wa-stat-card__label"><?php esc_html_e( 'Dead Letter', 'tsh-whatsapp-notify' ); ?></div>
		</div>

		<div class="tsh-wa-stat-card tsh-wa-stat-card--grey">
			<div class="tsh-wa-stat-card__value"><?php echo esc_html( number_format_i18n( $queue_stats['throughput_hour'] ?? 0 ) ); ?></div>
			<div class="tsh-wa-stat-card__label"><?php esc_html_e( 'Sent (Last Hour)', 'tsh-whatsapp-notify' ); ?></div>
		</div>

	</div>

	<?php /* ============================================================
		   PERFORMANCE METRICS + HEALTH
	   ============================================================ */ ?>
	<div class="tsh-wa-two-col">

		<div class="tsh-wa-panel">
			<div class="tsh-wa-panel__header">
				<h3><?php esc_html_e( 'Performance Metrics', 'tsh-whatsapp-notify' ); ?></h3>
				<span class="tsh-wa-refresh-hint"><?php esc_html_e( 'Refreshes every 30 s', 'tsh-whatsapp-notify' ); ?></span>
			</div>
			<div class="tsh-wa-panel__body">
				<table class="tsh-wa-kv-table">
					<tr>
						<th><?php esc_html_e( 'Avg API Latency', 'tsh-whatsapp-notify' ); ?></th>
						<td id="tsh-wa-metric-latency">
							<?php
							$avg_lat = $queue_stats['avg_latency_ms'] ?? 0.0;
							echo esc_html( $avg_lat > 0 ? number_format_i18n( (float) $avg_lat, 0 ) . ' ms' : '–' );
							?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Avg Processing Time', 'tsh-whatsapp-notify' ); ?></th>
						<td id="tsh-wa-metric-process">
							<?php
							$avg_proc = $queue_stats['avg_process_ms'] ?? 0.0;
							echo esc_html( $avg_proc > 0 ? number_format_i18n( (float) $avg_proc, 0 ) . ' ms' : '–' );
							?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Failed Today', 'tsh-whatsapp-notify' ); ?></th>
						<td><?php echo esc_html( number_format_i18n( $queue_stats['failed_today'] ?? 0 ) ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Last Processed', 'tsh-whatsapp-notify' ); ?></th>
						<td>
							<?php
							$last = $queue_stats['last_processed'] ?? null;
							echo esc_html(
								$last
									? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $last ) )
									: '–'
							);
							?>
						</td>
					</tr>
				</table>
			</div>
		</div>

		<div class="tsh-wa-panel">
			<div class="tsh-wa-panel__header">
				<h3><?php esc_html_e( 'Health Monitor', 'tsh-whatsapp-notify' ); ?></h3>
			</div>
			<div class="tsh-wa-panel__body tsh-wa-health-grid">
				<?php foreach ( $queue_health as $key => $check ) : ?>
					<div class="tsh-wa-health-item">
						<strong><?php echo esc_html( $check['label'] ?? $key ); ?></strong>
						<?php tsh_wa_health_badge( $check ); ?>
					</div>
				<?php endforeach; ?>
			</div>
		</div>

	</div>

	<?php /* ============================================================
		   MANUAL CONTROLS
	   ============================================================ */ ?>
	<div class="tsh-wa-panel tsh-wa-panel--controls">
		<div class="tsh-wa-panel__header">
			<h3><?php esc_html_e( 'Queue Controls', 'tsh-whatsapp-notify' ); ?></h3>
		</div>
		<div class="tsh-wa-panel__body tsh-wa-controls-row">

			<form method="post" style="display:inline-block">
				<?php wp_nonce_field( 'tsh_wa_queue_action', 'tsh_wa_queue_nonce' ); ?>
				<button type="submit" name="tsh_wa_action" value="retry_all_failed"
					class="button"
					<?php echo ( ( $queue_stats['dead_letter'] ?? 0 ) === 0 ) ? 'disabled' : ''; ?>>
					<span class="dashicons dashicons-redo"></span>
					<?php esc_html_e( 'Retry All Failed', 'tsh-whatsapp-notify' ); ?>
					<?php if ( ( $queue_stats['dead_letter'] ?? 0 ) > 0 ) : ?>
						<span class="tsh-wa-count-badge"><?php echo esc_html( $queue_stats['dead_letter'] ); ?></span>
					<?php endif; ?>
				</button>
			</form>

			<form method="post" style="display:inline-block">
				<?php wp_nonce_field( 'tsh_wa_queue_action', 'tsh_wa_queue_nonce' ); ?>
				<button type="submit" name="tsh_wa_action" value="clear_failed"
					class="button"
					<?php echo ( ( $queue_counts[ Queue::STATUS_FAILED ] ?? 0 ) === 0 ) ? 'disabled' : ''; ?>
					onclick="return confirm('<?php esc_attr_e( 'Delete all failed items? This cannot be undone.', 'tsh-whatsapp-notify' ); ?>')">
					<span class="dashicons dashicons-trash"></span>
					<?php esc_html_e( 'Clear Failed Queue', 'tsh-whatsapp-notify' ); ?>
				</button>
			</form>

			<form method="post" style="display:inline-block">
				<?php wp_nonce_field( 'tsh_wa_queue_action', 'tsh_wa_queue_nonce' ); ?>
				<button type="submit" name="tsh_wa_action" value="clear_dead_letter"
					class="button button-link-delete"
					<?php echo ( ( $queue_stats['dead_letter'] ?? 0 ) === 0 ) ? 'disabled' : ''; ?>
					onclick="return confirm('<?php esc_attr_e( 'Permanently delete all dead letter items?', 'tsh-whatsapp-notify' ); ?>')">
					<span class="dashicons dashicons-trash"></span>
					<?php esc_html_e( 'Clear Dead Letter', 'tsh-whatsapp-notify' ); ?>
				</button>
			</form>

			<form method="post" style="display:inline-block">
				<?php wp_nonce_field( 'tsh_wa_queue_action', 'tsh_wa_queue_nonce' ); ?>
				<button type="submit" name="tsh_wa_action" value="clear_all"
					class="button button-link-delete"
					onclick="return confirm('<?php esc_attr_e( 'Clear the entire queue? This cannot be undone.', 'tsh-whatsapp-notify' ); ?>')">
					<span class="dashicons dashicons-dismiss"></span>
					<?php esc_html_e( 'Clear All', 'tsh-whatsapp-notify' ); ?>
				</button>
			</form>

			<form method="post" style="display:inline-block">
				<?php wp_nonce_field( 'tsh_wa_queue_action', 'tsh_wa_queue_nonce' ); ?>
				<button type="submit" name="tsh_wa_action" value="export_dlq"
					class="button"
					<?php echo ( ( $queue_stats['dead_letter'] ?? 0 ) === 0 ) ? 'disabled' : ''; ?>>
					<span class="dashicons dashicons-download"></span>
					<?php esc_html_e( 'Export DLQ (JSON)', 'tsh-whatsapp-notify' ); ?>
				</button>
			</form>

		</div>
	</div>

	<?php /* ============================================================
		   DEAD LETTER QUEUE SECTION (only shown when DLQ has items)
	   ============================================================ */ ?>
	<?php if ( $dlq_count > 0 ) : ?>
	<div class="tsh-wa-panel tsh-wa-panel--dlq">
		<div class="tsh-wa-panel__header">
			<h3>
				<span class="dashicons dashicons-warning" style="color:#c0392b"></span>
				<?php esc_html_e( 'Dead Letter Queue', 'tsh-whatsapp-notify' ); ?>
				<span class="tsh-wa-badge tsh-wa-badge--red"><?php echo esc_html( number_format_i18n( $dlq_count ) ); ?></span>
			</h3>
			<p class="tsh-wa-panel__desc">
				<?php esc_html_e( 'These messages exhausted all retry attempts and will never be sent automatically. Retry them manually or delete.', 'tsh-whatsapp-notify' ); ?>
			</p>
		</div>
		<div class="tsh-wa-panel__body">
			<table class="tsh-wa-table wp-list-table widefat">
				<thead>
					<tr>
						<th><?php esc_html_e( 'ID', 'tsh-whatsapp-notify' ); ?></th>
						<th><?php esc_html_e( 'Phone', 'tsh-whatsapp-notify' ); ?></th>
						<th><?php esc_html_e( 'Message Preview', 'tsh-whatsapp-notify' ); ?></th>
						<th><?php esc_html_e( 'Order', 'tsh-whatsapp-notify' ); ?></th>
						<th><?php esc_html_e( 'Attempts', 'tsh-whatsapp-notify' ); ?></th>
						<th><?php esc_html_e( 'Error', 'tsh-whatsapp-notify' ); ?></th>
						<th><?php esc_html_e( 'Failed At', 'tsh-whatsapp-notify' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'tsh-whatsapp-notify' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $dlq_items as $dlq_item ) : ?>
						<tr>
							<td><?php echo esc_html( $dlq_item->id ); ?></td>
							<td><?php echo esc_html( Helpers::mask_phone( $dlq_item->phone ) ); ?></td>
							<td>
								<span class="tsh-wa-text-truncate" title="<?php echo esc_attr( $dlq_item->message ); ?>">
									<?php echo esc_html( wp_trim_words( $dlq_item->message, 12 ) ); ?>
								</span>
							</td>
							<td>
								<?php if ( $dlq_item->order_id ) : ?>
									<a href="<?php echo esc_url( get_edit_post_link( $dlq_item->order_id ) ?: admin_url( 'post.php?post=' . $dlq_item->order_id . '&action=edit' ) ); ?>">
										#<?php echo esc_html( $dlq_item->order_id ); ?>
									</a>
								<?php else : ?>
									–
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $dlq_item->attempts . '/' . $dlq_item->max_attempts ); ?></td>
							<td class="tsh-wa-error-cell">
								<?php if ( $dlq_item->error_message ) : ?>
									<span class="tsh-wa-error-excerpt" title="<?php echo esc_attr( $dlq_item->error_message ); ?>">
										<?php echo esc_html( mb_strimwidth( $dlq_item->error_message, 0, 80, '…' ) ); ?>
									</span>
								<?php else : ?>
									–
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $dlq_item->processed_at ) : ?>
									<time datetime="<?php echo esc_attr( $dlq_item->processed_at ); ?>">
										<?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $dlq_item->processed_at ) ) ); ?>
									</time>
								<?php else : ?>
									–
								<?php endif; ?>
							</td>
							<td>
								<form method="post" style="display:inline">
									<?php wp_nonce_field( 'tsh_wa_queue_action', 'tsh_wa_queue_nonce' ); ?>
									<input type="hidden" name="item_id" value="<?php echo esc_attr( $dlq_item->id ); ?>">
									<button type="submit" name="tsh_wa_action" value="dlq_retry" class="button button-small">
										<?php esc_html_e( 'Retry', 'tsh-whatsapp-notify' ); ?>
									</button>
								</form>
								<form method="post" style="display:inline">
									<?php wp_nonce_field( 'tsh_wa_queue_action', 'tsh_wa_queue_nonce' ); ?>
									<input type="hidden" name="item_id" value="<?php echo esc_attr( $dlq_item->id ); ?>">
									<button type="submit" name="tsh_wa_action" value="dlq_delete" class="button button-small button-link-delete"
										onclick="return confirm('<?php esc_attr_e( 'Delete this item permanently?', 'tsh-whatsapp-notify' ); ?>')">
										<?php esc_html_e( 'Delete', 'tsh-whatsapp-notify' ); ?>
									</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( $dlq_count > 10 ) : ?>
				<p class="tsh-wa-dlq-more">
					<?php
					printf(
						/* translators: %d: total dead letter count */
						esc_html__( 'Showing 10 of %d dead letter items. Use "Retry All Failed" or "Export DLQ" for bulk operations.', 'tsh-whatsapp-notify' ),
						esc_html( number_format_i18n( $dlq_count ) )
					);
					?>
				</p>
			<?php endif; ?>
		</div>
	</div>
	<?php endif; ?>

	<?php /* ============================================================
		   STATUS FILTER TABS
	   ============================================================ */ ?>
	<ul class="tsh-wa-status-tabs">
		<li>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=tsh-whatsapp-notify-queue' ) ); ?>"
			   class="<?php echo '' === $active_status ? 'current' : ''; ?>">
				<?php esc_html_e( 'All', 'tsh-whatsapp-notify' ); ?>
				<span class="count">(<?php echo esc_html( number_format_i18n( array_sum( $queue_counts ) ) ); ?>)</span>
			</a>
		</li>
		<?php foreach ( Queue::ALL_STATUSES as $tab_status ) : ?>
			<li>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=tsh-whatsapp-notify-queue&status=' . $tab_status ) ); ?>"
				   class="<?php echo $active_status === $tab_status ? 'current' : ''; ?>">
					<?php echo esc_html( ucfirst( $tab_status ) ); ?>
					<span class="count">(<?php echo esc_html( number_format_i18n( $queue_counts[ $tab_status ] ?? 0 ) ); ?>)</span>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>

	<?php /* ============================================================
		   MAIN QUEUE TABLE
	   ============================================================ */ ?>
	<div class="tsh-wa-panel">
		<div class="tsh-wa-panel__body">
			<?php if ( ! empty( $queue_items ) ) : ?>
				<table class="tsh-wa-table wp-list-table widefat">
					<thead>
						<tr>
							<th><?php esc_html_e( 'ID', 'tsh-whatsapp-notify' ); ?></th>
							<th><?php esc_html_e( 'Phone', 'tsh-whatsapp-notify' ); ?></th>
							<th><?php esc_html_e( 'Message', 'tsh-whatsapp-notify' ); ?></th>
							<th><?php esc_html_e( 'Order', 'tsh-whatsapp-notify' ); ?></th>
							<th><?php esc_html_e( 'Queue Status', 'tsh-whatsapp-notify' ); ?></th>
							<th><?php esc_html_e( 'Delivery', 'tsh-whatsapp-notify' ); ?></th>
							<th><?php esc_html_e( 'Attempts', 'tsh-whatsapp-notify' ); ?></th>
							<th><?php esc_html_e( 'Scheduled', 'tsh-whatsapp-notify' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'tsh-whatsapp-notify' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $queue_items as $item ) : ?>
							<tr class="tsh-wa-queue-row--<?php echo esc_attr( $item->status ); ?>">
								<td><?php echo esc_html( $item->id ); ?></td>
								<td><?php echo esc_html( Helpers::mask_phone( $item->phone ) ); ?></td>
								<td>
									<span class="tsh-wa-text-truncate" title="<?php echo esc_attr( $item->message ); ?>">
										<?php echo esc_html( wp_trim_words( $item->message, 10 ) ); ?>
									</span>
									<?php if ( ! empty( $item->error_message ) ) : ?>
										<span class="tsh-wa-error-hint" title="<?php echo esc_attr( $item->error_message ); ?>">⚠</span>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( $item->order_id ) : ?>
										<a href="<?php echo esc_url( get_edit_post_link( $item->order_id ) ?: admin_url( 'post.php?post=' . $item->order_id . '&action=edit' ) ); ?>">#<?php echo esc_html( $item->order_id ); ?></a>
									<?php else : ?>
										–
									<?php endif; ?>
								</td>
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
										<?php echo esc_html( ucfirst( $item->status ) ); ?>
									</span>
								</td>
								<td>
									<?php
									$ds    = $item->delivery_status ?? 'queued';
									$color = DeliveryTracker::color( $ds );
									?>
									<span class="tsh-wa-badge tsh-wa-badge--<?php echo esc_attr( $color ); ?> tsh-wa-badge--sm">
										<?php echo esc_html( DeliveryTracker::label( $ds ) ); ?>
									</span>
								</td>
								<td><?php echo esc_html( $item->attempts . '/' . $item->max_attempts ); ?></td>
								<td>
									<?php
									$show_time = $item->retry_after ?? $item->scheduled_at;
									if ( $show_time ) :
									?>
										<time datetime="<?php echo esc_attr( $show_time ); ?>" title="<?php echo $item->retry_after ? esc_attr__( 'Retry after', 'tsh-whatsapp-notify' ) : esc_attr__( 'Scheduled at', 'tsh-whatsapp-notify' ); ?>">
											<?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $show_time ) ) ); ?>
										</time>
									<?php else : ?>
										–
									<?php endif; ?>
								</td>
								<td class="tsh-wa-actions-cell">
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
										<button type="submit" name="tsh_wa_action" value="remove" class="button button-small button-link-delete"
											onclick="return confirm('<?php esc_attr_e( 'Remove this item from the queue?', 'tsh-whatsapp-notify' ); ?>')">
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
					<?php esc_html_e( 'No queue items found for this filter.', 'tsh-whatsapp-notify' ); ?>
				</p>
			<?php endif; ?>
		</div>
	</div>

</div>
