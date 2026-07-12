<?php
/**
 * Templates admin page controller.
 *
 * @package TSH\WhatsAppNotify\Admin\Pages
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Admin\Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TSH\WhatsAppNotify\Templates\TemplateAssignment;
use TSH\WhatsAppNotify\Templates\TemplateCategory;
use TSH\WhatsAppNotify\Templates\TemplateLanguage;
use TSH\WhatsAppNotify\Templates\TemplateManager;
use TSH\WhatsAppNotify\Templates\TemplateRepository;
use TSH\WhatsAppNotify\Templates\TemplateSync;

/**
 * Class Templates
 *
 * Full controller for the Templates admin page.
 * Handles pagination, filtering, bulk actions, stats header,
 * and delegates rendering to the view template.
 */
final class Templates {

	/** @var int Default items per page. */
	private const DEFAULT_PER_PAGE = 25;

	/** @var TemplateManager */
	private TemplateManager $manager;

	public function __construct() {
		$this->manager = new TemplateManager();
	}

	// -------------------------------------------------------------------------
	// Public render entry point
	// -------------------------------------------------------------------------

	/**
	 * Render the Templates page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'tsh-whatsapp-notify' ) );
		}

		$data     = $this->build_template_data();
		$template = TSH_WA_PATH . 'templates/admin/templates.php';

		if ( file_exists( $template ) ) {
			extract( $data, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
			include $template;
		}
	}

	// -------------------------------------------------------------------------
	// Data assembly
	// -------------------------------------------------------------------------

	/**
	 * Assemble all data for the templates view.
	 *
	 * @return array<string, mixed>
	 */
	private function build_template_data(): array {
		// ---- Query args from GET ----
		$page          = max( 1, absint( $_GET['tsh_wa_page'] ?? 1 ) );
		$per_page      = max( 5, min( 200, absint( $_GET['per_page'] ?? self::DEFAULT_PER_PAGE ) ) );
		$status_filter = sanitize_text_field( $_GET['status'] ?? '' );
		$cat_filter    = sanitize_text_field( $_GET['category'] ?? '' );
		$lang_filter   = sanitize_text_field( $_GET['language'] ?? '' );
		$quality_filter = sanitize_text_field( $_GET['quality'] ?? '' );
		$search_query  = sanitize_text_field( $_GET['s'] ?? '' );
		$orderby       = sanitize_key( $_GET['orderby'] ?? 'created_at' );
		$order         = strtoupper( sanitize_key( $_GET['order'] ?? 'DESC' ) ) === 'ASC' ? 'ASC' : 'DESC';

		// ---- Build query args ----
		$query_args = [
			'per_page' => $per_page,
			'page'     => $page,
			'orderby'  => $orderby,
			'order'    => $order,
		];

		if ( $status_filter )  { $query_args['status']   = $status_filter; }
		if ( $cat_filter )     { $query_args['category']  = $cat_filter; }
		if ( $lang_filter )    { $query_args['language']  = $lang_filter; }
		if ( $quality_filter ) { $query_args['quality']   = $quality_filter; }
		if ( $search_query )   { $query_args['search']    = $search_query; }

		// ---- Fetch templates ----
		$result   = $this->manager->get_templates( $query_args );
		$rows     = $result['rows'];
		$total    = $result['total'];
		$pages    = (int) ceil( $total / $per_page );

		// ---- Stats + sync status ----
		$overview = $this->manager->get_dashboard_overview();
		$sync_status = $this->manager->get_sync_status();

		// ---- Assignments map ----
		$assignment_service = new TemplateAssignment();
		$assignment_map     = $assignment_service->get_assignment_map();

		// ---- Language & Category options for filters ----
		$language_options = TemplateLanguage::get_supported();
		$category_options = TemplateCategory::get_select_options();

		// ---- Status options for filter ----
		$status_options = [
			TemplateRepository::STATUS_APPROVED  => __( 'Approved', 'tsh-whatsapp-notify' ),
			TemplateRepository::STATUS_PENDING   => __( 'Pending', 'tsh-whatsapp-notify' ),
			TemplateRepository::STATUS_REJECTED  => __( 'Rejected', 'tsh-whatsapp-notify' ),
			TemplateRepository::STATUS_PAUSED    => __( 'Paused', 'tsh-whatsapp-notify' ),
			TemplateRepository::STATUS_DISABLED  => __( 'Disabled', 'tsh-whatsapp-notify' ),
		];

		// ---- Pagination links base URL ----
		$base_url = add_query_arg(
			array_filter( [
				'page'     => 'tsh-whatsapp-notify-templates',
				'status'   => $status_filter  ?: null,
				'category' => $cat_filter     ?: null,
				'language' => $lang_filter    ?: null,
				'quality'  => $quality_filter ?: null,
				's'        => $search_query   ?: null,
				'per_page' => self::DEFAULT_PER_PAGE !== $per_page ? $per_page : null,
				'orderby'  => 'created_at' !== $orderby ? $orderby : null,
				'order'    => 'DESC' !== $order ? $order : null,
			] ),
			admin_url( 'admin.php' )
		);

		return [
			// Template list data.
			'templates'          => $rows,
			'total_templates'    => $total,
			'current_page'       => $page,
			'per_page'           => $per_page,
			'total_pages'        => $pages,
			'base_url'           => $base_url,

			// Active filters.
			'status_filter'      => $status_filter,
			'cat_filter'         => $cat_filter,
			'lang_filter'        => $lang_filter,
			'quality_filter'     => $quality_filter,
			'search_query'       => $search_query,
			'orderby'            => $orderby,
			'order'              => $order,

			// Filter options.
			'status_options'     => $status_options,
			'category_options'   => $category_options,
			'language_options'   => $language_options,

			// Overview stats for the stats bar.
			'overview'           => $overview,
			'sync_status'        => $sync_status,

			// Assignments.
			'assignment_map'     => $assignment_map,

			// URLs.
			'url_sync'           => admin_url( 'admin.php?page=tsh-whatsapp-notify-templates' ),
			'url_settings'       => admin_url( 'admin.php?page=tsh-whatsapp-notify-settings&tab=tsh_wa_sync_settings' ),
		];
	}
}
