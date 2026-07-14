<?php
/**
 * Customer Import — CSV and JSON bulk import.
 *
 * @package TSH\WhatsAppNotify\CRM
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\CRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CustomerImport
 *
 * Imports customers from:
 *   - CSV files (auto-detected column headers)
 *   - JSON arrays
 *   - WooCommerce customer sync (all WC registered customers)
 *
 * Supports three conflict modes:
 *   - skip      (default) — leave existing records untouched
 *   - update    — overwrite existing with imported data
 *   - replace   — delete and re-create
 */
final class CustomerImport {

	private CustomerRepository $repo;
	private CustomerManager    $manager;
	private CustomerScoring    $scoring;

	public function __construct(
		CustomerRepository $repo,
		CustomerManager    $manager,
		CustomerScoring    $scoring
	) {
		$this->repo    = $repo;
		$this->manager = $manager;
		$this->scoring = $scoring;
	}

	/**
	 * Import from CSV string.
	 *
	 * @param string $csv        Raw CSV content.
	 * @param string $conflict   'skip' | 'update' | 'replace'
	 * @return array{ imported: int, updated: int, skipped: int, errors: array }
	 */
	public function from_csv( string $csv, string $conflict = 'skip' ): array {
		$lines   = preg_split( '/\r\n|\r|\n/', trim( $csv ) );
		$headers = $lines ? array_map( 'strtolower', array_map( 'trim', str_getcsv( $lines[0] ) ) ) : [];

		if ( ! $headers ) return [ 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => [ 'Empty CSV' ] ];

		$col_map = $this->map_columns( $headers );
		$results = [ 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => [] ];

		for ( $i = 1; $i < count( $lines ); $i++ ) {
			if ( trim( $lines[ $i ] ) === '' ) continue;
			$values = str_getcsv( $lines[ $i ] );
			$data   = $this->map_row( $values, $col_map );

			$r = $this->process_row( $data, $conflict );
			$results[ $r ] = ( $results[ $r ] ?? 0 ) + 1;
		}

		return $results;
	}

	/**
	 * Import from JSON string (array or single object).
	 *
	 * @param string $json      JSON content.
	 * @param string $conflict  'skip' | 'update' | 'replace'
	 */
	public function from_json( string $json, string $conflict = 'skip' ): array {
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return [ 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => [ 'Invalid JSON' ] ];
		}
		// Single record
		if ( isset( $data['phone'] ) || isset( $data['email'] ) ) {
			$data = [ $data ];
		}

		$results = [ 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => [] ];
		foreach ( $data as $row ) {
			if ( ! is_array( $row ) ) continue;
			$r = $this->process_row( $row, $conflict );
			$results[ $r ] = ( $results[ $r ] ?? 0 ) + 1;
		}
		return $results;
	}

	/**
	 * Sync all WooCommerce registered customers into CRM.
	 * Runs in chunks for large stores.
	 */
	public function sync_from_woocommerce( int $chunk = 50, int $offset = 0 ): int {
		global $wpdb;
		$role_clause = " AND um.meta_value LIKE '%customer%'";
		$users = $wpdb->get_col( $wpdb->prepare(
			"SELECT u.ID FROM {$wpdb->users} u
			 INNER JOIN {$wpdb->usermeta} um ON um.user_id = u.ID AND um.meta_key = '{$wpdb->prefix}capabilities'
			 WHERE 1=1 {$role_clause} LIMIT %d OFFSET %d",
			$chunk, $offset
		) );

		$synced = 0;
		foreach ( $users as $user_id ) {
			$id = $this->manager->sync_from_woocommerce( (int) $user_id );
			if ( $id ) ++$synced;
		}
		return $synced;
	}

	// =========================================================================
	// Internal
	// =========================================================================

	/**
	 * @return 'imported' | 'updated' | 'skipped' | 'errors'
	 */
	private function process_row( array $data, string $conflict ): string {
		$phone = trim( $data['phone'] ?? '' );
		$email = trim( $data['email'] ?? '' );

		if ( ! $phone && ! $email ) return 'skipped';

		// Existing customer?
		$existing = null;
		if ( $phone ) $existing = $this->repo->get_customer_by_phone( $phone );
		if ( ! $existing && $email ) $existing = $this->repo->get_customer_by_email( $email );

		if ( $existing ) {
			if ( $conflict === 'skip' ) return 'skipped';
			if ( $conflict === 'replace' ) {
				$this->manager->delete( (int) $existing['id'] );
			} elseif ( $conflict === 'update' ) {
				$this->repo->update_customer( (int) $existing['id'], $data );
				$this->scoring->calculate( (int) $existing['id'] );
				return 'updated';
			}
		}

		$result = $this->manager->create( $data );
		return $result['success'] ? 'imported' : 'errors';
	}

	/** Map CSV headers to internal field names. */
	private function map_columns( array $headers ): array {
		$aliases = [
			'first_name'    => [ 'first name', 'firstname', 'first' ],
			'last_name'     => [ 'last name', 'lastname', 'surname' ],
			'full_name'     => [ 'full name', 'name', 'customer name', 'customer' ],
			'phone'         => [ 'phone', 'phone number', 'mobile', 'billing_phone' ],
			'whatsapp_phone'=> [ 'whatsapp', 'whatsapp phone', 'wa' ],
			'email'         => [ 'email', 'email address', 'billing_email' ],
			'country'       => [ 'country', 'billing_country' ],
			'state'         => [ 'state', 'billing_state' ],
			'city'          => [ 'city', 'billing_city' ],
			'address'       => [ 'address', 'billing_address' ],
			'language'      => [ 'language', 'lang' ],
			'timezone'      => [ 'timezone', 'tz' ],
			'birthday'      => [ 'birthday', 'birth date', 'dob', 'date of birth' ],
			'lifecycle'     => [ 'lifecycle', 'status', 'stage' ],
			'lifetime_value'=> [ 'lifetime value', 'ltv', 'total spend', 'revenue' ],
			'total_orders'  => [ 'total orders', 'order count', 'orders' ],
		];

		$map = [];
		foreach ( $headers as $idx => $header ) {
			$h = strtolower( trim( $header ) );
			foreach ( $aliases as $field => $options ) {
				if ( $h === $field || in_array( $h, $options, true ) ) {
					$map[ $field ] = $idx;
					break;
				}
			}
		}
		return $map;
	}

	private function map_row( array $values, array $col_map ): array {
		$data = [];
		foreach ( $col_map as $field => $idx ) {
			$data[ $field ] = $values[ $idx ] ?? '';
		}
		return $data;
	}
}
