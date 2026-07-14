<?php
/**
 * Customer Manager — high-level CRM business logic API.
 *
 * @package TSH\WhatsAppNotify\CRM
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\CRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CustomerManager
 *
 * Single entry point for all CRM operations from AJAX handlers.
 * Orchestrates the repository, scoring, lifecycle, and activity layers.
 */
final class CustomerManager {

	private CustomerRepository $repo;
	private CustomerScoring    $scoring;
	private CustomerLifecycle  $lifecycle;
	private CustomerActivity   $activity;
	private CustomerLogger     $logger;

	public function __construct(
		CustomerRepository $repo,
		CustomerScoring    $scoring,
		CustomerLifecycle  $lifecycle,
		CustomerActivity   $activity,
		CustomerLogger     $logger
	) {
		$this->repo      = $repo;
		$this->scoring   = $scoring;
		$this->lifecycle = $lifecycle;
		$this->activity  = $activity;
		$this->logger    = $logger;
	}

	// =========================================================================
	// CUSTOMER CRUD
	// =========================================================================

	/**
	 * Create a new CRM customer from raw input data.
	 *
	 * @return array{ success: bool, customer_id: int, message: string }
	 */
	public function create( array $data ): array {
		// Require at minimum phone or email
		if ( empty( $data['phone'] ) && empty( $data['email'] ) ) {
			return [ 'success' => false, 'message' => __( 'Phone or email is required.', 'tsh-whatsapp-notify' ) ];
		}

		// Dedup check
		if ( ! empty( $data['phone'] ) && $this->repo->get_customer_by_phone( $data['phone'] ) ) {
			return [ 'success' => false, 'message' => __( 'A customer with this phone already exists.', 'tsh-whatsapp-notify' ) ];
		}
		if ( ! empty( $data['email'] ) && $this->repo->get_customer_by_email( $data['email'] ) ) {
			return [ 'success' => false, 'message' => __( 'A customer with this email already exists.', 'tsh-whatsapp-notify' ) ];
		}

		$data['lifecycle'] = $data['lifecycle'] ?? CustomerLifecycle::LEAD;
		$id = $this->repo->insert_customer( $data );

		if ( ! $id ) {
			return [ 'success' => false, 'message' => __( 'Failed to create customer.', 'tsh-whatsapp-notify' ) ];
		}

		$this->scoring->calculate( $id );
		$this->activity->record( $id, CustomerActivity::TYPE_IMPORT, [
			'subject' => __( 'Customer profile created', 'tsh-whatsapp-notify' ),
		] );
		$this->logger->info( "Customer #{$id} created" );

		return [ 'success' => true, 'customer_id' => $id, 'message' => __( 'Customer created.', 'tsh-whatsapp-notify' ) ];
	}

	/**
	 * Update a customer profile.
	 */
	public function update( int $id, array $data ): array {
		$customer = $this->repo->get_customer( $id );
		if ( ! $customer ) {
			return [ 'success' => false, 'message' => __( 'Customer not found.', 'tsh-whatsapp-notify' ) ];
		}

		$ok = $this->repo->update_customer( $id, $data );

		// If lifecycle was explicitly set
		if ( ! empty( $data['lifecycle'] ) && $data['lifecycle'] !== $customer['lifecycle'] ) {
			$this->activity->record( $id, CustomerActivity::TYPE_LIFECYCLE_CHANGE, [
				'subject' => sprintf( __( 'Lifecycle: %s → %s', 'tsh-whatsapp-notify' ), $customer['lifecycle'], $data['lifecycle'] ),
				'data'    => [ 'old' => $customer['lifecycle'], 'new' => $data['lifecycle'] ],
			] );
		}

		if ( $ok ) {
			$this->scoring->calculate( $id );
		}

		return [ 'success' => $ok, 'message' => $ok ? __( 'Customer updated.', 'tsh-whatsapp-notify' ) : __( 'Update failed.', 'tsh-whatsapp-notify' ) ];
	}

