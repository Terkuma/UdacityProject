<?php
/**
 * API health monitor.
 *
 * @package TSH\WhatsAppNotify\API
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TSH\WhatsAppNotify\Logger\Logger;

/**
 * Class HealthMonitor
 *
 * Tracks the live health status of the WhatsApp Cloud API connection.
 *
 * Status is cached in a transient (10 minutes) so the dashboard never
 * makes a live API call on every page load. The WP-Cron health-check
 * event (Phase 1: tsh_wa_cron_health_check) refreshes the cache hourly.
 * The admin can force a manual refresh via AJAX.
 */
final class HealthMonitor {

	/** @var string Transient key for cached health status. */
	private const TRANSIENT_KEY = 'tsh_wa_api_health_status';

	/** @var string Option key for last-check timestamps and rate. */
	private const OPTION_KEY = 'tsh_wa_api_health_history';

	/** @var int Transient lifetime in seconds (10 minutes). */
	private const CACHE_TTL = 600;

	/** @var TokenManager */
	private TokenManager $token_manager;

	public function __construct() {
		$this->token_manager = new TokenManager();
	}

	// -------------------------------------------------------------------------
	// Bootstrap
	// -------------------------------------------------------------------------

	/**
	 * Register the health-check cron hook.
	 * Called once from Loader::register_components().
	 */
	public function register_hooks(): void {
		add_action( 'tsh_wa_cron_health_check', [ $this, 'handle_cron_health_check' ] );
	}

	// -------------------------------------------------------------------------
	// Cron handler
	// -------------------------------------------------------------------------

	/**
	 * Cron callback — fired hourly by Scheduler.
	 * Runs a health check and updates the cache + history option.
	 */
	public function handle_cron_health_check(): void {
		$this->refresh();
	}

	// -------------------------------------------------------------------------
	// Status
	// -------------------------------------------------------------------------

	/**
	 * Return the API health status.
	 *
	 * Returns the cached value unless $force_refresh is true or the cache
	 * has expired. NEVER makes an API call unless necessary.
	 *
	 * @param bool $force_refresh Bypass cache and run a fresh check.
	 * @return array<string, mixed>
	 */
	public function get_status( bool $force_refresh = false ): array {
		if ( ! $force_refresh ) {
			$cached = $this->get_cached_status();
			if ( null !== $cached ) {
				return $cached;
			}
		}

		// No cache (or forced) — credentials must be present to run a check.
		if ( ! $this->token_manager->has_required_credentials() ) {
			return $this->not_configured_status();
		}

		return $this->refresh();
	}

	/**
	 * Force a fresh health check, update the cache, and return the result.
	 *
	 * @return array<string, mixed>
	 */
	public function refresh(): array {
		if ( ! $this->token_manager->has_required_credentials() ) {
			$status = $this->not_configured_status();
			$this->cache_status( $status );
			return $status;
		}

		try {
			$provider = new MetaCloudProvider();
			$result   = $provider->healthCheck();
		} catch ( ApiException $e ) {
			$result = [
				'success'        => false,
				'message'        => $e->getMessage(),
				'dev_message'    => $e->get_developer_message(),
				'http_status'    => $e->get_http_status(),
				'error_code'     => $e->get_meta_error_code(),
				'retry'          => $e->is_retry_recommended(),
				'phone_number'   => '',
				'display_name'   => '',
				'quality_rating' => '',
				'api_version'    => $this->token_manager->get_credentials()['api_version'],
				'latency_ms'     => 0.0,
			];
		}

		// Add request logger stats.
		$request_logger = new RequestLogger();
		$stats_today    = $request_logger->get_stats( 'today' );
		$success_rate   = $request_logger->get_success_rate();
		$last_ok        = $request_logger->get_last_successful();
		$last_fail      = $request_logger->get_last_failed();

		$status = array_merge( $result, [
			'checked_at'              => current_time( 'c' ),
			'messages_today'          => $stats_today['success'],
			'errors_today'            => $stats_today['failed'],
			'avg_latency_ms'          => $stats_today['avg_latency_ms'],
			'success_rate'            => $success_rate,
			'last_successful_request' => $last_ok  ? $last_ok->created_at  : null,
			'last_failed_request'     => $last_fail ? $last_fail->created_at : null,
		] );

		$this->cache_status( $status );
		$this->update_history( $status );

		// Log health check.
		try {
			$logger = new Logger();
			$level  = $result['success'] ? Logger::LEVEL_INFO : Logger::LEVEL_WARNING;
			$logger->log(
				$level,
				$result['success']
					? __( 'API health check passed.', 'tsh-whatsapp-notify' )
					: sprintf(
						/* translators: %s: error message */
						__( 'API health check failed: %s', 'tsh-whatsapp-notify' ),
						$result['message']
					),
				[ 'latency_ms' => $result['latency_ms'] ?? 0 ],
				'health_monitor'
			);
		} catch ( \Throwable ) {
			// Logger must not crash the health monitor.
		}

		return $status;
	}

