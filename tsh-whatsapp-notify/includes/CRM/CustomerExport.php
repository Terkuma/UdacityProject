<?php
/**
 * Customer Export — CSV and JSON bulk export.
 *
 * @package TSH\WhatsAppNotify\CRM
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\CRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CustomerExport
 *
 * Exports CRM customers to CSV or JSON.
 * Supports filtered exports (by lifecycle, segment, VIP flag, etc.).
 */
final class CustomerExport {

	private CustomerRepository $repo;

	public function __construct( CustomerRepository $repo ) {
		$this->repo = $repo;
	}

	/**
	 * Export customers to CSV string.
	 *
	 * @param array $filters  Same filters as CustomerRepository::get_customers().
	 * @return string CSV content.
	 */
	public function to_csv( array $filters = [] ): string {
		$columns = $this->get_columns();
		$rows    = $this->fetch_all( $filters );

		$lines = [];
		$lines[] = implode( ',', array_map( fn( $c ) => '"' . $c . '"', array_keys( $columns ) ) );

		foreach ( $rows as $row ) {
			$cells = [];
			foreach ( $columns as $key => $field ) {
				$val = $row[ $field ] ?? '';
				if ( is_array( $val ) ) $val = implode( ';', $val );
				$cells[] = '"' . str_replace( '"', '""', $val ) . '"';
			}
			$lines[] = implode( ',', $cells );
		}

		return implode( "\r\n", $lines );
	}

	/**
	 * Export customers to JSON string.
	 *
	 * @param array $filters Filters to apply.
	 */
	public function to_json( array $filters = [] ): string {
		$rows = $this->fetch_all( $filters );
		return wp_json_encode( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ?: '[]';
	}

	/**
	 * Fetch all matching customers (no pagination limit, chunked internally).
	 */
	public function fetch_all( array $filters = [], int $chunk = 500 ): array {
		$all    = [];
		$page   = 1;

		do {
			$result = $this->repo->get_customers( array_merge( $filters, [ 'per_page' => $chunk, 'page' => $page ] ) );
			$rows   = $result['rows'] ?? [];
			$all    = array_merge( $all, $rows );
			$page++;
		} while ( count( $rows ) === $chunk );

		return $all;
	}

	private function get_columns(): array {
		return [
			'ID'             => 'id',
			'Full Name'      => 'full_name',
			'First Name'     => 'first_name',
			'Last Name'      => 'last_name',
			'Phone'          => 'phone',
			'WhatsApp'       => 'whatsapp_phone',
			'Email'          => 'email',
			'Country'        => 'country',
			'State'          => 'state',
			'City'           => 'city',
			'Lifecycle'      => 'lifecycle',
			'VIP'            => 'is_vip',
			'Blocked'        => 'is_blocked',
			'Subscribed'     => 'is_subscribed',
			'Total Orders'   => 'total_orders',
			'Lifetime Value' => 'lifetime_value',
			'Avg Order Value'=> 'avg_order_value',
			'Health Score'   => 'health_score',
			'Last Order'     => 'last_order_at',
			'Last Message'   => 'last_message_at',
			'Last Campaign'  => 'last_campaign_at',
			'Language'       => 'language',
			'Timezone'       => 'timezone',
			'Birthday'       => 'birthday',
			'Anniversary'    => 'anniversary',
			'Source'         => 'source',
			'Created At'     => 'created_at',
		];
	}
}
