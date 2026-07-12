<?php
/**
 * Workflow data-access layer.
 *
 * @package TSH\WhatsAppNotify\Automation
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Automation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WorkflowRepository
 *
 * All database reads and writes for workflows, runs and logs.
 */
class WorkflowRepository {

	// -------------------------------------------------------------------------
	// Table helpers
	// -------------------------------------------------------------------------

	private function tbl_workflows(): string {
		return $GLOBALS['wpdb']->prefix . 'tsh_wa_workflows';
	}

	private function tbl_runs(): string {
		return $GLOBALS['wpdb']->prefix . 'tsh_wa_workflow_runs';
	}

	private function tbl_logs(): string {
		return $GLOBALS['wpdb']->prefix . 'tsh_wa_workflow_logs';
	}

	// =========================================================================
	// Workflows
	// =========================================================================

	/**
	 * Get a paginated list of workflows.
	 *
	 * @param array $args {
	 *   @type string $status   Filter by status: active|inactive|draft|all
	 *   @type string $search   Search name/description
	 *   @type int    $per_page Default 20
	 *   @type int    $page     Default 1
	 *   @type string $orderby  Default 'created_at'
	 *   @type string $order    'ASC'|'DESC'
	 * }
	 * @return array{ rows: array, total: int }
	 */
	public function get_workflows( array $args = [] ): array {
		global $wpdb;

		$defaults = [
			'status'   => 'all',
			'search'   => '',
			'per_page' => 20,
			'page'     => 1,
			'orderby'  => 'created_at',
			'order'    => 'DESC',
		];

		$args    = wp_parse_args( $args, $defaults );
		$where   = [ '1=1' ];
		$values  = [];
		$table   = $this->tbl_workflows();

		if ( 'all' !== $args['status'] && $args['status'] ) {
			$where[]  = 'status = %s';
			$values[] = $args['status'];
		}

		if ( $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '( name LIKE %s OR description LIKE %s )';
			$values[] = $like;
			$values[] = $like;
		}

		$where_sql = implode( ' AND ', $where );

		$allowed_cols = [ 'id', 'name', 'status', 'trigger_type', 'run_count', 'created_at', 'updated_at', 'last_run_at' ];
		$orderby      = in_array( $args['orderby'], $allowed_cols, true ) ? $args['orderby'] : 'created_at';
		$order        = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';

		$per_page = max( 1, min( 200, (int) $args['per_page'] ) );
		$offset   = ( max( 1, (int) $args['page'] ) - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count_sql = "SELECT COUNT(*) FROM `{$table}` WHERE {$where_sql}";
		if ( $values ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$count_sql = $wpdb->prepare( $count_sql, ...$values );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $wpdb->get_var( $count_sql );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows_sql   = "SELECT * FROM `{$table}` WHERE {$where_sql} ORDER BY `{$orderby}` {$order} LIMIT %d OFFSET %d";
		$row_values = array_merge( $values, [ $per_page, $offset ] );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $rows_sql, ...$row_values ), ARRAY_A );

		return [
			'rows'  => array_map( [ $this, 'decode_workflow_row' ], $rows ?: [] ),
			'total' => $total,
		];
	}

