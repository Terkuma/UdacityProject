<?php
/**
 * Action definitions and execution engine.
 *
 * @package TSH\WhatsAppNotify\Automation
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Automation;

use TSH\WhatsAppNotify\Queue\Queue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ActionManager
 *
 * Defines every supported action node type and executes them.
 */
class ActionManager {

	/**
	 * Return all supported action definitions.
	 *
	 * @return array<string, array>
	 */
	public static function get_actions(): array {
		return [
			'send_whatsapp'   => [
				'label'       => 'Send WhatsApp',
				'group'       => 'WhatsApp',
				'icon'        => '💬',
				'color'       => '#25d366',
				'description' => 'Send a WhatsApp message immediately via the queue.',
				'fields'      => [
					[ 'key' => 'phone_source', 'label' => 'Phone Source', 'type' => 'select',
					  'options' => [ 'order_billing' => 'Order Billing Phone', 'customer' => 'Customer Phone', 'custom' => 'Custom Number' ] ],
					[ 'key' => 'custom_phone', 'label' => 'Custom Phone Number', 'type' => 'text', 'depends_on' => [ 'phone_source' => 'custom' ] ],
					[ 'key' => 'message', 'label' => 'Message', 'type' => 'textarea', 'required' => true, 'supports_variables' => true ],
					[ 'key' => 'priority', 'label' => 'Priority', 'type' => 'number', 'default' => 5, 'min' => 1, 'max' => 10 ],
				],
			],
			'queue_whatsapp'  => [
				'label'       => 'Queue WhatsApp',
				'group'       => 'WhatsApp',
				'icon'        => '📤',
				'color'       => '#128c7e',
				'description' => 'Add a WhatsApp message to the queue for scheduled delivery.',
				'fields'      => [
					[ 'key' => 'phone_source', 'label' => 'Phone Source', 'type' => 'select',
					  'options' => [ 'order_billing' => 'Order Billing Phone', 'customer' => 'Customer Phone', 'custom' => 'Custom Number' ] ],
					[ 'key' => 'custom_phone', 'label' => 'Custom Phone Number', 'type' => 'text', 'depends_on' => [ 'phone_source' => 'custom' ] ],
					[ 'key' => 'message', 'label' => 'Message', 'type' => 'textarea', 'required' => true, 'supports_variables' => true ],
					[ 'key' => 'send_at', 'label' => 'Schedule At', 'type' => 'text', 'placeholder' => 'e.g. +2 hours, tomorrow 09:00' ],
					[ 'key' => 'priority', 'label' => 'Priority', 'type' => 'number', 'default' => 5, 'min' => 1, 'max' => 10 ],
				],
			],
			'wait'            => [
				'label'       => 'Wait / Delay',
				'group'       => 'Flow Control',
				'icon'        => '⏳',
				'color'       => '#6366f1',
				'description' => 'Pause execution for a set time before continuing.',
				'fields'      => [
					[ 'key' => 'delay_type', 'label' => 'Delay Type', 'type' => 'select',
					  'options' => [ 'minutes' => 'Minutes', 'hours' => 'Hours', 'days' => 'Days', 'specific_datetime' => 'Specific Date & Time', 'business_hours' => 'Until Next Business Hours' ] ],
					[ 'key' => 'delay_value', 'label' => 'Amount', 'type' => 'number', 'default' => 1,
					  'depends_on' => [ 'delay_type' => [ 'minutes', 'hours', 'days' ] ] ],
					[ 'key' => 'specific_datetime', 'label' => 'Date & Time', 'type' => 'datetime',
					  'depends_on' => [ 'delay_type' => 'specific_datetime' ] ],
					[ 'key' => 'business_start', 'label' => 'Business Hours Start', 'type' => 'time', 'default' => '09:00',
					  'depends_on' => [ 'delay_type' => 'business_hours' ] ],
					[ 'key' => 'business_end', 'label' => 'Business Hours End', 'type' => 'time', 'default' => '17:00',
					  'depends_on' => [ 'delay_type' => 'business_hours' ] ],
				],
			],
			'condition'       => [
				'label'       => 'Condition / Branch',
				'group'       => 'Flow Control',
				'icon'        => '🔀',
				'color'       => '#f59e0b',
				'description' => 'Split the flow based on conditions. YES branch runs if all conditions pass.',
				'fields'      => [
					[ 'key' => 'logic', 'label' => 'Logic', 'type' => 'select', 'options' => [ 'AND' => 'All Conditions (AND)', 'OR' => 'Any Condition (OR)' ] ],
					[ 'key' => 'conditions', 'label' => 'Conditions', 'type' => 'condition_builder' ],
				],
				'outputs'     => [ 'yes' => 'Yes / True', 'no' => 'No / False' ],
			],
			'add_order_note'  => [
				'label'       => 'Add Order Note',
				'group'       => 'WooCommerce',
				'icon'        => '📝',
				'color'       => '#8b5cf6',
				'description' => 'Add a note to the WooCommerce order.',
				'fields'      => [
					[ 'key' => 'note', 'label' => 'Note Text', 'type' => 'textarea', 'required' => true, 'supports_variables' => true ],
					[ 'key' => 'note_type', 'label' => 'Note Type', 'type' => 'select', 'options' => [ 'private' => 'Private (Internal)', 'customer' => 'Customer Visible' ] ],
				],
			],
			'update_order_status' => [
				'label'       => 'Update Order Status',
				'group'       => 'WooCommerce',
				'icon'        => '🔄',
				'color'       => '#8b5cf6',
				'description' => 'Change the WooCommerce order status.',
				'fields'      => [
					[ 'key' => 'status', 'label' => 'New Status', 'type' => 'order_status', 'required' => true ],
					[ 'key' => 'note', 'label' => 'Transition Note', 'type' => 'text', 'supports_variables' => true ],
				],
			],
			'create_coupon'   => [
				'label'       => 'Create Coupon',
				'group'       => 'WooCommerce',
				'icon'        => '🎟️',
				'color'       => '#8b5cf6',
				'description' => 'Generate a unique coupon code and store it in the step output.',
				'fields'      => [
					[ 'key' => 'discount_type', 'label' => 'Discount Type', 'type' => 'select', 'options' => [ 'percent' => 'Percentage', 'fixed_cart' => 'Fixed Amount' ] ],
					[ 'key' => 'amount', 'label' => 'Amount', 'type' => 'number', 'required' => true ],
					[ 'key' => 'expiry_days', 'label' => 'Expires In (days)', 'type' => 'number', 'default' => 7 ],
					[ 'key' => 'prefix', 'label' => 'Code Prefix', 'type' => 'text', 'default' => 'AUTO' ],
					[ 'key' => 'usage_limit', 'label' => 'Usage Limit', 'type' => 'number', 'default' => 1 ],
				],
			],
			'assign_conversation' => [
				'label'       => 'Assign Conversation',
				'group'       => 'Inbox',
				'icon'        => '👥',
				'color'       => '#06b6d4',
				'description' => 'Assign the WhatsApp conversation to an agent.',
				'fields'      => [
					[ 'key' => 'agent_id', 'label' => 'Agent', 'type' => 'user_select', 'required' => true ],
				],
			],
			'update_customer_label' => [
				'label'       => 'Update Customer Label',
				'group'       => 'Inbox',
				'icon'        => '🏷️',
				'color'       => '#06b6d4',
				'description' => 'Add or remove a label on the customer\'s WhatsApp conversation.',
				'fields'      => [
					[ 'key' => 'action', 'label' => 'Action', 'type' => 'select', 'options' => [ 'add' => 'Add Label', 'remove' => 'Remove Label' ] ],
					[ 'key' => 'label', 'label' => 'Label', 'type' => 'text', 'required' => true ],
				],
			],
			'webhook'         => [
				'label'       => 'Outgoing Webhook',
				'group'       => 'Advanced',
				'icon'        => '🌐',
				'color'       => '#374151',
				'description' => 'Send an HTTP POST request to an external URL.',
				'fields'      => [
					[ 'key' => 'url', 'label' => 'Webhook URL', 'type' => 'text', 'required' => true, 'supports_variables' => true ],
					[ 'key' => 'method', 'label' => 'Method', 'type' => 'select', 'options' => [ 'POST' => 'POST', 'GET' => 'GET', 'PUT' => 'PUT', 'PATCH' => 'PATCH' ] ],
					[ 'key' => 'payload', 'label' => 'JSON Payload', 'type' => 'textarea', 'supports_variables' => true ],
					[ 'key' => 'headers', 'label' => 'Custom Headers', 'type' => 'key_value' ],
				],
			],
			'send_email'      => [
				'label'       => 'Send Email',
				'group'       => 'Advanced',
				'icon'        => '📧',
				'color'       => '#374151',
				'description' => 'Send an email via wp_mail().',
				'fields'      => [
					[ 'key' => 'to', 'label' => 'To', 'type' => 'text', 'required' => true, 'supports_variables' => true ],
					[ 'key' => 'subject', 'label' => 'Subject', 'type' => 'text', 'required' => true, 'supports_variables' => true ],
					[ 'key' => 'body', 'label' => 'Body', 'type' => 'textarea', 'supports_variables' => true ],
				],
			],
			'log_event'       => [
				'label'       => 'Log Event',
				'group'       => 'Advanced',
				'icon'        => '📋',
				'color'       => '#374151',
				'description' => 'Write a message to the workflow execution log.',
				'fields'      => [
					[ 'key' => 'message', 'label' => 'Log Message', 'type' => 'text', 'required' => true, 'supports_variables' => true ],
					[ 'key' => 'level', 'label' => 'Level', 'type' => 'select', 'options' => [ 'info' => 'Info', 'warning' => 'Warning', 'error' => 'Error' ] ],
				],
			],
		];
	}

