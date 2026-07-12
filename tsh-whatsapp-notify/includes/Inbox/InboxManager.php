<?php
/**
 * Inbox manager — main orchestrator for Phase 6.
 *
 * @package TSH\WhatsAppNotify\Inbox
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Inbox;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TSH\WhatsAppNotify\API\ApiClient;
use TSH\WhatsAppNotify\API\TokenManager;
use TSH\WhatsAppNotify\Queue\Queue;

/**
 * Class InboxManager
 *
 * Single entry point for all Inbox operations.
 * Composes all Inbox services and exposes a clean public API used by
 * Ajax.php, Admin pages, and CLI tools.
 *
 * Registered in Loader::register_components() as 'inbox_manager'.
 */
final class InboxManager {

	/** @var ConversationRepository */
	private ConversationRepository $repo;

	/** @var ConversationFormatter */
	private ConversationFormatter $formatter;

	/** @var ConversationSearch */
	private ConversationSearch $search;

	/** @var ConversationAssignment */
	private ConversationAssignment $assignment;

	/** @var ConversationSync */
	private ConversationSync $sync;

	/** @var ConversationAnalytics */
	private ConversationAnalytics $analytics;

	/** @var CustomerMatcher */
	private CustomerMatcher $customer_matcher;

	/** @var OrderMatcher */
	private OrderMatcher $order_matcher;

	/** @var ConversationCache */
	private ConversationCache $cache;

	/** @var ConversationLogger */
	private ConversationLogger $logger;

	/** @var MediaDownloader */
	private MediaDownloader $media;

	/** @var WebhookReceiver */
	private WebhookReceiver $webhook;

	/**
	 * Constructor — boot all services.
	 */
	public function __construct() {
		$this->repo             = new ConversationRepository();
		$this->formatter        = new ConversationFormatter();
		$this->search           = new ConversationSearch();
		$this->assignment       = new ConversationAssignment();
		$this->sync             = new ConversationSync();
		$this->analytics        = new ConversationAnalytics();
		$this->customer_matcher = new CustomerMatcher();
		$this->order_matcher    = new OrderMatcher();
		$this->cache            = new ConversationCache();
		$this->logger           = new ConversationLogger();
		$this->media            = new MediaDownloader();
		$this->webhook          = new WebhookReceiver();
	}

	/**
	 * Register all WordPress hooks.
	 */
	public function register_hooks(): void {
		$this->webhook->register_hooks();
		$this->media->register_hooks();

		// Async webhook processing hook.
		add_action( 'tsh_wa_process_webhook', [ $this, 'process_async_webhook' ] );
	}

	// =========================================================================
	// Conversation listing & retrieval
	// =========================================================================

