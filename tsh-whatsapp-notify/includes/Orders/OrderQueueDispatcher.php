<?php
/**
 * Order queue dispatcher with duplicate protection.
 *
 * @package TSH\WhatsAppNotify\Orders
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Orders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TSH\WhatsAppNotify\Queue\Queue;

/**
 * Class OrderQueueDispatcher
 *
 * Single responsibility: push a notification into the queue and record
 * it in the `tsh_wa_notifications` table for duplicate detection.
 *
 * Returns:
 *  - int   > 0  → successfully queued; value is the queue row ID.
 *  - false      → duplicate detected; skipped (not an error).
 *  - null       → queue insertion failed (error).
 */
final class OrderQueueDispatcher {

	/** @var Queue */
	private Queue $queue;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->queue = new Queue();
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Dispatch a notification to the queue.
	 *
	 * @param int                  $order_id       WC order ID.
	 * @param string               $event_key      Plugin event key.
	 * @param string               $phone          E.164 recipient phone.
	 * @param string               $message        Rendered message body.
	 * @param string               $recipient_type 'customer' | 'admin'.
	 * @param array<string, mixed> $options {
	 *   @type string $template_slug     Template slug used.
	 *   @type string $recipient_name    Admin recipient name.
	 *   @type int    $delay_seconds     Seconds before dispatch (0 = immediate).
	 *   @type bool   $queue_immediately True to skip delay.
	 *   @type int    $priority          Queue priority 1–10 (default 5).
	 *   @type bool   $force             Skip duplicate check.
	 * }
	 * @return int|false|null
	 */
	public function dispatch(
		int $order_id,
		string $event_key,
		string $phone,
		string $message,
		string $recipient_type,
		array $options = []
	): int|false|null {
		$template_slug  = sanitize_key( $options['template_slug']  ?? '' );
		$recipient_name = sanitize_text_field( $options['recipient_name'] ?? '' );
		$delay_seconds  = absint( $options['delay_seconds'] ?? 0 );
		$force          = ! empty( $options['force'] );
		$priority       = min( max( absint( $options['priority'] ?? 5 ), 1 ), 10 );

		// Duplicate detection.
		if ( ! $force && $this->is_duplicate( $order_id, $event_key, $phone ) ) {
			return false;
		}

		// Calculate scheduled time.
		$scheduled_at = ( ! $force && $delay_seconds > 0 )
			? gmdate( 'Y-m-d H:i:s', time() + $delay_seconds )
			: current_time( 'mysql' );

		// Build meta for the queue item.
		$meta = [
			'event_key'      => $event_key,
			'recipient_type' => $recipient_type,
			'recipient_name' => $recipient_name,
			'template_slug'  => $template_slug,
		];

		// Add to queue.
		$queue_id = $this->queue->add( [
			'phone'        => $phone,
			'message'      => $message,
			'order_id'     => $order_id,
			'priority'     => $priority,
			'scheduled_at' => $scheduled_at,
			'meta'         => $meta,
		] );

		if ( ! $queue_id ) {
			return null;
		}

		// Record in notifications table.
		$this->record_notification(
			$order_id,
			$event_key,
			$phone,
			$recipient_type,
			$recipient_name,
			$template_slug,
			$message,
			$queue_id
		);

		return $queue_id;
	}

	/**
	 * Check whether an identical notification has already been successfully sent.
	 *
	 * Duplicate = same order_id + event_key + phone + status IN (queued, sent).
	 *
	 * @param int    $order_id
	 * @param string $event_key
	 * @param string $phone
	 * @return bool
	 */
	public function is_duplicate( int $order_id, string $event_key, string $phone ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'tsh_wa_notifications';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table}`
				 WHERE order_id = %d
				   AND event = %s
				   AND recipient_phone = %s
				   AND status IN ('queued','sent')",
				$order_id,
				$event_key,
				$phone
			)
		);

		return (int) $count > 0;
	}

	/**
	 * Update the notification status after queue processing.
	 *
	 * @param int    $queue_id
	 * @param string $status    'sent' | 'failed' | 'skipped'.
	 * @param string $error     Error message (on failure).
	 */
	public function update_notification_status( int $queue_id, string $status, string $error = '' ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'tsh_wa_notifications';

		$wpdb->update(
			$table,
			[
				'status'        => sanitize_key( $status ),
				'error_message' => $error ? sanitize_textarea_field( $error ) : null,
			],
			[ 'queue_id' => $queue_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Return recent notification records for an order.
	 *
	 * @param int $order_id
	 * @param int $limit
	 * @return array<int, object>
	 */
	public function get_order_notifications( int $order_id, int $limit = 50 ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'tsh_wa_notifications';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` WHERE order_id = %d ORDER BY created_at DESC LIMIT %d",
				$order_id,
				$limit
			)
		) ?: [];
	}

	// -------------------------------------------------------------------------
	// Internal
	// -------------------------------------------------------------------------

	/**
	 * Write a record to the tsh_wa_notifications table.
	 *
	 * @param int    $order_id
	 * @param string $event_key
	 * @param string $phone
	 * @param string $recipient_type
	 * @param string $recipient_name
	 * @param string $template_slug
	 * @param string $message
	 * @param int    $queue_id
	 */
	private function record_notification(
		int $order_id,
		string $event_key,
		string $phone,
		string $recipient_type,
		string $recipient_name,
		string $template_slug,
		string $message,
		int $queue_id
	): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'tsh_wa_notifications',
			[
				'order_id'       => $order_id,
				'event'          => $event_key,
				'recipient_phone'=> $phone,
				'recipient_type' => $recipient_type,
				'recipient_name' => $recipient_name,
				'template_slug'  => $template_slug ?: null,
				'message_hash'   => hash( 'sha256', $message ),
				'queue_id'       => $queue_id,
				'status'         => 'queued',
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
		);
	}
}
