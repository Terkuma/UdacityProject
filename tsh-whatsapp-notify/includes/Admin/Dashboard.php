<?php
/**
 * Dashboard admin page controller.
 *
 * @package TSH\WhatsAppNotify\Admin
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TSH\WhatsAppNotify\API\HealthMonitor;
use TSH\WhatsAppNotify\Database\Installer;
use TSH\WhatsAppNotify\Helpers\Helpers;
use TSH\WhatsAppNotify\Logger\Logger;
use TSH\WhatsAppNotify\Queue\Queue;

/**
 * Class Dashboard
 *
 * Renders the plugin dashboard page with summary cards and recent activity.
 */
final class Dashboard {

	/**
	 * Render the dashboard page.
	 * Called by Menu when WordPress resolves the page slug.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'tsh-whatsapp-notify' ) );
		}

		$data = $this->build_template_data();
		$this->load_template( $data );
	}

	// -------------------------------------------------------------------------
	// Data assembly
	// -------------------------------------------------------------------------

	/**
	 * Collect all data required by the dashboard template.
	 *
	 * @return array<string, mixed>
	 */
	private function build_template_data(): array {
		$logger = new Logger();
		$queue  = new Queue();

		$queue_counts = $queue->count();
		$log_counts   = $logger->get_counts_by_level();

		$recent_logs_result = $logger->get_logs( [
			'per_page' => 10,
			'page'     => 1,
			'orderby'  => 'created_at',
			'order'    => 'DESC',
		] );

		// WhatsApp Cloud API health status (cached — no live API call on page load).
		$health_monitor = new HealthMonitor();
		$api_health     = $health_monitor->get_dashboard_status();

		return [
			// Plugin meta.
			'plugin_version'  => TSH_WA_VERSION,
			'db_version'      => get_option( 'tsh_wa_db_version', '–' ),
			'db_up_to_date'   => version_compare(
				(string) get_option( 'tsh_wa_db_version', '0' ),
				Installer::DB_VERSION,
				'>='
			),
			'activation_date' => get_option( 'tsh_wa_activation_date', '–' ),

			// WooCommerce.
			'woocommerce_active'  => class_exists( 'WooCommerce' ),
			'woocommerce_version' => defined( 'WC_VERSION' ) ? WC_VERSION : '–',

			// WhatsApp API — now powered by HealthMonitor cache.
			'whatsapp_status'    => ( true === $api_health['success'] ) ? 'connected' : 'not_configured',
			'whatsapp_test_mode' => Helpers::is_test_mode(),

			// API health detail (Phase 2).
			'api_health'     => $api_health,
			'url_tools'      => admin_url( 'admin.php?page=tsh-whatsapp-notify-tools' ),
			'url_settings_api' => admin_url( 'admin.php?page=tsh-whatsapp-notify-settings&tab=api' ),

			// Queue summary.
			'queue_pending'    => $queue_counts[ Queue::STATUS_PENDING ]    ?? 0,
			'queue_processing' => $queue_counts[ Queue::STATUS_PROCESSING ] ?? 0,
			'queue_sent'       => $queue_counts[ Queue::STATUS_SENT ]       ?? 0,
			'queue_failed'     => $queue_counts[ Queue::STATUS_FAILED ]     ?? 0,
			'queue_total'      => array_sum( $queue_counts ),

			// Log summary.
			'messages_sent_today'   => $logger->count_today( Logger::LEVEL_SUCCESS ),
			'messages_failed_today' => $logger->count_today( Logger::LEVEL_ERROR ),
			'log_error_count'       => $log_counts[ Logger::LEVEL_ERROR ]   ?? 0,
			'log_warning_count'     => $log_counts[ Logger::LEVEL_WARNING ] ?? 0,

			// Recent logs.
			'recent_logs' => $recent_logs_result['rows'],

			// System health checks.
			'system_health' => $this->get_system_health(),

			// Navigation links.
			'url_settings' => admin_url( 'admin.php?page=tsh-whatsapp-notify-settings' ),
			'url_queue'    => admin_url( 'admin.php?page=tsh-whatsapp-notify-queue' ),
			'url_logs'     => admin_url( 'admin.php?page=tsh-whatsapp-notify-logs' ),
		];
	}

