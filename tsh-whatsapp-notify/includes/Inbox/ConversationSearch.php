<?php
/**
 * Conversation search.
 *
 * @package TSH\WhatsAppNotify\Inbox
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Inbox;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ConversationSearch
 *
 * Full-text search across conversations and messages.
 * Supports searching by phone, customer name, email, order number, message text, and date.
 */
final class ConversationSearch {

	/**
	 * Search conversations and messages.
	 *
	 * @param string $query   Raw search string.
	 * @param int    $limit   Max results.
	 * @param int    $offset  Result offset.
	 * @return array{ results: array<int, array<string, mixed>>, total: int }
	 */
	public function search( string $query, int $limit = 30, int $offset = 0 ): array {
		global $wpdb;

		$query = sanitize_text_field( $query );

		if ( strlen( $query ) < 2 ) {
			return [ 'results' => [], 'total' => 0 ];
		}

		$conv_table = $wpdb->prefix . 'tsh_wa_conversations';
		$msg_table  = $wpdb->prefix . 'tsh_wa_messages';

		$like = '%' . $wpdb->esc_like( $query ) . '%';

		// Search: phone, contact_name, or message content.
		// Use UNION to get matching conversation IDs from both tables.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids_sql = $wpdb->prepare(
			"SELECT DISTINCT c.id
			 FROM `{$conv_table}` c
			 LEFT JOIN `{$msg_table}` m ON m.conversation_id = c.id
			 WHERE
			   c.phone       LIKE %s OR
			   c.contact_name LIKE %s OR
			   m.content     LIKE %s
			 ORDER BY c.last_message_at DESC
			 LIMIT %d OFFSET %d",
			$like, $like, $like, $limit, $offset
		);

		$count_sql = $wpdb->prepare(
			"SELECT COUNT(DISTINCT c.id)
			 FROM `{$conv_table}` c
			 LEFT JOIN `{$msg_table}` m ON m.conversation_id = c.id
			 WHERE
			   c.phone       LIKE %s OR
			   c.contact_name LIKE %s OR
			   m.content     LIKE %s",
			$like, $like, $like
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$ids   = $wpdb->get_col( $ids_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( empty( $ids ) ) {
			return [ 'results' => [], 'total' => 0 ];
		}

		$id_placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$conv_table}` WHERE id IN ({$id_placeholders}) ORDER BY last_message_at DESC",
				...$ids
			),
			ARRAY_A
		);

		// Also try customer name search via WP users.
		$customer_rows = $this->search_by_customer( $query, $conv_table );
		$existing_ids  = array_column( is_array( $rows ) ? $rows : [], 'id' );
		foreach ( $customer_rows as $row ) {
			if ( ! in_array( $row['id'], $existing_ids, true ) ) {
				$rows[] = $row;
			}
		}

		// Order number search.
		$order_rows = $this->search_by_order_number( $query, $conv_table );
		$existing_ids = array_column( is_array( $rows ) ? $rows : [], 'id' );
		foreach ( $order_rows as $row ) {
			if ( ! in_array( $row['id'], $existing_ids, true ) ) {
				$rows[] = $row;
			}
		}

		return [
			'results' => is_array( $rows ) ? $rows : [],
			'total'   => max( $total, count( is_array( $rows ) ? $rows : [] ) ),
		];
	}

	// -------------------------------------------------------------------------
	// Specialised search methods
	// -------------------------------------------------------------------------

	/**
	 * Search by customer name or email via WP users.
	 *
	 * @param string $query
	 * @param string $conv_table
	 * @return array<int, array<string, mixed>>
	 */
	private function search_by_customer( string $query, string $conv_table ): array {
		global $wpdb;

		$users = get_users( [
			'search'         => '*' . $query . '*',
			'search_columns' => [ 'display_name', 'user_email', 'user_login' ],
			'number'         => 20,
			'fields'         => 'ID',
		] );

		if ( empty( $users ) ) {
			return [];
		}

		$placeholders = implode( ',', array_fill( 0, count( $users ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$conv_table}` WHERE customer_id IN ({$placeholders}) ORDER BY last_message_at DESC LIMIT 20",
				...$users
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Search for conversations linked to an order number.
	 *
	 * @param string $query
	 * @param string $conv_table
	 * @return array<int, array<string, mixed>>
	 */
	private function search_by_order_number( string $query, string $conv_table ): array {
		global $wpdb;

		// Only attempt if query looks like an order number.
		if ( ! is_numeric( $query ) && ! str_starts_with( $query, '#' ) ) {
			return [];
		}

		$order_num = (int) ltrim( $query, '#' );
		if ( $order_num < 1 ) {
			return [];
		}

		$orders = wc_get_orders( [
			'meta_key'   => '_order_number', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value' => $order_num,       // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'limit'      => 5,
			'return'     => 'objects',
		] );

		// Also try direct ID.
		if ( empty( $orders ) ) {
			$order = wc_get_order( $order_num );
			if ( $order ) {
				$orders = [ $order ];
			}
		}

		if ( empty( $orders ) ) {
			return [];
		}

		$order_ids = array_map( static fn( $o ) => $o->get_id(), array_filter( $orders ) );
		if ( empty( $order_ids ) ) {
			return [];
		}

		$placeholders = implode( ',', array_fill( 0, count( $order_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$conv_table}` WHERE order_id IN ({$placeholders}) ORDER BY last_message_at DESC LIMIT 10",
				...$order_ids
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : [];
	}
}
