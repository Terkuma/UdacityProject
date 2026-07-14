<?php
/**
 * Customer Repository — raw DB CRUD for all CRM tables.
 *
 * @package TSH\WhatsAppNotify\CRM
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\CRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CustomerRepository
 *
 * All direct database access for the CRM layer.
 * Zero business logic — only queries, inserts, updates, deletes.
 *
 * Tables owned:
 *   tsh_wa_customers
 *   tsh_wa_customer_notes
 *   tsh_wa_customer_tasks
 *   tsh_wa_customer_activity
 *   tsh_wa_customer_segments
 *   tsh_wa_customer_tags
 *   tsh_wa_customer_scores
 *   tsh_wa_customer_custom_fields
 */
final class CustomerRepository {

	// -------------------------------------------------------------------------
	// Table name helpers
	// -------------------------------------------------------------------------

	public function table( string $suffix ): string {
		global $wpdb;
		return $wpdb->prefix . 'tsh_wa_' . $suffix;
	}

	public function customers(): string        { return $this->table( 'customers' ); }
	public function notes(): string            { return $this->table( 'customer_notes' ); }
	public function tasks(): string            { return $this->table( 'customer_tasks' ); }
	public function activity(): string         { return $this->table( 'customer_activity' ); }
	public function segments(): string         { return $this->table( 'customer_segments' ); }
	public function tags(): string             { return $this->table( 'customer_tags' ); }
	public function scores(): string           { return $this->table( 'customer_scores' ); }
	public function custom_fields(): string    { return $this->table( 'customer_custom_fields' ); }

	// =========================================================================
	// CUSTOMERS
	// =========================================================================

