<?php
/**
 * Workflow execution history viewer.
 *
 * @package TSH\WhatsAppNotify\Automation
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Automation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ExecutionHistory
 *
 * Provides formatted execution history data for the admin UI.
 */
class ExecutionHistory {

	private WorkflowRepository $repo;

	public function __construct() {
		$this->repo = new WorkflowRepository();
	}

	/**
	 * Get paginated history for a workflow (or all workflows).
	 *
	 * @param int   $workflow_id  0 = all workflows
	 * @param array $args         Pagination / filter args
	 * @return array{ rows: array, total: int }
	 */
	public function get_history( int $workflow_id = 0, array $args = [] ): array {
		$query_args = array_merge( $args, [
			'workflow_id' => $workflow_id ?: null,
			'per_page'    => $args['per_page'] ?? 20,
			'page'        => $args['page'] ?? 1,
		] );

		$result = $this->repo->get_runs( $query_args );
		$result['rows'] = array_map( [ $this, 'format_run' ], $result['rows'] );

		return $result;
	}

	/**
	 * Get detailed view of a single run including logs.
	 */
	public function get_run_detail( int $run_id ): ?array {
		$run = $this->repo->get_run( $run_id );

		if ( ! $run ) {
			return null;
		}

		$logs    = $this->repo->get_logs_for_run( $run_id );
		$details = $this->format_run( $run );

		$details['logs'] = array_map( function ( array $log ) {
			$log['time_human'] = $log['created_at'] ? human_time_diff( (int) strtotime( $log['created_at'] ) ) . ' ago' : '';
			return $log;
		}, $logs );

		return $details;
	}

	// -------------------------------------------------------------------------
	// Formatting
	// -------------------------------------------------------------------------

	private function format_run( array $run ): array {
		$run['duration_human'] = $run['execution_time_ms']
			? $this->format_ms( (int) $run['execution_time_ms'] )
			: '—';

		$run['started_human'] = $run['started_at']
			? human_time_diff( (int) strtotime( $run['started_at'] ) ) . ' ago'
			: '—';

		$run['status_label'] = $this->status_label( $run['status'] ?? '' );
		$run['status_class'] = $this->status_class( $run['status'] ?? '' );

		return $run;
	}

	private function format_ms( int $ms ): string {
		if ( $ms < 1000 ) {
			return $ms . 'ms';
		}
		return round( $ms / 1000, 2 ) . 's';
	}

	private function status_label( string $status ): string {
		$labels = [
			'pending'   => __( 'Pending', 'tsh-whatsapp-notify' ),
			'running'   => __( 'Running', 'tsh-whatsapp-notify' ),
			'completed' => __( 'Completed', 'tsh-whatsapp-notify' ),
			'failed'    => __( 'Failed', 'tsh-whatsapp-notify' ),
			'cancelled' => __( 'Cancelled', 'tsh-whatsapp-notify' ),
		];

		return $labels[ $status ] ?? ucfirst( $status );
	}

	private function status_class( string $status ): string {
		$classes = [
			'pending'   => 'tsh-wa-status--pending',
			'running'   => 'tsh-wa-status--running',
			'completed' => 'tsh-wa-status--success',
			'failed'    => 'tsh-wa-status--error',
			'cancelled' => 'tsh-wa-status--grey',
		];

		return $classes[ $status ] ?? '';
	}
}
