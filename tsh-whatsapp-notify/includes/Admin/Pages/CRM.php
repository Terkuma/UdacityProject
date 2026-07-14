<?php
/**
 * CRM admin page controller.
 *
 * @package TSH\WhatsAppNotify\Admin\Pages
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Admin\Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TSH\WhatsAppNotify\CRM\CustomerAnalytics;
use TSH\WhatsAppNotify\CRM\CustomerLifecycle;
use TSH\WhatsAppNotify\CRM\CustomerRepository;
use TSH\WhatsAppNotify\CRM\CustomerSegments;
use TSH\WhatsAppNotify\CRM\CustomerSettings;
use TSH\WhatsAppNotify\CRM\CustomerTags;
use TSH\WhatsAppNotify\CRM\CustomerTasks;
use TSH\WhatsAppNotify\CRM\CustomerActivity;

/**
 * Class CRM
 *
 * Renders the Customer CRM admin page and injects server-side seed data.
 */
final class CRM {

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'tsh-whatsapp-notify' ) );
		}

		$repo      = new CustomerRepository();
		$analytics = new CustomerAnalytics( $repo );
		$segments  = new CustomerSegments( $repo );
		$tags_cls  = new CustomerTags( $repo, new CustomerActivity( $repo ) );
		$tasks_cls = new CustomerTasks( $repo, new CustomerActivity( $repo ) );

		// Seed data for the JS layer
		$seed = [
			'dashboard'        => $analytics->get_dashboard( 30 ),
			'segments'         => $segments->list(),
			'segment_presets'  => $segments->get_presets(),
			'rule_fields'      => $segments->get_rule_fields(),
			'tags'             => $tags_cls->list(),
			'lifecycle_labels' => CustomerLifecycle::labels(),
			'lifecycle_all'    => CustomerLifecycle::ALL,
			'overdue_tasks'    => $tasks_cls->get_overdue(),
			'settings'         => CustomerSettings::get(),
		];

		wp_localize_script( 'tsh-wa-crm', 'tshWaCRMSeed', $seed );

		include TSH_WA_PATH . 'templates/admin/crm.php';
	}
}
