<?php
/**
 * WooCommerce order screen meta box.
 *
 * @package TSH\WhatsAppNotify\Admin
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TSH\WhatsAppNotify\Orders\AdminRecipient;
use TSH\WhatsAppNotify\Orders\CustomerRecipient;
use TSH\WhatsAppNotify\Orders\OrderFormatter;
use TSH\WhatsAppNotify\Orders\OrderMessageBuilder;
use TSH\WhatsAppNotify\Orders\OrderProcessor;
use TSH\WhatsAppNotify\Orders\OrderQueueDispatcher;
use TSH\WhatsAppNotify\Orders\OrderStatusListener;

/**
 * Class OrderMetaBox
 *
 * Adds a "WhatsApp Notify" meta box to the WooCommerce order edit screen
 * showing a live message preview for both customer and admin recipients,
 * and action buttons to queue or resend notifications.
 *
 * HPOS compatible — registers on both post type and WC order screen.
 */
final class OrderMetaBox {

	/**
	 * Constructor — registers meta box and order action hooks.
	 */
	public function __construct() {
		// Legacy post-based orders.
		add_action( 'add_meta_boxes', [ $this, 'register_meta_box' ] );

		// HPOS custom order tables.
		add_action( 'add_meta_boxes_woocommerce_page_wc-orders', [ $this, 'register_meta_box' ] );

		// WooCommerce order actions (dropdown on order screen).
		add_filter( 'woocommerce_order_actions', [ $this, 'add_order_actions' ] );
		add_action( 'woocommerce_order_action_tsh_wa_send_customer', [ $this, 'action_send_customer' ] );
		add_action( 'woocommerce_order_action_tsh_wa_send_admin',    [ $this, 'action_send_admin'    ] );
		add_action( 'woocommerce_order_action_tsh_wa_resend_last',   [ $this, 'action_resend_last'   ] );

		// Bulk actions on orders list.
		add_filter( 'bulk_actions-edit-shop_order',                        [ $this, 'register_bulk_actions' ] );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders',             [ $this, 'register_bulk_actions' ] );
		add_filter( 'handle_bulk_actions-edit-shop_order',                 [ $this, 'handle_bulk_actions'   ], 10, 3 );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders',      [ $this, 'handle_bulk_actions'   ], 10, 3 );

		// Bulk action admin notice.
		add_action( 'admin_notices', [ $this, 'bulk_action_notice' ] );
	}

	// -------------------------------------------------------------------------
	// Meta box
	// -------------------------------------------------------------------------

	/**
	 * Register the meta box on the order screen.
	 */
	public function register_meta_box(): void {
		$screen = wc_get_page_screen_id( 'shop_order' );

		add_meta_box(
			'tsh_wa_order_preview',
			__( '📱 WhatsApp Notify', 'tsh-whatsapp-notify' ),
			[ $this, 'render_meta_box' ],
			$screen,
			'side',
			'default'
		);

		// Also register for legacy screen.
		if ( 'shop_order' !== $screen ) {
			add_meta_box(
				'tsh_wa_order_preview',
				__( '📱 WhatsApp Notify', 'tsh-whatsapp-notify' ),
				[ $this, 'render_meta_box' ],
				'shop_order',
				'side',
				'default'
			);
		}
	}

