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

use TSH\WhatsAppNotify\Admin\Settings;

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

</div><!-- .tsh-wa-wrap -->
