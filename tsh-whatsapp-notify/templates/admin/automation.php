<?php
/**
 * Automation admin page template.
 *
 * Variables:
 * @var array  $workflows_data   { rows: [], total: int }
 * @var array  $overview         Analytics overview
 * @var array  $templates        Built-in template library (keys => metadata)
 * @var array  $triggers         TriggerManager::get_triggers()
 * @var array  $actions          ActionManager::get_actions()
 * @var array  $conditions       ConditionManager::get_conditions()
 * @var array  $variables        VariableResolver::get_available_variables()
 * @var array  $agents           Available WP users for assignment
 * @var array  $order_statuses   WC order status slugs
 * @var array  $settings         tsh_wa_automation_settings option
 *
 * @package TSH\WhatsAppNotify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$workflows   = $workflows_data['rows'] ?? [];
$total_wf    = $workflows_data['total'] ?? 0;
$enabled     = ! empty( $settings['enabled'] );
?>
<div class="wrap tsh-wa-automation-wrap">

	<!-- Page header -->
	<div class="tsh-wa-page-header tsh-wa-automation-header">
		<div class="tsh-wa-page-header__left">
			<h1 class="tsh-wa-page-title">
				<span class="dashicons dashicons-controls-play"></span>
				<?php esc_html_e( 'Workflow Automation', 'tsh-whatsapp-notify' ); ?>
			</h1>
		</div>
		<div class="tsh-wa-page-header__right">
			<?php if ( ! $enabled ) : ?>
				<span class="tsh-wa-badge tsh-wa-badge--warning"><?php esc_html_e( 'Automation Disabled', 'tsh-whatsapp-notify' ); ?></span>
			<?php endif; ?>
			<button type="button" class="button" id="tsh-wa-wf-import-btn">
				<span class="dashicons dashicons-upload"></span>
				<?php esc_html_e( 'Import', 'tsh-whatsapp-notify' ); ?>
			</button>
			<button type="button" class="button button-primary" id="tsh-wa-wf-create-btn">
				<span class="dashicons dashicons-plus-alt"></span>
				<?php esc_html_e( 'New Workflow', 'tsh-whatsapp-notify' ); ?>
			</button>
		</div>
	</div>

	<!-- Analytics stats -->
	<div class="tsh-wa-wf-stats-row">
		<div class="tsh-wa-wf-stat-card">
			<span class="tsh-wa-wf-stat-value tsh-wa-color-blue"><?php echo esc_html( $overview['total_workflows'] ?? 0 ); ?></span>
			<span class="tsh-wa-wf-stat-label"><?php esc_html_e( 'Total Workflows', 'tsh-whatsapp-notify' ); ?></span>
		</div>
		<div class="tsh-wa-wf-stat-card">
			<span class="tsh-wa-wf-stat-value tsh-wa-color-green"><?php echo esc_html( $overview['active_workflows'] ?? 0 ); ?></span>
			<span class="tsh-wa-wf-stat-label"><?php esc_html_e( 'Active', 'tsh-whatsapp-notify' ); ?></span>
		</div>
		<div class="tsh-wa-wf-stat-card">
			<span class="tsh-wa-wf-stat-value"><?php echo esc_html( $overview['total_runs'] ?? 0 ); ?></span>
			<span class="tsh-wa-wf-stat-label"><?php esc_html_e( 'Runs (30d)', 'tsh-whatsapp-notify' ); ?></span>
		</div>
		<div class="tsh-wa-wf-stat-card">
			<span class="tsh-wa-wf-stat-value tsh-wa-color-green"><?php echo esc_html( ( $overview['success_rate'] ?? 0 ) . '%' ); ?></span>
			<span class="tsh-wa-wf-stat-label"><?php esc_html_e( 'Success Rate', 'tsh-whatsapp-notify' ); ?></span>
		</div>
		<div class="tsh-wa-wf-stat-card">
			<span class="tsh-wa-wf-stat-value tsh-wa-color-red"><?php echo esc_html( $overview['failed'] ?? 0 ); ?></span>
			<span class="tsh-wa-wf-stat-label"><?php esc_html_e( 'Failed', 'tsh-whatsapp-notify' ); ?></span>
		</div>
		<div class="tsh-wa-wf-stat-card">
			<span class="tsh-wa-wf-stat-value"><?php echo esc_html( $overview['avg_duration'] ?? '—' ); ?></span>
			<span class="tsh-wa-wf-stat-label"><?php esc_html_e( 'Avg Duration', 'tsh-whatsapp-notify' ); ?></span>
		</div>
	</div>

	<!-- Main content: list view / builder view (toggled by JS) -->
	<div class="tsh-wa-wf-main" id="tsh-wa-wf-main">

		<!-- ================================================================
		     LIST VIEW
		     ================================================================ -->
		<div id="tsh-wa-wf-list-view" class="tsh-wa-wf-view">

			<!-- Search + filter bar -->
			<div class="tsh-wa-wf-toolbar">
				<input type="text" id="tsh-wa-wf-search" class="regular-text"
					placeholder="<?php esc_attr_e( 'Search workflows…', 'tsh-whatsapp-notify' ); ?>" />
				<select id="tsh-wa-wf-filter-status" class="tsh-wa-select-sm">
					<option value="all"><?php esc_html_e( 'All Statuses', 'tsh-whatsapp-notify' ); ?></option>
					<option value="active"><?php esc_html_e( 'Active', 'tsh-whatsapp-notify' ); ?></option>
					<option value="inactive"><?php esc_html_e( 'Inactive', 'tsh-whatsapp-notify' ); ?></option>
					<option value="draft"><?php esc_html_e( 'Draft', 'tsh-whatsapp-notify' ); ?></option>
				</select>
				<button type="button" class="button" id="tsh-wa-wf-templates-btn">
					<span class="dashicons dashicons-book"></span>
					<?php esc_html_e( 'Template Library', 'tsh-whatsapp-notify' ); ?>
				</button>
				<span class="tsh-wa-wf-total"><?php printf( esc_html__( '%d workflows', 'tsh-whatsapp-notify' ), $total_wf ); ?></span>
			</div>

			<!-- Workflow table -->
			<table class="wp-list-table widefat fixed striped tsh-wa-wf-table" id="tsh-wa-wf-table">
				<thead>
					<tr>
						<th class="column-name"><?php esc_html_e( 'Name', 'tsh-whatsapp-notify' ); ?></th>
						<th class="column-trigger"><?php esc_html_e( 'Trigger', 'tsh-whatsapp-notify' ); ?></th>
						<th class="column-status"><?php esc_html_e( 'Status', 'tsh-whatsapp-notify' ); ?></th>
						<th class="column-runs"><?php esc_html_e( 'Runs', 'tsh-whatsapp-notify' ); ?></th>
						<th class="column-last-run"><?php esc_html_e( 'Last Run', 'tsh-whatsapp-notify' ); ?></th>
						<th class="column-actions"><?php esc_html_e( 'Actions', 'tsh-whatsapp-notify' ); ?></th>
					</tr>
				</thead>
				<tbody id="tsh-wa-wf-table-body">
					<?php if ( empty( $workflows ) ) : ?>
						<tr id="tsh-wa-wf-empty-row">
							<td colspan="6" class="tsh-wa-wf-empty">
								<div class="tsh-wa-wf-empty-state">
									<span class="dashicons dashicons-controls-play tsh-wa-wf-empty-icon"></span>
									<p><?php esc_html_e( 'No workflows yet. Create your first or import from the Template Library.', 'tsh-whatsapp-notify' ); ?></p>
									<button type="button" class="button button-primary" id="tsh-wa-wf-first-create">
										<?php esc_html_e( 'Create First Workflow', 'tsh-whatsapp-notify' ); ?>
									</button>
								</div>
							</td>
						</tr>
					<?php else : ?>
						<?php foreach ( $workflows as $wf ) :
							$trigger_def  = $triggers[ $wf['trigger_type'] ] ?? null;
							$trigger_label= $trigger_def ? $trigger_def['label'] : esc_html( $wf['trigger_type'] );
							$status_class = 'active' === $wf['status'] ? 'tsh-wa-badge--success' : ( 'inactive' === $wf['status'] ? 'tsh-wa-badge--warning' : 'tsh-wa-badge--grey' );
							$last_run     = $wf['last_run_at'] ? human_time_diff( (int) strtotime( $wf['last_run_at'] ) ) . ' ago' : '—';
						?>
						<tr data-id="<?php echo esc_attr( (string) $wf['id'] ); ?>" class="tsh-wa-wf-row">
							<td class="column-name">
								<strong>
									<a href="#" class="tsh-wa-wf-edit-link" data-id="<?php echo esc_attr( (string) $wf['id'] ); ?>">
										<?php echo esc_html( $wf['name'] ); ?>
									</a>
								</strong>
								<?php if ( $wf['description'] ) : ?>
									<p class="tsh-wa-wf-desc"><?php echo esc_html( $wf['description'] ); ?></p>
								<?php endif; ?>
							</td>
							<td class="column-trigger">
								<span class="tsh-wa-wf-trigger-tag">
									<?php echo esc_html( $trigger_label ); ?>
								</span>
							</td>
							<td class="column-status">
								<span class="tsh-wa-badge <?php echo esc_attr( $status_class ); ?>">
									<?php echo esc_html( ucfirst( $wf['status'] ) ); ?>
								</span>
							</td>
							<td class="column-runs"><?php echo esc_html( number_format( (int) $wf['run_count'] ) ); ?></td>
							<td class="column-last-run"><?php echo esc_html( $last_run ); ?></td>
							<td class="column-actions">
								<div class="tsh-wa-wf-row-actions">
									<button type="button" class="button button-small tsh-wa-wf-edit-btn" data-id="<?php echo esc_attr( (string) $wf['id'] ); ?>" title="<?php esc_attr_e( 'Edit', 'tsh-whatsapp-notify' ); ?>">
										<span class="dashicons dashicons-edit"></span>
									</button>
									<?php if ( 'active' === $wf['status'] ) : ?>
										<button type="button" class="button button-small tsh-wa-wf-deactivate-btn" data-id="<?php echo esc_attr( (string) $wf['id'] ); ?>" title="<?php esc_attr_e( 'Deactivate', 'tsh-whatsapp-notify' ); ?>">
											<span class="dashicons dashicons-controls-pause"></span>
										</button>
									<?php else : ?>
										<button type="button" class="button button-small tsh-wa-wf-activate-btn" data-id="<?php echo esc_attr( (string) $wf['id'] ); ?>" title="<?php esc_attr_e( 'Activate', 'tsh-whatsapp-notify' ); ?>">
											<span class="dashicons dashicons-controls-play"></span>
										</button>
									<?php endif; ?>
									<button type="button" class="button button-small tsh-wa-wf-history-btn" data-id="<?php echo esc_attr( (string) $wf['id'] ); ?>" data-name="<?php echo esc_attr( $wf['name'] ); ?>" title="<?php esc_attr_e( 'History', 'tsh-whatsapp-notify' ); ?>">
										<span class="dashicons dashicons-list-view"></span>
									</button>
									<button type="button" class="button button-small tsh-wa-wf-duplicate-btn" data-id="<?php echo esc_attr( (string) $wf['id'] ); ?>" title="<?php esc_attr_e( 'Duplicate', 'tsh-whatsapp-notify' ); ?>">
										<span class="dashicons dashicons-admin-page"></span>
									</button>
									<button type="button" class="button button-small tsh-wa-wf-delete-btn" data-id="<?php echo esc_attr( (string) $wf['id'] ); ?>" data-name="<?php echo esc_attr( $wf['name'] ); ?>" title="<?php esc_attr_e( 'Delete', 'tsh-whatsapp-notify' ); ?>">
										<span class="dashicons dashicons-trash"></span>
									</button>
								</div>
							</td>
						</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

		</div><!-- /#tsh-wa-wf-list-view -->

		<!-- ================================================================
		     BUILDER VIEW
		     ================================================================ -->
		<div id="tsh-wa-wf-builder-view" class="tsh-wa-wf-view" style="display:none;">

			<!-- Builder top bar -->
			<div class="tsh-wa-builder-topbar">
				<button type="button" class="button tsh-wa-builder-back" id="tsh-wa-builder-back-btn">
					<span class="dashicons dashicons-arrow-left-alt"></span>
					<?php esc_html_e( 'Back to Workflows', 'tsh-whatsapp-notify' ); ?>
				</button>

				<div class="tsh-wa-builder-title-wrap">
					<input type="text" id="tsh-wa-builder-name" class="tsh-wa-builder-name-input"
						value="" placeholder="<?php esc_attr_e( 'Workflow Name', 'tsh-whatsapp-notify' ); ?>" />
				</div>

				<div class="tsh-wa-builder-topbar-actions">
					<span class="tsh-wa-builder-autosave" id="tsh-wa-builder-autosave"></span>

					<button type="button" class="button" id="tsh-wa-builder-undo" title="Undo (Ctrl+Z)" disabled>
						<span class="dashicons dashicons-undo"></span>
					</button>
					<button type="button" class="button" id="tsh-wa-builder-redo" title="Redo (Ctrl+Y)" disabled>
						<span class="dashicons dashicons-redo"></span>
					</button>
					<button type="button" class="button" id="tsh-wa-builder-zoom-fit" title="Fit to screen">
						<span class="dashicons dashicons-fullscreen-alt"></span>
					</button>
					<button type="button" class="button" id="tsh-wa-builder-test-btn">
						<span class="dashicons dashicons-media-interactive"></span>
						<?php esc_html_e( 'Test', 'tsh-whatsapp-notify' ); ?>
					</button>
					<button type="button" class="button" id="tsh-wa-builder-save-btn">
						<span class="dashicons dashicons-saved"></span>
						<?php esc_html_e( 'Save', 'tsh-whatsapp-notify' ); ?>
					</button>
					<select id="tsh-wa-builder-status" class="tsh-wa-select-sm">
						<option value="draft"><?php esc_html_e( 'Draft', 'tsh-whatsapp-notify' ); ?></option>
						<option value="active"><?php esc_html_e( 'Active', 'tsh-whatsapp-notify' ); ?></option>
						<option value="inactive"><?php esc_html_e( 'Inactive', 'tsh-whatsapp-notify' ); ?></option>
					</select>
				</div>
			</div>

			<!-- Builder layout -->
			<div class="tsh-wa-builder-layout">

				<!-- Left: Node palette -->
				<div class="tsh-wa-node-palette" id="tsh-wa-node-palette">
					<div class="tsh-wa-palette-section">
						<h4 class="tsh-wa-palette-heading"><?php esc_html_e( 'Triggers', 'tsh-whatsapp-notify' ); ?></h4>
						<?php
						$trigger_groups = [];
						foreach ( $triggers as $key => $trigger ) {
							$trigger_groups[ $trigger['group'] ][ $key ] = $trigger;
						}
						foreach ( $trigger_groups as $group => $group_triggers ) :
						?>
							<div class="tsh-wa-palette-group">
								<span class="tsh-wa-palette-group-label"><?php echo esc_html( $group ); ?></span>
								<?php foreach ( $group_triggers as $key => $trigger ) : ?>
									<div class="tsh-wa-palette-node tsh-wa-palette-node--trigger"
										draggable="true"
										data-node-type="trigger"
										data-trigger-type="<?php echo esc_attr( $key ); ?>"
										data-label="<?php echo esc_attr( $trigger['label'] ); ?>">
										<span class="tsh-wa-palette-node__icon"><?php echo esc_html( $trigger['icon'] ?? '⚡' ); ?></span>
										<span class="tsh-wa-palette-node__label"><?php echo esc_html( $trigger['label'] ); ?></span>
									</div>
								<?php endforeach; ?>
							</div>
						<?php endforeach; ?>
					</div>

					<div class="tsh-wa-palette-section">
						<h4 class="tsh-wa-palette-heading"><?php esc_html_e( 'Actions', 'tsh-whatsapp-notify' ); ?></h4>
						<?php
						$action_groups = [];
						foreach ( $actions as $key => $action ) {
							$action_groups[ $action['group'] ][ $key ] = $action;
						}
						foreach ( $action_groups as $group => $group_actions ) :
						?>
							<div class="tsh-wa-palette-group">
								<span class="tsh-wa-palette-group-label"><?php echo esc_html( $group ); ?></span>
								<?php foreach ( $group_actions as $key => $action ) : ?>
									<div class="tsh-wa-palette-node"
										draggable="true"
										data-node-type="<?php echo esc_attr( $key ); ?>"
										data-label="<?php echo esc_attr( $action['label'] ); ?>"
										style="border-left: 3px solid <?php echo esc_attr( $action['color'] ?? '#6b7280' ); ?>">
										<span class="tsh-wa-palette-node__icon"><?php echo esc_html( $action['icon'] ?? '▶' ); ?></span>
										<span class="tsh-wa-palette-node__label"><?php echo esc_html( $action['label'] ); ?></span>
									</div>
								<?php endforeach; ?>
							</div>
						<?php endforeach; ?>
					</div>
				</div><!-- /.tsh-wa-node-palette -->

				<!-- Center: Canvas -->
				<div class="tsh-wa-canvas-wrap" id="tsh-wa-canvas-wrap">

					<!-- Canvas toolbar overlay -->
					<div class="tsh-wa-canvas-overlay-toolbar">
						<button type="button" class="tsh-wa-canvas-tool" id="tsh-wa-zoom-in" title="Zoom In">+</button>
						<button type="button" class="tsh-wa-canvas-tool" id="tsh-wa-zoom-out" title="Zoom Out">−</button>
						<span class="tsh-wa-zoom-level" id="tsh-wa-zoom-level">100%</span>
					</div>

					<!-- Drop zone hint -->
					<div class="tsh-wa-canvas-hint" id="tsh-wa-canvas-hint">
						<span class="dashicons dashicons-controls-play tsh-wa-canvas-hint-icon"></span>
						<p><?php esc_html_e( 'Drag nodes from the left panel onto the canvas.', 'tsh-whatsapp-notify' ); ?></p>
						<p class="tsh-wa-canvas-hint-sub"><?php esc_html_e( 'Connect nodes by dragging from the ● output port to another node.', 'tsh-whatsapp-notify' ); ?></p>
					</div>

					<!-- SVG connections layer -->
					<svg class="tsh-wa-connections-svg" id="tsh-wa-connections-svg" xmlns="http://www.w3.org/2000/svg">
						<defs>
							<marker id="tsh-wa-arrow" markerWidth="6" markerHeight="6" refX="5" refY="3" orient="auto">
								<path d="M0,0 L6,3 L0,6 Z" fill="#9ca3af" />
							</marker>
							<marker id="tsh-wa-arrow-active" markerWidth="6" markerHeight="6" refX="5" refY="3" orient="auto">
								<path d="M0,0 L6,3 L0,6 Z" fill="#25d366" />
							</marker>
						</defs>
					</svg>

					<!-- Canvas nodes container -->
					<div class="tsh-wa-canvas" id="tsh-wa-canvas"></div>

					<!-- Minimap -->
					<div class="tsh-wa-minimap" id="tsh-wa-minimap">
						<canvas id="tsh-wa-minimap-canvas" width="160" height="100"></canvas>
					</div>

				</div><!-- /.tsh-wa-canvas-wrap -->

				<!-- Right: Node config panel -->
				<div class="tsh-wa-node-config-panel" id="tsh-wa-node-config-panel" style="display:none;">
					<div class="tsh-wa-node-config-header">
						<h3 class="tsh-wa-node-config-title" id="tsh-wa-config-title"><?php esc_html_e( 'Node Settings', 'tsh-whatsapp-notify' ); ?></h3>
						<button type="button" class="button-link" id="tsh-wa-config-close">
							<span class="dashicons dashicons-no-alt"></span>
						</button>
					</div>
					<div class="tsh-wa-node-config-body" id="tsh-wa-node-config-body">
						<!-- Populated dynamically by JS -->
					</div>
					<div class="tsh-wa-node-config-footer">
						<button type="button" class="button button-primary" id="tsh-wa-node-config-save">
							<?php esc_html_e( 'Apply', 'tsh-whatsapp-notify' ); ?>
						</button>
						<button type="button" class="button tsh-wa-btn-danger" id="tsh-wa-node-delete">
							<span class="dashicons dashicons-trash"></span>
							<?php esc_html_e( 'Delete Node', 'tsh-whatsapp-notify' ); ?>
						</button>
					</div>
				</div><!-- /.tsh-wa-node-config-panel -->

			</div><!-- /.tsh-wa-builder-layout -->

		</div><!-- /#tsh-wa-wf-builder-view -->

		<!-- ================================================================
		     HISTORY VIEW
		     ================================================================ -->
		<div id="tsh-wa-wf-history-view" class="tsh-wa-wf-view" style="display:none;">

			<div class="tsh-wa-wf-toolbar">
				<button type="button" class="button" id="tsh-wa-history-back">
					<span class="dashicons dashicons-arrow-left-alt"></span>
					<?php esc_html_e( 'Back', 'tsh-whatsapp-notify' ); ?>
				</button>
				<h2 class="tsh-wa-history-title" id="tsh-wa-history-title"><?php esc_html_e( 'Execution History', 'tsh-whatsapp-notify' ); ?></h2>
			</div>

			<table class="wp-list-table widefat fixed striped tsh-wa-history-table" id="tsh-wa-history-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Run #', 'tsh-whatsapp-notify' ); ?></th>
						<th><?php esc_html_e( 'Status', 'tsh-whatsapp-notify' ); ?></th>
						<th><?php esc_html_e( 'Trigger', 'tsh-whatsapp-notify' ); ?></th>
						<th><?php esc_html_e( 'Started', 'tsh-whatsapp-notify' ); ?></th>
						<th><?php esc_html_e( 'Duration', 'tsh-whatsapp-notify' ); ?></th>
						<th><?php esc_html_e( 'Steps', 'tsh-whatsapp-notify' ); ?></th>
						<th><?php esc_html_e( 'Details', 'tsh-whatsapp-notify' ); ?></th>
					</tr>
				</thead>
				<tbody id="tsh-wa-history-body">
					<tr><td colspan="7"><span class="spinner is-active"></span></td></tr>
				</tbody>
			</table>

		</div><!-- /#tsh-wa-wf-history-view -->

	</div><!-- /#tsh-wa-wf-main -->

</div><!-- /.wrap -->

<!-- ========================================================================
     Modals
     ======================================================================== -->

<!-- Template library modal -->
<div class="tsh-wa-modal-overlay" id="tsh-wa-templates-modal" style="display:none;">
	<div class="tsh-wa-modal tsh-wa-modal--large">
		<div class="tsh-wa-modal-header">
			<h2><?php esc_html_e( 'Workflow Template Library', 'tsh-whatsapp-notify' ); ?></h2>
			<button type="button" class="button-link tsh-wa-modal-close" data-modal="tsh-wa-templates-modal">
				<span class="dashicons dashicons-no-alt"></span>
			</button>
		</div>
		<div class="tsh-wa-modal-body">
			<div class="tsh-wa-templates-grid" id="tsh-wa-templates-grid">
				<?php foreach ( $templates as $key => $tpl ) : ?>
					<div class="tsh-wa-template-card" data-key="<?php echo esc_attr( $key ); ?>">
						<div class="tsh-wa-template-card__header">
							<h3 class="tsh-wa-template-card__name"><?php echo esc_html( $tpl['name'] ); ?></h3>
							<span class="tsh-wa-wf-trigger-tag"><?php echo esc_html( $tpl['trigger_type'] ); ?></span>
						</div>
						<p class="tsh-wa-template-card__desc"><?php echo esc_html( $tpl['description'] ); ?></p>
						<div class="tsh-wa-template-card__footer">
							<span class="tsh-wa-template-card__nodes"><?php printf( esc_html__( '%d nodes', 'tsh-whatsapp-notify' ), count( $tpl['nodes'] ) ); ?></span>
							<button type="button" class="button button-primary tsh-wa-import-template-btn" data-key="<?php echo esc_attr( $key ); ?>">
								<?php esc_html_e( 'Use Template', 'tsh-whatsapp-notify' ); ?>
							</button>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
	</div>
</div>

<!-- Import modal -->
<div class="tsh-wa-modal-overlay" id="tsh-wa-import-modal" style="display:none;">
	<div class="tsh-wa-modal">
		<div class="tsh-wa-modal-header">
			<h2><?php esc_html_e( 'Import Workflows', 'tsh-whatsapp-notify' ); ?></h2>
			<button type="button" class="button-link tsh-wa-modal-close" data-modal="tsh-wa-import-modal">
				<span class="dashicons dashicons-no-alt"></span>
			</button>
		</div>
		<div class="tsh-wa-modal-body">
			<label class="tsh-wa-field-label"><?php esc_html_e( 'JSON Data', 'tsh-whatsapp-notify' ); ?></label>
			<textarea id="tsh-wa-import-json" rows="10" class="large-text" placeholder="<?php esc_attr_e( 'Paste exported JSON here…', 'tsh-whatsapp-notify' ); ?>"></textarea>
			<label class="tsh-wa-field-label" style="margin-top:12px;"><?php esc_html_e( 'Import Mode', 'tsh-whatsapp-notify' ); ?></label>
			<select id="tsh-wa-import-mode" class="tsh-wa-select-sm">
				<option value="merge"><?php esc_html_e( 'Merge (add new, keep existing)', 'tsh-whatsapp-notify' ); ?></option>
				<option value="replace"><?php esc_html_e( 'Replace (delete all, then import)', 'tsh-whatsapp-notify' ); ?></option>
			</select>
		</div>
		<div class="tsh-wa-modal-footer">
			<button type="button" class="button" data-modal-close="tsh-wa-import-modal"><?php esc_html_e( 'Cancel', 'tsh-whatsapp-notify' ); ?></button>
			<button type="button" class="button button-primary" id="tsh-wa-import-confirm-btn"><?php esc_html_e( 'Import', 'tsh-whatsapp-notify' ); ?></button>
		</div>
	</div>
</div>

<!-- Run detail modal -->
<div class="tsh-wa-modal-overlay" id="tsh-wa-run-detail-modal" style="display:none;">
	<div class="tsh-wa-modal tsh-wa-modal--large">
		<div class="tsh-wa-modal-header">
			<h2><?php esc_html_e( 'Run Details', 'tsh-whatsapp-notify' ); ?></h2>
			<button type="button" class="button-link tsh-wa-modal-close" data-modal="tsh-wa-run-detail-modal">
				<span class="dashicons dashicons-no-alt"></span>
			</button>
		</div>
		<div class="tsh-wa-modal-body" id="tsh-wa-run-detail-body">
			<span class="spinner is-active"></span>
		</div>
	</div>
</div>

<!-- Localise data for JS -->
<script>
var tshWaAutomation = <?php echo wp_json_encode( [
	'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
	'nonce'        => wp_create_nonce( \TSH\WhatsAppNotify\Admin\Ajax::NONCE_ACTION ),
	'triggers'     => $triggers,
	'actions'      => $actions,
	'conditions'   => $conditions,
	'variables'    => $variables,
	'agents'       => $agents,
	'orderStatuses'=> $order_statuses,
	'autoSaveDelay'=> 3000,
	'i18n'         => [
		'confirm_delete'   => __( 'Delete this workflow and all its history? This cannot be undone.', 'tsh-whatsapp-notify' ),
		'confirm_replace'  => __( 'This will delete ALL existing workflows before importing. Continue?', 'tsh-whatsapp-notify' ),
		'saved'            => __( 'Workflow saved.', 'tsh-whatsapp-notify' ),
		'saving'           => __( 'Saving…', 'tsh-whatsapp-notify' ),
		'auto_saved'       => __( 'Auto-saved', 'tsh-whatsapp-notify' ),
		'activated'        => __( 'Workflow activated.', 'tsh-whatsapp-notify' ),
		'deactivated'      => __( 'Workflow deactivated.', 'tsh-whatsapp-notify' ),
		'deleted'          => __( 'Workflow deleted.', 'tsh-whatsapp-notify' ),
		'duplicated'       => __( 'Workflow duplicated.', 'tsh-whatsapp-notify' ),
		'imported'         => __( 'Import complete.', 'tsh-whatsapp-notify' ),
		'template_imported'=> __( 'Template imported as draft. Click to edit.', 'tsh-whatsapp-notify' ),
		'test_running'     => __( 'Running test…', 'tsh-whatsapp-notify' ),
		'test_done'        => __( 'Test complete.', 'tsh-whatsapp-notify' ),
		'test_failed'      => __( 'Test failed.', 'tsh-whatsapp-notify' ),
		'connect_hint'     => __( 'Click a node\'s output port (●) then click another node to connect.', 'tsh-whatsapp-notify' ),
		'no_history'       => __( 'No execution history yet.', 'tsh-whatsapp-notify' ),
		'select_trigger'   => __( '— Select Trigger —', 'tsh-whatsapp-notify' ),
		'error'            => __( 'An error occurred.', 'tsh-whatsapp-notify' ),
		'node_deleted'     => __( 'Node deleted.', 'tsh-whatsapp-notify' ),
		'untitled'         => __( 'Untitled Workflow', 'tsh-whatsapp-notify' ),
	],
] ); ?>;
</script>
