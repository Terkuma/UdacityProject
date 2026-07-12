<?php
/**
 * Rate limiter — prevents exceeding the Meta WhatsApp Cloud API per-minute cap.
 *
 * Uses a sliding 60-second window stored in a WP transient.  Each `consume()`
 * call increments the counter; `allow()` checks whether sending another message
 * would stay within the configured limit.
 *
 * If messages_per_minute = 0 the limiter is disabled (unlimited throughput).
 *
 * Meta Cloud API throughput limits vary by tier. The default of 80 msg/min is
 * conservative and works for Standard tier. Tier 1 businesses can go to 1,000.
 *
 * @package TSH\WhatsAppNotify\Queue
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Queue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RateLimiter
 */
final class RateLimiter {

	/** @var string Transient key for the sliding-window counter. */
	private const BUCKET_KEY = 'tsh_wa_rate_bucket';

	/** @var int Window size in seconds. */
	private const WINDOW_SECONDS = 60;

	/** @var int Maximum messages allowed per window. 0 = unlimited. */
	private int $max_per_minute;

	/**
	 * Initialise from queue settings.
	 */
	public function __construct() {
		$settings             = get_option( 'tsh_wa_queue_settings', [] );
		$this->max_per_minute = absint( $settings['messages_per_minute'] ?? 80 );
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Check whether sending one more message is within the rate limit.
	 * Does NOT consume a slot; pair with consume() after the send is dispatched.
	 *
	 * @return bool True if a message may be sent right now.
	 */
	public function allow(): bool {
		if ( $this->max_per_minute <= 0 ) {
			return true; // Unlimited.
		}

		return $this->get_count() < $this->max_per_minute;
	}

	/**
	 * Record that one message has been dispatched, consuming a slot in the window.
	 */
	public function consume(): void {
		$current = $this->get_count();

		if ( 0 === $current ) {
			// Start a new 60-second window.
			set_transient( self::BUCKET_KEY, 1, self::WINDOW_SECONDS );
		} else {
			// Increment — keeping whatever TTL is left on the current transient.
			set_transient( self::BUCKET_KEY, $current + 1, self::WINDOW_SECONDS );
		}
	}

	/**
	 * Return the number of remaining slots in the current window.
	 *
	 * @return int Remaining slots (PHP_INT_MAX when unlimited).
	 */
	public function remaining(): int {
		if ( $this->max_per_minute <= 0 ) {
			return PHP_INT_MAX;
		}

		return max( 0, $this->max_per_minute - $this->get_count() );
	}

	/**
	 * Return the configured maximum messages per minute (0 = unlimited).
	 */
	public function get_max(): int {
		return $this->max_per_minute;
	}

	/**
	 * Return the current count of messages sent in this window.
	 */
	public function get_count(): int {
		return (int) ( get_transient( self::BUCKET_KEY ) ?: 0 );
	}

	/**
	 * Reset the rate-limit bucket (useful after a pause/resume or for testing).
	 */
	public function reset(): void {
		delete_transient( self::BUCKET_KEY );
	}
}
