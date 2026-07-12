<?php
/**
 * Campaign scheduler — WP Cron integration for the marketing engine.
 *
 * @package TSH\WhatsAppNotify\Marketing
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Marketing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TSH\WhatsAppNotify\Queue\Queue;

/**
 * Class CampaignScheduler
 *
 * Registers WordPress cron hooks:
 *  - HOOK_RESOLVE_AUDIENCE : resolve audience and save to DB
 *  - HOOK_PROCESS          : load a slice of the audience into the core queue
 *  - HOOK_SEND_SCHEDULED   : fire campaigns whose send_at has arrived
 *  - HOOK_PRUNE            : prune logs and completed-run data
 *  - HOOK_RECURRING        : check recurring campaigns
 */
final class CampaignScheduler {

	public const HOOK_RESOLVE_AUDIENCE = 'tsh_wa_campaign_resolve_audience';
	public const HOOK_PROCESS          = 'tsh_wa_campaign_process';
	public const HOOK_SEND_SCHEDULED   = 'tsh_wa_campaign_send_scheduled';
	public const HOOK_PRUNE            = 'tsh_wa_campaign_prune';
	public const HOOK_RECURRING        = 'tsh_wa_campaign_check_recurring';

	private CampaignRunner     $runner;
	private CampaignRepository $repo;

	public function __construct( CampaignRunner $runner, CampaignRepository $repo ) {
		$this->runner = $runner;
		$this->repo   = $repo;
	}

	// -------------------------------------------------------------------------
	// Hook registration
	// -------------------------------------------------------------------------

	/**
	 * Register all cron action hooks and schedule recurring events.
	 */
	public function register_hooks(): void {
		add_action( self::HOOK_RESOLVE_AUDIENCE, [ $this, 'handle_resolve_audience' ], 10, 2 );
		add_action( self::HOOK_PROCESS,          [ $this, 'handle_process' ],          10, 2 );
		add_action( self::HOOK_SEND_SCHEDULED,   [ $this, 'handle_send_scheduled' ] );
		add_action( self::HOOK_PRUNE,            [ $this, 'handle_prune' ] );
		add_action( self::HOOK_RECURRING,        [ $this, 'handle_recurring' ] );

		// Schedule periodic events if not already registered.
		if ( ! wp_next_scheduled( self::HOOK_SEND_SCHEDULED ) ) {
			wp_schedule_event( time() + 60, 'every_five_minutes', self::HOOK_SEND_SCHEDULED );
		}

		if ( ! wp_next_scheduled( self::HOOK_PRUNE ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::HOOK_PRUNE );
		}

		if ( ! wp_next_scheduled( self::HOOK_RECURRING ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::HOOK_RECURRING );
		}
	}

	// -------------------------------------------------------------------------
	// Cron callbacks
	// -------------------------------------------------------------------------

	/**
	 * Stage 1: resolve audience and persist to the audience table.
	 *
	 * @param int $campaign_id
	 * @param int $run_id
	 */
	public function handle_resolve_audience( int $campaign_id, int $run_id ): void {
		$this->runner->run_audience_resolution( $campaign_id, $run_id );
	}

	/**
	 * Stage 2: load a batch of audience rows into the core queue.
	 *
	 * @param int $campaign_id
	 * @param int $run_id
	 */
	public function handle_process( int $campaign_id, int $run_id ): void {
		$this->runner->run_queue_loading( $campaign_id, $run_id );
	}

	/**
	 * Check for scheduled campaigns whose send_at time has arrived and launch them.
	 */
	public function handle_send_scheduled(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'tsh_wa_campaigns';
		$now   = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$due = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id FROM `{$table}`
				 WHERE status = %s
				   AND send_at IS NOT NULL
				   AND send_at <= %s",
				CampaignRepository::STATUS_SCHEDULED,
				$now
			),
			ARRAY_A
		);

		foreach ( (array) $due as $row ) {
			$this->runner->launch( (int) $row['id'] );
		}
	}

	/**
	 * Prune old log entries and stale run data.
	 */
	public function handle_prune(): void {
		$settings    = get_option( 'tsh_wa_marketing_settings', [] );
		$log_days    = absint( $settings['log_retention_days'] ?? 30 );
		$pruned      = $this->repo->prune_logs( $log_days );

		// Clean up very old completed runs.
		$run_days    = absint( $settings['run_retention_days'] ?? 90 );
		global $wpdb;

		$table = $wpdb->prefix . 'tsh_wa_campaign_runs';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$table}` WHERE status = 'completed' AND completed_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
				$run_days
			)
		);
	}

	/**
	 * Check recurring campaigns and launch any that are due.
	 */
	public function handle_recurring(): void {
		$result = $this->repo->get_campaigns( [
			'type'     => CampaignRepository::TYPE_RECURRING,
			'status'   => CampaignRepository::STATUS_SCHEDULED,
			'per_page' => 200,
		] );

		foreach ( $result['rows'] as $campaign ) {
			$this->runner->maybe_run_recurring( (int) $campaign['id'] );
		}
	}
}
