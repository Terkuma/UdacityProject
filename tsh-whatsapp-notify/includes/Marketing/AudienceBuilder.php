<?php
/**
 * Audience builder — resolves audience_config into a list of recipients.
 *
 * @package TSH\WhatsAppNotify\Marketing
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Marketing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AudienceBuilder
 *
 * Runs the SegmentEngine SQL query in streaming batches so we never load
 * 100,000 rows into memory at once.
 */
final class AudienceBuilder {

	/** Streaming chunk size when resolving large audiences. */
	private const CHUNK_SIZE = 500;

	private SegmentEngine $segment_engine;

	public function __construct( SegmentEngine $segment_engine ) {
		$this->segment_engine = $segment_engine;
	}

	// -------------------------------------------------------------------------
	// Audience count (for preview)
	// -------------------------------------------------------------------------

	/**
	 * Return the estimated audience size for a given config without loading members.
	 *
	 * @param array<string, mixed> $audience_config
	 * @return int
	 */
	public function estimate_count( array $audience_config ): int {
		global $wpdb;

		$inner_sql = $this->segment_engine->build_query( $audience_config );

		// Wrap in a COUNT to avoid fetching all rows.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM ({$inner_sql}) AS estimated" );
	}

	// -------------------------------------------------------------------------
	// Full audience resolution (streaming)
	// -------------------------------------------------------------------------

	/**
	 * Resolve a complete audience and call $callback for each batch.
	 *
	 * The callback receives an array of member arrays:
	 *   [ customer_id, phone, email, name ]
	 *
	 * Skips members with no phone number.
	 *
	 * @param array<string, mixed> $audience_config
	 * @param callable             $callback         function( array $members ): void
	 * @return int Total number of valid members found.
	 */
	public function resolve( array $audience_config, callable $callback ): int {
		global $wpdb;

		$inner_sql = $this->segment_engine->build_query( $audience_config );
		$total     = 0;
		$offset    = 0;

		do {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM ({$inner_sql}) AS a LIMIT %d OFFSET %d", self::CHUNK_SIZE, $offset ),
				ARRAY_A
			);

			if ( empty( $rows ) ) {
				break;
			}

			// Filter out rows with no phone.
			$valid = array_filter( $rows, static fn( $r ) => ! empty( $r['phone'] ) && strlen( $r['phone'] ) >= 7 );
			$valid = array_values( $valid );

			if ( $valid ) {
				$callback( $valid );
				$total += count( $valid );
			}

			$offset += self::CHUNK_SIZE;

		} while ( count( $rows ) === self::CHUNK_SIZE );

		return $total;
	}

	// -------------------------------------------------------------------------
	// Saved segments
	// -------------------------------------------------------------------------

	/**
	 * Load a saved segment config from options.
	 *
	 * @param int $segment_id
	 * @return array<string, mixed>|null
	 */
	public function get_saved_segment( int $segment_id ): ?array {
		$segments = get_option( 'tsh_wa_saved_segments', [] );

		return $segments[ $segment_id ] ?? null;
	}

	/**
	 * Save a named audience config as a re-usable segment.
	 *
	 * @param string               $name
	 * @param array<string, mixed> $config
	 * @return int  Segment ID.
	 */
	public function save_segment( string $name, array $config ): int {
		$segments = get_option( 'tsh_wa_saved_segments', [] );

		$id             = ! empty( $segments ) ? ( max( array_keys( $segments ) ) + 1 ) : 1;
		$segments[ $id ] = [
			'id'         => $id,
			'name'       => sanitize_text_field( $name ),
			'config'     => $config,
			'created_at' => current_time( 'mysql' ),
		];

		update_option( 'tsh_wa_saved_segments', $segments, false );

		return $id;
	}

	/**
	 * Delete a saved segment.
	 *
	 * @param int $segment_id
	 * @return bool
	 */
	public function delete_segment( int $segment_id ): bool {
		$segments = get_option( 'tsh_wa_saved_segments', [] );

		if ( ! isset( $segments[ $segment_id ] ) ) {
			return false;
		}

		unset( $segments[ $segment_id ] );
		update_option( 'tsh_wa_saved_segments', $segments, false );

		return true;
	}

	/**
	 * Get all saved segments.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_all_segments(): array {
		return array_values( get_option( 'tsh_wa_saved_segments', [] ) );
	}

	// -------------------------------------------------------------------------
	// Named audience type labels (for UI)
	// -------------------------------------------------------------------------

	/**
	 * @return array<string, string>
	 */
	public function get_audience_type_labels(): array {
		return [
			'all_customers'     => __( 'All Customers',        'tsh-whatsapp-notify' ),
			'previous_buyers'   => __( 'Previous Buyers',      'tsh-whatsapp-notify' ),
			'vip_customers'     => __( 'VIP Customers',        'tsh-whatsapp-notify' ),
			'high_spend'        => __( 'High Spenders',        'tsh-whatsapp-notify' ),
			'low_spend'         => __( 'Low Spenders',         'tsh-whatsapp-notify' ),
			'never_purchased'   => __( 'Never Purchased',      'tsh-whatsapp-notify' ),
			'first_purchase'    => __( 'First Purchase',       'tsh-whatsapp-notify' ),
			'repeat_buyers'     => __( 'Repeat Buyers',        'tsh-whatsapp-notify' ),
			'by_product'        => __( 'By Product',           'tsh-whatsapp-notify' ),
			'by_category'       => __( 'By Category',          'tsh-whatsapp-notify' ),
			'by_country'        => __( 'By Country',           'tsh-whatsapp-notify' ),
			'by_state'          => __( 'By State',             'tsh-whatsapp-notify' ),
			'by_city'           => __( 'By City',              'tsh-whatsapp-notify' ),
			'by_payment_method' => __( 'By Payment Method',   'tsh-whatsapp-notify' ),
			'by_role'           => __( 'By Customer Role',     'tsh-whatsapp-notify' ),
			'by_registration'   => __( 'By Registration Date', 'tsh-whatsapp-notify' ),
			'by_last_purchase'  => __( 'By Last Purchase',     'tsh-whatsapp-notify' ),
			'by_lifetime_value' => __( 'By Lifetime Value',    'tsh-whatsapp-notify' ),
			'saved_segment'     => __( 'Saved Segment',        'tsh-whatsapp-notify' ),
			'custom'            => __( 'Custom Segment',       'tsh-whatsapp-notify' ),
		];
	}
}
