<?php
/**
 * WooCommerce order event listener.
 *
 * Hooks into all WooCommerce order lifecycle actions and delegates
 * to OrderProcessor. HPOS-compatible — uses standard WC hooks that
 * work with both legacy post-based and custom order tables.
 *
 * @package TSH\WhatsAppNotify\Orders
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Orders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class OrderListener
 *
 * Single responsibility: translate WC hooks into normalised
 * `handle_event( $event_key, $order_id, $context )` calls.
 * Never sends messages or touches the queue directly.
 */
final class OrderListener {

	/** @var OrderProcessor */
	private OrderProcessor $processor;

	/**
	 * Constructor — registers all WC order hooks.
	 */
	public function __construct() {
		$this->processor = new OrderProcessor();
		$this->register_hooks();
	}

	// -------------------------------------------------------------------------
	// Hook registration
	// -------------------------------------------------------------------------

	/**
	 * Attach all WooCommerce order lifecycle hooks.
	 */
	private function register_hooks(): void {
		// New order created at checkout.
		// woocommerce_checkout_order_created passes a WC_Order; works with HPOS.
		add_action( 'woocommerce_checkout_order_created', [ $this, 'on_new_order' ], 20, 1 );

		// Payment complete (distinct from order completion).
		add_action( 'woocommerce_payment_complete', [ $this, 'on_payment_complete' ], 20, 1 );

		// Individual status hooks — fired AFTER the transition is committed.
		$statuses = [
			'pending'    => 'pending',
			'processing' => 'processing',
			'on-hold'    => 'on_hold',
			'completed'  => 'completed',
			'cancelled'  => 'cancelled',
			'failed'     => 'failed',
			'refunded'   => 'refunded',
		];

		foreach ( $statuses as $wc_slug => $event_key ) {
			add_action(
				'woocommerce_order_status_' . $wc_slug,
				function ( int $order_id ) use ( $event_key ): void {
					$this->processor->handle_event( $event_key, $order_id );
				},
				20,
				1
			);
		}

		// Generic status change (any transition) — fired for every status change.
		// Passes old and new status so we can provide context.
		add_action( 'woocommerce_order_status_changed', [ $this, 'on_status_changed' ], 20, 4 );

		// Order note added by admin or system.
		add_action( 'woocommerce_new_order_note', [ $this, 'on_note_added' ], 20, 2 );

		// Order deleted (moved to trash or permanently deleted).
		add_action( 'woocommerce_before_delete_order', [ $this, 'on_order_deleted' ], 20, 1 );

		// Order restored from trash.
		add_action( 'woocommerce_untrash_order', [ $this, 'on_order_restored' ], 20, 1 );

		// Bulk / admin actions handled via OrderMetaBox and Ajax — no hook here.
	}

	// -------------------------------------------------------------------------
	// Hook callbacks
	// -------------------------------------------------------------------------

	/**
	 * Handle a newly-created checkout order.
	 *
	 * @param \WC_Order $order WooCommerce order object.
	 */
	public function on_new_order( \WC_Order $order ): void {
		$this->processor->handle_event( 'new_order', (int) $order->get_id() );
	}

	/**
	 * Handle payment complete.
	 *
	 * @param int $order_id
	 */
	public function on_payment_complete( int $order_id ): void {
		$this->processor->handle_event( 'payment_complete', $order_id );
	}

	/**
	 * Handle any order status change.
	 *
	 * @param int       $order_id
	 * @param string    $old_status  Without 'wc-' prefix.
	 * @param string    $new_status  Without 'wc-' prefix.
	 * @param \WC_Order $order
	 */
	public function on_status_changed( int $order_id, string $old_status, string $new_status, \WC_Order $order ): void {
		$this->processor->handle_event( 'status_changed', $order_id, [
			'old_status' => $old_status,
			'new_status' => $new_status,
		] );
	}

	/**
	 * Handle a new order note being added.
	 *
	 * @param int   $note_id
	 * @param array $note_data  Raw note data array.
	 */
	public function on_note_added( int $note_id, array $note_data ): void {
		$order_id = absint( $note_data['order_id'] ?? 0 );
		if ( $order_id ) {
			$this->processor->handle_event( 'note_added', $order_id, [
				'note_id'      => $note_id,
				'note_content' => sanitize_textarea_field( $note_data['comment_content'] ?? '' ),
				'note_type'    => sanitize_key( $note_data['comment_type'] ?? 'order_note' ),
			] );
		}
	}

	/**
	 * Handle order deletion.
	 *
	 * @param int $order_id
	 */
	public function on_order_deleted( int $order_id ): void {
		$this->processor->handle_event( 'order_deleted', $order_id );
	}

	/**
	 * Handle order restored from trash.
	 *
	 * @param int $order_id
	 */
	public function on_order_restored( int $order_id ): void {
		$this->processor->handle_event( 'order_restored', $order_id );
	}
}
