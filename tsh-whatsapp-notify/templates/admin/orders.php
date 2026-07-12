<?php
/**
 * Orders page template — WhatsApp notification log for WooCommerce orders.
 *
 * Variables injected by Pages\Orders::build_template_data():
 *
 * @var array   $rows          Notification records from DB.
 * @var int     $total         Total matching records.
 * @var int     $per_page      Records per page (25).
 * @var int     $current_page  Current page number.
 * @var int     $total_pages   Total pages.
 * @var string  $filter_status Active status filter ('', 'sent', 'queued', 'failed', …).
 * @var string  $filter_event  Active event filter.
 * @var string  $filter_search Active search string.
 * @var array   $status_counts Keyed by status → object with ->cnt.
 * @var array   $all_events    OrderStatusListener::ALL_EVENTS map.
 * @var string  $base_url      Base admin page URL for this page.
 *
 * @package TSH\WhatsAppNotify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Helper: build a filter URL.
 *
 * @param array $overrides Query arg overrides.
 * @return string
 */
$filter_url = static function ( array $overrides ) use ( $base_url, $filter_status, $filter_event, $filter_search ): string {
	$args = array_filter(
		array_merge(
			[
				'filter_status' => $filter_status,
				'filter_event'  => $filter_event,
				's'             => $filter_search,
				'paged'         => 1,
			],
			$overrides
		)
	);
	return add_query_arg( $args, $base_url );
};

/**
 * Helper: status badge class.
 *
 * @param string $status
 * @return string CSS modifier.
 */
$status_badge = static function ( string $status ): string {
	$map = [
		'sent'      => 'green',
		'queued'    => 'orange',
		'pending'   => 'orange',
		'failed'    => 'red',
		'cancelled' => 'grey',
	];
	return $map[ $status ] ?? 'grey';
};

