<?php
/**
 * Workflow export and built-in template library.
 *
 * @package TSH\WhatsAppNotify\Automation
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Automation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WorkflowExporter
 */
class WorkflowExporter {

	private WorkflowRepository $repo;

	public function __construct() {
		$this->repo = new WorkflowRepository();
	}

	/**
	 * Export workflows as JSON.
	 *
	 * @param array $workflow_ids  Empty = export all.
	 * @return string JSON string.
	 */
	public function export( array $workflow_ids = [] ): string {
		if ( $workflow_ids ) {
			$workflows = [];
			foreach ( $workflow_ids as $id ) {
				$wf = $this->repo->get_workflow( (int) $id );
				if ( $wf ) {
					$workflows[] = $this->sanitize_for_export( $wf );
				}
			}
		} else {
			$result    = $this->repo->get_workflows( [ 'per_page' => 500, 'status' => 'all' ] );
			$workflows = array_map( [ $this, 'sanitize_for_export' ], $result['rows'] );
		}

		return wp_json_encode( [
			'version'    => '7.0.0',
			'exported_at'=> current_time( 'c' ),
			'site_url'   => get_bloginfo( 'url' ),
			'workflows'  => $workflows,
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Remove runtime-only fields before exporting.
	 */
	private function sanitize_for_export( array $wf ): array {
		unset( $wf['run_count'], $wf['last_run_at'] );
		$wf['status'] = 'draft'; // Always import as draft for safety.
		return $wf;
	}

	// -------------------------------------------------------------------------
	// Built-in template library
	// -------------------------------------------------------------------------

	/**
	 * Return the built-in workflow template library.
	 *
	 * @return array<string, array>
	 */
	public static function get_templates(): array {
		return [
			'order_confirmation' => [
				'name'         => 'Order Confirmation',
				'description'  => 'Send a WhatsApp confirmation when an order is placed.',
				'trigger_type' => 'wc_order_processing',
				'trigger_config'=> [],
				'nodes'        => [
					[
						'id'     => 'trigger_1',
						'type'   => 'trigger',
						'category'=> 'trigger',
						'label'  => 'Order Processing',
						'position'=> [ 'x' => 100, 'y' => 100 ],
						'config' => [],
					],
					[
						'id'     => 'action_1',
						'type'   => 'send_whatsapp',
						'label'  => 'Send Confirmation',
						'position'=> [ 'x' => 100, 'y' => 260 ],
						'config' => [
							'phone_source' => 'order_billing',
							'message'      => "🎉 Hi {{customer.first_name}}! Your order #{{order.number}} has been confirmed.\n\nTotal: {{order.total}}\nItems: {{order.items_summary}}\n\nThank you for shopping with {{site.name}}!",
							'priority'     => 3,
						],
					],
				],
				'edges' => [
					[ 'id' => 'e1', 'source' => 'trigger_1', 'target' => 'action_1' ],
				],
				'settings' => [],
			],

			'payment_reminder' => [
				'name'         => 'Payment Reminder (Pending)',
				'description'  => 'Remind customers to complete payment after 2 hours.',
				'trigger_type' => 'wc_order_new',
				'trigger_config'=> [],
				'nodes'        => [
					[ 'id' => 'trigger_1', 'type' => 'trigger', 'category' => 'trigger', 'label' => 'New Order', 'position' => [ 'x' => 100, 'y' => 100 ], 'config' => [] ],
					[ 'id' => 'wait_1',    'type' => 'wait',    'label' => 'Wait 2 Hours', 'position' => [ 'x' => 100, 'y' => 260 ],
					  'config' => [ 'delay_type' => 'hours', 'delay_value' => 2 ] ],
					[ 'id' => 'cond_1',    'type' => 'condition', 'label' => 'Still Pending?', 'position' => [ 'x' => 100, 'y' => 420 ],
					  'config' => [ 'logic' => 'AND', 'conditions' => [
						  [ 'condition_type' => 'order_status', 'operator' => 'is', 'value' => 'pending' ],
					  ] ] ],
					[ 'id' => 'action_1',  'type' => 'send_whatsapp', 'label' => 'Send Reminder', 'position' => [ 'x' => -60, 'y' => 580 ],
					  'config' => [
						  'phone_source' => 'order_billing',
						  'message'      => "⏰ Hi {{customer.first_name}}, your order #{{order.number}} is waiting for payment.\n\nTotal: {{order.total}}\n\nComplete your payment here: {{order.view_url}}",
					  ] ],
				],
				'edges' => [
					[ 'id' => 'e1', 'source' => 'trigger_1', 'target' => 'wait_1' ],
					[ 'id' => 'e2', 'source' => 'wait_1',    'target' => 'cond_1' ],
					[ 'id' => 'e3', 'source' => 'cond_1',    'target' => 'action_1', 'label' => 'yes' ],
				],
				'settings' => [],
			],

			'review_request' => [
				'name'         => 'Review Request',
				'description'  => 'Ask customers for a review 3 days after order completion.',
				'trigger_type' => 'wc_order_completed',
				'trigger_config'=> [],
				'nodes'        => [
					[ 'id' => 'trigger_1', 'type' => 'trigger', 'category' => 'trigger', 'label' => 'Order Completed', 'position' => [ 'x' => 100, 'y' => 100 ], 'config' => [] ],
					[ 'id' => 'wait_1',    'type' => 'wait',    'label' => 'Wait 3 Days', 'position' => [ 'x' => 100, 'y' => 260 ],
					  'config' => [ 'delay_type' => 'days', 'delay_value' => 3 ] ],
					[ 'id' => 'action_1',  'type' => 'send_whatsapp', 'label' => 'Send Review Request', 'position' => [ 'x' => 100, 'y' => 420 ],
					  'config' => [
						  'phone_source' => 'order_billing',
						  'message'      => "⭐ Hi {{customer.first_name}}! We hope you love your order #{{order.number}}.\n\nWould you mind leaving us a quick review? It means the world to us!\n\nShop: {{site.url}}",
					  ] ],
				],
				'edges' => [
					[ 'id' => 'e1', 'source' => 'trigger_1', 'target' => 'wait_1' ],
					[ 'id' => 'e2', 'source' => 'wait_1',    'target' => 'action_1' ],
				],
				'settings' => [],
			],

			'abandoned_cart' => [
				'name'         => 'Abandoned Cart Recovery',
				'description'  => 'Remind customers who placed an order but haven\'t paid after 1 hour.',
				'trigger_type' => 'wc_order_failed',
				'trigger_config'=> [],
				'nodes'        => [
					[ 'id' => 'trigger_1', 'type' => 'trigger', 'category' => 'trigger', 'label' => 'Order Failed', 'position' => [ 'x' => 100, 'y' => 100 ], 'config' => [] ],
					[ 'id' => 'wait_1',    'type' => 'wait',    'label' => 'Wait 1 Hour', 'position' => [ 'x' => 100, 'y' => 260 ],
					  'config' => [ 'delay_type' => 'hours', 'delay_value' => 1 ] ],
					[ 'id' => 'action_1',  'type' => 'send_whatsapp', 'label' => 'Recovery Message', 'position' => [ 'x' => 100, 'y' => 420 ],
					  'config' => [
						  'phone_source' => 'order_billing',
						  'message'      => "👋 Hi {{customer.first_name}}! It looks like you left something behind.\n\nYour cart total: {{order.total}}\n\nDon't miss out — complete your order: {{order.view_url}}",
					  ] ],
				],
				'edges' => [
					[ 'id' => 'e1', 'source' => 'trigger_1', 'target' => 'wait_1' ],
					[ 'id' => 'e2', 'source' => 'wait_1',    'target' => 'action_1' ],
				],
				'settings' => [],
			],

			'shipping_update' => [
				'name'         => 'Shipping Update',
				'description'  => 'Notify customers when their order ships.',
				'trigger_type' => 'wc_order_completed',
				'trigger_config'=> [],
				'nodes'        => [
					[ 'id' => 'trigger_1', 'type' => 'trigger', 'category' => 'trigger', 'label' => 'Order Completed', 'position' => [ 'x' => 100, 'y' => 100 ], 'config' => [] ],
					[ 'id' => 'action_1',  'type' => 'send_whatsapp', 'label' => 'Shipping Notification', 'position' => [ 'x' => 100, 'y' => 260 ],
					  'config' => [
						  'phone_source' => 'order_billing',
						  'message'      => "📦 Great news, {{customer.first_name}}! Your order #{{order.number}} has been shipped.\n\nExpected delivery: 3–5 business days.\n\nShipping to: {{order.shipping_address}}\n\nThank you, {{site.name}}",
					  ] ],
				],
				'edges' => [
					[ 'id' => 'e1', 'source' => 'trigger_1', 'target' => 'action_1' ],
				],
				'settings' => [],
			],

			'coupon_reminder' => [
				'name'         => 'Post-Purchase Coupon',
				'description'  => 'Send a discount coupon to customers after their first order.',
				'trigger_type' => 'wc_order_completed',
				'trigger_config'=> [],
				'nodes'        => [
					[ 'id' => 'trigger_1', 'type' => 'trigger', 'category' => 'trigger', 'label' => 'Order Completed', 'position' => [ 'x' => 100, 'y' => 100 ], 'config' => [] ],
					[ 'id' => 'cond_1',    'type' => 'condition', 'label' => 'First Order?', 'position' => [ 'x' => 100, 'y' => 260 ],
					  'config' => [ 'logic' => 'AND', 'conditions' => [
						  [ 'condition_type' => 'purchase_count', 'operator' => 'eq', 'value' => '1' ],
					  ] ] ],
					[ 'id' => 'action_coupon', 'type' => 'create_coupon', 'label' => 'Create Coupon', 'position' => [ 'x' => -60, 'y' => 420 ],
					  'config' => [ 'discount_type' => 'percent', 'amount' => 15, 'expiry_days' => 14, 'prefix' => 'THANKS' ] ],
					[ 'id' => 'action_msg',    'type' => 'send_whatsapp', 'label' => 'Send Coupon', 'position' => [ 'x' => -60, 'y' => 580 ],
					  'config' => [
						  'phone_source' => 'order_billing',
						  'message'      => "🎁 Thank you for your first order, {{customer.first_name}}!\n\nHere's 15% off your next purchase:\n*{{step.action_coupon.coupon_code}}*\n\nValid for 14 days. Shop again: {{site.url}}",
					  ] ],
				],
				'edges' => [
					[ 'id' => 'e1', 'source' => 'trigger_1', 'target' => 'cond_1' ],
					[ 'id' => 'e2', 'source' => 'cond_1', 'target' => 'action_coupon', 'label' => 'yes' ],
					[ 'id' => 'e3', 'source' => 'action_coupon', 'target' => 'action_msg' ],
				],
				'settings' => [],
			],

			'vip_customer' => [
				'name'         => 'VIP Customer Welcome',
				'description'  => 'Send a special message when a customer reaches high lifetime value.',
				'trigger_type' => 'wc_order_completed',
				'trigger_config'=> [],
				'nodes'        => [
					[ 'id' => 'trigger_1', 'type' => 'trigger', 'category' => 'trigger', 'label' => 'Order Completed', 'position' => [ 'x' => 100, 'y' => 100 ], 'config' => [] ],
					[ 'id' => 'cond_1',    'type' => 'condition', 'label' => 'LTV > 500?', 'position' => [ 'x' => 100, 'y' => 260 ],
					  'config' => [ 'logic' => 'AND', 'conditions' => [
						  [ 'condition_type' => 'customer_ltv', 'operator' => 'gte', 'value' => '500' ],
					  ] ] ],
					[ 'id' => 'action_1',  'type' => 'send_whatsapp', 'label' => 'VIP Welcome', 'position' => [ 'x' => -60, 'y' => 420 ],
					  'config' => [
						  'phone_source' => 'order_billing',
						  'message'      => "👑 Congratulations {{customer.first_name}}! You've officially become a VIP customer!\n\nThank you for your continued support of {{site.name}}. You'll now receive priority support and exclusive offers.",
					  ] ],
					[ 'id' => 'action_label', 'type' => 'update_customer_label', 'label' => 'Add VIP Label', 'position' => [ 'x' => 260, 'y' => 420 ],
					  'config' => [ 'action' => 'add', 'label' => 'vip' ] ],
				],
				'edges' => [
					[ 'id' => 'e1', 'source' => 'trigger_1', 'target' => 'cond_1' ],
					[ 'id' => 'e2', 'source' => 'cond_1', 'target' => 'action_1', 'label' => 'yes' ],
					[ 'id' => 'e3', 'source' => 'cond_1', 'target' => 'action_label', 'label' => 'yes' ],
				],
				'settings' => [],
			],

			'win_back' => [
				'name'         => 'Win-Back Campaign',
				'description'  => 'Reach out to customers who haven\'t ordered in 60 days.',
				'trigger_type' => 'wc_order_completed',
				'trigger_config'=> [],
				'nodes'        => [
					[ 'id' => 'trigger_1', 'type' => 'trigger', 'category' => 'trigger', 'label' => 'Order Completed', 'position' => [ 'x' => 100, 'y' => 100 ], 'config' => [] ],
					[ 'id' => 'wait_1',    'type' => 'wait',    'label' => 'Wait 60 Days', 'position' => [ 'x' => 100, 'y' => 260 ],
					  'config' => [ 'delay_type' => 'days', 'delay_value' => 60 ] ],
					[ 'id' => 'action_1',  'type' => 'send_whatsapp', 'label' => 'Win-Back Message', 'position' => [ 'x' => 100, 'y' => 420 ],
					  'config' => [
						  'phone_source' => 'order_billing',
						  'message'      => "💙 Hi {{customer.first_name}}, we miss you!\n\nIt's been a while since your last order. We have new products waiting for you.\n\nCome back and enjoy 10% off with code: MISSYOU10\n\nShop now: {{site.url}}",
					  ] ],
				],
				'edges' => [
					[ 'id' => 'e1', 'source' => 'trigger_1', 'target' => 'wait_1' ],
					[ 'id' => 'e2', 'source' => 'wait_1',    'target' => 'action_1' ],
				],
				'settings' => [],
			],

			'low_stock_alert' => [
				'name'         => 'Low Stock Admin Alert',
				'description'  => 'Notify admin via WhatsApp when a product is low on stock.',
				'trigger_type' => 'wc_low_stock',
				'trigger_config'=> [],
				'nodes'        => [
					[ 'id' => 'trigger_1', 'type' => 'trigger', 'category' => 'trigger', 'label' => 'Low Stock', 'position' => [ 'x' => 100, 'y' => 100 ], 'config' => [] ],
					[ 'id' => 'action_1',  'type' => 'send_whatsapp', 'label' => 'Alert Admin', 'position' => [ 'x' => 100, 'y' => 260 ],
					  'config' => [
						  'phone_source' => 'custom',
						  'custom_phone' => '',
						  'message'      => "⚠️ Low Stock Alert — {{site.name}}\n\nProduct ID {{trigger.product_id}} is running low ({{trigger.stock}} units remaining).\n\nPlease restock soon.",
					  ] ],
				],
				'edges' => [
					[ 'id' => 'e1', 'source' => 'trigger_1', 'target' => 'action_1' ],
				],
				'settings' => [],
			],

			'new_customer_welcome' => [
				'name'         => 'New Customer Welcome',
				'description'  => 'Welcome new customers when they register.',
				'trigger_type' => 'customer_registered',
				'trigger_config'=> [],
				'nodes'        => [
					[ 'id' => 'trigger_1', 'type' => 'trigger', 'category' => 'trigger', 'label' => 'Customer Registered', 'position' => [ 'x' => 100, 'y' => 100 ], 'config' => [] ],
					[ 'id' => 'wait_1',    'type' => 'wait',    'label' => 'Wait 5 Minutes', 'position' => [ 'x' => 100, 'y' => 260 ],
					  'config' => [ 'delay_type' => 'minutes', 'delay_value' => 5 ] ],
					[ 'id' => 'action_1',  'type' => 'send_whatsapp', 'label' => 'Welcome Message', 'position' => [ 'x' => 100, 'y' => 420 ],
					  'config' => [
						  'phone_source' => 'customer',
						  'message'      => "👋 Welcome to {{site.name}}, {{customer.first_name}}!\n\nWe're so glad you're here. Explore our store and enjoy your first shopping experience.\n\nShop: {{site.url}}",
					  ] ],
				],
				'edges' => [
					[ 'id' => 'e1', 'source' => 'trigger_1', 'target' => 'wait_1' ],
					[ 'id' => 'e2', 'source' => 'wait_1',    'target' => 'action_1' ],
				],
				'settings' => [],
			],
		];
	}
}
