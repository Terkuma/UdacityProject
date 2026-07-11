<?php
/**
 * Logger service.
 *
 * @package TSH\WhatsAppNotify\Logger
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Logger
 *
 * Central logging service for TSH WhatsApp Notify.
 *
 * Supports five severity levels (debug, info/success, warning, error) and
 * stores entries in the {prefix}tsh_wa_logs table. Optionally mirrors entries
 * to a flat log file inside the plugin's /logs directory.
 *
 * Usage:
 *   $logger = new Logger();
 *   $logger->info( 'Queue processed', [ 'batch_size' => 10 ] );
 *   $logger->error( 'API call failed', [ 'http_code' => 401 ], 'api' );
 */
class Logger {

	// -------------------------------------------------------------------------
	// Level constants
	// -------------------------------------------------------------------------

	public const LEVEL_DEBUG   = 'debug';
	public const LEVEL_INFO    = 'info';
	public const LEVEL_SUCCESS = 'success';
	public const LEVEL_WARNING = 'warning';
	public const LEVEL_ERROR   = 'error';

	/** @var array<string, int> Numeric priority for each level. */
	private const LEVEL_PRIORITY = [
		self::LEVEL_DEBUG   => 0,
		self::LEVEL_INFO    => 1,
		self::LEVEL_SUCCESS => 1,
		self::LEVEL_WARNING => 2,
		self::LEVEL_ERROR   => 3,
	];

	/** @var string Configured minimum log level. */
	private string $min_level;

	/** @var bool Whether to persist to the database. */
	private bool $log_to_db;

	/** @var bool Whether to mirror to a flat file. */
	private bool $log_to_file;