	/**
	 * Execute a single action node.
	 *
	 * @param string $action_type   Action type key.
	 * @param array  $config        Node configuration (field values, already variable-resolved).
	 * @param array  $context       Current execution context.
	 * @return array{ success: bool, output: array, error: string }
	 */
	public function execute( string $action_type, array $config, array $context ): array {
		$result = [
			'success' => false,
			'output'  => [],
			'error'   => '',
		];

		try {
			switch ( $action_type ) {
				case 'send_whatsapp':
				case 'queue_whatsapp':
					$result = $this->action_send_whatsapp( $config, $context, 'queue_whatsapp' === $action_type );
					break;

				case 'add_order_note':
					$result = $this->action_add_order_note( $config, $context );
					break;

				case 'update_order_status':
					$result = $this->action_update_order_status( $config, $context );
					break;

				case 'create_coupon':
					$result = $this->action_create_coupon( $config, $context );
					break;

				case 'assign_conversation':
					$result = $this->action_assign_conversation( $config, $context );
					break;

				case 'update_customer_label':
					$result = $this->action_update_customer_label( $config, $context );
					break;

				case 'webhook':
					$result = $this->action_webhook( $config );
					break;

				case 'send_email':
					$result = $this->action_send_email( $config );
					break;

				case 'log_event':
					$result = [
						'success' => true,
						'output'  => [ 'message' => $config['message'] ?? '' ],
						'error'   => '',
					];
					break;

				case 'wait':
					// Handled by WorkflowRunner / DelayManager — mark as success here.
					$result = [ 'success' => true, 'output' => [ 'delayed' => true ], 'error' => '' ];
					break;

				case 'condition':
					// Evaluated by WorkflowRunner before calling this.
					$result = [ 'success' => true, 'output' => [], 'error' => '' ];
					break;

				default:
					$result['error'] = 'Unknown action type: ' . $action_type;
			}
		} catch ( \Throwable $e ) {
			$result['success'] = false;
			$result['error']   = $e->getMessage();
		}

		return $result;
	}

