<?php
/**
 * Worker lock — prevents duplicate queue workers from running concurrently.
 *
 * Uses a WordPress options-table INSERT to achieve an atomic test-and-set,
 * since `insert()` respects the UNIQUE KEY on `option_name` and returns false
 * (0 rows affected) when another worker already owns the lock.
 *
 * Lock records expire automatically: on every acquire() call, stale locks
 * (older than LOCK_TTL seconds) are cleaned up before attempting to claim.
 *
 * @package TSH\WhatsAppNotify\Queue
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Queue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WorkerLock
 *
 * Usage:
 *   $lock = new WorkerLock();
 *   $worker_id = $lock->acquire();
 *   if ( ! $worker_id ) { return; } // another worker is already running
 *   try { ... } finally { $lock->release(); }
 */
final class WorkerLock {

	/** @var string Options-table key for the lock record. */
	public const LOCK_KEY = 'tsh_wa_queue_worker_lock';

	/** @var int Seconds before a lock is considered stale (safety TTL). */
	public const LOCK_TTL = 300; // 5 minutes

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Attempt to acquire the worker lock.
	 *
	 * @return string|false Worker ID on success, false if the lock is held by another worker.
	 */
	public function acquire(): string|false {
		global $wpdb;

		// Generate a unique ID for this worker.
		$worker_id = $this->generate_id();

		// Clean up any expired locks before trying to acquire.
		$this->release_if_expired();

		// INSERT IGNORE: atomic test-and-set using the UNIQUE option_name key.
		$payload = wp_json_encode( [
			'worker_id'  => $worker_id,
			'expires_at' => time() + self::LOCK_TTL,
			'pid'        => getmypid(),
		] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			$wpdb->options,
			[
				'option_name'  => self::LOCK_KEY,
				'option_value' => $payload,
				'autoload'     => 'no',
			],
			[ '%s', '%s', '%s' ]
		);

		if ( ! $inserted ) {
			return false; // Another worker holds the lock.
		}

		// Invalidate WP's object cache for this option so reads see the new value.
		wp_cache_delete( self::LOCK_KEY, 'options' );

		return $worker_id;
	}

	/**
	 * Release the worker lock (called at the end of every batch, also in finally blocks).
	 */
	public function release(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->delete( $wpdb->options, [ 'option_name' => self::LOCK_KEY ], [ '%s' ] );
		wp_cache_delete( self::LOCK_KEY, 'options' );
	}

	/**
	 * Return true if any worker currently holds the lock (and it has not expired).
	 */
	public function is_locked(): bool {
		$data = $this->get_lock_data();

		if ( null === $data ) {
			return false;
		}

		return time() < (int) ( $data['expires_at'] ?? 0 );
	}

	/**
	 * Return the worker ID currently holding the lock, or empty string if unlocked.
	 */
	public function current_holder(): string {
		$data = $this->get_lock_data();
		return $data ? (string) ( $data['worker_id'] ?? '' ) : '';
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Remove the lock record if its TTL has elapsed (safety cleanup for crashed workers).
	 */
	private function release_if_expired(): void {
		$data = $this->get_lock_data();

		if ( null === $data ) {
			return;
		}

		if ( time() >= (int) ( $data['expires_at'] ?? 0 ) ) {
			$this->release();
		}
	}

	/**
	 * Fetch and decode the lock record from the options table (bypasses WP object cache).
	 *
	 * @return array<string, mixed>|null
	 */
	private function get_lock_data(): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				self::LOCK_KEY
			)
		);

		if ( null === $value ) {
			return null;
		}

		$decoded = json_decode( (string) $value, true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Generate a short unique worker identifier.
	 */
	private function generate_id(): string {
		return 'wrk_' . substr( md5( uniqid( (string) mt_rand(), true ) ), 0, 12 );
	}
}