	/**
	 * Constructor — reads active settings.
	 */
	public function __construct() {
		$settings          = get_option( 'tsh_wa_logging_settings', [] );
		$this->min_level   = $settings['log_level']   ?? self::LEVEL_INFO;
		$this->log_to_db   = (bool) ( $settings['log_to_db']   ?? true );
		$this->log_to_file = (bool) ( $settings['log_to_file'] ?? false );
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Log a message at an arbitrary level.
	 *
	 * @param string               $level   One of the LEVEL_* constants.
	 * @param string               $message Human-readable log message.
	 * @param array<string, mixed> $context Optional contextual data (serialised as JSON).
	 * @param string               $source  Originating module / class identifier.
	 * @param int|null             $order_id  Associated WooCommerce order ID.
	 * @param string|null          $phone   Associated phone number.
	 */
	public function log(
		string $level,
		string $message,
		array $context = [],
		string $source = 'system',
		?int $order_id = null,
		?string $phone = null
	): void {
		if ( ! $this->should_log( $level ) ) {
			return;
		}

		$entry = [
			'level'    => sanitize_key( $level ),
			'source'   => sanitize_text_field( $source ),
			'message'  => sanitize_textarea_field( $message ),
			'context'  => ! empty( $context ) ? wp_json_encode( $context ) : null,
			'order_id' => $order_id,
			'phone'    => $phone ? sanitize_text_field( $phone ) : null,
		];

		if ( $this->log_to_db ) {
			$this->write_to_db( $entry );
		}

		if ( $this->log_to_file ) {
			$this->write_to_file( $entry );
		}
	}

	/**
	 * Log at DEBUG level.
	 *
	 * @param string               $message
	 * @param array<string, mixed> $context
	 * @param string               $source
	 */
	public function debug( string $message, array $context = [], string $source = 'system' ): void {
		$this->log( self::LEVEL_DEBUG, $message, $context, $source );
	}

	/**
	 * Log at INFO level.
	 *
	 * @param string               $message
	 * @param array<string, mixed> $context
	 * @param string               $source
	 */
	public function info( string $message, array $context = [], string $source = 'system' ): void {
		$this->log( self::LEVEL_INFO, $message, $context, $source );
	}

	/**
	 * Log a success event (semantic alias for INFO).
	 *
	 * @param string               $message
	 * @param array<string, mixed> $context
	 * @param string               $source
	 * @param int|null             $order_id
	 * @param string|null          $phone
	 */
	public function success(
		string $message,
		array $context = [],
		string $source = 'system',
		?int $order_id = null,
		?string $phone = null
	): void {
		$this->log( self::LEVEL_SUCCESS, $message, $context, $source, $order_id, $phone );
	}

	/**
	 * Log at WARNING level.
	 *
	 * @param string               $message
	 * @param array<string, mixed> $context
	 * @param string               $source
	 */
	public function warning( string $message, array $context = [], string $source = 'system' ): void {
		$this->log( self::LEVEL_WARNING, $message, $context, $source );
	}

	/**
	 * Log at ERROR level.
	 *
	 * @param string               $message
	 * @param array<string, mixed> $context
	 * @param string               $source
	 * @param int|null             $order_id
	 * @param string|null          $phone
	 */
	public function error(
		string $message,
		array $context = [],
		string $source = 'system',
		?int $order_id = null,
		?string $phone = null
	): void {
		$this->log( self::LEVEL_ERROR, $message, $context, $source, $order_id, $phone );
	}

	// -------------------------------------------------------------------------
	// Query helpers
	// -------------------------------------------------------------------------

	/**
	 * Fetch log entries with optional filtering, pagination, and search.
	 *
	 * @param array<string, mixed> $args {
	 *   @type string   $level     Filter by log level.
	 *   @type string   $source    Filter by source.
	 *   @type string   $search    Keyword search against message column.
	 *   @type int      $order_id  Filter by WooCommerce order ID.
	 *   @type string   $date_from ISO-8601 start date (Y-m-d).
	 *   @type string   $date_to   ISO-8601 end date (Y-m-d).
	 *   @type int      $per_page  Rows per page (default 20, max 200).
	 *   @type int      $page      1-based page number.
	 *   @type string   $orderby   Column to sort by (default 'created_at').
	 *   @type string   $order     'ASC' or 'DESC' (default 'DESC').
	 * }
	 * @return array{ rows: array<int, object>, total: int }
	 */
	public function get_logs( array $args = [] ): array {
		global $wpdb;

		$table    = $wpdb->prefix . 'tsh_wa_logs';
		$defaults = [
			'level'     => '',
			'source'    => '',
			'search'    => '',
			'order_id'  => 0,
			'date_from' => '',
			'date_to'   => '',
			'per_page'  => 20,
			'page'      => 1,
			'orderby'   => 'created_at',
			'order'     => 'DESC',
		];

		$args     = wp_parse_args( $args, $defaults );
		$where    = [ '1=1' ];
		$values   = [];

		if ( ! empty( $args['level'] ) ) {
			$where[]  = 'level = %s';
			$values[] = $args['level'];
		}

		if ( ! empty( $args['source'] ) ) {
			$where[]  = 'source = %s';
			$values[] = $args['source'];
		}

		if ( ! empty( $args['search'] ) ) {
			$where[]  = 'message LIKE %s';
			$values[] = '%' . $wpdb->esc_like( $args['search'] ) . '%';
		}

		if ( ! empty( $args['order_id'] ) ) {
			$where[]  = 'order_id = %d';
			$values[] = absint( $args['order_id'] );
		}

		if ( ! empty( $args['date_from'] ) ) {
			$where[]  = 'created_at >= %s';
			$values[] = sanitize_text_field( $args['date_from'] ) . ' 00:00:00';
		}

		if ( ! empty( $args['date_to'] ) ) {
			$where[]  = 'created_at <= %s';
			$values[] = sanitize_text_field( $args['date_to'] ) . ' 23:59:59';
		}

		$where_sql = implode( ' AND ', $where );

		// Whitelist orderby / order to prevent injection.
		$allowed_orderby = [ 'id', 'level', 'source', 'created_at' ];
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
		$order           = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		$per_page = min( absint( $args['per_page'] ), 200 );
		$per_page = max( $per_page, 1 );
		$offset   = ( absint( $args['page'] ) - 1 ) * $per_page;

		// Count total.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count_sql = "SELECT COUNT(*) FROM `{$table}` WHERE {$where_sql}";
		if ( ! empty( $values ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$count_sql = $wpdb->prepare( $count_sql, ...$values );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $wpdb->get_var( $count_sql );

		// Fetch rows.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows_sql = "SELECT * FROM `{$table}` WHERE {$where_sql} ORDER BY `{$orderby}` {$order} LIMIT %d OFFSET %d";
		$row_values = array_merge( $values, [ $per_page, $offset ] );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $rows_sql, ...$row_values ) );

		return [
			'rows'  => $rows ?: [],
			'total' => $total,
		];
	}

	/**
	 * Count log entries grouped by level (for the dashboard summary cards).
	 *
	 * @return array<string, int>
	 */
	public function get_counts_by_level(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'tsh_wa_logs';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT level, COUNT(*) AS cnt FROM `{$table}` GROUP BY level" );

		$counts = [];
		foreach ( (array) $rows as $row ) {
			$counts[ $row->level ] = (int) $row->cnt;
		}

		return $counts;
	}

	/**
	 * Count log entries created today at or after midnight.
	 *
	 * @param string $level If provided, filter by this level.
	 * @return int
	 */
	public function count_today( string $level = '' ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'tsh_wa_logs';
		$today = current_time( 'Y-m-d' ) . ' 00:00:00';

		if ( $level ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM `{$table}` WHERE level = %s AND created_at >= %s",
					$level,
					$today
				)
			);
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table}` WHERE created_at >= %s",
				$today
			)
		);
	}

	/**
	 * Delete log entries older than a given number of days.
	 *
	 * @param int $days Retention window in days.
	 * @return int Number of rows deleted.
	 */
	public function prune( int $days = 30 ): int {
		global $wpdb;

		$table     = $wpdb->prefix . 'tsh_wa_logs';
		$threshold = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$table}` WHERE created_at < %s",
				$threshold
			)
		);

		return (int) $deleted;
	}

	/**
	 * Delete all log entries.
	 *
	 * @return int Number of rows deleted.
	 */
	public function clear_all(): int {
		global $wpdb;

		$table = $wpdb->prefix . 'tsh_wa_logs';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query( "TRUNCATE TABLE `{$table}`" );
	}

	// -------------------------------------------------------------------------
	// Internal writers
	// -------------------------------------------------------------------------

	/**
	 * Persist a log entry to the database.
	 *
	 * @param array<string, mixed> $entry
	 */
	private function write_to_db( array $entry ): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'tsh_wa_logs',
			[
				'level'    => $entry['level'],
				'source'   => $entry['source'],
				'message'  => $entry['message'],
				'context'  => $entry['context'],
				'order_id' => $entry['order_id'],
				'phone'    => $entry['phone'],
			],
			[ '%s', '%s', '%s', '%s', '%d', '%s' ]
		);
	}

	/**
	 * Mirror a log entry to the flat-file log.
	 *
	 * @param array<string, mixed> $entry
	 */
	private function write_to_file( array $entry ): void {
		if ( ! is_dir( TSH_WA_LOG_DIR ) ) {
			return;
		}

		$file = TSH_WA_LOG_DIR . 'tsh-wa-' . current_time( 'Y-m-d' ) . '.log';
		$line = sprintf(
			"[%s] [%s] [%s] %s %s\n",
			current_time( 'Y-m-d H:i:s' ),
			strtoupper( $entry['level'] ),
			$entry['source'],
			$entry['message'],
			$entry['context'] ?? ''
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $file, $line, FILE_APPEND | LOCK_EX );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Determine whether a given level meets the minimum threshold.
	 *
	 * @param string $level Incoming level.
	 * @return bool
	 */
	private function should_log( string $level ): bool {
		$incoming = self::LEVEL_PRIORITY[ $level ]   ?? 1;
		$minimum  = self::LEVEL_PRIORITY[ $this->min_level ] ?? 1;

		return $incoming >= $minimum;
	}
}
