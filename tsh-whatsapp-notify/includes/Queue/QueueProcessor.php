<?php
/**
 * Queue processor — Phase 4 production-grade delivery engine.
 *
 * Improvements over Phase 3:
 *  - Worker lock (WorkerLock) prevents two cron invocations running in parallel.
 *  - Rate limiter (RateLimiter) respects the Meta Cloud API throughput cap.
 *  - Exponential backoff (RetryEngine) for transient failures.
 *  - Delivery tracker (DeliveryTracker) writes a per-item status timeline.
 *  - Stuck-item recovery releases orphaned 'processing' rows at every batch start.
 *  - Queue-pause check honours the admin's manual pause toggle.
 *  - Worker log records every batch run to {prefix}tsh_wa_worker_log.
 *  - Expire handler moves stale pending items to 'cancelled/expired'.
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
 */
final class QueueProcessor {

	/** @var Queue */
	private Queue $queue;

	/** @var Logger */
	private Logger $logger;

	/** @var OrderQueueDispatcher */
	private OrderQueueDispatcher $dispatcher;

	/** @var WorkerLock */
	private WorkerLock $lock;

	/** @var RateLimiter */
	private RateLimiter $rate_limiter;

	/** @var RetryEngine */
	private RetryEngine $retry_engine;

	/** @var DeliveryTracker */
	private DeliveryTracker $tracker;

	/**
	 * Constructor — registers cron action hooks.
	 */
	public function __construct() {
		$this->queue        = new Queue();
		$this->logger       = new Logger();
		$this->dispatcher   = new OrderQueueDispatcher();
		$this->lock         = new WorkerLock();
		$this->rate_limiter = new RateLimiter();
		$this->retry_engine = new RetryEngine();
		$this->tracker      = new DeliveryTracker();

		add_action( 'tsh_wa_cron_process_queue',  [ $this, 'process_batch'     ] );
		add_action( 'tsh_wa_cron_retry_failed',   [ $this, 'retry_failed'      ] );
		add_action( 'tsh_wa_cron_expire_queue',   [ $this, 'expire_stale_items'] );
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

		// Respect the admin's manual pause.
		if ( get_option( 'tsh_wa_queue_paused', false ) ) {
			return;
		}

		$this->run( $batch_size );
	}

	/**
	 * Reset items that failed but still have remaining attempts (legacy + pre-Phase-4 items).
	 * Called every 5 minutes via WP-Cron.
	 */
	public function retry_failed(): void {
		$this->reset_legacy_failed_items();
	}

	/**
	 * Mark stale pending items (never picked up) as expired.
	 * Called hourly via WP-Cron.
	 */
	public function expire_stale_items(): void {
		/**
		 * Filter: tsh_wa_queue_expiry_minutes
		 * Items pending for longer than this without being picked up are expired.
		 * Default: 1440 (24 hours).
		 *
		 * @param int $minutes
		 */
		$expiry_minutes = (int) apply_filters( 'tsh_wa_queue_expiry_minutes', 1440 );
		$items          = $this->queue->get_expired_pending_items( $expiry_minutes );

		if ( empty( $items ) ) {
			return;
		}

		foreach ( $items as $item ) {
			$this->queue->mark_expired( (int) $item->id );
			$this->tracker->record(
				(int) $item->id,
				DeliveryTracker::STATUS_EXPIRED,
				[ 'expiry_minutes' => $expiry_minutes ],
				$item->order_id ? (int) $item->order_id : null
			);
		}

		$this->logger->warning(
			sprintf(
				/* translators: %d: number of expired items */
				__( 'Expired %d stale queue item(s) pending for over %d minutes.', 'tsh-whatsapp-notify' ),
				count( $items ),
				$expiry_minutes
			),
			[ 'count' => count( $items ), 'expiry_minutes' => $expiry_minutes ],
			'queue'
		);
	}

	// -------------------------------------------------------------------------
	// Core processor
	// -------------------------------------------------------------------------

