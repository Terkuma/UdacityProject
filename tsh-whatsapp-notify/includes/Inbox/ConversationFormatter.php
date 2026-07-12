<?php
/**
 * Conversation formatter — prepares data for admin UI consumption.
 *
 * @package TSH\WhatsAppNotify\Inbox
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Inbox;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ConversationFormatter
 *
 * Converts raw conversation and message database rows into display-safe,
 * UI-ready arrays for JSON responses.
 */
final class ConversationFormatter {

	/** @var CustomerMatcher */
	private CustomerMatcher $customer_matcher;

	/** @var MessageRenderer */
	private MessageRenderer $renderer;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->customer_matcher = new CustomerMatcher();
		$this->renderer         = new MessageRenderer();
	}

	/**
	 * Format a list of conversation rows for the inbox sidebar.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 * @return array<int, array<string, mixed>>
	 */
	public function format_list( array $rows ): array {
		return array_map( [ $this, 'format_list_item' ], $rows );
	}

	/**
	 * Format a single conversation row for the list view.
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	public function format_list_item( array $row ): array {
		$customer_id = ! empty( $row['customer_id'] ) ? (int) $row['customer_id'] : null;
		$name        = $this->resolve_display_name( $row, $customer_id );

		return [
			'id'                => (int) $row['id'],
			'phone'             => esc_html( $row['phone'] ),
			'display_name'      => esc_html( $name ),
			'customer_id'       => $customer_id,
			'order_id'          => ! empty( $row['order_id'] ) ? (int) $row['order_id'] : null,
			'status'            => sanitize_key( $row['status'] ),
			'is_pinned'         => (bool) $row['is_pinned'],
			'is_archived'       => (bool) $row['is_archived'],
			'unread_count'      => (int) $row['unread_count'],
			'labels'            => $this->decode_labels( $row['labels'] ?? '[]' ),
			'assigned_to'       => ! empty( $row['assigned_to'] ) ? (int) $row['assigned_to'] : null,
			'assigned_name'     => ! empty( $row['assigned_to'] ) ? $this->resolve_agent_name( (int) $row['assigned_to'] ) : null,
			'last_message_at'   => esc_html( $row['last_message_at'] ?? '' ),
			'last_message_human'=> $this->human_time( $row['last_message_at'] ?? '' ),
			'last_message_text' => esc_html( mb_substr( $row['last_message_text'] ?? '', 0, 80 ) ),
			'avatar_url'        => $this->resolve_avatar( $customer_id, $row['phone'] ),
		];
	}

	/**
	 * Format a single conversation detail (for the chat view).
	 *
	 * @param array<string, mixed>             $conversation Raw DB row.
	 * @param array<int, array<string, mixed>> $messages     Raw message rows.
	 * @return array<string, mixed>
	 */
	public function format_detail( array $conversation, array $messages ): array {
		$item       = $this->format_list_item( $conversation );
		$formatted  = array_map( [ $this->renderer, 'render' ], $messages );

		// Group messages by date for the chat UI.
		$grouped = $this->group_by_date( $formatted );

		$item['messages']       = $formatted;
		$item['messages_grouped'] = $grouped;

		return $item;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Resolve a human-readable display name for a conversation row.
	 *
	 * Priority: contact_name from webhook → WC customer display name → phone.
	 *
	 * @param array<string, mixed> $row
	 * @param int|null             $customer_id
	 * @return string
	 */
	private function resolve_display_name( array $row, ?int $customer_id ): string {
		if ( ! empty( $row['contact_name'] ) ) {
			return (string) $row['contact_name'];
		}

		if ( $customer_id ) {
			$profile = $this->customer_matcher->get_customer_profile( $customer_id );
			if ( ! empty( $profile['display_name'] ) ) {
				return (string) $profile['display_name'];
			}
		}

		return (string) $row['phone'];
	}

	/**
	 * Resolve display name of an assigned agent.
	 *
	 * @param int $user_id
	 * @return string
	 */
	private function resolve_agent_name( int $user_id ): string {
		$user = get_user_by( 'id', $user_id );
		return $user ? $user->display_name : '';
	}

	/**
	 * Resolve an avatar URL.
	 *
	 * @param int|null $customer_id
	 * @param string   $phone
	 * @return string
	 */
	private function resolve_avatar( ?int $customer_id, string $phone ): string {
		if ( $customer_id ) {
			return get_avatar_url( $customer_id, [ 'size' => 48 ] );
		}
		// Use phone-based hash for Gravatar-style consistent avatar.
		return get_avatar_url( md5( $phone ), [ 'size' => 48, 'default' => 'identicon' ] );
	}

	/**
	 * Decode a JSON label array.
	 *
	 * @param string $json
	 * @return string[]
	 */
	private function decode_labels( string $json ): array {
		$labels = json_decode( $json, true );
		return is_array( $labels ) ? array_map( 'sanitize_text_field', $labels ) : [];
	}

	/**
	 * Return a human-readable relative time string.
	 *
	 * @param string $mysql_datetime
	 * @return string
	 */
	private function human_time( string $mysql_datetime ): string {
		if ( empty( $mysql_datetime ) ) {
			return '';
		}
		$timestamp = strtotime( $mysql_datetime . ' UTC' );
		if ( ! $timestamp ) {
			return '';
		}
		$diff = time() - $timestamp;
		if ( $diff < 60 ) {
			return __( 'Just now', 'tsh-whatsapp-notify' );
		}
		if ( $diff < 3600 ) {
			/* translators: %d: number of minutes */
			return sprintf( _n( '%d min ago', '%d mins ago', (int) floor( $diff / 60 ), 'tsh-whatsapp-notify' ), (int) floor( $diff / 60 ) );
		}
		if ( $diff < 86400 ) {
			/* translators: %d: number of hours */
			return sprintf( _n( '%d hour ago', '%d hours ago', (int) floor( $diff / 3600 ), 'tsh-whatsapp-notify' ), (int) floor( $diff / 3600 ) );
		}
		if ( $diff < 604800 ) {
			/* translators: %d: number of days */
			return sprintf( _n( '%d day ago', '%d days ago', (int) floor( $diff / 86400 ), 'tsh-whatsapp-notify' ), (int) floor( $diff / 86400 ) );
		}
		return date_i18n( get_option( 'date_format' ), $timestamp );
	}

	/**
	 * Group formatted messages by date for date separators in the chat UI.
	 *
	 * @param array<int, array<string, mixed>> $messages
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function group_by_date( array $messages ): array {
		$groups = [];
		foreach ( $messages as $msg ) {
			$date = ! empty( $msg['timestamp'] )
				? gmdate( 'Y-m-d', strtotime( $msg['timestamp'] . ' UTC' ) )
				: gmdate( 'Y-m-d' );
			$groups[ $date ][] = $msg;
		}
		return $groups;
	}
}
