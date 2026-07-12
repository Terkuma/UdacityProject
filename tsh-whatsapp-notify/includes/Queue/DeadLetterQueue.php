<?php
/**
 * Dead Letter Queue — manages permanently failed WhatsApp messages.
 *
 * A "dead letter" item is any queue row where:
 *   status = 'failed' AND attempts >= max_attempts
 *
 * These items will never be retried automatically.  Admins can:
 *   - View them with full error context and original API response.
 *   - Manually retry individual items (resets attempts to 0).
 *   - Delete individual items.
 *   - Clear all DLQ items at once.
 *   - Export as JSON for external analysis.
 *
 * @package TSH\WhatsAppNotify\Queue
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Queue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class DeadLetterQueue
 */
final class DeadLetterQueue {

	/** @var string Base WHERE clause identifying DLQ items. */
	private const DLQ_WHERE = "status = 'failed' AND attempts >= max_attempts";

	// -------------------------------------------------------------------------
	// Queries
	// -------------------------------------------------------------------------

	/**
	 * Retrieve DLQ items with optional pagination.
	 *
	 * @param int $per_page
	 * @param int $page
	 * @return array{ rows: array<int, object>, total: int }
	 */
	public function get_items( int $per_page = 25, int $page = 1 ): array {
		global $wpdb;

		$table  = $wpdb->prefix . 'tsh_wa_queue';
		$offset = ( max( 1, $page ) - 1 ) * max( 1, $per_page );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE " . self::DLQ_WHERE );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE " . self::DLQ_WHERE . ' ORDER BY processed_at DESC LIMIT %d OFFSET %d',
				$per_page,
				$offset
			)
		) ?: [];

		return [ 'rows' => $rows, 'total' => $total ];
	}

	/**
	 * Get a single DLQ item by ID (returns null if the item is not in the DLQ).
	 *
	 * @param int $id
	 * @return object|null
	 */
	public function get( int $id ): ?object {
		global $wpdb;

		$table = $wpdb->prefix . 'tsh_wa_queue';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM `' . $table . '` WHERE id = %d AND ' . self::DLQ_WHERE,
				$id
			)
		) ?: null;
	}

	/**
	 * Count the total number of DLQ items.
	 */
	public function count(): int {
		global $wpdb;

		$table = $wpdb->prefix . 'tsh_wa_queue';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE " . self::DLQ_WHERE );
	}

	// -------------------------------------------------------------------------
	// Mutations
	// -------------------------------------------------------------------------

	/**
	 * Manually retry a DLQ item: reset attempts to 0 and re-queue it.
	 *
	 * @param int $id Queue item ID.
	 * @return bool True if the item was found in the DLQ and reset.
	 */
	public function retry( int $id ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'tsh_wa_queue';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->query(
			$wpdb->prepare(
				'UPDATE `' . $table . '`
				 SET status = %s, attempts = 0, error_message = NULL,
				     scheduled_at = %s, retry_after = NULL
				 WHERE id = %d AND ' . self::DLQ_WHERE,
				Queue::STATUS_PENDING,
				current_time( 'mysql' ),
				$id
			)
		);

		return (int) $rows === 1;
	}

	/**
	 * Retry ALL DLQ items at once.
	 *
	 * @return int Number of items re-queued.
	 */
	public function retry_all(): int {
		global $wpdb;

		$table = $wpdb->prefix . 'tsh_wa_queue';
		$now   = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query(
			$wpdb->prepare(
				'UPDATE `' . $table . '`
				 SET status = %s, attempts = 0, error_message = NULL,
				     scheduled_at = %s, retry_after = NULL
				 WHERE ' . self::DLQ_WHERE,
				Queue::STATUS_PENDING,
				$now
			)
		);
	}

	/**
	 * Permanently delete a DLQ item.
	 *
	 * @param int $id Queue item ID.
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'tsh_wa_queue';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM `' . $table . '` WHERE id = %d AND ' . self::DLQ_WHERE,
				$id
			)
		);

		return (int) $rows === 1;
	}

	/**
	 * Delete ALL DLQ items.
	 *
	 * @return int Number of rows deleted.
	 */
	public function clear(): int {
		global $wpdb;

		$table = $wpdb->prefix . 'tsh_wa_queue';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query( "DELETE FROM `{$table}` WHERE " . self::DLQ_WHERE );
	}

	// -------------------------------------------------------------------------
	// Export
	// -------------------------------------------------------------------------

	/**
	 * Export all DLQ items as an array suitable for JSON encoding or CSV output.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function export(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'tsh_wa_queue';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT id, phone, status, attempts, max_attempts,
			        error_message, message_id, created_at, processed_at, meta
			 FROM `{$table}`
			 WHERE " . self::DLQ_WHERE . '
			 ORDER BY processed_at DESC',
			ARRAY_A
		) ?: [];

		// Decode meta for readability.
		foreach ( $rows as &$row ) {
			if ( ! empty( $row['meta'] ) ) {
				$decoded = json_decode( $row['meta'], true );
				$row['meta'] = is_array( $decoded ) ? $decoded : [];
			}
		}

		return $rows;
	}
}
