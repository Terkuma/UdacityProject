<?php
/**
 * Customer Activity — records and queries activity events.
 *
 * @package TSH\WhatsAppNotify\CRM
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\CRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CustomerActivity
 *
 * Provides a static factory method for recording structured activity events,
 * and hooks into WooCommerce + plugin events to auto-record activities.
 */
final class CustomerActivity {

	public const TYPE_ORDER_PLACED      = 'order_placed';
	public const TYPE_ORDER_COMPLETED   = 'order_completed';
	public const TYPE_ORDER_REFUNDED    = 'order_refunded';
	public const TYPE_ORDER_CANCELLED   = 'order_cancelled';
	public const TYPE_ORDER_STATUS      = 'order_status_change';
	public const TYPE_CAMPAIGN_RECEIVED = 'campaign_received';
	public const TYPE_CAMPAIGN_CLICKED  = 'campaign_clicked';
	public const TYPE_COUPON_REDEEMED   = 'coupon_redeemed';
	public const TYPE_MSG_DELIVERED     = 'message_delivered';
	public const TYPE_MSG_READ          = 'message_read';
	public const TYPE_MSG_SENT          = 'message_sent';
	public const TYPE_MSG_FAILED        = 'message_failed';
	public const TYPE_WORKFLOW_EXEC     = 'workflow_executed';
	public const TYPE_ABANDONED_CART    = 'abandoned_cart';
	public const TYPE_NOTE              = 'note_added';
	public const TYPE_TAG_ADDED         = 'tag_added';
	public const TYPE_TAG_REMOVED       = 'tag_removed';
	public const TYPE_TASK_COMPLETED    = 'task_completed';
	public const TYPE_LIFECYCLE_CHANGE  = 'lifecycle_change';
	public const TYPE_VIP_GRANTED       = 'vip_granted';
	public const TYPE_VIP_REVOKED       = 'vip_revoked';
	public const TYPE_BLOCKED           = 'customer_blocked';
	public const TYPE_UNBLOCKED         = 'customer_unblocked';
	public const TYPE_MERGE             = 'customer_merged';
	public const TYPE_IMPORT            = 'customer_imported';
	public const TYPE_CUSTOM            = 'custom';

	private CustomerRepository $repo;

	public function __construct( CustomerRepository $repo ) {
		$this->repo = $repo;
	}

	/**
	 * Register WooCommerce hooks for auto-recording.
	 */
	public function register_hooks(): void {
		add_action( 'woocommerce_new_order',              [ $this, 'on_order_placed' ], 10, 2 );
		add_action( 'woocommerce_order_status_completed', [ $this, 'on_order_completed' ], 10, 2 );
		add_action( 'woocommerce_order_status_refunded',  [ $this, 'on_order_refunded' ], 10, 2 );
		add_action( 'woocommerce_order_status_cancelled', [ $this, 'on_order_cancelled' ], 10, 2 );
		add_action( 'woocommerce_order_status_changed',   [ $this, 'on_order_status_changed' ], 10, 4 );
	}

	// =========================================================================
	// WooCommerce hooks
	// =========================================================================

	public function on_order_placed( int $order_id, $order ): void {
		$customer = $this->resolve_order_customer( $order_id );
		if ( ! $customer ) return;

		$this->record( (int) $customer['id'], self::TYPE_ORDER_PLACED, [
			'subject'        => sprintf( __( 'Order #%d placed', 'tsh-whatsapp-notify' ), $order_id ),
			'reference_type' => 'order',
			'reference_id'   => $order_id,
			'data'           => [ 'total' => $order ? $order->get_total() : 0 ],
		] );
	}

	public function on_order_completed( int $order_id ): void {
		$customer = $this->resolve_order_customer( $order_id );
		if ( ! $customer ) return;
		$this->record( (int) $customer['id'], self::TYPE_ORDER_COMPLETED, [
			'subject'        => sprintf( __( 'Order #%d completed', 'tsh-whatsapp-notify' ), $order_id ),
			'reference_type' => 'order',
			'reference_id'   => $order_id,
		] );
	}

	public function on_order_refunded( int $order_id ): void {
		$customer = $this->resolve_order_customer( $order_id );
		if ( ! $customer ) return;
		$this->record( (int) $customer['id'], self::TYPE_ORDER_REFUNDED, [
			'subject'        => sprintf( __( 'Order #%d refunded', 'tsh-whatsapp-notify' ), $order_id ),
			'reference_type' => 'order',
			'reference_id'   => $order_id,
		] );
	}

	public function on_order_cancelled( int $order_id ): void {
		$customer = $this->resolve_order_customer( $order_id );
		if ( ! $customer ) return;
		$this->record( (int) $customer['id'], self::TYPE_ORDER_CANCELLED, [
			'subject'        => sprintf( __( 'Order #%d cancelled', 'tsh-whatsapp-notify' ), $order_id ),
			'reference_type' => 'order',
			'reference_id'   => $order_id,
		] );
	}

	public function on_order_status_changed( int $order_id, string $old_status, string $new_status, $order ): void {
		$customer = $this->resolve_order_customer( $order_id );
		if ( ! $customer ) return;
		$this->record( (int) $customer['id'], self::TYPE_ORDER_STATUS, [
			'subject'        => sprintf( __( 'Order #%d: %s → %s', 'tsh-whatsapp-notify' ), $order_id, $old_status, $new_status ),
			'reference_type' => 'order',
			'reference_id'   => $order_id,
			'data'           => [ 'old' => $old_status, 'new' => $new_status ],
		] );
	}

