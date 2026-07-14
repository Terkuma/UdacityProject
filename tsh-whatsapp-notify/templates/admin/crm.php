<?php
/**
 * CRM admin page template.
 *
 * @package TSH\WhatsAppNotify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap tsh-wa-wrap tsh-wa-crm-wrap" id="tsh-wa-crm">

	<h1 class="wp-heading-inline">
		<span class="tsh-wa-logo">💬</span>
		<?php esc_html_e( 'Customer CRM', 'tsh-whatsapp-notify' ); ?>
	</h1>

	<a href="#" class="page-title-action tsh-wa-crm-btn-create" id="tsh-wa-crm-add-customer">
		<?php esc_html_e( '+ Add Customer', 'tsh-whatsapp-notify' ); ?>
	</a>

	<hr class="wp-header-end">

	<!-- ===== Tab Navigation ================================================ -->
	<nav class="tsh-wa-crm-tabs" id="tsh-wa-crm-tabs">
		<a href="#crm-dashboard"  class="tsh-wa-crm-tab active" data-tab="dashboard">  <?php esc_html_e( 'Dashboard',   'tsh-whatsapp-notify' ); ?></a>
		<a href="#crm-customers"  class="tsh-wa-crm-tab"        data-tab="customers">  <?php esc_html_e( 'Customers',   'tsh-whatsapp-notify' ); ?></a>
		<a href="#crm-segments"   class="tsh-wa-crm-tab"        data-tab="segments">   <?php esc_html_e( 'Segments',    'tsh-whatsapp-notify' ); ?></a>
		<a href="#crm-tasks"      class="tsh-wa-crm-tab"        data-tab="tasks">      <?php esc_html_e( 'Tasks',       'tsh-whatsapp-notify' ); ?></a>
		<a href="#crm-analytics"  class="tsh-wa-crm-tab"        data-tab="analytics">  <?php esc_html_e( 'Analytics',   'tsh-whatsapp-notify' ); ?></a>
		<a href="#crm-import"     class="tsh-wa-crm-tab"        data-tab="import">     <?php esc_html_e( 'Import',      'tsh-whatsapp-notify' ); ?></a>
		<a href="#crm-export"     class="tsh-wa-crm-tab"        data-tab="export">     <?php esc_html_e( 'Export',      'tsh-whatsapp-notify' ); ?></a>
		<a href="#crm-duplicates" class="tsh-wa-crm-tab"        data-tab="duplicates"> <?php esc_html_e( 'Duplicates',  'tsh-whatsapp-notify' ); ?></a>
		<a href="#crm-settings"   class="tsh-wa-crm-tab"        data-tab="settings">   <?php esc_html_e( 'Settings',    'tsh-whatsapp-notify' ); ?></a>
	</nav>

	<!-- ===== DASHBOARD ===================================================== -->
	<div class="tsh-wa-crm-panel" id="crm-dashboard">
		<div class="tsh-wa-crm-stat-cards" id="crm-stat-cards">
			<!-- JS-rendered stat cards -->
			<div class="tsh-wa-crm-loading"><?php esc_html_e( 'Loading stats…', 'tsh-whatsapp-notify' ); ?></div>
		</div>

		<div class="tsh-wa-crm-dashboard-row">
			<div class="tsh-wa-crm-chart-box">
				<h3><?php esc_html_e( 'Growth (last 30 days)', 'tsh-whatsapp-notify' ); ?></h3>
				<canvas id="crm-chart-growth" height="200"></canvas>
			</div>
			<div class="tsh-wa-crm-chart-box">
				<h3><?php esc_html_e( 'Lifecycle Distribution', 'tsh-whatsapp-notify' ); ?></h3>
				<canvas id="crm-chart-lifecycle" height="200"></canvas>
			</div>
			<div class="tsh-wa-crm-chart-box">
				<h3><?php esc_html_e( 'Health Score Distribution', 'tsh-whatsapp-notify' ); ?></h3>
				<canvas id="crm-chart-health" height="200"></canvas>
			</div>
		</div>

		<div class="tsh-wa-crm-dashboard-row">
			<div class="tsh-wa-crm-panel-box" id="crm-overdue-tasks">
				<h3><?php esc_html_e( 'Overdue Tasks', 'tsh-whatsapp-notify' ); ?></h3>
				<div class="tsh-wa-crm-task-list" id="crm-overdue-list">
					<p class="tsh-wa-crm-empty"><?php esc_html_e( 'No overdue tasks.', 'tsh-whatsapp-notify' ); ?></p>
				</div>
			</div>
			<div class="tsh-wa-crm-panel-box" id="crm-top-customers">
				<h3><?php esc_html_e( 'Top Customers by LTV', 'tsh-whatsapp-notify' ); ?></h3>
				<div id="crm-top-list">
					<div class="tsh-wa-crm-loading"><?php esc_html_e( 'Loading…', 'tsh-whatsapp-notify' ); ?></div>
				</div>
			</div>
		</div>
	</div><!-- /dashboard -->

	<!-- ===== CUSTOMERS ===================================================== -->
	<div class="tsh-wa-crm-panel tsh-wa-crm-panel--hidden" id="crm-customers">

		<!-- Toolbar -->
		<div class="tsh-wa-crm-toolbar">
			<div class="tsh-wa-crm-toolbar-left">
				<input type="search" id="crm-customer-search" class="tsh-wa-crm-search" placeholder="<?php esc_attr_e( 'Search name, phone, email…', 'tsh-whatsapp-notify' ); ?>">

				<select id="crm-filter-lifecycle" class="tsh-wa-crm-filter">
					<option value=""><?php esc_html_e( 'All Lifecycles', 'tsh-whatsapp-notify' ); ?></option>
					<?php foreach ( \TSH\WhatsAppNotify\CRM\CustomerLifecycle::labels() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>

				<select id="crm-filter-vip" class="tsh-wa-crm-filter">
					<option value=""><?php esc_html_e( 'All Customers', 'tsh-whatsapp-notify' ); ?></option>
					<option value="1"><?php esc_html_e( 'VIP Only', 'tsh-whatsapp-notify' ); ?></option>
					<option value="0"><?php esc_html_e( 'Non-VIP', 'tsh-whatsapp-notify' ); ?></option>
				</select>

				<select id="crm-filter-blocked" class="tsh-wa-crm-filter">
					<option value=""><?php esc_html_e( 'Active & Blocked', 'tsh-whatsapp-notify' ); ?></option>
					<option value="0"><?php esc_html_e( 'Active Only', 'tsh-whatsapp-notify' ); ?></option>
					<option value="1"><?php esc_html_e( 'Blocked Only', 'tsh-whatsapp-notify' ); ?></option>
				</select>
			</div>
			<div class="tsh-wa-crm-toolbar-right">
				<select id="crm-sort-by" class="tsh-wa-crm-filter">
					<option value="created_at"><?php esc_html_e( 'Sort: Date Added', 'tsh-whatsapp-notify' ); ?></option>
					<option value="lifetime_value"><?php esc_html_e( 'Sort: LTV', 'tsh-whatsapp-notify' ); ?></option>
					<option value="total_orders"><?php esc_html_e( 'Sort: Orders', 'tsh-whatsapp-notify' ); ?></option>
					<option value="health_score"><?php esc_html_e( 'Sort: Health Score', 'tsh-whatsapp-notify' ); ?></option>
					<option value="last_order_at"><?php esc_html_e( 'Sort: Last Order', 'tsh-whatsapp-notify' ); ?></option>
					<option value="full_name"><?php esc_html_e( 'Sort: Name', 'tsh-whatsapp-notify' ); ?></option>
				</select>
				<select id="crm-sort-order" class="tsh-wa-crm-filter tsh-wa-crm-filter--small">
					<option value="DESC"><?php esc_html_e( 'DESC', 'tsh-whatsapp-notify' ); ?></option>
					<option value="ASC"><?php esc_html_e( 'ASC', 'tsh-whatsapp-notify' ); ?></option>
				</select>
			</div>
		</div>

		<!-- Table -->
		<div class="tsh-wa-crm-table-wrap" id="crm-customer-table-wrap">
			<table class="tsh-wa-crm-table" id="crm-customer-table">
				<thead>
					<tr>
						<th class="col-avatar">&nbsp;</th>
						<th class="col-name"><?php esc_html_e( 'Customer', 'tsh-whatsapp-notify' ); ?></th>
						<th class="col-phone"><?php esc_html_e( 'Phone', 'tsh-whatsapp-notify' ); ?></th>
						<th class="col-lifecycle"><?php esc_html_e( 'Lifecycle', 'tsh-whatsapp-notify' ); ?></th>
						<th class="col-orders"><?php esc_html_e( 'Orders', 'tsh-whatsapp-notify' ); ?></th>
						<th class="col-ltv"><?php esc_html_e( 'LTV', 'tsh-whatsapp-notify' ); ?></th>
						<th class="col-health"><?php esc_html_e( 'Health', 'tsh-whatsapp-notify' ); ?></th>
						<th class="col-actions"><?php esc_html_e( 'Actions', 'tsh-whatsapp-notify' ); ?></th>
					</tr>
				</thead>
				<tbody id="crm-customer-tbody">
					<tr><td colspan="8" class="tsh-wa-crm-loading"><?php esc_html_e( 'Loading…', 'tsh-whatsapp-notify' ); ?></td></tr>
				</tbody>
			</table>
		</div>

		<!-- Pagination -->
		<div class="tsh-wa-crm-pagination" id="crm-pagination"></div>
	</div><!-- /customers -->

	<!-- ===== SEGMENTS ====================================================== -->
	<div class="tsh-wa-crm-panel tsh-wa-crm-panel--hidden" id="crm-segments">
		<div class="tsh-wa-crm-section-header">
			<h2><?php esc_html_e( 'Smart Segments', 'tsh-whatsapp-notify' ); ?></h2>
			<button class="button button-primary" id="crm-btn-create-segment">
				<?php esc_html_e( '+ New Segment', 'tsh-whatsapp-notify' ); ?>
			</button>
		</div>

		<div class="tsh-wa-crm-segment-grid" id="crm-segment-grid">
			<div class="tsh-wa-crm-loading"><?php esc_html_e( 'Loading segments…', 'tsh-whatsapp-notify' ); ?></div>
		</div>
	</div><!-- /segments -->

	<!-- ===== TASKS ========================================================= -->
	<div class="tsh-wa-crm-panel tsh-wa-crm-panel--hidden" id="crm-tasks">
		<div class="tsh-wa-crm-section-header">
			<h2><?php esc_html_e( 'All Tasks', 'tsh-whatsapp-notify' ); ?></h2>
			<div class="tsh-wa-crm-toolbar-right">
				<select id="crm-task-filter-status" class="tsh-wa-crm-filter">
					<option value=""><?php esc_html_e( 'All Statuses', 'tsh-whatsapp-notify' ); ?></option>
					<option value="pending"><?php esc_html_e( 'Pending', 'tsh-whatsapp-notify' ); ?></option>
					<option value="in_progress"><?php esc_html_e( 'In Progress', 'tsh-whatsapp-notify' ); ?></option>
					<option value="completed"><?php esc_html_e( 'Completed', 'tsh-whatsapp-notify' ); ?></option>
					<option value="cancelled"><?php esc_html_e( 'Cancelled', 'tsh-whatsapp-notify' ); ?></option>
				</select>
			</div>
		</div>
		<div id="crm-task-list-global">
			<div class="tsh-wa-crm-loading"><?php esc_html_e( 'Loading tasks…', 'tsh-whatsapp-notify' ); ?></div>
		</div>
		<div class="tsh-wa-crm-pagination" id="crm-task-pagination"></div>
	</div><!-- /tasks -->

	<!-- ===== ANALYTICS ===================================================== -->
	<div class="tsh-wa-crm-panel tsh-wa-crm-panel--hidden" id="crm-analytics">
		<div class="tsh-wa-crm-analytics-grid">
			<div class="tsh-wa-crm-chart-box tsh-wa-crm-chart-box--wide">
				<h3><?php esc_html_e( 'Customer Growth', 'tsh-whatsapp-notify' ); ?></h3>
				<div class="tsh-wa-crm-range-btns">
					<button class="button tsh-wa-crm-range-btn active" data-days="30">30d</button>
					<button class="button tsh-wa-crm-range-btn" data-days="60">60d</button>
					<button class="button tsh-wa-crm-range-btn" data-days="90">90d</button>
				</div>
				<canvas id="crm-analytics-growth" height="220"></canvas>
			</div>
			<div class="tsh-wa-crm-chart-box">
				<h3><?php esc_html_e( 'LTV Distribution', 'tsh-whatsapp-notify' ); ?></h3>
				<canvas id="crm-analytics-ltv" height="220"></canvas>
			</div>
			<div class="tsh-wa-crm-chart-box">
				<h3><?php esc_html_e( 'Order Frequency', 'tsh-whatsapp-notify' ); ?></h3>
				<canvas id="crm-analytics-orders" height="220"></canvas>
			</div>
			<div class="tsh-wa-crm-chart-box">
				<h3><?php esc_html_e( 'Countries', 'tsh-whatsapp-notify' ); ?></h3>
				<canvas id="crm-analytics-countries" height="220"></canvas>
			</div>
		</div>
	</div><!-- /analytics -->

	<!-- ===== IMPORT ======================================================== -->
	<div class="tsh-wa-crm-panel tsh-wa-crm-panel--hidden" id="crm-import">
		<div class="tsh-wa-crm-import-box">
			<h2><?php esc_html_e( 'Import Customers', 'tsh-whatsapp-notify' ); ?></h2>

			<div class="tsh-wa-crm-form-row">
				<label><?php esc_html_e( 'Format', 'tsh-whatsapp-notify' ); ?></label>
				<select id="crm-import-format" class="tsh-wa-crm-filter">
					<option value="csv">CSV</option>
					<option value="json">JSON</option>
				</select>
			</div>

			<div class="tsh-wa-crm-form-row">
				<label><?php esc_html_e( 'Conflict Mode', 'tsh-whatsapp-notify' ); ?></label>
				<select id="crm-import-conflict" class="tsh-wa-crm-filter">
					<option value="skip"><?php esc_html_e( 'Skip duplicates', 'tsh-whatsapp-notify' ); ?></option>
					<option value="update"><?php esc_html_e( 'Update existing', 'tsh-whatsapp-notify' ); ?></option>
					<option value="replace"><?php esc_html_e( 'Replace existing', 'tsh-whatsapp-notify' ); ?></option>
				</select>
			</div>

			<div class="tsh-wa-crm-dropzone" id="crm-import-dropzone">
				<p><?php esc_html_e( 'Drag & drop your CSV or JSON file here, or', 'tsh-whatsapp-notify' ); ?>
				<a href="#" id="crm-import-browse"><?php esc_html_e( 'browse', 'tsh-whatsapp-notify' ); ?></a></p>
				<input type="file" id="crm-import-file" accept=".csv,.json" style="display:none">
			</div>

			<div id="crm-import-preview" class="tsh-wa-crm-import-preview" style="display:none">
				<p id="crm-import-preview-msg"></p>
				<button class="button button-primary" id="crm-btn-import-run">
					<?php esc_html_e( 'Import Now', 'tsh-whatsapp-notify' ); ?>
				</button>
			</div>

			<div id="crm-import-result" class="tsh-wa-crm-import-result" style="display:none"></div>

			<hr>
			<h3><?php esc_html_e( 'Sync from WooCommerce', 'tsh-whatsapp-notify' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Imports all WooCommerce customers and their order history into the CRM.', 'tsh-whatsapp-notify' ); ?></p>
			<button class="button" id="crm-btn-woo-sync">
				<?php esc_html_e( 'Sync WooCommerce Customers', 'tsh-whatsapp-notify' ); ?>
			</button>
			<div id="crm-woo-sync-result" style="margin-top:8px"></div>
		</div>
	</div><!-- /import -->

	<!-- ===== EXPORT ======================================================== -->
	<div class="tsh-wa-crm-panel tsh-wa-crm-panel--hidden" id="crm-export">
		<div class="tsh-wa-crm-import-box">
			<h2><?php esc_html_e( 'Export Customers', 'tsh-whatsapp-notify' ); ?></h2>

			<div class="tsh-wa-crm-form-row">
				<label><?php esc_html_e( 'Format', 'tsh-whatsapp-notify' ); ?></label>
				<select id="crm-export-format" class="tsh-wa-crm-filter">
					<option value="csv">CSV</option>
					<option value="json">JSON</option>
				</select>
			</div>

			<div class="tsh-wa-crm-form-row">
				<label><?php esc_html_e( 'Lifecycle Filter', 'tsh-whatsapp-notify' ); ?></label>
				<select id="crm-export-lifecycle" class="tsh-wa-crm-filter">
					<option value=""><?php esc_html_e( 'All', 'tsh-whatsapp-notify' ); ?></option>
					<?php foreach ( \TSH\WhatsAppNotify\CRM\CustomerLifecycle::labels() as $k => $l ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $l ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="tsh-wa-crm-form-row">
				<label><?php esc_html_e( 'VIP Filter', 'tsh-whatsapp-notify' ); ?></label>
				<select id="crm-export-vip" class="tsh-wa-crm-filter">
					<option value=""><?php esc_html_e( 'All', 'tsh-whatsapp-notify' ); ?></option>
					<option value="1"><?php esc_html_e( 'VIP Only', 'tsh-whatsapp-notify' ); ?></option>
					<option value="0"><?php esc_html_e( 'Non-VIP', 'tsh-whatsapp-notify' ); ?></option>
				</select>
			</div>

			<button class="button button-primary" id="crm-btn-export-run">
				<?php esc_html_e( 'Export & Download', 'tsh-whatsapp-notify' ); ?>
			</button>
			<div id="crm-export-result" style="margin-top:8px"></div>
		</div>
	</div><!-- /export -->

	<!-- ===== DUPLICATES ==================================================== -->
	<div class="tsh-wa-crm-panel tsh-wa-crm-panel--hidden" id="crm-duplicates">
		<div class="tsh-wa-crm-section-header">
			<h2><?php esc_html_e( 'Duplicate Customers', 'tsh-whatsapp-notify' ); ?></h2>
			<button class="button" id="crm-btn-find-duplicates">
				<?php esc_html_e( '🔍 Find Duplicates', 'tsh-whatsapp-notify' ); ?>
			</button>
		</div>
		<div id="crm-duplicates-list">
			<p class="tsh-wa-crm-empty"><?php esc_html_e( 'Click "Find Duplicates" to scan for duplicate customer records.', 'tsh-whatsapp-notify' ); ?></p>
		</div>
	</div><!-- /duplicates -->

	<!-- ===== SETTINGS ====================================================== -->
	<div class="tsh-wa-crm-panel tsh-wa-crm-panel--hidden" id="crm-settings">
		<form id="crm-settings-form" class="tsh-wa-crm-settings-form">
			<h2><?php esc_html_e( 'CRM Settings', 'tsh-whatsapp-notify' ); ?></h2>

			<table class="form-table">
				<tr>
					<th><label for="crm-setting-vip-ltv"><?php esc_html_e( 'VIP LTV Threshold', 'tsh-whatsapp-notify' ); ?></label></th>
					<td>
						<input type="number" id="crm-setting-vip-ltv" name="vip_ltv_threshold" min="0" step="1" class="small-text">
						<p class="description"><?php esc_html_e( 'Customers with lifetime value above this amount are auto-promoted to VIP.', 'tsh-whatsapp-notify' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="crm-setting-vip-orders"><?php esc_html_e( 'VIP Order Threshold', 'tsh-whatsapp-notify' ); ?></label></th>
					<td>
						<input type="number" id="crm-setting-vip-orders" name="vip_order_threshold" min="1" step="1" class="small-text">
						<p class="description"><?php esc_html_e( 'Customers with at least this many orders are auto-promoted to VIP.', 'tsh-whatsapp-notify' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="crm-setting-dormant-days"><?php esc_html_e( 'Dormant After (days)', 'tsh-whatsapp-notify' ); ?></label></th>
					<td>
						<input type="number" id="crm-setting-dormant-days" name="dormant_days" min="1" step="1" class="small-text">
						<p class="description"><?php esc_html_e( 'Move customer to "dormant" after this many days without an order.', 'tsh-whatsapp-notify' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="crm-setting-inactive-days"><?php esc_html_e( 'Inactive After (days)', 'tsh-whatsapp-notify' ); ?></label></th>
					<td>
						<input type="number" id="crm-setting-inactive-days" name="inactive_days" min="1" step="1" class="small-text">
						<p class="description"><?php esc_html_e( 'Move customer to "inactive" after this many additional days.', 'tsh-whatsapp-notify' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="crm-setting-reminder-hours"><?php esc_html_e( 'Task Reminder (hours before due)', 'tsh-whatsapp-notify' ); ?></label></th>
					<td>
						<input type="number" id="crm-setting-reminder-hours" name="task_reminder_hours" min="1" step="1" class="small-text">
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Auto Sync WooCommerce', 'tsh-whatsapp-notify' ); ?></th>
					<td>
						<label>
							<input type="checkbox" id="crm-setting-auto-sync" name="auto_sync_woo" value="1">
							<?php esc_html_e( 'Automatically sync new WooCommerce customers to CRM', 'tsh-whatsapp-notify' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Auto Score on Order', 'tsh-whatsapp-notify' ); ?></th>
					<td>
						<label>
							<input type="checkbox" id="crm-setting-auto-score" name="auto_score_on_order" value="1">
							<?php esc_html_e( 'Recalculate health scores whenever an order changes status', 'tsh-whatsapp-notify' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Auto Lifecycle Cron', 'tsh-whatsapp-notify' ); ?></th>
					<td>
						<label>
							<input type="checkbox" id="crm-setting-auto-lifecycle" name="auto_lifecycle_cron" value="1">
							<?php esc_html_e( 'Run the daily lifecycle update cron job', 'tsh-whatsapp-notify' ); ?>
						</label>
					</td>
				</tr>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'tsh-whatsapp-notify' ); ?></button>
			</p>
			<div id="crm-settings-result"></div>
		</form>
	</div><!-- /settings -->

</div><!-- /tsh-wa-crm -->


<!-- =========================================================
     CUSTOMER PROFILE MODAL
     ======================================================= -->
<div class="tsh-wa-crm-modal" id="crm-profile-modal" style="display:none" aria-modal="true" role="dialog">
	<div class="tsh-wa-crm-modal-overlay"></div>
	<div class="tsh-wa-crm-modal-dialog tsh-wa-crm-modal-dialog--xl">

		<!-- Header -->
		<div class="tsh-wa-crm-modal-header" id="crm-profile-header">
			<div class="tsh-wa-crm-profile-avatar" id="crm-profile-avatar">?</div>
			<div class="tsh-wa-crm-profile-identity">
				<h2 id="crm-profile-name">—</h2>
				<div id="crm-profile-badges" class="tsh-wa-crm-badge-row"></div>
				<div id="crm-profile-meta" class="tsh-wa-crm-profile-meta"></div>
			</div>
			<div class="tsh-wa-crm-profile-score" id="crm-profile-score-ring">
				<svg viewBox="0 0 80 80" class="tsh-wa-crm-score-ring">
					<circle cx="40" cy="40" r="34" class="ring-bg"/>
					<circle cx="40" cy="40" r="34" class="ring-fill" id="crm-score-arc"/>
				</svg>
				<span class="tsh-wa-crm-score-value" id="crm-score-val">—</span>
				<span class="tsh-wa-crm-score-label"><?php esc_html_e( 'Health', 'tsh-whatsapp-notify' ); ?></span>
			</div>
			<button class="tsh-wa-crm-modal-close" id="crm-profile-close" aria-label="<?php esc_attr_e( 'Close', 'tsh-whatsapp-notify' ); ?>">&times;</button>
		</div>

		<!-- Stats Bar -->
		<div class="tsh-wa-crm-profile-stats" id="crm-profile-stats-bar"></div>

		<!-- Profile Tabs -->
		<nav class="tsh-wa-crm-profile-tabs">
			<a class="tsh-wa-crm-profile-tab active" data-ptab="overview"><?php esc_html_e( 'Overview', 'tsh-whatsapp-notify' ); ?></a>
			<a class="tsh-wa-crm-profile-tab" data-ptab="timeline"><?php esc_html_e( 'Timeline', 'tsh-whatsapp-notify' ); ?></a>
			<a class="tsh-wa-crm-profile-tab" data-ptab="orders"><?php esc_html_e( 'Orders', 'tsh-whatsapp-notify' ); ?></a>
			<a class="tsh-wa-crm-profile-tab" data-ptab="messages"><?php esc_html_e( 'Messages', 'tsh-whatsapp-notify' ); ?></a>
			<a class="tsh-wa-crm-profile-tab" data-ptab="notes"><?php esc_html_e( 'Notes', 'tsh-whatsapp-notify' ); ?></a>
			<a class="tsh-wa-crm-profile-tab" data-ptab="tasks"><?php esc_html_e( 'Tasks', 'tsh-whatsapp-notify' ); ?></a>
			<a class="tsh-wa-crm-profile-tab" data-ptab="tags"><?php esc_html_e( 'Tags', 'tsh-whatsapp-notify' ); ?></a>
		</nav>

		<!-- Profile Panel Contents -->
		<div class="tsh-wa-crm-profile-body">

			<!-- Overview -->
			<div class="tsh-wa-crm-ptab" id="ptab-overview">
				<div class="tsh-wa-crm-two-col">
					<div id="crm-profile-details"></div>
					<div id="crm-profile-scores-breakdown"></div>
				</div>
				<div class="tsh-wa-crm-profile-actions-row" id="crm-profile-actions">
					<button class="button" id="crm-profile-btn-edit"><?php esc_html_e( 'Edit', 'tsh-whatsapp-notify' ); ?></button>
					<button class="button" id="crm-profile-btn-toggle-vip"><?php esc_html_e( 'Toggle VIP', 'tsh-whatsapp-notify' ); ?></button>
					<button class="button" id="crm-profile-btn-toggle-block"><?php esc_html_e( 'Block / Unblock', 'tsh-whatsapp-notify' ); ?></button>
					<button class="button" id="crm-profile-btn-rescore"><?php esc_html_e( 'Recalculate Score', 'tsh-whatsapp-notify' ); ?></button>
					<button class="button button-link-delete" id="crm-profile-btn-delete"><?php esc_html_e( 'Delete', 'tsh-whatsapp-notify' ); ?></button>
				</div>
				<div class="tsh-wa-crm-lifecycle-selector" id="crm-profile-lifecycle">
					<label><?php esc_html_e( 'Lifecycle:', 'tsh-whatsapp-notify' ); ?></label>
					<select id="crm-lifecycle-select">
						<?php foreach ( \TSH\WhatsAppNotify\CRM\CustomerLifecycle::labels() as $k => $l ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $l ); ?></option>
						<?php endforeach; ?>
					</select>
					<button class="button" id="crm-btn-update-lifecycle"><?php esc_html_e( 'Update', 'tsh-whatsapp-notify' ); ?></button>
				</div>
			</div>

			<!-- Timeline -->
			<div class="tsh-wa-crm-ptab tsh-wa-crm-ptab--hidden" id="ptab-timeline">
				<div class="tsh-wa-crm-timeline-filters">
					<select id="crm-timeline-filter" class="tsh-wa-crm-filter">
						<option value=""><?php esc_html_e( 'All Events', 'tsh-whatsapp-notify' ); ?></option>
						<option value="order"><?php esc_html_e( 'Orders', 'tsh-whatsapp-notify' ); ?></option>
						<option value="message"><?php esc_html_e( 'Messages', 'tsh-whatsapp-notify' ); ?></option>
						<option value="campaign"><?php esc_html_e( 'Campaigns', 'tsh-whatsapp-notify' ); ?></option>
						<option value="note"><?php esc_html_e( 'Notes', 'tsh-whatsapp-notify' ); ?></option>
						<option value="task"><?php esc_html_e( 'Tasks', 'tsh-whatsapp-notify' ); ?></option>
					</select>
				</div>
				<div class="tsh-wa-crm-timeline" id="crm-timeline-feed">
					<div class="tsh-wa-crm-loading"><?php esc_html_e( 'Loading timeline…', 'tsh-whatsapp-notify' ); ?></div>
				</div>
				<button class="button" id="crm-timeline-load-more" style="display:none"><?php esc_html_e( 'Load more', 'tsh-whatsapp-notify' ); ?></button>
			</div>

			<!-- Orders -->
			<div class="tsh-wa-crm-ptab tsh-wa-crm-ptab--hidden" id="ptab-orders">
				<div id="crm-orders-list"><div class="tsh-wa-crm-loading"><?php esc_html_e( 'Loading…', 'tsh-whatsapp-notify' ); ?></div></div>
			</div>

			<!-- Messages -->
			<div class="tsh-wa-crm-ptab tsh-wa-crm-ptab--hidden" id="ptab-messages">
				<div id="crm-messages-list"><div class="tsh-wa-crm-loading"><?php esc_html_e( 'Loading…', 'tsh-whatsapp-notify' ); ?></div></div>
			</div>

			<!-- Notes -->
			<div class="tsh-wa-crm-ptab tsh-wa-crm-ptab--hidden" id="ptab-notes">
				<div class="tsh-wa-crm-note-editor">
					<textarea id="crm-note-editor" rows="3" placeholder="<?php esc_attr_e( 'Write a note…', 'tsh-whatsapp-notify' ); ?>"></textarea>
					<div class="tsh-wa-crm-note-opts">
						<label><input type="checkbox" id="crm-note-pin"> <?php esc_html_e( 'Pin', 'tsh-whatsapp-notify' ); ?></label>
						<label><input type="checkbox" id="crm-note-private" checked> <?php esc_html_e( 'Private', 'tsh-whatsapp-notify' ); ?></label>
						<button class="button button-primary" id="crm-btn-save-note"><?php esc_html_e( 'Add Note', 'tsh-whatsapp-notify' ); ?></button>
					</div>
				</div>
				<div id="crm-notes-feed"></div>
			</div>

			<!-- Tasks -->
			<div class="tsh-wa-crm-ptab tsh-wa-crm-ptab--hidden" id="ptab-tasks">
				<button class="button button-primary" id="crm-btn-add-task-modal"><?php esc_html_e( '+ Add Task', 'tsh-whatsapp-notify' ); ?></button>
				<div id="crm-tasks-feed" style="margin-top:12px"></div>
			</div>

			<!-- Tags -->
			<div class="tsh-wa-crm-ptab tsh-wa-crm-ptab--hidden" id="ptab-tags">
				<div class="tsh-wa-crm-tags-panel">
					<div id="crm-customer-tags" class="tsh-wa-crm-tag-chips"></div>
					<div class="tsh-wa-crm-tag-add-row">
						<select id="crm-tag-select" class="tsh-wa-crm-filter">
							<option value=""><?php esc_html_e( '— Select tag to add —', 'tsh-whatsapp-notify' ); ?></option>
						</select>
						<button class="button" id="crm-btn-add-tag"><?php esc_html_e( 'Add Tag', 'tsh-whatsapp-notify' ); ?></button>
					</div>
				</div>
			</div>

		</div><!-- /profile-body -->
	</div><!-- /modal-dialog -->
</div><!-- /profile-modal -->


<!-- =========================================================
     SEGMENT BUILDER MODAL
     ======================================================= -->
<div class="tsh-wa-crm-modal" id="crm-segment-modal" style="display:none">
	<div class="tsh-wa-crm-modal-overlay"></div>
	<div class="tsh-wa-crm-modal-dialog">
		<div class="tsh-wa-crm-modal-head">
			<h2><?php esc_html_e( 'Segment Builder', 'tsh-whatsapp-notify' ); ?></h2>
			<button class="tsh-wa-crm-modal-close" id="crm-segment-modal-close">&times;</button>
		</div>
		<div class="tsh-wa-crm-modal-body">
			<input type="hidden" id="crm-segment-edit-id" value="">
			<div class="tsh-wa-crm-form-row">
				<label><?php esc_html_e( 'Segment Name', 'tsh-whatsapp-notify' ); ?></label>
				<input type="text" id="crm-segment-name" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. High-Value Regulars', 'tsh-whatsapp-notify' ); ?>">
			</div>
			<div class="tsh-wa-crm-form-row">
				<label><?php esc_html_e( 'Description', 'tsh-whatsapp-notify' ); ?></label>
				<textarea id="crm-segment-desc" rows="2" class="large-text"></textarea>
			</div>
			<div class="tsh-wa-crm-segment-rules" id="crm-segment-rules-wrap">
				<div class="tsh-wa-crm-rules-list" id="crm-rules-list"></div>
				<button class="button" id="crm-btn-add-rule"><?php esc_html_e( '+ Add Rule', 'tsh-whatsapp-notify' ); ?></button>
			</div>
		</div>
		<div class="tsh-wa-crm-modal-foot">
			<button class="button button-primary" id="crm-btn-save-segment"><?php esc_html_e( 'Save Segment', 'tsh-whatsapp-notify' ); ?></button>
			<button class="button" id="crm-btn-cancel-segment"><?php esc_html_e( 'Cancel', 'tsh-whatsapp-notify' ); ?></button>
		</div>
	</div>
</div>


<!-- =========================================================
     ADD / EDIT CUSTOMER MODAL
     ======================================================= -->
<div class="tsh-wa-crm-modal" id="crm-customer-form-modal" style="display:none">
	<div class="tsh-wa-crm-modal-overlay"></div>
	<div class="tsh-wa-crm-modal-dialog">
		<div class="tsh-wa-crm-modal-head">
			<h2 id="crm-customer-form-title"><?php esc_html_e( 'Add Customer', 'tsh-whatsapp-notify' ); ?></h2>
			<button class="tsh-wa-crm-modal-close" id="crm-customer-form-close">&times;</button>
		</div>
		<div class="tsh-wa-crm-modal-body">
			<input type="hidden" id="crm-form-customer-id" value="">
			<div class="tsh-wa-crm-two-col">
				<div class="tsh-wa-crm-form-row">
					<label><?php esc_html_e( 'First Name', 'tsh-whatsapp-notify' ); ?></label>
					<input type="text" id="crm-form-first-name" class="regular-text">
				</div>
				<div class="tsh-wa-crm-form-row">
					<label><?php esc_html_e( 'Last Name', 'tsh-whatsapp-notify' ); ?></label>
					<input type="text" id="crm-form-last-name" class="regular-text">
				</div>
				<div class="tsh-wa-crm-form-row">
					<label><?php esc_html_e( 'Phone', 'tsh-whatsapp-notify' ); ?></label>
					<input type="tel" id="crm-form-phone" class="regular-text">
				</div>
				<div class="tsh-wa-crm-form-row">
					<label><?php esc_html_e( 'WhatsApp Phone', 'tsh-whatsapp-notify' ); ?></label>
					<input type="tel" id="crm-form-wa-phone" class="regular-text">
				</div>
				<div class="tsh-wa-crm-form-row">
					<label><?php esc_html_e( 'Email', 'tsh-whatsapp-notify' ); ?></label>
					<input type="email" id="crm-form-email" class="regular-text">
				</div>
				<div class="tsh-wa-crm-form-row">
					<label><?php esc_html_e( 'Country', 'tsh-whatsapp-notify' ); ?></label>
					<input type="text" id="crm-form-country" class="small-text" maxlength="5">
				</div>
				<div class="tsh-wa-crm-form-row">
					<label><?php esc_html_e( 'City', 'tsh-whatsapp-notify' ); ?></label>
					<input type="text" id="crm-form-city" class="regular-text">
				</div>
				<div class="tsh-wa-crm-form-row">
					<label><?php esc_html_e( 'Lifecycle', 'tsh-whatsapp-notify' ); ?></label>
					<select id="crm-form-lifecycle" class="tsh-wa-crm-filter">
						<?php foreach ( \TSH\WhatsAppNotify\CRM\CustomerLifecycle::labels() as $k => $l ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $l ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>
			<div class="tsh-wa-crm-form-row">
				<label>
					<input type="checkbox" id="crm-form-is-vip"> <?php esc_html_e( 'VIP Customer', 'tsh-whatsapp-notify' ); ?>
				</label>
			</div>
			<div class="tsh-wa-crm-form-row">
				<label>
					<input type="checkbox" id="crm-form-consent"> <?php esc_html_e( 'Marketing Consent', 'tsh-whatsapp-notify' ); ?>
				</label>
			</div>
		</div>
		<div class="tsh-wa-crm-modal-foot">
			<button class="button button-primary" id="crm-btn-save-customer"><?php esc_html_e( 'Save Customer', 'tsh-whatsapp-notify' ); ?></button>
			<button class="button" id="crm-btn-cancel-customer"><?php esc_html_e( 'Cancel', 'tsh-whatsapp-notify' ); ?></button>
		</div>
	</div>
</div>


<!-- =========================================================
     ADD TASK MODAL
     ======================================================= -->
<div class="tsh-wa-crm-modal" id="crm-task-modal" style="display:none">
	<div class="tsh-wa-crm-modal-overlay"></div>
	<div class="tsh-wa-crm-modal-dialog">
		<div class="tsh-wa-crm-modal-head">
			<h2 id="crm-task-modal-title"><?php esc_html_e( 'Add Task', 'tsh-whatsapp-notify' ); ?></h2>
			<button class="tsh-wa-crm-modal-close" id="crm-task-modal-close">&times;</button>
		</div>
		<div class="tsh-wa-crm-modal-body">
			<input type="hidden" id="crm-task-id" value="">
			<div class="tsh-wa-crm-form-row">
				<label><?php esc_html_e( 'Title', 'tsh-whatsapp-notify' ); ?></label>
				<input type="text" id="crm-task-title" class="large-text" required>
			</div>
			<div class="tsh-wa-crm-form-row">
				<label><?php esc_html_e( 'Description', 'tsh-whatsapp-notify' ); ?></label>
				<textarea id="crm-task-desc" rows="3" class="large-text"></textarea>
			</div>
			<div class="tsh-wa-crm-two-col">
				<div class="tsh-wa-crm-form-row">
					<label><?php esc_html_e( 'Priority', 'tsh-whatsapp-notify' ); ?></label>
					<select id="crm-task-priority" class="tsh-wa-crm-filter">
						<option value="low"><?php esc_html_e( 'Low', 'tsh-whatsapp-notify' ); ?></option>
						<option value="medium" selected><?php esc_html_e( 'Medium', 'tsh-whatsapp-notify' ); ?></option>
						<option value="high"><?php esc_html_e( 'High', 'tsh-whatsapp-notify' ); ?></option>
						<option value="urgent"><?php esc_html_e( 'Urgent', 'tsh-whatsapp-notify' ); ?></option>
					</select>
				</div>
				<div class="tsh-wa-crm-form-row">
					<label><?php esc_html_e( 'Due Date', 'tsh-whatsapp-notify' ); ?></label>
					<input type="datetime-local" id="crm-task-due" class="regular-text">
				</div>
				<div class="tsh-wa-crm-form-row">
					<label><?php esc_html_e( 'Assign To', 'tsh-whatsapp-notify' ); ?></label>
					<select id="crm-task-assignee" class="tsh-wa-crm-filter">
						<option value="0"><?php esc_html_e( '— Select user —', 'tsh-whatsapp-notify' ); ?></option>
					</select>
				</div>
				<div class="tsh-wa-crm-form-row">
					<label><?php esc_html_e( 'Status', 'tsh-whatsapp-notify' ); ?></label>
					<select id="crm-task-status" class="tsh-wa-crm-filter">
						<option value="pending"><?php esc_html_e( 'Pending', 'tsh-whatsapp-notify' ); ?></option>
						<option value="in_progress"><?php esc_html_e( 'In Progress', 'tsh-whatsapp-notify' ); ?></option>
						<option value="completed"><?php esc_html_e( 'Completed', 'tsh-whatsapp-notify' ); ?></option>
						<option value="cancelled"><?php esc_html_e( 'Cancelled', 'tsh-whatsapp-notify' ); ?></option>
					</select>
				</div>
			</div>
		</div>
		<div class="tsh-wa-crm-modal-foot">
			<button class="button button-primary" id="crm-btn-save-task"><?php esc_html_e( 'Save Task', 'tsh-whatsapp-notify' ); ?></button>
			<button class="button" id="crm-btn-cancel-task"><?php esc_html_e( 'Cancel', 'tsh-whatsapp-notify' ); ?></button>
		</div>
	</div>
</div>
