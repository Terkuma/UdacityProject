<?php
/**
 * Broadcast engine — orchestrates chunked audience resolution + queue loading.
 *
 * @package TSH\WhatsAppNotify\Marketing
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Marketing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BroadcastEngine
 *
 * Handles all large-scale broadcast operations:
 *  - Resolves audience in streaming chunks.
 *  - Assigns A/B template variants.
 *  - Persists audience members to tsh_wa_campaign_audience.
 *  - Calls CampaignQueue to push items to the core queue.
 *  - Emits progress signals for the scheduler/runner.
 *
 * Designed to be called repeatedly in short-lived cron slices so that even
 * 100,000-recipient campaigns never time out.
 */
final class BroadcastEngine {

	/** Audience rows per processing slice. */
	private const AUDIENCE_CHUNK  = 200;
	/** Queue rows per enqueue batch. */
	private const QUEUE_CHUNK     = 100;
	/** Maximum wall-clock seconds per cron slice. */
	private const MAX_SLICE_SECS  = 25;

	private AudienceBuilder    $audience_builder;
	private CampaignQueue      $campaign_queue;
	private CampaignRepository $repo;
	private CampaignLogger     $logger;

	public function __construct(
		AudienceBuilder    $audience_builder,
		CampaignQueue      $campaign_queue,
		CampaignRepository $repo,
		CampaignLogger     $logger
	) {
		$this->audience_builder = $audience_builder;
		$this->campaign_queue   = $campaign_queue;
		$this->repo             = $repo;
		$this->logger           = $logger;
	}

	// -------------------------------------------------------------------------
	// Phase 1 — Audience Resolution
	// -------------------------------------------------------------------------

	/**
	 * Resolve the full audience and persist to tsh_wa_campaign_audience.
	 *
	 * Sets run status to 'running' after completion.
	 *
	 * @param array<string, mixed> $campaign Decoded campaign row.
	 * @param int                  $run_id
	 * @return int  Total audience size.
	 */
	public function resolve_audience( array $campaign, int $run_id ): int {
		$campaign_id    = (int) $campaign['id'];
		$audience_config = $campaign['audience_config'] ?? [];
		$ab_split_ratio  = (int) ( $campaign['ab_split_ratio'] ?? 50 );  // % going to template_b
		$has_ab_test     = ! empty( $campaign['template_b_id'] );

		$total = 0;
		$index = 0;

		$this->audience_builder->resolve(
			$audience_config,
			function ( array $members ) use ( $campaign_id, $run_id, $has_ab_test, $ab_split_ratio, &$total, &$index ) {
				// Assign A/B variants.
				foreach ( $members as &$m ) {
					if ( $has_ab_test ) {
						$pct              = ( $index % 100 );
						$m['template_variant'] = ( $pct < $ab_split_ratio ) ? 'b' : 'a';
					} else {
						$m['template_variant'] = 'a';
					}
					$m['status'] = 'pending';
					++$index;
				}
				unset( $m );

				$this->repo->insert_audience_batch( $campaign_id, $run_id, $members );
				$total += count( $members );
			}
		);

		// Update campaign total_audience count.
		$this->repo->update_campaign( $campaign_id, [ 'total_audience' => $total ] );
		$this->repo->update_run( $run_id, [ 'total_queued' => $total ] );

		$this->logger->info( $campaign_id, $run_id, sprintf( 'Audience resolved: %d recipients.', $total ) );

		return $total;
	}

	// -------------------------------------------------------------------------
	// Phase 2 — Queue Loading (resumable)
	// -------------------------------------------------------------------------

