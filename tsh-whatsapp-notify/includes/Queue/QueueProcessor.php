<?php
/**
 * Queue processor — sends pending items via the WhatsApp API.
 *
 * @package TSH\WhatsAppNotify\Queue
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Queue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TSH\WhatsAppNotify\API\MetaCloudProvider;
use TSH\WhatsAppNotify\API\Exceptions\ApiException;
use TSH\WhatsAppNotify\Logger\Logger;
use TSH\WhatsAppNotify\Orders\OrderLogger;
use TSH\WhatsAppNotify\Orders\OrderQueueDispatcher;

/**
 * Class QueueProcessor
 *
 * Implements the queue processing loop that was stubbed in Phase 1/2.
 * Registered to fire on `tsh_wa_cron_process_queue` (every minute)
 * and `tsh_wa_cron_retry_failed` (every 5 minutes).
 *
 * Reads pending items from tsh_wa_queue in batches, sends each via
 * MetaCloudProvider, then updates the queue row and notification record.
 */
final class QueueProcessor {

	/** @var Queue */
	private Queue $queue;

	/** @var Logger */
	private Logger $logger;

	/** @var OrderQueueDispatcher */
	private OrderQueueDispatcher $dispatcher;

	/**
	 * Constructor — registers cron action hooks.
	 */
	public function __construct() {
		$this->queue      = new Queue();
		$this->logger     = new Logger();
		$this->dispatcher = new OrderQueueDispatcher();

		add_action( 'tsh_wa_cron_process_queue', [ $this, 'process_batch' ] );
		add_action( 'tsh_wa_cron_retry_failed',  [ $this, 'retry_failed'  ] );
	}

	// -------------------------------------------------------------------------
	// Cron callbacks
	// -------------------------------------------------------------------------

	/**
	 * Process a batch of pending queue items.
	 * Called every minute via WP-Cron.
	 */
	public function process_batch(): void {
		$settings   = get_option( 'tsh_wa_queue_settings', [] );
		$enabled    = ! empty( $settings['queue_enabled'] ) && '1' === (string) $settings['queue_enabled'];
		$batch_size = absint( $settings['batch_size'] ?? 10 );

		if ( ! $enabled || $batch_size < 1 ) {
			return;
		}

		$this->run( $batch_size );
	}

	/**
	 * Retry items that failed but have remaining attempts.
	 * Called every 5 minutes via WP-Cron.
	 */
	public function retry_failed(): void {
		$this->reset_retryable_failed_items();
	}

	// -------------------------------------------------------------------------
	// Core processor
	// -------------------------------------------------------------------------

	/**
	 * Process up to $batch_size pending items.
	 *
	 * @param int $batch_size Max items per run.
	 * @return int Items attempted.
	 */
	public function run( int $batch_size = 10 ): int {
		$items = $this->queue->get_pending_batch( $batch_size );

		if ( empty( $items ) ) {
			return 0;
		}

		$provider = new MetaCloudProvider();
		$count    = 0;

		foreach ( $items as $item ) {
			// Lock item immediately to prevent double-processing.
			if ( ! $this->queue->lock_item( (int) $item->id ) ) {
				continue;
			}

			$start = microtime( true );

			try {
				$result = $provider->sendMessage(
					(string) $item->phone,
					(string) $item->message
				);

				$latency_ms = (float) ( ( microtime( true ) - $start ) * 1000 );

				if ( ! empty( $result['success'] ) ) {
					$this->queue->mark_sent( (int) $item->id );
					$this->update_notification( (int) $item->id, 'sent', '' );
					$this->log_sent( $item, $latency_ms );
				} else {
					$error = $result['message'] ?? 'Unknown error';
					$this->handle_failure( $item, $error );
				}
			} catch ( ApiException $e ) {
				$this->handle_failure( $item, $e->getMessage(), $e->isRetryable() );
			} catch ( \Throwable $e ) {
				$this->handle_failure( $item, $e->getMessage(), false );
			}

			++$count;
		}

		return $count;
	}

	// -------------------------------------------------------------------------
	// Failure handling
	// -------------------------------------------------------------------------

