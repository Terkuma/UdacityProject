<?php
/**
 * Message queue service.
 *
 * @package TSH\WhatsAppNotify\Queue
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Queue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Queue
 *
 * Manages the outbound WhatsApp message queue stored in {prefix}tsh_wa_queue.
 *
 * This class provides the full data-access layer for the queue:
 *   add()     — push a new item into the queue
 *   remove()  — permanently delete an item
 *   retry()   — reset a failed item for re-processing
 *   process() — stub: execution logic injected in a later phase
 *   clear()   — truncate the queue table
 *   count()   — item counts by status
 *
 * No messages are dispatched in Phase 1; the API layer is a future phase.
 */
class Queue {

	// -------------------------------------------------------------------------
	// Status constants
	// -------------------------------------------------------------------------

	public const STATUS_PENDING    = 'pending';
	public const STATUS_PROCESSING = 'processing';
	public const STATUS_SENT       = 'sent';
	public const STATUS_FAILED     = 'failed';
	public const STATUS_CANCELLED  = 'cancelled';

	/** @var array<string> All valid queue statuses. */
	public const ALL_STATUSES = [
		self::STATUS_PENDING,
		self::STATUS_PROCESSING,
		self::STATUS_SENT,
		self::STATUS_FAILED,
		self::STATUS_CANCELLED,
	];

	// -------------------------------------------------------------------------
	// Add
	// -------------------------------------------------------------------------

