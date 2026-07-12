<?php
/**
 * Templates admin page view.
 *
 * Variables provided by Admin\Pages\Templates::render() via extract():
 *
 * @var array<int, object>   $templates
 * @var int                  $total_templates
 * @var int                  $current_page
 * @var int                  $per_page
 * @var int                  $total_pages
 * @var string               $base_url
 * @var string               $status_filter
 * @var string               $cat_filter
 * @var string               $lang_filter
 * @var string               $quality_filter
 * @var string               $search_query
 * @var string               $orderby
 * @var string               $order
 * @var array<string,string> $status_options
 * @var array<string,string> $category_options
 * @var array<string,string> $language_options
 * @var array<string,mixed>  $overview
 * @var array<string,mixed>  $sync_status
 * @var array<string,object> $assignment_map
 * @var string               $url_sync
 * @var string               $url_settings
 *
 * @package TSH\WhatsAppNotify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Nonce for AJAX actions.
$nonce = wp_create_nonce( \TSH\WhatsAppNotify\Admin\Ajax::NONCE_ACTION );

// Status badge map.
$status_badge_class = [
	'APPROVED'  => 'tsh-wa-status--success',
	'PENDING'   => 'tsh-wa-status--warning',
	'REJECTED'  => 'tsh-wa-status--danger',
	'PAUSED'    => 'tsh-wa-status--warning',
	'DISABLED'  => 'tsh-wa-status--default',
	'DELETED'   => 'tsh-wa-status--default',
];

// Quality badge map.
$quality_badge_class = [
	'HIGH'    => 'tsh-wa-badge--green',
	'MEDIUM'  => 'tsh-wa-badge--orange',
	'LOW'     => 'tsh-wa-badge--red',
	'UNKNOWN' => 'tsh-wa-badge--grey',
];

// Last sync text.
$last_sync_text = $sync_status['last_sync']
	? sprintf(
		/* translators: %s: datetime string */
		__( 'Last synced %s ago', 'tsh-whatsapp-notify' ),
		human_time_diff( strtotime( $sync_status['last_sync'] ) )
	)
	: __( 'Never synced', 'tsh-whatsapp-notify' );

$sync_color_class = match ( $sync_status['status'] ) {
	'success' => 'tsh-wa-status--success',
	'error'   => 'tsh-wa-status--danger',
	'running' => 'tsh-wa-status--warning',
	default   => 'tsh-wa-status--default',
};

// Column sort helper.
$col_url = static function ( string $col ) use ( $base_url, $orderby, $order ): string {
	$new_order = ( $col === $orderby && 'ASC' === $order ) ? 'DESC' : 'ASC';
	return esc_url( add_query_arg( [ 'orderby' => $col, 'order' => $new_order ], $base_url ) );
};

$sort_icon = static function ( string $col ) use ( $orderby, $order ): string {
	if ( $col !== $orderby ) {
		return '<span class="tsh-wa-sort-icon tsh-wa-sort-icon--none">↕</span>';
	}
	return 'ASC' === $order
		? '<span class="tsh-wa-sort-icon tsh-wa-sort-icon--asc">↑</span>'
		: '<span class="tsh-wa-sort-icon tsh-wa-sort-icon--desc">↓</span>';
};
?>