	/**
	 * Process up to $batch_size pending items with full Phase 4 engine features.
	 *
	 * @param int $batch_size Max items per run.
	 * @return int Items processed in this run.
	 */
	public function run( int $batch_size = 10 ): int {
		// --- Acquire worker lock -----------------------------------------.
		$worker_id = $this->lock->acquire();
		if ( ! $worker_id ) {
			$this->logger->debug(
				__( 'Queue batch skipped — another worker is already running.', 'tsh-whatsapp-notify' ),
				[ 'holder' => $this->lock->current_holder() ],
				'queue'
			);
			return 0;
		}

		$started_at = current_time( 'mysql' );
		$wall_start = microtime( true );

		$stats = [
			'processed'    => 0,
			'sent'         => 0,
			'failed'       => 0,
			'retried'      => 0,
			'skipped'      => 0,
			'rate_limited' => false,
		];

		try {
			// --- Safety: free orphaned stuck items -----------------------.
			$released = $this->queue->release_stuck_items( 10 );
			if ( $released > 0 ) {
				$this->logger->warning(
					sprintf(
						/* translators: %d: count of released items */
						__( 'Released %d item(s) stuck in "processing" from a previous crashed worker.', 'tsh-whatsapp-notify' ),
						$released
					),
					[ 'count' => $released ],
					'queue'
				);
			}

			// --- Fetch batch ------------------------------------------.
			$items = $this->queue->get_pending_batch( $batch_size );

			if ( empty( $items ) ) {
				return 0;
			}

			$provider = new MetaCloudProvider();

			foreach ( $items as $item ) {
				// Rate limit check BEFORE each message.
				if ( ! $this->rate_limiter->allow() ) {
					$stats['rate_limited'] = true;
					$this->logger->info(
						sprintf(
							/* translators: %d: remaining slots; %d: max per minute */
							__( 'Queue batch paused — rate limit reached (%1$d/%2$d msg/min).', 'tsh-whatsapp-notify' ),
							$this->rate_limiter->get_count(),
							$this->rate_limiter->get_max()
						),
						[ 'count' => $this->rate_limiter->get_count(), 'max' => $this->rate_limiter->get_max() ],
						'queue'
					);
					break;
				}

				// Atomic lock: SET status='processing', attempts++ (prevents double-send).
				if ( ! $this->queue->lock_item( (int) $item->id ) ) {
					++$stats['skipped'];
					continue;
				}

				// Stamp worker ID for traceability.
				$this->queue->set_worker_id( (int) $item->id, $worker_id );

				// Record "sending" delivery event.
				$this->tracker->record(
					(int) $item->id,
					DeliveryTracker::STATUS_SENDING,
					[],
					$item->order_id ? (int) $item->order_id : null
				);
				$this->queue->update_delivery_status( (int) $item->id, DeliveryTracker::STATUS_SENDING );

				$send_start = microtime( true );

				try {
					$result     = $provider->sendMessage( (string) $item->phone, (string) $item->message );
					$latency_ms = (float) ( ( microtime( true ) - $send_start ) * 1000 );

					if ( ! empty( $result['success'] ) ) {
						$message_id = (string) ( $result['message_id'] ?? '' );

						$this->queue->mark_sent( (int) $item->id );
						$this->queue->update_delivery_status(
							(int) $item->id,
							DeliveryTracker::STATUS_SENT,
							$message_id,
							$latency_ms
						);
						$this->rate_limiter->consume();
						$this->tracker->record(
							(int) $item->id,
							DeliveryTracker::STATUS_SENT,
							[ 'message_id' => $message_id, 'latency_ms' => $latency_ms ],
							$item->order_id ? (int) $item->order_id : null,
							(string) $item->phone
						);
						$this->update_notification( (int) $item->id, 'sent', '' );
						$this->log_sent( $item, $latency_ms, $message_id );
						++$stats['sent'];
					} else {
						$error       = $result['message']     ?? 'Unknown error';
						$error_code  = (string) ( $result['error_code']  ?? '' );
						$http_status = (int)    ( $result['http_status'] ?? 0 );
						$retryable   = $this->retry_engine->is_retryable( $error_code, $http_status );

						$this->rate_limiter->consume();
						$is_retry = $this->handle_failure( $item, $error, $retryable, $latency_ms );
						$is_retry ? ++$stats['retried'] : ++$stats['failed'];
					}
				} catch ( ApiException $e ) {
					$latency_ms = (float) ( ( microtime( true ) - $send_start ) * 1000 );
					$retryable  = $this->retry_engine->is_retryable(
						$e->get_meta_error_code(),
						$e->get_http_status()
					);
					$is_retry = $this->handle_failure( $item, $e->getMessage(), $retryable, $latency_ms );
					$is_retry ? ++$stats['retried'] : ++$stats['failed'];
				} catch ( \Throwable $e ) {
					$latency_ms = (float) ( ( microtime( true ) - $send_start ) * 1000 );
					$this->handle_failure( $item, $e->getMessage(), false, $latency_ms );
					++$stats['failed'];
				}

				++$stats['processed'];
			}
		} finally {
			$this->lock->release();
			$elapsed_ms = round( ( microtime( true ) - $wall_start ) * 1000, 3 );
			$this->write_worker_log( $worker_id, $started_at, $batch_size, $stats, $elapsed_ms );
		}

		return $stats['processed'];
	}

