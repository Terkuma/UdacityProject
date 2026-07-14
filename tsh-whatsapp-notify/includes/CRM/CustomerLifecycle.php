<?php
/**
 * Customer Lifecycle — state machine for lifecycle transitions.
 *
 * @package TSH\WhatsAppNotify\CRM
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\CRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CustomerLifecycle
 *
 * Determines and updates the lifecycle stage for each customer.
 *
 * Stages (in order of progression):
 *   lead → new → active → returning → vip → dormant → inactive → lost
 *
 * Auto-update rules are applied daily via WP-cron.
 */
final class CustomerLifecycle {

	public const LEAD      = 'lead';
	public const NEW       = 'new';
	public const ACTIVE    = 'active';
	public const RETURNING = 'returning';
	public const VIP       = 'vip';
	public const DORMANT   = 'dormant';
	public const INACTIVE  = 'inactive';
	public const LOST      = 'lost';

	public const ALL = [ self::LEAD, self::NEW, self::ACTIVE, self::RETURNING, self::VIP, self::DORMANT, self::INACTIVE, self::LOST ];

	private CustomerRepository $repo;
	private CustomerActivity   $activity;

	public function __construct( CustomerRepository $repo, CustomerActivity $activity ) {
		$this->repo     = $repo;
		$this->activity = $activity;
	}

	/**
	 * Register hooks.
	 */
	public function register_hooks(): void {
		add_action( 'tsh_wa_crm_daily_lifecycle', [ $this, 'run_lifecycle_updates' ] );
		if ( ! wp_next_scheduled( 'tsh_wa_crm_daily_lifecycle' ) ) {
			wp_schedule_event( time(), 'daily', 'tsh_wa_crm_daily_lifecycle' );
		}
	}

	/**
	 * Process lifecycle transitions for all customers in chunks.
	 */
	public function run_lifecycle_updates( int $chunk = 200 ): void {
		$settings     = CustomerSettings::get();
		$inactive_days= (int) ( $settings['inactive_days'] ?? 90 );
		$dormant_days = (int) ( $settings['dormant_days']  ?? 60 );
		$vip_ltv      = (float) ( $settings['vip_ltv_threshold'] ?? 500 );
		$vip_orders   = (int) ( $settings['vip_order_threshold'] ?? 5 );

		global $wpdb;
		$table  = $this->repo->customers();
		$offset = 0;

		do {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, lifecycle, is_vip, vip_manual, total_orders, lifetime_value, last_order_at, created_at FROM {$table} LIMIT %d OFFSET %d",
				$chunk, $offset
			), ARRAY_A );

			foreach ( $rows as $c ) {
				$new_lifecycle = $this->compute_lifecycle( $c, $inactive_days, $dormant_days, $vip_ltv, $vip_orders );
				if ( $new_lifecycle !== $c['lifecycle'] ) {
					$this->repo->update_customer( (int) $c['id'], [ 'lifecycle' => $new_lifecycle ] );
					$this->activity->record( (int) $c['id'], CustomerActivity::TYPE_LIFECYCLE_CHANGE, [
						'subject' => sprintf( __( 'Lifecycle: %s → %s', 'tsh-whatsapp-notify' ), $c['lifecycle'], $new_lifecycle ),
						'data'    => [ 'old' => $c['lifecycle'], 'new' => $new_lifecycle ],
					] );
				}

				// Auto VIP detection (unless manual override)
				if ( ! $c['vip_manual'] ) {
					$should_vip = ( (float) $c['lifetime_value'] >= $vip_ltv ) || ( (int) $c['total_orders'] >= $vip_orders );
					if ( $should_vip && ! $c['is_vip'] ) {
						$this->repo->update_customer( (int) $c['id'], [ 'is_vip' => 1, 'lifecycle' => self::VIP ] );
						$this->activity->record( (int) $c['id'], CustomerActivity::TYPE_VIP_GRANTED, [
							'subject' => __( 'VIP status automatically granted', 'tsh-whatsapp-notify' ),
						] );
					}
				}
			}

			$offset += $chunk;
		} while ( count( $rows ) === $chunk );
	}

	/**
	 * Manually set lifecycle for one customer.
	 */
	public function set_lifecycle( int $customer_id, string $lifecycle ): bool {
		if ( ! in_array( $lifecycle, self::ALL, true ) ) return false;
		$customer = $this->repo->get_customer( $customer_id );
		if ( ! $customer ) return false;
		$ok = $this->repo->update_customer( $customer_id, [ 'lifecycle' => $lifecycle ] );
		if ( $ok ) {
			$this->activity->record( $customer_id, CustomerActivity::TYPE_LIFECYCLE_CHANGE, [
				'subject' => sprintf( __( 'Lifecycle manually set to: %s', 'tsh-whatsapp-notify' ), $lifecycle ),
				'data'    => [ 'old' => $customer['lifecycle'], 'new' => $lifecycle ],
			] );
		}
		return $ok;
	}

	/**
	 * Get display labels for all lifecycle stages.
	 */
	public static function labels(): array {
		return [
			self::LEAD      => __( 'Lead',           'tsh-whatsapp-notify' ),
			self::NEW       => __( 'New Customer',   'tsh-whatsapp-notify' ),
			self::ACTIVE    => __( 'Active',         'tsh-whatsapp-notify' ),
			self::RETURNING => __( 'Returning',      'tsh-whatsapp-notify' ),
			self::VIP       => __( 'VIP',            'tsh-whatsapp-notify' ),
			self::DORMANT   => __( 'Dormant',        'tsh-whatsapp-notify' ),
			self::INACTIVE  => __( 'Inactive',       'tsh-whatsapp-notify' ),
			self::LOST      => __( 'Lost',           'tsh-whatsapp-notify' ),
		];
	}

	// =========================================================================
	// Internal
	// =========================================================================

	private function compute_lifecycle( array $c, int $inactive_days, int $dormant_days, float $vip_ltv, int $vip_orders ): string {
		$orders   = (int)   $c['total_orders'];
		$ltv      = (float) $c['lifetime_value'];
		$last_order_at = $c['last_order_at'] ?? '';
		$created_at    = $c['created_at']   ?? '';

		// No orders = lead
		if ( $orders === 0 ) {
			return self::LEAD;
		}

		// VIP detection (only if not manual)
		if ( ( $ltv >= $vip_ltv || $orders >= $vip_orders ) && ! (int) $c['vip_manual'] ) {
			return self::VIP;
		}

		// Days since last order
		$days_since_order = PHP_INT_MAX;
		if ( $last_order_at && $last_order_at !== '0000-00-00 00:00:00' ) {
			$days_since_order = (int) ( ( time() - strtotime( $last_order_at ) ) / DAY_IN_SECONDS );
		}

		// New = registered within 30 days, single order
		$days_registered = (int) ( ( time() - strtotime( $created_at ) ) / DAY_IN_SECONDS );
		if ( $orders === 1 && $days_registered <= 30 ) {
			return self::NEW;
		}

		// Lost = no purchase in inactive_days * 2
		if ( $days_since_order >= $inactive_days * 2 ) {
			return self::LOST;
		}

		// Inactive
		if ( $days_since_order >= $inactive_days ) {
			return self::INACTIVE;
		}

		// Dormant
		if ( $days_since_order >= $dormant_days ) {
			return self::DORMANT;
		}

		// Returning = multiple orders
		if ( $orders >= 2 ) {
			return self::RETURNING;
		}

		return self::ACTIVE;
	}
}
