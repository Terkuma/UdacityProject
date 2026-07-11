<?php
/**
 * API request logger.
 *
 * @package TSH\WhatsAppNotify\API
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RequestLogger
 *
 * Persists every outbound API request to the {prefix}tsh_wa_api_requests table
 * for auditing, debugging, and statistics.
 *
 * Security rule: the access token MUST NEVER appear in any column.
 */
final class RequestLogger {

	/** @var bool Whether to persist response bodies (debug mode only). */
	private bool $debug_mode;

	/**
	 * @param bool $debug_mode Store response bodies when true.
	 */
	public function __construct( bool $debug_mode = false ) {
		$this->debug_mode = $debug_mode;
	}

	// -------------------------------------------------------------------------
	// Write
	// -------------------------------------------------------------------------

	/**
	 * Persist one API request record.
	 *
	 * @param string      $endpoint      URL path after the base URL.
	 * @param string      $method        HTTP verb (GET, POST, etc.).
	 * @param float       $latency_ms    Round-trip time in milliseconds.
	 * @param int         $http_status   HTTP response code (0 = network error).
	 * @param bool        $success       Whether the request succeeded.
	 * @param int         $retry_count   Number of retries attempted.
	 * @param string      $error_code    Provider error code, or ''.
	 * @param string|null $response_body Raw response JSON (stored only in debug mode).
	 * @param int         $response_size Byte length of the response body.
	 * @return int Inserted row ID, or 0 on failure.
	 */
	public function log_request(
		string $endpoint,
		string $method,
		float $latency_ms,
		int $http_status,
		bool $success,
		int $retry_count = 0,
		string $error_code = '',
		?string $response_body = null,
		int $response_size = 0
	): int {
		global $wpdb;

		$table = $wpdb->prefix . 'tsh_wa_api_requests';

		// Never store token-containing strings. Scrub just in case.
		$endpoint = $this->scrub_sensitive( $endpoint );

		$data = [
			'endpoint'      => sanitize_text_field( $endpoint ),
			'method'        => sanitize_key( strtoupper( $method ) ),
			'latency_ms'    => round( $latency_ms, 3 ),
			'http_status'   => $http_status,
			'success'       => $success ? 1 : 0,
			'retry_count'   => absint( $retry_count ),
			'error_code'    => sanitize_text_field( $error_code ),
			'response_size' => absint( $response_size ),
		];

		$formats = [ '%s', '%s', '%f', '%d', '%d', '%d', '%s', '%d' ];

		// Response body only in debug mode.
		if ( $this->debug_mode && null !== $response_body ) {
			$data['response_body'] = wp_kses_post( $response_body );
			$formats[]             = '%s';
		}

		$result = $wpdb->insert( $table, $data, $formats );

		return $result ? (int) $wpdb->insert_id : 0;
	}

	// -------------------------------------------------------------------------
	// Read
	// -------------------------------------------------------------------------

	/**
	 * Retrieve the most recent API request records.
	 *
	 * @param int $limit Maximum records to return (1–200).
	 * @return array<int, object>
	 */
	public function get_recent( int $limit = 20 ): array {
		global $wpdb;

		$limit = min( max( $limit, 1 ), 200 );
		$table = $wpdb->prefix . 'tsh_wa_api_requests';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` ORDER BY created_at DESC LIMIT %d",
				$limit
			)
		);

		return $rows ?: [];
	}

	/**
	 * Return aggregate statistics for a given period.
	 *
	 * @param string $period 'today' | 'week' | 'month' | 'all'
	 * @return array{total: int, success: int, failed: int, avg_latency_ms: float, total_retries: int}
	 */
	public function get_stats( string $period = 'today' ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'tsh_wa_api_requests';
		$since = $this->period_to_datetime( $period );

		if ( $since ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT
						COUNT(*) AS total,
						SUM(success) AS success,
						SUM(1 - success) AS failed,
						AVG(latency_ms) AS avg_latency_ms,
						SUM(retry_count) AS total_retries
					FROM `{$table}`
					WHERE created_at >= %s",
					$since
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row = $wpdb->get_row(
				"SELECT
					COUNT(*) AS total,
					SUM(success) AS success,
					SUM(1 - success) AS failed,
					AVG(latency_ms) AS avg_latency_ms,
					SUM(retry_count) AS total_retries
				FROM `{$table}`"
			);
		}

		if ( ! $row ) {
			return [ 'total' => 0, 'success' => 0, 'failed' => 0, 'avg_latency_ms' => 0.0, 'total_retries' => 0 ];
		}

		return [
			'total'          => (int) $row->total,
			'success'        => (int) $row->success,
			'failed'         => (int) $row->failed,
			'avg_latency_ms' => round( (float) $row->avg_latency_ms, 1 ),
			'total_retries'  => (int) $row->total_retries,
		];
	}

	/**
	 * Calculate the overall success rate as a percentage.
	 *
	 * @return float 0.0–100.0
	 */
	public function get_success_rate(): float {
		$stats = $this->get_stats( 'all' );

		if ( 0 === $stats['total'] ) {
			return 0.0;
		}

		return round( ( $stats['success'] / $stats['total'] ) * 100, 1 );
	}

	/**
	 * Return the most recent successful request row, or null.
	 *
	 * @return object|null
	 */
	public function get_last_successful(): ?object {
		global $wpdb;

		$table = $wpdb->prefix . 'tsh_wa_api_requests';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			"SELECT * FROM `{$table}` WHERE success = 1 ORDER BY created_at DESC LIMIT 1"
		);

		return $row ?: null;
	}

	/**
	 * Return the most recent failed request row, or null.
	 *
	 * @return object|null
	 */
	public function get_last_failed(): ?object {
		global $wpdb;

		$table = $wpdb->prefix . 'tsh_wa_api_requests';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			"SELECT * FROM `{$table}` WHERE success = 0 ORDER BY created_at DESC LIMIT 1"
		);

		return $row ?: null;
	}

	/**
	 * Delete all API request log entries.
	 *
	 * @return int Number of rows deleted.
	 */
	public function clear(): int {
		global $wpdb;

		$table = $wpdb->prefix . 'tsh_wa_api_requests';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query( "TRUNCATE TABLE `{$table}`" );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Convert a period label to a MySQL datetime threshold string.
	 *
	 * @param string $period 'today' | 'week' | 'month' | 'all'
	 * @return string|null NULL means no filter (all time).
	 */
	private function period_to_datetime( string $period ): ?string {
		return match ( $period ) {
			'today' => current_time( 'Y-m-d' ) . ' 00:00:00',
			'week'  => gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) ),
			'month' => gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) ),
			default => null,
		};
	}

	/**
	 * Remove any string that looks like it could be a bearer token.
	 * Belt-and-suspenders guard — tokens should never reach here.
	 *
	 * @param string $value
	 * @return string
	 */
	private function scrub_sensitive( string $value ): string {
		// Strip anything that looks like a bearer token in a URL.
		return (string) preg_replace( '/access_token=[^&]+/', 'access_token=[REDACTED]', $value );
	}
}
