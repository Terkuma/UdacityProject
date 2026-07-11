<?php
/**
 * Settings page template.
 *
 * Variables available (from Settings::render()):
 *   $active_tab  — currently active tab slug
 *   $option_key  — wp_options key for the active tab
 *
 * @package TSH\WhatsAppNotify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TSH\WhatsAppNotify\Admin\Ajax;
use TSH\WhatsAppNotify\Admin\Settings;
use TSH\WhatsAppNotify\API\TokenManager;

$tabs       = Settings::get_tabs();
$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
if ( ! array_key_exists( $active_tab, $tabs ) ) {
	$active_tab = 'general';
}
$option_key = Settings::get_option_key( $active_tab );
?>
<div class="wrap tsh-wa-wrap">

	<?php /* ── Page header ──────────────────────────────────────────────── */ ?>
	<div class="tsh-wa-page-header">
		<div class="tsh-wa-page-header__inner">
			<h1><?php esc_html_e( 'Settings', 'tsh-whatsapp-notify' ); ?></h1>
		</div>
		<?php if ( 'api' === $active_tab ) : ?>
		<div class="tsh-wa-page-header__actions">
			<button type="button" id="tsh-wa-btn-verify" class="button button-primary">
				<span class="dashicons dashicons-admin-network" style="vertical-align:middle;margin-top:-2px;"></span>
				<?php esc_html_e( 'Verify Connection', 'tsh-whatsapp-notify' ); ?>
			</button>
			<button type="button" id="tsh-wa-btn-refresh-health" class="button">
				<span class="dashicons dashicons-update" style="vertical-align:middle;margin-top:-2px;"></span>
				<?php esc_html_e( 'Refresh Status', 'tsh-whatsapp-notify' ); ?>
			</button>
			<button type="button" id="tsh-wa-btn-export" class="button">
				<?php esc_html_e( 'Export Settings', 'tsh-whatsapp-notify' ); ?>
			</button>
			<button type="button" id="tsh-wa-btn-reset" class="button"
				data-tsh-wa-confirm="<?php esc_attr_e( 'Reset all API settings to defaults? The access token will be cleared. This cannot be undone.', 'tsh-whatsapp-notify' ); ?>">
				<?php esc_html_e( 'Reset to Defaults', 'tsh-whatsapp-notify' ); ?>
			</button>
		</div>
		<?php endif; ?>
	</div>

	<?php settings_errors( 'tsh_wa_messages' ); ?>

	<?php /* ── Tab navigation ───────────────────────────────────────────── */ ?>
	<nav class="tsh-wa-tab-nav" aria-label="<?php esc_attr_e( 'Settings tabs', 'tsh-whatsapp-notify' ); ?>">
		<?php foreach ( $tabs as $slug => $label ) : ?>
			<a
				href="<?php echo esc_url( admin_url( 'admin.php?page=tsh-whatsapp-notify-settings&tab=' . $slug ) ); ?>"
				class="tsh-wa-tab-nav__item <?php echo $active_tab === $slug ? 'tsh-wa-tab-nav__item--active' : ''; ?>"
				aria-current="<?php echo $active_tab === $slug ? 'page' : 'false'; ?>"
			>
				<?php echo esc_html( __( $label, 'tsh-whatsapp-notify' ) ); ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<?php /* ── Settings form ────────────────────────────────────────────── */ ?>
	<form method="post" action="options.php" class="tsh-wa-settings-form">
		<?php
		settings_fields( $option_key );
		do_settings_sections( $option_key );
		submit_button( __( 'Save Settings', 'tsh-whatsapp-notify' ) );
		?>
	</form>

	<?php /* ── Connection Tester (API tab only) ──────────────────────── */ ?>
	<?php if ( 'api' === $active_tab ) : ?>

	<div class="tsh-wa-panel tsh-wa-connection-tester" id="tsh-wa-connection-panel">
		<div class="tsh-wa-panel__header">
			<h2><?php esc_html_e( 'Connection Tester', 'tsh-whatsapp-notify' ); ?></h2>
			<span class="tsh-wa-panel__badge" id="tsh-wa-conn-status-badge"><?php esc_html_e( 'Not tested', 'tsh-whatsapp-notify' ); ?></span>
		</div>
		<div class="tsh-wa-panel__body">

			<div id="tsh-wa-verify-result" class="tsh-wa-ajax-result" style="display:none;"></div>

			<?php /* Connection steps */ ?>
			<div id="tsh-wa-verify-steps" style="display:none;">
				<h3 style="margin-top:0;"><?php esc_html_e( 'Verification Steps', 'tsh-whatsapp-notify' ); ?></h3>
				<ul class="tsh-wa-health-list" id="tsh-wa-steps-list"></ul>
				<div id="tsh-wa-conn-info" class="tsh-wa-conn-info" style="display:none;"></div>
			</div>

			<hr style="margin:20px 0;">

			<?php /* Send test message */ ?>
			<h3 style="margin-top:0;"><?php esc_html_e( 'Send Test Message', 'tsh-whatsapp-notify' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Send a real WhatsApp message to verify end-to-end delivery.', 'tsh-whatsapp-notify' ); ?></p>

			<?php
			$api_settings = get_option( 'tsh_wa_api_settings', [] );
			$test_phone   = sanitize_text_field( $api_settings['test_phone_number'] ?? '' );
			?>

			<table class="form-table" style="margin-top:12px;">
				<tr>
					<th scope="row">
						<label for="tsh-wa-test-phone"><?php esc_html_e( 'Recipient Phone', 'tsh-whatsapp-notify' ); ?></label>
					</th>
					<td>
						<input type="text" id="tsh-wa-test-phone" class="regular-text"
							value="<?php echo esc_attr( $test_phone ); ?>"
							placeholder="+2348012345678">
						<p class="description"><?php esc_html_e( 'E.164 format — e.g. +2348012345678', 'tsh-whatsapp-notify' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="tsh-wa-test-message"><?php esc_html_e( 'Message', 'tsh-whatsapp-notify' ); ?></label>
					</th>
					<td>
						<textarea id="tsh-wa-test-message" class="large-text" rows="3" placeholder="<?php esc_attr_e( 'Hello from TSH WhatsApp Notify! This is a test message.', 'tsh-whatsapp-notify' ); ?>"><?php echo esc_textarea( __( 'Hello from TSH WhatsApp Notify! 👋 This is a test message sent from your WordPress store.', 'tsh-whatsapp-notify' ) ); ?></textarea>
					</td>
				</tr>
			</table>

			<p>
				<button type="button" id="tsh-wa-btn-send-test" class="button button-primary">
					<span class="dashicons dashicons-email-alt" style="vertical-align:middle;margin-top:-2px;"></span>
					<?php esc_html_e( 'Send Test Message', 'tsh-whatsapp-notify' ); ?>
				</button>
				<span id="tsh-wa-send-spinner" class="spinner" style="float:none;"></span>
			</p>

			<div id="tsh-wa-send-result" class="tsh-wa-ajax-result" style="display:none;"></div>

		</div>
	</div>

	<?php endif; ?>

</div><!-- .tsh-wa-wrap -->