	// -------------------------------------------------------------------------
	// Action implementations
	// -------------------------------------------------------------------------

	private function action_send_whatsapp( array $config, array $context, bool $scheduled ): array {
		$phone = $this->resolve_phone( $config, $context );

		if ( ! $phone ) {
			return [ 'success' => false, 'output' => [], 'error' => 'Could not resolve phone number.' ];
		}

		$message = $config['message'] ?? '';
		if ( ! $message ) {
			return [ 'success' => false, 'output' => [], 'error' => 'Message is empty.' ];
		}

		$order_id  = (int) ( $context['order_id'] ?? $context['trigger_data']['order_id'] ?? 0 );
		$send_at   = $scheduled && ! empty( $config['send_at'] )
			? $this->parse_send_at( $config['send_at'] )
			: current_time( 'mysql' );

		$queue    = new Queue();
		$queue_id = $queue->add( [
			'phone'        => $phone,
			'message'      => $message,
			'order_id'     => $order_id ?: null,
			'priority'     => absint( $config['priority'] ?? 5 ),
			'scheduled_at' => $send_at,
			'meta'         => [
				'source'      => 'automation',
				'workflow_id' => $context['workflow_id'] ?? null,
				'run_id'      => $context['run_id'] ?? null,
			],
		] );

		if ( ! $queue_id ) {
			return [ 'success' => false, 'output' => [], 'error' => 'Failed to enqueue WhatsApp message.' ];
		}

		return [ 'success' => true, 'output' => [ 'queue_id' => $queue_id, 'phone' => $phone ], 'error' => '' ];
	}

