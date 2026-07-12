<?php
/**
 * Campaign runner — orchestrates a single campaign execution lifecycle.
 *
 * @package TSH\WhatsAppNotify\Marketing
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Marketing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CampaignRunner
 *
 * Entry point called by CampaignScheduler for each cron event.
 *
 * Execution is split into two stages:
 *   Stage 1 (AUDIENCE): Resolve the full audience and persist to DB.
 *   Stage 2 (QUEUE):    Load batches of audience members into the core queue.
 *
 * Stage 2 is resumable — if the cron slice times out, the offset is saved and
 * the next cron event continues where it left off.
 */
final class CampaignRunner {

	private CampaignRepository $repo;
	private BroadcastEngine    $broadcast;
	private CampaignValidator  $validator;
	private CampaignLogger     $logger;

	public function __construct(
		CampaignRepository $repo,
		BroadcastEngine    $broadcast,
		CampaignValidator  $validator,
		CampaignLogger     $logger
	) {
		$this->repo      = $repo;
		$this->broadcast = $broadcast;
		$this->validator = $validator;
		$this->logger    = $logger;
	}

	// -------------------------------------------------------------------------
	// Public: launch a campaign
	// -------------------------------------------------------------------------

	/**
	 * Launch a campaign: validate → create run → schedule audience resolution.
	 *
	 * @param int $campaign_id
	 * @return array{ success: bool, run_id: int, error: string }
	 */
	public function launch( int $campaign_id ): array {
		$campaign = $this->repo->get_campaign( $campaign_id );

		if ( ! $campaign ) {
			return [ 'success' => false, 'run_id' => 0, 'error' => __( 'Campaign not found.', 'tsh-whatsapp-notify' ) ];
		}

		$validation = $this->validator->validate_for_launch( $campaign );

		if ( ! $validation['valid'] ) {
			return [
				'success' => false,
				'run_id'  => 0,
				'error'   => implode( ' ', $validation['errors'] ),
			];
		}

		// Create the run record.
		$run_id = $this->repo->create_run( $campaign_id, [ 'stage' => 'audience' ] );

		if ( ! $run_id ) {
			return [ 'success' => false, 'run_id' => 0, 'error' => __( 'Failed to create campaign run.', 'tsh-whatsapp-notify' ) ];
		}

		// Mark campaign as running.
		$this->repo->update_campaign( $campaign_id, [
			'status'  => CampaignRepository::STATUS_RUNNING,
			'sent_at' => current_time( 'mysql' ),
		] );
		$this->repo->update_run( $run_id, [ 'status' => CampaignRepository::RUN_STATUS_RUNNING ] );

		$this->logger->info( $campaign_id, $run_id, 'Campaign launched, scheduling audience resolution.' );

		// Schedule Stage 1 immediately.
		wp_schedule_single_event(
			time() + 5,
			CampaignScheduler::HOOK_RESOLVE_AUDIENCE,
			[ $campaign_id, $run_id ]
		);

		return [ 'success' => true, 'run_id' => $run_id, 'error' => '' ];
	}

	// -------------------------------------------------------------------------
	// Public: stage 1 — audience resolution (called by cron)
	// -------------------------------------------------------------------------

	/**
	 * Cron callback for HOOK_RESOLVE_AUDIENCE.
	 *
	 * @param int $campaign_id
	 * @param int $run_id
	 */
	public function run_audience_resolution( int $campaign_id, int $run_id ): void {
		$campaign = $this->repo->get_campaign( $campaign_id );
		$run      = $this->repo->get_run( $run_id );

		if ( ! $campaign || ! $run ) {
			return;
		}

		// Guard: paused or cancelled campaigns must not proceed.
		if ( in_array( $campaign['status'], [
			CampaignRepository::STATUS_PAUSED,
			CampaignRepository::STATUS_CANCELLED,
			CampaignRepository::STATUS_ARCHIVED,
		], true ) ) {
			$this->logger->warning( $campaign_id, $run_id, 'Audience resolution skipped — campaign is not running.' );
			return;
		}

		try {
			$total = $this->broadcast->resolve_audience( $campaign, $run_id );

			// Advance to Stage 2 — queue loading.
			$this->repo->update_run( $run_id, [
				'context' => array_merge( $run['context'] ?? [], [ 'stage' => 'queue', 'audience_total' => $total ] ),
			] );

			$this->logger->info( $campaign_id, $run_id, "Audience resolved ({$total}). Scheduling queue load." );

			wp_schedule_single_event(
				time() + 10,
				CampaignScheduler::HOOK_PROCESS,
				[ $campaign_id, $run_id ]
			);
		} catch ( \Throwable $e ) {
			$this->fail_run( $campaign_id, $run_id, $e->getMessage() );
		}
	}

