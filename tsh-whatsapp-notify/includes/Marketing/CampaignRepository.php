<?php
/**
 * Campaign repository — all database CRUD for campaigns and related tables.
 *
 * @package TSH\WhatsAppNotify\Marketing
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Marketing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CampaignRepository
 *
 * Provides the data-access layer for:
 *  - tsh_wa_campaigns
 *  - tsh_wa_campaign_runs
 *  - tsh_wa_campaign_audience
 *  - tsh_wa_campaign_logs
 */
final class CampaignRepository {

	// -------------------------------------------------------------------------
	// Status constants
	// -------------------------------------------------------------------------

	public const STATUS_DRAFT     = 'draft';
	public const STATUS_SCHEDULED = 'scheduled';
	public const STATUS_RUNNING   = 'running';
	public const STATUS_PAUSED    = 'paused';
	public const STATUS_COMPLETED = 'completed';
	public const STATUS_FAILED    = 'failed';
	public const STATUS_CANCELLED = 'cancelled';
	public const STATUS_ARCHIVED  = 'archived';

	public const TYPE_ONETIME   = 'onetime';
	public const TYPE_SCHEDULED = 'scheduled';
	public const TYPE_RECURRING = 'recurring';

	public const RUN_STATUS_PENDING   = 'pending';
	public const RUN_STATUS_RUNNING   = 'running';
	public const RUN_STATUS_COMPLETED = 'completed';
	public const RUN_STATUS_FAILED    = 'failed';
	public const RUN_STATUS_PAUSED    = 'paused';

	// =========================================================================
	// CAMPAIGNS
	// =========================================================================

	/**
	 * Insert a new campaign.
	 *
	 * @param array<string, mixed> $data
	 * @return int|false New row ID or false on failure.
	 */
	public function create_campaign( array $data ): int|false {
		global $wpdb;

		$row = [
			'name'             => sanitize_text_field( $data['name'] ?? '' ),
			'description'      => sanitize_textarea_field( $data['description'] ?? '' ),
			'status'           => $data['status'] ?? self::STATUS_DRAFT,
			'type'             => $data['type'] ?? self::TYPE_ONETIME,
			'audience_config'  => wp_json_encode( $data['audience_config'] ?? [] ),
			'template_id'      => ! empty( $data['template_id'] ) ? absint( $data['template_id'] ) : null,
			'template_b_id'    => ! empty( $data['template_b_id'] ) ? absint( $data['template_b_id'] ) : null,
			'ab_split_ratio'   => isset( $data['ab_split_ratio'] ) ? min( 100, max( 0, (int) $data['ab_split_ratio'] ) ) : 50,
			'message_config'   => wp_json_encode( $data['message_config'] ?? [] ),
			'schedule_config'  => wp_json_encode( $data['schedule_config'] ?? [] ),
			'coupon_config'    => wp_json_encode( $data['coupon_config'] ?? [] ),
			'throttle_config'  => wp_json_encode( $data['throttle_config'] ?? [] ),
			'send_at'          => ! empty( $data['send_at'] ) ? sanitize_text_field( $data['send_at'] ) : null,
			'created_by'       => get_current_user_id() ?: null,
		];

		$inserted = $wpdb->insert( $wpdb->prefix . 'tsh_wa_campaigns', $row );

		return false !== $inserted ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Update an existing campaign.
	 *
	 * @param int                  $id
	 * @param array<string, mixed> $data
	 * @return bool
	 */
	public function update_campaign( int $id, array $data ): bool {
		global $wpdb;

		$allowed = [
			'name', 'description', 'status', 'type',
			'audience_config', 'template_id', 'template_b_id', 'ab_split_ratio',
			'message_config', 'schedule_config', 'coupon_config', 'throttle_config',
			'send_at', 'sent_at', 'completed_at',
			'total_audience', 'total_sent', 'total_delivered', 'total_read',
			'total_failed', 'total_coupons',
		];

		$update = [];
		foreach ( $allowed as $key ) {
			if ( ! array_key_exists( $key, $data ) ) { continue; }
			$val = $data[ $key ];
			if ( in_array( $key, [ 'audience_config', 'message_config', 'schedule_config', 'coupon_config', 'throttle_config' ], true ) ) {
				$update[ $key ] = is_array( $val ) ? wp_json_encode( $val ) : $val;
			} else {
				$update[ $key ] = $val;
			}
		}

		if ( empty( $update ) ) { return false; }

		return (bool) $wpdb->update(
			$wpdb->prefix . 'tsh_wa_campaigns',
			$update,
			[ 'id' => $id ]
		);
	}

	/**
	 * Get a single campaign by ID.
	 *
	 * @param int $id
	 * @return array<string, mixed>|null
	 */
	public function get_campaign( int $id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM `' . $wpdb->prefix . 'tsh_wa_campaigns` WHERE id = %d',
				$id
			),
			ARRAY_A
		);

