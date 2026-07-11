<?php
/**
 * Tools page template.
 *
 * Variables injected by Pages\Tools::render():
 *
 * @var array|null $tool_notice  ['type' => 'success|error', 'message' => '...']
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
				<?php esc_html_e( 'Administrative utilities for maintaining the plugin.', 'tsh-whatsapp-notify' ); ?>
			</p>
		</div>
	</div>

	<?php if ( ! empty( $tool_notice ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( $tool_notice['type'] ); ?> is-dismissible">
			<p><?php echo esc_html( $tool_notice['message'] ); ?></p>
		</div>
	<?php endif; ?>

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
									onclick="return confirm('<?php esc_attr_e( 'Clear the entire queue? This cannot be undone.', 'tsh-whatsapp-notify' ); ?>')">
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
									onclick="return confirm('<?php esc_attr_e( 'Delete all logs? This cannot be undone.', 'tsh-whatsapp-notify' ); ?>')">
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
				__( 'Plugin Version', 'tsh-whatsapp-notify' )      => TSH_WA_VERSION,
				__( 'DB Schema Version', 'tsh-whatsapp-notify' )    => get_option( 'tsh_wa_db_version', '–' ),
				__( 'WordPress Version', 'tsh-whatsapp-notify' )    => $wp_version,
				__( 'WooCommerce Version', 'tsh-whatsapp-notify' )  => defined( 'WC_VERSION' ) ? WC_VERSION : '–',
				__( 'PHP Version', 'tsh-whatsapp-notify' )          => PHP_VERSION,
				__( 'MySQL Version', 'tsh-whatsapp-notify' )        => $wpdb->db_version(),
				__( 'WordPress Memory Limit', 'tsh-whatsapp-notify' ) => WP_MEMORY_LIMIT,
				__( 'Debug Mode', 'tsh-whatsapp-notify' )           => defined( 'WP_DEBUG' ) && WP_DEBUG ? __( 'On', 'tsh-whatsapp-notify' ) : __( 'Off', 'tsh-whatsapp-notify' ),
				__( 'Plugin Path', 'tsh-whatsapp-notify' )          => TSH_WA_PATH,
				__( 'Log Directory', 'tsh-whatsapp-notify' )        => TSH_WA_LOG_DIR,
				__( 'Activation Date', 'tsh-whatsapp-notify' )      => get_option( 'tsh_wa_activation_date', '–' ),
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
