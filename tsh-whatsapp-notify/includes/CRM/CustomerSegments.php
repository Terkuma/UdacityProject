<?php
/**
 * Customer Segments — dynamic segment engine.
 *
 * @package TSH\WhatsAppNotify\CRM
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\CRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CustomerSegments
 *
 * Builds dynamic SQL queries from declarative rule arrays
 * and evaluates which customers belong to each segment.
 *
 * Supported rule fields:
 *   lifecycle, is_vip, is_blocked, total_orders, lifetime_value,
 *   avg_order_value, last_order_at, created_at, health_score,
 *   country, city, tag (tag ID), birthday_month, anniversary_week
 */
final class CustomerSegments {

	private CustomerRepository $repo;

	/** Allowed rule fields and their DB column/type */
	private const RULE_FIELDS = [
		'lifecycle'       => [ 'col' => 'c.lifecycle',        'type' => 'string' ],
		'is_vip'          => [ 'col' => 'c.is_vip',           'type' => 'bool' ],
		'is_blocked'      => [ 'col' => 'c.is_blocked',       'type' => 'bool' ],
		'is_subscribed'   => [ 'col' => 'c.is_subscribed',    'type' => 'bool' ],
		'total_orders'    => [ 'col' => 'c.total_orders',     'type' => 'numeric' ],
		'lifetime_value'  => [ 'col' => 'c.lifetime_value',   'type' => 'numeric' ],
		'avg_order_value' => [ 'col' => 'c.avg_order_value',  'type' => 'numeric' ],
		'health_score'    => [ 'col' => 'c.health_score',     'type' => 'numeric' ],
		'last_order_at'   => [ 'col' => 'c.last_order_at',    'type' => 'date' ],
		'created_at'      => [ 'col' => 'c.created_at',       'type' => 'date' ],
		'country'         => [ 'col' => 'c.country',          'type' => 'string' ],
		'city'            => [ 'col' => 'c.city',             'type' => 'string' ],
		'rfm_recency'     => [ 'col' => 'c.rfm_recency',      'type' => 'numeric' ],
		'rfm_frequency'   => [ 'col' => 'c.rfm_frequency',    'type' => 'numeric' ],
	];

	public function __construct( CustomerRepository $repo ) {
		$this->repo = $repo;
	}

	/**
	 * Get all segments with member counts.
	 */
	public function list(): array {
		return $this->repo->get_segments();
	}

	/**
	 * Create a segment.
	 */
	public function create( string $name, string $description, array $rules ): int {
		return $this->repo->insert_segment( [
			'name'        => $name,
			'description' => $description,
			'rules'       => $rules,
		] );
	}

	/**
	 * Update a segment.
	 */
	public function update( int $id, array $data ): bool {
		return $this->repo->update_segment( $id, $data );
	}

	/**
	 * Delete a segment.
	 */
	public function delete( int $id ): bool {
		return $this->repo->delete_segment( $id );
	}

	/**
	 * Count customers matching a segment's rules.
	 */
	public function count_members( int $segment_id ): int {
		$segment = $this->repo->get_segment( $segment_id );
		if ( ! $segment ) return 0;
		return $this->count_by_rules( $segment['rules'] ?? [] );
	}

	/**
	 * Get paginated member list for a segment.
	 */
	public function get_members( int $segment_id, int $per_page = 50, int $page = 1 ): array {
		$segment = $this->repo->get_segment( $segment_id );
		if ( ! $segment ) return [ 'rows' => [], 'total' => 0 ];
		return $this->query_by_rules( $segment['rules'] ?? [], $per_page, $page );
	}

	/**
	 * Refresh all segment member counts.
	 */
	public function refresh_all_counts(): void {
		$segments = $this->repo->get_segments( 500 );
		foreach ( $segments as $seg ) {
			if ( ! $seg['auto_refresh'] ) continue;
			$count = $this->count_by_rules( $seg['rules'] ?? [] );
			$this->repo->update_segment( (int) $seg['id'], [
				'match_count'  => $count,
				'last_computed'=> current_time( 'mysql' ),
			] );
		}
	}

