<?php
/**
 * Template manager — main orchestrator for Phase 5.
 *
 * @package TSH\WhatsAppNotify\Templates
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Templates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TemplateManager
 *
 * The single entry point for all Phase 5 template operations.
 * Composes the repository, cache, sync engine, preview, assignment,
 * usage tracking, analytics, import/export, search, and logger.
 *
 * Consumers should depend on this class rather than the individual services.
 */
final class TemplateManager {

	/** @var TemplateRepository */
	private TemplateRepository $repository;

	/** @var TemplateCache */
	private TemplateCache $cache;

	/** @var TemplateSync */
	private TemplateSync $sync;

	/** @var TemplatePreview */
	private TemplatePreview $preview;

	/** @var TemplateAssignment */
	private TemplateAssignment $assignment;

	/** @var TemplateUsage */
	private TemplateUsage $usage;

	/** @var TemplateAnalytics */
	private TemplateAnalytics $analytics;

	/** @var TemplateImporter */
	private TemplateImporter $importer;

	/** @var TemplateExporter */
	private TemplateExporter $exporter;

	/** @var TemplateSearch */
	private TemplateSearch $search;

	/** @var TemplateValidator */
	private TemplateValidator $validator;

	/** @var TemplateLogger */
	private TemplateLogger $logger;

	public function __construct() {
		$this->repository = new TemplateRepository();
		$this->cache      = new TemplateCache();
		$this->sync       = new TemplateSync();
		$this->preview    = new TemplatePreview( $this->repository );
		$this->assignment = new TemplateAssignment();
		$this->usage      = new TemplateUsage();
		$this->analytics  = new TemplateAnalytics();
		$this->importer   = new TemplateImporter();
		$this->exporter   = new TemplateExporter();
		$this->search     = new TemplateSearch( $this->repository, $this->cache );
		$this->validator  = new TemplateValidator();
		$this->logger     = new TemplateLogger();

		// Register the background sync hook handler.
		add_action( TemplateSync::HOOK_BACKGROUND_SYNC, [ $this->sync, 'manual_sync' ] );
	}

	// -------------------------------------------------------------------------
	// Template retrieval
	// -------------------------------------------------------------------------

	/**
	 * Get a single template by ID (cache-aware).
	 *
	 * @param int $id
	 * @return object|null
	 */
	public function get_template( int $id ): ?object {
		$cached = $this->cache->get_template( $id );
		if ( $cached ) {
			return $cached;
		}

		$template = $this->repository->find( $id );
		if ( $template ) {
			$this->cache->set_template( $id, $template );
		}
		return $template;
	}

	/**
	 * Get paginated/filtered templates.
	 *
	 * @param array<string, mixed> $args
	 * @return array{ rows: array<int, object>, total: int }
	 */
	public function get_templates( array $args = [] ): array {
		$hash   = md5( wp_json_encode( $args ) );
		$cached = $this->cache->get_template_list( $hash );
		if ( null !== $cached ) {
			return $cached;
		}

		$result = $this->repository->get_all( $args );
		$this->cache->set_template_list( $hash, $result, 120 );
		return $result;
	}

	/**
	 * Get the assigned template for a WC event + recipient type.
	 *
	 * Performs language fallback if no exact match is available.
	 *
	 * @param string $event
	 * @param string $recipient_type customer|admin
	 * @param string $preferred_language
	 * @return object|null Template row or null.
	 */
	public function get_assigned_template( string $event, string $recipient_type = 'customer', string $preferred_language = '' ): ?object {
		$assignment = $this->assignment->get( $event, $recipient_type );
		if ( ! $assignment ) {
			return null;
		}

		$template = $this->get_template( (int) $assignment->template_id );
		if ( ! $template ) {
			return null;
		}

		// If language preference doesn't match, try to find the same template
		// in the preferred language.
		if ( $preferred_language && $template->language !== $preferred_language ) {
			$alt = $this->repository->find_by_name( $template->template_name, $preferred_language );
			if ( $alt && $alt->status === TemplateRepository::STATUS_APPROVED ) {
				return $alt;
			}
			// Fall through — use the assigned template even if language differs.
		}

		return $template;
	}

