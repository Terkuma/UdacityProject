<?php
/**
 * Queue statistics — aggregated metrics for the Phase 4 dashboard.
 *
 * Provides performance-oriented counters (avg latency, throughput, DLQ size)
 * and health signals (last processed, stuck items, cron status) that feed both
 * the Queue admin page and the AJAX live-refresh endpoint.
 *
 * @package TSH\WhatsAppNotify\Queue
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Queue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TSH\WhatsAppNotify\API\HealthMonitor;
use TSH\WhatsAppNotify\Cron\Scheduler;

/**
 * Class QueueStats
 */
final class QueueStats {

	// -------------------------------------------------------------------------
	// Public API — summary
	// -------------------------------------------------------------------------

	/**
	 * Return a full stats snapshot suitable for the queue dashboard.
	 *
	 * @return array<string, mixed>
	 */
	public function get_summary(): array {
		return [
			'counts'          => $this->get_counts(),
			'dead_letter'     => $this->get_dead_letter_count(),
			'retrying'        => $this->get_retrying_count(),
			'avg_latency_ms'  => $this->get_avg_latency_ms(),
			'avg_process_ms'  => $this->get_avg_process_time_ms(),
			'throughput_hour' => $this->get_hourly_throughput(),
			'sent_today'      => $this->get_sent_today(),
			'failed_today'    => $this->get_failed_today(),
			'last_processed'  => $this->get_last_processed_at(),
			'is_paused'       => $this->is_paused(),
			'queue_enabled'   => $this->is_queue_enabled(),
		];
	}

	/**
	 * Return queue health signals for the health monitor panel.
	 *
	 * @return array<string, array<string, string|bool>>
	 */
	public function get_health(): array {
		$checks = [];

		// --- Queue health --------------------------------------------------.
		$paused  = $this->is_paused();
		$enabled = $this->is_queue_enabled();
		$last_at = $this->get_last_processed_at();
		$stuck   = $this->get_stuck_count();

		if ( ! $enabled ) {
			$checks['queue'] = [
				'status'  => 'warning',
				'label'   => __( 'Queue', 'tsh-whatsapp-notify' ),
				'message' => __( 'Queue processing is disabled.', 'tsh-whatsapp-notify' ),
			];
		} elseif ( $paused ) {
			$checks['queue'] = [
				'status'  => 'warning',
				'label'   => __( 'Queue', 'tsh-whatsapp-notify' ),
				'message' => __( 'Queue is manually paused.', 'tsh-whatsapp-notify' ),
			];
		} elseif ( $stuck > 0 ) {
			$checks['queue'] = [
				'status'  => 'error',
				'label'   => __( 'Queue', 'tsh-whatsapp-notify' ),
				'message' => sprintf(
					/* translators: %d: number of stuck items */
					__( '%d item(s) stuck in "processing" for over 10 minutes.', 'tsh-whatsapp-notify' ),
					$stuck
				),
			];
		} else {
			$age_minutes = $last_at ? (int) ( ( time() - strtotime( $last_at ) ) / 60 ) : null;
			$checks['queue'] = [
				'status'  => 'ok',
				'label'   => __( 'Queue', 'tsh-whatsapp-notify' ),
				'message' => $last_at
					/* translators: %d: minutes since last run */
					? sprintf( __( 'Last run %d min ago.', 'tsh-whatsapp-notify' ), $age_minutes ?? 0 )
					: __( 'No items processed yet.', 'tsh-whatsapp-notify' ),
			];
		}

		// --- Cron health ---------------------------------------------------.
		$next_run = wp_next_scheduled( Scheduler::HOOK_PROCESS_QUEUE );
		if ( false === $next_run ) {
			$checks['cron'] = [
				'status'  => 'error',
				'label'   => __( 'Cron', 'tsh-whatsapp-notify' ),
				'message' => __( 'tsh_wa_process_queue is not scheduled!', 'tsh-whatsapp-notify' ),
			];
		} else {
			$seconds_until = $next_run - time();
			// Flag as warning if cron is more than 5 min late.
			$status = $seconds_until > 600 ? 'warning' : 'ok';
			$checks['cron'] = [
				'status'  => $status,
				'label'   => __( 'Cron', 'tsh-whatsapp-notify' ),
				'message' => $seconds_until > 0
					/* translators: %d: seconds until next run */
					? sprintf( __( 'Next run in %d s.', 'tsh-whatsapp-notify' ), $seconds_until )
					: __( 'Due to run now.', 'tsh-whatsapp-notify' ),
			];
		}

		// --- API health ----------------------------------------------------.
		$api_health = ( new HealthMonitor() )->get_dashboard_status();
		$checks['api'] = [
			'status'  => $api_health['success'] ? 'ok' : 'error',
			'label'   => __( 'WhatsApp API', 'tsh-whatsapp-notify' ),
			'message' => $api_health['success']
				? __( 'Connected.', 'tsh-whatsapp-notify' )
				: ( $api_health['message'] ?? __( 'Not connected.', 'tsh-whatsapp-notify' ) ),
		];

		// --- Database health -----------------------------------------------.
		$checks['database'] = $this->check_database_health();

		return $checks;
	}

	// -------------------------------------------------------------------------
	// Individual metrics
	// -------------------------------------------------------------------------

	/**
	 * Item counts keyed by queue status.
	 *
	 * @return array<string, int>
	 */
	public function get_counts(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'tsh_wa_queue';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT status, COUNT(*) AS cnt FROM `{$table}` GROUP BY status"
		);

		$counts = array_fill_keys( Queue::ALL_STATUSES, 0 );
		foreach ( (array) $rows as $row ) {
			if ( isset( $counts[ $row->status ] ) ) {
				$counts[ $row->status ] = (int) $row->cnt;
			}
		}