		return $row ? $this->decode_campaign( $row ) : null;
	}

	/**
	 * Get campaigns with filtering and pagination.
	 *
	 * @param array<string, mixed> $args
	 * @return array{ rows: array<int, array<string, mixed>>, total: int }
	 */
	public function get_campaigns( array $args = [] ): array {
		global $wpdb;

		$defaults = [
			'status'   => '',
			'type'     => '',
			'search'   => '',
			'per_page' => 20,
			'page'     => 1,
			'orderby'  => 'created_at',
			'order'    => 'DESC',
		];

		$args    = wp_parse_args( $args, $defaults );
		$table   = $wpdb->prefix . 'tsh_wa_campaigns';
		$where   = [ '1=1' ];
		$values  = [];

		if ( $args['status'] && 'all' !== $args['status'] ) {
			$where[]  = 'status = %s';
			$values[] = sanitize_key( $args['status'] );
		}

		if ( $args['type'] ) {
			$where[]  = 'type = %s';
			$values[] = sanitize_key( $args['type'] );
		}

		if ( $args['search'] ) {
			$where[]  = 'name LIKE %s';
			$values[] = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
		}

		$where_sql  = implode( ' AND ', $where );
		$allowed_ob = [ 'id', 'name', 'status', 'type', 'send_at', 'created_at', 'total_sent' ];
		$orderby    = in_array( $args['orderby'], $allowed_ob, true ) ? $args['orderby'] : 'created_at';
		$order      = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';
		$per_page   = max( 1, min( (int) $args['per_page'], 200 ) );
		$offset     = ( max( 1, (int) $args['page'] ) - 1 ) * $per_page;

		// Count.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count_sql = "SELECT COUNT(*) FROM `{$table}` WHERE {$where_sql}";
		if ( $values ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$count_sql = $wpdb->prepare( $count_sql, ...$values );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $wpdb->get_var( $count_sql );

		// Rows.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows_sql    = "SELECT * FROM `{$table}` WHERE {$where_sql} ORDER BY `{$orderby}` {$order} LIMIT %d OFFSET %d";
		$row_values  = array_merge( $values, [ $per_page, $offset ] );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $rows_sql, ...$row_values ), ARRAY_A );

		return [
			'rows'  => array_map( [ $this, 'decode_campaign' ], $rows ?: [] ),
			'total' => $total,
		];
	}

	/**
	 * Delete a campaign and all associated data.
	 *
	 * @param int $id
	 * @return bool
	 */
	public function delete_campaign( int $id ): bool {
		global $wpdb;

		$wpdb->delete( $wpdb->prefix . 'tsh_wa_campaign_audience', [ 'campaign_id' => $id ], [ '%d' ] );
		$wpdb->delete( $wpdb->prefix . 'tsh_wa_campaign_logs',     [ 'campaign_id' => $id ], [ '%d' ] );
		$wpdb->delete( $wpdb->prefix . 'tsh_wa_campaign_runs',     [ 'campaign_id' => $id ], [ '%d' ] );

		return (bool) $wpdb->delete( $wpdb->prefix . 'tsh_wa_campaigns', [ 'id' => $id ], [ '%d' ] );
	}

	/**
	 * Decode JSON columns in a campaign row.
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function decode_campaign( array $row ): array {
		$json_cols = [ 'audience_config', 'message_config', 'schedule_config', 'coupon_config', 'throttle_config' ];
		foreach ( $json_cols as $col ) {
			if ( isset( $row[ $col ] ) && is_string( $row[ $col ] ) ) {
				$decoded = json_decode( $row[ $col ], true );
				$row[ $col ] = is_array( $decoded ) ? $decoded : [];
			}
		}
		return $row;
	}

	// =========================================================================
	// CAMPAIGN RUNS
	// =========================================================================

	/**
	 * Create a new campaign run record.
	 *
	 * @param int                  $campaign_id
	 * @param array<string, mixed> $context
	 * @return int|false
	 */
	public function create_run( int $campaign_id, array $context = [] ): int|false {
		global $wpdb;

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'tsh_wa_campaign_runs',
			[
				'campaign_id'  => $campaign_id,
				'status'       => self::RUN_STATUS_PENDING,
				'batch_offset' => 0,
				'context'      => wp_json_encode( $context ),
				'started_at'   => current_time( 'mysql' ),
			]
		);

		return false !== $inserted ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Update a campaign run.
	 *
	 * @param int                  $run_id
	 * @param array<string, mixed> $data
	 * @return bool
	 */
	public function update_run( int $run_id, array $data ): bool {
		global $wpdb;

		if ( isset( $data['context'] ) && is_array( $data['context'] ) ) {
			$data['context'] = wp_json_encode( $data['context'] );
		}

		return (bool) $wpdb->update(
			$wpdb->prefix . 'tsh_wa_campaign_runs',
			$data,
			[ 'id' => $run_id ]
		);
	}

	/**
	 * Get a campaign run by ID.
	 *
	 * @param int $run_id
	 * @return array<string, mixed>|null
	 */
	public function get_run( int $run_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM `' . $wpdb->prefix . 'tsh_wa_campaign_runs` WHERE id = %d',
				$run_id
			),
			ARRAY_A
		);

		if ( $row && isset( $row['context'] ) ) {
			$decoded = json_decode( $row['context'], true );
			$row['context'] = is_array( $decoded ) ? $decoded : [];
		}

		return $row ?: null;
	}

	/**
	 * Get the latest run for a campaign.
	 *
	 * @param int $campaign_id
	 * @return array<string, mixed>|null
	 */
	public function get_latest_run( int $campaign_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM `' . $wpdb->prefix . 'tsh_wa_campaign_runs`
				 WHERE campaign_id = %d ORDER BY id DESC LIMIT 1',
				$campaign_id
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Get all runs for a campaign (paginated).
	 *
	 * @param int $campaign_id
	 * @param int $per_page
	 * @param int $page
	 * @return array{ rows: array, total: int }
	 */
	public function get_runs( int $campaign_id, int $per_page = 20, int $page = 1 ): array {
		global $wpdb;

		$table  = $wpdb->prefix . 'tsh_wa_campaign_runs';
		$offset = ( max( 1, $page ) - 1 ) * $per_page;
		$total  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE campaign_id = %d", $campaign_id ) );
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE campaign_id = %d ORDER BY id DESC LIMIT %d OFFSET %d", $campaign_id, $per_page, $offset ), ARRAY_A );

		return [ 'rows' => $rows ?: [], 'total' => $total ];
	}

	// =========================================================================
	// CAMPAIGN AUDIENCE
	// =========================================================================

	/**
	 * Bulk-insert audience members for a run.
	 *
	 * @param int                         $campaign_id
	 * @param int                         $run_id
	 * @param array<int, array>           $members [ { customer_id, phone, email, name, template_variant } ]
	 * @return int Rows inserted.
	 */
	public function insert_audience_batch( int $campaign_id, int $run_id, array $members ): int {
		global $wpdb;

		if ( empty( $members ) ) { return 0; }

		$table   = $wpdb->prefix . 'tsh_wa_campaign_audience';
		$now     = current_time( 'mysql' );
		$inserts = [];
		$values  = [];

		foreach ( $members as $m ) {
			$inserts[] = '(%d, %d, %d, %s, %s, %s, %s, %s)';
			$values[]  = $campaign_id;
			$values[]  = $run_id;
			$values[]  = (int) ( $m['customer_id'] ?? 0 );
			$values[]  = sanitize_text_field( $m['phone'] ?? '' );
			$values[]  = sanitize_email( $m['email'] ?? '' );
			$values[]  = sanitize_text_field( $m['name'] ?? '' );
			$values[]  = sanitize_key( $m['template_variant'] ?? 'a' );
			$values[]  = $now;
		}

		$chunks   = array_chunk( $inserts, 200 );
		$inserted = 0;

		foreach ( $chunks as $chunk ) {
			$sql = "INSERT INTO `{$table}` (campaign_id, run_id, customer_id, phone, email, name, template_variant, created_at) VALUES " . implode( ',', $chunk );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$inserted += (int) $wpdb->query( $wpdb->prepare( $sql, ...$values ) );
			$values    = array_slice( $values, count( $chunk ) * 8 );
		}

		return $inserted;
	}

	/**
	 * Update a single audience member's status after send attempt.
	 *
	 * @param int    $audience_id
	 * @param string $status       'queued'|'sent'|'failed'|'skipped'
	 * @param int    $queue_id     tsh_wa_queue.id
	 * @param string $coupon_code
	 * @param string $error
	 * @return bool
	 */
	public function update_audience_member(
		int $audience_id,
		string $status,
		int $queue_id = 0,
		string $coupon_code = '',
		string $error = ''
	): bool {
		global $wpdb;

		$data = [ 'status' => $status ];
		if ( $queue_id )    { $data['queue_id']     = $queue_id; }
		if ( $coupon_code ) { $data['coupon_code']  = $coupon_code; }
		if ( $error )       { $data['error_message'] = sanitize_textarea_field( $error ); }
		if ( 'sent' === $status || 'queued' === $status ) {
			$data['sent_at'] = current_time( 'mysql' );
		}

		return (bool) $wpdb->update(
			$wpdb->prefix . 'tsh_wa_campaign_audience',
			$data,
			[ 'id' => $audience_id ]
		);
	}

	/**
	 * Get paginated audience members for a run.
	 *
	 * @param int    $run_id
	 * @param string $status
	 * @param int    $limit
	 * @param int    $offset
	 * @return array<int, array<string, mixed>>
	 */
	public function get_audience_batch( int $run_id, string $status = 'pending', int $limit = 100, int $offset = 0 ): array {
		global $wpdb;

		$table  = $wpdb->prefix . 'tsh_wa_campaign_audience';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE run_id = %d AND status = %s ORDER BY id ASC LIMIT %d OFFSET %d",
				$run_id,
				$status,
				$limit,
				$offset
			),
			ARRAY_A
		);

		return $rows ?: [];
	}

	/**
	 * Count audience members for a run by status.
	 *
	 * @param int    $run_id
	 * @param string $status  Empty string = all.
	 * @return int
	 */
	public function count_audience( int $run_id, string $status = '' ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'tsh_wa_campaign_audience';
		if ( $status ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE run_id = %d AND status = %s", $run_id, $status ) );
		}

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE run_id = %d", $run_id ) );
	}

	/**
	 * Get audience stats for a run.
	 *
	 * @param int $run_id
	 * @return array<string, int>
	 */
	public function get_audience_stats( int $run_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'tsh_wa_campaign_audience';
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT status, COUNT(*) AS cnt FROM `{$table}` WHERE run_id = %d GROUP BY status", $run_id ),
			ARRAY_A
		);

		$stats = [ 'pending' => 0, 'queued' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0 ];
		foreach ( (array) $rows as $row ) {
			$stats[ $row['status'] ] = (int) $row['cnt'];
		}

		return $stats;
	}

	// =========================================================================
	// CAMPAIGN LOGS
	// =========================================================================

	/**
	 * Write a campaign log entry.
	 *
	 * @param int                  $campaign_id
	 * @param int                  $run_id      0 = no specific run.
	 * @param string               $level       'info'|'warning'|'error'|'debug'
	 * @param string               $message
	 * @param array<string, mixed> $data
	 * @return bool
	 */
	public function log( int $campaign_id, int $run_id, string $level, string $message, array $data = [] ): bool {
		global $wpdb;

		$result = $wpdb->insert(
			$wpdb->prefix . 'tsh_wa_campaign_logs',
			[
				'campaign_id' => $campaign_id,
				'run_id'      => $run_id ?: null,
				'level'       => $level,
				'message'     => $message,
				'data'        => $data ? wp_json_encode( $data ) : null,
			]
		);

		return false !== $result;
	}

	/**
	 * Get log entries for a campaign/run.
	 *
	 * @param int $campaign_id
	 * @param int $run_id       0 = all runs.
	 * @param int $limit
	 * @return array<int, array<string, mixed>>
	 */
	public function get_logs( int $campaign_id, int $run_id = 0, int $limit = 100 ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'tsh_wa_campaign_logs';

		if ( $run_id ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM `{$table}` WHERE campaign_id = %d AND run_id = %d ORDER BY id DESC LIMIT %d", $campaign_id, $run_id, $limit ),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM `{$table}` WHERE campaign_id = %d ORDER BY id DESC LIMIT %d", $campaign_id, $limit ),
				ARRAY_A
			);
		}

		return $rows ?: [];
	}

	/**
	 * Prune old log entries beyond retention period.
	 *
	 * @param int $days
	 * @return int Rows deleted.
	 */
	public function prune_logs( int $days = 30 ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'tsh_wa_campaign_logs';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query(
			$wpdb->prepare( "DELETE FROM `{$table}` WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)", $days )
		);
	}

	// =========================================================================
	// ANALYTICS HELPERS
	// =========================================================================

	/**
	 * Aggregate campaign stats across all campaigns.
	 *
	 * @param int $days  Lookback window.
	 * @return array<string, mixed>
	 */
	public function get_overview_stats( int $days = 30 ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'tsh_wa_campaigns';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS total_campaigns,
					SUM(IF(status = 'running', 1, 0))    AS running,
					SUM(IF(status = 'completed', 1, 0))  AS completed,
					SUM(IF(status = 'failed', 1, 0))     AS failed,
					SUM(IF(status = 'paused', 1, 0))     AS paused,
					SUM(total_sent)                       AS total_sent,
					SUM(total_delivered)                  AS total_delivered,
					SUM(total_read)                       AS total_read,
					SUM(total_failed)                     AS total_failed,
					SUM(total_coupons)                    AS total_coupons
				 FROM `{$table}`
				 WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
				$days
			),
			ARRAY_A
		);

		return $row ?: [];
	}

	/**
	 * Get per-campaign stats for analytics table.
	 *
	 * @param int $campaign_id
	 * @return array<string, mixed>
	 */
	public function get_campaign_stats( int $campaign_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'tsh_wa_campaigns';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $campaign_id ),
			ARRAY_A
		);

		return $row ? $this->decode_campaign( $row ) : [];
	}
}
