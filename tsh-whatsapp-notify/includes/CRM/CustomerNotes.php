<?php
/**
 * Customer Notes — private note management.
 *
 * @package TSH\WhatsAppNotify\CRM
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\CRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CustomerNotes
 *
 * Business logic for creating, updating, pinning, and searching
 * private customer notes.
 */
final class CustomerNotes {

	private CustomerRepository $repo;
	private CustomerActivity   $activity;

	public function __construct( CustomerRepository $repo, CustomerActivity $activity ) {
		$this->repo     = $repo;
		$this->activity = $activity;
	}

	public function list( int $customer_id, int $limit = 50 ): array {
		return $this->repo->get_notes( $customer_id, $limit );
	}

	public function add( int $customer_id, array $data ): array {
		if ( empty( $data['content'] ) ) {
			return [ 'success' => false, 'message' => __( 'Note content is required.', 'tsh-whatsapp-notify' ) ];
		}

		$id = $this->repo->insert_note( array_merge( $data, [ 'customer_id' => $customer_id ] ) );

		if ( $id ) {
			$this->activity->record( $customer_id, CustomerActivity::TYPE_NOTE, [
				'subject'      => __( 'Note added', 'tsh-whatsapp-notify' ),
				'description'  => wp_trim_words( $data['content'], 20 ),
				'reference_type' => 'note',
				'reference_id'   => $id,
			] );
		}

		return $id
			? [ 'success' => true, 'note_id' => $id ]
			: [ 'success' => false, 'message' => __( 'Failed to add note.', 'tsh-whatsapp-notify' ) ];
	}

	public function update( int $note_id, array $data ): array {
		$ok = $this->repo->update_note( $note_id, $data );
		return [ 'success' => $ok ];
	}

	public function delete( int $note_id ): array {
		$ok = $this->repo->delete_note( $note_id );
		return [ 'success' => $ok ];
	}

	public function pin( int $note_id, bool $pinned ): array {
		$note = $this->repo->get_note( $note_id );
		if ( ! $note ) return [ 'success' => false ];
		$ok = $this->repo->update_note( $note_id, [ 'content' => $note['content'], 'is_pinned' => (int) $pinned, 'is_private' => (int) $note['is_private'] ] );
		return [ 'success' => $ok ];
	}

	/**
	 * Search notes across all customers.
	 */
	public function search( string $query, int $limit = 50 ): array {
		global $wpdb;
		$t = $this->repo->notes();
		$s = '%' . $wpdb->esc_like( $query ) . '%';
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT n.*, c.full_name as customer_name FROM {$t} n
			 LEFT JOIN {$this->repo->customers()} c ON c.id = n.customer_id
			 WHERE n.content LIKE %s ORDER BY n.is_pinned DESC, n.created_at DESC LIMIT %d",
			$s, $limit
		), ARRAY_A ) ?: [];
	}
}
