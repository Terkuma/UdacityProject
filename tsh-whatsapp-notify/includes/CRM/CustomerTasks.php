<?php
/**
 * Customer Tasks — task management for CRM customers.
 *
 * @package TSH\WhatsAppNotify\CRM
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\CRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CustomerTasks
 *
 * Business logic for creating, completing, and managing
 * tasks assigned to customer profiles.
 */
final class CustomerTasks {

	public const STATUS_PENDING     = 'pending';
	public const STATUS_IN_PROGRESS = 'in_progress';
	public const STATUS_COMPLETED   = 'completed';
	public const STATUS_CANCELLED   = 'cancelled';

	public const PRIORITY_LOW    = 'low';
	public const PRIORITY_MEDIUM = 'medium';
	public const PRIORITY_HIGH   = 'high';
	public const PRIORITY_URGENT = 'urgent';

	private CustomerRepository $repo;
	private CustomerActivity   $activity;

	public function __construct( CustomerRepository $repo, CustomerActivity $activity ) {
		$this->repo     = $repo;
		$this->activity = $activity;
	}

	public function list( int $customer_id, string $status = '' ): array {
		return $this->repo->get_tasks( $customer_id, $status );
	}

	public function list_all( array $args = [] ): array {
		return $this->repo->get_all_tasks( $args );
	}

	public function get( int $task_id ): ?array {
		return $this->repo->get_task( $task_id );
	}

	public function add( int $customer_id, array $data ): array {
		if ( empty( $data['title'] ) ) {
			return [ 'success' => false, 'message' => __( 'Task title is required.', 'tsh-whatsapp-notify' ) ];
		}

		$id = $this->repo->insert_task( array_merge( $data, [ 'customer_id' => $customer_id ] ) );

		return $id
			? [ 'success' => true, 'task_id' => $id ]
			: [ 'success' => false, 'message' => __( 'Failed to create task.', 'tsh-whatsapp-notify' ) ];
	}

	public function update( int $task_id, array $data ): array {
		$ok = $this->repo->update_task( $task_id, $data );
		return [ 'success' => $ok ];
	}

	public function complete( int $task_id ): array {
		$task = $this->repo->get_task( $task_id );
		if ( ! $task ) return [ 'success' => false ];

		$ok = $this->repo->update_task( $task_id, [
			'status'       => self::STATUS_COMPLETED,
			'completed_at' => current_time( 'mysql' ),
		] );

		if ( $ok ) {
			$this->activity->record( (int) $task['customer_id'], CustomerActivity::TYPE_TASK_COMPLETED, [
				'subject'      => sprintf( __( 'Task completed: %s', 'tsh-whatsapp-notify' ), $task['title'] ),
				'reference_type' => 'task',
				'reference_id'   => $task_id,
			] );

			// Handle recurring task
			if ( $task['is_recurring'] && $task['recurrence_config'] ) {
				$config = json_decode( $task['recurrence_config'], true ) ?: [];
				$this->create_next_recurrence( $task, $config );
			}
		}

		return [ 'success' => $ok ];
	}

	public function delete( int $task_id ): array {
		$ok = $this->repo->delete_task( $task_id );
		return [ 'success' => $ok ];
	}

	/**
	 * Get all overdue tasks (for dashboard widget).
	 */
	public function get_overdue(): array {
		return $this->repo->get_overdue_tasks( 100 );
	}

	public static function priority_labels(): array {
		return [
			self::PRIORITY_LOW    => __( 'Low',    'tsh-whatsapp-notify' ),
			self::PRIORITY_MEDIUM => __( 'Medium', 'tsh-whatsapp-notify' ),
			self::PRIORITY_HIGH   => __( 'High',   'tsh-whatsapp-notify' ),
			self::PRIORITY_URGENT => __( 'Urgent', 'tsh-whatsapp-notify' ),
		];
	}

	public static function status_labels(): array {
		return [
			self::STATUS_PENDING     => __( 'Pending',     'tsh-whatsapp-notify' ),
			self::STATUS_IN_PROGRESS => __( 'In Progress', 'tsh-whatsapp-notify' ),
			self::STATUS_COMPLETED   => __( 'Completed',   'tsh-whatsapp-notify' ),
			self::STATUS_CANCELLED   => __( 'Cancelled',   'tsh-whatsapp-notify' ),
		];
	}

	// -------------------------------------------------------------------------

	private function create_next_recurrence( array $task, array $config ): void {
		$interval = $config['interval'] ?? 'weekly';
		$due_at   = $task['due_at'] ?? null;
		if ( ! $due_at ) return;

		$next = strtotime( '+1 ' . $interval, strtotime( $due_at ) );
		if ( ! $next ) return;

		$this->repo->insert_task( [
			'customer_id'       => $task['customer_id'],
			'assigned_to'       => $task['assigned_to'],
			'title'             => $task['title'],
			'description'       => $task['description'],
			'status'            => self::STATUS_PENDING,
			'priority'          => $task['priority'],
			'due_at'            => date( 'Y-m-d H:i:s', $next ),
			'is_recurring'      => 1,
			'recurrence_config' => $config,
			'created_by'        => $task['created_by'],
		] );
	}
}