	/**
	 * Push a new message item into the queue.
	 *
	 * @param array<string, mixed> $args {
	 *   @type string   $phone        Required. E.164-formatted recipient number.
	 *   @type string   $message      Required. Message body.
	 *   @type int|null $template_id  Optional. Template DB id.
	 *   @type int|null $order_id     Optional. Associated WooCommerce order id.
	 *   @type int      $priority     Optional. 1 (high) – 10 (low). Default 5.
	 *   @type int      $max_attempts Optional. Maximum send attempts. Default 3.
	 *   @type string   $scheduled_at Optional. MySQL datetime to send at. Default now.
	 *   @type array    $meta         Optional. Arbitrary key/value meta.
	 * }
	 * @return int|false Inserted row ID, or false on failure.
	 */
	public function add( array $args ): int|false {
		global $wpdb;

		if ( empty( $args['phone'] ) || empty( $args['message'] ) ) {
			return false;
		}

		$defaults = [
			'template_id'  => null,
			'order_id'     => null,
			'priority'     => 5,
			'max_attempts' => 3,
			'scheduled_at' => current_time( 'mysql' ),
			'meta'         => [],
		];

		$args = wp_parse_args( $args, $defaults );

		$data = [
			'phone'        => sanitize_text_field( $args['phone'] ),
			'message'      => wp_kses_post( $args['message'] ),
			'template_id'  => $args['template_id'] ? absint( $args['template_id'] ) : null,
			'order_id'     => $args['order_id'] ? absint( $args['order_id'] ) : null,
			'status'       => self::STATUS_PENDING,
			'priority'     => min( max( absint( $args['priority'] ), 1 ), 10 ),
			'attempts'     => 0,
			'max_attempts' => absint( $args['max_attempts'] ),
			'scheduled_at' => sanitize_text_field( $args['scheduled_at'] ),
			'meta'         => ! empty( $args['meta'] ) ? wp_json_encode( $args['meta'] ) : null,
		];

		$formats = [ '%s', '%s', '%d', '%d', '%s', '%d', '%d', '%d', '%s', '%s' ];

		// Nullable integer columns: pass null explicitly.
		if ( null === $data['template_id'] ) {
			$formats[2] = null;
		}
		if ( null === $data['order_id'] ) {
			$formats[3] = null;
		}

		$inserted = $wpdb->insert( $wpdb->prefix . 'tsh_wa_queue', $data );

		if ( false === $inserted ) {
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	// -------------------------------------------------------------------------
	// Remove
	// -------------------------------------------------------------------------

	/**
	 * Permanently delete a queue item by ID.
	 *
	 * @param int $id Queue item ID.
	 * @return bool True on success.
	 */
	public function remove( int $id ): bool {
		global $wpdb;

		$deleted = $wpdb->delete(
			$wpdb->prefix . 'tsh_wa_queue',
			[ 'id' => $id ],
			[ '%d' ]
		);

		return (bool) $deleted;
	}

	// -------------------------------------------------------------------------
	// Retry
	// -------------------------------------------------------------------------

	/**
	 * Reset a failed queue item so it will be picked up again on the next run.
	 *
	 * @param int $id Queue item ID.
	 * @return bool True on success.
	 */
	public function retry( int $id ): bool {
		global $wpdb;

		$updated = $wpdb->update(
			$wpdb->prefix . 'tsh_wa_queue',
			[
				'status'        => self::STATUS_PENDING,
				'attempts'      => 0,
				'error_message' => null,
				'scheduled_at'  => current_time( 'mysql' ),
			],
			[ 'id' => $id ],
			[ '%s', '%d', null, '%s' ],
			[ '%d' ]
		);

		return (bool) $updated;
	}

	// -------------------------------------------------------------------------
	// Process (stub)
	// -------------------------------------------------------------------------

	/**
	 * Process pending queue items.
	 *
	 * Implementation is deferred to the API phase.
	 * This stub exists so the cron scheduler can already reference it.
	 *
	 * @param int $batch_size Maximum number of items to process per run.
	 * @return int Number of items attempted.
	 */
	public function process( int $batch_size = 10 ): int {
		/*
		 * Phase 2 implementation:
		 *  1. Lock items (status = 'processing').
		 *  2. Call WhatsApp Cloud API for each.
		 *  3. Update status to 'sent' or 'failed'.
		 *  4. Log result via Logger.
		 */
		return 0;
	}

	// -------------------------------------------------------------------------
	// Clear
	// -------------------------------------------------------------------------

	/**
	 * Permanently remove all queue items matching a status (or all items).
	 *
	 * @param string $status One of the STATUS_* constants, or 'all'.
	 * @return int Number of rows deleted.
	 */
	public function clear( string $status = 'all' ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'tsh_wa_queue';

		if ( 'all' === $status ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return (int) $wpdb->query( "TRUNCATE TABLE `{$table}`" );
		}

		if ( ! in_array( $status, self::ALL_STATUSES, true ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$table}` WHERE status = %s",
				$status
			)
		);
	}

	// -------------------------------------------------------------------------
	// Count
	// -------------------------------------------------------------------------

	/**
	 * Return item counts per status.
	 *
	 * @param string $status Optional. Limit count to a single status.
	 * @return array<string, int>|int
	 *   - If $status is provided: integer count for that status.
	 *   - Otherwise: associative array keyed by status.
	 */
	public function count( string $status = '' ): array|int {
		global $wpdb;

		$table = $wpdb->prefix . 'tsh_wa_queue';

		if ( $status ) {
			if ( ! in_array( $status, self::ALL_STATUSES, true ) ) {
				return 0;
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM `{$table}` WHERE status = %s",
					$status
				)
			);
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS cnt FROM `{$table}` GROUP BY status" );

		$counts = array_fill_keys( self::ALL_STATUSES, 0 );
		foreach ( (array) $rows as $row ) {
			if ( isset( $counts[ $row->status ] ) ) {
				$counts[ $row->status ] = (int) $row->cnt;
			}
		}

		return $counts;
	}

	// -------------------------------------------------------------------------
	// Fetch helpers
	// -------------------------------------------------------------------------

	/**
	 * Get a single queue item by ID.
	 *
	 * @param int $id
	 * @return object|null
	 */
	public function get( int $id ): ?object {
		global $wpdb;

		$table = $wpdb->prefix . 'tsh_wa_queue';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $id )
		) ?: null;
	}

	/**
	 * Get multiple queue items with optional filtering and pagination.
	 *
	 * @param array<string, mixed> $args {
	 *   @type string $status   Filter by status.
	 *   @type int    $per_page Rows per page (default 20).
	 *   @type int    $page     Page number (default 1).
	 *   @type string $orderby  Column (default 'scheduled_at').
	 *   @type string $order    'ASC' or 'DESC' (default 'ASC').
	 * }
	 * @return array{ rows: array<int, object>, total: int }
	 */
	public function get_items( array $args = [] ): array {
		global $wpdb;

		$table    = $wpdb->prefix . 'tsh_wa_queue';
		$defaults = [
			'status'   => '',
			'per_page' => 20,
			'page'     => 1,
			'orderby'  => 'scheduled_at',
			'order'    => 'ASC',
		];

		$args     = wp_parse_args( $args, $defaults );
		$where    = [ '1=1' ];
		$values   = [];

		if ( $args['status'] && in_array( $args['status'], self::ALL_STATUSES, true ) ) {
			$where[]  = 'status = %s';
			$values[] = $args['status'];
		}

		$where_sql = implode( ' AND ', $where );

		$allowed_orderby = [ 'id', 'phone', 'status', 'priority', 'scheduled_at', 'created_at' ];
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'scheduled_at';
		$order           = 'DESC' === strtoupper( $args['order'] ) ? 'DESC' : 'ASC';

		$per_page = min( absint( $args['per_page'] ), 200 );
		$per_page = max( $per_page, 1 );
		$offset   = ( absint( $args['page'] ) - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count_sql = "SELECT COUNT(*) FROM `{$table}` WHERE {$where_sql}";
		if ( ! empty( $values ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$count_sql = $wpdb->prepare( $count_sql, ...$values );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $wpdb->get_var( $count_sql );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows_sql   = "SELECT * FROM `{$table}` WHERE {$where_sql} ORDER BY `{$orderby}` {$order} LIMIT %d OFFSET %d";
		$row_values = array_merge( $values, [ $per_page, $offset ] );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $rows_sql, ...$row_values ) );

		return [
			'rows'  => $rows ?: [],
			'total' => $total,
		];
	}
}