	/**
	 * Render the meta box content.
	 *
	 * @param \WP_Post|\WC_Order $post_or_order
	 */
	public function render_meta_box( $post_or_order ): void {
		$order = $post_or_order instanceof \WC_Order
			? $post_or_order
			: wc_get_order( $post_or_order->ID );

		if ( ! $order instanceof \WC_Order ) {
			echo '<p>' . esc_html__( 'Order not found.', 'tsh-whatsapp-notify' ) . '</p>';
			return;
		}

		$order_id          = (int) $order->get_id();
		$builder           = new OrderMessageBuilder();
		$customer_rec      = new CustomerRecipient();
		$admin_rec         = new AdminRecipient();
		$dispatcher        = new OrderQueueDispatcher();
		$notifications     = $dispatcher->get_order_notifications( $order_id, 10 );
		$customer_phone    = $customer_rec->get_phone( $order );
		$admin_count       = $admin_rec->count_enabled();

		// Get current event settings for "status_changed" as a preview event.
		$preview_event     = 'processing';
		$event_settings    = get_option( 'tsh_wa_wc_events_settings', [] );
		$ev                = $event_settings[ $preview_event ] ?? [];

		// Build preview messages.
		$customer_template = $this->resolve_template( $ev['customer_template'] ?? '', $preview_event, 'customer' );
		$admin_template    = $this->resolve_template( $ev['admin_template'] ?? '', $preview_event, 'admin' );
		$customer_message  = $builder->build( $customer_template, $order );
		$admin_message     = $builder->build( $admin_template, $order );

		$nonce = wp_create_nonce( Ajax::NONCE_ACTION );

		include TSH_WA_PATH . 'templates/admin/order-meta-box.php';
	}

	// -------------------------------------------------------------------------
	// Order actions
	// -------------------------------------------------------------------------

	/**
	 * Register plugin actions in the WooCommerce order actions dropdown.
	 *
	 * @param array<string, string> $actions
	 * @return array<string, string>
	 */
	public function add_order_actions( array $actions ): array {
		$actions['tsh_wa_send_customer'] = __( '📱 WhatsApp: Send to Customer', 'tsh-whatsapp-notify' );
		$actions['tsh_wa_send_admin']    = __( '📱 WhatsApp: Send to Admin(s)', 'tsh-whatsapp-notify' );
		$actions['tsh_wa_resend_last']   = __( '📱 WhatsApp: Resend Last Notification', 'tsh-whatsapp-notify' );
		return $actions;
	}

	/**
	 * Handle "Send to Customer" order action.
	 *
	 * @param \WC_Order $order
	 */
	public function action_send_customer( \WC_Order $order ): void {
		$processor = new OrderProcessor();
		$result    = $processor->force_queue( 'status_changed', (int) $order->get_id(), 'customer' );
		$this->flash_action_notice( $result );
	}

	/**
	 * Handle "Send to Admin(s)" order action.
	 *
	 * @param \WC_Order $order
	 */
	public function action_send_admin( \WC_Order $order ): void {
		$processor = new OrderProcessor();
		$result    = $processor->force_queue( 'status_changed', (int) $order->get_id(), 'admin' );
		$this->flash_action_notice( $result );
	}

	/**
	 * Handle "Resend Last Notification" order action.
	 *
	 * @param \WC_Order $order
	 */
	public function action_resend_last( \WC_Order $order ): void {
		$processor = new OrderProcessor();
		$result    = $processor->force_queue( 'status_changed', (int) $order->get_id(), 'all' );
		$this->flash_action_notice( $result );
	}

	// -------------------------------------------------------------------------
	// Bulk actions
	// -------------------------------------------------------------------------

	/**
	 * Register bulk actions on the orders list table.
	 *
	 * @param array<string, string> $actions
	 * @return array<string, string>
	 */
	public function register_bulk_actions( array $actions ): array {
		$actions['tsh_wa_bulk_send']   = __( '📱 WhatsApp: Send Notification', 'tsh-whatsapp-notify' );
		$actions['tsh_wa_bulk_queue']  = __( '📱 WhatsApp: Queue Notification', 'tsh-whatsapp-notify' );
		$actions['tsh_wa_bulk_retry']  = __( '📱 WhatsApp: Retry Failed', 'tsh-whatsapp-notify' );
		return $actions;
	}

