<?php
/**
 * Data-access layer for the tsh_wa_meta_templates table.
 *
 * @package TSH\WhatsAppNotify\Templates
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Templates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TemplateRepository
 *
 * All database interaction for Meta WhatsApp templates lives here.
 * Every query uses $wpdb->prepare() — no raw interpolation of user input.
 */
final class TemplateRepository {

	// -------------------------------------------------------------------------
	// Status constants (mirrors Meta API values)
	// -------------------------------------------------------------------------

	public const STATUS_APPROVED  = 'APPROVED';
	public const STATUS_PENDING   = 'PENDING';
	public const STATUS_REJECTED  = 'REJECTED';
	public const STATUS_PAUSED    = 'PAUSED';
	public const STATUS_DISABLED  = 'DISABLED';
	public const STATUS_DELETED   = 'DELETED';
	public const STATUS_DRAFT     = 'DRAFT';
	public const STATUS_EXPIRED   = 'EXPIRED';

	// Quality score constants.
	public const QUALITY_HIGH    = 'HIGH';
	public const QUALITY_MEDIUM  = 'MEDIUM';
	public const QUALITY_LOW     = 'LOW';
	public const QUALITY_UNKNOWN = 'UNKNOWN';

	// -------------------------------------------------------------------------
	// Read operations
	// -------------------------------------------------------------------------

