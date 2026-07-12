<?php
/**
 * Conversation & message data-access layer.
 *
 * @package TSH\WhatsAppNotify\Inbox
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Inbox;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ConversationRepository
 *
 * All direct database reads/writes for tsh_wa_conversations and tsh_wa_messages.
 * No business logic lives here — only data access.
 */
final class ConversationRepository {

	// -------------------------------------------------------------------------
	// Conversation table name helpers
	// -------------------------------------------------------------------------

	/** @return string */
	private function conversations_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'tsh_wa_conversations';
	}

	/** @return string */
	private function messages_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'tsh_wa_messages';
	}

	// =========================================================================
	// CONVERSATIONS
	// =========================================================================

	/**
	 * Find a conversation by phone number.
	 *
	 * @param string $phone E.164 phone number.
	 * @return array<string, mixed>|null
	 */
	public function find_by_phone( string $phone ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$this->conversations_table()}` WHERE phone = %s LIMIT 1",
				$phone
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * Find a conversation by its primary key.
	 *
	 * @param int $id
	 * @return array<string, mixed>|null
	 */
	public function find_conversation( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$this->conversations_table()}` WHERE id = %d LIMIT 1",
				$id
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * Create a new conversation row.
	 *
	 * @param array<string, mixed> $data
	 * @return int Inserted conversation ID.
	 */
	public function create_conversation( array $data ): int {
		global $wpdb;
		$defaults = [
			'status'        => 'open',
			'is_pinned'     => 0,
			'is_archived'   => 0,
			'unread_count'  => 0,
			'labels'        => '[]',
			'created_at'    => current_time( 'mysql' ),
			'updated_at'    => current_time( 'mysql' ),
		];
		$wpdb->insert( $this->conversations_table(), array_merge( $defaults, $data ) );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update an existing conversation.
	 *
	 * @param int                  $id
	 * @param array<string, mixed> $data
	 * @return int Rows affected.
	 */
	public function update_conversation( int $id, array $data ): int {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql' );
		return (int) $wpdb->update( $this->conversations_table(), $data, [ 'id' => $id ] );
	}

	/**
	 * Increment unread_count for a conversation.
	 *
	 * @param int $id
	 */
	public function increment_unread( int $id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare(
			"UPDATE `{$this->conversations_table()}` SET unread_count = unread_count + 1, updated_at = %s WHERE id = %d",
			current_time( 'mysql' ),
			$id
		) );
	}

	/**
	 * Reset unread_count to zero.
	 *
	 * @param int $id
	 */
	public function mark_read( int $id ): void {
		$this->update_conversation( $id, [ 'unread_count' => 0 ] );
	}

	/**
	 * Get paginated conversations.
	 *
	 * @param array<string, mixed> $args {
	 *   @type string $status     'open'|'closed'|'archived'|'all'
	 *   @type string $assigned   Filter by assigned_to user ID ('0' = unassigned).
	 *   @type int    $page       1-based page number.
	 *   @type int    $per_page   Items per page (max 100).
	 *   @type string $order_by   Column to order by.
	 *   @type string $order      'ASC'|'DESC'
	 *   @type bool   $pinned_first Show pinned at top.
	 * }
	 * @return array{ rows: array<int, array<string, mixed>>, total: int }
	 */
	public function get_conversations( array $args = [] ): array {
		global $wpdb;

		$status      = sanitize_key( $args['status']      ?? 'open' );
		$assigned    = $args['assigned']   ?? null;
		$page        = max( 1, (int) ( $args['page']     ?? 1 ) );
		$per_page    = min( 100, max( 1, (int) ( $args['per_page'] ?? 30 ) ) );
		$order_by    = in_array( $args['order_by'] ?? '', [ 'last_message_at', 'created_at', 'unread_count', 'id' ], true )
			? $args['order_by']
			: 'last_message_at';
		$order       = strtoupper( $args['order'] ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC';
		$pinned_first = (bool) ( $args['pinned_first'] ?? true );

		$where   = [];
		$prepare = [];

		// Status filter.
		if ( 'all' !== $status ) {
			if ( 'archived' === $status ) {
				$where[]   = 'is_archived = 1';
			} else {
				$where[]   = 'is_archived = 0';
				$where[]   = 'status = %s';
				$prepare[] = $status;
			}
		}

		// Assignment filter.
		if ( null !== $assigned ) {
			if ( '0' === (string) $assigned || 0 === (int) $assigned ) {
				$where[] = 'assigned_to IS NULL';
			} else {
				$where[]   = 'assigned_to = %d';
				$prepare[] = (int) $assigned;
			}
		}

		$where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';
		$order_sql = $pinned_first
			? "ORDER BY is_pinned DESC, {$order_by} {$order}"
			: "ORDER BY {$order_by} {$order}";
		$offset = ( $page - 1 ) * $per_page;

		$count_sql = "SELECT COUNT(*) FROM `{$this->conversations_table()}` {$where_sql}";
		$rows_sql  = "SELECT * FROM `{$this->conversations_table()}` {$where_sql} {$order_sql} LIMIT %d OFFSET %d";

		if ( $prepare ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$prepare ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows  = $wpdb->get_results( $wpdb->prepare( $rows_sql, ...$prepare, $per_page, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		} else {
			$total = (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows  = $wpdb->get_results( $wpdb->prepare( $rows_sql, $per_page, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return [
			'rows'  => is_array( $rows ) ? $rows : [],
			'total' => $total,
		];
	}

	/**
	 * Get unread counts grouped by status.
	 *
	 * @return array<string, int>
	 */
	public function get_unread_counts(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT status, SUM(unread_count) AS total
			 FROM `{$this->conversations_table()}`
			 WHERE is_archived = 0
			 GROUP BY status",
			ARRAY_A
		);
		$counts = [ 'open' => 0, 'closed' => 0, 'total' => 0 ];
		foreach ( (array) $rows as $row ) {
			$counts[ $row['status'] ] = (int) $row['total'];
			$counts['total']         += (int) $row['total'];
		}
		return $counts;
	}

	// =========================================================================
	// MESSAGES
	// =========================================================================

	/**
	 * Find a message by its Meta message ID.
	 *
	 * @param string $meta_message_id
	 * @return array<string, mixed>|null
	 */
	public function find_by_meta_id( string $meta_message_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$this->messages_table()}` WHERE meta_message_id = %s LIMIT 1",
				$meta_message_id
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	/**
	 * Insert a new message.
	 *
	 * @param array<string, mixed> $data
	 * @return int Inserted message ID.
	 */
	public function insert_message( array $data ): int {
		global $wpdb;
		$defaults = [
			'direction'    => 'incoming',
			'status'       => 'received',
			'message_type' => 'text',
			'is_note'      => 0,
			'is_deleted'   => 0,
			'created_at'   => current_time( 'mysql' ),
		];
		$wpdb->insert( $this->messages_table(), array_merge( $defaults, $data ) );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a message row.
	 *
	 * @param int                  $id
	 * @param array<string, mixed> $data
	 * @return int Rows affected.
	 */
	public function update_message( int $id, array $data ): int {
		global $wpdb;
		return (int) $wpdb->update( $this->messages_table(), $data, [ 'id' => $id ] );
	}

	/**
	 * Update message status by Meta message ID (delivery receipts).
	 *
	 * @param string $meta_message_id
	 * @param string $status
	 * @return int Rows affected.
	 */
	public function update_status_by_meta_id( string $meta_message_id, string $status ): int {
		global $wpdb;
		return (int) $wpdb->update(
			$this->messages_table(),
			[ 'status' => $status ],
			[ 'meta_message_id' => $meta_message_id ]
		);
	}

	/**
	 * Get messages for a conversation.
	 *
	 * @param int $conversation_id
	 * @param int $limit           Maximum messages to return.
	 * @param int $before_id       Return only messages with ID < before_id (cursor pagination).
	 * @return array<int, array<string, mixed>>
	 */
	public function get_messages( int $conversation_id, int $limit = 50, int $before_id = 0 ): array {
		global $wpdb;

		$before_sql = $before_id > 0
			? $wpdb->prepare( 'AND id < %d', $before_id )
			: '';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$this->messages_table()}`
				 WHERE conversation_id = %d {$before_sql}
				 ORDER BY timestamp ASC, id ASC
				 LIMIT %d",
				$conversation_id,
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Get the latest N messages across all conversations (for polling).
	 *
	 * @param string $after_datetime MySQL datetime string.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_new_messages_since( string $after_datetime ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.*, c.phone as conv_phone, c.status as conv_status
				 FROM `{$this->messages_table()}` m
				 JOIN `{$this->conversations_table()}` c ON c.id = m.conversation_id
				 WHERE m.created_at > %s
				 ORDER BY m.created_at ASC
				 LIMIT 200",
				$after_datetime
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Delete all messages for a conversation.
	 *
	 * @param int $conversation_id
	 */
	public function delete_messages( int $conversation_id ): void {
		global $wpdb;
		$wpdb->delete( $this->messages_table(), [ 'conversation_id' => $conversation_id ] );
	}

	/**
	 * Delete all conversation and message rows (for rebuild).
	 */
	public function truncate_all(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE `{$this->messages_table()}`" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE `{$this->conversations_table()}`" );
	}

	/**
	 * Aggregate counts for analytics.
	 *
	 * @return array<string, int>
	 */
	public function get_conversation_counts(): array {
		global $wpdb;
		$total    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$this->conversations_table()}`" ); // phpcs:ignore
		$open     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$this->conversations_table()}` WHERE status='open' AND is_archived=0" ); // phpcs:ignore
		$closed   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$this->conversations_table()}` WHERE status='closed' AND is_archived=0" ); // phpcs:ignore
		$archived = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$this->conversations_table()}` WHERE is_archived=1" ); // phpcs:ignore

		$today         = gmdate( 'Y-m-d' );
		$msgs_today_in = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore
			"SELECT COUNT(*) FROM `{$this->messages_table()}` WHERE direction='incoming' AND DATE(created_at)=%s",
			$today
		) );
		$msgs_today_out = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore
			"SELECT COUNT(*) FROM `{$this->messages_table()}` WHERE direction='outgoing' AND DATE(created_at)=%s",
			$today
		) );

		return [
			'total'          => $total,
			'open'           => $open,
			'closed'         => $closed,
			'archived'       => $archived,
			'messages_today_incoming' => $msgs_today_in,
			'messages_today_outgoing' => $msgs_today_out,
		];
	}
}
