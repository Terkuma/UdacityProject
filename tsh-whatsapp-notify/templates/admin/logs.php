<?php
/**
 * Logs page template.
 *
 * Variables injected by Pages\Logs::render():
 *
 * @var array  $log_rows
 * @var int    $total_logs
 * @var array  $log_counts
 * @var array  $filter_args
 *
 * @package TSH\WhatsAppNotify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TSH\WhatsAppNotify\Logger\Logger;

$per_page    = 25;
$total_pages = $total_logs > 0 ? (int) ceil( $total_logs / $per_page ) : 1;
$current_page = (int) ( $filter_args['page'] ?? 1 );
?>
<div class="wrap tsh-wa-wrap">

	<div class="tsh-wa-page-header">
		<div class="tsh-wa-page-header__inner">
			<h1><?php esc_html_e( 'Activity Logs', 'tsh-whatsapp-notify' ); ?></h1>
		</div>
	</div>

	<?php if ( isset( $_GET['updated'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Logs updated.', 'tsh-whatsapp-notify' ); ?></p></div>
	<?php endif; ?>

	<?php /* Level filter tabs */ ?>
	<ul class="tsh-wa-status-tabs">
		<li>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=tsh-whatsapp-notify-logs' ) ); ?>"
			   class="<?php echo empty( $filter_args['level'] ) ? 'current' : ''; ?>">
				<?php esc_html_e( 'All', 'tsh-whatsapp-notify' ); ?>
				<span class="count">(<?php echo esc_html( number_format_i18n( array_sum( $log_counts ) ) ); ?>)</span>
			</a>
		</li>
		<?php foreach ( [ Logger::LEVEL_SUCCESS, Logger::LEVEL_INFO, Logger::LEVEL_WARNING, Logger::LEVEL_ERROR, Logger::LEVEL_DEBUG ] as $level ) : ?>
			<li>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=tsh-whatsapp-notify-logs&level=' . $level ) ); ?>"
				   class="<?php echo $filter_args['level'] === $level ? 'current' : ''; ?>">
					<?php echo esc_html( ucfirst( $level ) ); ?>
					<span class="count">(<?php echo esc_html( number_format_i18n( $log_counts[ $level ] ?? 0 ) ); ?>)</span>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>

	<?php /* Search / filter bar */ ?>
	<form method="get" action="" class="tsh-wa-filter-form">
		<input type="hidden" name="page" value="tsh-whatsapp-notify-logs">
		<?php if ( ! empty( $filter_args['level'] ) ) : ?>
			<input type="hidden" name="level" value="<?php echo esc_attr( $filter_args['level'] ); ?>">
		<?php endif; ?>
		<input type="search" name="s" placeholder="<?php esc_attr_e( 'Search messages…', 'tsh-whatsapp-notify' ); ?>"
			value="<?php echo esc_attr( $filter_args['search'] ); ?>" class="regular-text">
		<input type="date" name="date_from" value="<?php echo esc_attr( $filter_args['date_from'] ); ?>" title="<?php esc_attr_e( 'From date', 'tsh-whatsapp-notify' ); ?>">
		<input type="date" name="date_to" value="<?php echo esc_attr( $filter_args['date_to'] ); ?>" title="<?php esc_attr_e( 'To date', 'tsh-whatsapp-notify' ); ?>">
		<button type="submit" class="button"><?php esc_html_e( 'Filter', 'tsh-whatsapp-notify' ); ?></button>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=tsh-whatsapp-notify-logs' ) ); ?>" class="button">
			<?php esc_html_e( 'Reset', 'tsh-whatsapp-notify' ); ?>
		</a>
	</form>

	<?php /* Management actions */ ?>
	<form method="post" class="tsh-wa-log-actions" style="margin-bottom:12px;">
		<?php wp_nonce_field( 'tsh_wa_log_action', 'tsh_wa_log_nonce' ); ?>
		<button type="submit" name="tsh_wa_log_action" value="prune_old" class="button"
			onclick="return confirm('<?php esc_attr_e( 'Delete logs older than the retention period?', 'tsh-whatsapp-notify' ); ?>')">
			<?php esc_html_e( 'Prune Old Logs', 'tsh-whatsapp-notify' ); ?>
		</button>
		<button type="submit" name="tsh_wa_log_action" value="clear_all" class="button"
			onclick="return confirm('<?php esc_attr_e( 'Delete ALL log entries? This cannot be undone.', 'tsh-whatsapp-notify' ); ?>')">
			<?php esc_html_e( 'Clear All Logs', 'tsh-whatsapp-notify' ); ?>
		</button>
	</form>

	<div class="tsh-wa-panel">
		<div class="tsh-wa-panel__body">
			<?php if ( ! empty( $log_rows ) ) : ?>
				<table class="tsh-wa-table wp-list-table widefat">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Level', 'tsh-whatsapp-notify' ); ?></th>
							<th><?php esc_html_e( 'Source', 'tsh-whatsapp-notify' ); ?></th>
							<th><?php esc_html_e( 'Message', 'tsh-whatsapp-notify' ); ?></th>
							<th><?php esc_html_e( 'Order', 'tsh-whatsapp-notify' ); ?></th>
							<th><?php esc_html_e( 'Phone', 'tsh-whatsapp-notify' ); ?></th>
							<th><?php esc_html_e( 'Time', 'tsh-whatsapp-notify' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $log_rows as $log ) : ?>
							<tr>
								<td>
									<span class="tsh-wa-level-badge tsh-wa-level-badge--<?php echo esc_attr( $log->level ); ?>">
										<?php echo esc_html( strtoupper( $log->level ) ); ?>
									</span>
								</td>
								<td><code><?php echo esc_html( $log->source ); ?></code></td>
								<td>
									<?php echo esc_html( $log->message ); ?>
									<?php if ( ! empty( $log->context ) ) : ?>
										<details class="tsh-wa-log-context">
											<summary><?php esc_html_e( 'Context', 'tsh-whatsapp-notify' ); ?></summary>
											<pre><?php echo esc_html( json_encode( json_decode( $log->context ), JSON_PRETTY_PRINT ) ); ?></pre>
										</details>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( $log->order_id ) : ?>
										<a href="<?php echo esc_url( admin_url( 'post.php?post=' . absint( $log->order_id ) . '&action=edit' ) ); ?>">
											#<?php echo esc_html( $log->order_id ); ?>
										</a>
									<?php else : ?>
										–
									<?php endif; ?>
								</td>
								<td>
									<?php echo $log->phone ? esc_html( \TSH\WhatsAppNotify\Helpers\Helpers::mask_phone( $log->phone ) ) : '–'; ?>
								</td>
								<td>
									<time datetime="<?php echo esc_attr( $log->created_at ); ?>" title="<?php echo esc_attr( $log->created_at ); ?>">
										<?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $log->created_at ) ) ); ?>
									</time>
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
					<span class="dashicons dashicons-info-outline"></span>
					<?php esc_html_e( 'No log entries found.', 'tsh-whatsapp-notify' ); ?>
				</p>
			<?php endif; ?>
		</div>
	</div>

</div>
