<?php
/**
 * Workflow execution engine.
 *
 * @package TSH\WhatsAppNotify\Automation
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Automation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WorkflowRunner
 *
 * Executes a workflow graph node-by-node.
 *
 * A workflow is a directed graph of nodes (actions/conditions/delays) and
 * edges (connections). Execution begins at the trigger node and follows edges
 * in topological order.
 *
 * Delay (wait) nodes schedule a cron event and halt execution; the scheduler
 * resumes the run from the next node when the delay expires.
 */
class WorkflowRunner {

	private WorkflowRepository $repo;
	private ActionManager      $actions;
	private ConditionManager   $conditions;
	private DelayManager       $delays;
	private WorkflowScheduler  $scheduler;

	public function __construct() {
		$this->repo       = new WorkflowRepository();
		$this->actions    = new ActionManager();
		$this->conditions = new ConditionManager();
		$this->delays     = new DelayManager();
		$this->scheduler  = new WorkflowScheduler();
	}

	// -------------------------------------------------------------------------
	// Entry point
	// -------------------------------------------------------------------------

	/**
	 * Execute a workflow.
	 *
	 * @param int   $workflow_id
	 * @param array $trigger_data   Data passed by the trigger.
	 * @param int   $run_id         If continuing a previously delayed run.
	 * @param string $resume_node_id If resuming after a delay, the node to resume from.
	 * @return array{ success: bool, run_id: int, error: string }
	 */
	public function run( int $workflow_id, array $trigger_data, int $run_id = 0, string $resume_node_id = '' ): array {
		$workflow = $this->repo->get_workflow( $workflow_id );

		if ( ! $workflow ) {
			return [ 'success' => false, 'run_id' => 0, 'error' => 'Workflow not found.' ];
		}

		if ( 'active' !== $workflow['status'] && ! $run_id ) {
			return [ 'success' => false, 'run_id' => 0, 'error' => 'Workflow is not active.' ];
		}

		// Create or load run record.
		if ( ! $run_id ) {
			$run_id = $this->repo->create_run( [
				'workflow_id'  => $workflow_id,
				'trigger_type' => $workflow['trigger_type'],
				'trigger_data' => $trigger_data,
				'status'       => 'running',
			] );

			if ( ! $run_id ) {
				return [ 'success' => false, 'run_id' => 0, 'error' => 'Failed to create run record.' ];
			}
		} else {
			$this->repo->update_run( $run_id, [ 'status' => 'running' ] );
		}

		$logger = new WorkflowLogger( $workflow_id, $run_id );
		$logger->info( 'Workflow started.', '', 'system' );

		// Build the execution context.
		$order_id    = (int) ( $trigger_data['order_id'] ?? 0 );
		$customer_id = (int) ( $trigger_data['customer_id'] ?? 0 );

		if ( ! $customer_id && $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$customer_id = (int) $order->get_customer_id();
			}
		}

		$context = [
			'workflow_id'  => $workflow_id,
			'run_id'       => $run_id,
			'order_id'     => $order_id ?: null,
			'customer_id'  => $customer_id ?: null,
			'trigger_data' => $trigger_data,
			'step_outputs' => [],
			'extra'        => [],
		];

		$start_ms    = hrtime( true );
		$steps_done  = 0;
		$error_msg   = '';
		$final_status = 'completed';

		try {
			$result = $this->execute_graph(
				$workflow['nodes'],
				$workflow['edges'],
				$context,
				$logger,
				$steps_done,
				$resume_node_id
			);

			if ( $result['paused'] ) {
				// A delay node paused execution. The scheduler will resume later.
				$this->repo->update_run( $run_id, [
					'status'          => 'pending',
					'steps_completed' => $steps_done,
					'context'         => $context,
				] );

				$logger->info( 'Workflow paused — delayed until: ' . $result['resume_at'], '', 'system' );

				return [ 'success' => true, 'run_id' => $run_id, 'error' => '' ];
			}

			if ( ! $result['success'] ) {
				$final_status = 'failed';
				$error_msg    = $result['error'];
				$logger->error( 'Workflow failed: ' . $error_msg );
			}
		} catch ( \Throwable $e ) {
			$final_status = 'failed';
			$error_msg    = $e->getMessage();
			$logger->error( 'Uncaught exception: ' . $error_msg );
		}

		$elapsed_ms = (int) ( ( hrtime( true ) - $start_ms ) / 1_000_000 );

		$this->repo->update_run( $run_id, [
			'status'           => $final_status,
			'completed_at'     => current_time( 'mysql' ),
			'execution_time_ms'=> $elapsed_ms,
			'steps_completed'  => $steps_done,
			'error_message'    => $error_msg,
		] );

		$this->repo->increment_run_count( $workflow_id );

		$logger->info( "Workflow {$final_status} in {$elapsed_ms}ms. Steps: {$steps_done}.", '', 'system' );