	/**
	 * Handle bulk actions.
	 *
	 * @param string            $redirect_to Redirect URL.
	 * @param string            $action      Action name.
	 * @param array<int, mixed> $order_ids   Selected order IDs.
	 * @return string
	 */
	public function handle_bulk_actions( string $redirect_to, string $action, array $order_ids ): string {
		if ( ! in_array( $action, [ 'tsh_wa_bulk_send', 'tsh_wa_bulk_queue', 'tsh_wa_bulk_retry' ], true ) ) {
			return $redirect_to;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return $redirect_to;
		}

		$queued = 0;
		$errors = 0;

		foreach ( $order_ids as $order_id ) {
			$order_id  = absint( $order_id );
			$processor = new OrderProcessor();

			if ( 'tsh_wa_bulk_retry' === $action ) {
				// Retry failed queue items for this order.
				global $wpdb;
				$table = $wpdb->prefix . 'tsh_wa_queue';
				$wpdb->update(
					$table,
					[ 'status' => 'pending', 'attempts' => 0, 'error_message' => null ],
					[ 'order_id' => $order_id, 'status' => 'failed' ],
					[ '%s', '%d', null ],
					[ '%d', '%s' ]
				);
				++$queued;
			} else {
				$result  = $processor->force_queue( 'status_changed', $order_id, 'all' );
				$queued += $result['queued'];
				$errors += count( $result['errors'] );
			}
		}

		$redirect_to = add_query_arg( [
			'tsh_wa_bulk_done'   => $queued,
			'tsh_wa_bulk_errors' => $errors,
		], $redirect_to );

		return $redirect_to;
	}

	/**
	 * Show admin notice after bulk action completes.
	 */
	public function bulk_action_notice(): void {
		if ( empty( $_REQUEST['tsh_wa_bulk_done'] ) ) {
			return;
		}

		$queued = absint( $_REQUEST['tsh_wa_bulk_done'] );
		$errors = absint( $_REQUEST['tsh_wa_bulk_errors'] ?? 0 );

		echo '<div class="notice notice-success is-dismissible"><p>';
		printf(
			/* translators: %d: number of notifications queued */
			esc_html( _n( '%d WhatsApp notification queued.', '%d WhatsApp notifications queued.', $queued, 'tsh-whatsapp-notify' ) ),
			esc_html( (string) $queued )
		);
		if ( $errors > 0 ) {
			printf(
				' <strong>' . esc_html( _n( '%d error.', '%d errors.', $errors, 'tsh-whatsapp-notify' ) ) . '</strong>',
				esc_html( (string) $errors )
			);
		}
		echo '</p></div>';
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Resolve template slug to body. Falls back to a default if not found.
	 *
	 * @param string $slug
	 * @param string $event_key
	 * @param string $type 'customer' | 'admin'.
	 * @return string
	 */
	private function resolve_template( string $slug, string $event_key, string $type ): string {
		if ( $slug ) {
			global $wpdb;
			$table = $wpdb->prefix . 'tsh_wa_templates';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$body = $wpdb->get_var(
				$wpdb->prepare( "SELECT message_body FROM `{$table}` WHERE slug = %s AND status = 'active' LIMIT 1", $slug )
			);
			if ( $body ) {
				return $body;
			}
		}

		if ( 'admin' === $type ) {
			return "🛒 *Order #{order_number}* — {customer_name}\nTotal: {total}\nPayment: {payment_method}\n\n{products}\n\n{admin_order_url}";
		}

		return "Hello {customer_name}! 👋\nOrder #{order_number} update from *{store_name}*.\nTotal: {total}\n\nTrack: {customer_order_url}";
	}

	/**
	 * Store a transient admin notice after a redirect.
	 * WooCommerce will show the result on the next page load.
	 *
	 * @param array{ queued: int, errors: string[] } $result
	 */
	private function flash_action_notice( array $result ): void {
		if ( $result['queued'] > 0 ) {
			set_transient(
				'tsh_wa_action_notice_' . get_current_user_id(),
				[
					'type'    => 'success',
					'message' => sprintf(
						/* translators: %d: number of notifications queued */
						_n( '%d WhatsApp notification queued.', '%d WhatsApp notifications queued.', $result['queued'], 'tsh-whatsapp-notify' ),
						$result['queued']
					),
				],
				60
			);
		}
	}
}
