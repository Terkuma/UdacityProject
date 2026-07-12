<?php
/**
 * Cron scheduler.
 *
 * @package TSH\WhatsAppNotify\Cron
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Cron;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Scheduler
 *
 * Registers and manages all plugin WP-Cron events.
 *
 * Events registered (no processing in Phase 1):
 *  - tsh_wa_process_queue   every minute  — pop & send queued messages
 *  - tsh_wa_retry_failed    every 5 min   — retry failed queue items
 *  - tsh_wa_prune_logs      daily         — delete old log rows
 *  - tsh_wa_health_check    hourly        — verify API connectivity
 *
 * Custom interval 'tsh_wa_every_minute' (60 s) is added to the WP-Cron
 * schedule list so events fire at one-minute resolution.
 */
final class Scheduler {

	// -------------------------------------------------------------------------
	// Hook names
	// -------------------------------------------------------------------------

	public const HOOK_PROCESS_QUEUE           = 'tsh_wa_process_queue';
	public const HOOK_RETRY_FAILED            = 'tsh_wa_retry_failed';
	public const HOOK_PRUNE_LOGS              = 'tsh_wa_prune_logs';
	public const HOOK_HEALTH_CHECK            = 'tsh_wa_health_check';
	public const HOOK_EXPIRE_QUEUE            = 'tsh_wa_expire_queue';           // Phase 4
	public const HOOK_SYNC_TEMPLATES          = 'tsh_wa_sync_templates';          // Phase 5
	public const HOOK_REFRESH_TEMPLATE_QUALITY = 'tsh_wa_refresh_template_quality'; // Phase 5

	/**
	 * Constructor — attaches WP hooks immediately on instantiation.
	 */
	public function __construct() {
		// Register the custom cron intervals.
		add_filter( 'cron_schedules', [ $this, 'add_cron_intervals' ] );

		// Schedule events once WordPress is fully loaded.
		add_action( 'init', [ $this, 'register_events' ] );

		// Callbacks for each cron hook.
		add_action( self::HOOK_PROCESS_QUEUE, [ $this, 'handle_process_queue' ] );
		add_action( self::HOOK_RETRY_FAILED,  [ $this, 'handle_retry_failed'  ] );
		add_action( self::HOOK_PRUNE_LOGS,    [ $this, 'handle_prune_logs'    ] );
		add_action( self::HOOK_HEALTH_CHECK,  [ $this, 'handle_health_check'  ] );
		add_action( self::HOOK_EXPIRE_QUEUE,             [ $this, 'handle_expire_queue'             ] ); // Phase 4
		add_action( self::HOOK_SYNC_TEMPLATES,           [ $this, 'handle_sync_templates'           ] ); // Phase 5
		add_action( self::HOOK_REFRESH_TEMPLATE_QUALITY, [ $this, 'handle_refresh_template_quality' ] ); // Phase 5
	}

	// -------------------------------------------------------------------------
	// Intervals
	// -------------------------------------------------------------------------

	/**
	 * Add custom WP-Cron recurrence intervals.
	 *
	 * @param array<string, array<string, int|string>> $schedules Existing schedules.
	 * @return array<string, array<string, int|string>>
	 */
	public function add_cron_intervals( array $schedules ): array {
		if ( ! isset( $schedules['tsh_wa_every_minute'] ) ) {
			$schedules['tsh_wa_every_minute'] = [
				'interval' => 60,
				'display'  => esc_html__( 'Every Minute', 'tsh-whatsapp-notify' ),
			];
		}

		if ( ! isset( $schedules['tsh_wa_every_five_minutes'] ) ) {
			$schedules['tsh_wa_every_five_minutes'] = [
				'interval' => 300,
				'display'  => esc_html__( 'Every 5 Minutes', 'tsh-whatsapp-notify' ),
			];
		}

		return $schedules;
	}

	// -------------------------------------------------------------------------
	// Registration
	// -------------------------------------------------------------------------

	/**
	 * Register all plugin cron events if they are not already scheduled.
	 */
	public function register_events(): void {
		$events = $this->get_event_definitions();

		foreach ( $events as $hook => $event ) {
			if ( ! wp_next_scheduled( $hook ) ) {
				wp_schedule_event( time(), $event['recurrence'], $hook );
			}
		}
	}