		return [ 'success' => 'completed' === $final_status, 'run_id' => $run_id, 'error' => $error_msg ];
	}

	// -------------------------------------------------------------------------
	// Graph traversal
	// -------------------------------------------------------------------------

	/**
	 * Execute the workflow graph.
	 *
	 * @param array  $nodes
	 * @param array  $edges
	 * @param array  $context  Passed by reference to accumulate step outputs.
	 * @param WorkflowLogger $logger
	 * @param int    $steps_done  Passed by reference.
	 * @param string $start_node_id  If non-empty, skip to this node (resume from delay).
	 * @return array{ success: bool, paused: bool, resume_at: string, error: string }
	 */
	private function execute_graph(
		array $nodes,
		array $edges,
		array &$context,
		WorkflowLogger $logger,
		int &$steps_done,
		string $start_node_id = ''
	): array {
		// Index nodes and build adjacency.
		$node_map  = [];
		foreach ( $nodes as $node ) {
			$node_map[ $node['id'] ] = $node;
		}

		// Build successor map: node_id → [ {target_id, condition: 'yes'|'no'|null} ]
		$successors = [];
		foreach ( $edges as $edge ) {
			$source = $edge['source'] ?? '';
			$target = $edge['target'] ?? '';
			$label  = $edge['label'] ?? $edge['sourceHandle'] ?? null;
			if ( $source && $target ) {
				$successors[ $source ][] = [ 'id' => $target, 'branch' => $label ];
			}
		}

		// Find start nodes (nodes with no incoming edges OR the trigger node).
		$has_incoming = [];
		foreach ( $edges as $edge ) {
			$has_incoming[ $edge['target'] ?? '' ] = true;
		}

		$start_nodes = [];
		foreach ( $nodes as $node ) {
			if ( ! isset( $has_incoming[ $node['id'] ] ) ) {
				$start_nodes[] = $node['id'];
			}
		}

		if ( empty( $start_nodes ) && ! empty( $nodes ) ) {
			$start_nodes = [ $nodes[0]['id'] ];
		}

		// If resuming, find resume node.
		if ( $start_node_id && isset( $node_map[ $start_node_id ] ) ) {
			$start_nodes = [ $start_node_id ];
		}

		// Execute via BFS.
		$queue   = $start_nodes;
		$visited = [];
		$limit   = 100; // Max nodes per run to prevent infinite loops.

		while ( ! empty( $queue ) && $limit-- > 0 ) {
			$node_id = array_shift( $queue );

			if ( isset( $visited[ $node_id ] ) ) {
				continue;
			}
			$visited[ $node_id ] = true;

			$node = $node_map[ $node_id ] ?? null;
			if ( ! $node ) {
				continue;
			}

			$type   = $node['type'] ?? '';
			$config = $node['config'] ?? $node['data'] ?? [];

			// Resolve variables in config values.
			$resolver = new VariableResolver( $context );
			$config   = $resolver->resolve_array( $config );

			$logger->info( "Executing node: {$type} ({$node_id})", $node_id, $type );

			// ---- Delay/Wait node ----
			if ( 'wait' === $type ) {
				$delay_secs = $this->delays->calculate_delay_seconds( $config );

				if ( $delay_secs > 30 ) {
					// Schedule resume and halt this run.
					$resume_ts = (int) current_time( 'timestamp' ) + $delay_secs;
					$next_node = $successors[ $node_id ][0]['id'] ?? '';

					$this->scheduler->schedule_resume(
						$context['workflow_id'],
						$context['run_id'],
						$next_node,
						$resume_ts,
						$context['trigger_data']
					);

					$steps_done++;

					return [
						'success'   => true,
						'paused'    => true,
						'resume_at' => gmdate( 'Y-m-d H:i:s', $resume_ts ),
						'error'     => '',
					];
				}

				// Short delay (≤30s) — sleep inline.
				if ( $delay_secs > 0 ) {
					sleep( $delay_secs );
				}

				$steps_done++;
				$context['step_outputs'][ $node_id ] = [ 'waited_seconds' => $delay_secs ];
			}

			// ---- Condition node ----
			elseif ( 'condition' === $type ) {
				$passed  = $this->conditions->evaluate( $config, $context );
				$branch  = $passed ? 'yes' : 'no';
				$label   = $passed ? 'YES' : 'NO';

				$logger->info( "Condition result: {$label}", $node_id, $type );
				$steps_done++;
				$context['step_outputs'][ $node_id ] = [ 'result' => $passed, 'branch' => $branch ];

				// Only follow the correct branch.
				foreach ( $successors[ $node_id ] ?? [] as $suc ) {
					if ( null === $suc['branch'] || $suc['branch'] === $branch ) {
						$queue[] = $suc['id'];
					}
				}
				continue;
			}

			// ---- Regular action node ----
			else {
				$result = $this->actions->execute( $type, $config, $context );
				$steps_done++;
				$context['step_outputs'][ $node_id ] = $result['output'];

				if ( ! $result['success'] ) {
					$logger->error( "Action failed: " . $result['error'], $node_id, $type );

					// Check if node has a 'continue_on_error' flag.
					if ( empty( $config['continue_on_error'] ) ) {
						return [ 'success' => false, 'paused' => false, 'resume_at' => '', 'error' => $result['error'] ];
					}
				} else {
					$logger->info( "Action succeeded.", $node_id, $type, $result['output'] );
				}
			}

			// Enqueue successors (for non-condition nodes, follow all edges).
			foreach ( $successors[ $node_id ] ?? [] as $suc ) {
				if ( ! isset( $visited[ $suc['id'] ] ) ) {
					$queue[] = $suc['id'];
				}
			}
		}

		return [ 'success' => true, 'paused' => false, 'resume_at' => '', 'error' => '' ];
	}
}
