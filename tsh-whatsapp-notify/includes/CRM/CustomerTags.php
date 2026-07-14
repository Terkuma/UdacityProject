<?php
/**
 * Customer Tags — tag definitions and assignment.
 *
 * @package TSH\WhatsAppNotify\CRM
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\CRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CustomerTags
 *
 * Business logic for tag definitions (global) and per-customer tag assignment.
 * Tags are stored as a JSON array of tag IDs in the customer record.
 */
final class CustomerTags {

	public const TYPE_MANUAL   = 'manual';
	public const TYPE_AUTO     = 'auto';
	public const TYPE_WORKFLOW = 'workflow';
	public const TYPE_CAMPAIGN = 'campaign';

	private CustomerRepository $repo;
	private CustomerActivity   $activity;

	public function __construct( CustomerRepository $repo, CustomerActivity $activity ) {
		$this->repo     = $repo;
		$this->activity = $activity;
	}

	/** List all tag definitions. */
	public function list(): array {
		return $this->repo->get_all_tags();
	}

	/** Create a new tag definition. */
	public function create( string $name, string $color = '#6b7280', string $type = self::TYPE_MANUAL, string $description = '' ): array {
		if ( empty( $name ) ) return [ 'success' => false, 'message' => __( 'Tag name required.', 'tsh-whatsapp-notify' ) ];

		$id = $this->repo->insert_tag( [ 'name' => $name, 'color' => $color, 'type' => $type, 'description' => $description ] );
		return $id
			? [ 'success' => true, 'tag_id' => $id ]
			: [ 'success' => false, 'message' => __( 'Failed to create tag.', 'tsh-whatsapp-notify' ) ];
	}

	/** Update a tag definition. */
	public function update( int $tag_id, array $data ): array {
		return [ 'success' => $this->repo->update_tag( $tag_id, $data ) ];
	}

	/** Delete a tag definition (also removes from all customer records). */
	public function delete( int $tag_id ): array {
		global $wpdb;
		$t = $this->repo->customers();
		// Remove tag from all customer JSON arrays — this is best-effort
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$t} SET tags = JSON_REMOVE(tags, JSON_UNQUOTE(JSON_SEARCH(tags, 'one', %s))) WHERE JSON_SEARCH(tags, 'one', %s) IS NOT NULL",
			$tag_id, $tag_id
		) );
		$ok = $this->repo->delete_tag( $tag_id );
		return [ 'success' => $ok ];
	}

	/** Add a tag to a specific customer. */
	public function add_to_customer( int $customer_id, int $tag_id ): array {
		$ok = $this->repo->add_customer_tag( $customer_id, $tag_id );
		if ( $ok ) {
			$tag = $this->repo->get_tag( $tag_id );
			$this->activity->record( $customer_id, CustomerActivity::TYPE_TAG_ADDED, [
				'subject'      => sprintf( __( 'Tag added: %s', 'tsh-whatsapp-notify' ), $tag['name'] ?? '#' . $tag_id ),
				'reference_type' => 'tag',
				'reference_id'   => $tag_id,
			] );
		}
		return [ 'success' => $ok ];
	}

	/** Remove a tag from a specific customer. */
	public function remove_from_customer( int $customer_id, int $tag_id ): array {
		$ok = $this->repo->remove_customer_tag( $customer_id, $tag_id );
		if ( $ok ) {
			$tag = $this->repo->get_tag( $tag_id );
			$this->activity->record( $customer_id, CustomerActivity::TYPE_TAG_REMOVED, [
				'subject' => sprintf( __( 'Tag removed: %s', 'tsh-whatsapp-notify' ), $tag['name'] ?? '#' . $tag_id ),
			] );
		}
		return [ 'success' => $ok ];
	}

	/** Get customers for a specific tag. */
	public function get_customers_by_tag( int $tag_id, int $per_page = 50, int $page = 1 ): array {
		global $wpdb;
		$t      = $this->repo->customers();
		$offset = ( max( 1, $page ) - 1 ) * $per_page;
		$search = (string) $tag_id;

		$total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$t} WHERE JSON_SEARCH(tags, 'one', %s) IS NOT NULL", $search
		) );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, full_name, phone, email, lifecycle, is_vip, health_score FROM {$t}
			 WHERE JSON_SEARCH(tags, 'one', %s) IS NOT NULL
			 ORDER BY full_name ASC LIMIT %d OFFSET %d",
			$search, $per_page, $offset
		), ARRAY_A ) ?: [];

		return [ 'rows' => $rows, 'total' => $total ];
	}

	/** Auto-tag customers based on simple rules. */
	public function auto_tag_vip( int $tag_id ): int {
		global $wpdb;
		$t = $this->repo->customers();
		$customers = $wpdb->get_col( "SELECT id FROM {$t} WHERE is_vip = 1" );
		$count = 0;
		foreach ( $customers as $cid ) {
			if ( $this->repo->add_customer_tag( (int) $cid, $tag_id ) ) ++$count;
		}
		return $count;
	}
}
