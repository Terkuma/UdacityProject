<?php
/**
 * Conversation assignment — assign conversations to agents.
 *
 * @package TSH\WhatsAppNotify\Inbox
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Inbox;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ConversationAssignment
 *
 * Manages assigning conversations to WordPress users (agents/team members)
 * and adding/removing labels.
 *
 * Supported assignment targets: any WP user with manage_woocommerce capability.
 */
final class ConversationAssignment {

	/** @var ConversationRepository */
	private ConversationRepository $repo;

	/** @var ConversationLogger */
	private ConversationLogger $logger;

	/** @var ConversationCache */
	private ConversationCache $cache;

	/** @var string[] Allowed label slugs. */
	public const BUILT_IN_LABELS = [
		'vip',
		'refund',
		'shipping',
		'pending',
		'priority',
	];

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->repo   = new ConversationRepository();
		$this->logger = new ConversationLogger();
		$this->cache  = new ConversationCache();
	}

	// -------------------------------------------------------------------------
	// Assignment
	// -------------------------------------------------------------------------

	/**
	 * Assign a conversation to a WP user.
	 *
	 * @param int      $conversation_id
	 * @param int|null $user_id         Null to unassign.
	 * @return bool
	 */
	public function assign( int $conversation_id, ?int $user_id ): bool {
		if ( null !== $user_id && ! $this->is_valid_agent( $user_id ) ) {
			return false;
		}

		$updated = $this->repo->update_conversation( $conversation_id, [
			'assigned_to' => $user_id,
		] );

		if ( $updated ) {
			$this->cache->bust_conversation( $conversation_id );
			do_action( 'tsh_wa_conversation_assigned', $conversation_id, $user_id );
			$this->logger->info( 'Conversation assigned.', [
				'conversation_id' => $conversation_id,
				'user_id'         => $user_id,
			] );
		}

		return (bool) $updated;
	}

	/**
	 * Get a list of available agents (users with manage_woocommerce cap).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_available_agents(): array {
		$users = get_users( [
			'capability' => 'manage_woocommerce',
			'number'     => 50,
			'fields'     => [ 'ID', 'display_name', 'user_email' ],
		] );

		$agents = [];
		foreach ( (array) $users as $user ) {
			$agents[] = [
				'id'    => (int) $user->ID,
				'name'  => esc_html( $user->display_name ),
				'email' => esc_html( $user->user_email ),
			];
		}
		return $agents;
	}

	// -------------------------------------------------------------------------
	// Labels
	// -------------------------------------------------------------------------

	/**
	 * Add a label to a conversation.
	 *
	 * @param int    $conversation_id
	 * @param string $label           Label slug.
	 * @return bool
	 */
	public function add_label( int $conversation_id, string $label ): bool {
		$label = sanitize_key( $label );
		if ( empty( $label ) ) {
			return false;
		}

		$conv = $this->repo->find_conversation( $conversation_id );
		if ( ! $conv ) {
			return false;
		}

		$labels = json_decode( $conv['labels'] ?? '[]', true );
		if ( ! is_array( $labels ) ) {
			$labels = [];
		}

		if ( in_array( $label, $labels, true ) ) {
			return true; // Already has label.
		}

		$labels[] = $label;
		$updated  = $this->repo->update_conversation( $conversation_id, [
			'labels' => wp_json_encode( array_values( $labels ) ),
		] );

		if ( $updated ) {
			$this->cache->bust_conversation( $conversation_id );
		}

		return (bool) $updated;
	}

	/**
	 * Remove a label from a conversation.
	 *
	 * @param int    $conversation_id
	 * @param string $label
	 * @return bool
	 */
	public function remove_label( int $conversation_id, string $label ): bool {
		$label = sanitize_key( $label );
		$conv  = $this->repo->find_conversation( $conversation_id );
		if ( ! $conv ) {
			return false;
		}

		$labels  = json_decode( $conv['labels'] ?? '[]', true );
		$labels  = is_array( $labels ) ? $labels : [];
		$labels  = array_values( array_filter( $labels, fn( $l ) => $l !== $label ) );
		$updated = $this->repo->update_conversation( $conversation_id, [
			'labels' => wp_json_encode( $labels ),
		] );

		if ( $updated ) {
			$this->cache->bust_conversation( $conversation_id );
		}

		return (bool) $updated;
	}

	/**
	 * Get all available labels (built-in + custom from settings).
	 *
	 * @return array<int, array<string, string>>
	 */
	public function get_all_labels(): array {
		$settings = get_option( 'tsh_wa_inbox_settings', [] );
		$custom   = is_array( $settings['custom_labels'] ?? null ) ? $settings['custom_labels'] : [];

		$labels = [];
		$built  = [
			'vip'      => __( 'VIP', 'tsh-whatsapp-notify' ),
			'refund'   => __( 'Refund', 'tsh-whatsapp-notify' ),
			'shipping' => __( 'Shipping', 'tsh-whatsapp-notify' ),
			'pending'  => __( 'Pending', 'tsh-whatsapp-notify' ),
			'priority' => __( 'Priority', 'tsh-whatsapp-notify' ),
		];

		foreach ( $built as $slug => $name ) {
			$labels[] = [ 'slug' => $slug, 'name' => $name, 'type' => 'built-in' ];
		}

		foreach ( $custom as $label ) {
			if ( ! empty( $label['slug'] ) && ! empty( $label['name'] ) ) {
				$labels[] = [
					'slug' => sanitize_key( $label['slug'] ),
					'name' => sanitize_text_field( $label['name'] ),
					'type' => 'custom',
				];
			}
		}

		return $labels;
	}

	// -------------------------------------------------------------------------
	// Status changes
	// -------------------------------------------------------------------------

	/**
	 * Update conversation status.
	 *
	 * @param int    $conversation_id
	 * @param string $status 'open'|'closed'|'archived'
	 * @return bool
	 */
	public function update_status( int $conversation_id, string $status ): bool {
		$allowed = [ 'open', 'closed', 'archived' ];
		if ( ! in_array( $status, $allowed, true ) ) {
			return false;
		}

		$data = [ 'status' => $status ];
		if ( 'archived' === $status ) {
			$data['is_archived'] = 1;
		} else {
			$data['is_archived'] = 0;
		}

		$updated = $this->repo->update_conversation( $conversation_id, $data );
		if ( $updated ) {
			$this->cache->bust_conversation( $conversation_id );
		}
		return (bool) $updated;
	}

	/**
	 * Toggle pin status.
	 *
	 * @param int  $conversation_id
	 * @param bool $pinned
	 * @return bool
	 */
	public function set_pinned( int $conversation_id, bool $pinned ): bool {
		$updated = $this->repo->update_conversation( $conversation_id, [
			'is_pinned' => $pinned ? 1 : 0,
		] );
		if ( $updated ) {
			$this->cache->bust_conversation( $conversation_id );
		}
		return (bool) $updated;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Check whether a user is allowed to be an agent.
	 *
	 * @param int $user_id
	 * @return bool
	 */
	private function is_valid_agent( int $user_id ): bool {
		$user = get_user_by( 'id', $user_id );
		return $user && user_can( $user, 'manage_woocommerce' );
	}
}
