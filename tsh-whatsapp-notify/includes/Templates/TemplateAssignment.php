<?php
/**
 * Template assignment engine — maps WooCommerce events to templates.
 *
 * @package TSH\WhatsAppNotify\Templates
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Templates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TemplateAssignment
 *
 * Stores and retrieves event → template assignments in the
 * tsh_wa_template_assignments table.
 *
 * Each WooCommerce event can have separate customer and admin templates.
 */
final class TemplateAssignment {

	// -------------------------------------------------------------------------
	// Read operations
	// -------------------------------------------------------------------------

	/**
	 * Get the assignment for a specific event + recipient type.
	 *
	 * @param string $event          WooCommerce event key, e.g. 'order_processing'.
	 * @param string $recipient_type customer|admin
	 * @return object|null DB row or null if not assigned.
	 */
	public function get( string $event, string $recipient_type = 'customer' ): ?object {
		global $wpdb;
		$table = $wpdb->prefix . 'tsh_wa_template_assignments';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE event = %s AND recipient_type = %s AND active = 1 LIMIT 1",
				$event,
				$recipient_type
			)
		);
		return $row ?: null;
	}

	/**
	 * Get the assigned template object for a specific event + recipient type.
	 *
	 * @param string $event
	 * @param string $recipient_type
	 * @return object|null Template row or null.
	 */
	public function get_template( string $event, string $recipient_type = 'customer' ): ?object {
		$assignment = $this->get( $event, $recipient_type );
		if ( ! $assignment ) {
			return null;
		}
		$repo = new TemplateRepository();
		return $repo->find( (int) $assignment->template_id );
	}

	/**
	 * Return all active assignments.
	 *
	 * @return array<int, object>
	 */
	public function get_all(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'tsh_wa_template_assignments';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			"SELECT a.*, t.template_name, t.language, t.status AS template_status
			 FROM `{$table}` a
			 LEFT JOIN `{$wpdb->prefix}tsh_wa_meta_templates` t ON t.id = a.template_id
			 WHERE a.active = 1
			 ORDER BY a.event ASC"
		) ?: [];
	}

	/**
	 * Return all assigned template IDs (for usage tracking).
	 *
	 * @return array<int, int>
	 */
	public function get_assigned_template_ids(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'tsh_wa_template_assignments';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( "SELECT DISTINCT template_id FROM `{$table}` WHERE active = 1" ) ?: [];
		return array_map( static fn( $r ) => (int) $r->template_id, $rows );
	}

	/**
	 * Check if an event has any assignment.
	 *
	 * @param string $event
	 * @return bool
	 */
	public function has_assignment( string $event ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'tsh_wa_template_assignments';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table}` WHERE event = %s AND active = 1",
				$event
			)
		);
		return $count > 0;
	}

	/**
	 * Return assignments keyed by "event:recipient_type" for quick lookup.
	 *
	 * @return array<string, object>
	 */
	public function get_assignment_map(): array {
		$assignments = $this->get_all();
		$map         = [];
		foreach ( $assignments as $a ) {
			$map[ $a->event . ':' . $a->recipient_type ] = $a;
		}
		return $map;
	}

	// -------------------------------------------------------------------------
	// Write operations
	// -------------------------------------------------------------------------

	/**
	 * Assign a template to an event.
	 *
	 * @param string $event
	 * @param int    $template_id
	 * @param string $recipient_type customer|admin
	 * @param string $language       Language preference. Empty = use template's language.
	 * @return bool
	 */
	public function assign( string $event, int $template_id, string $recipient_type = 'customer', string $language = '' ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'tsh_wa_template_assignments';

		// If the template_id is 0, treat as unassign.
		if ( 0 === $template_id ) {
			return $this->unassign( $event, $recipient_type );
		}

		$existing = $this->get( $event, $recipient_type );

		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$result = $wpdb->update(
				$table,
				[
					'template_id'    => $template_id,
					'language'       => $language,
					'active'         => 1,
					'updated_at'     => current_time( 'mysql' ),
				],
				[ 'id' => (int) $existing->id ]
			);
			return false !== $result;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			$table,
			[
				'event'          => sanitize_key( $event ),
				'template_id'    => $template_id,
				'recipient_type' => sanitize_text_field( $recipient_type ),
				'language'       => sanitize_text_field( $language ),
				'active'         => 1,
				'created_at'     => current_time( 'mysql' ),
				'updated_at'     => current_time( 'mysql' ),
			]
		);

		return (bool) $result;
	}

	/**
	 * Remove a template assignment for an event.
	 *
	 * @param string $event
	 * @param string $recipient_type
	 * @return bool
	 */
	public function unassign( string $event, string $recipient_type = 'customer' ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'tsh_wa_template_assignments';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->delete(
			$table,
			[ 'event' => $event, 'recipient_type' => $recipient_type ]
		);
		return false !== $result;
	}

	/**
	 * Deactivate all assignments for a given template (e.g. when it's deleted).
	 *
	 * @param int $template_id
	 * @return bool
	 */
	public function deactivate_for_template( int $template_id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'tsh_wa_template_assignments';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->update(
			$table,
			[ 'active' => 0, 'updated_at' => current_time( 'mysql' ) ],
			[ 'template_id' => $template_id ]
		);
		return false !== $result;
	}

	/**
	 * Save a full assignments array (from the settings form).
	 * Expects [ ['event' => ..., 'customer_template_id' => ..., 'admin_template_id' => ...], ... ]
	 *
	 * @param array<int, array<string, mixed>> $assignments
	 */
	public function save_bulk( array $assignments ): void {
		foreach ( $assignments as $item ) {
			$event = sanitize_key( $item['event'] ?? '' );
			if ( ! $event ) {
				continue;
			}

			$customer_id = absint( $item['customer_template_id'] ?? 0 );
			$admin_id    = absint( $item['admin_template_id']    ?? 0 );

			$this->assign( $event, $customer_id, 'customer' );
			$this->assign( $event, $admin_id, 'admin' );
		}
	}
}