	/**
	 * Count customers matching a rule set.
	 */
	public function count_by_rules( array $rules ): int {
		global $wpdb;
		[ $where, $params ] = $this->rules_to_sql( $rules );
		$table = $this->repo->customers();
		$sql   = "SELECT COUNT(DISTINCT c.id) FROM {$table} c WHERE {$where}";
		return (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $sql, ...$params ) ) : $wpdb->get_var( $sql ) );
	}

	/**
	 * Query customers matching a rule set.
	 */
	public function query_by_rules( array $rules, int $per_page = 50, int $page = 1 ): array {
		global $wpdb;

		[ $where, $params ] = $this->rules_to_sql( $rules );
		$table  = $this->repo->customers();
		$offset = ( max( 1, $page ) - 1 ) * $per_page;

		$count_sql = "SELECT COUNT(DISTINCT c.id) FROM {$table} c WHERE {$where}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) ) : $wpdb->get_var( $count_sql ) );

		$rows_sql   = "SELECT c.* FROM {$table} c WHERE {$where} ORDER BY c.lifetime_value DESC LIMIT %d OFFSET %d";
		$all_params = array_merge( $params, [ $per_page, $offset ] );
		$rows       = $params
			? $wpdb->get_results( $wpdb->prepare( $rows_sql, ...$all_params ), ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( $rows_sql, $per_page, $offset ), ARRAY_A );

		return [ 'rows' => $rows ?: [], 'total' => $total ];
	}

	/**
	 * Get built-in preset segment definitions.
	 */
	public function get_presets(): array {
		return [
			[ 'name' => 'VIP Customers',        'rules' => [ [ 'field' => 'is_vip', 'op' => 'equals', 'value' => '1' ] ] ],
			[ 'name' => 'High Spenders',        'rules' => [ [ 'field' => 'lifetime_value', 'op' => 'greater_than', 'value' => 500 ] ] ],
			[ 'name' => 'New Customers',        'rules' => [ [ 'field' => 'lifecycle', 'op' => 'equals', 'value' => 'new' ] ] ],
			[ 'name' => 'Inactive Customers',   'rules' => [ [ 'field' => 'lifecycle', 'op' => 'in', 'value' => 'dormant,inactive,lost' ] ] ],
			[ 'name' => 'Never Purchased',      'rules' => [ [ 'field' => 'total_orders', 'op' => 'equals', 'value' => 0 ] ] ],
			[ 'name' => 'Repeat Buyers',        'rules' => [ [ 'field' => 'total_orders', 'op' => 'greater_than', 'value' => 2 ] ] ],
			[ 'name' => 'Blocked',              'rules' => [ [ 'field' => 'is_blocked', 'op' => 'equals', 'value' => '1' ] ] ],
			[ 'name' => 'Marketing Consent',    'rules' => [ [ 'field' => 'is_subscribed', 'op' => 'equals', 'value' => '1' ] ] ],
			[ 'name' => 'High Health Score',    'rules' => [ [ 'field' => 'health_score', 'op' => 'greater_than', 'value' => 75 ] ] ],
			[ 'name' => 'Critical Health',      'rules' => [ [ 'field' => 'health_score', 'op' => 'less_than', 'value' => 20 ] ] ],
		];
	}

	/** Available rule fields for UI builder. */
	public function get_rule_fields(): array {
		return [
			[ 'value' => 'lifecycle',       'label' => 'Lifecycle Stage' ],
			[ 'value' => 'is_vip',          'label' => 'Is VIP' ],
			[ 'value' => 'is_blocked',      'label' => 'Is Blocked' ],
			[ 'value' => 'is_subscribed',   'label' => 'Is Subscribed' ],
			[ 'value' => 'total_orders',    'label' => 'Total Orders' ],
			[ 'value' => 'lifetime_value',  'label' => 'Lifetime Value' ],
			[ 'value' => 'avg_order_value', 'label' => 'Avg Order Value' ],
			[ 'value' => 'health_score',    'label' => 'Health Score' ],
			[ 'value' => 'last_order_at',   'label' => 'Last Order Date' ],
			[ 'value' => 'created_at',      'label' => 'Registration Date' ],
			[ 'value' => 'country',         'label' => 'Country' ],
			[ 'value' => 'city',            'label' => 'City' ],
		];
	}

	// =========================================================================
	// SQL builder
	// =========================================================================

	private function rules_to_sql( array $rules ): array {
		global $wpdb;
		if ( empty( $rules ) ) return [ '1=1', [] ];

		$conditions = [];
		$params     = [];

		foreach ( $rules as $rule ) {
			$field    = $rule['field']    ?? '';
			$operator = $rule['op']       ?? 'equals';
			$value    = $rule['value']    ?? '';

			if ( ! isset( self::RULE_FIELDS[ $field ] ) ) continue;

			$col  = self::RULE_FIELDS[ $field ]['col'];
			$type = self::RULE_FIELDS[ $field ]['type'];

			switch ( $operator ) {
				case 'equals':
					$conditions[] = "{$col} = %s";
					$params[]     = $value;
					break;

				case 'not_equals':
					$conditions[] = "{$col} != %s";
					$params[]     = $value;
					break;

				case 'greater_than':
					$conditions[] = "{$col} > %f";
					$params[]     = (float) $value;
					break;

				case 'less_than':
					$conditions[] = "{$col} < %f";
					$params[]     = (float) $value;
					break;

				case 'greater_than_or_equal':
					$conditions[] = "{$col} >= %f";
					$params[]     = (float) $value;
					break;

				case 'less_than_or_equal':
					$conditions[] = "{$col} <= %f";
					$params[]     = (float) $value;
					break;

				case 'in':
					$vals = array_map( 'trim', explode( ',', (string) $value ) );
					if ( $vals ) {
						$placeholders = implode( ', ', array_fill( 0, count( $vals ), '%s' ) );
						$conditions[] = "{$col} IN ({$placeholders})";
						$params       = array_merge( $params, $vals );
					}
					break;

				case 'not_in':
					$vals = array_map( 'trim', explode( ',', (string) $value ) );
					if ( $vals ) {
						$placeholders = implode( ', ', array_fill( 0, count( $vals ), '%s' ) );
						$conditions[] = "{$col} NOT IN ({$placeholders})";
						$params       = array_merge( $params, $vals );
					}
					break;

				case 'within_days':
					$conditions[] = "{$col} >= DATE_SUB(NOW(), INTERVAL %d DAY)";
					$params[]     = (int) $value;
					break;

				case 'more_than_days_ago':
					$conditions[] = "{$col} < DATE_SUB(NOW(), INTERVAL %d DAY)";
					$params[]     = (int) $value;
					break;

				case 'is_null':
					$conditions[] = "{$col} IS NULL";
					break;

				case 'is_not_null':
					$conditions[] = "{$col} IS NOT NULL";
					break;

				case 'contains':
					$conditions[] = "{$col} LIKE %s";
					$params[]     = '%' . $wpdb->esc_like( $value ) . '%';
					break;
			}
		}

		if ( empty( $conditions ) ) return [ '1=1', [] ];

		$logic = ( $rules[0]['logic'] ?? 'AND' ) === 'OR' ? 'OR' : 'AND';
		return [ '(' . implode( " {$logic} ", $conditions ) . ')', $params ];
	}
}
