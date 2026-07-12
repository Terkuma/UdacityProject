<?php
/**
 * Built-in campaign templates / presets.
 *
 * @package TSH\WhatsAppNotify\Marketing
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Marketing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CampaignTemplates
 *
 * Returns a catalogue of ready-made campaign presets that users can clone.
 * Each preset is a complete campaign definition array (minus template_id, which
 * the user selects after cloning).
 */
final class CampaignTemplates {

	/**
	 * Return all built-in campaign presets.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_all(): array {
		return [
			[
				'id'          => 'welcome_new_customers',
				'name'        => __( 'Welcome New Customers', 'tsh-whatsapp-notify' ),
				'description' => __( 'Send a warm welcome message to customers who registered in the last 30 days but have not yet made a purchase.', 'tsh-whatsapp-notify' ),
				'category'    => 'onboarding',
				'type'        => CampaignRepository::TYPE_ONETIME,
				'audience_config' => [
					'type'   => 'never_purchased',
					'rules'  => [
						[
							'field'    => 'registration_date',
							'operator' => 'within_days',
							'value'    => 30,
						],
					],
					'logic'  => 'AND',
				],
				'throttle_config' => [
					'msgs_per_minute' => 10,
					'msgs_per_hour'   => 200,
					'batch_size'      => 50,
				],
				'message_config' => [
					'type'      => 'template',
					'variables' => [ '{{first_name}}', '{{store_name}}' ],
				],
				'coupon_config' => [],
			],
			[
				'id'          => 'win_back_lapsed',
				'name'        => __( 'Win-Back Lapsed Customers', 'tsh-whatsapp-notify' ),
				'description' => __( 'Re-engage customers who have not purchased in the last 90 days with an exclusive offer.', 'tsh-whatsapp-notify' ),
				'category'    => 'retention',
				'type'        => CampaignRepository::TYPE_ONETIME,
				'audience_config' => [
					'type'  => 'custom',
					'rules' => [
						[
							'field'    => 'last_purchase',
							'operator' => 'more_than_days_ago',
							'value'    => 90,
						],
						[
							'field'    => 'order_count',
							'operator' => 'greater_than',
							'value'    => 0,
						],
					],
					'logic' => 'AND',
				],
				'throttle_config' => [
					'msgs_per_minute' => 10,
					'msgs_per_hour'   => 200,
					'batch_size'      => 50,
				],
				'message_config' => [
					'type'      => 'template',
					'variables' => [ '{{first_name}}', '{{coupon_code}}', '{{coupon_expiry}}' ],
				],
				'coupon_config' => [
					'enabled'      => true,
					'discount_type'=> 'percent',
					'amount'       => 15,
					'expiry_days'  => 14,
					'usage_limit'  => 1,
				],
			],
			[
				'id'          => 'vip_exclusive',
				'name'        => __( 'VIP Exclusive Offer', 'tsh-whatsapp-notify' ),
				'description' => __( 'Reward your highest-value customers with an exclusive deal. Targets customers with lifetime spend over $500.', 'tsh-whatsapp-notify' ),
				'category'    => 'loyalty',
				'type'        => CampaignRepository::TYPE_ONETIME,
				'audience_config' => [
					'type'  => 'high_spend',
					'rules' => [
						[
							'field'    => 'lifetime_value',
							'operator' => 'greater_than',
							'value'    => 500,
						],
					],
					'logic' => 'AND',
				],
				'throttle_config' => [
					'msgs_per_minute' => 5,
					'msgs_per_hour'   => 100,
					'batch_size'      => 25,
				],
				'message_config' => [
					'type'      => 'template',
					'variables' => [ '{{first_name}}', '{{coupon_code}}', '{{expiry_date}}' ],
				],
				'coupon_config' => [
					'enabled'       => true,
					'discount_type' => 'percent',
					'amount'        => 20,
					'expiry_days'   => 7,
					'usage_limit'   => 1,
					'min_spend'     => 100,
				],
			],
			[
				'id'          => 'product_launch',
				'name'        => __( 'New Product Launch', 'tsh-whatsapp-notify' ),
				'description' => __( 'Announce a new product to all previous buyers. Ideal for follow-on products or collections.', 'tsh-whatsapp-notify' ),
				'category'    => 'promotional',
				'type'        => CampaignRepository::TYPE_ONETIME,
				'audience_config' => [
					'type'  => 'previous_buyers',
					'rules' => [],
					'logic' => 'AND',
				],
				'throttle_config' => [
					'msgs_per_minute' => 20,
					'msgs_per_hour'   => 500,
					'batch_size'      => 100,
				],
				'message_config' => [
					'type'      => 'template',
					'variables' => [ '{{first_name}}', '{{product_name}}', '{{product_url}}' ],
				],
				'coupon_config' => [],
			],
			[
				'id'          => 'weekly_newsletter',
				'name'        => __( 'Weekly Newsletter', 'tsh-whatsapp-notify' ),
				'description' => __( 'A recurring weekly broadcast to all opted-in customers. Runs every Monday at 10 AM.', 'tsh-whatsapp-notify' ),
				'category'    => 'newsletter',
				'type'        => CampaignRepository::TYPE_RECURRING,
				'audience_config' => [
					'type'  => 'all_customers',
					'rules' => [],
					'logic' => 'AND',
				],
				'throttle_config' => [
					'msgs_per_minute' => 30,
					'msgs_per_hour'   => 1000,
					'batch_size'      => 200,
				],
				'schedule_config' => [
					'recurrence'    => 'weekly',
					'day_of_week'   => 1,
					'time'          => '10:00',
					'timezone'      => 'site',
				],
				'message_config' => [
					'type'      => 'template',
					'variables' => [ '{{first_name}}' ],
				],
				'coupon_config' => [],
			],
			[
				'id'          => 'abandoned_cart_recovery',
				'name'        => __( 'Abandoned Cart Recovery', 'tsh-whatsapp-notify' ),
				'description' => __( 'Target customers who added items to cart but did not complete checkout in the last 24 hours.', 'tsh-whatsapp-notify' ),
				'category'    => 'recovery',
				'type'        => CampaignRepository::TYPE_ONETIME,
				'audience_config' => [
					'type'  => 'custom',
					'rules' => [
						[
							'field'    => 'has_pending_cart',
							'operator' => 'equals',
							'value'    => true,
						],
						[
							'field'    => 'last_cart_update',
							'operator' => 'within_days',
							'value'    => 1,
						],
					],
					'logic' => 'AND',
				],
				'throttle_config' => [
					'msgs_per_minute' => 10,
					'msgs_per_hour'   => 200,
					'batch_size'      => 50,
				],
				'message_config' => [
					'type'      => 'template',
					'variables' => [ '{{first_name}}', '{{cart_total}}', '{{coupon_code}}' ],
				],
				'coupon_config' => [
					'enabled'       => true,
					'discount_type' => 'percent',
					'amount'        => 10,
					'expiry_days'   => 2,
					'usage_limit'   => 1,
				],
			],
			[
				'id'          => 'first_purchase_upsell',
				'name'        => __( 'First Purchase Upsell', 'tsh-whatsapp-notify' ),
				'description' => __( 'Reach customers who made their first purchase in the last 14 days and encourage a second order.', 'tsh-whatsapp-notify' ),
				'category'    => 'upsell',
				'type'        => CampaignRepository::TYPE_ONETIME,
				'audience_config' => [
					'type'  => 'first_purchase',
					'rules' => [
						[
							'field'    => 'first_order_date',
							'operator' => 'within_days',
							'value'    => 14,
						],
						[
							'field'    => 'order_count',
							'operator' => 'equals',
							'value'    => 1,
						],
					],
					'logic' => 'AND',
				],
				'throttle_config' => [
					'msgs_per_minute' => 10,
					'msgs_per_hour'   => 200,
					'batch_size'      => 50,
				],
				'message_config' => [
					'type'      => 'template',
					'variables' => [ '{{first_name}}', '{{coupon_code}}' ],
				],
				'coupon_config' => [
					'enabled'       => true,
					'discount_type' => 'fixed_cart',
					'amount'        => 10,
					'expiry_days'   => 10,
					'usage_limit'   => 1,
				],
			],
			[
				'id'          => 'birthday_offer',
				'name'        => __( 'Birthday Offer', 'tsh-whatsapp-notify' ),
				'description' => __( 'Scheduled campaign for customers whose birthday falls this month. Requires a birthday custom field.', 'tsh-whatsapp-notify' ),
				'category'    => 'lifecycle',
				'type'        => CampaignRepository::TYPE_RECURRING,
				'audience_config' => [
					'type'  => 'custom',
					'rules' => [
						[
							'field'    => 'birthday_month',
							'operator' => 'equals',
							'value'    => '{{current_month}}',
						],
					],
					'logic' => 'AND',
				],
				'throttle_config' => [
					'msgs_per_minute' => 5,
					'msgs_per_hour'   => 100,
					'batch_size'      => 25,
				],
				'schedule_config' => [
					'recurrence'  => 'monthly',
					'day_of_month'=> 1,
					'time'        => '09:00',
					'timezone'    => 'site',
				],
				'message_config' => [
					'type'      => 'template',
					'variables' => [ '{{first_name}}', '{{coupon_code}}', '{{expiry_date}}' ],
				],
				'coupon_config' => [
					'enabled'       => true,
					'discount_type' => 'percent',
					'amount'        => 25,
					'expiry_days'   => 30,
					'usage_limit'   => 1,
				],
			],
		];
	}

	/**
	 * Get a single built-in template by ID.
	 *
	 * @param string $id
	 * @return array<string, mixed>|null
	 */
	public function get( string $id ): ?array {
		foreach ( $this->get_all() as $tpl ) {
			if ( $tpl['id'] === $id ) {
				return $tpl;
			}
		}

		return null;
	}

	/**
	 * Get templates grouped by category.
	 *
	 * @return array<string, array<int, array>>
	 */
	public function get_by_category(): array {
		$grouped = [];
		foreach ( $this->get_all() as $tpl ) {
			$cat = $tpl['category'] ?? 'other';
			$grouped[ $cat ][] = $tpl;
		}

		return $grouped;
	}
}
