<?php
/**
 * Automation Engine — main orchestrator.
 *
 * @package TSH\WhatsAppNotify\Automation
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Automation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AutomationEngine
 *
 * Bootstraps the automation system:
 *   - Registers WordPress/WooCommerce hooks for all active workflow triggers.
 *   - Routes trigger events to the WorkflowQueue for background execution.
 *   - Registers cron hooks.
 */
class AutomationEngine {

	private static ?self $instance = null;

	private WorkflowRepository $repo;
	private TriggerManager     $trigger_manager;
	private WorkflowScheduler  $scheduler;
	private WorkflowQueue      $queue;

	/** @var array<string> Active trigger types (cached). */
	private array $active_triggers = [];

	/** @var bool Whether automation is globally enabled. */
	private bool $enabled;

	private function __construct() {
		$this->repo            = new WorkflowRepository();
		$this->trigger_manager = new TriggerManager();
		$this->scheduler       = new WorkflowScheduler();
		$this->queue           = new WorkflowQueue();

		$settings       = get_option( 'tsh_wa_automation_settings', [] );
		$this->enabled  = ! empty( $settings['enabled'] );
	}

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	// -------------------------------------------------------------------------
	// Hook registration
	// -------------------------------------------------------------------------

	/**
	 * Register all hooks. Called once from Bootstrap/Loader.
	 */
	public function register_hooks(): void {
		// Scheduler cron hooks always registered (needed for resuming delayed runs).
		$this->scheduler->register_hooks();

		// Queue processor hook.
		add_action( 'tsh_wa_automation_queue_process', [ $this->queue, 'process' ] );

		if ( ! $this->enabled ) {
			return;
		}

		// Load active trigger types from DB (single query, cached for request).
		$this->load_active_triggers();

		if ( empty( $this->active_triggers ) ) {
			return;
		}

		// Register WC/WP event hooks.
		$this->trigger_manager->register_hooks(
			[ $this, 'handle_trigger' ],
			$this->active_triggers
		);

		// Register any custom hook triggers.
		$this->register_custom_hooks();
	}

	/**
	 * Called by TriggerManager when a trigger event fires.
	 *
	 * @param string $trigger_type
	 * @param array  $trigger_data
	 */
	public function handle_trigger( string $trigger_type, array $trigger_data ): void {
		if ( ! $this->enabled ) {
			return;
		}

		// Find all active workflows for this trigger.
		$workflows = $this->repo->get_active_by_trigger( $trigger_type );

		if ( empty( $workflows ) ) {
			return;
		}

		$settings     = get_option( 'tsh_wa_automation_settings', [] );
		$dedup_window = max( 60, (int) ( $settings['dedup_window_seconds'] ?? 3600 ) );

		foreach ( $workflows as $workflow ) {
			$workflow_id = (int) $workflow['id'];
			$dedup_key   = $this->build_dedup_key( $trigger_type, $trigger_data );

			// Prevent duplicate executions.
			if ( $dedup_key && $this->repo->has_recent_run( $workflow_id, $dedup_key, $dedup_window ) ) {
				continue;
			}

			// Enrich trigger data with dedup key for storage.
			$enriched = array_merge( $trigger_data, [ '_dedup_key' => $dedup_key ] );

			// Push to background queue instead of executing synchronously.
			$this->queue->enqueue( $workflow_id, $trigger_type, $enriched, $dedup_key );
		}
	}

	// -------------------------------------------------------------------------
	// Cache helpers
	// -------------------------------------------------------------------------

	/**
	 * Invalidate the trigger cache (call when a workflow is saved/activated).
	 */
	public static function flush_trigger_cache(): void {
		delete_transient( 'tsh_wa_active_triggers' );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	private function load_active_triggers(): void {
		$cached = get_transient( 'tsh_wa_active_triggers' );

		if ( is_array( $cached ) ) {
			$this->active_triggers = $cached;
			return;
		}

		// Pull all active workflows and extract trigger types.
		$result  = $this->repo->get_workflows( [ 'status' => 'active', 'per_page' => 500 ] );
		$types   = array_unique( array_column( $result['rows'], 'trigger_type' ) );
		$this->active_triggers = array_values( array_filter( $types ) );

		set_transient( 'tsh_wa_active_triggers', $this->active_triggers, 5 * MINUTE_IN_SECONDS );
	}

	private function register_custom_hooks(): void {
		$result = $this->repo->get_active_by_trigger( 'custom_hook' );

		foreach ( $result as $workflow ) {
			$hook     = $workflow['trigger_config']['hook_name'] ?? '';
			$priority = (int) ( $workflow['trigger_config']['priority'] ?? 10 );

			if ( $hook ) {
				$this->trigger_manager->register_custom_hook( $hook, $priority, [ $this, 'handle_trigger' ] );
			}
		}
	}

	private function build_dedup_key( string $trigger_type, array $trigger_data ): string {
		$identifiers = [];

		if ( ! empty( $trigger_data['order_id'] ) ) {
			$identifiers[] = 'order_' . (int) $trigger_data['order_id'];
		}
		if ( ! empty( $trigger_data['customer_id'] ) ) {
			$identifiers[] = 'cust_' . (int) $trigger_data['customer_id'];
		}
		if ( ! empty( $trigger_data['product_id'] ) ) {
			$identifiers[] = 'prod_' . (int) $trigger_data['product_id'];
		}

		if ( empty( $identifiers ) ) {
			return '';
		}

		return $trigger_type . ':' . implode( ':', $identifiers );
	}

	// Prevent cloning and unserialization.
	private function __clone() {}
	public function __wakeup(): void {
		throw new \Exception( 'Cannot unserialize singleton.' );
	}
}