	// -------------------------------------------------------------------------
	// Failure handling
	// -------------------------------------------------------------------------

	/**
	 * Handle a failed queue item.
	 * Returns true if the item was scheduled for retry, false if permanently failed.
	 *
	 * @param object $item
	 * @param string $error
	 * @param bool   $retryable
	 * @param float  $latency_ms
	 * @return bool True = retry scheduled; false = permanent failure.
	 */
	private function handle_failure(
		object $item,
		string $error,
		bool $retryable,
		float $latency_ms = 0.0
	): bool {
		$attempts     = absint( $item->attempts );
		$max_attempts = absint( $item->max_attempts );

		if ( $retryable && $attempts < $max_attempts ) {
			$retry_at = $this->retry_engine->next_retry_at( $attempts );

			$this->queue->mark_retrying( (int) $item->id, $retry_at, $error );
			$this->queue->update_delivery_status(
				(int) $item->id,
				DeliveryTracker::STATUS_RETRYING,
				'',
				$latency_ms
			);
			$this->tracker->record(
				(int) $item->id,
				DeliveryTracker::STATUS_RETRYING,
				[
					'error'      => $error,
					'attempt'    => $attempts,
					'retry_at'   => $retry_at,
					'latency_ms' => $latency_ms,
				],
				$item->order_id ? (int) $item->order_id : null
			);
			$this->update_notification( (int) $item->id, 'queued', '' );
			$this->log_retry( $item, $error, $attempts, $retry_at );

			return true;
		}

		// Permanently failed — enters the dead letter queue.
		$this->queue->mark_failed( (int) $item->id, $error );
		$this->queue->update_delivery_status(
			(int) $item->id,
			DeliveryTracker::STATUS_FAILED,
			'',
			$latency_ms
		);
		$this->tracker->record(
			(int) $item->id,
			DeliveryTracker::STATUS_FAILED,
			[
				'error'      => $error,
				'attempts'   => $attempts,
				'latency_ms' => $latency_ms,
			],
			$item->order_id ? (int) $item->order_id : null
		);
		$this->update_notification( (int) $item->id, 'failed', $error );
		$this->log_failed( $item, $error, $attempts );

		return false;
	}

