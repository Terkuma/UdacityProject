<?php
/**
 * Campaign manager — high-level campaign lifecycle operations.
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
 * Class CampaignManager
 *
 * Provides the public API for the Marketing Engine:
 *   create, update, delete, duplicate, launch, pause, resume, cancel, archive.
 *
 * All business-logic rules live here; the repository is pure data access.
 */
final class CampaignManager {

	private CampaignRepository $repo;
	private CampaignRunner     $runner;
	private CampaignValidator  $validator;
	private CampaignLogger     $logger;
	private BroadcastEngine    $broadcast;
	private AudienceBuilder    $audience;

	public function __construct(
		CampaignRepository $repo,
		CampaignRunner     $runner,
		CampaignValidator  $validator,
		CampaignLogger     $logger,
		BroadcastEngine    $broadcast,
		AudienceBuilder    $audience
	) {
		$this->repo      = $repo;
		$this->runner    = $runner;
		$this->validator = $validator;
		$this->logger    = $logger;
		$this->broadcast = $broadcast;
		$this->audience  = $audience;
	}

	// -------------------------------------------------------------------------
	// CRUD
	// -------------------------------------------------------------------------

	/**
	 * Create a new campaign.
	 *
	 * @param array<string, mixed> $data
	 * @return array{ success: bool, campaign_id: int, errors: array<string> }
	 */
	public function create( array $data ): array {
		$validation = $this->validator->validate( $data );

		// Campaigns in draft status may skip template requirement.
		$status = $data['status'] ?? CampaignRepository::STATUS_DRAFT;
		if ( CampaignRepository::STATUS_DRAFT === $status ) {
			$validation['valid']  = true;
			$validation['errors'] = [];
		}

		if ( ! $validation['valid'] ) {
			return [ 'success' => false, 'campaign_id' => 0, 'errors' => $validation['errors'] ];
		}

		$id = $this->repo->create_campaign( $data );

		if ( ! $id ) {
			return [ 'success' => false, 'campaign_id' => 0, 'errors' => [ __( 'Database insert failed.', 'tsh-whatsapp-notify' ) ] ];
		}

		$this->logger->info( $id, 0, 'Campaign created.' );

		return [ 'success' => true, 'campaign_id' => $id, 'errors' => [] ];
	}

	/**
	 * Update campaign data.
	 *
	 * @param int                  $campaign_id
	 * @param array<string, mixed> $data
	 * @return array{ success: bool, errors: array<string> }
	 */
	public function update( int $campaign_id, array $data ): array {
		$campaign = $this->repo->get_campaign( $campaign_id );

		if ( ! $campaign ) {
			return [ 'success' => false, 'errors' => [ __( 'Campaign not found.', 'tsh-whatsapp-notify' ) ] ];
		}

		// Cannot edit running campaigns (except status).
		if ( CampaignRepository::STATUS_RUNNING === $campaign['status'] && ! isset( $data['status'] ) ) {
			return [ 'success' => false, 'errors' => [ __( 'Cannot edit a running campaign. Pause it first.', 'tsh-whatsapp-notify' ) ] ];
		}

		$ok = $this->repo->update_campaign( $campaign_id, $data );
		$this->logger->info( $campaign_id, 0, 'Campaign updated.' );

		return [ 'success' => $ok, 'errors' => [] ];
	}

	/**
	 * Delete a campaign and all related data.
	 *
	 * @param int $campaign_id
	 * @return bool
	 */
	public function delete( int $campaign_id ): bool {
		$campaign = $this->repo->get_campaign( $campaign_id );

		if ( ! $campaign ) {
			return false;
		}

		if ( CampaignRepository::STATUS_RUNNING === $campaign['status'] ) {
			$this->cancel( $campaign_id );
		}

		return $this->repo->delete_campaign( $campaign_id );
	}

	/**
	 * Duplicate an existing campaign as a draft.
	 *
	 * @param int $campaign_id
	 * @return int|false  New campaign ID or false.
	 */
	public function duplicate( int $campaign_id ): int|false {
		$original = $this->repo->get_campaign( $campaign_id );

		if ( ! $original ) {
			return false;
		}

		$copy = $original;
		unset( $copy['id'], $copy['created_at'], $copy['updated_at'],
			   $copy['sent_at'], $copy['completed_at'],
			   $copy['total_audience'], $copy['total_sent'], $copy['total_delivered'],
			   $copy['total_read'], $copy['total_failed'], $copy['total_coupons'] );

		$copy['name']   = $original['name'] . ' ' . __( '(Copy)', 'tsh-whatsapp-notify' );
		$copy['status'] = CampaignRepository::STATUS_DRAFT;

		$new_id = $this->repo->create_campaign( $copy );
		if ( $new_id ) {
			$this->logger->info( $new_id, 0, "Duplicated from campaign #{$campaign_id}." );
		}

		return $new_id ?: false;
	}

	// -------------------------------------------------------------------------
	// Lifecycle operations
	// -------------------------------------------------------------------------

