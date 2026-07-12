<?php
/**
 * Central order-event orchestrator.
 *
 * @package TSH\WhatsAppNotify\Orders
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Orders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TSH\WhatsAppNotify\Helpers\Helpers;
use TSH\WhatsAppNotify\Logger\Logger;

/**
 * Class OrderProcessor
 *
 * Coordinates the full pipeline for a single order event:
 *   1. Validate order + event configuration.
 *   2. Resolve recipients (customer + admins).
 *   3. Build message bodies from templates + placeholders.
 *   4. Dispatch to queue via OrderQueueDispatcher.
 *   5. Log everything.
 *
 * This class never talks to the WhatsApp API directly.
 * It only feeds the queue.
 */
final class OrderProcessor {

	/** @var OrderValidator */
	private OrderValidator $validator;

	/** @var OrderMessageBuilder */
	private OrderMessageBuilder $message_builder;

	/** @var CustomerRecipient */
	private CustomerRecipient $customer_recipient;

	/** @var AdminRecipient */
	private AdminRecipient $admin_recipient;

	/** @var OrderQueueDispatcher */
	private OrderQueueDispatcher $dispatcher;

	/** @var OrderLogger */
	private OrderLogger $logger;

	/**
	 * Constructor — instantiates all pipeline dependencies.
	 */
	public function __construct() {
		$this->validator          = new OrderValidator();
		$this->message_builder    = new OrderMessageBuilder();
		$this->customer_recipient = new CustomerRecipient();
		$this->admin_recipient    = new AdminRecipient();
		$this->dispatcher         = new OrderQueueDispatcher();
		$this->logger             = new OrderLogger();
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Process an order lifecycle event.
	 *
	 * This is the single entry point called by OrderListener.
	 * All failures are caught and logged; nothing bubbles up to crash checkout.
	 *
	 * @param string               $event_key Plugin event key (see OrderStatusListener::ALL_EVENTS).
	 * @param int                  $order_id  WooCommerce order ID.
	 * @param array<string, mixed> $context   Optional extra data (old_status, new_status, note_id…).
	 */
	public function handle_event( string $event_key, int $order_id, array $context = [] ): void {
		try {
			$this->process( $event_key, $order_id, $context );
		} catch ( \Throwable $e ) {
			// Never let any exception surface to the checkout or order edit screen.
			$this->logger->log_event(
				$event_key,
				$order_id,
				Logger::LEVEL_ERROR,
				sprintf( 'Unhandled exception in OrderProcessor: %s', $e->getMessage() ),
				[ 'exception' => get_class( $e ), 'file' => $e->getFile(), 'line' => $e->getLine() ]
			);
		}
	}

	/**
	 * Force-queue a notification, bypassing duplicate-protection.
	 * Used for "Resend" and manual admin actions.
	 *
	 * @param string $event_key
	 * @param int    $order_id
	 * @param string $recipient_type 'customer' | 'admin' | 'all'
	 * @return array{ queued: int, errors: string[] }
	 */
	public function force_queue( string $event_key, int $order_id, string $recipient_type = 'all' ): array {
		$result = [ 'queued' => 0, 'errors' => [] ];

		try {
			$order = $this->load_order( $order_id );
			if ( ! $order ) {
				$result['errors'][] = "Order #{$order_id} not found.";
				return $result;
			}

			$event_settings = $this->get_event_settings( $event_key );

			if ( in_array( $recipient_type, [ 'customer', 'all' ], true ) ) {
				$r = $this->dispatch_to_customer( $order, $event_key, $event_settings, true );
				$result['queued'] += $r['queued'];
				$result['errors']  = array_merge( $result['errors'], $r['errors'] );
			}

			if ( in_array( $recipient_type, [ 'admin', 'all' ], true ) ) {
				$r = $this->dispatch_to_admins( $order, $event_key, $event_settings, true );
				$result['queued'] += $r['queued'];
				$result['errors']  = array_merge( $result['errors'], $r['errors'] );
			}
		} catch ( \Throwable $e ) {
			$result['errors'][] = $e->getMessage();
		}

		return $result;
	}

	// -------------------------------------------------------------------------
	// Internal pipeline
	// -------------------------------------------------------------------------

	/**
	 * Full processing pipeline.
	 *
	 * @param string               $event_key
	 * @param int                  $order_id
	 * @param array<string, mixed> $context
	 */
	private function process( string $event_key, int $order_id, array $context ): void {
		// 1. Validate event is known + API is configured.
		if ( ! $this->validator->is_event_supported( $event_key ) ) {
			return;
		}

		if ( ! $this->validator->is_api_enabled() ) {
			return;
		}

		// 2. Load the order.
		$order = $this->load_order( $order_id );
		if ( ! $order ) {
			$this->logger->log_event( $event_key, $order_id, Logger::LEVEL_WARNING, "Order not found — skipping." );
			return;
		}

		// 3. Validate order data.
		if ( ! $this->validator->is_valid_order( $order ) ) {
			return;
		}

		// 4. Load event configuration.
		$event_settings = $this->get_event_settings( $event_key );

		if ( empty( $event_settings['enabled'] ) || '1' !== (string) $event_settings['enabled'] ) {
			return; // Event disabled in settings.
		}

		// 5. Dispatch to customer.
		if ( ! empty( $event_settings['notify_customer'] ) && '1' === (string) $event_settings['notify_customer'] ) {
			$this->dispatch_to_customer( $order, $event_key, $event_settings );
		}

		// 6. Dispatch to admins.
		if ( ! empty( $event_settings['notify_admin'] ) && '1' === (string) $event_settings['notify_admin'] ) {
			$this->dispatch_to_admins( $order, $event_key, $event_settings );
		}
	}

	/**
	 * Build + dispatch a customer notification.
	 *
	 * @param \WC_Order            $order
	 * @param string               $event_key
	 * @param array<string,mixed>  $event_settings
	 * @param bool                 $force Skip duplicate check.
	 * @return array{ queued: int, errors: string[] }
	 */
	private function dispatch_to_customer( \WC_Order $order, string $event_key, array $event_settings, bool $force = false ): array {
		$result = [ 'queued' => 0, 'errors' => [] ];

		$phone = $this->customer_recipient->get_phone( $order );
		if ( ! $phone ) {
			$this->logger->log_event( $event_key, (int) $order->get_id(), Logger::LEVEL_WARNING,
				'No valid customer phone — customer notification skipped.',
				[ 'billing_phone' => $order->get_billing_phone() ]
			);
			$result['errors'][] = 'No valid customer phone number.';
			return $result;
		}

		$template_slug = sanitize_key( $event_settings['customer_template'] ?? '' );
		$template_body = $this->resolve_template( $template_slug, $event_key, 'customer' );

		$message = $this->message_builder->build( $template_body, $order );

		$queue_id = $this->dispatcher->dispatch(
			(int) $order->get_id(),
			$event_key,
			$phone,
			$message,
			'customer',
			[
				'template_slug'    => $template_slug,
				'delay_seconds'    => absint( $event_settings['delay_seconds'] ?? 0 ),
				'queue_immediately'=> ! empty( $event_settings['queue_immediately'] ),
				'force'            => $force,
			]
		);

		if ( $queue_id ) {
			$result['queued']++;
			$this->logger->log_event( $event_key, (int) $order->get_id(), Logger::LEVEL_INFO,
				sprintf( 'Customer notification queued (#%d) to %s.', $queue_id, Helpers::mask_phone( $phone ) ),
				[ 'queue_id' => $queue_id, 'recipient_type' => 'customer' ]
			);
			$this->logger->add_order_note( (int) $order->get_id(),
				sprintf( __( 'WhatsApp queued → Customer (%s) — Event: %s. Queue ID: %d.', 'tsh-whatsapp-notify' ),
					\TSH\WhatsAppNotify\Helpers\Helpers::mask_phone( $phone ),
					OrderStatusListener::event_label( $event_key ),
					$queue_id
				)
			);
		} elseif ( false === $queue_id ) {
			// Duplicate skipped.
			$this->logger->log_event( $event_key, (int) $order->get_id(), Logger::LEVEL_INFO,
				'Customer notification skipped — already sent for this event.',
				[ 'phone' => \TSH\WhatsAppNotify\Helpers\Helpers::mask_phone( $phone ) ]
			);
		} else {
			$result['errors'][] = 'Failed to add customer notification to queue.';
		}

		return $result;
	}

	/**
	 * Build + dispatch notifications to all enabled admin recipients.
	 *
	 * @param \WC_Order            $order
	 * @param string               $event_key
	 * @param array<string,mixed>  $event_settings
	 * @param bool                 $force Skip duplicate check.
	 * @return array{ queued: int, errors: string[] }
	 */
	private function dispatch_to_admins( \WC_Order $order, string $event_key, array $event_settings, bool $force = false ): array {
		$result     = [ 'queued' => 0, 'errors' => [] ];
		$recipients = $this->admin_recipient->get_enabled_recipients();

		if ( empty( $recipients ) ) {
			$this->logger->log_event( $event_key, (int) $order->get_id(), Logger::LEVEL_WARNING,
				'No enabled admin recipients — admin notification skipped.'
			);
			return $result;
		}

		$template_slug = sanitize_key( $event_settings['admin_template'] ?? '' );
		$template_body = $this->resolve_template( $template_slug, $event_key, 'admin' );
		$message       = $this->message_builder->build( $template_body, $order );

		foreach ( $recipients as $recipient ) {
			$phone = sanitize_text_field( $recipient['phone'] ?? '' );
			if ( ! Helpers::is_valid_phone( $phone ) ) {
				$result['errors'][] = "Admin recipient '{$recipient['name']}' has an invalid phone: {$phone}";
				continue;
			}

			$queue_id = $this->dispatcher->dispatch(
				(int) $order->get_id(),
				$event_key,
				$phone,
				$message,
				'admin',
				[
					'template_slug'     => $template_slug,
					'recipient_name'    => sanitize_text_field( $recipient['name'] ?? '' ),
					'delay_seconds'     => absint( $event_settings['delay_seconds'] ?? 0 ),
					'queue_immediately' => ! empty( $event_settings['queue_immediately'] ),
					'priority'          => absint( $recipient['priority'] ?? 5 ),
					'force'             => $force,
				]
			);

			if ( $queue_id ) {
				$result['queued']++;
				$this->logger->log_event( $event_key, (int) $order->get_id(), Logger::LEVEL_INFO,
					sprintf( 'Admin notification queued (#%d) to %s (%s).', $queue_id, esc_html( $recipient['name'] ?? '' ), Helpers::mask_phone( $phone ) ),
					[ 'queue_id' => $queue_id, 'recipient_type' => 'admin', 'recipient_name' => $recipient['name'] ?? '' ]
				);
			} elseif ( false === $queue_id ) {
				// Duplicate — silently skip.
			} else {
				$result['errors'][] = "Failed to queue admin notification for {$recipient['name']}.";
			}
		}

		if ( $result['queued'] > 0 ) {
			$this->logger->add_order_note( (int) $order->get_id(),
				sprintf(
					/* translators: 1: count, 2: event label */
					__( 'WhatsApp queued → %1$d admin recipient(s) — Event: %2$s.', 'tsh-whatsapp-notify' ),
					$result['queued'],
					OrderStatusListener::event_label( $event_key )
				)
			);
		}

		return $result;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Load a WC_Order by ID. Returns null on failure.
	 *
	 * @param int $order_id
	 * @return \WC_Order|null
	 */
	private function load_order( int $order_id ): ?\WC_Order {
		if ( $order_id <= 0 ) {
			return null;
		}

		$order = wc_get_order( $order_id );

		return ( $order instanceof \WC_Order ) ? $order : null;
	}

	/**
	 * Return per-event configuration from settings.
	 *
	 * @param string $event_key
	 * @return array<string, mixed>
	 */
	private function get_event_settings( string $event_key ): array {
		$all_events = get_option( 'tsh_wa_wc_events_settings', [] );
		return $all_events[ $event_key ] ?? [];
	}

	/**
	 * Resolve a template slug to its body text.
	 * Falls back to a sensible default message if no template is configured.
	 *
	 * @param string $template_slug
	 * @param string $event_key
	 * @param string $recipient_type 'customer' | 'admin'
	 * @return string Template body with {placeholder} tokens intact.
	 */
	private function resolve_template( string $template_slug, string $event_key, string $recipient_type ): string {
		if ( $template_slug ) {
			global $wpdb;
			$table = $wpdb->prefix . 'tsh_wa_templates';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$body = $wpdb->get_var(
				$wpdb->prepare( "SELECT message_body FROM `{$table}` WHERE slug = %s AND status = 'active' LIMIT 1", $template_slug )
			);
			if ( $body ) {
				return $body;
			}
		}

		// Default built-in templates.
		return $this->get_default_template( $event_key, $recipient_type );
	}

	/**
	 * Return a default message template for a given event + recipient type.
	 *
	 * @param string $event_key
	 * @param string $recipient_type
	 * @return string
	 */
	private function get_default_template( string $event_key, string $recipient_type ): string {
		$event_label = OrderStatusListener::event_label( $event_key );

		if ( 'admin' === $recipient_type ) {
			return sprintf(
				"🛒 *New Order Notification*\n\n*Event:* %s\n*Order:* #{order_number}\n*Customer:* {customer_name}\n*Phone:* {customer_phone}\n*Total:* {total}\n*Payment:* {payment_method}\n\n*Products:*\n{products}\n\n🔗 {admin_order_url}",
				$event_label
			);
		}

		return "Hello {customer_name}! 👋\n\nThank you for your order at *{store_name}*.\n\n*Order #{order_number}* — {event_label}\n\n*Items:*\n{products}\n\n*Total:* {total}\n*Payment:* {payment_method}\n\nTrack your order: {customer_order_url}";
	}
}