	/**
	 * Return the cached status without making any API call.
	 * Returns null when the cache is cold.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get_cached_status(): ?array {
		$cached = get_transient( self::TRANSIENT_KEY );

		if ( false === $cached || ! is_array( $cached ) ) {
			return null;
		}

		return $cached;
	}

	/**
	 * Return the connection history option array.
	 *
	 * @return array<string, mixed>
	 */
	public function get_history(): array {
		$history = get_option( self::OPTION_KEY, [] );
		return is_array( $history ) ? $history : [];
	}

	// -------------------------------------------------------------------------
	// Dashboard helper
	// -------------------------------------------------------------------------

	/**
	 * Return a lightweight status snapshot safe for the dashboard card.
	 * Never triggers an API call. Falls back to a "not configured" shape.
	 *
	 * @return array<string, mixed>
	 */
	public function get_dashboard_status(): array {
		$cached = $this->get_cached_status();

		if ( null !== $cached ) {
			return $cached;
		}

		if ( ! $this->token_manager->has_required_credentials() ) {
			return $this->not_configured_status();
		}

		// Credentials present but cache is cold — return unknown status (no API call).
		return [
			'success'                 => null,
			'message'                 => __( 'Status not yet checked.', 'tsh-whatsapp-notify' ),
			'phone_number'            => '',
			'display_name'            => '',
			'quality_rating'          => '',
			'api_version'             => $this->token_manager->get_credentials()['api_version'],
			'latency_ms'              => null,
			'checked_at'              => null,
			'messages_today'          => 0,
			'errors_today'            => 0,
			'avg_latency_ms'          => 0.0,
			'success_rate'            => 0.0,
			'last_successful_request' => null,
			'last_failed_request'     => null,
		];
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Persist the status in the transient cache.
	 *
	 * @param array<string, mixed> $status
	 */
	private function cache_status( array $status ): void {
		set_transient( self::TRANSIENT_KEY, $status, self::CACHE_TTL );
	}

	/**
	 * Persist last-check timestamps and running stats to an option.
	 *
	 * @param array<string, mixed> $status
	 */
	private function update_history( array $status ): void {
		$history = $this->get_history();

		if ( $status['success'] ) {
			$history['last_success_at']  = current_time( 'c' );
			$history['last_latency_ms']  = $status['latency_ms'] ?? 0;
		} else {
			$history['last_failure_at']  = current_time( 'c' );
			$history['last_error_code']  = $status['error_code'] ?? '';
			$history['last_error_msg']   = $status['message']    ?? '';
		}

		$history['last_checked_at'] = current_time( 'c' );

		update_option( self::OPTION_KEY, $history, false );
	}

	/**
	 * Return a status array representing an unconfigured state.
	 *
	 * @return array<string, mixed>
	 */
	private function not_configured_status(): array {
		return [
			'success'                 => false,
			'message'                 => __( 'API credentials not configured.', 'tsh-whatsapp-notify' ),
			'dev_message'             => 'No credentials found in settings.',
			'http_status'             => 0,
			'error_code'              => 'NOT_CONFIGURED',
			'retry'                   => false,
			'phone_number'            => '',
			'display_name'            => '',
			'quality_rating'          => '',
			'api_version'             => '',
			'latency_ms'              => null,
			'checked_at'              => null,
			'messages_today'          => 0,
			'errors_today'            => 0,
			'avg_latency_ms'          => 0.0,
			'success_rate'            => 0.0,
			'last_successful_request' => null,
			'last_failed_request'     => null,
		];
	}
}
