<?php
/**
 * Conversation sync — re-links conversations with WC customers and orders.
 *
 * @package TSH\WhatsAppNotify\Inbox
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Inbox;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ConversationSync
 *
 * Re-scans existing conversations and refreshes:
 *  - customer_id (matched from phone)
 *  - order_id (most recent order linked to the customer/phone)
 *
 * Also provides a full rebuild (truncate + re-import) placeholder that
 * can be extended to re-pull history from Meta if desired.
 */
final class ConversationSync {

	/** @var ConversationRepository */
	private ConversationRepository $repo;

	/** @var CustomerMatcher */
	private CustomerMatcher $customer_matcher;

	/** @var OrderMatcher */
	private OrderMatcher $order_matcher;

	/** @var ConversationCache */
	private ConversationCache $cache;

	/** @var ConversationLogger */
	private ConversationLogger $logger;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->repo             = new ConversationRepository();
		$this->customer_matcher = new CustomerMatcher();
		$this->order_matcher    = new OrderMatcher();
		$this->cache            = new ConversationCache();
		$this->logger           = new ConversationLogger();
	}

	/**
	 * Resync all conversations — refresh customer + order links.
	 *
	 * @return array{ updated: int, skipped: int }
	 */
	public function resync_all(): array {
		$counts = [ 'updated' => 0, 'skipped' => 0 ];

		$result = $this->repo->get_conversations( [ 'status' => 'all', 'per_page' => 100 ] );
		$rows   = $result['rows'];

		foreach ( $rows as $conv ) {
			$phone       = $conv['phone'];
			$customer_id = $this->customer_matcher->find_user_id( $phone );
			$orders      = $this->order_matcher->find_orders( $phone, $customer_id, 1 );
			$order_id    = ! empty( $orders ) ? (int) $orders[0]['id'] : null;

			$update = [];
			if ( (int) ( $conv['customer_id'] ?? 0 ) !== (int) ( $customer_id ?? 0 ) ) {
				$update['customer_id'] = $customer_id;
			}
			if ( (int) ( $conv['order_id'] ?? 0 ) !== (int) ( $order_id ?? 0 ) ) {
				$update['order_id'] = $order_id;
			}

			if ( $update ) {
				$this->repo->update_conversation( (int) $conv['id'], $update );
				$this->cache->bust_conversation( (int) $conv['id'] );
				$counts['updated']++;
			} else {
				$counts['skipped']++;
			}
		}

		$this->logger->info( 'Conversation resync complete.', $counts );
		return $counts;
	}

	/**
	 * Rebuild the inbox — wipes all conversation/message data.
	 * Use only as a last resort from Tools.
	 *
	 * @return bool
	 */
	public function rebuild_inbox(): bool {
		$this->repo->truncate_all();
		$this->cache->flush();
		$this->logger->warning( 'Inbox rebuilt — all conversation and message data deleted.' );
		do_action( 'tsh_wa_inbox_rebuilt' );
		return true;
	}
}
