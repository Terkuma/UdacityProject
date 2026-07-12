<?php
/**
 * Delivery tracker — records every delivery status transition for a queue item.
 *
 * Writes to {prefix}tsh_wa_delivery_events and optionally appends a WC Order Note
 * for order-linked messages.  Provides a complete per-message audit trail for
 * the Phase 4 delivery timeline UI.
 *
 * Delivery statuses (mapped from Meta webhook + local processing states):
 *   queued     → item accepted into the queue
 *   sending    → API call in progress
 *   sent       → API returned 200 + message_id (Meta accepted the message)
 *   delivered  → Meta webhook: status = delivered
 *   read       → Meta webhook: status = read
 *   failed     → API returned error or max retries exhausted
 *   expired    → item exceeded its scheduled window without being sent
 *   retrying   → failed attempt; retry has been scheduled
 *
 * @package TSH\WhatsAppNotify\Queue
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Queue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class DeliveryTracker
 */
final class DeliveryTracker {

	// -------------------------------------------------------------------------
	// Status constants
	// -------------------------------------------------------------------------

	public const STATUS_QUEUED    = 'queued';
	public const STATUS_SENDING   = 'sending';
	public const STATUS_SENT      = 'sent';
	public const STATUS_DELIVERED = 'delivered';
	public const STATUS_READ      = 'read';
	public const STATUS_FAILED    = 'failed';
	public const STATUS_EXPIRED   = 'expired';
	public const STATUS_RETRYING  = 'retrying';

	/** @var array<string> All valid delivery statuses, ordered by lifecycle. */
	public const ALL_STATUSES = [
		self::STATUS_QUEUED,
		self::STATUS_SENDING,
		self::STATUS_SENT,
		self::STATUS_DELIVERED,
		self::STATUS_READ,
		self::STATUS_RETRYING,
		self::STATUS_FAILED,
		self::STATUS_EXPIRED,
	];

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Record a delivery status event for a queue item.
	 *
	 * @param int                  $queue_id      Queue item ID.
	 * @param string               $status        One of the STATUS_* constants.
	 * @param array<string, mixed> $response_data Optional: raw API response or context data.
	 * @param int|null             $order_id      If set, append an order note.
	 * @param string               $phone         Masked phone for order note (optional).
	 */
	public function record(
		int $queue_id,
		string $status,
		array $response_data = [],
		?int $order_id = null,
		string $phone = ''
	): void {
		global $wpdb;

		$table = $wpdb->prefix . 'tsh_wa_delivery_events';

		$wpdb->insert(
			$table,
			[
				'queue_id'      => $queue_id,
				'status'        => $status,
				'response_data' => ! empty( $response_data ) ? wp_json_encode( $response_data ) : null,
				'created_at'    => current_time( 'mysql' ),
			],
			[ '%d', '%s', '%s', '%s' ]
		);

		if ( $order_id && $order_id > 0 ) {
			$this->add_order_note( $order_id, $queue_id, $status, $phone );
		}
	}

