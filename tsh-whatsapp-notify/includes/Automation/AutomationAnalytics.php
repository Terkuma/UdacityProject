<?php
/**
 * Automation analytics and reporting.
 *
 * @package TSH\WhatsAppNotify\Automation
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Automation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AutomationAnalytics
 */
class AutomationAnalytics {

	private WorkflowRepository $repo;

	public function __construct() {
		$this->repo = new WorkflowRepository();
	}

	/**
	 * Overall analytics overview.
	 *
	 * @param int $days Look-back window in days (default 30).
	 * @return array
	 */
	public function get_overview( int $days = 30 ): array {
		$stats = $this->repo->get_run_stats( null, $days );

		$success_rate = $stats['total'] > 0
			? round( ( $stats['completed'] / $stats['total'] ) * 100, 1 )
			: 0;

		return [
			'total_runs'     => $stats['total'],
			'completed'      => $stats['completed'],
			'failed'         => $stats['failed'],
			'running'        => $stats['running'],
			'pending'        => $stats['pending'],
			'success_rate'   => $success_rate,
			'avg_ms'         => $stats['avg_ms'],
			'avg_duration'   => $this->format_ms( $stats['avg_ms'] ),
			'top_workflows'  => $this->repo->get_top_workflows( 5 ),
			'total_workflows'=> $this->count_workflows(),
			'active_workflows'=> $this->count_workflows( 'active' ),
		];
	}

	/**
	 * Per-workflow stats.
	 */
	public function get_workflow_stats( int $workflow_id, int $days = 30 ): array {
		$stats = $this->repo->get_run_stats( $workflow_id, $days );

		$success_rate = $stats['total'] > 0
			? round( ( $stats['completed'] / $stats['total'] ) * 100, 1 )
			: 0;

		return array_merge( $stats, [
			'success_rate' => $success_rate,
			'avg_duration' => $this->format_ms( $stats['avg_ms'] ),
		] );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function count_workflows( string $status = 'all' ): int {
		$result = $this->repo->get_workflows( [ 'status' => $status, 'per_page' => 1, 'page' => 1 ] );

		return $result['total'];
	}

	private function format_ms( int $ms ): string {
		if ( ! $ms ) { return '—'; }
		if ( $ms < 1000 ) { return $ms . 'ms'; }
		return round( $ms / 1000, 2 ) . 's';
	}
}
