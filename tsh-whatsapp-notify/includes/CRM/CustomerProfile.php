<?php
/**
 * Customer Profile — 360° customer view aggregator.
 *
 * @package TSH\WhatsAppNotify\CRM
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\CRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CustomerProfile
 *
 * Assembles the complete 360° customer profile by joining data from:
 *   - CRM customer record
 *   - WooCommerce orders
 *   - WhatsApp Inbox conversations
 *   - Marketing campaigns
 *   - Coupon history
 *   - Notes, tasks, tags, scores
 */
final class CustomerProfile {

	private CustomerRepository    $repo;
	private CustomerOrders        $orders;
	private CustomerConversations $conversations;
	private CustomerCampaigns     $campaigns;
	private CustomerCoupons       $coupons;
	private CustomerScoring       $scoring;

	public function __construct(
		CustomerRepository    $repo,
		CustomerOrders        $orders,
		CustomerConversations $conversations,
		CustomerCampaigns     $campaigns,
		CustomerCoupons       $coupons,
		CustomerScoring       $scoring
	) {
		$this->repo          = $repo;
		$this->orders        = $orders;
		$this->conversations = $conversations;
		$this->campaigns     = $campaigns;
		$this->coupons       = $coupons;
		$this->scoring       = $scoring;
	}

	/**
	 * Build and return the full 360° profile for a customer.
	 *
	 * @param int  $customer_id CRM customer ID.
	 * @param bool $refresh     Force score/lifecycle refresh.
	 */
	public function get( int $customer_id, bool $refresh = false ): ?array {
		$customer = $this->repo->get_customer( $customer_id );
		if ( ! $customer ) return null;

		if ( $refresh ) {
			$this->scoring->calculate( $customer_id );
			$customer = $this->repo->get_customer( $customer_id );
		}

		$scores   = $this->repo->get_scores( $customer_id );
		$all_tags = $this->repo->get_all_tags();
		$tag_map  = [];
		foreach ( $all_tags as $t ) { $tag_map[ (int) $t['id'] ] = $t; }

		// Resolve customer tags
		$customer_tag_ids = is_array( $customer['tags'] ) ? $customer['tags'] : [];
		$resolved_tags    = array_values( array_filter( array_map( fn( $tid ) => $tag_map[ (int) $tid ] ?? null, $customer_tag_ids ) ) );

		// WP user avatar
		$avatar_url = $customer['avatar_url'] ?? '';
		if ( ! $avatar_url && $customer['wp_user_id'] ) {
			$avatar_url = get_avatar_url( (int) $customer['wp_user_id'], [ 'size' => 128 ] );
		}
		if ( ! $avatar_url && $customer['email'] ) {
			$avatar_url = get_avatar_url( $customer['email'], [ 'size' => 128 ] );
		}

		// Recent orders (from WooCommerce)
		$recent_orders = $this->orders->get_recent( $customer, 5 );

		// Conversations summary
		$conv_summary = $this->conversations->get_summary( $customer );

		// Campaign summary
		$campaign_summary = $this->campaigns->get_summary( $customer );

		// Coupon summary
		$coupon_summary = $this->coupons->get_summary( $customer );

		// Notes + tasks
		$notes = $this->repo->get_notes( $customer_id, 10 );
		$tasks = $this->repo->get_tasks( $customer_id, '', 10 );

		// Custom fields
		$custom_field_defs   = $this->repo->get_custom_fields();
		$custom_field_values = $customer['custom_fields'] ?? [];

		// Score label
		$score_label = CustomerScoring::label( (float) ( $customer['health_score'] ?? 0 ) );

		// Lifecycle label
		$lifecycle_labels = CustomerLifecycle::labels();
		$lifecycle_label  = $lifecycle_labels[ $customer['lifecycle'] ?? 'lead' ] ?? $customer['lifecycle'];

		return [
			'customer'         => $customer,
			'avatar_url'       => $avatar_url,
			'tags'             => $resolved_tags,
			'scores'           => $scores,
			'score_label'      => $score_label,
			'lifecycle_label'  => $lifecycle_label,
			'recent_orders'    => $recent_orders,
			'conversations'    => $conv_summary,
			'campaigns'        => $campaign_summary,
			'coupons'          => $coupon_summary,
			'notes'            => $notes,
			'tasks'            => $tasks,
			'custom_fields'    => [
				'definitions' => $custom_field_defs,
				'values'      => $custom_field_values,
			],
		];
	}

	/**
	 * Get a compact card-style summary (for list views / search results).
	 */
	public function get_card( int $customer_id ): ?array {
		$customer = $this->repo->get_customer( $customer_id );
		if ( ! $customer ) return null;

		$avatar_url = '';
		if ( $customer['email'] ) {
			$avatar_url = get_avatar_url( $customer['email'], [ 'size' => 64 ] );
		}

		return [
			'id'           => $customer['id'],
			'full_name'    => $customer['full_name'],
			'phone'        => $customer['phone'],
			'email'        => $customer['email'],
			'avatar_url'   => $avatar_url,
			'lifecycle'    => $customer['lifecycle'],
			'is_vip'       => (bool) $customer['is_vip'],
			'is_blocked'   => (bool) $customer['is_blocked'],
			'health_score' => (float) $customer['health_score'],
			'score_label'  => CustomerScoring::label( (float) $customer['health_score'] ),
			'lifetime_value'=> (float) $customer['lifetime_value'],
			'total_orders' => (int) $customer['total_orders'],
			'last_order_at'=> $customer['last_order_at'],
			'tags'         => $customer['tags'] ?? [],
		];
	}
}