	/**
	 * Run a set of system health checks for the dashboard panel.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function get_system_health(): array {
		$checks = [];

		// PHP version.
		$checks['php_version'] = [
			'label'  => __( 'PHP Version', 'tsh-whatsapp-notify' ),
			'value'  => PHP_VERSION,
			'status' => version_compare( PHP_VERSION, '8.1', '>=' ) ? 'ok' : 'error',
			'note'   => __( 'PHP 8.1+ required', 'tsh-whatsapp-notify' ),
		];

		// WordPress version.
		global $wp_version;
		$checks['wp_version'] = [
			'label'  => __( 'WordPress Version', 'tsh-whatsapp-notify' ),
			'value'  => $wp_version,
			'status' => version_compare( $wp_version, '6.3', '>=' ) ? 'ok' : 'warning',
			'note'   => __( 'WordPress 6.3+ recommended', 'tsh-whatsapp-notify' ),
		];

		// WooCommerce version.
		$wc_version = defined( 'WC_VERSION' ) ? WC_VERSION : null;
		$checks['wc_version'] = [
			'label'  => __( 'WooCommerce Version', 'tsh-whatsapp-notify' ),
			'value'  => $wc_version ?? __( 'Not active', 'tsh-whatsapp-notify' ),
			'status' => $wc_version ? 'ok' : 'error',
			'note'   => __( 'WooCommerce 7.0+ required', 'tsh-whatsapp-notify' ),
		];

		// Logs directory writable.
		$log_dir_ok = is_dir( TSH_WA_LOG_DIR ) && is_writable( TSH_WA_LOG_DIR );
		$checks['log_dir'] = [
			'label'  => __( 'Log Directory', 'tsh-whatsapp-notify' ),
			'value'  => $log_dir_ok
				? __( 'Writable', 'tsh-whatsapp-notify' )
				: __( 'Not writable', 'tsh-whatsapp-notify' ),
			'status' => $log_dir_ok ? 'ok' : 'warning',
			'note'   => TSH_WA_LOG_DIR,
		];

		// WP-Cron enabled.
		$cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$checks['wp_cron'] = [
			'label'  => __( 'WP-Cron', 'tsh-whatsapp-notify' ),
			'value'  => $cron_disabled
				? __( 'Disabled', 'tsh-whatsapp-notify' )
				: __( 'Enabled', 'tsh-whatsapp-notify' ),
			'status' => $cron_disabled ? 'warning' : 'ok',
			'note'   => $cron_disabled
				? __( 'DISABLE_WP_CRON is true. Set up a server cron for reliable queues.', 'tsh-whatsapp-notify' )
				: '',
		];

		// HTTPS.
		$is_https = is_ssl();
		$checks['https'] = [
			'label'  => __( 'HTTPS', 'tsh-whatsapp-notify' ),
			'value'  => $is_https
				? __( 'Active', 'tsh-whatsapp-notify' )
				: __( 'Not active', 'tsh-whatsapp-notify' ),
			'status' => $is_https ? 'ok' : 'warning',
			'note'   => $is_https ? '' : __( 'HTTPS is required for Meta Webhook verification.', 'tsh-whatsapp-notify' ),
		];

		// API configured.
		$api_ready = Helpers::is_plugin_ready();
		$checks['api_configured'] = [
			'label'  => __( 'WhatsApp API', 'tsh-whatsapp-notify' ),
			'value'  => $api_ready
				? __( 'Configured', 'tsh-whatsapp-notify' )
				: __( 'Not configured', 'tsh-whatsapp-notify' ),
			'status' => $api_ready ? 'ok' : 'warning',
			'note'   => $api_ready ? '' : __( 'Enter your Meta credentials in Settings → WhatsApp API.', 'tsh-whatsapp-notify' ),
		];

		return $checks;
	}

	// -------------------------------------------------------------------------
	// Template loader
	// -------------------------------------------------------------------------

	/**
	 * Load the dashboard template, injecting data as scoped variables.
	 *
	 * @param array<string, mixed> $data Template variables.
	 */
	private function load_template( array $data ): void {
		$template = TSH_WA_PATH . 'templates/admin/dashboard.php';

		if ( ! file_exists( $template ) ) {
			echo '<div class="notice notice-error"><p>' .
				esc_html__( 'Dashboard template not found.', 'tsh-whatsapp-notify' ) .
				'</p></div>';
			return;
		}

		// Extract $data into local scope for the template.
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		extract( $data, EXTR_SKIP );

		include $template;
	}
}
