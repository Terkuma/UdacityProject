<?php
/**
 * Dedicated status-change event listener.
 *
 * Supplements OrderListener by providing a clean status-to-event mapping
 * and firing a normalised `tsh_wa_order_event` action that third-party code
 * can hook into without coupling to WooCommerce directly.
 *
 * @package TSH\WhatsAppNotify\Orders
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Orders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class OrderStatusListener
 *
 * Maps WooCommerce status slugs to plugin event keys and fires the
 * `tsh_wa_order_event` action so any future extension point can
 * react to normalised events without knowing WC internals.
 */
final class OrderStatusListener {

	/**
	 * WooCommerce status slug → plugin event key.
	 *
	 * @var array<string, string>
	 */
	public const STATUS_EVENT_MAP = [
		'pending'    => 'pending',
		'processing' => 'processing',
		'on-hold'    => 'on_hold',
		'completed'  => 'completed',
		'cancelled'  => 'cancelled',
		'failed'     => 'failed',
		'refunded'   => 'refunded',
	];

	/**
	 * All supported event keys (canonical list for the whole plugin).
	 *
	 * @var array<string, string>
	 */
	public const ALL_EVENTS = [
		'new_order'        => 'New Order',
		'pending'          => 'Pending Payment',
		'processing'       => 'Processing',
		'on_hold'          => 'On Hold',
		'completed'        => 'Completed',
		'cancelled'        => 'Cancelled',
		'failed'           => 'Failed',
		'refunded'         => 'Refunded',
		'payment_complete' => 'Payment Complete',
		'note_added'       => 'Order Note Added',
		'status_changed'   => 'Status Changed (any)',
		'order_deleted'    => 'Order Deleted',
		'order_restored'   => 'Order Restored',
	];

	/**
	 * Constructor — registers the normalising status-changed hook.
	 */
	public function __construct() {
		add_action( 'woocommerce_order_status_changed', [ $this, 'on_status_changed' ], 10, 4 );
	}

	/**
	 * Fire the normalised `tsh_wa_order_event` action on every status change.
	 *
	 * @param int       $order_id
	 * @param string    $old_status
	 * @param string    $new_status
	 * @param \WC_Order $order
	 */
	public function on_status_changed( int $order_id, string $old_status, string $new_status, \WC_Order $order ): void {
		$event_key = self::STATUS_EVENT_MAP[ $new_status ] ?? null;

		/**
		 * Fires whenever a WooCommerce order changes status.
		 *
		 * @param int       $order_id   Order ID.
		 * @param string    $event_key  Normalised plugin event key, or null if unmapped.
		 * @param string    $old_status Previous WC status slug.
		 * @param string    $new_status New WC status slug.
		 * @param \WC_Order $order      WooCommerce order object.
		 */
		do_action( 'tsh_wa_order_event', $order_id, $event_key, $old_status, $new_status, $order );
	}

	/**
	 * Translate a WooCommerce status slug to a plugin event key.
	 * Returns null if the status has no mapped event.
	 *
	 * @param string $wc_status WC status slug (without 'wc-' prefix).
	 * @return string|null
	 */
	public static function status_to_event( string $wc_status ): ?string {
		return self::STATUS_EVENT_MAP[ $wc_status ] ?? null;
	}

	/**
	 * Return the human-readable label for an event key.
	 *
	 * @param string $event_key
	 * @return string
	 */
	public static function event_label( string $event_key ): string {
		return self::ALL_EVENTS[ $event_key ] ?? $event_key;
	}

	/**
	 * Return all supported event keys as key → label pairs.
	 *
	 * @return array<string, string>
	 */
	public static function all_events(): array {
		return self::ALL_EVENTS;
	}
}
