<?php
/**
 * Cron-based scheduler for delayed workflow resumption.
 *
 * @package TSH\WhatsAppNotify\Automation
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Automation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WorkflowScheduler
 *
 * Schedules future workflow executions and resumes delayed runs via WP Cron.
 */
class WorkflowScheduler {

	public const HOOK_PROCESS    = 'tsh_wa_automation_process';
	public const HOOK_RESUME     = 'tsh_wa_automation_resume';
	public const HOOK_PRUNE_LOGS = 'tsh_wa_automation_prune';

	// -------------------------------------------------------------------------
	// Scheduling
	// -------------------------------------------------------------------------

	/**
	 * Schedule a new workflow execution (initial trigger).
	 */
	public function schedule_run( int $workflow_id, array $trigger_data, int $at_timestamp = 0 ): void {
		$at = $at_timestamp ?: (int) current_time( 'timestamp' );

		wp_schedule_single_event( $at, self::HOOK_PROCESS, [
			'workflow_id'  => $workflow_id,
			'trigger_data' => $trigger_data,
			'run_id'       => 0,
			'resume_node'  => '',
		] );
	}

	/**
	 * Schedule resumption of a paused (delayed) run.
	 *
	 * @param int    $workflow_id
	 * @param int    $run_id
	 * @param string $resume_node_id  Node to resume execution from.
	 * @param int    $at_timestamp    Unix timestamp.
	 * @param array  $trigger_data
	 */
	public function schedule_resume( int $workflow_id, int $run_id, string $resume_node_id, int $at_timestamp, array $trigger_data ): void {
		wp_schedule_single_event( $at_timestamp, self::HOOK_RESUME, [
			'workflow_id'  => $workflow_id,
			'run_id'       => $run_id,
			'resume_node'  => $resume_node_id,
			'trigger_data' => $trigger_data,
		] );
	}

	/**
	 * Cancel all pending scheduled events for a workflow (e.g. when deactivated).
	 */
	public function cancel_workflow_events( int $workflow_id ): void {
		// WP doesn't support filtering scheduled events by args, so we iterate.
		$crons = _get_cron_array();
		if ( ! $crons ) { return; }

		foreach ( $crons as $timestamp => $cron ) {
			foreach ( [ self::HOOK_PROCESS, self::HOOK_RESUME ] as $hook ) {
				if ( isset( $cron[ $hook ] ) ) {
					foreach ( $cron[ $hook ] as $key => $event ) {
						if ( isset( $event['args']['workflow_id'] ) && (int) $event['args']['workflow_id'] === $workflow_id ) {
							wp_unschedule_event( $timestamp, $hook, $event['args'] );
						}
					}
				}
			}
		}
	}

	// -------------------------------------------------------------------------
	// Cron hook handlers
	// -------------------------------------------------------------------------

	/**
	 * Handle tsh_wa_automation_process cron event.
	 */
	public function handle_process( array $args ): void {
		$workflow_id  = (int) ( $args['workflow_id'] ?? 0 );
		$trigger_data = (array) ( $args['trigger_data'] ?? [] );
		$run_id       = (int) ( $args['run_id'] ?? 0 );
		$resume_node  = (string) ( $args['resume_node'] ?? '' );

		if ( ! $workflow_id ) { return; }

		$runner = new WorkflowRunner();
		$runner->run( $workflow_id, $trigger_data, $run_id, $resume_node );
	}

	/**
	 * Handle tsh_wa_automation_resume cron event (delayed run resumption).
	 */
	public function handle_resume( array $args ): void {
		$this->handle_process( $args );
	}

	/**
	 * Handle tsh_wa_automation_prune cron event.
	 */
	public function handle_prune(): void {
		$repo = new WorkflowRepository();
		$settings = get_option( 'tsh_wa_automation_settings', [] );

		$log_retention  = max( 7, (int) ( $settings['log_retention_days'] ?? 30 ) );
		$run_retention  = max( 14, (int) ( $settings['run_retention_days'] ?? 60 ) );

		$repo->prune_logs( $log_retention );
		$repo->prune_runs( $run_retention );
	}

	// -------------------------------------------------------------------------
	// Hook registration
	// -------------------------------------------------------------------------

	public function register_hooks(): void {
		add_action( self::HOOK_PROCESS, [ $this, 'handle_process' ] );
		add_action( self::HOOK_RESUME,  [ $this, 'handle_resume' ] );
		add_action( self::HOOK_PRUNE_LOGS, [ $this, 'handle_prune' ] );

		// Register recurring prune schedule if not set.
		if ( ! wp_next_scheduled( self::HOOK_PRUNE_LOGS ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::HOOK_PRUNE_LOGS );
		}
	}
}
