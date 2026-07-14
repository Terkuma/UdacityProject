<?php
/**
 * Customer Timeline — chronological event aggregation.
 *
 * @package TSH\WhatsAppNotify\CRM
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\CRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CustomerTimeline
 *
 * Merges activity events from multiple sources into a single
 * chronological timeline:
 *   - CRM activity log
 *   - WooCommerce orders
 *   - WhatsApp messages
 *   - Campaigns
 *   - Coupon usage
 *   - Notes (pinned/recent)
 *   - Tasks
 */
final class CustomerTimeline {

	private CustomerRepository    $repo;
	private CustomerOrders        $orders;
	private CustomerConversations $conversations;
	private CustomerCampaigns     $campaigns;
	private CustomerCoupons       $coupons;

	public function __construct(
		CustomerRepository    $repo,
		CustomerOrders        $orders,
		CustomerConversations $conversations,
		CustomerCampaigns     $campaigns,
		CustomerCoupons       $coupons
	) {
		$this->repo          = $repo;
		$this->orders        = $orders;
		$this->conversations = $conversations;
		$this->campaigns     = $campaigns;
		$this->coupons       = $coupons;
	}

	/**
	 * Build the full timeline for a customer.
	 *
	 * @param int $customer_id  CRM customer ID.
	 * @param int $limit        Max events to return.
	 * @param int $offset       Pagination offset.
	 * @param array $filters    Optional: [ 'type' => 'order' ] etc.
	 */
	public function get( int $customer_id, int $limit = 50, int $offset = 0, array $filters = [] ): array {
		$customer = $this->repo->get_customer( $customer_id );
		if ( ! $customer ) return [ 'events' => [], 'total' => 0 ];

		$events = [];

		// 1. CRM activity log
		$activities = $this->repo->get_activity( $customer_id, 500 );
		$type_labels = CustomerActivity::type_labels();
		$type_icons  = CustomerActivity::type_icons();
		foreach ( $activities as $a ) {
			$events[] = [
				'id'          => 'act_' . $a['id'],
				'type'        => $a['type'],
				'label'       => $type_labels[ $a['type'] ] ?? ucwords( str_replace( '_', ' ', $a['type'] ) ),
				'icon'        => $type_icons[ $a['type'] ] ?? '📌',
				'subject'     => $a['subject'],
				'description' => $a['description'],
				'data'        => is_string( $a['data'] ) ? json_decode( $a['data'], true ) : $a['data'],
				'reference_type' => $a['reference_type'],
				'reference_id'   => $a['reference_id'],
				'created_at'  => $a['created_at'],
				'source'      => 'activity',
			];
		}

		// 2. WooCommerce orders
		$order_events = $this->orders->get_timeline_events( $customer );
		$events       = array_merge( $events, $order_events );

		// 3. WhatsApp conversations
		$msg_events = $this->conversations->get_timeline_events( $customer );
		$events     = array_merge( $events, $msg_events );

		// 4. Campaigns
		$cmp_events = $this->campaigns->get_timeline_events( $customer );
		$events     = array_merge( $events, $cmp_events );

		// 5. Coupons
		$coupon_events = $this->coupons->get_timeline_events( $customer );
		$events        = array_merge( $events, $coupon_events );

		// 6. Notes
		$notes = $this->repo->get_notes( $customer_id, 50 );
		foreach ( $notes as $note ) {
			$events[] = [
				'id'          => 'note_' . $note['id'],
				'type'        => 'note',
				'label'       => __( 'Note', 'tsh-whatsapp-notify' ),
				'icon'        => '📝',
				'subject'     => __( 'Private note', 'tsh-whatsapp-notify' ),
				'description' => wp_trim_words( $note['content'], 20 ),
				'data'        => [ 'is_pinned' => (bool) $note['is_pinned'] ],
				'created_at'  => $note['created_at'],
				'source'      => 'note',
			];
		}

		// 7. Tasks
		$tasks = $this->repo->get_tasks( $customer_id );
		foreach ( $tasks as $task ) {
			if ( $task['completed_at'] ) {
				$events[] = [
					'id'          => 'task_' . $task['id'],
					'type'        => 'task_completed',
					'label'       => __( 'Task Completed', 'tsh-whatsapp-notify' ),
					'icon'        => '☑️',
					'subject'     => esc_html( $task['title'] ),
					'description' => '',
					'data'        => [ 'priority' => $task['priority'] ],
					'created_at'  => $task['completed_at'],
					'source'      => 'task',
				];
			}
		}

		// Filter by type if requested
		if ( ! empty( $filters['type'] ) ) {
			$filter_type = $filters['type'];
			$events = array_filter( $events, fn( $e ) => $e['source'] === $filter_type || $e['type'] === $filter_type );
		}

		// Sort descending by date
		usort( $events, function ( $a, $b ) {
			return strcmp( $b['created_at'] ?? '', $a['created_at'] ?? '' );
		} );

		$total         = count( $events );
		$paged_events  = array_slice( $events, $offset, $limit );

		return [
			'events' => array_values( $paged_events ),
			'total'  => $total,
		];
	}
}
