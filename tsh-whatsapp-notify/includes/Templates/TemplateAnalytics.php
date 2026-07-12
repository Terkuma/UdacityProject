<?php
/**
 * Template analytics data aggregator.
 *
 * @package TSH\WhatsAppNotify\Templates
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Templates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TemplateAnalytics
 *
 * Aggregates usage, quality, status, and performance data for
 * charts and dashboard widgets.
 */
final class TemplateAnalytics {

	/** @var TemplateRepository */
	private TemplateRepository $repository;

	/** @var TemplateUsage */
	private TemplateUsage $usage;

	public function __construct() {
		$this->repository = new TemplateRepository();
		$this->usage      = new TemplateUsage();
	}

	// -------------------------------------------------------------------------
	// Overview
	// -------------------------------------------------------------------------

	/**
	 * Return an overview summary suitable for dashboard widgets.
	 *
	 * @return array<string, mixed>
	 */
	public function get_overview(): array {
		$counts_by_status = $this->repository->count_by_status();
		$usage_stats      = $this->usage->get_overall_stats();
		$sync             = new TemplateSync();
		$sync_status      = $sync->get_sync_status();

		return [
			'total'           => $this->repository->count(),
			'approved'        => $counts_by_status[ TemplateRepository::STATUS_APPROVED ]  ?? 0,
			'pending'         => $counts_by_status[ TemplateRepository::STATUS_PENDING ]   ?? 0,
			'rejected'        => $counts_by_status[ TemplateRepository::STATUS_REJECTED ]  ?? 0,
			'paused'          => $counts_by_status[ TemplateRepository::STATUS_PAUSED ]    ?? 0,
			'disabled'        => $counts_by_status[ TemplateRepository::STATUS_DISABLED ]  ?? 0,
			'total_sends'     => $usage_stats['total_sends'],
			'success_rate'    => $usage_stats['success_rate'],
			'last_sync'       => $sync_status['last_sync'],
			'sync_status'     => $sync_status['status'],
			'last_sync_error' => $sync_status['last_error'],
		];
	}

	// -------------------------------------------------------------------------
	// Status breakdown
	// -------------------------------------------------------------------------

	/**
	 * Return template counts grouped by status.
	 *
	 * @return array<string, int>
	 */
	public function get_status_breakdown(): array {
		return $this->repository->count_by_status();
	}

	// -------------------------------------------------------------------------
	// Category breakdown
	// -------------------------------------------------------------------------

	/**
	 * Return template counts grouped by category.
	 *
	 * @return array<string, int>
	 */
	public function get_category_breakdown(): array {
		return $this->repository->count_by_category();
	}

	// -------------------------------------------------------------------------
	// Language breakdown
	// -------------------------------------------------------------------------

	/**
	 * Return template counts grouped by language.
	 *
	 * @return array<string, int>
	 */
	public function get_language_breakdown(): array {
		return $this->repository->count_by_language();
	}

	// -------------------------------------------------------------------------
	// Quality breakdown
	// -------------------------------------------------------------------------

	/**
	 * Return template counts grouped by quality_score.
	 *
	 * @return array<string, int>
	 */
	public function get_quality_breakdown(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'tsh_wa_meta_templates';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT quality_score, COUNT(*) AS cnt FROM `{$table}` GROUP BY quality_score"
		) ?: [];

		$counts = [];
		foreach ( $rows as $row ) {
			$counts[ $row->quality_score ] = (int) $row->cnt;
		}
		return $counts;
	}

	// -------------------------------------------------------------------------
	// Usage charts
	// -------------------------------------------------------------------------

	/**
	 * Return the most-used templates with full stats.
	 *
	 * @param int $limit
	 * @return array<int, array<string, mixed>>
	 */
	public function get_most_used( int $limit = 10 ): array {
		$rows = $this->usage->get_most_used( $limit );
		return array_map( function ( object $t ) {
			return [
				'id'            => (int) $t->id,
				'template_name' => $t->template_name,
				'category'      => $t->category,
				'language'      => $t->language,
				'usage_count'   => (int) $t->usage_count,
				'send_success'  => (int) $t->send_success,
				'send_failed'   => (int) $t->send_failed,
				'success_rate'  => $t->usage_count > 0
					? round( $t->send_success / $t->usage_count * 100, 1 )
					: 0.0,
				'last_used'     => $t->last_used ?? null,
			];
		}, $rows );
	}

	/**
	 * Return success rates for all templates that have been used.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_success_rates(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'tsh_wa_meta_templates';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT id, template_name, usage_count, send_success, send_failed
			 FROM `{$table}` WHERE usage_count > 0 ORDER BY usage_count DESC LIMIT 20"
		) ?: [];

		return array_map( static function ( object $t ) {
			$total = (int) $t->usage_count;
			return [
				'id'            => (int) $t->id,
				'template_name' => $t->template_name,
				'usage_count'   => $total,
				'success_rate'  => $total > 0 ? round( (int) $t->send_success / $total * 100, 1 ) : 0.0,
				'failure_rate'  => $total > 0 ? round( (int) $t->send_failed  / $total * 100, 1 ) : 0.0,
			];
		}, $rows );
	}

	/**
	 * Return quality-warning templates (LOW quality or REJECTED status).
	 *
	 * @return array<int, object>
	 */
	public function get_quality_warnings(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'tsh_wa_meta_templates';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE quality_score = %s OR status = %s ORDER BY updated_at DESC",
				TemplateRepository::QUALITY_LOW,
				TemplateRepository::STATUS_REJECTED
			)
		) ?: [];
	}
}