	/**
	 * Get a single workflow by ID.
	 */
	public function get_workflow( int $id ): ?array {
		global $wpdb;

		$table = $this->tbl_workflows();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $id ), ARRAY_A );

		return $row ? $this->decode_workflow_row( $row ) : null;
	}

	/**
	 * Get all active workflows that have a specific trigger type.
	 *
	 * @param string $trigger_type
	 * @return array<int, array>
	 */
	public function get_active_by_trigger( string $trigger_type ): array {
		global $wpdb;

		$table = $this->tbl_workflows();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE status = 'active' AND trigger_type = %s ORDER BY id ASC",
				$trigger_type
			),
			ARRAY_A
		);

		return array_map( [ $this, 'decode_workflow_row' ], $rows ?: [] );
	}

	/**
	 * Create a new workflow.
	 *
	 * @param array $data
	 * @return int|false New row ID or false on failure.
	 */
	public function create_workflow( array $data ): int|false {
		global $wpdb;

		$insert = [
			'name'           => sanitize_text_field( $data['name'] ?? 'Untitled Workflow' ),
			'description'    => sanitize_textarea_field( $data['description'] ?? '' ),
			'status'         => $this->valid_workflow_status( $data['status'] ?? 'draft' ),
			'trigger_type'   => sanitize_key( $data['trigger_type'] ?? '' ),
			'trigger_config' => wp_json_encode( $data['trigger_config'] ?? [] ),
			'nodes'          => wp_json_encode( $data['nodes'] ?? [] ),
			'edges'          => wp_json_encode( $data['edges'] ?? [] ),
			'settings'       => wp_json_encode( $data['settings'] ?? [] ),
			'created_by'     => absint( $data['created_by'] ?? get_current_user_id() ),
		];

		$result = $wpdb->insert( $this->tbl_workflows(), $insert );

		return $result ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Update an existing workflow.
	 *
	 * @param int   $id
	 * @param array $data
	 * @return bool
	 */
	public function update_workflow( int $id, array $data ): bool {
		global $wpdb;

		$update = [];

		if ( isset( $data['name'] ) ) {
			$update['name'] = sanitize_text_field( $data['name'] );
		}
		if ( isset( $data['description'] ) ) {
			$update['description'] = sanitize_textarea_field( $data['description'] );
		}
		if ( isset( $data['status'] ) ) {
			$update['status'] = $this->valid_workflow_status( $data['status'] );
		}
		if ( isset( $data['trigger_type'] ) ) {
			$update['trigger_type'] = sanitize_key( $data['trigger_type'] );
		}
		if ( isset( $data['trigger_config'] ) ) {
			$update['trigger_config'] = wp_json_encode( $data['trigger_config'] );
		}
		if ( isset( $data['nodes'] ) ) {
			$update['nodes'] = wp_json_encode( $data['nodes'] );
		}
		if ( isset( $data['edges'] ) ) {
			$update['edges'] = wp_json_encode( $data['edges'] );
		}
		if ( isset( $data['settings'] ) ) {
			$update['settings'] = wp_json_encode( $data['settings'] );
		}

		if ( empty( $update ) ) {
			return false;
		}

		$result = $wpdb->update( $this->tbl_workflows(), $update, [ 'id' => $id ] );

		return false !== $result;
	}

	/**
	 * Delete a workflow and all associated runs/logs.
	 */
	public function delete_workflow( int $id ): bool {
		global $wpdb;

		$runs  = $this->tbl_runs();
		$logs  = $this->tbl_logs();
		$table = $this->tbl_workflows();

		// Delete logs for this workflow's runs.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$logs}` WHERE workflow_id = %d", $id ) );

		// Delete runs.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$runs}` WHERE workflow_id = %d", $id ) );

		// Delete workflow.
		$result = $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );

		return (bool) $result;
	}

	/**
	 * Increment run_count and update last_run_at.
	 */
	public function increment_run_count( int $workflow_id ): void {
		global $wpdb;

		$table = $this->tbl_workflows();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET run_count = run_count + 1, last_run_at = NOW() WHERE id = %d", $workflow_id ) );
	}

	// =========================================================================
	// Runs
	// =========================================================================

	/**
	 * Create a new workflow run record.
	 *
	 * @param array $data
	 * @return int|false
	 */
	public function create_run( array $data ): int|false {
		global $wpdb;

		$insert = [
			'workflow_id'   => absint( $data['workflow_id'] ),
			'trigger_type'  => sanitize_text_field( $data['trigger_type'] ?? '' ),
			'trigger_data'  => wp_json_encode( $data['trigger_data'] ?? [] ),
			'status'        => $this->valid_run_status( $data['status'] ?? 'pending' ),
			'started_at'    => current_time( 'mysql' ),
			'context'       => wp_json_encode( $data['context'] ?? [] ),
		];

		$result = $wpdb->insert( $this->tbl_runs(), $insert );

		return $result ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Update a run record.
	 */
	public function update_run( int $run_id, array $data ): bool {
		global $wpdb;

		$update = [];

		if ( isset( $data['status'] ) ) {
			$update['status'] = $this->valid_run_status( $data['status'] );
		}
		if ( isset( $data['completed_at'] ) ) {
			$update['completed_at'] = sanitize_text_field( $data['completed_at'] );
		}
		if ( isset( $data['execution_time_ms'] ) ) {
			$update['execution_time_ms'] = absint( $data['execution_time_ms'] );
		}
		if ( isset( $data['steps_completed'] ) ) {
			$update['steps_completed'] = absint( $data['steps_completed'] );
		}
		if ( isset( $data['error_message'] ) ) {
			$update['error_message'] = sanitize_textarea_field( $data['error_message'] );
		}
		if ( isset( $data['context'] ) ) {
			$update['context'] = wp_json_encode( $data['context'] );
		}

		if ( empty( $update ) ) {
			return false;
		}

		$result = $wpdb->update( $this->tbl_runs(), $update, [ 'id' => $run_id ] );

		return false !== $result;
	}

	/**
	 * Get a single run by ID.
	 */
	public function get_run( int $run_id ): ?array {
		global $wpdb;

		$table = $this->tbl_runs();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $run_id ), ARRAY_A );

		if ( ! $row ) {
			return null;
		}

		$row['trigger_data'] = json_decode( $row['trigger_data'] ?? '{}', true ) ?: [];
		$row['context']      = json_decode( $row['context'] ?? '{}', true ) ?: [];

		return $row;
	}

	/**
	 * Get paginated runs with optional filters.
	 */
	public function get_runs( array $args = [] ): array {
		global $wpdb;

		$defaults = [
			'workflow_id' => null,
			'status'      => '',
			'per_page'    => 20,
			'page'        => 1,
			'orderby'     => 'started_at',
			'order'       => 'DESC',
		];

		$args   = wp_parse_args( $args, $defaults );
		$where  = [ '1=1' ];
		$values = [];
		$table  = $this->tbl_runs();

		if ( $args['workflow_id'] ) {
			$where[]  = 'workflow_id = %d';
			$values[] = absint( $args['workflow_id'] );
		}
		if ( $args['status'] ) {
			$where[]  = 'status = %s';
			$values[] = $args['status'];
		}

		$where_sql = implode( ' AND ', $where );
		$per_page  = max( 1, min( 200, (int) $args['per_page'] ) );
		$offset    = ( max( 1, (int) $args['page'] ) - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count_sql = "SELECT COUNT(*) FROM `{$table}` WHERE {$where_sql}";
		if ( $values ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$count_sql = $wpdb->prepare( $count_sql, ...$values );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $wpdb->get_var( $count_sql );

		$order    = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows_sql   = "SELECT * FROM `{$table}` WHERE {$where_sql} ORDER BY started_at {$order} LIMIT %d OFFSET %d";
		$row_values = array_merge( $values, [ $per_page, $offset ] );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $rows_sql, ...$row_values ), ARRAY_A ) ?: [];

		return [ 'rows' => $rows, 'total' => $total ];
	}

	/**
	 * Check if a workflow already has a pending or running execution for the given trigger context.
	 * Used to prevent duplicate executions.
	 *
	 * @param int    $workflow_id
	 * @param string $dedup_key  e.g. "order_123"
	 * @param int    $window_seconds  Look-back window. Default 3600 (1 hour).
	 */
	public function has_recent_run( int $workflow_id, string $dedup_key, int $window_seconds = 3600 ): bool {
		global $wpdb;

		$table    = $this->tbl_runs();
		$since    = gmdate( 'Y-m-d H:i:s', time() - $window_seconds );
		$like_key = '%' . $wpdb->esc_like( '"_dedup_key":"' . $dedup_key . '"' ) . '%';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table}`
				 WHERE workflow_id = %d
				   AND status IN ('pending','running','completed')
				   AND started_at >= %s
				   AND context LIKE %s",
				$workflow_id,
				$since,
				$like_key
			)
		);

		return (int) $count > 0;
	}

	// =========================================================================
	// Logs
	// =========================================================================

	/**
	 * Insert a log entry.
	 */
	public function create_log( array $data ): int|false {
		global $wpdb;

		$insert = [
			'run_id'      => absint( $data['run_id'] ?? 0 ),
			'workflow_id' => absint( $data['workflow_id'] ?? 0 ),
			'node_id'     => sanitize_text_field( $data['node_id'] ?? '' ),
			'node_type'   => sanitize_text_field( $data['node_type'] ?? '' ),
			'level'       => in_array( $data['level'] ?? 'info', [ 'info', 'warning', 'error', 'debug' ], true )
				? $data['level']
				: 'info',
			'message'     => sanitize_textarea_field( $data['message'] ?? '' ),
			'data'        => wp_json_encode( $data['data'] ?? null ),
		];

		$result = $wpdb->insert( $this->tbl_logs(), $insert );

		return $result ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Get logs for a run.
	 */
	public function get_logs_for_run( int $run_id ): array {
		global $wpdb;

		$table = $this->tbl_logs();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE run_id = %d ORDER BY id ASC", $run_id ),
			ARRAY_A
		) ?: [];

		foreach ( $rows as &$row ) {
			$row['data'] = json_decode( $row['data'] ?? 'null', true );
		}

		return $rows;
	}

	/**
	 * Prune old logs older than $days days.
	 */
	public function prune_logs( int $days = 30 ): int {
		global $wpdb;

		$table  = $this->tbl_logs();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query(
			$wpdb->prepare( "DELETE FROM `{$table}` WHERE created_at < %s", $cutoff )
		);
	}

	/**
	 * Prune old runs older than $days days.
	 */
	public function prune_runs( int $days = 60 ): int {
		global $wpdb;

		$runs   = $this->tbl_runs();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query(
			$wpdb->prepare( "DELETE FROM `{$runs}` WHERE started_at < %s AND status IN ('completed','failed','cancelled')", $cutoff )
		);
	}

	// =========================================================================
	// Analytics helpers
	// =========================================================================

	/**
	 * Aggregate run stats grouped by status.
	 */
	public function get_run_stats( ?int $workflow_id = null, int $days = 30 ): array {
		global $wpdb;

		$table  = $this->tbl_runs();
		$since  = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$extra  = $workflow_id ? $wpdb->prepare( ' AND workflow_id = %d', $workflow_id ) : '';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT status, COUNT(*) as cnt, AVG(execution_time_ms) as avg_ms
				 FROM `{$table}`
				 WHERE started_at >= %s{$extra}
				 GROUP BY status",
				$since
			),
			ARRAY_A
		) ?: [];

		$stats = [ 'total' => 0, 'completed' => 0, 'failed' => 0, 'running' => 0, 'pending' => 0, 'avg_ms' => 0 ];

		foreach ( $rows as $row ) {
			$status = $row['status'];
			$cnt    = (int) $row['cnt'];
			if ( isset( $stats[ $status ] ) ) {
				$stats[ $status ] = $cnt;
			}
			$stats['total'] += $cnt;
			if ( 'completed' === $status && $row['avg_ms'] ) {
				$stats['avg_ms'] = round( (float) $row['avg_ms'] );
			}
		}

		return $stats;
	}

	/**
	 * Get top N workflows by run count.
	 */
	public function get_top_workflows( int $limit = 5 ): array {
		global $wpdb;

		$wf   = $this->tbl_workflows();
		$runs = $this->tbl_runs();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT w.id, w.name, w.run_count, w.last_run_at,
				        COUNT(r.id) as recent_runs
				 FROM `{$wf}` w
				 LEFT JOIN `{$runs}` r ON r.workflow_id = w.id
				   AND r.started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
				 GROUP BY w.id
				 ORDER BY w.run_count DESC
				 LIMIT %d",
				$limit
			),
			ARRAY_A
		) ?: [];
	}

	// =========================================================================
	// Private helpers
	// =========================================================================

	private function decode_workflow_row( array $row ): array {
		$row['trigger_config'] = json_decode( $row['trigger_config'] ?? '{}', true ) ?: [];
		$row['nodes']          = json_decode( $row['nodes'] ?? '[]', true ) ?: [];
		$row['edges']          = json_decode( $row['edges'] ?? '[]', true ) ?: [];
		$row['settings']       = json_decode( $row['settings'] ?? '{}', true ) ?: [];

		return $row;
	}

	private function valid_workflow_status( string $status ): string {
		return in_array( $status, [ 'active', 'inactive', 'draft' ], true ) ? $status : 'draft';
	}

	private function valid_run_status( string $status ): string {
		return in_array( $status, [ 'pending', 'running', 'completed', 'failed', 'cancelled' ], true ) ? $status : 'pending';
	}
}
