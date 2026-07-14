<?php
/**
 * Customer Conversations — WhatsApp Inbox data for CRM customers.
 *
 * @package TSH\WhatsAppNotify\CRM
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\CRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CustomerConversations
 *
 * Pulls WhatsApp message history from the Inbox tables (Phase 6)
 * for a CRM customer record.
 */
final class CustomerConversations {

	private CustomerRepository $repo;

	public function __construct( CustomerRepository $repo ) {
		$this->repo = $repo;
	}

	/**
	 * Get conversation summary for the profile sidebar.
	 */
	public function get_summary( array $customer ): array {
		global $wpdb;

		$phone = $customer['whatsapp_phone'] ?: $customer['phone'];
		if ( ! $phone ) return [ 'total_messages' => 0, 'conversations' => 0 ];

		$conv_table = $wpdb->prefix . 'tsh_wa_conversations';
		$msg_table  = $wpdb->prefix . 'tsh_wa_messages';

		// Check tables exist
		if ( ! $this->table_exists( $conv_table ) ) {
			return [ 'total_messages' => 0, 'conversations' => 0, 'last_message_at' => null ];
		}

		$conv_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$conv_table} WHERE phone = %s", $phone
		) );

		$msg_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(m.id) FROM {$msg_table} m
			 INNER JOIN {$conv_table} c ON c.id = m.conversation_id
			 WHERE c.phone = %s", $phone
		) );

		$last_msg = $wpdb->get_var( $wpdb->prepare(
			"SELECT m.created_at FROM {$msg_table} m
			 INNER JOIN {$conv_table} c ON c.id = m.conversation_id
			 WHERE c.phone = %s ORDER BY m.created_at DESC LIMIT 1", $phone
		) );

		return [
			'conversations'  => $conv_count,
			'total_messages' => $msg_count,
			'last_message_at'=> $last_msg,
		];
	}

	/**
	 * Timeline events from WhatsApp messages.
	 */
	public function get_timeline_events( array $customer ): array {
		global $wpdb;

		$phone = $customer['whatsapp_phone'] ?: $customer['phone'];
		if ( ! $phone ) return [];

		$conv_table = $wpdb->prefix . 'tsh_wa_conversations';
		$msg_table  = $wpdb->prefix . 'tsh_wa_messages';

		if ( ! $this->table_exists( $conv_table ) ) return [];

		$messages = $wpdb->get_results( $wpdb->prepare(
			"SELECT m.id, m.direction, m.type, m.body, m.status, m.created_at
			 FROM {$msg_table} m
			 INNER JOIN {$conv_table} c ON c.id = m.conversation_id
			 WHERE c.phone = %s
			 ORDER BY m.created_at DESC LIMIT 100",
			$phone
		), ARRAY_A ) ?: [];

		$events = [];
		foreach ( $messages as $msg ) {
			$is_outbound = $msg['direction'] === 'outbound';
			$events[] = [
				'id'          => 'msg_' . $msg['id'],
				'type'        => $is_outbound ? CustomerActivity::TYPE_MSG_SENT : 'message_received',
				'label'       => $is_outbound ? __( 'Message Sent', 'tsh-whatsapp-notify' ) : __( 'Message Received', 'tsh-whatsapp-notify' ),
				'icon'        => $is_outbound ? '📤' : '📥',
				'subject'     => wp_trim_words( $msg['body'] ?? '', 15, '…' ),
				'description' => sprintf( __( 'Status: %s', 'tsh-whatsapp-notify' ), $msg['status'] ?? '' ),
				'data'        => [ 'direction' => $msg['direction'], 'type' => $msg['type'] ],
				'created_at'  => $msg['created_at'],
				'source'      => 'message',
			];
		}
		return $events;
	}

	/**
	 * Get recent messages for the profile panel.
	 */
	public function get_recent_messages( array $customer, int $limit = 10 ): array {
		global $wpdb;

		$phone = $customer['whatsapp_phone'] ?: $customer['phone'];
		if ( ! $phone ) return [];

		$conv_table = $wpdb->prefix . 'tsh_wa_conversations';
		$msg_table  = $wpdb->prefix . 'tsh_wa_messages';

		if ( ! $this->table_exists( $conv_table ) ) return [];

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT m.* FROM {$msg_table} m
			 INNER JOIN {$conv_table} c ON c.id = m.conversation_id
			 WHERE c.phone = %s ORDER BY m.created_at DESC LIMIT %d",
			$phone, $limit
		), ARRAY_A ) ?: [];
	}

	private function table_exists( string $table ): bool {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}
}
