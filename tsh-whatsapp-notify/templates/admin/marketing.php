<?php
/**
 * Marketing & Broadcast Engine admin page template.
 *
 * @package TSH\WhatsAppNotify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TSH\WhatsAppNotify\Admin\Menu;
?>
<div class="wrap tsh-wa-marketing">

	<h1 class="wp-heading-inline">
		<?php esc_html_e( 'Marketing & Broadcast Engine', 'tsh-whatsapp-notify' ); ?>
	</h1>
	<span style="font-size:12px;color:#6b7280;margin-left:10px;font-weight:400;">Phase 8</span>
	<a href="#" id="mkt-btn-new-campaign" class="mkt-btn mkt-btn-primary" style="float:right;margin-top:6px;">
		+ <?php esc_html_e( 'New Campaign', 'tsh-whatsapp-notify' ); ?>
	</a>

	<!-- ============================================================
	     Top-level view tabs
	     ============================================================ -->
	<nav class="mkt-tabs">
		<button class="mkt-tab active" data-view="list">
			📋 <?php esc_html_e( 'Campaigns', 'tsh-whatsapp-notify' ); ?>
		</button>
		<button class="mkt-tab" data-view="dashboard">
			📊 <?php esc_html_e( 'Dashboard', 'tsh-whatsapp-notify' ); ?>
		</button>
		<button class="mkt-tab" data-view="library">
			📚 <?php esc_html_e( 'Templates', 'tsh-whatsapp-notify' ); ?>
		</button>
		<button class="mkt-tab" data-view="segments">
			🎯 <?php esc_html_e( 'Segments', 'tsh-whatsapp-notify' ); ?>
		</button>
		<button class="mkt-tab" data-view="import">
			⬆️ <?php esc_html_e( 'Import / Export', 'tsh-whatsapp-notify' ); ?>
		</button>
	</nav>

	<!-- ============================================================
	     VIEW: Campaign list
	     ============================================================ -->
	<div id="mkt-view-list" class="mkt-view active">

		<!-- Toolbar -->
		<div class="mkt-toolbar">
			<input type="search" id="mkt-search" placeholder="<?php esc_attr_e( 'Search campaigns…', 'tsh-whatsapp-notify' ); ?>">
			<select id="mkt-filter-status">
				<option value=""><?php esc_html_e( 'All statuses', 'tsh-whatsapp-notify' ); ?></option>
				<option value="draft">Draft</option>
				<option value="scheduled">Scheduled</option>
				<option value="running">Running</option>
				<option value="paused">Paused</option>
				<option value="completed">Completed</option>
				<option value="failed">Failed</option>
				<option value="archived">Archived</option>
			</select>
			<select id="mkt-filter-type">
				<option value=""><?php esc_html_e( 'All types', 'tsh-whatsapp-notify' ); ?></option>
				<option value="onetime">One-time</option>
				<option value="scheduled">Scheduled</option>
				<option value="recurring">Recurring</option>
			</select>
		</div>

		<!-- Table -->
		<div class="mkt-table-wrap">
			<table class="mkt-table" id="mkt-campaigns-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Campaign', 'tsh-whatsapp-notify' ); ?></th>
						<th><?php esc_html_e( 'Status', 'tsh-whatsapp-notify' ); ?></th>
						<th><?php esc_html_e( 'Type', 'tsh-whatsapp-notify' ); ?></th>
						<th><?php esc_html_e( 'Audience', 'tsh-whatsapp-notify' ); ?></th>
						<th><?php esc_html_e( 'Sent', 'tsh-whatsapp-notify' ); ?></th>
						<th><?php esc_html_e( 'Failed', 'tsh-whatsapp-notify' ); ?></th>
						<th><?php esc_html_e( 'Send At', 'tsh-whatsapp-notify' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'tsh-whatsapp-notify' ); ?></th>
					</tr>
				</thead>
				<tbody id="mkt-campaigns-tbody">
					<tr>
						<td colspan="8">
							<div class="mkt-loading">
								<div class="mkt-spinner"></div>
								<?php esc_html_e( 'Loading campaigns…', 'tsh-whatsapp-notify' ); ?>
							</div>
						</td>
					</tr>
				</tbody>
			</table>

			<div class="mkt-pagination" id="mkt-pagination" style="display:none;">
				<span id="mkt-pagination-info"></span>
				<div class="mkt-pagination-btns" id="mkt-pagination-btns"></div>
			</div>
		</div>
	</div><!-- /VIEW: list -->

	<!-- ============================================================
	     VIEW: Dashboard stats
	     ============================================================ -->
	<div id="mkt-view-dashboard" class="mkt-view">

		<div class="mkt-toolbar">
			<select id="mkt-dashboard-days">
				<option value="7">Last 7 days</option>
				<option value="30" selected>Last 30 days</option>
				<option value="90">Last 90 days</option>
			</select>
			<button class="mkt-btn mkt-btn-secondary" id="mkt-refresh-dashboard">
				🔄 <?php esc_html_e( 'Refresh', 'tsh-whatsapp-notify' ); ?>
			</button>
		</div>

		<!-- Stats row -->
		<div class="mkt-stats-row" id="mkt-dashboard-stats">
			<?php
			$stats_placeholders = [
				[ 'label' => 'Total Campaigns',  'key' => 'total_campaigns', 'class' => '' ],
				[ 'label' => 'Running Now',       'key' => 'running',         'class' => 'orange' ],
				[ 'label' => 'Completed',         'key' => 'completed',       'class' => 'teal' ],
				[ 'label' => 'Messages Sent',     'key' => 'total_sent',      'class' => 'blue' ],
				[ 'label' => 'Failed',            'key' => 'total_failed',    'class' => 'red' ],
				[ 'label' => 'Revenue',           'key' => 'revenue',         'class' => 'purple' ],
				[ 'label' => 'Orders',            'key' => 'orders',          'class' => '' ],
				[ 'label' => 'Conversion Rate',   'key' => 'conversion_rate', 'class' => 'teal' ],
			];

			foreach ( $stats_placeholders as $s ) :
			?>
			<div class="mkt-stat-card <?php echo esc_attr( $s['class'] ); ?>">
				<div class="mkt-stat-label"><?php echo esc_html( $s['label'] ); ?></div>
				<div class="mkt-stat-value" data-stat="<?php echo esc_attr( $s['key'] ); ?>">–</div>
			</div>
			<?php endforeach; ?>
		</div>

		<!-- Today's campaigns panel -->
		<h2 style="font-size:15px;font-weight:700;margin:0 0 12px;"><?php esc_html_e( "Today's Campaigns", 'tsh-whatsapp-notify' ); ?></h2>

		<div class="mkt-stats-row">
			<?php
			$today_cards = [
				[ 'label' => 'Running',   'key' => 'running',   'class' => 'orange' ],
				[ 'label' => 'Completed', 'key' => 'completed', 'class' => 'teal' ],
				[ 'label' => 'Failed',    'key' => 'failed',    'class' => 'red' ],
				[ 'label' => 'Scheduled', 'key' => 'scheduled', 'class' => 'blue' ],
			];
			foreach ( $today_cards as $c ) :
			?>
			<div class="mkt-stat-card <?php echo esc_attr( $c['class'] ); ?>">
				<div class="mkt-stat-label"><?php echo esc_html( $c['label'] ); ?></div>
				<div class="mkt-stat-value" data-today="<?php echo esc_attr( $c['key'] ); ?>">–</div>
			</div>
			<?php endforeach; ?>
		</div>
	</div><!-- /VIEW: dashboard -->

	<!-- ============================================================
	     VIEW: Template library
	     ============================================================ -->
	<div id="mkt-view-library" class="mkt-view">

		<p style="color:#6b7280;font-size:13px;margin-bottom:20px;">
			<?php esc_html_e( 'Choose a pre-built campaign template to get started quickly. Campaigns are created as drafts — edit, select your WhatsApp template, and launch.', 'tsh-whatsapp-notify' ); ?>
		</p>

		<div id="mkt-library-container">
			<div class="mkt-loading">
				<div class="mkt-spinner"></div>
				<?php esc_html_e( 'Loading templates…', 'tsh-whatsapp-notify' ); ?>
			</div>
		</div>
	</div><!-- /VIEW: library -->

	<!-- ============================================================
	     VIEW: Saved segments
	     ============================================================ -->
	<div id="mkt-view-segments" class="mkt-view">

		<div class="mkt-toolbar">
			<button class="mkt-btn mkt-btn-primary" id="mkt-btn-new-segment">
				+ <?php esc_html_e( 'New Segment', 'tsh-whatsapp-notify' ); ?>
			</button>
		</div>

		<div id="mkt-segments-container">
			<div class="mkt-loading">
				<div class="mkt-spinner"></div>
				<?php esc_html_e( 'Loading segments…', 'tsh-whatsapp-notify' ); ?>
			</div>
		</div>
	</div><!-- /VIEW: segments -->

	<!-- ============================================================
	     VIEW: Import / Export
	     ============================================================ -->
	<div id="mkt-view-import" class="mkt-view">

		<div class="mkt-row-2">

			<!-- Import -->
			<div>
				<h3 style="font-size:14px;font-weight:700;margin:0 0 12px;">
					<?php esc_html_e( 'Import Campaigns', 'tsh-whatsapp-notify' ); ?>
				</h3>

				<div class="mkt-import-zone" id="mkt-drop-zone">
					<div class="mkt-import-icon">📥</div>
					<div class="mkt-import-label"><?php esc_html_e( 'Drop JSON file here or click to browse', 'tsh-whatsapp-notify' ); ?></div>
					<div class="mkt-import-sub"><?php esc_html_e( 'Supports single campaign or array of campaigns', 'tsh-whatsapp-notify' ); ?></div>
					<input type="file" id="mkt-import-file" accept=".json" style="display:none;">
				</div>

				<div class="mkt-field" style="margin-top:16px;">
					<label><?php esc_html_e( 'Or paste JSON directly', 'tsh-whatsapp-notify' ); ?></label>
					<textarea class="mkt-textarea" id="mkt-import-json" rows="8" placeholder='[{"name": "Campaign name", ...}]'></textarea>
				</div>

				<label class="mkt-coupon-toggle" style="margin-bottom:12px;">
					<label class="mkt-toggle-switch">
						<input type="checkbox" id="mkt-import-replace">
						<span class="mkt-toggle-track"></span>
					</label>
					<?php esc_html_e( 'Replace all existing campaigns', 'tsh-whatsapp-notify' ); ?>
				</label>

				<button class="mkt-btn mkt-btn-primary" id="mkt-btn-import">
					<?php esc_html_e( 'Import Campaigns', 'tsh-whatsapp-notify' ); ?>
				</button>

				<div id="mkt-import-result" style="margin-top:12px;"></div>
			</div>

			<!-- Export -->
			<div>
				<h3 style="font-size:14px;font-weight:700;margin:0 0 12px;">
					<?php esc_html_e( 'Export Campaigns', 'tsh-whatsapp-notify' ); ?>
				</h3>

				<p style="font-size:13px;color:#6b7280;margin-bottom:16px;">
					<?php esc_html_e( 'Export all campaigns or select specific campaigns. The JSON file can be imported into any TSH WhatsApp Notify installation.', 'tsh-whatsapp-notify' ); ?>
				</p>

				<div class="mkt-field">
					<label><?php esc_html_e( 'Campaign IDs to export', 'tsh-whatsapp-notify' ); ?></label>
					<input type="text" class="mkt-input" id="mkt-export-ids" placeholder="<?php esc_attr_e( 'Leave empty to export all', 'tsh-whatsapp-notify' ); ?>">
					<div class="mkt-hint"><?php esc_html_e( 'Comma-separated IDs, e.g. 1,2,5', 'tsh-whatsapp-notify' ); ?></div>
				</div>

				<label class="mkt-coupon-toggle" style="margin-bottom:16px;">
					<label class="mkt-toggle-switch">
						<input type="checkbox" id="mkt-export-stats">
						<span class="mkt-toggle-track"></span>
					</label>
					<?php esc_html_e( 'Include analytics stats', 'tsh-whatsapp-notify' ); ?>
				</label>

				<button class="mkt-btn mkt-btn-secondary" id="mkt-btn-export">
					📤 <?php esc_html_e( 'Download JSON', 'tsh-whatsapp-notify' ); ?>
				</button>
			</div>
		</div>
	</div><!-- /VIEW: import -->

</div><!-- /tsh-wa-marketing -->


<!-- ============================================================
     CAMPAIGN BUILDER MODAL
     ============================================================ -->
<div class="mkt-modal-overlay" id="mkt-builder-modal">
<div class="mkt-modal mkt-modal-lg">

	<div class="mkt-modal-header">
		<div class="mkt-modal-title" id="mkt-builder-modal-title">
			<?php esc_html_e( 'New Campaign', 'tsh-whatsapp-notify' ); ?>
		</div>
		<button class="mkt-modal-close" id="mkt-builder-close">✕</button>
	</div>

	<!-- Steps indicator -->
	<div class="mkt-steps" id="mkt-steps">
		<?php
		$steps = [
			1 => [ 'label' => __( 'Details', 'tsh-whatsapp-notify' ),   'id' => 'details' ],
			2 => [ 'label' => __( 'Audience', 'tsh-whatsapp-notify' ),  'id' => 'audience' ],
			3 => [ 'label' => __( 'Template', 'tsh-whatsapp-notify' ),  'id' => 'template' ],
			4 => [ 'label' => __( 'Message', 'tsh-whatsapp-notify' ),   'id' => 'message' ],
			5 => [ 'label' => __( 'Schedule', 'tsh-whatsapp-notify' ),  'id' => 'schedule' ],
			6 => [ 'label' => __( 'Preview', 'tsh-whatsapp-notify' ),   'id' => 'preview' ],
		];

		foreach ( $steps as $num => $step ) :
		?>
		<div class="mkt-step <?php echo 1 === $num ? 'active' : ''; ?>" data-step="<?php echo esc_attr( $num ); ?>">
			<div class="mkt-step-num"><?php echo esc_html( $num ); ?></div>
			<span><?php echo esc_html( $step['label'] ); ?></span>
		</div>
		<?php endforeach; ?>
	</div>

	<!-- Step panels -->
	<div class="mkt-step-panels" id="mkt-step-panels" style="overflow-y:auto;flex:1;">

		<!-- Step 1: Campaign details -->
		<div class="mkt-step-panel active" data-panel="1">
			<div class="mkt-field">
				<label><?php esc_html_e( 'Campaign Name *', 'tsh-whatsapp-notify' ); ?></label>
				<input type="text" class="mkt-input" id="mkt-campaign-name" placeholder="<?php esc_attr_e( 'e.g. Black Friday Sale 2025', 'tsh-whatsapp-notify' ); ?>">
			</div>
			<div class="mkt-field">
				<label><?php esc_html_e( 'Description', 'tsh-whatsapp-notify' ); ?></label>
				<textarea class="mkt-textarea" id="mkt-campaign-description" rows="3" placeholder="<?php esc_attr_e( 'Optional internal notes…', 'tsh-whatsapp-notify' ); ?>"></textarea>
			</div>
			<div class="mkt-field">
				<label><?php esc_html_e( 'Campaign Type', 'tsh-whatsapp-notify' ); ?></label>
				<div class="mkt-type-cards">
					<div class="mkt-type-card active" data-type="onetime">
						<div class="mkt-type-card-icon">📨</div>
						<div class="mkt-type-card-label"><?php esc_html_e( 'One-time', 'tsh-whatsapp-notify' ); ?></div>
						<div class="mkt-type-card-desc"><?php esc_html_e( 'Send once, immediately or scheduled.', 'tsh-whatsapp-notify' ); ?></div>
					</div>
					<div class="mkt-type-card" data-type="scheduled">
						<div class="mkt-type-card-icon">🗓️</div>
						<div class="mkt-type-card-label"><?php esc_html_e( 'Scheduled', 'tsh-whatsapp-notify' ); ?></div>
						<div class="mkt-type-card-desc"><?php esc_html_e( 'Send at a specific date and time.', 'tsh-whatsapp-notify' ); ?></div>
					</div>
					<div class="mkt-type-card" data-type="recurring">
						<div class="mkt-type-card-icon">🔁</div>
						<div class="mkt-type-card-label"><?php esc_html_e( 'Recurring', 'tsh-whatsapp-notify' ); ?></div>
						<div class="mkt-type-card-desc"><?php esc_html_e( 'Daily, weekly, or monthly cadence.', 'tsh-whatsapp-notify' ); ?></div>
					</div>
				</div>
			</div>
		</div>

		<!-- Step 2: Audience -->
		<div class="mkt-step-panel" data-panel="2">
			<div class="mkt-field">
				<label><?php esc_html_e( 'Audience Type', 'tsh-whatsapp-notify' ); ?></label>
				<div class="mkt-audience-type-grid" id="mkt-audience-type-grid">
					<!-- Populated by JS from tshWaMarketingData.audienceTypes -->
				</div>
			</div>

			<!-- Audience count estimate -->
			<div class="mkt-audience-count" id="mkt-audience-count-row" style="display:none;">
				<span>👥</span>
				<span>Estimated audience:</span>
				<span class="mkt-count-num" id="mkt-audience-count">–</span>
				<button class="mkt-btn mkt-btn-ghost" id="mkt-btn-estimate" style="height:auto;padding:4px 10px;">
					<?php esc_html_e( 'Calculate', 'tsh-whatsapp-notify' ); ?>
				</button>
			</div>

			<!-- Segment rules -->
			<div class="mkt-rules-section" id="mkt-rules-section" style="display:none;">
				<div class="mkt-rules-header">
					<span style="font-size:13px;font-weight:700;"><?php esc_html_e( 'Segment Rules', 'tsh-whatsapp-notify' ); ?></span>
					<div style="display:flex;align-items:center;gap:10px;">
						<span style="font-size:12px;color:#6b7280;"><?php esc_html_e( 'Logic:', 'tsh-whatsapp-notify' ); ?></span>
						<div class="mkt-rules-logic">
							<button class="mkt-logic-btn active" data-logic="AND">AND</button>
							<button class="mkt-logic-btn" data-logic="OR">OR</button>
						</div>
						<button class="mkt-btn mkt-btn-secondary" id="mkt-btn-add-rule" style="height:32px;padding:0 12px;font-size:12px;">
							+ <?php esc_html_e( 'Add Rule', 'tsh-whatsapp-notify' ); ?>
						</button>
					</div>
				</div>
				<div id="mkt-rules-container"></div>
			</div>

			<!-- Saved segment picker -->
			<div class="mkt-field" id="mkt-saved-segment-field" style="display:none;">
				<label><?php esc_html_e( 'Saved Segment', 'tsh-whatsapp-notify' ); ?></label>
				<select class="mkt-select" id="mkt-saved-segment-select">
					<option value=""><?php esc_html_e( 'Select a segment…', 'tsh-whatsapp-notify' ); ?></option>
				</select>
			</div>
		</div>

		<!-- Step 3: Template -->
		<div class="mkt-step-panel" data-panel="3">
			<div class="mkt-field">
				<label><?php esc_html_e( 'WhatsApp Template (Variant A) *', 'tsh-whatsapp-notify' ); ?></label>
				<input type="text" class="mkt-input" id="mkt-template-search" placeholder="<?php esc_attr_e( 'Search approved templates…', 'tsh-whatsapp-notify' ); ?>">
			</div>
			<div class="mkt-template-grid" id="mkt-template-grid">
				<!-- Populated by JS -->
			</div>

			<!-- A/B Test -->
			<div class="mkt-ab-section" id="mkt-ab-section" style="margin-top:20px;">
				<div class="mkt-ab-header">
					<span class="mkt-ab-title">🧪 <?php esc_html_e( 'A/B Test', 'tsh-whatsapp-notify' ); ?></span>
					<label class="mkt-toggle-switch">
						<input type="checkbox" id="mkt-ab-enabled">
						<span class="mkt-toggle-track"></span>
					</label>
				</div>

				<div id="mkt-ab-fields" style="display:none;">
					<div class="mkt-field">
						<label><?php esc_html_e( 'Template B (Variant B)', 'tsh-whatsapp-notify' ); ?></label>
						<div class="mkt-template-grid" id="mkt-template-grid-b" style="max-height:200px;overflow-y:auto;">
							<!-- Populated by JS -->
						</div>
					</div>
					<div class="mkt-field">
						<label>
							<?php esc_html_e( 'Traffic Split', 'tsh-whatsapp-notify' ); ?>
							— <span id="mkt-split-a-label">50</span>% A / <span id="mkt-split-b-label">50</span>% B
						</label>
						<input type="range" class="mkt-split-slider" id="mkt-ab-split" min="0" max="100" value="50">
					</div>
				</div>
			</div>
		</div>

		<!-- Step 4: Message / Variables -->
		<div class="mkt-step-panel" data-panel="4">
			<div class="mkt-field">
				<label><?php esc_html_e( 'Message Body', 'tsh-whatsapp-notify' ); ?></label>
				<div class="mkt-hint" style="margin-bottom:8px;">
					<?php esc_html_e( 'Use placeholders — click a variable chip to insert it.', 'tsh-whatsapp-notify' ); ?>
				</div>
				<div class="mkt-variables" id="mkt-variable-chips">
					<?php
					$vars = [ '{{first_name}}', '{{last_name}}', '{{full_name}}', '{{email}}', '{{phone}}', '{{store_name}}', '{{coupon_code}}', '{{coupon_expiry}}', '{{store_url}}' ];
					foreach ( $vars as $v ) :
					?>
					<span class="mkt-var-chip" data-var="<?php echo esc_attr( $v ); ?>"><?php echo esc_html( $v ); ?></span>
					<?php endforeach; ?>
				</div>
				<textarea class="mkt-textarea" id="mkt-message-body" rows="6" style="margin-top:10px;" placeholder="<?php esc_attr_e( 'Hello {{first_name}}, we have an exclusive offer for you!', 'tsh-whatsapp-notify' ); ?>"></textarea>
				<div class="mkt-hint"><span id="mkt-char-count">0</span> / 4096 <?php esc_html_e( 'characters', 'tsh-whatsapp-notify' ); ?></div>
			</div>

			<!-- Coupon Engine -->
			<div class="mkt-coupon-section">
				<div class="mkt-coupon-toggle" id="mkt-coupon-toggle">
					<label class="mkt-toggle-switch">
						<input type="checkbox" id="mkt-coupon-enabled">
						<span class="mkt-toggle-track"></span>
					</label>
					<?php esc_html_e( 'Generate unique coupons for each recipient', 'tsh-whatsapp-notify' ); ?>
				</div>

				<div class="mkt-coupon-fields" id="mkt-coupon-fields">
					<div class="mkt-row-3">
						<div class="mkt-field">
							<label><?php esc_html_e( 'Discount Type', 'tsh-whatsapp-notify' ); ?></label>
							<select class="mkt-select" id="mkt-coupon-type">
								<option value="percent">Percentage</option>
								<option value="fixed_cart">Fixed Cart</option>
								<option value="fixed_product">Fixed Product</option>
							</select>
						</div>
						<div class="mkt-field">
							<label><?php esc_html_e( 'Amount', 'tsh-whatsapp-notify' ); ?></label>
							<input type="number" class="mkt-input" id="mkt-coupon-amount" value="10" min="0" step="0.01">
						</div>
						<div class="mkt-field">
							<label><?php esc_html_e( 'Expiry (days)', 'tsh-whatsapp-notify' ); ?></label>
							<input type="number" class="mkt-input" id="mkt-coupon-expiry" value="14" min="1">
						</div>
					</div>
					<div class="mkt-row-3">
						<div class="mkt-field">
							<label><?php esc_html_e( 'Usage Limit', 'tsh-whatsapp-notify' ); ?></label>
							<input type="number" class="mkt-input" id="mkt-coupon-usage-limit" value="1" min="1">
						</div>
						<div class="mkt-field">
							<label><?php esc_html_e( 'Min Spend', 'tsh-whatsapp-notify' ); ?></label>
							<input type="number" class="mkt-input" id="mkt-coupon-min-spend" placeholder="0.00" min="0" step="0.01">
						</div>
						<div class="mkt-field">
							<label><?php esc_html_e( 'Max Spend', 'tsh-whatsapp-notify' ); ?></label>
							<input type="number" class="mkt-input" id="mkt-coupon-max-spend" placeholder="No limit" min="0" step="0.01">
						</div>
					</div>
					<div class="mkt-field">
						<label><?php esc_html_e( 'Coupon Code Prefix', 'tsh-whatsapp-notify' ); ?></label>
						<input type="text" class="mkt-input" id="mkt-coupon-prefix" value="TSH" placeholder="TSH" style="max-width:160px;">
						<div class="mkt-hint"><?php esc_html_e( 'Example: TSH-5-123-ABC123', 'tsh-whatsapp-notify' ); ?></div>
					</div>
				</div>
			</div>
		</div>

		<!-- Step 5: Schedule & Throttle -->
		<div class="mkt-step-panel" data-panel="5">

			<!-- Send timing -->
			<div class="mkt-field">
				<label><?php esc_html_e( 'Send Timing', 'tsh-whatsapp-notify' ); ?></label>
				<select class="mkt-select" id="mkt-send-timing" style="max-width:300px;">
					<option value="immediate"><?php esc_html_e( 'Send Immediately', 'tsh-whatsapp-notify' ); ?></option>
					<option value="scheduled"><?php esc_html_e( 'Schedule for Later', 'tsh-whatsapp-notify' ); ?></option>
				</select>
			</div>

			<div id="mkt-scheduled-time-field" style="display:none;">
				<div class="mkt-field">
					<label><?php esc_html_e( 'Send Date & Time', 'tsh-whatsapp-notify' ); ?></label>
					<input type="datetime-local" class="mkt-input" id="mkt-send-at" style="max-width:280px;">
				</div>
			</div>

			<!-- Recurring schedule -->
			<div id="mkt-recurring-fields" style="display:none;">
				<div class="mkt-row-3">
					<div class="mkt-field">
						<label><?php esc_html_e( 'Recurrence', 'tsh-whatsapp-notify' ); ?></label>
						<select class="mkt-select" id="mkt-recurrence">
							<option value="daily">Daily</option>
							<option value="weekly">Weekly</option>
							<option value="monthly">Monthly</option>
						</select>
					</div>
					<div class="mkt-field">
						<label><?php esc_html_e( 'Day of Week / Month', 'tsh-whatsapp-notify' ); ?></label>
						<select class="mkt-select" id="mkt-recurrence-day">
							<option value="0">Sunday</option>
							<option value="1" selected>Monday</option>
							<option value="2">Tuesday</option>
							<option value="3">Wednesday</option>
							<option value="4">Thursday</option>
							<option value="5">Friday</option>
							<option value="6">Saturday</option>
						</select>
					</div>
					<div class="mkt-field">
						<label><?php esc_html_e( 'Time', 'tsh-whatsapp-notify' ); ?></label>
						<input type="time" class="mkt-input" id="mkt-recurrence-time" value="09:00">
					</div>
				</div>
			</div>

			<!-- Throttle presets -->
			<div class="mkt-field">
				<label><?php esc_html_e( 'Delivery Speed', 'tsh-whatsapp-notify' ); ?></label>
				<div class="mkt-throttle-presets">
					<button class="mkt-preset-btn" data-msgs-min="5"  data-msgs-hour="100"  data-batch="25">🐢 Slow (5/min)</button>
					<button class="mkt-preset-btn active" data-msgs-min="30" data-msgs-hour="1000" data-batch="100">🚶 Normal (30/min)</button>
					<button class="mkt-preset-btn" data-msgs-min="60" data-msgs-hour="2000" data-batch="200">🏃 Fast (60/min)</button>
					<button class="mkt-preset-btn" data-msgs-min="0"  data-msgs-hour="0"    data-batch="0">⚙️ Custom</button>
				</div>
			</div>

			<div class="mkt-row-3" id="mkt-throttle-custom" style="display:none;">
				<div class="mkt-field">
					<label><?php esc_html_e( 'Messages / Minute', 'tsh-whatsapp-notify' ); ?></label>
					<input type="number" class="mkt-input" id="mkt-msgs-per-min" value="30" min="1">
				</div>
				<div class="mkt-field">
					<label><?php esc_html_e( 'Messages / Hour', 'tsh-whatsapp-notify' ); ?></label>
					<input type="number" class="mkt-input" id="mkt-msgs-per-hour" value="1000" min="1">
				</div>
				<div class="mkt-field">
					<label><?php esc_html_e( 'Batch Size', 'tsh-whatsapp-notify' ); ?></label>
					<input type="number" class="mkt-input" id="mkt-batch-size" value="100" min="1" max="1000">
				</div>
			</div>
			<div class="mkt-row-2">
				<div class="mkt-field">
					<label><?php esc_html_e( 'Max Retry Attempts', 'tsh-whatsapp-notify' ); ?></label>
					<input type="number" class="mkt-input" id="mkt-retry-attempts" value="3" min="0" max="10">
				</div>
			</div>
		</div>

		<!-- Step 6: Preview & Launch -->
		<div class="mkt-step-panel" data-panel="6">

			<div class="mkt-preview-card" id="mkt-preview-card">
				<div class="mkt-loading" id="mkt-preview-loading" style="display:none;">
					<div class="mkt-spinner"></div>
					<?php esc_html_e( 'Calculating…', 'tsh-whatsapp-notify' ); ?>
				</div>

				<div id="mkt-preview-content">
					<div class="mkt-preview-stat">
						<div class="mkt-preview-stat-icon">👥</div>
						<div>
							<div class="mkt-preview-stat-label"><?php esc_html_e( 'Audience Size', 'tsh-whatsapp-notify' ); ?></div>
							<div class="mkt-preview-stat-value" id="mkt-prev-audience">–</div>
							<div class="mkt-preview-stat-sub"><?php esc_html_e( 'recipients will receive this campaign', 'tsh-whatsapp-notify' ); ?></div>
						</div>
					</div>

					<div class="mkt-preview-stat">
						<div class="mkt-preview-stat-icon">⏱️</div>
						<div>
							<div class="mkt-preview-stat-label"><?php esc_html_e( 'Estimated Duration', 'tsh-whatsapp-notify' ); ?></div>
							<div class="mkt-preview-stat-value" id="mkt-prev-duration">–</div>
							<div class="mkt-preview-stat-sub" id="mkt-prev-rate"><?php esc_html_e( 'at selected delivery speed', 'tsh-whatsapp-notify' ); ?></div>
						</div>
					</div>

					<div class="mkt-preview-stat" id="mkt-prev-ab-row" style="display:none;">
						<div class="mkt-preview-stat-icon">🧪</div>
						<div>
							<div class="mkt-preview-stat-label"><?php esc_html_e( 'A/B Test', 'tsh-whatsapp-notify' ); ?></div>
							<div class="mkt-preview-stat-value">Active</div>
							<div class="mkt-preview-stat-sub" id="mkt-prev-ab-split"></div>
						</div>
					</div>

					<div class="mkt-preview-stat" id="mkt-prev-coupon-row" style="display:none;">
						<div class="mkt-preview-stat-icon">🎟️</div>
						<div>
							<div class="mkt-preview-stat-label"><?php esc_html_e( 'Coupons', 'tsh-whatsapp-notify' ); ?></div>
							<div class="mkt-preview-stat-value"><?php esc_html_e( 'Unique per recipient', 'tsh-whatsapp-notify' ); ?></div>
							<div class="mkt-preview-stat-sub" id="mkt-prev-coupon-info"></div>
						</div>
					</div>
				</div>
			</div>

			<!-- Message preview -->
			<div class="mkt-message-preview" style="margin-top:20px;">
				<div class="mkt-message-preview-title"><?php esc_html_e( 'Message Preview', 'tsh-whatsapp-notify' ); ?></div>
				<div class="mkt-whatsapp-bubble" id="mkt-message-preview-body">
					<?php esc_html_e( '(No message body entered yet)', 'tsh-whatsapp-notify' ); ?>
				</div>
			</div>

			<!-- Launch options -->
			<div style="margin-top:24px;display:flex;gap:12px;flex-wrap:wrap;">
				<button class="mkt-btn mkt-btn-secondary" id="mkt-btn-save-draft">
					💾 <?php esc_html_e( 'Save as Draft', 'tsh-whatsapp-notify' ); ?>
				</button>
				<button class="mkt-btn mkt-btn-secondary" id="mkt-btn-schedule-launch">
					🗓️ <?php esc_html_e( 'Schedule', 'tsh-whatsapp-notify' ); ?>
				</button>
				<button class="mkt-btn mkt-btn-primary" id="mkt-btn-launch">
					🚀 <?php esc_html_e( 'Launch Now', 'tsh-whatsapp-notify' ); ?>
				</button>
			</div>
		</div>

	</div><!-- /mkt-step-panels -->

	<!-- Step navigation -->
	<div class="mkt-step-nav">
		<button class="mkt-btn mkt-btn-secondary" id="mkt-btn-prev" disabled>← <?php esc_html_e( 'Previous', 'tsh-whatsapp-notify' ); ?></button>
		<span id="mkt-step-label" style="font-size:12px;color:#9ca3af;">Step 1 of 6</span>
		<button class="mkt-btn mkt-btn-primary" id="mkt-btn-next"><?php esc_html_e( 'Next', 'tsh-whatsapp-notify' ); ?> →</button>
	</div>

</div><!-- /mkt-modal -->
</div><!-- /mkt-builder-modal -->


<!-- ============================================================
     CAMPAIGN DETAIL / ANALYTICS MODAL
     ============================================================ -->
<div class="mkt-modal-overlay" id="mkt-analytics-modal">
<div class="mkt-modal mkt-modal-lg">
	<div class="mkt-modal-header">
		<div class="mkt-modal-title" id="mkt-analytics-title"><?php esc_html_e( 'Campaign Analytics', 'tsh-whatsapp-notify' ); ?></div>
		<button class="mkt-modal-close" data-modal="mkt-analytics-modal">✕</button>
	</div>
	<div class="mkt-modal-body" id="mkt-analytics-body">
		<div class="mkt-loading"><div class="mkt-spinner"></div> <?php esc_html_e( 'Loading…', 'tsh-whatsapp-notify' ); ?></div>
	</div>
</div>
</div>


<!-- ============================================================
     LOGS MODAL
     ============================================================ -->
<div class="mkt-modal-overlay" id="mkt-logs-modal">
<div class="mkt-modal">
	<div class="mkt-modal-header">
		<div class="mkt-modal-title"><?php esc_html_e( 'Campaign Logs', 'tsh-whatsapp-notify' ); ?></div>
		<button class="mkt-modal-close" data-modal="mkt-logs-modal">✕</button>
	</div>
	<div class="mkt-modal-body" id="mkt-logs-body">
		<div class="mkt-loading"><div class="mkt-spinner"></div> <?php esc_html_e( 'Loading…', 'tsh-whatsapp-notify' ); ?></div>
	</div>
</div>
</div>

<!-- Toast container -->
<div id="mkt-toasts"></div>
