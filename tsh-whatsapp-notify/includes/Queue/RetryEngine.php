<?php
/**
 * Retry engine — exponential backoff scheduling and retryable-error classification.
 *
 * Separates the decision of whether to retry from the decision of when to retry.
 * QueueProcessor calls this class before writing a retry schedule to the queue row.
 *
 * Backoff formula:
 *   delay(attempt) = base_delay × 2^(attempt − 1), capped at MAX_DELAY_SECONDS.
 *   attempt 1 →  1 × base  (default 60 s  → 1 min)
 *   attempt 2 →  2 × base  (default 120 s → 2 min)
 *   attempt 3 →  4 × base  (default 240 s → 4 min)
 *   attempt 4 →  8 × base  (default 480 s → 8 min)
 *   attempt 5 → 16 × base  (default 960 s → capped to MAX_DELAY)
 *
 * @package TSH\WhatsAppNotify\Queue
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Queue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RetryEngine
 */
final class RetryEngine {

	// -------------------------------------------------------------------------
	// Constants
	// -------------------------------------------------------------------------

	/** @var int Maximum retry delay, regardless of attempt count. */
	public const MAX_DELAY_SECONDS = 3600; // 1 hour

	/** @var int Minimum retry delay. */
	public const MIN_DELAY_SECONDS = 30;

	/**
	 * Meta Graph API error codes that indicate a PERMANENT failure.
	 * These errors cannot be fixed by retrying; they require admin intervention.
	 *
	 * @link https://developers.facebook.com/docs/whatsapp/cloud-api/support/error-codes
	 *
	 * @var string[]
	 */
	private const PERMANENT_META_ERROR_CODES = [
		'100',   // Invalid parameter
		'190',   // Invalid/expired access token
		'200',   // API Permission denied
		'270',   // Messaging permission denied
		'131009',// Parameter value is not valid
		'131021',// Recipient is not valid (blocked, no WA account, etc.)
		'131026',// Message undeliverable — recipient opted out or invalid
		'131047',// Re-engagement message — 24-hour window expired
		'131048',// Spam rate limit — account flagged; needs manual review
		'131051',// Unsupported message type
		'131052',// Media download error (permanent asset issue)
	];

	/**
	 * HTTP status codes from which retrying will never succeed.
	 *
	 * @var int[]
	 */
	private const PERMANENT_HTTP_STATUSES = [
		401, // Unauthorised — wrong token
		403, // Forbidden — insufficient permissions
		404, // Not found — wrong Phone Number ID
		400, // Bad request — malformed payload (structural issue)
	];

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Determine whether the given error is retryable.
	 *
	 * @param string $meta_error_code Meta API error code (empty string if none).
	 * @param int    $http_status     HTTP response status (0 if no response received).
	 * @return bool True if retrying may succeed.
	 */
	public function is_retryable( string $meta_error_code = '', int $http_status = 0 ): bool {
		// HTTP-level permanent failures.
		if ( $http_status > 0 && in_array( $http_status, self::PERMANENT_HTTP_STATUSES, true ) ) {
			return false;
		}

		// Meta-level permanent error codes.
		if ( $meta_error_code && in_array( $meta_error_code, self::PERMANENT_META_ERROR_CODES, true ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Calculate when the next attempt should be scheduled.
	 *
	 * @param int $attempts      Number of attempts already made (≥ 1 after the first failure).
	 * @param int $base_delay_s  Base delay in seconds (default: from queue settings, fallback 60).
	 * @return string MySQL DATETIME string (UTC-compatible, current_time base).
	 */
	public function next_retry_at( int $attempts, int $base_delay_s = 0 ): string {
		if ( $base_delay_s <= 0 ) {
			$settings     = get_option( 'tsh_wa_queue_settings', [] );
			$base_delay_s = absint( $settings['retry_delay'] ?? 1 ) * 60; // convert minutes → seconds
			$base_delay_s = max( self::MIN_DELAY_SECONDS, $base_delay_s );
		}

		$exponent = max( 0, $attempts - 1 );
		$delay    = (int) min( $base_delay_s * ( 2 ** $exponent ), self::MAX_DELAY_SECONDS );

		// Use WordPress current time + offset so it matches how queue.scheduled_at is stored.
		return gmdate( 'Y-m-d H:i:s', time() + $delay );
	}

	/**
	 * Return the delay in seconds for the given attempt number, without scheduling it.
	 *
	 * @param int $attempts     Number of attempts already made.
	 * @param int $base_delay_s Base delay in seconds.
	 * @return int Delay in seconds.
	 */
	public function delay_for_attempt( int $attempts, int $base_delay_s = 60 ): int {
		$exponent = max( 0, $attempts - 1 );
		return (int) min( $base_delay_s * ( 2 ** $exponent ), self::MAX_DELAY_SECONDS );
	}

	/**
	 * Build a human-readable summary of the retry schedule for logging.
	 *
	 * @param int    $attempt      Current attempt number (the one that just failed).
	 * @param int    $max_attempts Maximum allowed attempts.
	 * @param string $retry_at     MySQL DATETIME when next attempt will run.
	 * @return string
	 */
	public function format_retry_notice( int $attempt, int $max_attempts, string $retry_at ): string {
		$remaining = $max_attempts - $attempt;

		return sprintf(
			/* translators: 1: current attempt, 2: max attempts, 3: remaining attempts, 4: next run time */
			__( 'Attempt %1$d of %2$d failed. %3$d attempt(s) remaining. Next try at: %4$s.', 'tsh-whatsapp-notify' ),
			$attempt,
			$max_attempts,
			$remaining,
			$retry_at
		);
	}
}