	/**
	 * Reset pre-Phase-4 failed items that still have remaining attempts
	 * and were not scheduled via retry_after (legacy items have NULL retry_after).
	 */
	private function reset_legacy_failed_items(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'tsh_wa_queue';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"UPDATE `{$table}`
			 SET status = 'pending', error_message = NULL
			 WHERE status = 'failed'
			   AND attempts < max_attempts
			   AND attempts > 0
			   AND retry_after IS NULL"
		);
	}

	// -------------------------------------------------------------------------
	// Notification sync
	// -------------------------------------------------------------------------

	/**
	 * Sync the tsh_wa_notifications table status with the queue outcome.
	 */
	private function update_notification( int $queue_id, string $status, string $error ): void {
		$this->dispatcher->update_notification_status( $queue_id, $status, $error );
	}

	// -------------------------------------------------------------------------
	// Worker log
	// -------------------------------------------------------------------------

	/**
	 * Insert a row into tsh_wa_worker_log for this batch run.
	 *
	 * @param string               $worker_id  Worker ID.
	 * @param string               $started_at MySQL datetime when batch began.
	 * @param int                  $batch_size Configured batch size.
	 * @param array<string, mixed> $stats      Counters from the run.
	 * @param float                $elapsed_ms Wall time in milliseconds.
	 */
	private function write_worker_log(
		string $worker_id,
		string $started_at,
		int $batch_size,
		array $stats,
		float $elapsed_ms
	): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'tsh_wa_worker_log',
			[
				'worker_id'       => $worker_id,
				'started_at'      => $started_at,
				'finished_at'     => current_time( 'mysql' ),
				'batch_size'      => $batch_size,
				'items_processed' => $stats['processed'],
				'items_sent'      => $stats['sent'],
				'items_failed'    => $stats['failed'],
				'items_retried'   => $stats['retried'],
				'items_skipped'   => $stats['skipped'],
				'duration_ms'     => $elapsed_ms,
				'rate_limited'    => $stats['rate_limited'] ? 1 : 0,
			],
			[ '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%f', '%d' ]
		);
	}

	// -------------------------------------------------------------------------
	// Logging helpers
	// -------------------------------------------------------------------------

	/**
	 * Log a successful send.
	 *
	 * @param object $item
	 * @param float  $latency_ms
	 * @param string $message_id WhatsApp message ID.
	 */
	private function log_sent( object $item, float $latency_ms, string $message_id = '' ): void {
		$meta = json_decode( (string) ( $item->meta ?? '{}' ), true );
		$this->logger->success(
			sprintf(
				/* translators: 1: queue item ID; 2: API latency */
				__( 'Queue item #%1$d sent. Latency: %2$.0f ms.', 'tsh-whatsapp-notify' ),
				$item->id,
				$latency_ms
			),
			[
				'queue_id'       => $item->id,
				'order_id'       => $item->order_id,
				'message_id'     => $message_id,
				'recipient_type' => $meta['recipient_type'] ?? 'unknown',
				'event_key'      => $meta['event_key']      ?? '',
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
				$meta['event_key']      ?? '',
				$latency_ms
			);
		}
	}

	/**
	 * Log a permanent failure.
	 *
	 * @param object $item
	 * @param string $error
	 * @param int    $attempts
	 */
	private function log_failed( object $item, string $error, int $attempts ): void {
		$meta = json_decode( (string) ( $item->meta ?? '{}' ), true );
		$this->logger->error(
			sprintf(
				/* translators: 1: queue item ID; 2: attempts; 3: error message */
				__( 'Queue item #%1$d permanently failed after %2$d attempt(s): %3$s', 'tsh-whatsapp-notify' ),
				$item->id,
				$attempts,
				$error
			),
			[
				'queue_id'  => $item->id,
				'order_id'  => $item->order_id,
				'event_key' => $meta['event_key'] ?? '',
				'error'     => $error,
				'attempts'  => $attempts,
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
				$meta['event_key']      ?? '',
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
	 * @param string $retry_at  MySQL datetime for next attempt.
	 */
	private function log_retry(
		object $item,
		string $error,
		int $attempts,
		string $retry_at = ''
	): void {
		$meta = json_decode( (string) ( $item->meta ?? '{}' ), true );
		$this->logger->log(
			Logger::LEVEL_WARNING,
			sprintf(
				/* translators: 1: queue item ID; 2: attempt; 3: max attempts; 4: retry time; 5: error */
				__( 'Queue item #%1$d failed (attempt %2$d/%3$d) — retry at %4$s. Error: %5$s', 'tsh-whatsapp-notify' ),
				$item->id,
				$attempts,
				$item->max_attempts,
				$retry_at,
				$error
			),
			[
				'queue_id' => $item->id,
				'order_id' => $item->order_id,
				'event_key'=> $meta['event_key'] ?? '',
				'error'    => $error,
				'attempt'  => $attempts,
				'retry_at' => $retry_at,
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