	/**
	 * Return the full delivery timeline for a queue item, oldest first.
	 *
	 * @param int $queue_id Queue item ID.
	 * @return array<int, object>
	 */
	public function get_timeline( int $queue_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'tsh_wa_delivery_events';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE queue_id = %d ORDER BY created_at ASC, id ASC",
				$queue_id
			)
		) ?: [];
	}

	/**
	 * Return the most recent delivery status event for a queue item.
	 *
	 * @param int $queue_id
	 * @return object|null
	 */
	public function get_latest( int $queue_id ): ?object {
		global $wpdb;

		$table = $wpdb->prefix . 'tsh_wa_delivery_events';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE queue_id = %d ORDER BY id DESC LIMIT 1",
				$queue_id
			)
		) ?: null;
	}

	/**
	 * Delete all delivery events for a given queue item (called on item removal).
	 *
	 * @param int $queue_id
	 * @return int Rows deleted.
	 */
	public function delete_for_item( int $queue_id ): int {
		global $wpdb;

		return (int) $wpdb->delete(
			$wpdb->prefix . 'tsh_wa_delivery_events',
			[ 'queue_id' => $queue_id ],
			[ '%d' ]
		);
	}

	/**
	 * Prune delivery events older than $days days.
	 *
	 * @param int $days
	 * @return int Rows deleted.
	 */
	public function prune( int $days ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'tsh_wa_delivery_events';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$table}` WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
				$days
			)
		);
	}

	// -------------------------------------------------------------------------
	// Status helpers
	// -------------------------------------------------------------------------

	/**
	 * Return a human-readable label for a delivery status.
	 *
	 * @param string $status
	 * @return string
	 */
	public static function label( string $status ): string {
		$labels = [
			self::STATUS_QUEUED    => __( 'Queued',     'tsh-whatsapp-notify' ),
			self::STATUS_SENDING   => __( 'Sending',    'tsh-whatsapp-notify' ),
			self::STATUS_SENT      => __( 'Sent',       'tsh-whatsapp-notify' ),
			self::STATUS_DELIVERED => __( 'Delivered',  'tsh-whatsapp-notify' ),
			self::STATUS_READ      => __( 'Read',       'tsh-whatsapp-notify' ),
			self::STATUS_FAILED    => __( 'Failed',     'tsh-whatsapp-notify' ),
			self::STATUS_EXPIRED   => __( 'Expired',    'tsh-whatsapp-notify' ),
			self::STATUS_RETRYING  => __( 'Retrying',   'tsh-whatsapp-notify' ),
		];

		return $labels[ $status ] ?? ucfirst( $status );
	}

	/**
	 * Return a CSS colour class token for a delivery status.
	 *
	 * @param string $status
	 * @return string
	 */
	public static function color( string $status ): string {
		$colors = [
			self::STATUS_QUEUED    => 'blue',
			self::STATUS_SENDING   => 'orange',
			self::STATUS_SENT      => 'green',
			self::STATUS_DELIVERED => 'teal',
			self::STATUS_READ      => 'purple',
			self::STATUS_RETRYING  => 'yellow',
			self::STATUS_FAILED    => 'red',
			self::STATUS_EXPIRED   => 'grey',
		];

		return $colors[ $status ] ?? 'grey';
	}

	// -------------------------------------------------------------------------
	// Order note helper
	// -------------------------------------------------------------------------

	/**
	 * Append a WooCommerce order note reflecting the new delivery status.
	 *
	 * @param int    $order_id  WooCommerce order ID.
	 * @param int    $queue_id  Queue item ID.
	 * @param string $status    Delivery status.
	 * @param string $phone     Optional masked phone number for context.
	 */
	private function add_order_note(
		int $order_id,
		int $queue_id,
		string $status,
		string $phone = ''
	): void {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Abstract_Order ) {
			return;
		}

		$phone_suffix = $phone ? sprintf( ' (%s)', esc_html( $phone ) ) : '';

		$notes = [
			self::STATUS_SENDING  => sprintf(
				/* translators: 1: queue item ID */
				__( '[TSH WA] Sending WhatsApp message (Queue #%1$d)…', 'tsh-whatsapp-notify' ),
				$queue_id
			),
			self::STATUS_SENT     => sprintf(
				/* translators: 1: queue item ID, 2: phone suffix */
				__( '[TSH WA] WhatsApp message sent successfully (Queue #%1$d)%2$s.', 'tsh-whatsapp-notify' ),
				$queue_id,
				$phone_suffix
			),
			self::STATUS_DELIVERED => sprintf(
				/* translators: 1: queue item ID */
				__( '[TSH WA] WhatsApp message delivered to device (Queue #%1$d).', 'tsh-whatsapp-notify' ),
				$queue_id
			),
			self::STATUS_READ     => sprintf(
				/* translators: 1: queue item ID */
				__( '[TSH WA] WhatsApp message read by customer (Queue #%1$d).', 'tsh-whatsapp-notify' ),
				$queue_id
			),
			self::STATUS_RETRYING => sprintf(
				/* translators: 1: queue item ID */
				__( '[TSH WA] WhatsApp message send failed — retry scheduled (Queue #%1$d).', 'tsh-whatsapp-notify' ),
				$queue_id
			),
			self::STATUS_FAILED   => sprintf(
				/* translators: 1: queue item ID */
				__( '[TSH WA] WhatsApp message permanently failed (Queue #%1$d).', 'tsh-whatsapp-notify' ),
				$queue_id
			),
			self::STATUS_EXPIRED  => sprintf(
				/* translators: 1: queue item ID */
				__( '[TSH WA] WhatsApp message expired without being sent (Queue #%1$d).', 'tsh-whatsapp-notify' ),
				$queue_id
			),
		];

		if ( isset( $notes[ $status ] ) ) {
			$order->add_order_note( $notes[ $status ] );
		}
	}
}