	/**
	 * Handle a failed queue item — mark failed or reschedule for retry.
	 *
	 * @param object $item      Queue row object.
	 * @param string $error     Error message.
	 * @param bool   $retryable Whether the error is retryable.
	 */
	private function handle_failure( object $item, string $error, bool $retryable = true ): void {
		$attempts     = absint( $item->attempts );
		$max_attempts = absint( $item->max_attempts );

		if ( $retryable && $attempts < $max_attempts ) {
			// Reset to pending for retry on next cron run.
			$this->queue->reset_for_retry( (int) $item->id );
			$this->update_notification( (int) $item->id, 'queued', '' );
			$this->log_retry( $item, $error, $attempts );
		} else {
			// Permanently failed.
			$this->queue->mark_failed( (int) $item->id, $error );
			$this->update_notification( (int) $item->id, 'failed', $error );
			$this->log_failed( $item, $error, $attempts );
		}
	}

	/**
	 * Scan for failed items that still have remaining attempts and reset them.
	 */
	private function reset_retryable_failed_items(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'tsh_wa_queue';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"UPDATE `{$table}`
			 SET status = 'pending', error_message = NULL
			 WHERE status = 'failed'
			   AND attempts < max_attempts
			   AND attempts > 0"
		);
	}

	// -------------------------------------------------------------------------
	// Notification record sync
	// -------------------------------------------------------------------------

	/**
	 * Sync the notification table status with the queue outcome.
	 *
	 * @param int    $queue_id
	 * @param string $status
	 * @param string $error
	 */
	private function update_notification( int $queue_id, string $status, string $error ): void {
		$this->dispatcher->update_notification_status( $queue_id, $status, $error );
	}

	// -------------------------------------------------------------------------
	// Logging helpers
	// -------------------------------------------------------------------------

	/**
	 * Log a successful send.
	 *
	 * @param object $item
	 * @param float  $latency_ms
	 */
	private function log_sent( object $item, float $latency_ms ): void {
		$meta = json_decode( (string) ( $item->meta ?? '{}' ), true );
		$this->logger->success(
			sprintf( 'Queue item #%d sent. Latency: %.0f ms.', $item->id, $latency_ms ),
			[
				'queue_id'       => $item->id,
				'order_id'       => $item->order_id,
				'recipient_type' => $meta['recipient_type'] ?? 'unknown',
				'event_key'      => $meta['event_key'] ?? '',
				'latency_ms'     => $latency_ms,
			],
			'queue',
			$item->order_id ? (int) $item->order_id : null,
			$item->phone
		);

		if ( $item->order_id ) {
			$order_logger = new OrderLogger();
			$order_logger->add_sent_note(
				(int) $item->order_id,
				$meta['recipient_type'] ?? 'unknown',
				$meta['event_key'] ?? '',
				$latency_ms
			);
		}
	}

	/**
	 * Log a final failure (max retries exhausted).
	 *
	 * @param object $item
	 * @param string $error
	 * @param int    $attempts
	 */
	private function log_failed( object $item, string $error, int $attempts ): void {
		$meta = json_decode( (string) ( $item->meta ?? '{}' ), true );
		$this->logger->error(
			sprintf( 'Queue item #%d failed permanently after %d attempt(s): %s', $item->id, $attempts, $error ),
			[
				'queue_id'    => $item->id,
				'order_id'    => $item->order_id,
				'event_key'   => $meta['event_key'] ?? '',
				'error'       => $error,
				'attempts'    => $attempts,
			],
			'queue',
			$item->order_id ? (int) $item->order_id : null,
			$item->phone
		);

		if ( $item->order_id ) {
			$order_logger = new OrderLogger();
			$order_logger->add_failed_note(
				(int) $item->order_id,
				$meta['recipient_type'] ?? 'unknown',
				$meta['event_key'] ?? '',
				$error,
				$attempts
			);
		}
	}

	/**
	 * Log a scheduled retry.
	 *
	 * @param object $item
	 * @param string $error
	 * @param int    $attempts
	 */
	private function log_retry( object $item, string $error, int $attempts ): void {
		$meta = json_decode( (string) ( $item->meta ?? '{}' ), true );
		$this->logger->log(
			Logger::LEVEL_WARNING,
			sprintf( 'Queue item #%d failed (attempt %d/%d) — retry scheduled. Error: %s', $item->id, $attempts, $item->max_attempts, $error ),
			[
				'queue_id' => $item->id,
				'order_id' => $item->order_id,
				'event_key'=> $meta['event_key'] ?? '',
				'error'    => $error,
				'attempt'  => $attempts,
			],
			'queue',
			$item->order_id ? (int) $item->order_id : null
		);

		if ( $item->order_id ) {
			$order_logger = new OrderLogger();
			$order_logger->add_retry_note(
				(int) $item->order_id,
				$meta['event_key'] ?? '',
				$attempts + 1
			);
		}
	}
}