	/**
	 * Unschedule all plugin cron events.
	 * Called by Deactivator::deactivate() and uninstall.php.
	 */
	public static function unregister_all_events(): void {
		$hooks = [
			self::HOOK_PROCESS_QUEUE,
			self::HOOK_RETRY_FAILED,
			self::HOOK_PRUNE_LOGS,
			self::HOOK_HEALTH_CHECK,
			self::HOOK_EXPIRE_QUEUE,
			self::HOOK_SYNC_TEMPLATES,           // Phase 5
			self::HOOK_REFRESH_TEMPLATE_QUALITY, // Phase 5
		];

		foreach ( $hooks as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Return the definition map for all plugin cron events.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function get_event_definitions(): array {
		return [
			self::HOOK_PROCESS_QUEUE => [
				'recurrence' => 'tsh_wa_every_minute',
			],
			self::HOOK_RETRY_FAILED  => [
				'recurrence' => 'tsh_wa_every_five_minutes',
			],
			self::HOOK_PRUNE_LOGS    => [
				'recurrence' => 'daily',
			],
			self::HOOK_HEALTH_CHECK  => [
				'recurrence' => 'hourly',
			],
			self::HOOK_EXPIRE_QUEUE  => [   // Phase 4
				'recurrence' => 'hourly',
			],
			self::HOOK_SYNC_TEMPLATES => [  // Phase 5
				'recurrence' => $this->get_sync_interval(),
			],
			self::HOOK_REFRESH_TEMPLATE_QUALITY => [  // Phase 5
				'recurrence' => 'daily',
			],
		];
	}

	/**
	 * Return the configured template sync recurrence interval.
	 * Falls back to 'hourly' when not configured or invalid.
	 */
	private function get_sync_interval(): string {
		$settings = get_option( 'tsh_wa_sync_settings', [] );
		$interval = $settings['sync_interval'] ?? 'hourly';

		$allowed = [ 'tsh_wa_every_minute', 'tsh_wa_every_five_minutes', 'hourly', 'twicedaily', 'daily' ];
		return in_array( $interval, $allowed, true ) ? $interval : 'hourly';
	}

	// -------------------------------------------------------------------------
	// Cron handlers (stubs — implementations injected in later phases)
	// -------------------------------------------------------------------------

	/**
	 * Handle queue processing cron trigger.
	 * Full implementation: Phase 2 (API layer).
	 */
	public function handle_process_queue(): void {
		/**
		 * Action: tsh_wa_process_queue
		 *
		 * Fired once per minute by WP-Cron. Phase 2 hooks a Queue\Processor
		 * listener here to send pending messages.
		 *
		 * @since 1.0.0
		 */
		do_action( 'tsh_wa_cron_process_queue' );
	}

	/**
	 * Handle failed-item retry cron trigger.
	 * Full implementation: Phase 2 (API layer).
	 */
	public function handle_retry_failed(): void {
		/**
		 * Action: tsh_wa_cron_retry_failed
		 *
		 * Fired every 5 minutes. Phase 2 hooks a retry processor here.
		 *
		 * @since 1.0.0
		 */
		do_action( 'tsh_wa_cron_retry_failed' );
	}

	/**
	 * Handle log pruning cron trigger.
	 */
	public function handle_prune_logs(): void {
		$settings  = get_option( 'tsh_wa_logging_settings', [] );
		$retention = absint( $settings['log_retention'] ?? 30 );

		if ( $retention > 0 ) {
			$logger = new \TSH\WhatsAppNotify\Logger\Logger();
			$pruned = $logger->prune( $retention );

			if ( $pruned > 0 ) {
				$logger->info(
					sprintf(
						/* translators: %d: number of log rows deleted */
						__( 'Log pruning complete — %d entries removed.', 'tsh-whatsapp-notify' ),
						$pruned
					),
					[ 'retention_days' => $retention ],
					'cron'
				);
			}
		}

		do_action( 'tsh_wa_cron_prune_logs' );
	}

	/**
	 * Handle queue-expiry cron trigger — Phase 4.
	 * Delegates to QueueProcessor::expire_stale_items() via the action hook.
	 */
	public function handle_expire_queue(): void {
		do_action( 'tsh_wa_cron_expire_queue' );
	}

	/**
	 * Handle scheduled template sync cron trigger — Phase 5.
	 */
	public function handle_sync_templates(): void {
		do_action( 'tsh_wa_cron_sync_templates' );
	}

	/**
	 * Handle template quality refresh cron trigger — Phase 5.
	 */
	public function handle_refresh_template_quality(): void {
		do_action( 'tsh_wa_cron_refresh_template_quality' );
	}

	/**
	 * Handle API health-check cron trigger.
	 * Full implementation: Phase 2 (API layer).
	 */
	public function handle_health_check(): void {
		/**
		 * Action: tsh_wa_cron_health_check
		 *
		 * Fired hourly. Phase 2 hooks an API health-check here.
		 *
		 * @since 1.0.0
		 */
		do_action( 'tsh_wa_cron_health_check' );
	}

	// -------------------------------------------------------------------------
	// Status helpers
	// -------------------------------------------------------------------------

	/**
	 * Return the next scheduled timestamp for each plugin cron hook.
	 *
	 * @return array<string, int|false>
	 */
	public function get_next_run_times(): array {
		$hooks = [
			self::HOOK_PROCESS_QUEUE,
			self::HOOK_RETRY_FAILED,
			self::HOOK_PRUNE_LOGS,
			self::HOOK_HEALTH_CHECK,
			self::HOOK_EXPIRE_QUEUE,
			self::HOOK_SYNC_TEMPLATES,            // Phase 5
			self::HOOK_REFRESH_TEMPLATE_QUALITY,  // Phase 5
		];

		$times = [];
		foreach ( $hooks as $hook ) {
			$times[ $hook ] = wp_next_scheduled( $hook );
		}

		return $times;
	}
}