<div class="tsh-wa-page tsh-wa-page--templates">

	<!-- Page header -->
	<div class="tsh-wa-page__header">
		<h1 class="tsh-wa-page__title">
			<?php esc_html_e( 'WhatsApp Templates', 'tsh-whatsapp-notify' ); ?>
			<span class="tsh-wa-badge tsh-wa-badge--grey tsh-wa-badge--count">
				<?php echo esc_html( number_format_i18n( $total_templates ) ); ?>
			</span>
		</h1>

		<div class="tsh-wa-page__header-actions">
			<!-- Sync status indicator -->
			<span class="tsh-wa-status-pill <?php echo esc_attr( $sync_color_class ); ?>" id="tsh-wa-sync-status-pill">
				<?php echo esc_html( $last_sync_text ); ?>
			</span>

			<button type="button" class="button tsh-wa-btn--secondary" id="tsh-wa-btn-sync-templates"
				data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<span class="tsh-wa-btn-icon">↻</span>
				<?php esc_html_e( 'Sync Now', 'tsh-whatsapp-notify' ); ?>
			</button>

			<button type="button" class="button tsh-wa-btn--danger" id="tsh-wa-btn-force-full-sync"
				data-nonce="<?php echo esc_attr( $nonce ); ?>"
				data-confirm="<?php esc_attr_e( 'This will delete all locally synced templates and re-fetch everything from Meta. Continue?', 'tsh-whatsapp-notify' ); ?>">
				<?php esc_html_e( 'Full Reset Sync', 'tsh-whatsapp-notify' ); ?>
			</button>

			<button type="button" class="button tsh-wa-btn--secondary" id="tsh-wa-btn-flush-cache"
				data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<?php esc_html_e( 'Flush Cache', 'tsh-whatsapp-notify' ); ?>
			</button>

			<a href="<?php echo esc_url( $url_settings ); ?>" class="button">
				<?php esc_html_e( 'Sync Settings', 'tsh-whatsapp-notify' ); ?>
			</a>
		</div>
	</div>

	<!-- Last sync error notice -->
	<?php if ( 'error' === $sync_status['status'] && $sync_status['last_error'] ) : ?>
	<div class="tsh-wa-notice tsh-wa-notice--error tsh-wa-notice--inline">
		<strong><?php esc_html_e( 'Last sync failed:', 'tsh-whatsapp-notify' ); ?></strong>
		<?php echo esc_html( $sync_status['last_error'] ); ?>
	</div>
	<?php endif; ?>

	<!-- Stats bar -->
	<div class="tsh-wa-stats-bar tsh-wa-stats-bar--templates" id="tsh-wa-template-stats-bar">
		<div class="tsh-wa-stat-card">
			<div class="tsh-wa-stat-card__value tsh-wa-stat-card__value--green">
				<?php echo esc_html( number_format_i18n( $overview['approved'] ?? 0 ) ); ?>
			</div>
			<div class="tsh-wa-stat-card__label"><?php esc_html_e( 'Approved', 'tsh-whatsapp-notify' ); ?></div>
		</div>
		<div class="tsh-wa-stat-card">
			<div class="tsh-wa-stat-card__value tsh-wa-stat-card__value--orange">
				<?php echo esc_html( number_format_i18n( $overview['pending'] ?? 0 ) ); ?>
			</div>
			<div class="tsh-wa-stat-card__label"><?php esc_html_e( 'Pending', 'tsh-whatsapp-notify' ); ?></div>
		</div>
		<div class="tsh-wa-stat-card">
			<div class="tsh-wa-stat-card__value tsh-wa-stat-card__value--red">
				<?php echo esc_html( number_format_i18n( $overview['rejected'] ?? 0 ) ); ?>
			</div>
			<div class="tsh-wa-stat-card__label"><?php esc_html_e( 'Rejected', 'tsh-whatsapp-notify' ); ?></div>
		</div>
		<div class="tsh-wa-stat-card">
			<div class="tsh-wa-stat-card__value tsh-wa-stat-card__value--orange">
				<?php echo esc_html( number_format_i18n( $overview['paused'] ?? 0 ) ); ?>
			</div>
			<div class="tsh-wa-stat-card__label"><?php esc_html_e( 'Paused', 'tsh-whatsapp-notify' ); ?></div>
		</div>
		<div class="tsh-wa-stat-card">
			<div class="tsh-wa-stat-card__value">
				<?php echo esc_html( number_format_i18n( $overview['total_sends'] ?? 0 ) ); ?>
			</div>
			<div class="tsh-wa-stat-card__label"><?php esc_html_e( 'Total Sends', 'tsh-whatsapp-notify' ); ?></div>
		</div>
		<div class="tsh-wa-stat-card">
			<div class="tsh-wa-stat-card__value tsh-wa-stat-card__value--green">
				<?php echo esc_html( ( $overview['success_rate'] ?? 0 ) . '%' ); ?>
			</div>
			<div class="tsh-wa-stat-card__label"><?php esc_html_e( 'Success Rate', 'tsh-whatsapp-notify' ); ?></div>
		</div>
	</div>

	<!-- Filter bar -->
	<div class="tsh-wa-filter-bar" id="tsh-wa-template-filter-bar">
		<form method="GET" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="tsh-wa-filter-bar__form">
			<input type="hidden" name="page" value="tsh-whatsapp-notify-templates" />

			<!-- Search -->
			<div class="tsh-wa-filter-bar__search">
				<input type="search" name="s" class="tsh-wa-input tsh-wa-input--search"
					placeholder="<?php esc_attr_e( 'Search templates…', 'tsh-whatsapp-notify' ); ?>"
					value="<?php echo esc_attr( $search_query ); ?>"
					id="tsh-wa-template-search" autocomplete="off" />
			</div>

			<!-- Status filter -->
			<select name="status" class="tsh-wa-select tsh-wa-filter-bar__select">
				<option value=""><?php esc_html_e( 'All Statuses', 'tsh-whatsapp-notify' ); ?></option>
				<?php foreach ( $status_options as $slug => $label ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $status_filter, $slug ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<!-- Category filter -->
			<select name="category" class="tsh-wa-select tsh-wa-filter-bar__select">
				<option value=""><?php esc_html_e( 'All Categories', 'tsh-whatsapp-notify' ); ?></option>
				<?php foreach ( $category_options as $slug => $label ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $cat_filter, $slug ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<!-- Language filter -->
			<select name="language" class="tsh-wa-select tsh-wa-filter-bar__select">
				<option value=""><?php esc_html_e( 'All Languages', 'tsh-whatsapp-notify' ); ?></option>
				<?php foreach ( $language_options as $code => $label ) : ?>
					<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $lang_filter, $code ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<button type="submit" class="button tsh-wa-btn--primary">
				<?php esc_html_e( 'Filter', 'tsh-whatsapp-notify' ); ?>
			</button>

			<?php if ( $status_filter || $cat_filter || $lang_filter || $quality_filter || $search_query ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=tsh-whatsapp-notify-templates' ) ); ?>"
					class="button tsh-wa-btn--secondary">
					<?php esc_html_e( 'Clear', 'tsh-whatsapp-notify' ); ?>
				</a>
			<?php endif; ?>
		</form>

		<!-- Import / Export -->
		<div class="tsh-wa-filter-bar__actions">
			<button type="button" class="button" id="tsh-wa-btn-import-templates"
				data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<?php esc_html_e( 'Import', 'tsh-whatsapp-notify' ); ?>
			</button>
			<button type="button" class="button" id="tsh-wa-btn-export-templates"
				data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<?php esc_html_e( 'Export', 'tsh-whatsapp-notify' ); ?>
			</button>
		</div>
	</div>

	<!-- Template table -->
	<div class="tsh-wa-table-wrapper" id="tsh-wa-templates-table-wrapper">
		<?php if ( empty( $templates ) ) : ?>
			<div class="tsh-wa-empty-state">
				<div class="tsh-wa-empty-state__icon">📋</div>
				<div class="tsh-wa-empty-state__title">
					<?php esc_html_e( 'No templates found', 'tsh-whatsapp-notify' ); ?>
				</div>
				<div class="tsh-wa-empty-state__message">
					<?php if ( $search_query || $status_filter || $cat_filter || $lang_filter ) : ?>
						<?php esc_html_e( 'No templates match your current filters. Try clearing the filters.', 'tsh-whatsapp-notify' ); ?>
					<?php else : ?>
						<?php esc_html_e( 'No templates are synced yet. Click "Sync Now" to fetch your approved WhatsApp templates from Meta.', 'tsh-whatsapp-notify' ); ?>
					<?php endif; ?>
				</div>
				<?php if ( ! $search_query && ! $status_filter && ! $cat_filter ) : ?>
					<button type="button" class="button button-primary" id="tsh-wa-btn-sync-templates-empty"
						data-nonce="<?php echo esc_attr( $nonce ); ?>">
						<?php esc_html_e( 'Sync Templates Now', 'tsh-whatsapp-notify' ); ?>
					</button>
				<?php endif; ?>
			</div>
		<?php else : ?>
		<table class="tsh-wa-table tsh-wa-table--templates wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th class="tsh-wa-col--name">
						<a href="<?php echo $col_url( 'template_name' ); ?>">
							<?php esc_html_e( 'Template Name', 'tsh-whatsapp-notify' ); ?>
							<?php echo $sort_icon( 'template_name' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</a>
					</th>
					<th class="tsh-wa-col--category">
						<a href="<?php echo $col_url( 'category' ); ?>">
							<?php esc_html_e( 'Category', 'tsh-whatsapp-notify' ); ?>
							<?php echo $sort_icon( 'category' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</a>
					</th>
					<th class="tsh-wa-col--language">
						<a href="<?php echo $col_url( 'language' ); ?>">
							<?php esc_html_e( 'Language', 'tsh-whatsapp-notify' ); ?>
							<?php echo $sort_icon( 'language' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</a>
					</th>
					<th class="tsh-wa-col--status">
						<a href="<?php echo $col_url( 'status' ); ?>">
							<?php esc_html_e( 'Status', 'tsh-whatsapp-notify' ); ?>
							<?php echo $sort_icon( 'status' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</a>
					</th>
					<th class="tsh-wa-col--quality">
						<?php esc_html_e( 'Quality', 'tsh-whatsapp-notify' ); ?>
					</th>
					<th class="tsh-wa-col--usage">
						<a href="<?php echo $col_url( 'usage_count' ); ?>">
							<?php esc_html_e( 'Uses', 'tsh-whatsapp-notify' ); ?>
							<?php echo $sort_icon( 'usage_count' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</a>
					</th>
					<th class="tsh-wa-col--assignment">
						<?php esc_html_e( 'Assigned To', 'tsh-whatsapp-notify' ); ?>
					</th>
					<th class="tsh-wa-col--synced">
						<a href="<?php echo $col_url( 'last_synced' ); ?>">
							<?php esc_html_e( 'Last Synced', 'tsh-whatsapp-notify' ); ?>
							<?php echo $sort_icon( 'last_synced' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</a>
					</th>
					<th class="tsh-wa-col--actions"><?php esc_html_e( 'Actions', 'tsh-whatsapp-notify' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $templates as $template ) :
					$template_id   = (int) $template->id;
					$status_class  = $status_badge_class[ $template->status ] ?? 'tsh-wa-status--default';
					$quality_class = $quality_badge_class[ $template->quality_score ] ?? 'tsh-wa-badge--grey';

					// Find assignments for this template.
					$assigned_customer = null;
					$assigned_admin    = null;
					foreach ( $assignment_map as $key => $asgn ) {
						if ( (int) $asgn->template_id === $template_id ) {
							if ( 'admin' === $asgn->recipient_type ) {
								$assigned_admin = $asgn->event;
							} else {
								$assigned_customer = $asgn->event;
							}
						}
					}
				?>
				<tr class="tsh-wa-template-row" data-template-id="<?php echo esc_attr( $template_id ); ?>">
					<!-- Name + body preview -->
					<td class="tsh-wa-col--name">
						<strong class="tsh-wa-template-name"><?php echo esc_html( $template->template_name ); ?></strong>
						<?php if ( ! empty( $template->body ) ) : ?>
							<div class="tsh-wa-template-body-preview">
								<?php echo esc_html( mb_substr( strip_tags( $template->body ), 0, 80 ) . ( mb_strlen( $template->body ) > 80 ? '…' : '' ) ); ?>
							</div>
						<?php endif; ?>
						<div class="tsh-wa-template-meta-id tsh-wa-text--muted tsh-wa-text--small">
							ID: <?php echo esc_html( $template->meta_template_id ); ?>
						</div>
					</td>

					<!-- Category -->
					<td class="tsh-wa-col--category">
						<span class="tsh-wa-badge <?php echo esc_attr( \TSH\WhatsAppNotify\Templates\TemplateCategory::get_badge_class( $template->category ) ); ?>">
							<?php echo esc_html( \TSH\WhatsAppNotify\Templates\TemplateCategory::get_label( $template->category ) ); ?>
						</span>
					</td>

					<!-- Language -->
					<td class="tsh-wa-col--language">
						<span class="tsh-wa-text--muted">
							<?php echo esc_html( \TSH\WhatsAppNotify\Templates\TemplateLanguage::get_label( $template->language ) ); ?>
						</span>
						<div class="tsh-wa-text--small tsh-wa-text--muted"><?php echo esc_html( $template->language ); ?></div>
					</td>

					<!-- Status -->
					<td class="tsh-wa-col--status">
						<span class="tsh-wa-status-pill <?php echo esc_attr( $status_class ); ?>">
							<?php echo esc_html( ucfirst( strtolower( $template->status ) ) ); ?>
						</span>
					</td>

					<!-- Quality -->
					<td class="tsh-wa-col--quality">
						<span class="tsh-wa-badge <?php echo esc_attr( $quality_class ); ?>">
							<?php echo esc_html( ucfirst( strtolower( $template->quality_score ) ) ); ?>
						</span>
					</td>

					<!-- Usage -->
					<td class="tsh-wa-col--usage tsh-wa-text--center">
						<?php echo esc_html( number_format_i18n( (int) $template->usage_count ) ); ?>
						<?php if ( $template->usage_count > 0 ) :
							$rate = round( (int) $template->send_success / (int) $template->usage_count * 100 );
						?>
							<div class="tsh-wa-text--small tsh-wa-text--muted">
								<?php echo esc_html( $rate . '%' ); ?>
							</div>
						<?php endif; ?>
					</td>

					<!-- Assignments -->
					<td class="tsh-wa-col--assignment">
						<?php if ( $assigned_customer ) : ?>
							<div class="tsh-wa-assignment-badge tsh-wa-assignment-badge--customer">
								👤 <?php echo esc_html( str_replace( '_', ' ', $assigned_customer ) ); ?>
							</div>
						<?php endif; ?>
						<?php if ( $assigned_admin ) : ?>
							<div class="tsh-wa-assignment-badge tsh-wa-assignment-badge--admin">
								🔧 <?php echo esc_html( str_replace( '_', ' ', $assigned_admin ) ); ?>
							</div>
						<?php endif; ?>
						<?php if ( ! $assigned_customer && ! $assigned_admin ) : ?>
							<span class="tsh-wa-text--muted tsh-wa-text--small">—</span>
						<?php endif; ?>
					</td>

					<!-- Last synced -->
					<td class="tsh-wa-col--synced tsh-wa-text--small tsh-wa-text--muted">
						<?php if ( $template->last_synced ) : ?>
							<?php echo esc_html( human_time_diff( strtotime( $template->last_synced ) ) . ' ago' ); ?>
						<?php else : ?>
							—
						<?php endif; ?>
					</td>

					<!-- Actions -->
					<td class="tsh-wa-col--actions">
						<div class="tsh-wa-row-actions">
							<button type="button"
								class="button button-small tsh-wa-btn-preview-template"
								data-template-id="<?php echo esc_attr( $template_id ); ?>"
								data-nonce="<?php echo esc_attr( $nonce ); ?>"
								title="<?php esc_attr_e( 'Preview', 'tsh-whatsapp-notify' ); ?>">
								👁
							</button>
							<button type="button"
								class="button button-small tsh-wa-btn-assign-template"
								data-template-id="<?php echo esc_attr( $template_id ); ?>"
								data-template-name="<?php echo esc_attr( $template->template_name ); ?>"
								data-nonce="<?php echo esc_attr( $nonce ); ?>"
								title="<?php esc_attr_e( 'Assign to Event', 'tsh-whatsapp-notify' ); ?>">
								🔗
							</button>
						</div>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<!-- Pagination -->
		<?php if ( $total_pages > 1 ) : ?>
		<div class="tsh-wa-pagination">
			<span class="tsh-wa-pagination__info">
				<?php
				printf(
					/* translators: %1$d: first item, %2$d: last item, %3$d: total */
					esc_html__( 'Showing %1$d–%2$d of %3$d templates', 'tsh-whatsapp-notify' ),
					( ( $current_page - 1 ) * $per_page ) + 1,
					min( $current_page * $per_page, $total_templates ),
					$total_templates
				);
				?>
			</span>

			<div class="tsh-wa-pagination__links">
				<?php if ( $current_page > 1 ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'tsh_wa_page', $current_page - 1, $base_url ) ); ?>"
						class="button button-small">&laquo; <?php esc_html_e( 'Prev', 'tsh-whatsapp-notify' ); ?></a>
				<?php endif; ?>

				<?php
				$start_page = max( 1, $current_page - 2 );
				$end_page   = min( $total_pages, $current_page + 2 );

				for ( $p = $start_page; $p <= $end_page; $p++ ) : ?>
					<?php if ( $p === $current_page ) : ?>
						<span class="button button-small tsh-wa-pagination__current"><?php echo esc_html( $p ); ?></span>
					<?php else : ?>
						<a href="<?php echo esc_url( add_query_arg( 'tsh_wa_page', $p, $base_url ) ); ?>"
							class="button button-small"><?php echo esc_html( $p ); ?></a>
					<?php endif; ?>
				<?php endfor; ?>

				<?php if ( $current_page < $total_pages ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'tsh_wa_page', $current_page + 1, $base_url ) ); ?>"
						class="button button-small"><?php esc_html_e( 'Next', 'tsh-whatsapp-notify' ); ?> &raquo;</a>
				<?php endif; ?>
			</div>
		</div>
		<?php endif; ?>

		<?php endif; ?>
	</div><!-- /.tsh-wa-table-wrapper -->

</div><!-- /.tsh-wa-page--templates -->

<?php
// Include the preview modal.
$modal_template = TSH_WA_PATH . 'templates/admin/template-preview-modal.php';
if ( file_exists( $modal_template ) ) {
	include $modal_template;
}
?>
