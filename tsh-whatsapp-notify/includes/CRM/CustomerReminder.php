<?php
/**
 * Customer Reminder — WP-cron based task reminders.
 *
 * @package TSH\WhatsAppNotify\CRM
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\CRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CustomerReminder
 *
 * Schedules and sends WP admin notifications for overdue/due tasks.
 * Hooks into WP-cron hourly to check for tasks needing reminders.
 */
final class CustomerReminder {

	public const HOOK_CHECK_REMINDERS = 'tsh_wa_crm_check_reminders';

	private CustomerRepository $repo;
	private CustomerTasks      $tasks;

	public function __construct( CustomerRepository $repo, CustomerTasks $tasks ) {
		$this->repo  = $repo;
		$this->tasks = $tasks;
	}

	/**
	 * Register WP-cron hook.
	 */
	public function register_hooks(): void {
		add_action( self::HOOK_CHECK_REMINDERS, [ $this, 'process_reminders' ] );
		if ( ! wp_next_scheduled( self::HOOK_CHECK_REMINDERS ) ) {
			wp_schedule_event( time(), 'hourly', self::HOOK_CHECK_REMINDERS );
		}
	}

	/**
	 * Check for overdue tasks and send admin notifications.
	 */
	public function process_reminders(): void {
		$overdue_tasks = $this->repo->get_overdue_tasks( 50 );

		foreach ( $overdue_tasks as $task ) {
			$this->send_reminder( $task );
			$this->repo->update_task( (int) $task['id'], [ 'reminder_sent' => 1 ] );
		}
	}

	private function send_reminder( array $task ): void {
		$assigned_to = (int) ( $task['assigned_to'] ?? 0 );
		if ( ! $assigned_to ) return;

		$user = get_user_by( 'id', $assigned_to );
		if ( ! $user || ! $user->user_email ) return;

		$customer = $this->repo->get_customer( (int) $task['customer_id'] );
		$customer_name = $customer ? $customer['full_name'] : __( 'Unknown Customer', 'tsh-whatsapp-notify' );

		$due_date = $task['due_at'] ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $task['due_at'] ) ) : '';

		$subject = sprintf( __( '[CRM] Task Overdue: %s', 'tsh-whatsapp-notify' ), $task['title'] );

		$message  = sprintf( __( "Hello %s,\n\nA CRM task is overdue:\n\nCustomer: %s\nTask: %s\nDue: %s\nPriority: %s\n\nPlease action this task.", 'tsh-whatsapp-notify' ),
			$user->display_name,
			$customer_name,
			$task['title'],
			$due_date,
			ucfirst( $task['priority'] )
		);

		wp_mail( $user->user_email, $subject, $message );
	}
}