		return $counts;
	}

	/**
	 * Count items permanently failed (dead letter): failed AND attempts >= max_attempts.
	 */
	public function get_dead_letter_count(): int {
		global $wpdb;

		$table = $wpdb->prefix . 'tsh_wa_queue';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM `{$table}` WHERE status = 'failed' AND attempts >= max_attempts"
		);
	}

	/**
	 * Count items currently in the retrying state (pending + retry_after in the future).
	 */
	public function get_retrying_count(): int {
		global $wpdb;

		$table = $wpdb->prefix . 'tsh_wa_queue';
		$now   = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table}` WHERE status = 'pending' AND retry_after IS NOT NULL AND retry_after > %s",
				$now
			)
		);
	}

	/**
	 * Count items stuck in "processing" state for more than 10 minutes.
	 */
	public function get_stuck_count(): int {
		global $wpdb;

		$table = $wpdb->prefix . 'tsh_wa_queue';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM `{$table}` WHERE status = 'processing' AND updated_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)"
		);
	}

	/**
	 * Average API latency in milliseconds for successful sends (last 24 hours).
	 */
	public function get_avg_latency_ms(): float {
		global $wpdb;

		$table = $wpdb->prefix . 'tsh_wa_api_requests';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->get_var(
			"SELECT AVG(latency_ms) FROM `{$table}` WHERE success = 1 AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
		);

		return $result ? round( (float) $result, 1 ) : 0.0;
	}

	/**
	 * Average time from queue insertion to processed_at, for sent items today.
	 * Returns milliseconds.
	 */
	public function get_avg_process_time_ms(): float {
		global $wpdb;

		$table = $wpdb->prefix . 'tsh_wa_queue';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->get_var(
			"SELECT AVG(TIMESTAMPDIFF(SECOND, created_at, processed_at)) * 1000
			 FROM `{$table}`
			 WHERE status = 'sent'
			   AND processed_at IS NOT NULL
			   AND DATE(processed_at) = CURDATE()"
		);

		return $result ? round( (float) $result, 1 ) : 0.0;
	}

	/**
	 * Number of messages sent in the last 60 minutes (throughput).
	 */
	public function get_hourly_throughput(): int {
		global $wpdb;

		$table = $wpdb->prefix . 'tsh_wa_queue';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM `{$table}` WHERE status = 'sent' AND processed_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
		);
	}

	/**
	 * Total sent items today.
	 */
	public function get_sent_today(): int {
		global $wpdb;

		$table = $wpdb->prefix . 'tsh_wa_queue';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM `{$table}` WHERE status = 'sent' AND DATE(processed_at) = CURDATE()"
		);
	}

	/**
	 * Total permanently failed items today.
	 */
	public function get_failed_today(): int {
		global $wpdb;

		$table = $wpdb->prefix . 'tsh_wa_queue';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM `{$table}` WHERE status = 'failed' AND DATE(processed_at) = CURDATE()"
		);
	}

	/**
	 * MySQL datetime of the most recently processed (sent or failed) item.
	 *
	 * @return string|null
	 */
	public function get_last_processed_at(): ?string {
		global $wpdb;

		$table = $wpdb->prefix . 'tsh_wa_queue';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$value = $wpdb->get_var(
			"SELECT MAX(processed_at) FROM `{$table}` WHERE processed_at IS NOT NULL"
		);

		return $value ?: null;
	}

	/**
	 * Return recent worker log entries.
	 *
	 * @param int $limit
	 * @return array<int, object>
	 */
	public function get_worker_history( int $limit = 10 ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'tsh_wa_worker_log';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM `{$table}` ORDER BY started_at DESC LIMIT %d",
				$limit
			)
		) ?: [];
	}

	// -------------------------------------------------------------------------
	// State helpers
	// -------------------------------------------------------------------------

	/**
	 * Return true if the queue has been manually paused via the dashboard.
	 */
	public function is_paused(): bool {
		return (bool) get_option( 'tsh_wa_queue_paused', false );
	}

	/**
	 * Return true if queue processing is enabled in settings.
	 */
	public function is_queue_enabled(): bool {
		$settings = get_option( 'tsh_wa_queue_settings', [] );
		return ! empty( $settings['queue_enabled'] ) && '1' === (string) $settings['queue_enabled'];
	}

	// -------------------------------------------------------------------------
	// Database health check
	// -------------------------------------------------------------------------

	/**
	 * @return array<string, string|bool>
	 */
	private function check_database_health(): array {
		global $wpdb;

		$required_tables = [
			$wpdb->prefix . 'tsh_wa_queue',
			$wpdb->prefix . 'tsh_wa_logs',
			$wpdb->prefix . 'tsh_wa_notifications',
			$wpdb->prefix . 'tsh_wa_delivery_events',
		];

		$missing = [];
		foreach ( $required_tables as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );
			if ( null === $exists ) {
				$short = str_replace( $wpdb->prefix, '', $table );
				$missing[] = $short;
			}
		}

		if ( ! empty( $missing ) ) {
			return [
				'status'  => 'error',
				'label'   => __( 'Database', 'tsh-whatsapp-notify' ),
				'message' => sprintf(
					/* translators: %s: comma-separated table names */
					__( 'Missing tables: %s', 'tsh-whatsapp-notify' ),
					implode( ', ', $missing )
				),
			];
		}

		return [
			'status'  => 'ok',
			'label'   => __( 'Database', 'tsh-whatsapp-notify' ),
			'message' => __( 'All tables present.', 'tsh-whatsapp-notify' ),
		];
	}
}