	private function action_add_order_note( array $config, array $context ): array {
		$order_id = (int) ( $context['order_id'] ?? $context['trigger_data']['order_id'] ?? 0 );
		if ( ! $order_id ) {
			return [ 'success' => false, 'output' => [], 'error' => 'No order ID in context.' ];
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return [ 'success' => false, 'output' => [], 'error' => 'Order not found.' ];
		}

		$note        = $config['note'] ?? '';
		$customer_note = ( $config['note_type'] ?? 'private' ) === 'customer';

		$note_id = $order->add_order_note( $note, $customer_note ? 1 : 0 );

		return [ 'success' => (bool) $note_id, 'output' => [ 'note_id' => $note_id ], 'error' => '' ];
	}

	private function action_update_order_status( array $config, array $context ): array {
		$order_id = (int) ( $context['order_id'] ?? $context['trigger_data']['order_id'] ?? 0 );
		if ( ! $order_id ) {
			return [ 'success' => false, 'output' => [], 'error' => 'No order ID in context.' ];
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return [ 'success' => false, 'output' => [], 'error' => 'Order not found.' ];
		}

		$new_status = sanitize_key( $config['status'] ?? '' );
		if ( ! $new_status ) {
			return [ 'success' => false, 'output' => [], 'error' => 'Status not specified.' ];
		}

		$order->update_status( $new_status, $config['note'] ?? 'Updated by automation.' );

		return [ 'success' => true, 'output' => [ 'new_status' => $new_status ], 'error' => '' ];
	}

	private function action_create_coupon( array $config, array $context ): array {
		$prefix  = sanitize_text_field( $config['prefix'] ?? 'AUTO' );
		$code    = strtoupper( $prefix . '-' . wp_generate_password( 8, false ) );
		$amount  = (float) ( $config['amount'] ?? 10 );
		$expires = absint( $config['expiry_days'] ?? 7 );
		$type    = sanitize_key( $config['discount_type'] ?? 'percent' );
		$limit   = absint( $config['usage_limit'] ?? 1 );

		$coupon = new \WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( $type );
		$coupon->set_amount( $amount );
		$coupon->set_usage_limit( $limit );
		if ( $expires ) {
			$coupon->set_date_expires( strtotime( '+' . $expires . ' days' ) );
		}
		$coupon->save();

		return [
			'success' => (bool) $coupon->get_id(),
			'output'  => [ 'coupon_code' => $code, 'coupon_id' => $coupon->get_id() ],
			'error'   => '',
		];
	}

	private function action_assign_conversation( array $config, array $context ): array {
		if ( ! class_exists( '\\TSH\\WhatsAppNotify\\Inbox\\InboxManager' ) ) {
			return [ 'success' => false, 'output' => [], 'error' => 'Inbox not available.' ];
		}

		$phone = $this->get_phone_from_context( $context );
		if ( ! $phone ) {
			return [ 'success' => false, 'output' => [], 'error' => 'Could not resolve phone for conversation.' ];
		}

		$repo = new \TSH\WhatsAppNotify\Inbox\ConversationRepository();
		$conv = $repo->get_or_create_conversation( $phone );
		if ( ! $conv ) {
			return [ 'success' => false, 'output' => [], 'error' => 'Could not find/create conversation.' ];
		}

		$manager = new \TSH\WhatsAppNotify\Inbox\InboxManager();
		$ok      = $manager->assign_conversation( (int) $conv['id'], absint( $config['agent_id'] ?? 0 ) );

		return [ 'success' => $ok, 'output' => [ 'conversation_id' => $conv['id'] ], 'error' => $ok ? '' : 'Assignment failed.' ];
	}

