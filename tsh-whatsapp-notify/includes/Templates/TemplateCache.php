<?php
/**
 * Transient-based cache for template data.
 *
 * @package TSH\WhatsAppNotify\Templates
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Templates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TemplateCache
 *
 * Wraps WordPress transients to cache template objects and list results.
 * All cache keys are prefixed with 'tsh_wa_tmpl_' so they can be
 * bulk-flushed without touching other plugin transients.
 */
final class TemplateCache {

	private const KEY_PREFIX      = 'tsh_wa_tmpl_';
	private const DEFAULT_TTL     = 3600; // 1 hour.
	private const REGISTRY_OPTION = 'tsh_wa_tmpl_cache_keys';

	// -------------------------------------------------------------------------
	// Generic get / set / delete
	// -------------------------------------------------------------------------

	/**
	 * Retrieve a cached value.
	 *
	 * @param string $key Cache key (without prefix).
	 * @return mixed|false False on cache miss.
	 */
	public function get( string $key ) {
		return get_transient( self::KEY_PREFIX . $key );
	}

	/**
	 * Store a value in the cache.
	 *
	 * @param string $key   Cache key (without prefix).
	 * @param mixed  $value Value to store.
	 * @param int    $ttl   Time-to-live in seconds. 0 = use default.
	 * @return bool
	 */
	public function set( string $key, $value, int $ttl = 0 ): bool {
		$ttl = $ttl > 0 ? $ttl : $this->get_configured_ttl();
		$this->register_key( $key );
		return set_transient( self::KEY_PREFIX . $key, $value, $ttl );
	}

	/**
	 * Delete a specific cached value.
	 *
	 * @param string $key
	 * @return bool
	 */
	public function delete( string $key ): bool {
		$this->unregister_key( $key );
		return delete_transient( self::KEY_PREFIX . $key );
	}

	// -------------------------------------------------------------------------
	// Template-specific helpers
	// -------------------------------------------------------------------------

	/**
	 * Get a single cached template object.
	 *
	 * @param int $id
	 * @return object|null
	 */
	public function get_template( int $id ): ?object {
		$val = $this->get( 'single_' . $id );
		return is_object( $val ) ? $val : null;
	}

	/**
	 * Cache a single template object.
	 *
	 * @param int    $id
	 * @param object $template
	 */
	public function set_template( int $id, object $template ): void {
		$this->set( 'single_' . $id, $template );
	}

	/**
	 * Invalidate cache for a single template.
	 *
	 * @param int $id
	 */
	public function bust_template( int $id ): void {
		$this->delete( 'single_' . $id );
		// Also bust any list queries — safest approach.
		$this->flush_lists();
	}

	/**
	 * Get a cached template list result.
	 *
	 * @param string $hash MD5 of the query args array.
	 * @return array<int, object>|null
	 */
	public function get_template_list( string $hash ): ?array {
		$val = $this->get( 'list_' . $hash );
		return is_array( $val ) ? $val : null;
	}

	/**
	 * Cache a template list result.
	 *
	 * @param string            $hash MD5 of the query args array.
	 * @param array<int, object> $list
	 * @param int               $ttl  0 = use default.
	 */
	public function set_template_list( string $hash, array $list, int $ttl = 0 ): void {
		$this->set( 'list_' . $hash, $list, $ttl );
	}

	// -------------------------------------------------------------------------
	// Bulk operations
	// -------------------------------------------------------------------------

	/**
	 * Delete all template list caches (single-template caches preserved).
	 */
	public function flush_lists(): void {
		$keys = $this->get_registered_keys();
		foreach ( $keys as $key ) {
			if ( str_starts_with( $key, 'list_' ) ) {
				delete_transient( self::KEY_PREFIX . $key );
				$this->unregister_key( $key );
			}
		}
	}

	/**
	 * Delete ALL plugin template transients.
	 */
	public function flush(): void {
		$keys = $this->get_registered_keys();
		foreach ( $keys as $key ) {
			delete_transient( self::KEY_PREFIX . $key );
		}
		delete_option( self::REGISTRY_OPTION );

		// Also try bulk delete via wpdb for keys not in the registry.
		global $wpdb;
		$like = $wpdb->esc_like( '_transient_' . self::KEY_PREFIX ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare( "DELETE FROM `{$wpdb->options}` WHERE option_name LIKE %s", $like )
		);
	}

	// -------------------------------------------------------------------------
	// Registry helpers (track which keys we set so flush() is complete)
	// -------------------------------------------------------------------------

	/**
	 * @return array<int, string>
	 */
	private function get_registered_keys(): array {
		$keys = get_option( self::REGISTRY_OPTION, [] );
		return is_array( $keys ) ? $keys : [];
	}

	private function register_key( string $key ): void {
		$keys = $this->get_registered_keys();
		if ( ! in_array( $key, $keys, true ) ) {
			$keys[] = $key;
			update_option( self::REGISTRY_OPTION, $keys, false );
		}
	}

	private function unregister_key( string $key ): void {
		$keys = $this->get_registered_keys();
		$keys = array_filter( $keys, static fn( $k ) => $k !== $key );
		update_option( self::REGISTRY_OPTION, array_values( $keys ), false );
	}

	// -------------------------------------------------------------------------
	// Config
	// -------------------------------------------------------------------------

	/**
	 * Return the configured cache TTL from sync settings.
	 */
	private function get_configured_ttl(): int {
		$settings = get_option( 'tsh_wa_sync_settings', [] );
		$minutes  = absint( $settings['cache_duration'] ?? 60 );
		return max( 60, $minutes * 60 );
	}
}