	/**
	 * Delete a customer and all associated CRM data.
	 */
	public function delete( int $id ): bool {
		// Cascade-delete owned rows (notes, tasks, activity, scores)
		global $wpdb;
		foreach ( [ 'notes', 'tasks', 'activity', 'scores' ] as $table_method ) {
			$wpdb->delete( $this->repo->{$table_method}(), [ 'customer_id' => $id ] );
		}
		return $this->repo->delete_customer( $id );
	}

	// =========================================================================
	// VIP / BLOCK
	// =========================================================================

	public function set_vip( int $id, bool $vip, bool $manual = true ): array {
		$ok = $this->repo->update_customer( $id, [
			'is_vip'    => (int) $vip,
			'vip_manual'=> (int) $manual,
			'lifecycle' => $vip ? CustomerLifecycle::VIP : CustomerLifecycle::ACTIVE,
		] );
		if ( $ok ) {
			$type = $vip ? CustomerActivity::TYPE_VIP_GRANTED : CustomerActivity::TYPE_VIP_REVOKED;
			$this->activity->record( $id, $type, [ 'subject' => $vip ? __( 'VIP granted', 'tsh-whatsapp-notify' ) : __( 'VIP revoked', 'tsh-whatsapp-notify' ) ] );
		}
		return [ 'success' => $ok ];
	}

	public function set_blocked( int $id, bool $blocked ): array {
		$ok = $this->repo->update_customer( $id, [ 'is_blocked' => (int) $blocked ] );
		if ( $ok ) {
			$type = $blocked ? CustomerActivity::TYPE_BLOCKED : CustomerActivity::TYPE_UNBLOCKED;
			$this->activity->record( $id, $type, [ 'subject' => $blocked ? __( 'Customer blocked', 'tsh-whatsapp-notify' ) : __( 'Customer unblocked', 'tsh-whatsapp-notify' ) ] );
		}
		return [ 'success' => $ok ];
	}

	// =========================================================================
	// LIFECYCLE
	// =========================================================================

	public function update_lifecycle( int $id, string $lifecycle ): array {
		$ok = $this->lifecycle->set_lifecycle( $id, $lifecycle );
		return [ 'success' => $ok ];
	}

	// =========================================================================
	// SCORES
	// =========================================================================

	public function recalculate_scores( int $customer_id ): array {
		$scores = $this->scoring->calculate( $customer_id );
		return [ 'success' => ! empty( $scores ), 'scores' => $scores ];
	}

	/**
	 * Sync a WooCommerce customer into the CRM.
	 * Creates or updates based on phone/email match.
	 */
	public function sync_from_woocommerce( int $wc_customer_id ): ?int {
		$user = get_user_by( 'id', $wc_customer_id );
		if ( ! $user ) return null;

		$phone = get_user_meta( $wc_customer_id, 'billing_phone', true );
		$email = $user->user_email;

		$data = [
			'wp_user_id'    => $wc_customer_id,
			'wc_customer_id'=> $wc_customer_id,
			'first_name'    => get_user_meta( $wc_customer_id, 'billing_first_name', true ) ?: $user->first_name,
			'last_name'     => get_user_meta( $wc_customer_id, 'billing_last_name', true ) ?: $user->last_name,
			'email'         => $email,
			'phone'         => $phone,
			'whatsapp_phone'=> $phone,
			'country'       => get_user_meta( $wc_customer_id, 'billing_country', true ),
			'state'         => get_user_meta( $wc_customer_id, 'billing_state', true ),
			'city'          => get_user_meta( $wc_customer_id, 'billing_city', true ),
			'address'       => get_user_meta( $wc_customer_id, 'billing_address_1', true ),
			'source'        => 'woocommerce',
		];

		// Pull order stats
		$orders_data = ( new CustomerOrders( $this->repo ) )->get_stats_for_wc_customer( $wc_customer_id );
		if ( $orders_data ) {
			$data = array_merge( $data, $orders_data );
		}

		$customer_id = null;
		if ( $phone ) {
			$customer_id = $this->repo->upsert_by_phone( $phone, $data );
		} elseif ( $email ) {
			$customer_id = $this->repo->upsert_by_email( $email, $data );
		}

		if ( $customer_id ) {
			$this->scoring->calculate( $customer_id );
		}

		return $customer_id;
	}
}
