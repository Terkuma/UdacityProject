<?php
/**
 * Processes incoming webhook payloads from Meta.
 *
 * @package TSH\WhatsAppNotify\Inbox
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Inbox;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IncomingMessageProcessor
 *
 * Parses the Meta Cloud API webhook payload and creates/updates conversation
 * and message records accordingly.
 *
 * Supported webhook event types:
 *  - messages      → text, image, audio, video, document, location, sticker,
 *                    interactive (button/list replies), button, reaction
 *  - statuses      → sent, delivered, read, failed
 */
final class IncomingMessageProcessor {

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

	/** @var MediaDownloader */
	private MediaDownloader $media;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->repo             = new ConversationRepository();
		$this->customer_matcher = new CustomerMatcher();
		$this->order_matcher    = new OrderMatcher();
		$this->cache            = new ConversationCache();
		$this->logger           = new ConversationLogger();
		$this->media            = new MediaDownloader();
	}

	/**
	 * Process a full decoded Meta webhook payload array.
	 *
	 * @param array<string, mixed> $payload Decoded JSON from Meta.
	 */
	public function process( array $payload ): void {
		$object = $payload['object'] ?? '';

		if ( 'whatsapp_business_account' !== $object ) {
			$this->logger->warning( 'Unexpected webhook object type.', [ 'object' => $object ] );
			return;
		}

		$entries = $payload['entry'] ?? [];
		foreach ( (array) $entries as $entry ) {
			$changes = $entry['changes'] ?? [];
			foreach ( (array) $changes as $change ) {
				if ( ( $change['field'] ?? '' ) !== 'messages' ) {
					continue;
				}
				$this->process_change( $change['value'] ?? [] );
			}
		}
	}

	// -------------------------------------------------------------------------
	// Change processing
	// -------------------------------------------------------------------------

	/**
	 * Process a single 'messages' change value.
	 *
	 * @param array<string, mixed> $value
	 */
	private function process_change( array $value ): void {
		// Incoming messages.
		$messages = $value['messages'] ?? [];
		foreach ( (array) $messages as $message ) {
			try {
				$this->handle_incoming_message( $message, $value );
			} catch ( \Throwable $e ) {
				$this->logger->error( 'Failed to process incoming message.', [
					'error'   => $e->getMessage(),
					'message' => $message,
				] );
			}
		}

		// Status updates (delivery receipts).
		$statuses = $value['statuses'] ?? [];
		foreach ( (array) $statuses as $status ) {
			try {
				$this->handle_status_update( $status );
			} catch ( \Throwable $e ) {
				$this->logger->error( 'Failed to process status update.', [
					'error'  => $e->getMessage(),
					'status' => $status,
				] );
			}
		}
	}

	/**
	 * Handle one incoming message object from the webhook.
	 *
	 * @param array<string, mixed> $message
	 * @param array<string, mixed> $value   Full change value (for contact info).
	 */
	private function handle_incoming_message( array $message, array $value ): void {
		$phone       = $message['from'] ?? '';
		$meta_msg_id = $message['id']   ?? '';

		if ( empty( $phone ) || empty( $meta_msg_id ) ) {
			$this->logger->warning( 'Incoming message missing phone or ID.', $message );
			return;
		}

		// Deduplicate — Meta may deliver events more than once.
		if ( $this->repo->find_by_meta_id( $meta_msg_id ) ) {
			$this->logger->debug( 'Duplicate message received; skipping.', [ 'meta_message_id' => $meta_msg_id ] );
			return;
		}

		$phone = '+' . ltrim( $phone, '+' ); // Normalise to E.164.

		// Resolve or create conversation.
		$conversation = $this->resolve_conversation( $phone );

		// Extract contact display name if present.
		$contact_name = $this->extract_contact_name( $phone, $value );
		if ( $contact_name && empty( $conversation['contact_name'] ) ) {
			$this->repo->update_conversation( (int) $conversation['id'], [ 'contact_name' => $contact_name ] );
		}

		// Parse message type and content.
		$type         = $message['type'] ?? 'text';
		$content_data = $this->extract_content( $message, $type );

		// Timestamp.
		$timestamp_unix = (int) ( $message['timestamp'] ?? time() );
		$timestamp_mysql = gmdate( 'Y-m-d H:i:s', $timestamp_unix );

		// Build message row.
		$msg_data = [
			'conversation_id' => (int) $conversation['id'],
			'meta_message_id' => $meta_msg_id,
			'phone'           => $phone,
			'direction'       => 'incoming',
			'status'          => 'received',
			'message_type'    => $content_data['type'],
			'content'         => $content_data['text'] ?? null,
			'media_id'        => $content_data['media_id'] ?? null,
			'mime_type'       => $content_data['mime_type'] ?? null,
			'is_note'         => 0,
			'timestamp'       => $timestamp_mysql,
			'raw_payload'     => wp_json_encode( $message ),
		];

		$message_id = $this->repo->insert_message( $msg_data );

		// Update conversation summary.
		$preview = $this->build_preview( $content_data );
		$this->repo->update_conversation( (int) $conversation['id'], [
			'status'            => 'open',      // Re-open if closed.
			'last_message_at'   => $timestamp_mysql,
			'last_message_text' => mb_substr( $preview, 0, 500 ),
		] );
		$this->repo->increment_unread( (int) $conversation['id'] );

		// Queue background media download for media messages.
		if ( ! empty( $content_data['media_id'] ) && $message_id ) {
			$this->media->schedule_download( $message_id, $content_data['media_id'], $content_data['type'] );
		}

		// Fire action for extensibility.
		do_action( 'tsh_wa_incoming_message', $message_id, $conversation, $message );

		// Bust caches.
		$this->cache->bust_conversation( (int) $conversation['id'] );

		$this->logger->info( 'Incoming message stored.', [
			'phone'      => $phone,
			'type'       => $type,
			'message_id' => $message_id,
		], $phone );
	}

	/**
	 * Handle a delivery status update.
	 *
	 * @param array<string, mixed> $status
	 */
	private function handle_status_update( array $status ): void {
		$meta_msg_id  = $status['id']     ?? '';
		$new_status   = $status['status'] ?? '';

		if ( empty( $meta_msg_id ) || empty( $new_status ) ) {
			return;
		}

		// Map Meta statuses to our internal delivery_status vocabulary.
		$status_map = [
			'sent'      => 'sent',
			'delivered' => 'delivered',
			'read'      => 'read',
			'failed'    => 'failed',
		];

		$internal = $status_map[ $new_status ] ?? $new_status;
		$updated  = $this->repo->update_status_by_meta_id( $meta_msg_id, $internal );

		if ( $updated ) {
			$this->logger->debug( 'Message status updated.', [
				'meta_message_id' => $meta_msg_id,
				'status'          => $internal,
			] );
		}

		do_action( 'tsh_wa_message_status_updated', $meta_msg_id, $internal, $status );
	}

	// -------------------------------------------------------------------------
	// Conversation resolution
	// -------------------------------------------------------------------------

	/**
	 * Find existing conversation or create a new one.
	 *
	 * @param string $phone
	 * @return array<string, mixed>
	 */
	private function resolve_conversation( string $phone ): array {
		$existing = $this->repo->find_by_phone( $phone );
		if ( $existing ) {
			return $existing;
		}

		// New conversation — auto-match customer.
		$customer_id = $this->customer_matcher->find_user_id( $phone );
		$orders      = $this->order_matcher->find_orders( $phone, $customer_id, 1 );
		$order_id    = ! empty( $orders ) ? (int) $orders[0]['id'] : null;

		$data = [
			'phone'       => $phone,
			'customer_id' => $customer_id,
			'order_id'    => $order_id,
			'status'      => 'open',
		];

		$conv_id = $this->repo->create_conversation( $data );

		$this->logger->info( 'New conversation created.', [
			'phone'       => $phone,
			'customer_id' => $customer_id,
			'order_id'    => $order_id,
		], $phone );

		do_action( 'tsh_wa_conversation_created', $conv_id, $data );

		return $this->repo->find_conversation( $conv_id ) ?? array_merge( $data, [ 'id' => $conv_id ] );
	}

	// -------------------------------------------------------------------------
	// Content extraction
	// -------------------------------------------------------------------------

	/**
	 * Extract normalised content data from a message object.
	 *
	 * @param array<string, mixed> $message
	 * @param string               $type    Raw Meta message type.
	 * @return array<string, mixed>
	 */
	private function extract_content( array $message, string $type ): array {
		switch ( $type ) {
			case 'text':
				return [
					'type' => 'text',
					'text' => $message['text']['body'] ?? '',
				];

			case 'image':
			case 'audio':
			case 'video':
			case 'document':
			case 'sticker':
				$media = $message[ $type ] ?? [];
				return [
					'type'      => $type,
					'text'      => $media['caption'] ?? null,
					'media_id'  => $media['id']       ?? null,
					'mime_type' => $media['mime_type'] ?? null,
					'filename'  => $media['filename']  ?? null,
				];

			case 'location':
				$loc = $message['location'] ?? [];
				return [
					'type' => 'location',
					'text' => sprintf(
						'📍 %s — Lat: %s, Long: %s',
						$loc['name'] ?? __( 'Location', 'tsh-whatsapp-notify' ),
						$loc['latitude']  ?? '',
						$loc['longitude'] ?? ''
					),
				];

			case 'interactive':
				$interactive = $message['interactive'] ?? [];
				$reply_type  = $interactive['type'] ?? '';
				if ( 'button_reply' === $reply_type ) {
					return [
						'type' => 'interactive',
						'text' => $interactive['button_reply']['title'] ?? __( '[Button Reply]', 'tsh-whatsapp-notify' ),
					];
				}
				if ( 'list_reply' === $reply_type ) {
					return [
						'type' => 'interactive',
						'text' => $interactive['list_reply']['title'] ?? __( '[List Reply]', 'tsh-whatsapp-notify' ),
					];
				}
				return [ 'type' => 'interactive', 'text' => __( '[Interactive]', 'tsh-whatsapp-notify' ) ];

			case 'button':
				return [
					'type' => 'button',
					'text' => $message['button']['text'] ?? __( '[Button]', 'tsh-whatsapp-notify' ),
				];

			case 'reaction':
				$reaction = $message['reaction'] ?? [];
				return [
					'type' => 'reaction',
					'text' => ( $reaction['emoji'] ?? '👍' ) . ' ' . __( 'Reaction', 'tsh-whatsapp-notify' ),
				];

			default:
				return [
					'type' => $type,
					'text' => sprintf( __( '[Unsupported message type: %s]', 'tsh-whatsapp-notify' ), esc_html( $type ) ),
				];
		}
	}

	/**
	 * Build a short text preview for the conversation list.
	 *
	 * @param array<string, mixed> $content
	 * @return string
	 */
	private function build_preview( array $content ): string {
		$type = $content['type'] ?? 'text';

		if ( ! empty( $content['text'] ) ) {
			return (string) $content['text'];
		}

		$icons = [
			'image'       => '📷 ' . __( 'Photo', 'tsh-whatsapp-notify' ),
			'audio'       => '🎵 ' . __( 'Audio', 'tsh-whatsapp-notify' ),
			'video'       => '🎬 ' . __( 'Video', 'tsh-whatsapp-notify' ),
			'document'    => '📄 ' . __( 'Document', 'tsh-whatsapp-notify' ),
			'sticker'     => '🎉 ' . __( 'Sticker', 'tsh-whatsapp-notify' ),
			'location'    => '📍 ' . __( 'Location', 'tsh-whatsapp-notify' ),
			'interactive' => '💬 ' . __( 'Interactive', 'tsh-whatsapp-notify' ),
			'button'      => '🔘 ' . __( 'Button', 'tsh-whatsapp-notify' ),
			'reaction'    => '👍 ' . __( 'Reaction', 'tsh-whatsapp-notify' ),
		];

		return $icons[ $type ] ?? '📩 ' . __( 'Message', 'tsh-whatsapp-notify' );
	}

	/**
	 * Extract a contact display name from the webhook value payload.
	 *
	 * @param string               $phone
	 * @param array<string, mixed> $value
	 * @return string
	 */
	private function extract_contact_name( string $phone, array $value ): string {
		$contacts = $value['contacts'] ?? [];
		foreach ( (array) $contacts as $contact ) {
			$wa_id = '+' . ltrim( (string) ( $contact['wa_id'] ?? '' ), '+' );
			if ( $wa_id === $phone ) {
				return $contact['profile']['name'] ?? '';
			}
		}
		return '';
	}
}
