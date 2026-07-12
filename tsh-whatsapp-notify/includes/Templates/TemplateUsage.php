<?php
/**
 * Template usage tracking.
 *
 * @package TSH\WhatsAppNotify\Templates
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Templates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TemplateUsage
 *
 * Records every template send attempt and provides aggregated usage stats.
 */
final class TemplateUsage {

	/** @var TemplateRepository */
	private TemplateRepository $repository;

	/** @var TemplateLogger */
	private TemplateLogger $logger;

	public function __construct() {
		$this->repository = new TemplateRepository();
		$this->logger     = new TemplateLogger();
	}

	// -------------------------------------------------------------------------
	// Recording
	// -------------------------------------------------------------------------

	/**
	 * Record a template usage event.
	 *
	 * @param int   $template_id
	 * @param bool  $success
	 * @param float $latency_ms
	 */
	public function record( int $template_id, bool $success, float $latency_ms = 0.0 ): void {
		$this->repository->increment_usage( $template_id );

		if ( $success ) {
			$this->repository->increment_success( $template_id );
		} else {
			$this->repository->increment_failure( $template_id );
		}

		$this->logger->template_used( $template_id, $success, $latency_ms );
	}

	// -------------------------------------------------------------------------
	// Statistics
	// -------------------------------------------------------------------------

	/**
	 * Return usage stats for a single template.
	 *
	 * @param int $template_id
	 * @return array{ total: int, success: int, failed: int, success_rate: float, last_used: string|null }
	 */
	public function get_stats( int $template_id ): array {
		$template = $this->repository->find( $template_id );
		if ( ! $template ) {
			return [
				'total'        => 0,
				'success'      => 0,
				'failed'       => 0,
				'success_rate' => 0.0,
				'last_used'    => null,
			];
		}

		$total   = (int) $template->usage_count;
		$success = (int) $template->send_success;
		$failed  = (int) $template->send_failed;

		return [
			'total'        => $total,
			'success'      => $success,
			'failed'       => $failed,
			'success_rate' => $total > 0 ? round( $success / $total * 100, 1 ) : 0.0,
			'last_used'    => $template->last_used ?? null,
		];
	}

	/**
	 * Return the most-used templates.
	 *
	 * @param int $limit
	 * @return array<int, object>
	 */
	public function get_most_used( int $limit = 10 ): array {
		return $this->repository->get_all( [
			'per_page' => $limit,
			'page'     => 1,
			'orderby'  => 'usage_count',
			'order'    => 'DESC',
		] )['rows'];
	}

	/**
	 * Return the least-used templates (with at least 1 use).
	 *
	 * @param int $limit
	 * @return array<int, object>
	 */
	public function get_least_used( int $limit = 10 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'tsh_wa_meta_templates';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE usage_count > 0 ORDER BY usage_count ASC LIMIT %d",
				$limit
			)
		) ?: [];
	}

	/**
	 * Return templates that have never been used.
	 *
	 * @return array<int, object>
	 */
	public function get_never_used(): array {
		return $this->repository->get_all( [
			'never_used' => true,
			'per_page'   => 200,
			'page'       => 1,
		] )['rows'];
	}

	/**
	 * Return overall usage summary across all templates.
	 *
	 * @return array{ total_sends: int, total_success: int, total_failed: int, success_rate: float }
	 */
	public function get_overall_stats(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'tsh_wa_meta_templates';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			"SELECT SUM(usage_count) AS total, SUM(send_success) AS success, SUM(send_failed) AS failed
			 FROM `{$table}`"
		);

		$total   = (int) ( $row->total   ?? 0 );
		$success = (int) ( $row->success ?? 0 );
		$failed  = (int) ( $row->failed  ?? 0 );

		return [
			'total_sends'  => $total,
			'total_success' => $success,
			'total_failed'  => $failed,
			'success_rate'  => $total > 0 ? round( $success / $total * 100, 1 ) : 0.0,
		];
	}
}