	// -------------------------------------------------------------------------
	// Public: stage 2 — queue loading (called by cron, resumable)
	// -------------------------------------------------------------------------

	/**
	 * Cron callback for HOOK_PROCESS.
	 *
	 * @param int $campaign_id
	 * @param int $run_id
	 */
	public function run_queue_loading( int $campaign_id, int $run_id ): void {
		$campaign = $this->repo->get_campaign( $campaign_id );
		$run      = $this->repo->get_run( $run_id );

		if ( ! $campaign || ! $run ) {
			return;
		}

		if ( in_array( $campaign['status'], [
			CampaignRepository::STATUS_PAUSED,
			CampaignRepository::STATUS_CANCELLED,
		], true ) ) {
			$this->logger->info( $campaign_id, $run_id, 'Queue loading deferred — campaign is paused or cancelled.' );
			return;
		}

		try {
			$result = $this->broadcast->load_queue_slice( $campaign, $run );

			if ( ! $result['done'] ) {
				// Schedule next slice in 30 s.
				wp_schedule_single_event(
					time() + 30,
					CampaignScheduler::HOOK_PROCESS,
					[ $campaign_id, $run_id ]
				);
			}

			// Update coupon total on the campaign.
			if ( $result['coupon_count'] > 0 ) {
				$existing = (int) ( $campaign['total_coupons'] ?? 0 );
				$this->repo->update_campaign( $campaign_id, [ 'total_coupons' => $existing + $result['coupon_count'] ] );
			}
		} catch ( \Throwable $e ) {
			$this->fail_run( $campaign_id, $run_id, $e->getMessage() );
		}
	}

	// -------------------------------------------------------------------------
	// Recurring campaigns
	// -------------------------------------------------------------------------

	/**
	 * Trigger a recurring campaign run if its schedule is due.
	 *
	 * @param int $campaign_id
	 */
	public function maybe_run_recurring( int $campaign_id ): void {
		$campaign = $this->repo->get_campaign( $campaign_id );

		if ( ! $campaign || CampaignRepository::TYPE_RECURRING !== $campaign['type'] ) {
			return;
		}

		$schedule_config = $campaign['schedule_config'] ?? [];
		if ( $this->is_schedule_due( $schedule_config ) ) {
			$this->launch( $campaign_id );
		}
	}

	// -------------------------------------------------------------------------
	// Failure handling
	// -------------------------------------------------------------------------

	/**
	 * Mark a run and campaign as failed.
	 *
	 * @param int    $campaign_id
	 * @param int    $run_id
	 * @param string $error
	 */
	private function fail_run( int $campaign_id, int $run_id, string $error ): void {
		$this->repo->update_run( $run_id, [
			'status'        => CampaignRepository::RUN_STATUS_FAILED,
			'error_message' => $error,
			'completed_at'  => current_time( 'mysql' ),
		] );

		$this->repo->update_campaign( $campaign_id, [
			'status' => CampaignRepository::STATUS_FAILED,
		] );

		$this->logger->error( $campaign_id, $run_id, "Campaign failed: {$error}" );

		do_action( 'tsh_wa_campaign_failed', $campaign_id, $run_id, $error );
	}

	// -------------------------------------------------------------------------
	// Schedule check helper
	// -------------------------------------------------------------------------

	/**
	 * Determine if a recurring schedule is currently due.
	 *
	 * @param array<string, mixed> $config
	 * @return bool
	 */
	private function is_schedule_due( array $config ): bool {
		$recurrence = $config['recurrence'] ?? 'weekly';
		$time       = $config['time']       ?? '09:00';
		$tz_label   = $config['timezone']   ?? 'site';

		try {
			$tz  = 'site' === $tz_label
				? new \DateTimeZone( wp_timezone_string() )
				: new \DateTimeZone( $tz_label );
		} catch ( \Exception $e ) {
			$tz = new \DateTimeZone( 'UTC' );
		}

		$now = new \DateTime( 'now', $tz );

		[ $hour, $minute ] = array_map( 'intval', explode( ':', $time . ':0' ) );

		$due = clone $now;
		$due->setTime( $hour, $minute, 0 );

		// Allow a 5-minute window either side of the scheduled time.
		$diff_mins = abs( ( $now->getTimestamp() - $due->getTimestamp() ) / 60 );
		if ( $diff_mins > 5 ) {
			return false;
		}

		switch ( $recurrence ) {
			case 'daily':
				return true;

			case 'weekly':
				$day = (int) ( $config['day_of_week'] ?? 1 );   // 0=Sun … 6=Sat
				return (int) $now->format( 'w' ) === $day;

			case 'monthly':
				$dom = (int) ( $config['day_of_month'] ?? 1 );
				return (int) $now->format( 'j' ) === $dom;

			default:
				return false;
		}
	}
}