	// -------------------------------------------------------------------------
	// Sync
	// -------------------------------------------------------------------------

	/**
	 * Trigger a sync.
	 *
	 * @param string $type manual|incremental|full|background|scheduled
	 * @return array{ success: bool, stats: array<string, int>, message: string }
	 */
	public function sync( string $type = 'manual' ): array {
		switch ( $type ) {
			case 'full':
				return $this->sync->full_sync();
			case 'incremental':
				return $this->sync->incremental_sync();
			case 'background':
				$this->sync->background_sync();
				return [ 'success' => true, 'stats' => [], 'message' => __( 'Background sync scheduled.', 'tsh-whatsapp-notify' ) ];
			case 'scheduled':
				return $this->sync->scheduled_sync();
			default:
				return $this->sync->manual_sync();
		}
	}

	/**
	 * Get the current sync status.
	 *
	 * @return array<string, mixed>
	 */
	public function get_sync_status(): array {
		return $this->sync->get_sync_status();
	}

	// -------------------------------------------------------------------------
	// Preview
	// -------------------------------------------------------------------------

	/**
	 * Build a preview for a template.
	 *
	 * @param int                  $template_id
	 * @param array<string, mixed> $variables
	 * @return array{ success: bool, data?: array, message?: string }
	 */
	public function preview( int $template_id, array $variables = [] ): array {
		return $this->preview->build_modal_data( $template_id, $variables );
	}

	// -------------------------------------------------------------------------
	// Variable mapping
	// -------------------------------------------------------------------------

	/**
	 * Save admin-configured variable mapping for a template.
	 *
	 * @param int                  $template_id
	 * @param array<string, mixed> $mapping  { "1": { wc_field: "...", example: "..." }, ... }
	 * @return bool
	 */
	public function save_variable_mapping( int $template_id, array $mapping ): bool {
		$sanitised = [];
		foreach ( $mapping as $num => $entry ) {
			$n = (string) absint( $num );
			$sanitised[ $n ] = [
				'wc_field' => sanitize_text_field( $entry['wc_field'] ?? '' ),
				'example'  => sanitize_text_field( $entry['example']  ?? '' ),
			];
		}

		$result = $this->repository->update( $template_id, [
			'variable_mapping' => wp_json_encode( $sanitised ),
		] );

		if ( $result ) {
			$this->cache->bust_template( $template_id );
		}

		return $result;
	}

	// -------------------------------------------------------------------------
	// Assignment
	// -------------------------------------------------------------------------

	/**
	 * Assign a template to a WC event.
	 *
	 * @param string $event
	 * @param int    $template_id  0 = unassign.
	 * @param string $recipient_type
	 * @return bool
	 */
	public function assign( string $event, int $template_id, string $recipient_type = 'customer' ): bool {
		$result = $this->assignment->assign( $event, $template_id, $recipient_type );
		if ( $result ) {
			$this->logger->template_assigned( $event, $template_id, $recipient_type );
		}
		return $result;
	}

	/**
	 * Unassign a template from a WC event.
	 *
	 * @param string $event
	 * @param string $recipient_type
	 * @return bool
	 */
	public function unassign( string $event, string $recipient_type = 'customer' ): bool {
		return $this->assignment->unassign( $event, $recipient_type );
	}

	/**
	 * Return all event assignments.
	 *
	 * @return array<int, object>
	 */
	public function get_assignments(): array {
		return $this->assignment->get_all();
	}

	// -------------------------------------------------------------------------
	// Validation
	// -------------------------------------------------------------------------

	/**
	 * Validate template data.
	 *
	 * @param array<string, mixed> $data
	 * @return array{ valid: bool, errors: array<string, string[]> }
	 */
	public function validate( array $data ): array {
		return $this->validator->validate( $data );
	}