	/**
	 * Get paginated conversations.
	 *
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 */
	public function get_conversations( array $args = [] ): array {
		$cache_key = 'list_' . md5( wp_json_encode( $args ) );
		$cached    = $this->cache->get( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$result    = $this->repo->get_conversations( $args );
		$formatted = $this->formatter->format_list( $result['rows'] );
		$unread    = $this->repo->get_unread_counts();

		$response = [
			'conversations' => $formatted,
			'total'         => $result['total'],
			'unread'        => $unread,
		];

		$this->cache->set( $cache_key, $response, 60 );
		return $response;
	}

	/**
	 * Get a single conversation with its messages.
	 *
	 * @param int $conversation_id
	 * @param int $message_limit
	 * @param int $before_message_id
	 * @return array<string, mixed>|null
	 */
	public function get_conversation_detail( int $conversation_id, int $message_limit = 50, int $before_message_id = 0 ): ?array {
		$conv = $this->repo->find_conversation( $conversation_id );
		if ( ! $conv ) {
			return null;
		}

		$messages = $this->repo->get_messages( $conversation_id, $message_limit, $before_message_id );
		$detail   = $this->formatter->format_detail( $conv, $messages );

		// Attach customer profile.
		$customer_id = ! empty( $conv['customer_id'] ) ? (int) $conv['customer_id'] : null;
		if ( $customer_id ) {
			$detail['customer'] = $this->customer_matcher->get_customer_profile( $customer_id );
		} else {
			$detail['customer'] = $this->customer_matcher->get_guest_profile( $conv['phone'] );
		}

		// Attach linked orders.
		$detail['orders'] = $this->order_matcher->find_orders( $conv['phone'], $customer_id, 10 );

		// Attach available agents and labels.
		$detail['agents'] = $this->assignment->get_available_agents();
		$detail['labels'] = $this->assignment->get_all_labels();

		// Mark conversation as read.
		$this->repo->mark_read( $conversation_id );
		$this->cache->bust_conversation( $conversation_id );

		return $detail;
	}

	// =========================================================================
	// Search
	// =========================================================================

	/**
	 * Search conversations and messages.
	 *
	 * @param string $query
	 * @param int    $limit
	 * @param int    $offset
	 * @return array<string, mixed>
	 */
	public function search( string $query, int $limit = 30, int $offset = 0 ): array {
		$raw    = $this->search->search( $query, $limit, $offset );
		$items  = $this->formatter->format_list( $raw['results'] );
		return [
			'results' => $items,
			'total'   => $raw['total'],
			'query'   => esc_html( $query ),
		];
	}

	// =========================================================================
	// Messaging
	// =========================================================================

	/**
	 * Send an outgoing text reply to a conversation.
	 *
	 * The message is dispatched via the existing queue system so all retry,
	 * rate-limiting, and logging logic is preserved from Phase 4.
	 *
	 * @param int    $conversation_id
	 * @param string $message_text
	 * @return array{ success: bool, message: string, queue_id?: int }
	 */
	public function send_reply( int $conversation_id, string $message_text ): array {
		$conv = $this->repo->find_conversation( $conversation_id );
		if ( ! $conv ) {
			return [ 'success' => false, 'message' => __( 'Conversation not found.', 'tsh-whatsapp-notify' ) ];
		}

		$phone   = $conv['phone'];
		$message = sanitize_textarea_field( $message_text );

		if ( empty( $message ) ) {
			return [ 'success' => false, 'message' => __( 'Message cannot be empty.', 'tsh-whatsapp-notify' ) ];
		}

		// Queue the outbound message.
		$queue    = new Queue();
		$queue_id = $queue->add( [
			'phone'    => $phone,
			'message'  => $message,
			'order_id' => ! empty( $conv['order_id'] ) ? (int) $conv['order_id'] : null,
			'priority' => 3, // Higher priority for manual replies.
		] );

		if ( ! $queue_id ) {
			return [ 'success' => false, 'message' => __( 'Failed to queue message.', 'tsh-whatsapp-notify' ) ];
		}

		// Record the outgoing message in the inbox table immediately.
		$msg_id = $this->repo->insert_message( [
			'conversation_id' => $conversation_id,
			'phone'           => $phone,
			'direction'       => 'outgoing',
			'status'          => 'queued',
			'message_type'    => 'text',
			'content'         => $message,
			'timestamp'       => current_time( 'mysql' ),
		] );

		// Update conversation.
		$this->repo->update_conversation( $conversation_id, [
			'status'            => 'open',
			'last_message_at'   => current_time( 'mysql' ),
			'last_message_text' => mb_substr( $message, 0, 500 ),
		] );

		$this->cache->bust_conversation( $conversation_id );

		$this->logger->info( 'Outgoing reply queued.', [
			'conversation_id' => $conversation_id,
			'queue_id'        => $queue_id,
			'message_id'      => $msg_id,
		], $phone );

		return [
			'success'    => true,
			'message'    => __( 'Message queued for delivery.', 'tsh-whatsapp-notify' ),
			'queue_id'   => $queue_id,
			'message_id' => $msg_id,
		];
	}

	/**
	 * Add an internal note to a conversation (not sent to customer).
	 *
	 * @param int    $conversation_id
	 * @param string $note_text
	 * @return array{ success: bool, message: string, note_id?: int }
	 */
	public function add_note( int $conversation_id, string $note_text ): array {
		$conv = $this->repo->find_conversation( $conversation_id );
		if ( ! $conv ) {
			return [ 'success' => false, 'message' => __( 'Conversation not found.', 'tsh-whatsapp-notify' ) ];
		}

		$note = sanitize_textarea_field( $note_text );
		if ( empty( $note ) ) {
			return [ 'success' => false, 'message' => __( 'Note cannot be empty.', 'tsh-whatsapp-notify' ) ];
		}

		$current_user = wp_get_current_user();
		$note_with_author = sprintf(
			'[%s] %s',
			esc_html( $current_user->display_name ?: 'Admin' ),
			$note
		);

		$note_id = $this->repo->insert_message( [
			'conversation_id' => $conversation_id,
			'phone'           => $conv['phone'],
			'direction'       => 'outgoing',
			'status'          => 'sent',
			'message_type'    => 'note',
			'content'         => $note_with_author,
			'is_note'         => 1,
			'timestamp'       => current_time( 'mysql' ),
		] );

		$this->cache->bust_conversation( $conversation_id );

		return [
			'success' => true,
			'message' => __( 'Note added.', 'tsh-whatsapp-notify' ),
			'note_id' => $note_id,
		];
	}

	// =========================================================================
	// Assignment & label management
	// =========================================================================

	/**
	 * Assign a conversation.
	 *
	 * @param int      $conversation_id
	 * @param int|null $user_id
	 * @return bool
	 */
	public function assign_conversation( int $conversation_id, ?int $user_id ): bool {
		return $this->assignment->assign( $conversation_id, $user_id );
	}

	/**
	 * Update conversation status.
	 *
	 * @param int    $conversation_id
	 * @param string $status
	 * @return bool
	 */
	public function update_status( int $conversation_id, string $status ): bool {
		return $this->assignment->update_status( $conversation_id, $status );
	}

	/**
	 * Toggle pin.
	 *
	 * @param int  $conversation_id
	 * @param bool $pinned
	 * @return bool
	 */
	public function set_pinned( int $conversation_id, bool $pinned ): bool {
		return $this->assignment->set_pinned( $conversation_id, $pinned );
	}

	/**
	 * Add label.
	 *
	 * @param int    $conversation_id
	 * @param string $label
	 * @return bool
	 */
	public function add_label( int $conversation_id, string $label ): bool {
		return $this->assignment->add_label( $conversation_id, $label );
	}

	/**
	 * Remove label.
	 *
	 * @param int    $conversation_id
	 * @param string $label
	 * @return bool
	 */
	public function remove_label( int $conversation_id, string $label ): bool {
		return $this->assignment->remove_label( $conversation_id, $label );
	}

	/**
	 * Get all available labels.
	 *
	 * @return array<int, array<string, string>>
	 */
	public function get_labels(): array {
		return $this->assignment->get_all_labels();
	}

	// =========================================================================
	// Customer profile & orders
	// =========================================================================

	/**
	 * Get the customer profile for a conversation.
	 *
	 * @param int $conversation_id
	 * @return array<string, mixed>
	 */
	public function get_customer_profile( int $conversation_id ): array {
		$conv = $this->repo->find_conversation( $conversation_id );
		if ( ! $conv ) {
			return [];
		}

		$customer_id = ! empty( $conv['customer_id'] ) ? (int) $conv['customer_id'] : null;

		$profile = $customer_id
			? $this->customer_matcher->get_customer_profile( $customer_id )
			: $this->customer_matcher->get_guest_profile( $conv['phone'] );

		$profile['orders'] = $this->order_matcher->find_orders( $conv['phone'], $customer_id, 10 );

		return $profile;
	}

	// =========================================================================
	// Polling
	// =========================================================================

	/**
	 * Get new messages since a given datetime for AJAX polling.
	 *
	 * @param string $since MySQL datetime string.
	 * @return array<int, array<string, mixed>>
	 */
	public function poll_new_messages( string $since ): array {
		$rows = $this->repo->get_new_messages_since( $since );
		$renderer = new MessageRenderer();
		return array_map( [ $renderer, 'render' ], $rows );
	}

	// =========================================================================
	// Analytics
	// =========================================================================

	/**
	 * Get inbox analytics overview.
	 *
	 * @return array<string, mixed>
	 */
	public function get_analytics(): array {
		return $this->analytics->get_overview();
	}

	// =========================================================================
	// Tools
	// =========================================================================

	/**
	 * Resync all conversations (refresh customer + order links).
	 *
	 * @return array<string, int>
	 */
	public function resync(): array {
		return $this->sync->resync_all();
	}

	/**
	 * Rebuild the inbox (destructive — wipes all data).
	 *
	 * @return bool
	 */
	public function rebuild(): bool {
		return $this->sync->rebuild_inbox();
	}

	/**
	 * Clear all inbox caches.
	 */
	public function clear_cache(): void {
		$this->cache->flush();
	}

	/**
	 * Download media for a message by ID.
	 *
	 * @param int $message_id
	 * @return bool
	 */
	public function download_media( int $message_id ): bool {
		global $wpdb;
		$msg_table = $wpdb->prefix . 'tsh_wa_messages';
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM `{$msg_table}` WHERE id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$message_id
		), ARRAY_A );

		if ( ! $row || empty( $row['media_id'] ) ) {
			return false;
		}

		return $this->media->download( $message_id, $row['media_id'], $row['message_type'] );
	}

	// =========================================================================
	// Webhook URL
	// =========================================================================

	/**
	 * Get the public webhook URL.
	 *
	 * @return string
	 */
	public function get_webhook_url(): string {
		return WebhookReceiver::get_webhook_url();
	}

	// =========================================================================
	// Async webhook hook
	// =========================================================================

	/**
	 * Process a webhook payload queued via wp_schedule_single_event.
	 *
	 * @param array<string, mixed> $payload
	 */
	public function process_async_webhook( array $payload ): void {
		$processor = new IncomingMessageProcessor();
		try {
			$processor->process( $payload );
		} catch ( \Throwable $e ) {
			$this->logger->error( 'Async webhook processing failed.', [ 'error' => $e->getMessage() ] );
		}
	}
}
