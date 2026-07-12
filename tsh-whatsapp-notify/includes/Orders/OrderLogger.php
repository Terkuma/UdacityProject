<?php
/**
 * Order-aware logging service.
 *
 * @package TSH\WhatsAppNotify\Orders
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Orders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TSH\WhatsAppNotify\Logger\Logger;
use TSH\WhatsAppNotify\Orders\OrderStatusListener;

/**
 * Class OrderLogger
 *
 * Wraps the core Logger service with order-specific context injection
 * and adds events to the WooCommerce Order Notes timeline.
 *
 * Single responsibility: create log entries that are traceable back
 * to a specific order and event.
 */
final class OrderLogger {

	/** @var Logger */
	private Logger $logger;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->logger = new Logger();
	}

	// -------------------------------------------------------------------------
	// Logging
	// -------------------------------------------------------------------------

	/**
	 * Log an order-related event.
	 *
	 * @param string               $event_key  Plugin event key.
	 * @param int                  $order_id   WooCommerce order ID.
	 * @param string               $level      Logger::LEVEL_* constant.
	 * @param string               $message    Human-readable log message.
	 * @param array<string, mixed> $context    Optional extra context.
	 */
	public function log_event(
		string $event_key,
		int $order_id,
		string $level,
		string $message,
		array $context = []
	): void {
		$context = array_merge( $context, [
			'order_id'   => $order_id,
			'event_key'  => $event_key,
			'event_label'=> OrderStatusListener::event_label( $event_key ),
		] );

		$this->logger->log(
			$level,
			$message,
			$context,
			'orders',
			$order_id > 0 ? $order_id : null
		);
	}

	/**
	 * Log a successful dispatch.
	 *
	 * @param string $event_key
	 * @param int    $order_id
	 * @param string $recipient_type 'customer' | 'admin'.
	 * @param int    $queue_id
	 */
	public function log_queued( string $event_key, int $order_id, string $recipient_type, int $queue_id ): void {
		$this->log_event(
			$event_key,
			$order_id,
			Logger::LEVEL_INFO,
			sprintf( 'WhatsApp queued — %s notification. Queue ID: %d.', ucfirst( $recipient_type ), $queue_id ),
			[ 'queue_id' => $queue_id, 'recipient_type' => $recipient_type ]
		);
	}

	/**
	 * Log a send success after queue processing.
	 *
	 * @param string $event_key
	 * @param int    $order_id
	 * @param string $recipient_type
	 * @param float  $latency_ms
	 */
	public function log_sent( string $event_key, int $order_id, string $recipient_type, float $latency_ms = 0.0 ): void {
		$this->log_event(
			$event_key,
			$order_id,
			Logger::LEVEL_SUCCESS,
			sprintf( 'WhatsApp sent — %s notification. Latency: %.0f ms.', ucfirst( $recipient_type ), $latency_ms ),
			[ 'latency_ms' => $latency_ms, 'recipient_type' => $recipient_type ]
		);
	}

	/**
	 * Log a send failure.
	 *
	 * @param string $event_key
	 * @param int    $order_id
	 * @param string $error_message
	 * @param int    $retry_count
	 */
	public function log_failed( string $event_key, int $order_id, string $error_message, int $retry_count = 0 ): void {
		$this->log_event(
			$event_key,
			$order_id,
			Logger::LEVEL_ERROR,
			sprintf( 'WhatsApp failed: %s (attempts: %d)', $error_message, $retry_count + 1 ),
			[ 'error' => $error_message, 'retry_count' => $retry_count ]
		);
	}

	// -------------------------------------------------------------------------
	// WooCommerce Order Notes
	// -------------------------------------------------------------------------

	/**
	 * Append a note to the WooCommerce order timeline.
	 *
	 * Notes are internal (not sent to the customer) unless $customer_note = true.
	 *
	 * @param int    $order_id
	 * @param string $note           Plain-text note body.
	 * @param bool   $customer_note  True to make it visible to the customer.
	 */
	public function add_order_note( int $order_id, string $note, bool $customer_note = false ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		// Prepend plugin identifier.
		$full_note = '[TSH WA] ' . $note;

		$order->add_order_note( $full_note, (int) $customer_note, true );
	}

	/**
	 * Add a delivery confirmation note to the order.
	 *
	 * @param int    $order_id
	 * @param string $recipient_type 'customer' | 'admin'.
	 * @param string $event_key
	 * @param float  $latency_ms
	 */
	public function add_sent_note( int $order_id, string $recipient_type, string $event_key, float $latency_ms = 0.0 ): void {
		$this->add_order_note(
			$order_id,
			sprintf(
				/* translators: 1: recipient type, 2: event label, 3: latency */
				__( 'WhatsApp sent to %1$s — Event: %2$s. API: %.0f ms.', 'tsh-whatsapp-notify' ),
				ucfirst( $recipient_type ),
				OrderStatusListener::event_label( $event_key ),
				$latency_ms
			)
		);
	}

	/**
	 * Add a failure note to the order.
	 *
	 * @param int    $order_id
	 * @param string $recipient_type
	 * @param string $event_key
	 * @param string $error
	 * @param int    $retry_count
	 */
	public function add_failed_note( int $order_id, string $recipient_type, string $event_key, string $error, int $retry_count = 0 ): void {
		$retry_text = $retry_count < 3 ? __( 'Retry scheduled.', 'tsh-whatsapp-notify' ) : __( 'Max retries reached.', 'tsh-whatsapp-notify' );
		$this->add_order_note(
			$order_id,
			sprintf(
				/* translators: 1: recipient type, 2: event label, 3: error, 4: retry info */
				__( 'WhatsApp FAILED → %1$s — Event: %2$s. Error: %3$s. %4$s', 'tsh-whatsapp-notify' ),
				ucfirst( $recipient_type ),
				OrderStatusListener::event_label( $event_key ),
				$error,
				$retry_text
			)
		);
	}

	/**
	 * Add a retry-scheduled note.
	 *
	 * @param int    $order_id
	 * @param string $event_key
	 * @param int    $retry_number
	 */
	public function add_retry_note( int $order_id, string $event_key, int $retry_number ): void {
		$this->add_order_note(
			$order_id,
			sprintf(
				/* translators: 1: retry attempt number, 2: event label */
				__( 'WhatsApp retry #%1$d scheduled — Event: %2$s.', 'tsh-whatsapp-notify' ),
				$retry_number,
				OrderStatusListener::event_label( $event_key )
			)
		);
	}
}
