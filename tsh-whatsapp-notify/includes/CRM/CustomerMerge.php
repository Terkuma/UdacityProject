<?php
/**
 * Customer Merge — safely merge two customer records.
 *
 * @package TSH\WhatsAppNotify\CRM
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\CRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CustomerMerge
 *
 * Merges two CRM customer records into one, preserving all data.
 * The "winner" (target) record absorbs all data from the "loser" (source).
 * Notes, tasks, activity, scores, tags, and campaign records are transferred.
 * The source record is then deleted.
 */
final class CustomerMerge {

	private CustomerRepository $repo;
	private CustomerActivity   $activity;
	private CustomerScoring    $scoring;

	public function __construct(
		CustomerRepository $repo,
		CustomerActivity   $activity,
		CustomerScoring    $scoring
	) {
		$this->repo     = $repo;
		$this->activity = $activity;
		$this->scoring  = $scoring;
	}

	/**
	 * Merge source customer into target customer.
	 *
	 * @param int $source_id  Customer to absorb (will be deleted after merge).
	 * @param int $target_id  Customer to keep.
	 * @return array{ success: bool, message: string }
	 */
	public function merge( int $source_id, int $target_id ): array {
		if ( $source_id === $target_id ) {
			return [ 'success' => false, 'message' => __( 'Cannot merge a customer with itself.', 'tsh-whatsapp-notify' ) ];
		}

		$source = $this->repo->get_customer( $source_id );
		$target = $this->repo->get_customer( $target_id );

		if ( ! $source ) return [ 'success' => false, 'message' => __( 'Source customer not found.', 'tsh-whatsapp-notify' ) ];
		if ( ! $target ) return [ 'success' => false, 'message' => __( 'Target customer not found.', 'tsh-whatsapp-notify' ) ];

		// 1. Transfer all relational rows
		$this->repo->transfer_relations( $source_id, $target_id );

		// 2. Transfer campaign audience rows
		$this->transfer_campaign_audience( $source_id, $target_id, $source );

		// 3. Merge scalar fields — fill in blanks on target with source data
		$merged = $this->merge_fields( $source, $target );
		$this->repo->update_customer( $target_id, $merged );

		// 4. Merge tags (union of both)
		$merged_tags = array_values( array_unique( array_merge(
			is_array( $target['tags'] ) ? $target['tags'] : [],
			is_array( $source['tags'] ) ? $source['tags'] : []
		) ) );
		$this->repo->update_customer( $target_id, [ 'tags' => $merged_tags ] );

		// 5. Delete source
		$this->repo->delete_customer( $source_id );

		// 6. Record activity on target
		$this->activity->record( $target_id, CustomerActivity::TYPE_MERGE, [
			'subject' => sprintf( __( 'Merged with customer #%d (%s)', 'tsh-whatsapp-notify' ), $source_id, $source['full_name'] ),
			'data'    => [ 'source_id' => $source_id, 'source_name' => $source['full_name'] ],
		] );

		// 7. Recalculate scores
		$this->scoring->calculate( $target_id );

		return [ 'success' => true, 'message' => __( 'Customers merged successfully.', 'tsh-whatsapp-notify' ) ];
	}

	// -------------------------------------------------------------------------

	private function merge_fields( array $source, array $target ): array {
		$merged = [];
		$fill_blank_fields = [
			'phone', 'whatsapp_phone', 'email', 'first_name', 'last_name', 'full_name',
			'country', 'state', 'city', 'address', 'language', 'timezone',
			'birthday', 'anniversary', 'avatar_url', 'wp_user_id', 'wc_customer_id',
		];
		foreach ( $fill_blank_fields as $f ) {
			if ( empty( $target[ $f ] ) && ! empty( $source[ $f ] ) ) {
				$merged[ $f ] = $source[ $f ];
			}
		}

		// Sum numeric fields
		$merged['total_orders']     = max( (int) $source['total_orders'],   (int) $target['total_orders'] );
		$merged['completed_orders'] = max( (int) $source['completed_orders'], (int) $target['completed_orders'] );
		$merged['cancelled_orders'] = (int) $source['cancelled_orders'] + (int) $target['cancelled_orders'];
		$merged['refunded_orders']  = (int) $source['refunded_orders'] + (int) $target['refunded_orders'];
		$merged['lifetime_value']   = (float) $source['lifetime_value'] + (float) $target['lifetime_value'];
		$merged['notes_count']      = (int) $source['notes_count'] + (int) $target['notes_count'];
		$merged['tasks_count']      = (int) $source['tasks_count'] + (int) $target['tasks_count'];

		// Recalculate AOV
		if ( $merged['total_orders'] > 0 ) {
			$merged['avg_order_value'] = $merged['lifetime_value'] / $merged['total_orders'];
		}

		// Keep VIP if either is VIP
		if ( $source['is_vip'] ) $merged['is_vip'] = 1;

		// Keep earliest dates
		$min_date_fields = [ 'first_order_at', 'created_at' ];
		foreach ( $min_date_fields as $f ) {
			$s = $source[ $f ] ?? '';
			$t = $target[ $f ] ?? '';
			if ( $s && $t ) $merged[ $f ] = min( $s, $t );
			elseif ( $s )   $merged[ $f ] = $s;
		}

		// Keep latest dates
		$max_date_fields = [ 'last_order_at', 'last_message_at', 'last_campaign_at', 'last_coupon_at' ];
		foreach ( $max_date_fields as $f ) {
			$s = $source[ $f ] ?? '';
			$t = $target[ $f ] ?? '';
			if ( $s && $t ) $merged[ $f ] = max( $s, $t );
			elseif ( $s )   $merged[ $f ] = $s;
		}

		// Merge custom fields
		$src_cf  = is_array( $source['custom_fields'] ) ? $source['custom_fields'] : [];
		$tgt_cf  = is_array( $target['custom_fields'] ) ? $target['custom_fields'] : [];
		$merged['custom_fields'] = array_merge( $src_cf, $tgt_cf ); // target wins on conflict

		return $merged;
	}

	private function transfer_campaign_audience( int $source_id, int $target_id, array $source ): void {
		global $wpdb;
		$aud_table = $wpdb->prefix . 'tsh_wa_campaign_audience';
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $aud_table ) );
		if ( ! $exists ) return;

		$phone = $source['whatsapp_phone'] ?: $source['phone'];
		if ( ! $phone ) return;

		// Update phone-based references
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$aud_table} SET customer_id = %d WHERE customer_id = %d",
			$target_id, $source_id
		) );
	}
}
