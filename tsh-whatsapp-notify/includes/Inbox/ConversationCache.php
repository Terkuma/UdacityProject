<?php
/**
 * Conversation cache layer.
 *
 * @package TSH\WhatsAppNotify\Inbox
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Inbox;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ConversationCache
 *
 * Manages transient-based caching for inbox data.
 * Each cache key is registered in a central registry option so it can be
 * bulk-flushed without a table scan.
 */
final class ConversationCache {

	/** @var string Option key for the key registry. */
	public const REGISTRY_OPTION = 'tsh_wa_inbox_cache_keys';

	/** @var string Transient prefix. */
	private const PREFIX = 'tsh_wa_inbox_';

	/** @var int Default TTL in seconds (5 minutes). */
	private const DEFAULT_TTL = 300;

	// -------------------------------------------------------------------------
	// Read / write
	// -------------------------------------------------------------------------

	/**
	 * Get a cached value.
	 *
	 * @param string $key Cache key (without prefix).
	 * @return mixed|false False if not found or expired.
	 */
	public function get( string $key ): mixed {
		return get_transient( self::PREFIX . $key );
	}

	/**
	 * Store a value in cache.
	 *
	 * @param string $key   Cache key (without prefix).
	 * @param mixed  $value Value to store.
	 * @param int    $ttl   Time-to-live in seconds.
	 */
	public function set( string $key, mixed $value, int $ttl = self::DEFAULT_TTL ): void {
		set_transient( self::PREFIX . $key, $value, $ttl );
		$this->register_key( $key );
	}

	/**
	 * Delete a cached value.
	 *
	 * @param string $key Cache key (without prefix).
	 */
	public function delete( string $key ): void {
		delete_transient( self::PREFIX . $key );
		$this->unregister_key( $key );
	}

	// -------------------------------------------------------------------------
	// Specialised bust helpers
	// -------------------------------------------------------------------------

	/**
	 * Bust all cache entries for a specific conversation.
	 *
	 * @param int $conversation_id
	 */
	public function bust_conversation( int $conversation_id ): void {
		$this->delete( 'conv_' . $conversation_id );
		$this->delete( 'msgs_' . $conversation_id );
		// Also bust list caches (unread counts, etc.).
		$this->bust_lists();
	}

	/**
	 * Delete all conversation list caches (used when conversations change).
	 */
	public function bust_lists(): void {
		$registry = $this->get_registry();
		foreach ( $registry as $key ) {
			if ( str_starts_with( $key, 'list_' ) || str_starts_with( $key, 'stats_' ) ) {
				delete_transient( self::PREFIX . $key );
			}
		}
	}

	/**
	 * Delete every inbox cache transient.
	 */
	public function flush(): void {
		global $wpdb;

		$like = $wpdb->esc_like( '_transient_' . self::PREFIX ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$wpdb->options}` WHERE option_name LIKE %s", $like ) );

		delete_option( self::REGISTRY_OPTION );
	}

	// -------------------------------------------------------------------------
	// Key registry
	// -------------------------------------------------------------------------

	/**
	 * Get the list of registered cache keys.
	 *
	 * @return string[]
	 */
	private function get_registry(): array {
		$keys = get_option( self::REGISTRY_OPTION, [] );
		return is_array( $keys ) ? $keys : [];
	}

	/**
	 * Add a key to the registry.
	 *
	 * @param string $key
	 */
	private function register_key( string $key ): void {
		$keys = $this->get_registry();
		if ( ! in_array( $key, $keys, true ) ) {
			$keys[] = $key;
			update_option( self::REGISTRY_OPTION, $keys, false );
		}
	}

	/**
	 * Remove a key from the registry.
	 *
	 * @param string $key
	 */
	private function unregister_key( string $key ): void {
		$keys = $this->get_registry();
		$keys = array_values( array_filter( $keys, fn( $k ) => $k !== $key ) );
		update_option( self::REGISTRY_OPTION, $keys, false );
	}
}
