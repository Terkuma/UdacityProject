<?php
/**
 * Marketing admin page controller.
 *
 * @package TSH\WhatsAppNotify\Admin\Pages
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Admin\Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TSH\WhatsAppNotify\Marketing\CampaignAnalytics;
use TSH\WhatsAppNotify\Marketing\CampaignRepository;
use TSH\WhatsAppNotify\Marketing\CampaignTemplates;
use TSH\WhatsAppNotify\Marketing\AudienceBuilder;
use TSH\WhatsAppNotify\Marketing\SegmentEngine;
use TSH\WhatsAppNotify\Templates\TemplateRepository;

/**
 * Class Marketing
 *
 * Renders the Marketing & Broadcast Engine admin page and provides
 * server-side data for the JS layer.
 */
final class Marketing {

	/**
	 * Render the Marketing admin page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'tsh-whatsapp-notify' ) );
		}

		$repo         = new CampaignRepository();
		$analytics    = new CampaignAnalytics( $repo );
		$segment_eng  = new SegmentEngine();
		$audience     = new AudienceBuilder( $segment_eng );
		$tpl_presets  = new CampaignTemplates();

		// Dashboard stats for the overview cards.
		$dashboard_stats  = $analytics->get_dashboard_stats( 30 );
		$campaign_result  = $repo->get_campaigns( [ 'per_page' => 10, 'order' => 'DESC' ] );
		$saved_segments   = $audience->get_all_segments();
		$audience_types   = $audience->get_audience_type_labels();
		$preset_templates = $tpl_presets->get_by_category();

		// Meta templates for the message template picker (from Phase 5).
		$meta_templates = [];
		if ( class_exists( TemplateRepository::class ) ) {
			$template_repo  = new TemplateRepository();
			$tpl_result     = $template_repo->get_templates( [ 'status' => 'APPROVED', 'per_page' => 200 ] );
			$meta_templates = $tpl_result['rows'] ?? [];
		}

		// WooCommerce product categories for audience builder.
		$product_categories = get_terms( [
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
			'fields'     => 'id=>name',
		] );

		// WooCommerce payment gateways.
		$payment_gateways = [];
		if ( class_exists( 'WC_Payment_Gateways' ) ) {
			$gateways = WC_Payment_Gateways::instance()->get_available_payment_gateways();
			foreach ( $gateways as $id => $gateway ) {
				$payment_gateways[] = [ 'value' => $id, 'label' => $gateway->get_title() ];
			}
		}

		// Serialize data for JS.
		$page_data = [
			'dashboardStats'    => $dashboard_stats,
			'savedSegments'     => $saved_segments,
			'audienceTypes'     => $audience_types,
			'presetTemplates'   => $preset_templates,
			'metaTemplates'     => $meta_templates,
			'productCategories' => is_array( $product_categories ) ? $product_categories : [],
			'paymentGateways'   => $payment_gateways,
			'countries'         => WC()->countries ? WC()->countries->get_countries() : [],
			'currency'          => get_woocommerce_currency_symbol(),
			'dateFormat'        => get_option( 'date_format' ),
		];

		// Pass to marketing.js.
		wp_localize_script(
			'tsh-wa-marketing',
			'tshWaMarketingData',
			$page_data
		);

		include TSH_WA_PATH . 'templates/admin/marketing.php';
	}
}
