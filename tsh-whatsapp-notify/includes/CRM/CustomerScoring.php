<?php
/**
 * Customer Scoring — RFM analysis and health score calculation.
 *
 * @package TSH\WhatsAppNotify\CRM
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\CRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CustomerScoring
 *
 * Calculates five scores per customer:
 *  - Purchase Score  (based on LTV, order count, avg order)
 *  - Engagement Score (based on messages read, campaigns opened, last activity)
 *  - Marketing Score  (campaign open rates, coupon usage)
 *  - Support Score    (complaint rate, resolution speed)
 *  - Health Score     (composite of all four + RFM)
 *
 * All scores are 0–100. RFM is expressed as three 1–5 digits (e.g. "543").
 */
final class CustomerScoring {

	private CustomerRepository $repo;

	// Health label thresholds (score => label)
	private const THRESHOLDS = [
		80 => 'excellent',
		60 => 'good',
		40 => 'average',
		20 => 'poor',
		0  => 'critical',
	];

	public function __construct( CustomerRepository $repo ) {
		$this->repo = $repo;
	}

	/**
	 * Calculate and persist scores for a single customer.
	 */
	public function calculate( int $customer_id ): array {
		$customer = $this->repo->get_customer( $customer_id );
		if ( ! $customer ) return [];

		$scores = $this->compute_scores( $customer );
		$this->repo->upsert_scores( $customer_id, $scores );

		// Update customer health_score column for quick filtering
		$this->repo->update_customer( $customer_id, [
			'health_score'  => $scores['health'],
			'rfm_monetary'  => (float) ( $customer['lifetime_value'] ?? 0 ),
			'rfm_recency'   => $scores['rfm_r'],
			'rfm_frequency' => $scores['rfm_f'],
		] );

		return $scores;
	}

	/**
	 * Recalculate scores for all customers in chunks.
	 * Intended for background WP-cron execution.
	 */
	public function recalculate_all( int $chunk = 100, int $offset = 0 ): int {
		global $wpdb;
		$table = $this->repo->customers();
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT id FROM {$table} ORDER BY id LIMIT %d OFFSET %d",
			$chunk, $offset
		), ARRAY_A );

		$processed = 0;
		foreach ( $rows as $row ) {
			$this->calculate( (int) $row['id'] );
			++$processed;
		}
		return $processed;
	}

	/**
	 * Get human-readable label for a score (0–100).
	 */
	public static function label( float $score ): string {
		foreach ( self::THRESHOLDS as $min => $label ) {
			if ( $score >= $min ) return $label;
		}
		return 'critical';
	}

	// =========================================================================
	// Internal scoring
	// =========================================================================

	private function compute_scores( array $c ): array {
		$now   = new \DateTime( current_time( 'mysql' ) );

		// --- Purchase Score (0–100) ---
		$ltv        = (float) ( $c['lifetime_value'] ?? 0 );
		$orders     = (int)   ( $c['total_orders']   ?? 0 );
		$avg        = (float) ( $c['avg_order_value'] ?? 0 );
		$p_score    = $this->clamp(
			min( $ltv / 100, 40 ) +       // Up to 40 pts from LTV
			min( $orders * 5, 40 ) +       // Up to 40 pts from order count
			min( $avg / 10,  20 ),         // Up to 20 pts from AOV
			0, 100
		);

		// --- RFM ---
		$rfm_r = 1; // recency score 1–5
		if ( $c['last_order_at'] && $c['last_order_at'] !== '0000-00-00 00:00:00' ) {
			$last   = new \DateTime( $c['last_order_at'] );
			$days   = (int) $now->diff( $last )->days;
			$rfm_r  = $days <= 30 ? 5 : ( $days <= 60 ? 4 : ( $days <= 90 ? 3 : ( $days <= 180 ? 2 : 1 ) ) );
		}
		$rfm_f = min( 5, max( 1, (int) ceil( $orders / 3 ) ) );
		$rfm_m = min( 5, max( 1, (int) ceil( min( $ltv / 500, 5 ) ) ) );
		$rfm   = (string) $rfm_r . (string) $rfm_f . (string) $rfm_m;

		// --- Engagement Score (0–100) ---
		$e_score = 0;
		// Days since last message
		if ( $c['last_message_at'] && $c['last_message_at'] !== '0000-00-00 00:00:00' ) {
			$last_msg = new \DateTime( $c['last_message_at'] );
			$msg_days = (int) $now->diff( $last_msg )->days;
			$e_score  += $msg_days <= 7 ? 30 : ( $msg_days <= 30 ? 20 : ( $msg_days <= 90 ? 10 : 0 ) );
		}
		// Days since last campaign
		if ( $c['last_campaign_at'] && $c['last_campaign_at'] !== '0000-00-00 00:00:00' ) {
			$last_cmp = new \DateTime( $c['last_campaign_at'] );
			$cmp_days = (int) $now->diff( $last_cmp )->days;
			$e_score  += $cmp_days <= 30 ? 20 : ( $cmp_days <= 90 ? 10 : 5 );
		}
		// Recent orders
		$e_score += min( 50, $rfm_r * 10 );
		$e_score  = $this->clamp( $e_score, 0, 100 );

		// --- Marketing Score (0–100) ---
		$m_score = (float) ( isset( $c['last_campaign_at'] ) && $c['last_campaign_at'] ? 50 : 20 );
		$m_score += (float) ( isset( $c['last_coupon_at'] ) && $c['last_coupon_at'] ? 25 : 0 );
		$m_score += (float) ( $c['marketing_consent'] ? 25 : 0 );
		$m_score  = $this->clamp( $m_score, 0, 100 );

		// --- Support Score (simplified, 0–100) ---
		$s_score = 70.0; // Baseline — no complaint data tracked yet
		$s_score += ( $c['is_blocked'] ?? 0 ) ? -30 : 0;
		$s_score  = $this->clamp( $s_score, 0, 100 );

		// --- Overall Health (weighted composite) ---
		$health = $this->clamp(
			( $p_score   * 0.40 ) +
			( $e_score   * 0.25 ) +
			( $m_score   * 0.20 ) +
			( $s_score   * 0.15 ),
			0, 100
		);

		return [
			'purchase'   => round( $p_score, 2 ),
			'engagement' => round( $e_score, 2 ),
			'marketing'  => round( $m_score, 2 ),
			'support'    => round( $s_score, 2 ),
			'health'     => round( $health, 2 ),
			'rfm'        => $rfm,
			'rfm_r'      => $rfm_r,
			'rfm_f'      => $rfm_f,
			'rfm_m'      => $rfm_m,
			'label'      => self::label( $health ),
		];
	}

	private function clamp( float $v, float $min, float $max ): float {
		return max( $min, min( $max, $v ) );
	}
}
