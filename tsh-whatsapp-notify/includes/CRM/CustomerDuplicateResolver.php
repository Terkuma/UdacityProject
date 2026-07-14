<?php
/**
 * Customer Duplicate Resolver — detect and surface duplicate customers.
 *
 * @package TSH\WhatsAppNotify\CRM
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\CRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CustomerDuplicateResolver
 *
 * Finds CRM customers that share a phone number or email address.
 * Results are surfaced in the CRM UI; merging is handled by CustomerMerge.
 */
final class CustomerDuplicateResolver {

	private CustomerRepository $repo;

	public function __construct( CustomerRepository $repo ) {
		$this->repo = $repo;
	}

	/**
	 * Find all duplicate groups (by phone and by email).
	 *
	 * @return array{
	 *   by_phone: array,
	 *   by_email: array,
	 *   total: int
	 * }
	 */
	public function find(): array {
		$raw   = $this->repo->find_duplicates( 200 );
		$total = count( $raw['by_phone'] ) + count( $raw['by_email'] );

		// Enrich with customer details
		$by_phone = $this->enrich_groups( $raw['by_phone'], 'phone' );
		$by_email = $this->enrich_groups( $raw['by_email'], 'email' );

		return [
			'by_phone' => $by_phone,
			'by_email' => $by_email,
			'total'    => $total,
		];
	}

	/**
	 * Check if two specific customers are potential duplicates.
	 */
	public function are_duplicates( int $id_a, int $id_b ): bool {
		$a = $this->repo->get_customer( $id_a );
		$b = $this->repo->get_customer( $id_b );
		if ( ! $a || ! $b ) return false;

		// Same phone or email
		if ( $a['phone'] && $a['phone'] === $b['phone'] ) return true;
		if ( $a['email'] && $a['email'] === $b['email'] ) return true;
		if ( $a['whatsapp_phone'] && $a['whatsapp_phone'] === $b['whatsapp_phone'] ) return true;

		return false;
	}

	// -------------------------------------------------------------------------

	private function enrich_groups( array $groups, string $field ): array {
		$enriched = [];
		foreach ( $groups as $group ) {
			$ids = array_map( 'intval', explode( ',', $group['ids'] ) );
			$customers = [];
			foreach ( $ids as $id ) {
				$c = $this->repo->get_customer( $id );
				if ( $c ) $customers[] = [
					'id'           => $c['id'],
					'full_name'    => $c['full_name'],
					'phone'        => $c['phone'],
					'email'        => $c['email'],
					'lifetime_value'=> $c['lifetime_value'],
					'total_orders' => $c['total_orders'],
					'created_at'   => $c['created_at'],
					'source'       => $c['source'],
				];
			}
			if ( count( $customers ) > 1 ) {
				$enriched[] = [
					$field       => $group[ $field ],
					'count'      => count( $customers ),
					'customers'  => $customers,
				];
			}
		}
		return $enriched;
	}
}
