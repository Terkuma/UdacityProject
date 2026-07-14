<?php
/**
 * Customer Analytics — dashboard stats and chart data.
 *
 * @package TSH\WhatsAppNotify\CRM
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\CRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CustomerAnalytics
 *
 * Provides all aggregate stats for the CRM dashboard and analytics pages.
 * All queries are read-only and designed to run efficiently with proper indexes.
 */
final class CustomerAnalytics {

	private CustomerRepository $repo;

	public function __construct( CustomerRepository $repo ) {
		$this->repo = $repo;
	}

	/**
	 * Full dashboard widget data.
	 */
	public function get_dashboard( int $days = 30 ): array {
		$counts    = $this->repo->get_dashboard_counts();
		$growth    = $this->repo->get_customer_growth( $days );
		$top       = $this->repo->get_top_customers( 10 );
		$lifecycle = $this->repo->get_lifecycle_distribution();
		$activity  = $this->repo->get_recent_activity( 20 );
		$averages  = $this->get_averages();
		$rates     = $this->get_retention_churn( $days );

		return [
			'counts'     => $counts,
			'growth'     => $growth,
			'top'        => $top,
			'lifecycle'  => $lifecycle,
			'activity'   => $activity,
			'averages'   => $averages,
			'rates'      => $rates,
		];
	}

	/**
	 * Averages — LTV, AOV, orders per customer.
	 */
	public function get_averages(): array {
		global $wpdb;
		$t = $this->repo->customers();
		return [
			'avg_ltv'    => (float) $wpdb->get_var( "SELECT AVG(lifetime_value) FROM {$t} WHERE total_orders > 0" ),
			'avg_aov'    => (float) $wpdb->get_var( "SELECT AVG(avg_order_value) FROM {$t} WHERE total_orders > 0" ),
			'avg_orders' => (float) $wpdb->get_var( "SELECT AVG(total_orders) FROM {$t} WHERE total_orders > 0" ),
			'total_ltv'  => (float) $wpdb->get_var( "SELECT SUM(lifetime_value) FROM {$t}" ),
		];
	}

	/**
	 * Retention and churn rates over a period.
	 */
	public function get_retention_churn( int $days = 30 ): array {
		global $wpdb;
		$t = $this->repo->customers();

		$total     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" );
		$lost      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE lifecycle = 'lost'" );
		$returning = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE lifecycle = 'returning'" );

		$churn_rate     = $total > 0 ? round( ( $lost / $total ) * 100, 2 ) : 0;
		$retention_rate = $total > 0 ? round( ( $returning / $total ) * 100, 2 ) : 0;

		// New vs returning in period
		$period_new = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$t} WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
			$days
		) );

		return [
			'churn_rate'     => $churn_rate,
			'retention_rate' => $retention_rate,
			'period_new'     => $period_new,
			'lost_total'     => $lost,
			'returning_total'=> $returning,
		];
	}

	/**
	 * Customer growth over time — returns daily counts.
	 */
	public function get_growth_chart( int $days = 30 ): array {
		return $this->repo->get_customer_growth( $days );
	}

	/**
	 * Lifecycle distribution for pie chart.
	 */
	public function get_lifecycle_chart(): array {
		return $this->repo->get_lifecycle_distribution();
	}

	/**
	 * LTV distribution buckets.
	 */
	public function get_ltv_distribution(): array {
		global $wpdb;
		$t = $this->repo->customers();
		return $wpdb->get_results(
			"SELECT
				CASE
					WHEN lifetime_value = 0         THEN '0'
					WHEN lifetime_value < 50        THEN '1-50'
					WHEN lifetime_value < 100       THEN '50-100'
					WHEN lifetime_value < 250       THEN '100-250'
					WHEN lifetime_value < 500       THEN '250-500'
					WHEN lifetime_value < 1000      THEN '500-1k'
					WHEN lifetime_value < 5000      THEN '1k-5k'
					ELSE '5k+'
				END as bucket,
				COUNT(*) as count
			FROM {$t}
			GROUP BY bucket
			ORDER BY MIN(lifetime_value)",
			ARRAY_A
		) ?: [];
	}

	/**
	 * Order frequency distribution.
	 */
	public function get_order_frequency(): array {
		global $wpdb;
		$t = $this->repo->customers();
		return $wpdb->get_results(
			"SELECT
				CASE
					WHEN total_orders = 0 THEN '0 orders'
					WHEN total_orders = 1 THEN '1 order'
					WHEN total_orders <= 3 THEN '2-3 orders'
					WHEN total_orders <= 5 THEN '4-5 orders'
					WHEN total_orders <= 10 THEN '6-10 orders'
					ELSE '10+ orders'
				END as bucket,
				COUNT(*) as count
			FROM {$t}
			GROUP BY bucket
			ORDER BY MIN(total_orders)",
			ARRAY_A
		) ?: [];
	}

	/**
	 * Health score distribution.
	 */
	public function get_health_distribution(): array {
		global $wpdb;
		$t = $this->repo->customers();
		return $wpdb->get_results(
			"SELECT
				CASE
					WHEN health_score >= 80 THEN 'excellent'
					WHEN health_score >= 60 THEN 'good'
					WHEN health_score >= 40 THEN 'average'
					WHEN health_score >= 20 THEN 'poor'
					ELSE 'critical'
				END as label,
				COUNT(*) as count
			FROM {$t}
			GROUP BY label",
			ARRAY_A
		) ?: [];
	}

	/**
	 * Country distribution (top 10).
	 */
	public function get_country_distribution( int $limit = 10 ): array {
		global $wpdb;
		$t = $this->repo->customers();
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT country, COUNT(*) as count FROM {$t} WHERE country != '' GROUP BY country ORDER BY count DESC LIMIT %d",
			$limit
		), ARRAY_A ) ?: [];
	}

	/**
	 * Per-customer analytics (for the individual profile page).
	 */
	public function get_customer_stats( int $customer_id ): array {
		$customer = $this->repo->get_customer( $customer_id );
		if ( ! $customer ) return [];

		$scores = $this->repo->get_scores( $customer_id );

		return [
			'customer' => $customer,
			'scores'   => $scores,
			'label'    => CustomerScoring::label( (float) ( $customer['health_score'] ?? 0 ) ),
		];
	}
}
