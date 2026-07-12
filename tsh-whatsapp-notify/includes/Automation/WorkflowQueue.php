<?php
/**
 * Background queue for workflow trigger events.
 *
 * @package TSH\WhatsAppNotify\Automation
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Automation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WorkflowQueue
 *
 * Decouples trigger firing from workflow execution.
 * Triggers write to a transient-backed queue; a cron job drains it.
 *
 * This prevents slow checkout/admin responses when multiple workflows fire
 * on the same trigger.
 */
class WorkflowQueue {

	private const QUEUE_OPTION   = 'tsh_wa_wf_queue';
	private const LOCK_TRANSIENT = 'tsh_wa_wf_queue_lock';
	private const BATCH_SIZE     = 10;

	// -------------------------------------------------------------------------
	// Enqueue
	// -------------------------------------------------------------------------

	/**
	 * Add a workflow execution request to the queue.
	 *
	 * @param int    $workflow_id
	 * @param string $trigger_type
	 * @param array  $trigger_data
	 * @param string $dedup_key  Optional deduplication key.
	 */
	public function enqueue( int $workflow_id, string $trigger_type, array $trigger_data, string $dedup_key = '' ): void {
		$queue = $this->load_queue();

		// Dedup: skip if an identical item is already queued.
		if ( $dedup_key ) {
			foreach ( $queue as $item ) {
				if ( $item['workflow_id'] === $workflow_id && $item['dedup_key'] === $dedup_key ) {
					return;
				}
			}
		}

		$queue[] = [
			'workflow_id'  => $workflow_id,
			'trigger_type' => $trigger_type,
			'trigger_data' => $trigger_data,
			'dedup_key'    => $dedup_key,
			'queued_at'    => time(),
		];

		$this->save_queue( $queue );

		// Ensure the processing cron fires soon.
		if ( ! wp_next_scheduled( 'tsh_wa_automation_queue_process' ) ) {
			wp_schedule_single_event( time() + 5, 'tsh_wa_automation_queue_process' );
		}
	}

	// -------------------------------------------------------------------------
	// Draining
	// -------------------------------------------------------------------------

	/**
	 * Process a batch of queued workflow executions.
	 * Called by the tsh_wa_automation_queue_process cron hook.
	 */
	public function process(): void {
		// Acquire lock.
		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			return;
		}
		set_transient( self::LOCK_TRANSIENT, 1, 60 );

		$queue = $this->load_queue();

		if ( empty( $queue ) ) {
			delete_transient( self::LOCK_TRANSIENT );
			return;
		}

		$batch     = array_splice( $queue, 0, self::BATCH_SIZE );
		$remaining = $queue;
		$this->save_queue( $remaining );

		// Release lock early so concurrent processes can handle remaining.
		delete_transient( self::LOCK_TRANSIENT );

		$runner = new WorkflowRunner();

		foreach ( $batch as $item ) {
			try {
				$runner->run(
					(int) $item['workflow_id'],
					(array) $item['trigger_data']
				);
			} catch ( \Throwable $e ) {
				// Log but continue processing the batch.
				error_log( '[TSH WA Automation] Queue error: ' . $e->getMessage() );
			}
		}

		// If there are still items, schedule another run immediately.
		if ( ! empty( $remaining ) && ! wp_next_scheduled( 'tsh_wa_automation_queue_process' ) ) {
			wp_schedule_single_event( time() + 2, 'tsh_wa_automation_queue_process' );
		}
	}

	// -------------------------------------------------------------------------
	// Stats
	// -------------------------------------------------------------------------

	public function get_queue_depth(): int {
		return count( $this->load_queue() );
	}

	public function clear(): void {
		$this->save_queue( [] );
	}

	// -------------------------------------------------------------------------
	// Persistence
	// -------------------------------------------------------------------------

	private function load_queue(): array {
		return (array) ( get_option( self::QUEUE_OPTION, [] ) ?: [] );
	}

	private function save_queue( array $queue ): void {
		update_option( self::QUEUE_OPTION, $queue, false );
	}
}
