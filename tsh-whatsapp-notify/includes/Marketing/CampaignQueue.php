<?php
/**
 * Campaign queue — bridges the campaign engine to the existing Queue engine.
 *
 * @package TSH\WhatsAppNotify\Marketing
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Marketing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TSH\WhatsAppNotify\Queue\Queue;

/**
 * Class CampaignQueue
 *
 * Resolves message bodies for each audience member and pushes entries into
 * the core tsh_wa_queue table with time-spaced scheduled_at values to honour
 * throttle settings.
 */
final class CampaignQueue {

	private Queue         $queue;
	private CouponEngine  $coupon_engine;

	public function __construct( Queue $queue, CouponEngine $coupon_engine ) {
		$this->queue         = $queue;
		$this->coupon_engine = $coupon_engine;
	}

	// -------------------------------------------------------------------------
	// Batch enqueue
	// -------------------------------------------------------------------------

	/**
	 * Enqueue a batch of audience members into the core message queue.
	 *
	 * Each member is sent using the meta template resolved from message_config.
	 * Throttle config controls the time gap between each scheduled_at timestamp.
	 *
	 * @param int                              $campaign_id
	 * @param int                              $run_id
	 * @param array<int, array<string, mixed>> $members      Audience member rows.
	 * @param array<string, mixed>             $campaign      Decoded campaign row.
	 * @param CampaignRepository               $repo
	 * @return array{ queued: int, failed: int, coupon_count: int }
	 */
	public function enqueue_batch(
		int $campaign_id,
		int $run_id,
		array $members,
		array $campaign,
		CampaignRepository $repo
	): array {
		$queued       = 0;
		$failed       = 0;
		$coupon_count = 0;

		$message_config  = $campaign['message_config']  ?? [];
		$coupon_config   = $campaign['coupon_config']   ?? [];
		$throttle_config = $campaign['throttle_config'] ?? [];

		$use_coupons    = ! empty( $coupon_config['enabled'] );
		$coupon_prefix  = sanitize_key( $coupon_config['prefix'] ?? 'TSH' );

		// Calculate time spacing between messages.
		$msgs_per_minute = max( 1, (int) ( $throttle_config['msgs_per_minute'] ?? 30 ) );
		$gap_seconds     = (int) ceil( 60 / $msgs_per_minute );

		// Start scheduling from "now".
		$schedule_base  = time();
		$schedule_index = 0;

		// A/B test: member already has template_variant assigned (a|b).
		// template_a = template_id, template_b = template_b_id.
		$template_a_id = (int) ( $campaign['template_id']   ?? 0 );
		$template_b_id = (int) ( $campaign['template_b_id'] ?? 0 );

		foreach ( $members as $member ) {
			$audience_id = (int) ( $member['id'] ?? 0 );
			$phone       = sanitize_text_field( $member['phone'] ?? '' );
			$customer_id = (int) ( $member['customer_id'] ?? 0 );
			$variant     = $member['template_variant'] ?? 'a';

			if ( ! $phone ) {
				$repo->update_audience_member( $audience_id, 'skipped', 0, '', 'No phone number' );
				++$failed;
				continue;
			}

			// Resolve template ID.
			$template_id = ( 'b' === $variant && $template_b_id ) ? $template_b_id : $template_a_id;

			// Generate coupon if needed.
			$coupon_code = '';
			if ( $use_coupons ) {
				$code    = $this->coupon_engine->generate_code( $campaign_id, $customer_id, $coupon_prefix );
				$created = $this->coupon_engine->create_coupon( $code, $customer_id, $coupon_config );
				if ( $created ) {
					$coupon_code = $code;
					++$coupon_count;
				}
			}

			// Build message body — resolve variables.
			$message = $this->resolve_message(
				$message_config,
				$member,
				$coupon_code,
				$coupon_config
			);

			// Calculate scheduled_at with throttle spacing.
			$scheduled_at = gmdate( 'Y-m-d H:i:s', $schedule_base + ( $schedule_index * $gap_seconds ) );
			++$schedule_index;

			// Push to queue.
			$queue_id = $this->queue->add( [
				'phone'        => $phone,
				'message'      => $message,
				'template_id'  => $template_id ?: null,
				'order_id'     => null,
				'priority'     => 7,   // campaigns are lower priority than transactional
				'max_attempts' => (int) ( $throttle_config['retry_attempts'] ?? 3 ),
				'scheduled_at' => $scheduled_at,
				'meta'         => [
					'campaign_id'  => $campaign_id,
					'run_id'       => $run_id,
					'audience_id'  => $audience_id,
					'coupon_code'  => $coupon_code,
					'customer_id'  => $customer_id,
				],
			] );

			if ( $queue_id ) {
				$repo->update_audience_member( $audience_id, 'queued', $queue_id, $coupon_code );
				++$queued;
			} else {
				$repo->update_audience_member( $audience_id, 'failed', 0, '', 'Queue insert failed' );
				++$failed;
			}
		}

		return [
			'queued'       => $queued,
			'failed'       => $failed,
			'coupon_count' => $coupon_count,
		];
	}

	// -------------------------------------------------------------------------
	// Message resolution
	// -------------------------------------------------------------------------

	/**
	 * Resolve the message body for a recipient.
	 *
	 * @param array<string, mixed> $message_config
	 * @param array<string, mixed> $member           Audience member row.
	 * @param string               $coupon_code
	 * @param array<string, mixed> $coupon_config
	 * @return string
	 */
	private function resolve_message(
		array $message_config,
		array $member,
		string $coupon_code,
		array $coupon_config
	): string {
		$body = $message_config['body'] ?? $message_config['template_body'] ?? '';

		if ( ! $body ) {
			$body = '{{first_name}}, you have a message from us!';
		}

		// Standard variable map.
		$name_parts = explode( ' ', trim( $member['name'] ?? '' ), 2 );
		$first_name = $name_parts[0] ?? '';
		$last_name  = $name_parts[1] ?? '';

		$vars = [
			'{{first_name}}'  => $first_name,
			'{{last_name}}'   => $last_name,
			'{{full_name}}'   => trim( $member['name'] ?? '' ),
			'{{email}}'       => $member['email'] ?? '',
			'{{phone}}'       => $member['phone'] ?? '',
			'{{store_name}}'  => get_bloginfo( 'name' ),
			'{{store_url}}'   => get_home_url(),
			'{{coupon_code}}' => $coupon_code,
		];

		if ( $coupon_code && ! empty( $coupon_config['expiry_days'] ) ) {
			$expiry = gmdate(
				get_option( 'date_format' ),
				strtotime( '+' . (int) $coupon_config['expiry_days'] . ' days' )
			);
			$vars['{{coupon_expiry}}'] = $expiry;
			$vars['{{expiry_date}}']   = $expiry;
		}

		return strtr( $body, $vars );
	}
}