	/**
	 * Launch a campaign immediately or schedule for later.
	 *
	 * @param int  $campaign_id
	 * @param bool $schedule_only  If true, sets status to 'scheduled' without running now.
	 * @return array{ success: bool, run_id: int, error: string }
	 */
	public function launch( int $campaign_id, bool $schedule_only = false ): array {
		$campaign = $this->repo->get_campaign( $campaign_id );

		if ( ! $campaign ) {
			return [ 'success' => false, 'run_id' => 0, 'error' => __( 'Campaign not found.', 'tsh-whatsapp-notify' ) ];
		}

		if ( $schedule_only ) {
			$this->repo->update_campaign( $campaign_id, [ 'status' => CampaignRepository::STATUS_SCHEDULED ] );
			$this->logger->info( $campaign_id, 0, 'Campaign scheduled.' );

			return [ 'success' => true, 'run_id' => 0, 'error' => '' ];
		}

		return $this->runner->launch( $campaign_id );
	}

	/**
	 * Pause a running campaign.
	 *
	 * @param int $campaign_id
	 * @return bool
	 */
	public function pause( int $campaign_id ): bool {
		$campaign = $this->repo->get_campaign( $campaign_id );

		if ( ! $campaign || CampaignRepository::STATUS_RUNNING !== $campaign['status'] ) {
			return false;
		}

		$run = $this->repo->get_latest_run( $campaign_id );

		if ( $run ) {
			return $this->broadcast->pause( $campaign_id, (int) $run['id'] );
		}

		return (bool) $this->repo->update_campaign( $campaign_id, [ 'status' => CampaignRepository::STATUS_PAUSED ] );
	}

	/**
	 * Resume a paused campaign.
	 *
	 * @param int $campaign_id
	 * @return bool
	 */
	public function resume( int $campaign_id ): bool {
		$campaign = $this->repo->get_campaign( $campaign_id );

		if ( ! $campaign || CampaignRepository::STATUS_PAUSED !== $campaign['status'] ) {
			return false;
		}

		$run = $this->repo->get_latest_run( $campaign_id );

		if ( $run && CampaignRepository::RUN_STATUS_PAUSED === $run['status'] ) {
			return $this->broadcast->resume( $campaign_id, (int) $run['id'] );
		}

		// No existing run — re-launch from scratch.
		return $this->runner->launch( $campaign_id )['success'];
	}

	/**
	 * Cancel a running or paused campaign.
	 *
	 * @param int $campaign_id
	 * @return bool
	 */
	public function cancel( int $campaign_id ): bool {
		$campaign = $this->repo->get_campaign( $campaign_id );

		if ( ! $campaign ) {
			return false;
		}

		$run = $this->repo->get_latest_run( $campaign_id );

		if ( $run ) {
			$this->repo->update_run( (int) $run['id'], [
				'status'       => CampaignRepository::RUN_STATUS_FAILED,
				'completed_at' => current_time( 'mysql' ),
				'error_message'=> 'Cancelled by user.',
			] );
		}

		$ok = $this->repo->update_campaign( $campaign_id, [ 'status' => CampaignRepository::STATUS_CANCELLED ] );
		$this->logger->info( $campaign_id, $run['id'] ?? 0, 'Campaign cancelled by user.' );

		// Unschedule any pending cron events for this campaign.
		wp_clear_scheduled_hook( CampaignScheduler::HOOK_RESOLVE_AUDIENCE, [ $campaign_id, $run['id'] ?? 0 ] );
		wp_clear_scheduled_hook( CampaignScheduler::HOOK_PROCESS, [ $campaign_id, $run['id'] ?? 0 ] );

		return $ok;
	}

	/**
	 * Archive a completed or cancelled campaign.
	 *
	 * @param int $campaign_id
	 * @return bool
	 */
	public function archive( int $campaign_id ): bool {
		$campaign = $this->repo->get_campaign( $campaign_id );

		if ( ! $campaign ) {
			return false;
		}

		$ok = $this->repo->update_campaign( $campaign_id, [ 'status' => CampaignRepository::STATUS_ARCHIVED ] );
		$this->logger->info( $campaign_id, 0, 'Campaign archived.' );

		return $ok;
	}

	// -------------------------------------------------------------------------
	// Preview
	// -------------------------------------------------------------------------

	/**
	 * Generate a campaign preview (audience count, estimated duration, queue size).
	 *
	 * @param array<string, mixed> $campaign  Decoded campaign or unsaved data.
	 * @return array<string, mixed>
	 */
	public function preview( array $campaign ): array {
		$audience_config = $campaign['audience_config'] ?? [];
		$throttle        = $campaign['throttle_config'] ?? [];

		$count           = $this->audience->estimate_count( $audience_config );
		$msgs_per_minute = max( 1, (int) ( $throttle['msgs_per_minute'] ?? 30 ) );
		$duration_secs   = (int) ceil( $count / ( $msgs_per_minute / 60 ) );
		$duration_mins   = (int) ceil( $duration_secs / 60 );

		return [
			'audience_count'    => $count,
			'estimated_minutes' => $duration_mins,
			'msgs_per_minute'   => $msgs_per_minute,
			'has_ab_test'       => ! empty( $campaign['template_b_id'] ),
			'has_coupons'       => ! empty( $campaign['coupon_config']['enabled'] ),
		];
	}
}
