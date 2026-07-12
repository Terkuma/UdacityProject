<?php
/**
 * Campaign analytics — aggregates stats and computes ROI.
 *
 * @package TSH\WhatsAppNotify\Marketing
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Marketing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CampaignAnalytics
 */
final class CampaignAnalytics {

	private CampaignRepository $repo;

	public function __construct( CampaignRepository $repo ) {
		$this->repo = $repo;
	}

	// -------------------------------------------------------------------------
	// Dashboard overview
	// -------------------------------------------------------------------------

	/**
	 * Return the top-level marketing dashboard stats.
	 *
	 * @param int $days  Lookback window in days.
	 * @return array<string, mixed>
	 */
	public function get_dashboard_stats( int $days = 30 ): array {
		global $wpdb;

		$overview = $this->repo->get_overview_stats( $days );

		// Revenue generated from orders whose coupons originated from campaigns.
		$revenue = $this->get_campaign_revenue( $days );

		// Orders placed via campaign coupons.
		$orders = $this->get_campaign_order_count( $days );

		// Conversion rate: orders / audience.
		$audience    = max( 1, (int) ( $overview['total_sent'] ?? 0 ) );
		$conversion  = $orders > 0 ? round( ( $orders / $audience ) * 100, 2 ) : 0.0;

		// ROI = revenue / estimated cost (messages sent * $0.005 as a proxy).
		$cost = max( 1, $audience ) * 0.005;
		$roi  = $revenue > 0 ? round( ( ( $revenue - $cost ) / $cost ) * 100, 1 ) : 0.0;

		// Today's campaigns.
		$today_table = $wpdb->prefix . 'tsh_wa_campaigns';
		$today_stats = $wpdb->get_row(
			"SELECT
				SUM(IF(status='running',1,0))   AS running,
				SUM(IF(status='completed',1,0)) AS completed,
				SUM(IF(status='failed',1,0))    AS failed,
				SUM(IF(status='scheduled',1,0)) AS scheduled
			 FROM `{$today_table}`
			 WHERE DATE(created_at) = CURDATE()",
			ARRAY_A
		);

		return array_merge( (array) $overview, [
			'revenue'         => $revenue,
			'orders'          => $orders,
			'conversion_rate' => $conversion,
			'roi'             => $roi,
			'today'           => $today_stats ?: [],
		] );
	}

	// -------------------------------------------------------------------------
	// Per-campaign analytics
	// -------------------------------------------------------------------------

	/**
	 * Compute detailed analytics for a single campaign.
	 *
	 * @param int $campaign_id
	 * @param int $run_id      0 = latest run.
	 * @return array<string, mixed>
	 */
	public function get_campaign_analytics( int $campaign_id, int $run_id = 0 ): array {
		global $wpdb;

		$campaign = $this->repo->get_campaign_stats( $campaign_id );
		if ( ! $campaign ) {
			return [];
		}

		// Audience stats from audience table.
		if ( ! $run_id ) {
			$latest = $this->repo->get_latest_run( $campaign_id );
			$run_id = $latest ? (int) $latest['id'] : 0;
		}

		$audience_stats = $run_id ? $this->repo->get_audience_stats( $run_id ) : [];

		// Delivery stats from queue table (matched via meta).
		$delivery = $this->get_delivery_stats_for_campaign( $campaign_id );

		// Revenue + orders from WooCommerce coupons.
		$revenue = $this->get_campaign_revenue_by_id( $campaign_id );
		$orders  = $this->get_campaign_order_count_by_id( $campaign_id );

		$total_sent = (int) ( $campaign['total_sent'] ?? 0 );
		$conversion = $total_sent > 0 && $orders > 0 ? round( ( $orders / $total_sent ) * 100, 2 ) : 0.0;
		$cost       = max( 1, $total_sent ) * 0.005;
		$roi        = $revenue > 0 ? round( ( ( $revenue - $cost ) / $cost ) * 100, 1 ) : 0.0;

		// A/B testing summary.
		$ab = $run_id ? $this->get_ab_stats( $run_id ) : [];

		return [
			'campaign'        => $campaign,
			'audience_stats'  => $audience_stats,
			'delivery'        => $delivery,
			'revenue'         => $revenue,
			'orders'          => $orders,
			'conversion_rate' => $conversion,
			'roi'             => $roi,
			'ab_test'         => $ab,
		];
	}

	// -------------------------------------------------------------------------
	// A/B testing stats
	// -------------------------------------------------------------------------