	// =========================================================================
	// Core record method
	// =========================================================================

	/**
	 * Record an activity event.
	 *
	 * @param int    $customer_id CRM customer ID.
	 * @param string $type        Activity type constant.
	 * @param array  $args        subject, description, data, reference_type, reference_id, created_by
	 */
	public function record( int $customer_id, string $type, array $args = [] ): int {
		if ( ! $customer_id ) return 0;
		return $this->repo->insert_activity( array_merge( $args, [
			'customer_id' => $customer_id,
			'type'        => $type,
			'created_by'  => $args['created_by'] ?? 0,
		] ) );
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	private function resolve_order_customer( int $order_id ): ?array {
		$order = wc_get_order( $order_id );
		if ( ! $order ) return null;

		$phone = $order->get_billing_phone();
		$email = $order->get_billing_email();

		if ( $phone ) {
			$customer = $this->repo->get_customer_by_phone( $phone );
			if ( $customer ) return $customer;
		}

		if ( $email ) {
			return $this->repo->get_customer_by_email( $email );
		}

		return null;
	}

	/**
	 * Get type labels for UI display.
	 */
	public static function type_labels(): array {
		return [
			self::TYPE_ORDER_PLACED      => __( 'Order Placed',       'tsh-whatsapp-notify' ),
			self::TYPE_ORDER_COMPLETED   => __( 'Order Completed',    'tsh-whatsapp-notify' ),
			self::TYPE_ORDER_REFUNDED    => __( 'Order Refunded',     'tsh-whatsapp-notify' ),
			self::TYPE_ORDER_CANCELLED   => __( 'Order Cancelled',    'tsh-whatsapp-notify' ),
			self::TYPE_ORDER_STATUS      => __( 'Status Change',      'tsh-whatsapp-notify' ),
			self::TYPE_CAMPAIGN_RECEIVED => __( 'Campaign Received',  'tsh-whatsapp-notify' ),
			self::TYPE_COUPON_REDEEMED   => __( 'Coupon Redeemed',    'tsh-whatsapp-notify' ),
			self::TYPE_MSG_DELIVERED     => __( 'Message Delivered',  'tsh-whatsapp-notify' ),
			self::TYPE_MSG_READ          => __( 'Message Read',       'tsh-whatsapp-notify' ),
			self::TYPE_MSG_SENT          => __( 'Message Sent',       'tsh-whatsapp-notify' ),
			self::TYPE_MSG_FAILED        => __( 'Message Failed',     'tsh-whatsapp-notify' ),
			self::TYPE_WORKFLOW_EXEC     => __( 'Workflow Executed',  'tsh-whatsapp-notify' ),
			self::TYPE_ABANDONED_CART    => __( 'Abandoned Cart',     'tsh-whatsapp-notify' ),
			self::TYPE_NOTE              => __( 'Note Added',         'tsh-whatsapp-notify' ),
			self::TYPE_TAG_ADDED         => __( 'Tag Added',          'tsh-whatsapp-notify' ),
			self::TYPE_TAG_REMOVED       => __( 'Tag Removed',        'tsh-whatsapp-notify' ),
			self::TYPE_TASK_COMPLETED    => __( 'Task Completed',     'tsh-whatsapp-notify' ),
			self::TYPE_LIFECYCLE_CHANGE  => __( 'Lifecycle Updated',  'tsh-whatsapp-notify' ),
			self::TYPE_VIP_GRANTED       => __( 'VIP Granted',        'tsh-whatsapp-notify' ),
			self::TYPE_VIP_REVOKED       => __( 'VIP Revoked',        'tsh-whatsapp-notify' ),
			self::TYPE_MERGE             => __( 'Customers Merged',   'tsh-whatsapp-notify' ),
			self::TYPE_IMPORT            => __( 'Customer Imported',  'tsh-whatsapp-notify' ),
		];
	}

	/**
	 * Get type icons for UI display.
	 */
	public static function type_icons(): array {
		return [
			self::TYPE_ORDER_PLACED      => '🛒',
			self::TYPE_ORDER_COMPLETED   => '✅',
			self::TYPE_ORDER_REFUNDED    => '↩️',
			self::TYPE_ORDER_CANCELLED   => '❌',
			self::TYPE_ORDER_STATUS      => '🔄',
			self::TYPE_CAMPAIGN_RECEIVED => '📢',
			self::TYPE_COUPON_REDEEMED   => '🎟️',
			self::TYPE_MSG_DELIVERED     => '📨',
			self::TYPE_MSG_READ          => '👁️',
			self::TYPE_MSG_SENT          => '📤',
			self::TYPE_MSG_FAILED        => '⚠️',
			self::TYPE_WORKFLOW_EXEC     => '⚙️',
			self::TYPE_ABANDONED_CART    => '🛒',
			self::TYPE_NOTE              => '📝',
			self::TYPE_TAG_ADDED         => '🏷️',
			self::TYPE_TAG_REMOVED       => '🏷️',
			self::TYPE_TASK_COMPLETED    => '☑️',
			self::TYPE_LIFECYCLE_CHANGE  => '🔁',
			self::TYPE_VIP_GRANTED       => '⭐',
			self::TYPE_VIP_REVOKED       => '⭐',
			self::TYPE_MERGE             => '🔀',
			self::TYPE_IMPORT            => '⬆️',
		];
	}
}