$all_statuses = [ 'sent', 'queued', 'failed', 'cancelled' ];
?>
<div class="wrap tsh-wa-wrap">

	<?php /* ── Page header ─────────────────────────────────────────────── */ ?>
	<div class="tsh-wa-page-header">
		<div class="tsh-wa-page-header__inner">
			<h1><?php esc_html_e( 'Order Notifications', 'tsh-whatsapp-notify' ); ?></h1>
			<p class="tsh-wa-page-header__subtitle">
				<?php esc_html_e( 'WhatsApp notification log linked to WooCommerce orders.', 'tsh-whatsapp-notify' ); ?>
			</p>
		</div>
		<div>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-orders' ) ); ?>" class="button">
				<span class="dashicons dashicons-cart" style="vertical-align:middle;margin-top:-2px;"></span>
				<?php esc_html_e( 'WooCommerce Orders', 'tsh-whatsapp-notify' ); ?>
			</a>
		</div>
	</div>

	<?php /* ── Status summary row ──────────────────────────────────────── */ ?>
	<div class="tsh-wa-orders-summary">
		<?php
		$summary_items = [
			''          => __( 'All', 'tsh-whatsapp-notify' ),
			'sent'      => __( 'Sent', 'tsh-whatsapp-notify' ),
			'queued'    => __( 'Queued', 'tsh-whatsapp-notify' ),
			'failed'    => __( 'Failed', 'tsh-whatsapp-notify' ),
			'cancelled' => __( 'Cancelled', 'tsh-whatsapp-notify' ),
		];
		$all_count = array_sum( array_map( static function ( $row ) { return (int) $row->cnt; }, $status_counts ) );
		?>
		<a href="<?php echo esc_url( $filter_url( [ 'filter_status' => '', 'paged' => 1 ] ) ); ?>"
			class="tsh-wa-orders-summary__item <?php echo '' === $filter_status ? 'tsh-wa-orders-summary__item--active' : ''; ?>">
			<span class="tsh-wa-orders-summary__label"><?php esc_html_e( 'All', 'tsh-whatsapp-notify' ); ?></span>
			<span class="tsh-wa-orders-summary__count"><?php echo esc_html( number_format_i18n( $all_count ) ); ?></span>
		</a>
		<?php foreach ( $all_statuses as $st ) : ?>
		<?php $cnt = (int) ( $status_counts[ $st ]->cnt ?? 0 ); ?>
		<a href="<?php echo esc_url( $filter_url( [ 'filter_status' => $st, 'paged' => 1 ] ) ); ?>"
			class="tsh-wa-orders-summary__item tsh-wa-orders-summary__item--<?php echo esc_attr( $st ); ?> <?php echo $filter_status === $st ? 'tsh-wa-orders-summary__item--active' : ''; ?>">
			<span class="tsh-wa-orders-summary__label"><?php echo esc_html( ucfirst( $st ) ); ?></span>
			<span class="tsh-wa-orders-summary__count"><?php echo esc_html( number_format_i18n( $cnt ) ); ?></span>
		</a>
		<?php endforeach; ?>
	</div>

	<?php /* ── Search & event filter bar ──────────────────────────────── */ ?>
	<div class="tsh-wa-panel">
		<div class="tsh-wa-panel__body" style="padding:12px 20px;">
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="tsh-wa-orders-filters">
				<input type="hidden" name="page" value="tsh-whatsapp-notify-orders">
				<?php if ( $filter_status ) : ?>
					<input type="hidden" name="filter_status" value="<?php echo esc_attr( $filter_status ); ?>">
				<?php endif; ?>

				<select name="filter_event" id="tsh-wa-filter-event">
					<option value=""><?php esc_html_e( '— All Events —', 'tsh-whatsapp-notify' ); ?></option>
					<?php foreach ( $all_events as $ev_key => $ev_label ) : ?>
						<option value="<?php echo esc_attr( $ev_key ); ?>" <?php selected( $filter_event, $ev_key ); ?>>
							<?php echo esc_html( $ev_label ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<input type="search"
					name="s"
					class="tsh-wa-orders-search"
					placeholder="<?php esc_attr_e( 'Search order ID, phone, name…', 'tsh-whatsapp-notify' ); ?>"
					value="<?php echo esc_attr( $filter_search ); ?>"
				>

				<button type="submit" class="button">
					<span class="dashicons dashicons-search" style="vertical-align:middle;margin-top:-2px;"></span>
					<?php esc_html_e( 'Filter', 'tsh-whatsapp-notify' ); ?>
				</button>

				<?php if ( $filter_event || $filter_search || $filter_status ) : ?>
					<a href="<?php echo esc_url( $base_url ); ?>" class="button">
						<?php esc_html_e( 'Reset', 'tsh-whatsapp-notify' ); ?>
					</a>
				<?php endif; ?>
			</form>
		</div>
	</div>

	<?php /* ── Notification table ────────────────────────────────────────── */ ?>
	<div class="tsh-wa-panel" style="margin-top:16px;">
		<div class="tsh-wa-panel__header">
			<h2>
				<?php
				printf(
					/* translators: %s: formatted total count */
					esc_html__( 'Notifications (%s)', 'tsh-whatsapp-notify' ),
					'<strong>' . esc_html( number_format_i18n( $total ) ) . '</strong>'
				);
				?>
			</h2>
			<?php if ( $total_pages > 1 ) : ?>
				<span class="tsh-wa-panel__badge">
					<?php
					printf(
						/* translators: 1: current page, 2: total pages */
						esc_html__( 'Page %1$d of %2$d', 'tsh-whatsapp-notify' ),
						$current_page,
						$total_pages
					);
					?>
				</span>
			<?php endif; ?>
		</div>
		<div class="tsh-wa-panel__body" style="padding:0;">

			<?php if ( ! empty( $rows ) ) : ?>
			<div class="tsh-wa-orders-table-wrapper">
				<table class="tsh-wa-table tsh-wa-orders-table widefat">
					<thead>
						<tr>
							<th class="tsh-wa-orders-table__col-order">
								<?php esc_html_e( 'Order', 'tsh-whatsapp-notify' ); ?>
							</th>
							<th class="tsh-wa-orders-table__col-event">
								<?php esc_html_e( 'Event', 'tsh-whatsapp-notify' ); ?>
							</th>
							<th class="tsh-wa-orders-table__col-recipient">
								<?php esc_html_e( 'Recipient', 'tsh-whatsapp-notify' ); ?>
							</th>
							<th class="tsh-wa-orders-table__col-phone">
								<?php esc_html_e( 'Phone', 'tsh-whatsapp-notify' ); ?>
							</th>
							<th class="tsh-wa-orders-table__col-status">
								<?php esc_html_e( 'Status', 'tsh-whatsapp-notify' ); ?>
							</th>
							<th class="tsh-wa-orders-table__col-time">
								<?php esc_html_e( 'Sent', 'tsh-whatsapp-notify' ); ?>
							</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
						<tr>

							<?php /* Order link */ ?>
							<td class="tsh-wa-orders-table__col-order">
								<?php
								$order_link = admin_url( 'post.php?post=' . absint( $row->order_id ) . '&action=edit' );
								// Support HPOS.
								if ( function_exists( 'wc_get_order' ) ) {
									$o = wc_get_order( (int) $row->order_id );
									if ( $o instanceof \WC_Order ) {
										$order_link = $o->get_edit_order_url();
									}
								}
								?>
								<a href="<?php echo esc_url( $order_link ); ?>" target="_blank" class="tsh-wa-orders-table__order-link">
									#<?php echo esc_html( $row->order_id ); ?>
									<span class="dashicons dashicons-external" aria-hidden="true" style="font-size:12px;width:12px;height:12px;vertical-align:middle;"></span>
								</a>
							</td>

							<?php /* Event badge */ ?>
							<td class="tsh-wa-orders-table__col-event">
								<span class="tsh-wa-badge tsh-wa-badge--blue">
									<?php echo esc_html( $all_events[ $row->event ] ?? ucwords( str_replace( '_', ' ', $row->event ) ) ); ?>
								</span>
							</td>

							<?php /* Recipient type + name */ ?>
							<td class="tsh-wa-orders-table__col-recipient">
								<span class="tsh-wa-badge tsh-wa-badge--<?php echo 'admin' === $row->recipient_type ? 'purple' : 'blue'; ?>">
									<?php echo esc_html( ucfirst( $row->recipient_type ) ); ?>
								</span>
								<?php if ( $row->recipient_name ) : ?>
									<br><small class="tsh-wa-text-muted"><?php echo esc_html( $row->recipient_name ); ?></small>
								<?php endif; ?>
							</td>

							<?php /* Phone (masked) */ ?>
							<td class="tsh-wa-orders-table__col-phone">
								<?php if ( $row->recipient_phone ) : ?>
									<code class="tsh-wa-orders-table__phone">
										<?php
										$phone = $row->recipient_phone;
										if ( strlen( $phone ) > 7 ) {
											$phone = substr( $phone, 0, 4 ) . str_repeat( '•', strlen( $phone ) - 7 ) . substr( $phone, -3 );
										}
										echo esc_html( $phone );
										?>
									</code>
								<?php else : ?>
									<span class="tsh-wa-text-muted">—</span>
								<?php endif; ?>
							</td>

							<?php /* Status badge + error */ ?>
							<td class="tsh-wa-orders-table__col-status">
								<span class="tsh-wa-badge tsh-wa-badge--<?php echo esc_attr( $status_badge( $row->status ) ); ?>">
									<?php echo esc_html( ucfirst( $row->status ) ); ?>
								</span>
								<?php if ( 'failed' === $row->status && ! empty( $row->error_message ) ) : ?>
									<div class="tsh-wa-orders-table__error" title="<?php echo esc_attr( $row->error_message ); ?>">
										<?php echo esc_html( wp_trim_words( $row->error_message, 8, '…' ) ); ?>
									</div>
								<?php endif; ?>
							</td>

							<?php /* Time */ ?>
							<td class="tsh-wa-orders-table__col-time">
								<time datetime="<?php echo esc_attr( $row->created_at ); ?>"
									title="<?php echo esc_attr( $row->created_at ); ?>">
									<?php
									echo esc_html(
										human_time_diff( strtotime( $row->created_at ), current_time( 'timestamp' ) )
										. ' '
										. __( 'ago', 'tsh-whatsapp-notify' )
									);
									?>
								</time>
							</td>

						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div><!-- .tsh-wa-orders-table-wrapper -->

			<?php /* ── Pagination ── */ ?>
			<?php if ( $total_pages > 1 ) : ?>
			<div class="tsh-wa-orders-pagination">
				<?php if ( $current_page > 1 ) : ?>
					<a href="<?php echo esc_url( $filter_url( [ 'paged' => $current_page - 1 ] ) ); ?>"
						class="button button-small">
						&laquo; <?php esc_html_e( 'Previous', 'tsh-whatsapp-notify' ); ?>
					</a>
				<?php endif; ?>

				<?php
				$start = max( 1, $current_page - 2 );
				$end   = min( $total_pages, $current_page + 2 );
				for ( $p = $start; $p <= $end; $p++ ) :
				?>
					<a href="<?php echo esc_url( $filter_url( [ 'paged' => $p ] ) ); ?>"
						class="button button-small <?php echo $p === $current_page ? 'button-primary' : ''; ?>">
						<?php echo esc_html( $p ); ?>
					</a>
				<?php endfor; ?>

				<?php if ( $current_page < $total_pages ) : ?>
					<a href="<?php echo esc_url( $filter_url( [ 'paged' => $current_page + 1 ] ) ); ?>"
						class="button button-small">
						<?php esc_html_e( 'Next', 'tsh-whatsapp-notify' ); ?> &raquo;
					</a>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			<?php else : ?>
			<div class="tsh-wa-empty" style="padding:32px 20px;text-align:center;">
				<span class="dashicons dashicons-bell" style="font-size:36px;width:36px;height:36px;color:var(--tsh-wa-grey);margin-bottom:10px;"></span>
				<p style="font-size:15px;color:var(--tsh-wa-text-muted);margin:0 0 8px;">
					<?php esc_html_e( 'No notifications found.', 'tsh-whatsapp-notify' ); ?>
				</p>
				<?php if ( $filter_status || $filter_event || $filter_search ) : ?>
					<a href="<?php echo esc_url( $base_url ); ?>" class="button button-small">
						<?php esc_html_e( 'Clear filters', 'tsh-whatsapp-notify' ); ?>
					</a>
				<?php else : ?>
					<p style="font-size:13px;color:var(--tsh-wa-grey);">
						<?php esc_html_e( 'Notifications will appear here once WooCommerce order events are processed.', 'tsh-whatsapp-notify' ); ?>
					</p>
				<?php endif; ?>
			</div>
			<?php endif; ?>

		</div>
	</div>

</div><!-- .tsh-wa-wrap -->