	private function action_update_customer_label( array $config, array $context ): array {
		if ( ! class_exists( '\\TSH\\WhatsAppNotify\\Inbox\\InboxManager' ) ) {
			return [ 'success' => false, 'output' => [], 'error' => 'Inbox not available.' ];
		}

		$phone = $this->get_phone_from_context( $context );
		if ( ! $phone ) {
			return [ 'success' => false, 'output' => [], 'error' => 'Could not resolve phone.' ];
		}

		$repo  = new \TSH\WhatsAppNotify\Inbox\ConversationRepository();
		$conv  = $repo->get_or_create_conversation( $phone );
		if ( ! $conv ) {
			return [ 'success' => false, 'output' => [], 'error' => 'Conversation not found.' ];
		}

		$manager = new \TSH\WhatsAppNotify\Inbox\InboxManager();
		$label   = sanitize_key( $config['label'] ?? '' );
		$action  = $config['action'] ?? 'add';

		$ok = 'remove' === $action
			? $manager->remove_label( (int) $conv['id'], $label )
			: $manager->add_label( (int) $conv['id'], $label );

		return [ 'success' => (bool) $ok, 'output' => [ 'label' => $label ], 'error' => '' ];
	}

	private function action_webhook( array $config ): array {
		$url     = $config['url'] ?? '';
		$method  = strtoupper( $config['method'] ?? 'POST' );
		$payload = $config['payload'] ?? '';
		$headers = $config['headers'] ?? [];

		if ( ! $url || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return [ 'success' => false, 'output' => [], 'error' => 'Invalid webhook URL.' ];
		}

		$request_headers = array_merge(
			[ 'Content-Type' => 'application/json', 'User-Agent' => 'TSH-WA-Automation/1.0' ],
			(array) $headers
		);

		$response = wp_remote_request( $url, [
			'method'    => $method,
			'headers'   => $request_headers,
			'body'      => $payload,
			'timeout'   => 15,
			'sslverify' => true,
		] );

		if ( is_wp_error( $response ) ) {
			return [ 'success' => false, 'output' => [], 'error' => $response->get_error_message() ];
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$ok   = $code >= 200 && $code < 300;

		return [
			'success' => $ok,
			'output'  => [ 'status_code' => $code, 'response_body' => substr( (string) $body, 0, 500 ) ],
			'error'   => $ok ? '' : "HTTP {$code}: " . substr( (string) $body, 0, 200 ),
		];
	}

	private function action_send_email( array $config ): array {
		$to      = $config['to'] ?? '';
		$subject = $config['subject'] ?? '';
		$body    = $config['body'] ?? '';

		if ( ! $to || ! $subject ) {
			return [ 'success' => false, 'output' => [], 'error' => 'Email recipient and subject are required.' ];
		}

		$sent = wp_mail( $to, $subject, $body );

		return [ 'success' => $sent, 'output' => [ 'to' => $to ], 'error' => $sent ? '' : 'wp_mail() returned false.' ];
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function resolve_phone( array $config, array $context ): string {
		$source = $config['phone_source'] ?? 'order_billing';

		if ( 'custom' === $source ) {
			return sanitize_text_field( $config['custom_phone'] ?? '' );
		}

		return $this->get_phone_from_context( $context );
	}

	private function get_phone_from_context( array $context ): string {
		$order_id = (int) ( $context['order_id'] ?? $context['trigger_data']['order_id'] ?? 0 );

		if ( $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$phone = $order->get_billing_phone();
				if ( $phone ) {
					return $phone;
				}
			}
		}

		$customer_id = (int) ( $context['customer_id'] ?? $context['trigger_data']['customer_id'] ?? 0 );

		if ( $customer_id ) {
			$phone = get_user_meta( $customer_id, 'billing_phone', true );
			if ( $phone ) {
				return (string) $phone;
			}
		}

		return (string) ( $context['trigger_data']['phone'] ?? '' );
	}

	private function parse_send_at( string $value ): string {
		$ts = strtotime( $value );

		return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : current_time( 'mysql' );
	}
}
