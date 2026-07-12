<?php
/**
 * Admin recipients manager.
 *
 * @package TSH\WhatsAppNotify\Orders
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Orders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TSH\WhatsAppNotify\Helpers\Helpers;

/**
 * Class AdminRecipient
 *
 * Manages the list of admin/staff phone numbers that receive
 * order notifications. Supports unlimited recipients, each with
 * a name, department, priority, and enabled flag.
 *
 * Recipients are stored in the `tsh_wa_admin_recipients` option as a
 * JSON array. Each recipient:
 *  [
 *    'id'         => 'r_abc123',
 *    'name'       => 'Sales',
 *    'department' => 'Sales',
 *    'phone'      => '+2348012345678',
 *    'enabled'    => '1',
 *    'priority'   => 1,   // lower = higher priority
 *  ]
 */
final class AdminRecipient {

	/** @var string wp_options key. */
	public const OPTION_KEY = 'tsh_wa_admin_recipients';

	// -------------------------------------------------------------------------
	// Public API — reads
	// -------------------------------------------------------------------------

	/**
	 * Return all configured recipients (enabled and disabled).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_all_recipients(): array {
		$raw = get_option( self::OPTION_KEY, [] );

		if ( ! is_array( $raw ) ) {
			return [];
		}

		return array_values( $raw );
	}

	/**
	 * Return only enabled recipients, sorted by priority ascending.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_enabled_recipients(): array {
		$all = $this->get_all_recipients();

		$enabled = array_filter( $all, static function ( array $r ): bool {
			return ! empty( $r['enabled'] ) && '1' === (string) $r['enabled'];
		} );

		// Sort by priority (lower number = higher priority).
		usort( $enabled, static function ( array $a, array $b ): int {
			return ( absint( $a['priority'] ?? 5 ) ) <=> ( absint( $b['priority'] ?? 5 ) );
		} );

		return array_values( $enabled );
	}

	/**
	 * Return the count of enabled recipients.
	 *
	 * @return int
	 */
	public function count_enabled(): int {
		return count( $this->get_enabled_recipients() );
	}

	// -------------------------------------------------------------------------
	// Public API — writes
	// -------------------------------------------------------------------------

	/**
	 * Save the full recipient list.
	 * Each recipient is sanitised before storage.
	 *
	 * @param array<int, array<string, mixed>> $recipients
	 * @return bool
	 */
	public function save_recipients( array $recipients ): bool {
		$sanitised = [];

		foreach ( $recipients as $r ) {
			$sanitised[] = $this->sanitise_recipient( $r );
		}

		return update_option( self::OPTION_KEY, $sanitised, false );
	}

	/**
	 * Add a single new recipient to the list.
	 *
	 * @param array<string, mixed> $recipient
	 * @return string|false New recipient ID, or false on validation failure.
	 */
	public function add_recipient( array $recipient ): string|false {
		$phone = Helpers::format_phone( $recipient['phone'] ?? '' );

		if ( ! Helpers::is_valid_phone( $phone ) ) {
			return false;
		}

		$all       = $this->get_all_recipients();
		$sanitised = $this->sanitise_recipient( $recipient );

		// Generate a unique ID if not provided.
		if ( empty( $sanitised['id'] ) ) {
			$sanitised['id'] = 'r_' . wp_generate_password( 8, false );
		}

		$all[] = $sanitised;

		$this->save_recipients( $all );

		return $sanitised['id'];
	}

	/**
	 * Update a recipient by ID.
	 *
	 * @param string               $id
	 * @param array<string, mixed> $data Fields to update.
	 * @return bool
	 */
	public function update_recipient( string $id, array $data ): bool {
		$all     = $this->get_all_recipients();
		$updated = false;

		foreach ( $all as &$r ) {
			if ( ( $r['id'] ?? '' ) === $id ) {
				$r       = array_merge( $r, $this->sanitise_recipient( $data ) );
				$r['id'] = $id; // preserve original ID.
				$updated = true;
				break;
			}
		}
		unset( $r );

		if ( $updated ) {
			$this->save_recipients( $all );
		}

		return $updated;
	}

	/**
	 * Remove a recipient by ID.
	 *
	 * @param string $id
	 * @return bool
	 */
	public function delete_recipient( string $id ): bool {
		$all      = $this->get_all_recipients();
		$filtered = array_filter( $all, static fn( array $r ) => ( $r['id'] ?? '' ) !== $id );

		if ( count( $filtered ) === count( $all ) ) {
			return false; // Not found.
		}

		return $this->save_recipients( array_values( $filtered ) );
	}

	// -------------------------------------------------------------------------
	// Sanitisation
	// -------------------------------------------------------------------------

	/**
	 * Sanitise a single recipient array.
	 *
	 * @param array<string, mixed> $r Raw input.
	 * @return array<string, mixed> Clean recipient.
	 */
	public function sanitise_recipient( array $r ): array {
		$phone = Helpers::format_phone( sanitize_text_field( $r['phone'] ?? '' ) );

		return [
			'id'         => sanitize_key( $r['id'] ?? '' ),
			'name'       => sanitize_text_field( $r['name'] ?? '' ),
			'department' => sanitize_text_field( $r['department'] ?? '' ),
			'phone'      => $phone,
			'enabled'    => isset( $r['enabled'] ) && in_array( (string) $r['enabled'], [ '1', 'true', true ], true ) ? '1' : '0',
			'priority'   => (string) min( max( absint( $r['priority'] ?? 5 ), 1 ), 10 ),
		];
	}
}
