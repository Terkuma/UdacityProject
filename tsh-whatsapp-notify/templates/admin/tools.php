<?php
/**
 * Tools page template.
 *
 * Variables injected by Pages\Tools::render():
 *
 * @var array|null $tool_notice  ['type' => 'success|error', 'message' => '...']
 * @var string     $test_phone   Default test phone from API settings.
 * @var bool       $is_debug     Whether debug mode is active.
 *
 * @package TSH\WhatsAppNotify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap tsh-wa-wrap">

	<div class="tsh-wa-page-header">
		<div class="tsh-wa-page-header__inner">
			<h1><?php esc_html_e( 'Tools', 'tsh-whatsapp-notify' ); ?></h1>
			<p class="tsh-wa-page-header__subtitle">
				<?php esc_html_e( 'Administrative utilities, diagnostics, and message sandbox.', 'tsh-whatsapp-notify' ); ?>
			</p>
		</div>
	</div>

	<?php if ( ! empty( $tool_notice ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( $tool_notice['type'] ); ?> is-dismissible">
			<p><?php echo esc_html( $tool_notice['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<?php /* ── API Diagnostics ─────────────────────────────────────────── */ ?>
	<div class="tsh-wa-panel">
		<div class="tsh-wa-panel__header">
			<h2><?php esc_html_e( 'API Diagnostics', 'tsh-whatsapp-notify' ); ?></h2>
		</div>
		<div class="tsh-wa-panel__body">
			<p class="description"><?php esc_html_e( 'Run a comprehensive check of all system components, credentials, and API connectivity. Results include PHP, OpenSSL, cURL, WP-Cron, database tables, and a live Meta API ping.', 'tsh-whatsapp-notify' ); ?></p>

			<p>
				<button type="button" id="tsh-wa-btn-diagnostics" class="button button-primary">
					<span class="dashicons dashicons-search" style="vertical-align:middle;margin-top:-2px;"></span>
					<?php esc_html_e( 'Run Diagnostics', 'tsh-whatsapp-notify' ); ?>
				</button>
				<span id="tsh-wa-diag-spinner" class="spinner" style="float:none;"></span>
				<button type="button" id="tsh-wa-btn-download-report" class="button" style="display:none;">
					<span class="dashicons dashicons-download" style="vertical-align:middle;margin-top:-2px;"></span>
					<?php esc_html_e( 'Download Report', 'tsh-whatsapp-notify' ); ?>
				</button>
			</p>

			<div id="tsh-wa-diag-result" style="display:none;">
				<div class="tsh-wa-diag-grid" id="tsh-wa-diag-grid"></div>
			</div>
		</div>
	</div>

	<?php /* ── Message Sandbox ──────────────────────────────────────────── */ ?>
	<div class="tsh-wa-panel">
		<div class="tsh-wa-panel__header">
			<h2><?php esc_html_e( 'Message Sandbox', 'tsh-whatsapp-notify' ); ?></h2>
		</div>
		<div class="tsh-wa-panel__body">
			<p class="description"><?php esc_html_e( 'Send a test WhatsApp message directly from this page. Useful for verifying API credentials and message delivery before enabling WooCommerce notifications.', 'tsh-whatsapp-notify' ); ?></p>

			<table class="form-table" style="max-width:600px;">
				<tr>
					<th scope="row">
						<label for="tsh-wa-sandbox-phone"><?php esc_html_e( 'Recipient Number', 'tsh-whatsapp-notify' ); ?></label>
					</th>
					<td>
						<input type="text" id="tsh-wa-sandbox-phone" class="regular-text"
							value="<?php echo esc_attr( $test_phone ); ?>"
							placeholder="+2348012345678">
						<p class="description"><?php esc_html_e( 'E.164 format required.', 'tsh-whatsapp-notify' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="tsh-wa-sandbox-message"><?php esc_html_e( 'Message', 'tsh-whatsapp-notify' ); ?></label>
					</th>
					<td>
						<textarea id="tsh-wa-sandbox-message" class="large-text" rows="4"
							placeholder="<?php esc_attr_e( 'Type your test message here…', 'tsh-whatsapp-notify' ); ?>"><?php echo esc_textarea( __( 'Hello! This is a test message from TSH WhatsApp Notify.', 'tsh-whatsapp-notify' ) ); ?></textarea>
						<p class="description" id="tsh-wa-sandbox-char-count">0 / 4096</p>
					</td>
				</tr>
			</table>

			<p>
				<button type="button" id="tsh-wa-btn-sandbox-send" class="button button-primary">
					<span class="dashicons dashicons-email-alt" style="vertical-align:middle;margin-top:-2px;"></span>
					<?php esc_html_e( 'Send Message', 'tsh-whatsapp-notify' ); ?>
				</button>
				<span id="tsh-wa-sandbox-spinner" class="spinner" style="float:none;"></span>
			</p>

			<div id="tsh-wa-sandbox-result" class="tsh-wa-ajax-result" style="display:none;"></div>

			<?php if ( $is_debug ) : ?>
			<div id="tsh-wa-sandbox-json" class="tsh-wa-code-block" style="display:none;">
				<strong><?php esc_html_e( 'API Response (Debug Mode)', 'tsh-whatsapp-notify' ); ?></strong>
				<pre id="tsh-wa-sandbox-json-body"></pre>
			</div>
			<?php endif; ?>
		</div>
	</div>

	<?php /* ── WooCommerce tools ─────────────────────────────────────────── */ ?>
	<div class="tsh-wa-panel">
		<div class="tsh-wa-panel__header">
			<h2><?php esc_html_e( 'WooCommerce Integration', 'tsh-whatsapp-notify' ); ?></h2>
		</div>
		<div class="tsh-wa-panel__body">
			<table class="tsh-wa-tools-table">
				<tbody>
					<tr>
						<td>
							<strong><?php esc_html_e( 'Clear Order Notification Log', 'tsh-whatsapp-notify' ); ?></strong>
							<p class="description">
								<?php esc_html_e( 'Delete all records from the order notifications log table (tsh_wa_notifications). Queue items are not affected. Cannot be undone.', 'tsh-whatsapp-notify' ); ?>
							</p>
						</td>
						<td>
							<form method="post">
								<?php wp_nonce_field( 'tsh_wa_tools_action', 'tsh_wa_tools_nonce' ); ?>
								<button type="submit" name="tsh_wa_tool" value="clear_notifications" class="button"
									data-tsh-wa-confirm="<?php esc_attr_e( 'Delete all order notification records? This cannot be undone.', 'tsh-whatsapp-notify' ); ?>">
									<?php esc_html_e( 'Clear Notification Log', 'tsh-whatsapp-notify' ); ?>
								</button>
							</form>
						</td>
					</tr>
					<tr>
						<td>
							<strong><?php esc_html_e( 'Retry Failed Order Notifications', 'tsh-whatsapp-notify' ); ?></strong>
							<p class="description">
								<?php esc_html_e( 'Requeue all failed order notification queue items for another delivery attempt.', 'tsh-whatsapp-notify' ); ?>
							</p>
						</td>
						<td>
							<form method="post">
								<?php wp_nonce_field( 'tsh_wa_tools_action', 'tsh_wa_tools_nonce' ); ?>
								<button type="submit" name="tsh_wa_tool" value="retry_failed_queue" class="button button-primary">
									<?php esc_html_e( 'Retry Failed', 'tsh-whatsapp-notify' ); ?>
								</button>
							</form>
						</td>
					</tr>
					<tr>
						<td>
							<strong><?php esc_html_e( 'Verify WooCommerce Hooks', 'tsh-whatsapp-notify' ); ?></strong>
							<p class="description">
								<?php esc_html_e( 'Confirms that all WooCommerce order lifecycle hooks are correctly registered by this plugin.', 'tsh-whatsapp-notify' ); ?>
							</p>
						</td>
						<td>
							<form method="post">
								<?php wp_nonce_field( 'tsh_wa_tools_action', 'tsh_wa_tools_nonce' ); ?>
								<button type="submit" name="tsh_wa_tool" value="verify_wc_hooks" class="button">
									<?php esc_html_e( 'Verify Hooks', 'tsh-whatsapp-notify' ); ?>
								</button>
							</form>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
	</div>

	<?php /* ── Database tools ────────────────────────────────────────────── */ ?>
	<div class="tsh-wa-panel">
		<div class="tsh-wa-panel__header">
			<h2><?php esc_html_e( 'Database', 'tsh-whatsapp-notify' ); ?></h2>
		</div>
		<div class="tsh-wa-panel__body">
			<table class="tsh-wa-tools-table">
				<tbody>
					<tr>
						<td>
							<strong><?php esc_html_e( 'Repair / Verify Tables', 'tsh-whatsapp-notify' ); ?></strong>
							<p class="description"><?php esc_html_e( 'Re-runs the database installer. Safely creates any missing tables without dropping existing data.', 'tsh-whatsapp-notify' ); ?></p>
						</td>
						<td>
							<form method="post">
								<?php wp_nonce_field( 'tsh_wa_tools_action', 'tsh_wa_tools_nonce' ); ?>
								<button type="submit" name="tsh_wa_tool" value="repair_db" class="button">
									<?php esc_html_e( 'Repair Database', 'tsh-whatsapp-notify' ); ?>
								</button>
							</form>
						</td>
					</tr>
					<tr>
						<td>
							<strong><?php esc_html_e( 'Clear API Request Log', 'tsh-whatsapp-notify' ); ?></strong>
							<p class="description"><?php esc_html_e( 'Delete all entries from the API requests log table.', 'tsh-whatsapp-notify' ); ?></p>
						</td>
						<td>
							<form method="post">
								<?php wp_nonce_field( 'tsh_wa_tools_action', 'tsh_wa_tools_nonce' ); ?>
								<button type="submit" name="tsh_wa_tool" value="clear_api_requests" class="button"
									data-tsh-wa-confirm="<?php esc_attr_e( 'Clear all API request logs?', 'tsh-whatsapp-notify' ); ?>">
									<?php esc_html_e( 'Clear API Log', 'tsh-whatsapp-notify' ); ?>
								</button>
							</form>
						</td>
					</tr>
					<tr>
						<td>
							<strong><?php esc_html_e( 'Bust Health Status Cache', 'tsh-whatsapp-notify' ); ?></strong>
							<p class="description"><?php esc_html_e( 'Clears the cached API health status so the next dashboard load triggers a fresh check.', 'tsh-whatsapp-notify' ); ?></p>
						</td>
						<td>
							<form method="post">
								<?php wp_nonce_field( 'tsh_wa_tools_action', 'tsh_wa_tools_nonce' ); ?>
								<button type="submit" name="tsh_wa_tool" value="bust_health_cache" class="button">
									<?php esc_html_e( 'Bust Cache', 'tsh-whatsapp-notify' ); ?>
								</button>
							</form>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
	</div>

	<?php /* ── Queue tools ─────────────────────────────────────────────── */ ?>
	<div class="tsh-wa-panel">
		<div class="tsh-wa-panel__header">
			<h2><?php esc_html_e( 'Queue', 'tsh-whatsapp-notify' ); ?></h2>
		</div>
		<div class="tsh-wa-panel__body">
			<table class="tsh-wa-tools-table">
				<tbody>
					<tr>
						<td>
							<strong><?php esc_html_e( 'Clear Entire Queue', 'tsh-whatsapp-notify' ); ?></strong>
							<p class="description"><?php esc_html_e( 'Permanently removes all items from the queue. Cannot be undone.', 'tsh-whatsapp-notify' ); ?></p>
						</td>
						<td>
							<form method="post">
								<?php wp_nonce_field( 'tsh_wa_tools_action', 'tsh_wa_tools_nonce' ); ?>
								<button type="submit" name="tsh_wa_tool" value="clear_queue" class="button"
									data-tsh-wa-confirm="<?php esc_attr_e( 'Clear the entire queue? This cannot be undone.', 'tsh-whatsapp-notify' ); ?>">
									<?php esc_html_e( 'Clear Queue', 'tsh-whatsapp-notify' ); ?>
								</button>
							</form>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
	</div>

	<?php /* ── Log tools ─────────────────────────────────────────────── */ ?>
	<div class="tsh-wa-panel">
		<div class="tsh-wa-panel__header">
			<h2><?php esc_html_e( 'Logs', 'tsh-whatsapp-notify' ); ?></h2>
		</div>
		<div class="tsh-wa-panel__body">
			<table class="tsh-wa-tools-table">
				<tbody>
					<tr>
						<td>
							<strong><?php esc_html_e( 'Clear All Logs', 'tsh-whatsapp-notify' ); ?></strong>
							<p class="description"><?php esc_html_e( 'Permanently deletes all log entries from the database. Cannot be undone.', 'tsh-whatsapp-notify' ); ?></p>
						</td>
						<td>
							<form method="post">
								<?php wp_nonce_field( 'tsh_wa_tools_action', 'tsh_wa_tools_nonce' ); ?>
								<button type="submit" name="tsh_wa_tool" value="clear_logs" class="button"
									data-tsh-wa-confirm="<?php esc_attr_e( 'Delete all logs? This cannot be undone.', 'tsh-whatsapp-notify' ); ?>">
									<?php esc_html_e( 'Clear Logs', 'tsh-whatsapp-notify' ); ?>
								</button>
							</form>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
	</div>

	<?php /* ── System info ─────────────────────────────────────────────── */ ?>
	<div class="tsh-wa-panel">
		<div class="tsh-wa-panel__header">
			<h2><?php esc_html_e( 'System Information', 'tsh-whatsapp-notify' ); ?></h2>
		</div>
		<div class="tsh-wa-panel__body">
			<?php
			global $wpdb, $wp_version;
			$info = [
				__( 'Plugin Version', 'tsh-whatsapp-notify' )        => TSH_WA_VERSION,
				__( 'DB Schema Version', 'tsh-whatsapp-notify' )      => get_option( 'tsh_wa_db_version', '–' ),
				__( 'WordPress Version', 'tsh-whatsapp-notify' )      => $wp_version,
				__( 'WooCommerce Version', 'tsh-whatsapp-notify' )    => defined( 'WC_VERSION' ) ? WC_VERSION : '–',
				__( 'PHP Version', 'tsh-whatsapp-notify' )            => PHP_VERSION,
				__( 'MySQL Version', 'tsh-whatsapp-notify' )          => $wpdb->db_version(),
				__( 'OpenSSL', 'tsh-whatsapp-notify' )                => extension_loaded( 'openssl' ) && defined( 'OPENSSL_VERSION_TEXT' ) ? OPENSSL_VERSION_TEXT : __( 'Not available', 'tsh-whatsapp-notify' ),
				__( 'cURL', 'tsh-whatsapp-notify' )                   => extension_loaded( 'curl' ) ? ( function_exists( 'curl_version' ) ? ( curl_version()['version'] ?? 'Available' ) : 'Available' ) : __( 'Not available', 'tsh-whatsapp-notify' ),
				__( 'WordPress Memory Limit', 'tsh-whatsapp-notify' ) => WP_MEMORY_LIMIT,
				__( 'WP-Cron', 'tsh-whatsapp-notify' )                => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? __( 'Disabled', 'tsh-whatsapp-notify' ) : __( 'Enabled', 'tsh-whatsapp-notify' ),
				__( 'Debug Mode', 'tsh-whatsapp-notify' )             => defined( 'WP_DEBUG' ) && WP_DEBUG ? __( 'On', 'tsh-whatsapp-notify' ) : __( 'Off', 'tsh-whatsapp-notify' ),
				__( 'Plugin Path', 'tsh-whatsapp-notify' )            => TSH_WA_PATH,
				__( 'Log Directory', 'tsh-whatsapp-notify' )          => TSH_WA_LOG_DIR,
				__( 'Activation Date', 'tsh-whatsapp-notify' )        => get_option( 'tsh_wa_activation_date', '–' ),
			];
			?>
			<table class="tsh-wa-sysinfo-table widefat">
				<tbody>
					<?php foreach ( $info as $label => $value ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $label ); ?></strong></td>
							<td><?php echo esc_html( $value ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>

</div>
