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

	<?php /* ── Admin Recipients (admin_notifications tab only) ──────────── */ ?>
	<?php if ( 'admin_notifications' === $active_tab ) : ?>

	<?php
	$admin_recipients = get_option( 'tsh_wa_admin_recipients', [] );
	if ( ! is_array( $admin_recipients ) ) {
		$admin_recipients = [];
	}
	?>
	<div class="tsh-wa-panel" style="margin-top:24px;" id="tsh-wa-admin-recipients">
		<div class="tsh-wa-panel__header">
			<h2><?php esc_html_e( 'Admin Notification Numbers', 'tsh-whatsapp-notify' ); ?></h2>
			<span class="tsh-wa-panel__badge">
				<?php
				printf(
					/* translators: %d: number of admin recipients */
					esc_html( _n( '%d recipient', '%d recipients', count( $admin_recipients ), 'tsh-whatsapp-notify' ) ),
					count( $admin_recipients )
				);
				?>
			</span>
		</div>
		<div class="tsh-wa-panel__body">

			<p class="description" style="margin-top:0;">
				<?php esc_html_e( 'These phone numbers receive admin-facing WhatsApp notifications for the events enabled above. Numbers must be in E.164 format (e.g. +2348012345678).', 'tsh-whatsapp-notify' ); ?>
			</p>

			<?php /* Current recipients list */ ?>
			<input type="hidden" id="tsh-wa-recipients-json" value="<?php echo esc_attr( wp_json_encode( array_values( $admin_recipients ) ) ); ?>">

			<ul class="tsh-wa-recipients-list" id="tsh-wa-recipients-list" style="margin-bottom:16px;border:1px solid var(--tsh-wa-border);border-radius:var(--tsh-wa-radius);overflow:hidden;">
				<?php if ( empty( $admin_recipients ) ) : ?>
					<li class="tsh-wa-recipients-empty" style="padding:12px 16px;">
						<?php esc_html_e( 'No admin recipients configured yet.', 'tsh-whatsapp-notify' ); ?>
					</li>
				<?php else : ?>
					<?php foreach ( $admin_recipients as $r ) : ?>
					<li class="tsh-wa-recipients-item">
						<span class="tsh-wa-recipients-item__phone">
							<code><?php echo esc_html( $r['phone'] ?? '' ); ?></code>
						</span>
						<?php if ( ! empty( $r['name'] ) ) : ?>
							<span class="tsh-wa-recipients-item__name"><?php echo esc_html( $r['name'] ); ?></span>
						<?php endif; ?>
						<button type="button"
							class="button button-small tsh-wa-recipient-delete"
							data-id="<?php echo esc_attr( $r['id'] ?? '' ); ?>"
							style="margin-left:auto;"
						>
							<span class="dashicons dashicons-trash" aria-hidden="true" style="vertical-align:middle;margin-top:-2px;font-size:14px;width:14px;height:14px;"></span>
							<?php esc_html_e( 'Remove', 'tsh-whatsapp-notify' ); ?>
						</button>
					</li>
					<?php endforeach; ?>
				<?php endif; ?>
			</ul>

			<?php /* Add new recipient form */ ?>
			<div class="tsh-wa-recipients-add-row">
				<div>
					<label for="tsh-wa-recipient-phone"><?php esc_html_e( 'Phone Number', 'tsh-whatsapp-notify' ); ?></label><br>
					<input type="text"
						id="tsh-wa-recipient-phone"
						class="regular-text"
						placeholder="+2348012345678"
						style="margin-top:4px;"
					>
				</div>
				<div>
					<label for="tsh-wa-recipient-name"><?php esc_html_e( 'Label (optional)', 'tsh-whatsapp-notify' ); ?></label><br>
					<input type="text"
						id="tsh-wa-recipient-name"
						class="regular-text"
						placeholder="<?php esc_attr_e( 'e.g. Store Manager', 'tsh-whatsapp-notify' ); ?>"
						style="margin-top:4px;"
					>
				</div>
				<div style="padding-top:22px;">
					<button type="button" id="tsh-wa-btn-add-recipient" class="button button-primary">
						<span class="dashicons dashicons-plus-alt2" aria-hidden="true" style="vertical-align:middle;margin-top:-2px;"></span>
						<?php esc_html_e( 'Add Recipient', 'tsh-whatsapp-notify' ); ?>
					</button>
				</div>
			</div>

			<div id="tsh-wa-recipients-result" class="tsh-wa-ajax-result" style="display:none;"></div>

		</div>
	</div>

	<?php endif; ?>

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