	/**
	 * Get paginated customer list.
	 *
	 * @param array $args {
	 *   search, lifecycle, is_vip, is_blocked, tag_id, segment_id,
	 *   orderby, order, per_page, page, min_ltv, max_ltv, date_from, date_to
	 * }
	 */
	public function get_customers( array $args ): array {
		global $wpdb;

		$defaults = [
			'search'    => '',
			'lifecycle' => '',
			'is_vip'    => null,
			'is_blocked'=> null,
			'orderby'   => 'created_at',
			'order'     => 'DESC',
			'per_page'  => 25,
			'page'      => 1,
			'min_ltv'   => null,
			'max_ltv'   => null,
			'date_from' => '',
			'date_to'   => '',
			'tag_id'    => null,
		];
		$args = wp_parse_args( $args, $defaults );

		$where  = [ '1=1' ];
		$params = [];

		if ( $args['search'] ) {
			$s        = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(c.full_name LIKE %s OR c.phone LIKE %s OR c.whatsapp_phone LIKE %s OR c.email LIKE %s)';
			$params   = array_merge( $params, [ $s, $s, $s, $s ] );
		}

		if ( $args['lifecycle'] ) {
			$where[]  = 'c.lifecycle = %s';
			$params[] = $args['lifecycle'];
		}

		if ( null !== $args['is_vip'] ) {
			$where[]  = 'c.is_vip = %d';
			$params[] = (int) $args['is_vip'];
		}

		if ( null !== $args['is_blocked'] ) {
			$where[]  = 'c.is_blocked = %d';
			$params[] = (int) $args['is_blocked'];
		}

		if ( $args['min_ltv'] !== null ) {
			$where[]  = 'c.lifetime_value >= %f';
			$params[] = (float) $args['min_ltv'];
		}

		if ( $args['max_ltv'] !== null ) {
			$where[]  = 'c.lifetime_value <= %f';
			$params[] = (float) $args['max_ltv'];
		}

		if ( $args['date_from'] ) {
			$where[]  = 'c.created_at >= %s';
			$params[] = $args['date_from'];
		}

		if ( $args['date_to'] ) {
			$where[]  = 'c.created_at <= %s';
			$params[] = $args['date_to'] . ' 23:59:59';
		}

		$allowed_orderby = [ 'created_at', 'full_name', 'lifetime_value', 'last_order_at', 'health_score', 'total_orders' ];
		$orderby = in_array( $args['orderby'], $allowed_orderby, true ) ? 'c.' . $args['orderby'] : 'c.created_at';
		$order   = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

		$per_page = max( 1, min( 200, (int) $args['per_page'] ) );
		$offset   = ( max( 1, (int) $args['page'] ) - 1 ) * $per_page;

		$where_sql = implode( ' AND ', $where );

		// Count
		$count_sql = "SELECT COUNT(*) FROM {$this->customers()} c WHERE {$where_sql}";
		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) ) : $wpdb->get_var( $count_sql ) );

		// Rows
		$rows_sql  = "SELECT c.* FROM {$this->customers()} c WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$all_params = array_merge( $params, [ $per_page, $offset ] );
		$rows = $params
			? $wpdb->get_results( $wpdb->prepare( $rows_sql, ...$all_params ), ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( $rows_sql, $per_page, $offset ), ARRAY_A );

		return [
			'rows'       => array_map( [ $this, 'decode_customer_json' ], $rows ?: [] ),
			'total'      => $total,
			'per_page'   => $per_page,
			'page'       => (int) $args['page'],
			'total_pages'=> (int) ceil( $total / $per_page ),
		];
	}

	public function get_customer( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->customers()} WHERE id = %d", $id ), ARRAY_A );
		return $row ? $this->decode_customer_json( $row ) : null;
	}

	public function get_customer_by_phone( string $phone ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$this->customers()} WHERE phone = %s OR whatsapp_phone = %s LIMIT 1",
			$phone, $phone
		), ARRAY_A );
		return $row ? $this->decode_customer_json( $row ) : null;
	}

	public function get_customer_by_email( string $email ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$this->customers()} WHERE email = %s LIMIT 1",
			$email
		), ARRAY_A );
		return $row ? $this->decode_customer_json( $row ) : null;
	}

	public function get_customer_by_wp_user( int $user_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$this->customers()} WHERE wp_user_id = %d LIMIT 1",
			$user_id
		), ARRAY_A );
		return $row ? $this->decode_customer_json( $row ) : null;
	}

	public function insert_customer( array $data ): int {
		global $wpdb;
		$row = $this->prepare_customer_row( $data );
		$wpdb->insert( $this->customers(), $row );
		return (int) $wpdb->insert_id;
	}

	public function update_customer( int $id, array $data ): bool {
		global $wpdb;
		$row = $this->prepare_customer_row( $data );
		unset( $row['created_at'] );
		return (bool) $wpdb->update( $this->customers(), $row, [ 'id' => $id ] );
	}

	public function delete_customer( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete( $this->customers(), [ 'id' => $id ] );
	}

	/** Upsert by phone — returns customer id. */
	public function upsert_by_phone( string $phone, array $data ): int {
		$existing = $this->get_customer_by_phone( $phone );
		if ( $existing ) {
			$this->update_customer( (int) $existing['id'], $data );
			return (int) $existing['id'];
		}
		$data['phone'] = $phone;
		return $this->insert_customer( $data );
	}

	/** Upsert by email — returns customer id. */
	public function upsert_by_email( string $email, array $data ): int {
		$existing = $this->get_customer_by_email( $email );
		if ( $existing ) {
			$this->update_customer( (int) $existing['id'], $data );
			return (int) $existing['id'];
		}
		$data['email'] = $email;
		return $this->insert_customer( $data );
	}

	// =========================================================================
	// NOTES
	// =========================================================================

	public function get_notes( int $customer_id, int $limit = 50 ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$this->notes()} WHERE customer_id = %d ORDER BY is_pinned DESC, created_at DESC LIMIT %d",
			$customer_id, $limit
		), ARRAY_A ) ?: [];
	}

	public function get_note( int $id ): ?array {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->notes()} WHERE id = %d", $id ), ARRAY_A ) ?: null;
	}

	public function insert_note( array $data ): int {
		global $wpdb;
		$wpdb->insert( $this->notes(), [
			'customer_id'    => (int) ( $data['customer_id'] ?? 0 ),
			'user_id'        => (int) ( $data['user_id'] ?? get_current_user_id() ),
			'content'        => $data['content'] ?? '',
			'is_pinned'      => (int) ( $data['is_pinned'] ?? 0 ),
			'is_private'     => (int) ( $data['is_private'] ?? 1 ),
			'attachment_url' => $data['attachment_url'] ?? null,
		] );
		$id = (int) $wpdb->insert_id;
		if ( $id ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$this->customers()} SET notes_count = notes_count + 1 WHERE id = %d", (int) $data['customer_id'] ) );
		}
		return $id;
	}

	public function update_note( int $id, array $data ): bool {
		global $wpdb;
		return (bool) $wpdb->update( $this->notes(), [
			'content'    => $data['content'] ?? '',
			'is_pinned'  => (int) ( $data['is_pinned'] ?? 0 ),
			'is_private' => (int) ( $data['is_private'] ?? 1 ),
		], [ 'id' => $id ] );
	}

	public function delete_note( int $id ): bool {
		global $wpdb;
		$note = $this->get_note( $id );
		$ok   = (bool) $wpdb->delete( $this->notes(), [ 'id' => $id ] );
		if ( $ok && $note ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$this->customers()} SET notes_count = GREATEST(0, notes_count - 1) WHERE id = %d", (int) $note['customer_id'] ) );
		}
		return $ok;
	}

	// =========================================================================
	// TASKS
	// =========================================================================

	public function get_tasks( int $customer_id, string $status = '', int $limit = 50 ): array {
		global $wpdb;
		$where = 'customer_id = %d';
		$args  = [ $customer_id ];
		if ( $status ) { $where .= ' AND status = %s'; $args[] = $status; }
		$args[] = $limit;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$this->tasks()} WHERE {$where} ORDER BY priority DESC, due_at ASC LIMIT %d",
			...$args
		), ARRAY_A ) ?: [];
	}

	public function get_all_tasks( array $args = [] ): array {
		global $wpdb;
		$per_page = (int) ( $args['per_page'] ?? 25 );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;
		$status   = sanitize_key( $args['status'] ?? '' );
		$user_id  = (int) ( $args['assigned_to'] ?? 0 );

		$where  = '1=1';
		$params = [];

		if ( $status ) { $where .= ' AND t.status = %s'; $params[] = $status; }
		if ( $user_id ) { $where .= ' AND t.assigned_to = %d'; $params[] = $user_id; }

		$total_sql = "SELECT COUNT(*) FROM {$this->tasks()} t WHERE {$where}";
		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $total_sql, ...$params ) ) : $wpdb->get_var( $total_sql ) );

		$rows_sql  = "SELECT t.*, c.full_name as customer_name, c.phone as customer_phone FROM {$this->tasks()} t LEFT JOIN {$this->customers()} c ON c.id = t.customer_id WHERE {$where} ORDER BY t.due_at ASC, t.priority DESC LIMIT %d OFFSET %d";
		$all_params = array_merge( $params, [ $per_page, $offset ] );
		$rows = $params
			? $wpdb->get_results( $wpdb->prepare( $rows_sql, ...$all_params ), ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( $rows_sql, $per_page, $offset ), ARRAY_A );

		return [ 'rows' => $rows ?: [], 'total' => $total ];
	}

	public function get_task( int $id ): ?array {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->tasks()} WHERE id = %d", $id ), ARRAY_A ) ?: null;
	}

	public function insert_task( array $data ): int {
		global $wpdb;
		$wpdb->insert( $this->tasks(), [
			'customer_id'       => (int) ( $data['customer_id'] ?? 0 ),
			'assigned_to'       => (int) ( $data['assigned_to'] ?? get_current_user_id() ),
			'title'             => $data['title'] ?? '',
			'description'       => $data['description'] ?? '',
			'status'            => $data['status'] ?? 'pending',
			'priority'          => $data['priority'] ?? 'medium',
			'due_at'            => $data['due_at'] ?? null,
			'is_recurring'      => (int) ( $data['is_recurring'] ?? 0 ),
			'recurrence_config' => isset( $data['recurrence_config'] ) ? wp_json_encode( $data['recurrence_config'] ) : null,
			'created_by'        => (int) ( $data['created_by'] ?? get_current_user_id() ),
		] );
		$id = (int) $wpdb->insert_id;
		if ( $id ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$this->customers()} SET tasks_count = tasks_count + 1 WHERE id = %d", (int) $data['customer_id'] ) );
		}
		return $id;
	}

	public function update_task( int $id, array $data ): bool {
		global $wpdb;
		$allowed = [ 'title', 'description', 'status', 'priority', 'due_at', 'assigned_to', 'completed_at', 'is_recurring', 'reminder_sent' ];
		$row = array_intersect_key( $data, array_flip( $allowed ) );
		if ( isset( $row['is_recurring'] ) ) $row['is_recurring'] = (int) $row['is_recurring'];
		if ( isset( $row['reminder_sent'] ) ) $row['reminder_sent'] = (int) $row['reminder_sent'];
		if ( isset( $data['recurrence_config'] ) ) $row['recurrence_config'] = wp_json_encode( $data['recurrence_config'] );
		return (bool) $wpdb->update( $this->tasks(), $row, [ 'id' => $id ] );
	}

	public function delete_task( int $id ): bool {
		global $wpdb;
		$task = $this->get_task( $id );
		$ok   = (bool) $wpdb->delete( $this->tasks(), [ 'id' => $id ] );
		if ( $ok && $task ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$this->customers()} SET tasks_count = GREATEST(0, tasks_count - 1) WHERE id = %d", (int) $task['customer_id'] ) );
		}
		return $ok;
	}

	/** Get overdue tasks (for reminders). */
	public function get_overdue_tasks( int $limit = 100 ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$this->tasks()} WHERE status = 'pending' AND due_at <= %s AND reminder_sent = 0 AND due_at IS NOT NULL LIMIT %d",
			current_time( 'mysql' ), $limit
		), ARRAY_A ) ?: [];
	}

	// =========================================================================
	// ACTIVITY
	// =========================================================================

	public function get_activity( int $customer_id, int $limit = 50, int $offset = 0 ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$this->activity()} WHERE customer_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
			$customer_id, $limit, $offset
		), ARRAY_A ) ?: [];
	}

	public function insert_activity( array $data ): int {
		global $wpdb;
		$wpdb->insert( $this->activity(), [
			'customer_id'    => (int) ( $data['customer_id'] ?? 0 ),
			'type'           => $data['type'] ?? 'note',
			'subject'        => substr( $data['subject'] ?? '', 0, 200 ),
			'description'    => $data['description'] ?? '',
			'data'           => isset( $data['data'] ) ? wp_json_encode( $data['data'] ) : null,
			'reference_type' => $data['reference_type'] ?? null,
			'reference_id'   => isset( $data['reference_id'] ) ? (int) $data['reference_id'] : null,
			'created_by'     => (int) ( $data['created_by'] ?? get_current_user_id() ),
		] );
		return (int) $wpdb->insert_id;
	}

	public function get_recent_activity( int $limit = 50 ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT a.*, c.full_name as customer_name, c.phone as customer_phone
			 FROM {$this->activity()} a
			 LEFT JOIN {$this->customers()} c ON c.id = a.customer_id
			 ORDER BY a.created_at DESC LIMIT %d",
			$limit
		), ARRAY_A ) ?: [];
	}

	// =========================================================================
	// SEGMENTS
	// =========================================================================

	public function get_segments( int $limit = 200 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$this->segments()} ORDER BY created_at DESC LIMIT %d", $limit
		), ARRAY_A ) ?: [];
		foreach ( $rows as &$r ) {
			$r['rules'] = json_decode( $r['rules'] ?? '[]', true ) ?: [];
		}
		return $rows;
	}

	public function get_segment( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->segments()} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) return null;
		$row['rules'] = json_decode( $row['rules'] ?? '[]', true ) ?: [];
		return $row;
	}

	public function insert_segment( array $data ): int {
		global $wpdb;
		$wpdb->insert( $this->segments(), [
			'name'        => $data['name'] ?? '',
			'description' => $data['description'] ?? '',
			'rules'       => wp_json_encode( $data['rules'] ?? [] ),
			'auto_refresh'=> (int) ( $data['auto_refresh'] ?? 1 ),
			'created_by'  => (int) ( $data['created_by'] ?? get_current_user_id() ),
		] );
		return (int) $wpdb->insert_id;
	}

	public function update_segment( int $id, array $data ): bool {
		global $wpdb;
		$row = [];
		if ( isset( $data['name'] ) )        $row['name']        = $data['name'];
		if ( isset( $data['description'] ) ) $row['description'] = $data['description'];
		if ( isset( $data['rules'] ) )       $row['rules']       = wp_json_encode( $data['rules'] );
		if ( isset( $data['auto_refresh'] ) )$row['auto_refresh']= (int) $data['auto_refresh'];
		if ( isset( $data['match_count'] ) ) $row['match_count'] = (int) $data['match_count'];
		if ( isset( $data['last_computed'] ) )$row['last_computed']= $data['last_computed'];
		return (bool) $wpdb->update( $this->segments(), $row, [ 'id' => $id ] );
	}

	public function delete_segment( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete( $this->segments(), [ 'id' => $id ] );
	}

	// =========================================================================
	// TAGS
	// =========================================================================

	public function get_all_tags(): array {
		global $wpdb;
		return $wpdb->get_results( "SELECT * FROM {$this->tags()} ORDER BY name ASC", ARRAY_A ) ?: [];
	}

	public function get_tag( int $id ): ?array {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->tags()} WHERE id = %d", $id ), ARRAY_A ) ?: null;
	}

	public function insert_tag( array $data ): int {
		global $wpdb;
		$wpdb->insert( $this->tags(), [
			'name'        => $data['name'] ?? '',
			'color'       => $data['color'] ?? '#6b7280',
			'type'        => $data['type'] ?? 'manual',
			'description' => $data['description'] ?? '',
		] );
		return (int) $wpdb->insert_id;
	}

	public function update_tag( int $id, array $data ): bool {
		global $wpdb;
		return (bool) $wpdb->update( $this->tags(), [
			'name'        => $data['name'] ?? '',
			'color'       => $data['color'] ?? '#6b7280',
			'description' => $data['description'] ?? '',
		], [ 'id' => $id ] );
	}

	public function delete_tag( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete( $this->tags(), [ 'id' => $id ] );
	}

	/** Add tag to customer. */
	public function add_customer_tag( int $customer_id, int $tag_id ): bool {
		global $wpdb;
		$customer = $this->get_customer( $customer_id );
		if ( ! $customer ) return false;
		$tags   = $customer['tags'] ?? [];
		$tag_id = (int) $tag_id;
		if ( in_array( $tag_id, $tags, true ) ) return true;
		$tags[] = $tag_id;
		$ok     = (bool) $wpdb->update( $this->customers(), [ 'tags' => wp_json_encode( $tags ) ], [ 'id' => $customer_id ] );
		if ( $ok ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$this->tags()} SET usage_count = usage_count + 1 WHERE id = %d", $tag_id ) );
		}
		return $ok;
	}

	/** Remove tag from customer. */
	public function remove_customer_tag( int $customer_id, int $tag_id ): bool {
		global $wpdb;
		$customer = $this->get_customer( $customer_id );
		if ( ! $customer ) return false;
		$tags = array_values( array_filter( $customer['tags'] ?? [], fn( $t ) => (int) $t !== $tag_id ) );
		$ok   = (bool) $wpdb->update( $this->customers(), [ 'tags' => wp_json_encode( $tags ) ], [ 'id' => $customer_id ] );
		if ( $ok ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$this->tags()} SET usage_count = GREATEST(0, usage_count - 1) WHERE id = %d", $tag_id ) );
		}
		return $ok;
	}

	// =========================================================================
	// SCORES
	// =========================================================================

	public function get_scores( int $customer_id ): ?array {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$this->scores()} WHERE customer_id = %d ORDER BY computed_at DESC LIMIT 1",
			$customer_id
		), ARRAY_A ) ?: null;
	}

	public function upsert_scores( int $customer_id, array $scores ): bool {
		global $wpdb;
		$existing = $this->get_scores( $customer_id );
		$row = [
			'customer_id'       => $customer_id,
			'engagement_score'  => (float) ( $scores['engagement'] ?? 0 ),
			'purchase_score'    => (float) ( $scores['purchase']   ?? 0 ),
			'support_score'     => (float) ( $scores['support']    ?? 0 ),
			'marketing_score'   => (float) ( $scores['marketing']  ?? 0 ),
			'health_score'      => (float) ( $scores['health']     ?? 0 ),
			'rfm_score'         => $scores['rfm'] ?? '000',
			'computed_at'       => current_time( 'mysql' ),
		];
		if ( $existing ) {
			return (bool) $wpdb->update( $this->scores(), $row, [ 'customer_id' => $customer_id ] );
		}
		return (bool) $wpdb->insert( $this->scores(), $row );
	}

	// =========================================================================
	// CUSTOM FIELDS
	// =========================================================================

	public function get_custom_fields(): array {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT * FROM {$this->custom_fields()} ORDER BY sort_order ASC, id ASC", ARRAY_A ) ?: [];
		foreach ( $rows as &$r ) {
			$r['options'] = json_decode( $r['options'] ?? '[]', true ) ?: [];
		}
		return $rows;
	}

	public function insert_custom_field( array $data ): int {
		global $wpdb;
		$wpdb->insert( $this->custom_fields(), [
			'field_key'   => sanitize_key( $data['field_key'] ?? '' ),
			'field_label' => $data['field_label'] ?? '',
			'field_type'  => $data['field_type'] ?? 'text',
			'options'     => wp_json_encode( $data['options'] ?? [] ),
			'is_required' => (int) ( $data['is_required'] ?? 0 ),
			'sort_order'  => (int) ( $data['sort_order'] ?? 0 ),
		] );
		return (int) $wpdb->insert_id;
	}

	public function delete_custom_field( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete( $this->custom_fields(), [ 'id' => $id ] );
	}

	// =========================================================================
	// AGGREGATES / STATS
	// =========================================================================

	public function get_dashboard_counts(): array {
		global $wpdb;
		$t = $this->customers();
		return [
			'total'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ),
			'vip'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE is_vip = 1" ),
			'active'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE lifecycle = 'active'" ),
			'inactive'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE lifecycle IN ('dormant','inactive','lost')" ),
			'returning' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE lifecycle = 'returning'" ),
			'new'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE lifecycle = 'new'" ),
			'lead'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE lifecycle = 'lead'" ),
			'blocked'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE is_blocked = 1" ),
		];
	}

	public function get_lifecycle_distribution(): array {
		global $wpdb;
		return $wpdb->get_results(
			"SELECT lifecycle, COUNT(*) as count FROM {$this->customers()} GROUP BY lifecycle ORDER BY count DESC",
			ARRAY_A
		) ?: [];
	}

	public function get_top_customers( int $limit = 10 ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT id, full_name, phone, email, lifetime_value, total_orders, is_vip, lifecycle
			 FROM {$this->customers()} ORDER BY lifetime_value DESC LIMIT %d",
			$limit
		), ARRAY_A ) ?: [];
	}

	public function get_customer_growth( int $days = 30 ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE(created_at) as date, COUNT(*) as count
			 FROM {$this->customers()}
			 WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
			 GROUP BY DATE(created_at) ORDER BY date ASC",
			$days
		), ARRAY_A ) ?: [];
	}

	public function count_by_date_range( string $from, string $to ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$this->customers()} WHERE created_at BETWEEN %s AND %s",
			$from, $to . ' 23:59:59'
		) );
	}

	// =========================================================================
	// BULK / MERGE
	// =========================================================================

	/** Transfer all relational data from source customer to target. */
	public function transfer_relations( int $source_id, int $target_id ): void {
		global $wpdb;
		// Notes, tasks, activity
		$wpdb->query( $wpdb->prepare( "UPDATE {$this->notes()}    SET customer_id = %d WHERE customer_id = %d", $target_id, $source_id ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$this->tasks()}    SET customer_id = %d WHERE customer_id = %d", $target_id, $source_id ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$this->activity()} SET customer_id = %d WHERE customer_id = %d", $target_id, $source_id ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$this->scores()}   SET customer_id = %d WHERE customer_id = %d", $target_id, $source_id ) );
	}

	/** Find potential duplicate customers. */
	public function find_duplicates( int $limit = 100 ): array {
		global $wpdb;
		// By phone
		$by_phone = $wpdb->get_results( $wpdb->prepare(
			"SELECT phone, COUNT(*) as cnt, GROUP_CONCAT(id ORDER BY id) as ids
			 FROM {$this->customers()}
			 WHERE phone != ''
			 GROUP BY phone HAVING cnt > 1 LIMIT %d",
			$limit
		), ARRAY_A ) ?: [];

		// By email
		$by_email = $wpdb->get_results( $wpdb->prepare(
			"SELECT email, COUNT(*) as cnt, GROUP_CONCAT(id ORDER BY id) as ids
			 FROM {$this->customers()}
			 WHERE email != '' AND email IS NOT NULL
			 GROUP BY email HAVING cnt > 1 LIMIT %d",
			$limit
		), ARRAY_A ) ?: [];

		return [ 'by_phone' => $by_phone, 'by_email' => $by_email ];
	}

	// =========================================================================
	// JSON helpers
	// =========================================================================

	private function prepare_customer_row( array $data ): array {
		$row = [];
		$text_fields = [ 'phone', 'whatsapp_phone', 'email', 'first_name', 'last_name', 'full_name', 'avatar_url', 'country', 'state', 'city', 'language', 'timezone', 'source', 'lifecycle' ];
		foreach ( $text_fields as $f ) {
			if ( array_key_exists( $f, $data ) ) $row[ $f ] = $data[ $f ];
		}
		$text_area = [ 'address' ];
		foreach ( $text_area as $f ) {
			if ( array_key_exists( $f, $data ) ) $row[ $f ] = $data[ $f ];
		}
		$int_fields = [ 'wp_user_id', 'wc_customer_id', 'is_vip', 'vip_manual', 'is_blocked', 'is_subscribed', 'marketing_consent', 'total_orders', 'completed_orders', 'cancelled_orders', 'refunded_orders', 'pending_orders' ];
		foreach ( $int_fields as $f ) {
			if ( array_key_exists( $f, $data ) ) $row[ $f ] = (int) $data[ $f ];
		}
		$float_fields = [ 'lifetime_value', 'avg_order_value', 'health_score', 'rfm_monetary' ];
		foreach ( $float_fields as $f ) {
			if ( array_key_exists( $f, $data ) ) $row[ $f ] = (float) $data[ $f ];
		}
		$date_fields = [ 'birthday', 'anniversary' ];
		foreach ( $date_fields as $f ) {
			if ( array_key_exists( $f, $data ) ) $row[ $f ] = $data[ $f ] ?: null;
		}
		$datetime_fields = [ 'first_order_at', 'last_order_at', 'last_message_at', 'last_campaign_at', 'last_coupon_at' ];
		foreach ( $datetime_fields as $f ) {
			if ( array_key_exists( $f, $data ) ) $row[ $f ] = $data[ $f ] ?: null;
		}
		$json_fields = [ 'tags', 'custom_fields', 'communication_prefs' ];
		foreach ( $json_fields as $f ) {
			if ( array_key_exists( $f, $data ) ) {
				$row[ $f ] = is_array( $data[ $f ] ) ? wp_json_encode( $data[ $f ] ) : ( $data[ $f ] ?? null );
			}
		}
		// Compute full_name if names provided
		if ( ! isset( $row['full_name'] ) && ( isset( $row['first_name'] ) || isset( $row['last_name'] ) ) ) {
			$row['full_name'] = trim( ( $row['first_name'] ?? '' ) . ' ' . ( $row['last_name'] ?? '' ) );
		}
		return $row;
	}

	private function decode_customer_json( array $row ): array {
		$json_fields = [ 'tags', 'custom_fields', 'communication_prefs' ];
		foreach ( $json_fields as $f ) {
			if ( isset( $row[ $f ] ) && is_string( $row[ $f ] ) ) {
				$row[ $f ] = json_decode( $row[ $f ], true ) ?: [];
			}
		}
		return $row;
	}
}