	// -------------------------------------------------------------------------
	// Usage tracking
	// -------------------------------------------------------------------------

	/**
	 * Record a template usage event.
	 *
	 * @param int   $template_id
	 * @param bool  $success
	 * @param float $latency_ms
	 */
	public function record_usage( int $template_id, bool $success, float $latency_ms = 0.0 ): void {
		$this->usage->record( $template_id, $success, $latency_ms );
	}

	// -------------------------------------------------------------------------
	// Analytics
	// -------------------------------------------------------------------------

	/**
	 * Return dashboard overview data.
	 *
	 * @return array<string, mixed>
	 */
	public function get_dashboard_overview(): array {
		return $this->analytics->get_overview();
	}

	/**
	 * Return full analytics data.
	 *
	 * @return array<string, mixed>
	 */
	public function get_analytics(): array {
		return [
			'overview'        => $this->analytics->get_overview(),
			'status_breakdown' => $this->analytics->get_status_breakdown(),
			'category_breakdown' => $this->analytics->get_category_breakdown(),
			'language_breakdown' => $this->analytics->get_language_breakdown(),
			'quality_breakdown'  => $this->analytics->get_quality_breakdown(),
			'most_used'          => $this->analytics->get_most_used( 10 ),
			'success_rates'      => $this->analytics->get_success_rates(),
			'quality_warnings'   => $this->analytics->get_quality_warnings(),
		];
	}

	// -------------------------------------------------------------------------
	// Import / Export
	// -------------------------------------------------------------------------

	/**
	 * Import templates.
	 *
	 * @param string $data   JSON or CSV string.
	 * @param string $format json|csv
	 * @param string $mode   merge|replace|skip
	 * @return array{ imported: int, skipped: int, errors: int, messages: string[] }
	 */
	public function import( string $data, string $format = 'json', string $mode = 'merge' ): array {
		if ( 'csv' === $format ) {
			return $this->importer->import_csv( $data, $mode );
		}
		return $this->importer->import_json( $data, $mode );
	}

	/**
	 * Export templates.
	 *
	 * @param string          $format      json|csv
	 * @param array<int, int> $template_ids Empty = all.
	 * @return string
	 */
	public function export( string $format = 'json', array $template_ids = [] ): string {
		if ( 'csv' === $format ) {
			return $this->exporter->export_csv( $template_ids );
		}
		return $this->exporter->export_json( $template_ids );
	}

	// -------------------------------------------------------------------------
	// Search
	// -------------------------------------------------------------------------

	/**
	 * Search templates.
	 *
	 * @param string               $query
	 * @param array<string, mixed> $filters
	 * @return array{ rows: array, total: int, page: int, per_page: int, pages: int }
	 */
	public function search( string $query, array $filters = [] ): array {
		return $this->search->search( $query, $filters );
	}

	// -------------------------------------------------------------------------
	// Cache
	// -------------------------------------------------------------------------

	/**
	 * Flush the entire template cache.
	 */
	public function flush_cache(): void {
		$this->cache->flush();
		$this->logger->cache_flushed();
	}

	// -------------------------------------------------------------------------
	// Quality monitoring
	// -------------------------------------------------------------------------

	/**
	 * Return templates that need quality attention.
	 *
	 * @return array<int, object>
	 */
	public function get_quality_warnings(): array {
		return $this->analytics->get_quality_warnings();
	}

	// -------------------------------------------------------------------------
	// Service accessors (for callers that need a specific sub-service)
	// -------------------------------------------------------------------------

	public function repository(): TemplateRepository  { return $this->repository; }
	public function cache(): TemplateCache             { return $this->cache; }
	public function sync_service(): TemplateSync       { return $this->sync; }
	public function assignment_service(): TemplateAssignment { return $this->assignment; }
	public function preview_service(): TemplatePreview { return $this->preview; }
	public function analytics_service(): TemplateAnalytics { return $this->analytics; }
}
