<?php
/**
 * Automation admin page controller.
 *
 * @package TSH\WhatsAppNotify\Admin\Pages
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Admin\Pages;

use TSH\WhatsAppNotify\Automation\AutomationEngine;
use TSH\WhatsAppNotify\Automation\AutomationAnalytics;
use TSH\WhatsAppNotify\Automation\TriggerManager;
use TSH\WhatsAppNotify\Automation\ActionManager;
use TSH\WhatsAppNotify\Automation\ConditionManager;
use TSH\WhatsAppNotify\Automation\VariableResolver;
use TSH\WhatsAppNotify\Automation\WorkflowExporter;
use TSH\WhatsAppNotify\Automation\WorkflowRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Automation
 *
 * Renders the Automation admin page (workflow list + builder).
 */
class Automation {

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'tsh-whatsapp-notify' ) );
		}

		$repo      = new WorkflowRepository();
		$analytics = new AutomationAnalytics();

		$overview = $analytics->get_overview( 30 );

		$workflows_data = $repo->get_workflows( [
			'status'   => 'all',
			'per_page' => 50,
			'page'     => 1,
			'orderby'  => 'updated_at',
		] );

		$templates = WorkflowExporter::get_templates();
		$triggers  = TriggerManager::get_triggers();
		$actions   = ActionManager::get_actions();
		$conditions= ConditionManager::get_conditions();
		$variables = VariableResolver::get_available_variables();

		// Build agents list for assignment action.
		$agents_query = get_users( [ 'role__in' => [ 'administrator', 'shop_manager', 'editor' ], 'number' => 50 ] );
		$agents = array_map( fn( \WP_User $u ) => [
			'id'   => $u->ID,
			'name' => $u->display_name ?: $u->user_login,
		], $agents_query );

		// Build WC order statuses for condition dropdowns.
		$order_statuses = function_exists( 'wc_get_order_statuses' )
			? array_map( fn( $s ) => ltrim( $s, 'wc-' ), array_keys( wc_get_order_statuses() ) )
			: [ 'pending', 'processing', 'completed', 'cancelled', 'refunded', 'failed', 'on-hold' ];

		$settings = get_option( 'tsh_wa_automation_settings', [] );

		// Pass everything to the template via include.
		include TSH_WA_PLUGIN_DIR . 'templates/admin/automation.php';
	}
}