	/**
	 * Compare variant A vs variant B delivery rates.
	 *
	 * @param int $run_id
	 * @return array<string, mixed>
	 */
	public function get_ab_stats( int $run_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'tsh_wa_campaign_audience';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT template_variant, status, COUNT(*) AS cnt
				 FROM `{$table}`
				 WHERE run_id = %d
				 GROUP BY template_variant, status",
				$run_id
			),
			ARRAY_A
		);

		$stats = [
			'a' => [ 'sent' => 0, 'failed' => 0, 'queued' => 0, 'total' => 0 ],
			'b' => [ 'sent' => 0, 'failed' => 0, 'queued' => 0, 'total' => 0 ],
		];

		foreach ( (array) $rows as $row ) {
			$variant = $row['template_variant'] ?? 'a';
			$status  = $row['status']           ?? '';
			$count   = (int) $row['cnt'];

			if ( isset( $stats[ $variant ][ $status ] ) ) {
				$stats[ $variant ][ $status ] += $count;
			}

			$stats[ $variant ]['total'] += $count;
		}

		// Determine winner by sent rate.
		$rate_a = $stats['a']['total'] > 0 ? ( $stats['a']['sent'] / $stats['a']['total'] ) : 0;
		$rate_b = $stats['b']['total'] > 0 ? ( $stats['b']['sent'] / $stats['b']['total'] ) : 0;

		$winner = 'none';
		if ( $rate_a > $rate_b ) { $winner = 'a'; }
		if ( $rate_b > $rate_a ) { $winner = 'b'; }

		return [
			'variants' => $stats,
			'winner'   => $winner,
		];
	}

	// -------------------------------------------------------------------------
	// Revenue helpers
	// -------------------------------------------------------------------------

	/**
	 * Total revenue from WC orders placed with TSH WA campaign coupons (global).
	 *
	 * @param int $days
	 * @return float
	 */
	private function get_campaign_revenue( int $days ): float {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(pm.meta_value), 0)
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_order_total'
				 WHERE p.post_type = 'shop_order'
				   AND p.post_status IN ('wc-completed','wc-processing')
				   AND p.post_date >= DATE_SUB(NOW(), INTERVAL %d DAY)
				   AND EXISTS (
					   SELECT 1 FROM {$wpdb->postmeta} pm2
					   WHERE pm2.post_id = p.ID
					     AND pm2.meta_key = '_coupon_discount_amount'
					     AND pm2.meta_value > 0
				   )
				   AND EXISTS (
					   SELECT 1 FROM {$wpdb->postmeta} pm3
					   WHERE pm3.post_id = p.ID
					     AND pm3.meta_key = '_used_coupons'
					     AND pm3.meta_value LIKE %s
				   )",
				$days,
				'%TSH-%'
			)
		);

		return (float) $result;
	}

	/**
	 * Revenue attributed to a specific campaign.
	 *
	 * @param int $campaign_id
	 * @return float
	 */
	private function get_campaign_revenue_by_id( int $campaign_id ): float {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(pm.meta_value), 0)
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_order_total'
				 WHERE p.post_type = 'shop_order'
				   AND p.post_status IN ('wc-completed','wc-processing')
				   AND EXISTS (
					   SELECT 1 FROM {$wpdb->postmeta} pm3
					   WHERE pm3.post_id = p.ID
					     AND pm3.meta_key = '_used_coupons'
					     AND pm3.meta_value LIKE %s
				   )",
				'%TSH-' . $campaign_id . '-%'
			)
		);

		return (float) $result;
	}

	private function get_campaign_order_count( int $days ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM {$wpdb->posts} p
				 WHERE p.post_type = 'shop_order'
				   AND p.post_status IN ('wc-completed','wc-processing')
				   AND p.post_date >= DATE_SUB(NOW(), INTERVAL %d DAY)
				   AND EXISTS (
					   SELECT 1 FROM {$wpdb->postmeta} pm3
					   WHERE pm3.post_id = p.ID
					     AND pm3.meta_key = '_used_coupons'
					     AND pm3.meta_value LIKE %s
				   )",
				$days,
				'%TSH-%'
			)
		);
	}

	private function get_campaign_order_count_by_id( int $campaign_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM {$wpdb->posts} p
				 WHERE p.post_type = 'shop_order'
				   AND p.post_status IN ('wc-completed','wc-processing')
				   AND EXISTS (
					   SELECT 1 FROM {$wpdb->postmeta} pm3
					   WHERE pm3.post_id = p.ID
					     AND pm3.meta_key = '_used_coupons'
					     AND pm3.meta_value LIKE %s
				   )",
				'%TSH-' . $campaign_id . '-%'
			)
		);
	}

	/**
	 * Get delivery stats from the main queue table for a campaign.
	 *
	 * @param int $campaign_id
	 * @return array<string, int>
	 */
	private function get_delivery_stats_for_campaign( int $campaign_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'tsh_wa_queue';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT delivery_status, COUNT(*) AS cnt
				 FROM `{$table}`
				 WHERE meta LIKE %s
				 GROUP BY delivery_status",
				'%"campaign_id":' . $campaign_id . '%'
			),
			ARRAY_A
		);

		$stats = [ 'sent' => 0, 'delivered' => 0, 'read' => 0, 'failed' => 0, 'pending' => 0 ];
		foreach ( (array) $rows as $row ) {
			$key = $row['delivery_status'] ?? 'pending';
			if ( array_key_exists( $key, $stats ) ) {
				$stats[ $key ] += (int) $row['cnt'];
			}
		}

		return $stats;
	}
}