	/**
	 * Find a template by its primary key.
	 *
	 * @param int $id
	 * @return object|null
	 */
	public function find( int $id ): ?object {
		global $wpdb;
		$table = $wpdb->prefix . 'tsh_wa_meta_templates';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d LIMIT 1", $id )
		);
		return $row ?: null;
	}

	/**
	 * Find a template by Meta template ID.
	 *
	 * @param string $meta_id
	 * @return object|null
	 */
	public function find_by_meta_id( string $meta_id ): ?object {
		global $wpdb;
		$table = $wpdb->prefix . 'tsh_wa_meta_templates';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE meta_template_id = %s LIMIT 1", $meta_id )
		);
		return $row ?: null;
	}

	/**
	 * Find a template by name and optional language.
	 *
	 * @param string $name
	 * @param string $language Empty = any language.
	 * @return object|null
	 */
	public function find_by_name( string $name, string $language = '' ): ?object {
		global $wpdb;
		$table = $wpdb->prefix . 'tsh_wa_meta_templates';

		if ( $language ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM `{$table}` WHERE template_name = %s AND language = %s LIMIT 1",
					$name,
					$language
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM `{$table}` WHERE template_name = %s LIMIT 1",
					$name
				)
			);
		}

		return $row ?: null;
	}

	/**
	 * Return a paginated, filtered list of templates.
	 *
	 * Supported $args keys:
	 *   status      string|array   Filter by status.
	 *   category    string         Filter by category.
	 *   language    string         Filter by language.
	 *   quality     string         Filter by quality score.
	 *   search      string         Full-text search (name + body).
	 *   orderby     string         Column name (default: created_at).
	 *   order       string         ASC|DESC (default: DESC).
	 *   per_page    int            Items per page (default: 25).
	 *   page        int            Page number (default: 1).
	 *
	 * @param array<string, mixed> $args
	 * @return array{ rows: array<int, object>, total: int }
	 */
	public function get_all( array $args = [] ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'tsh_wa_meta_templates';

		$where    = [];
		$bindings = [];

		// Status filter.
		if ( ! empty( $args['status'] ) ) {
			$statuses = (array) $args['status'];
			$statuses = array_map( 'sanitize_text_field', $statuses );
			$ph       = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
			$where[]  = "status IN ({$ph})";
			$bindings = array_merge( $bindings, $statuses );
		}

		// Category filter.
		if ( ! empty( $args['category'] ) ) {
			$where[]    = 'category = %s';
			$bindings[] = sanitize_text_field( $args['category'] );
		}

		// Language filter.
		if ( ! empty( $args['language'] ) ) {
			$where[]    = 'language = %s';
			$bindings[] = sanitize_text_field( $args['language'] );
		}

		// Quality filter.
		if ( ! empty( $args['quality'] ) ) {
			$where[]    = 'quality_score = %s';
			$bindings[] = sanitize_text_field( $args['quality'] );
		}

		// Search.
		if ( ! empty( $args['search'] ) ) {
			$search     = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
			$where[]    = '(template_name LIKE %s OR body LIKE %s)';
			$bindings[] = $search;
			$bindings[] = $search;
		}

		// Recently used filter.
		if ( ! empty( $args['recently_used'] ) ) {
			$where[] = 'last_used IS NOT NULL';
		}

		// Never used filter.
		if ( ! empty( $args['never_used'] ) ) {
			$where[] = 'usage_count = 0';
		}

		$where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

		// Ordering.
		$allowed_orderby = [ 'id', 'template_name', 'category', 'language', 'status', 'quality_score', 'usage_count', 'last_synced', 'last_used', 'created_at' ];
		$orderby         = in_array( $args['orderby'] ?? '', $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
		$order           = strtoupper( $args['order'] ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC';

		// Pagination.
		$per_page = max( 1, min( (int) ( $args['per_page'] ?? 25 ), 200 ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		// Count query.
		if ( $bindings ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM `{$table}` {$where_sql}",
					...$bindings
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` {$where_sql}" );
		}

		// Data query.
		$limit_sql = $wpdb->prepare( 'LIMIT %d OFFSET %d', $per_page, $offset );
		$sql       = "SELECT * FROM `{$table}` {$where_sql} ORDER BY `{$orderby}` {$order} {$limit_sql}";

		if ( $bindings ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$bindings ) ) ?: [];
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $sql ) ?: [];
		}

		return [ 'rows' => $rows, 'total' => $total ];
	}

	/**
	 * Count templates matching optional filters.
	 *
	 * @param array<string, mixed> $args Accepts same keys as get_all().
	 * @return int
	 */
	public function count( array $args = [] ): int {
		$result = $this->get_all( array_merge( $args, [ 'per_page' => 1 ] ) );
		return $result['total'];
	}

	/**
	 * Get templates by status.
	 *
	 * @param string $status
	 * @return array<int, object>
	 */
	public function get_by_status( string $status ): array {
		return $this->get_all( [ 'status' => $status, 'per_page' => 500 ] )['rows'];
	}

	/**
	 * Return a count breakdown by status.
	 *
	 * @return array<string, int>
	 */
	public function count_by_status(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'tsh_wa_meta_templates';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT status, COUNT(*) AS cnt FROM `{$table}` GROUP BY status"
		) ?: [];

		$counts = [];
		foreach ( $rows as $row ) {
			$counts[ $row->status ] = (int) $row->cnt;
		}
		return $counts;
	}

	/**
	 * Return a count breakdown by category.
	 *
	 * @return array<string, int>
	 */
	public function count_by_category(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'tsh_wa_meta_templates';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT category, COUNT(*) AS cnt FROM `{$table}` GROUP BY category"
		) ?: [];

		$counts = [];
		foreach ( $rows as $row ) {
			$counts[ $row->category ] = (int) $row->cnt;
		}
		return $counts;
	}

	/**
	 * Return a count breakdown by language.
	 *
	 * @return array<string, int>
	 */
	public function count_by_language(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'tsh_wa_meta_templates';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT language, COUNT(*) AS cnt FROM `{$table}` GROUP BY language ORDER BY cnt DESC"
		) ?: [];

		$counts = [];
		foreach ( $rows as $row ) {
			$counts[ $row->language ] = (int) $row->cnt;
		}
		return $counts;
	}

	/**
	 * Return all distinct meta_template_ids currently stored.
	 *
	 * @return array<int, string>
	 */
	public function get_all_meta_ids(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'tsh_wa_meta_templates';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( "SELECT meta_template_id FROM `{$table}`" ) ?: [];
		return array_column( $rows, 'meta_template_id' );
	}

	// -------------------------------------------------------------------------
	// Write operations
	// -------------------------------------------------------------------------

	/**
	 * Insert a new template row and return its ID, or false on failure.
	 *
	 * @param array<string, mixed> $data
	 * @return int|false
	 */
	public function insert( array $data ): int|false {
		global $wpdb;
		$table  = $wpdb->prefix . 'tsh_wa_meta_templates';
		$result = $wpdb->insert( $table, $this->prepare_data( $data ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $result ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Update an existing template row.
	 *
	 * @param int                  $id
	 * @param array<string, mixed> $data
	 * @return bool
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;
		$table  = $wpdb->prefix . 'tsh_wa_meta_templates';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->update( $table, $this->prepare_data( $data ), [ 'id' => $id ] );
		return false !== $result;
	}

	/**
	 * Insert or update by meta_template_id.
	 * Returns the row ID on success, false on failure.
	 *
	 * @param array<string, mixed> $data Must include meta_template_id.
	 * @return int|false
	 */
	public function upsert( array $data ): int|false {
		if ( empty( $data['meta_template_id'] ) ) {
			return false;
		}

		$existing = $this->find_by_meta_id( (string) $data['meta_template_id'] );

		if ( $existing ) {
			$data['last_synced'] = current_time( 'mysql' );
			$this->update( (int) $existing->id, $data );
			return (int) $existing->id;
		}

		$data['last_synced'] = current_time( 'mysql' );
		return $this->insert( $data );
	}

	/**
	 * Delete a template by primary key.
	 *
	 * @param int $id
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'tsh_wa_meta_templates';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return (bool) $wpdb->delete( $table, [ 'id' => $id ] );
	}

	/**
	 * Soft-delete by marking status as DELETED.
	 *
	 * @param string $meta_id
	 * @return bool
	 */
	public function mark_deleted( string $meta_id ): bool {
		$row = $this->find_by_meta_id( $meta_id );
		if ( ! $row ) {
			return false;
		}
		return $this->update( (int) $row->id, [ 'status' => self::STATUS_DELETED ] );
	}

	/**
	 * Increment usage count and set last_used.
	 *
	 * @param int $id
	 */
	public function increment_usage( int $id ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'tsh_wa_meta_templates';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table}` SET usage_count = usage_count + 1, last_used = %s WHERE id = %d",
				current_time( 'mysql' ),
				$id
			)
		);
	}

	/**
	 * Increment send_success counter.
	 *
	 * @param int $id
	 */
	public function increment_success( int $id ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'tsh_wa_meta_templates';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query(
			$wpdb->prepare( "UPDATE `{$table}` SET send_success = send_success + 1 WHERE id = %d", $id )
		);
	}

	/**
	 * Increment send_failed counter.
	 *
	 * @param int $id
	 */
	public function increment_failure( int $id ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'tsh_wa_meta_templates';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query(
			$wpdb->prepare( "UPDATE `{$table}` SET send_failed = send_failed + 1 WHERE id = %d", $id )
		);
	}

	/**
	 * Update the last_synced timestamp.
	 *
	 * @param int $id
	 */
	public function update_last_synced( int $id ): void {
		$this->update( $id, [ 'last_synced' => current_time( 'mysql' ) ] );
	}

	/**
	 * Delete all templates (used for full sync reset).
	 *
	 * @return int Number of rows deleted.
	 */
	public function truncate(): int {
		global $wpdb;
		$table = $wpdb->prefix . 'tsh_wa_meta_templates';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return (int) $wpdb->query( "TRUNCATE TABLE `{$table}`" );
	}

	// -------------------------------------------------------------------------
	// Helper: normalise data before insert/update
	// -------------------------------------------------------------------------

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	private function prepare_data( array $data ): array {
		$clean = [];

		$string_fields = [
			'meta_template_id', 'template_name', 'category', 'language',
			'status', 'quality_score', 'namespace', 'header_type', 'footer',
		];
		foreach ( $string_fields as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$clean[ $field ] = sanitize_text_field( (string) ( $data[ $field ] ?? '' ) );
			}
		}

		$json_fields = [ 'header_content', 'body', 'buttons', 'variables', 'example_values', 'variable_mapping', 'raw_data' ];
		foreach ( $json_fields as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$val            = $data[ $field ];
				$clean[ $field ] = is_array( $val ) || is_object( $val )
					? wp_json_encode( $val )
					: (string) $val;
			}
		}

		$int_fields = [ 'usage_count', 'send_success', 'send_failed' ];
		foreach ( $int_fields as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$clean[ $field ] = absint( $data[ $field ] );
			}
		}

		$datetime_fields = [ 'last_synced', 'last_used' ];
		foreach ( $datetime_fields as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$clean[ $field ] = $data[ $field ] ?? null;
			}
		}

		return $clean;
	}
}