	/**
	 * Load a batch of pending audience members into the queue.
	 *
	 * Designed to be called in successive cron slices.  Each call picks up
	 * where the previous left off using the stored batch_offset.
	 *
	 * @param array<string, mixed> $campaign  Decoded campaign row.
	 * @param array<string, mixed> $run       Decoded run row.
	 * @return array{ queued: int, failed: int, coupon_count: int, done: bool }
	 */
	public function load_queue_slice( array $campaign, array $run ): array {
		$campaign_id  = (int) $campaign['id'];
		$run_id       = (int) $run['id'];
		$batch_offset = (int) ( $run['batch_offset'] ?? 0 );

		$slice_queued  = 0;
		$slice_failed  = 0;
		$coupon_count  = 0;
		$start_time    = microtime( true );

		while ( true ) {
			// Time-budget check.
			if ( ( microtime( true ) - $start_time ) >= self::MAX_SLICE_SECS ) {
				break;
			}

			$members = $this->repo->get_audience_batch( $run_id, 'pending', self::QUEUE_CHUNK, $batch_offset );

			if ( empty( $members ) ) {
				// All members have been processed — run is complete.
				$this->repo->update_run( $run_id, [
					'status'       => CampaignRepository::RUN_STATUS_COMPLETED,
					'completed_at' => current_time( 'mysql' ),
					'batch_offset' => $batch_offset,
				] );

				$this->finalise_campaign( $campaign_id, $run_id );

				return [
					'queued'       => $slice_queued,
					'failed'       => $slice_failed,
					'coupon_count' => $coupon_count,
					'done'         => true,
				];
			}

			$result = $this->campaign_queue->enqueue_batch(
				$campaign_id,
				$run_id,
				$members,
				$campaign,
				$this->repo
			);

			$slice_queued += $result['queued'];
			$slice_failed += $result['failed'];
			$coupon_count += $result['coupon_count'];
			$batch_offset += count( $members );
		}

		// Save progress — next cron slice will resume from batch_offset.
		$this->repo->update_run( $run_id, [
			'batch_offset' => $batch_offset,
		] );

		$this->repo->update_campaign( $campaign_id, [
			'total_sent'    => $batch_offset,
		] );

		$this->logger->info(
			$campaign_id,
			$run_id,
			sprintf( 'Slice: queued %d, failed %d, offset now %d.', $slice_queued, $slice_failed, $batch_offset )
		);

		return [
			'queued'       => $slice_queued,
			'failed'       => $slice_failed,
			'coupon_count' => $coupon_count,
			'done'         => false,
		];
	}

	// -------------------------------------------------------------------------
	// Pause / Resume
	// -------------------------------------------------------------------------

	/**
	 * Pause a running campaign.
	 *
	 * @param int $campaign_id
	 * @param int $run_id
	 * @return bool
	 */
	public function pause( int $campaign_id, int $run_id ): bool {
		$this->repo->update_run( $run_id, [ 'status' => CampaignRepository::RUN_STATUS_PAUSED ] );
		$ok = $this->repo->update_campaign( $campaign_id, [ 'status' => CampaignRepository::STATUS_PAUSED ] );

		$this->logger->info( $campaign_id, $run_id, 'Campaign paused by user.' );

		return $ok;
	}

	/**
	 * Resume a paused campaign — marks run + campaign as running again.
	 *
	 * @param int $campaign_id
	 * @param int $run_id
	 * @return bool
	 */
	public function resume( int $campaign_id, int $run_id ): bool {
		$this->repo->update_run( $run_id, [ 'status' => CampaignRepository::RUN_STATUS_RUNNING ] );
		$ok = $this->repo->update_campaign( $campaign_id, [ 'status' => CampaignRepository::STATUS_RUNNING ] );

		$this->logger->info( $campaign_id, $run_id, 'Campaign resumed by user.' );

		// Re-schedule the next processing event.
		wp_schedule_single_event( time() + 30, CampaignScheduler::HOOK_PROCESS, [ $campaign_id, $run_id ] );

		return $ok;
	}

	// -------------------------------------------------------------------------
	// Post-run finalisation
	// -------------------------------------------------------------------------

	/**
	 * Update campaign counters and status after queue loading is complete.
	 *
	 * @param int $campaign_id
	 * @param int $run_id
	 */
	private function finalise_campaign( int $campaign_id, int $run_id ): void {
		$stats = $this->repo->get_audience_stats( $run_id );

		$this->repo->update_campaign( $campaign_id, [
			'status'       => CampaignRepository::STATUS_COMPLETED,
			'completed_at' => current_time( 'mysql' ),
			'total_sent'   => $stats['queued'] + $stats['sent'],
			'total_failed' => $stats['failed'],
		] );

		$this->logger->info(
			$campaign_id,
			$run_id,
			sprintf(
				'Campaign complete. Queued: %d | Failed: %d | Skipped: %d.',
				$stats['queued'] + $stats['sent'],
				$stats['failed'],
				$stats['skipped']
			)
		);

		do_action( 'tsh_wa_campaign_completed', $campaign_id, $run_id, $stats );
	}
}
