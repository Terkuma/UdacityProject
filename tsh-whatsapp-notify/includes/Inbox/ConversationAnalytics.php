<?php
/**
 * Conversation analytics — inbox dashboard metrics.
 *
 * @package TSH\WhatsAppNotify\Inbox
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Inbox;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ConversationAnalytics
 *
 * Computes dashboard metrics for the Inbox:
 *  - Open / closed / archived conversation counts.
 *  - Messages today (incoming vs outgoing).
 *  - Average first-response time.
 *  - Top agents by conversation volume.
 */
final class ConversationAnalytics {

	/** @var ConversationRepository */
	private ConversationRepository $repo;

	/** @var ConversationCache */
	private ConversationCache $cache;

	/** @var int Analytics cache TTL in seconds (5 minutes). */
	private const CACHE_TTL = 300;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->repo  = new ConversationRepository();
		$this->cache = new ConversationCache();
	}

	/**
	 * Get a full analytics overview.
	 *
	 * @return array<string, mixed>
	 */
	public function get_overview(): array {
		$cached = $this->cache->get( 'stats_overview' );
		if ( false !== $cached ) {
			return $cached;
		}

		$counts            = $this->repo->get_conversation_counts();
		$unread            = $this->repo->get_unread_counts();
		$avg_response      = $this->compute_avg_response_time();
		$top_agents        = $this->get_top_agents();
		$message_volume    = $this->get_message_volume_7days();

		$overview = [
			'conversations' => [
				'total'    => $counts['total'],
				'open'     => $counts['open'],
				'closed'   => $counts['closed'],
				'archived' => $counts['archived'],
			],
			'unread' => [
				'open'  => $unread['open'],
				'total' => $unread['total'],
			],
			'messages_today' => [
				'incoming' => $counts['messages_today_incoming'],
				'outgoing' => $counts['messages_today_outgoing'],
				'total'    => $counts['messages_today_incoming'] + $counts['messages_today_outgoing'],
			],
			'avg_response_minutes' => $avg_response,
			'top_agents'           => $top_agents,
			'message_volume_7days' => $message_volume,
			'generated_at'         => current_time( 'c' ),
		];

		$this->cache->set( 'stats_overview', $overview, self::CACHE_TTL );
		return $overview;
	}

	// -------------------------------------------------------------------------
	// Private computations
	// -------------------------------------------------------------------------

	/**
	 * Compute average first-response time in minutes.
	 *
	 * First response = time from first incoming message to first outgoing
	 * message in the same conversation.
	 *
	 * @return float|null Average minutes, or null if no data.
	 */
	private function compute_avg_response_time(): ?float {
		global $wpdb;

		$msg_table  = $wpdb->prefix . 'tsh_wa_messages';

		// Get the first incoming and first outgoing message per conversation
		// where both exist. Compare timestamps.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT
			   m_in.conversation_id,
			   MIN(m_in.timestamp)  AS first_incoming,
			   MIN(m_out.timestamp) AS first_outgoing
			 FROM `{$msg_table}` m_in
			 JOIN `{$msg_table}` m_out
			   ON m_out.conversation_id = m_in.conversation_id
			   AND m_out.direction = 'outgoing'
			   AND m_out.is_note   = 0
			 WHERE m_in.direction = 'incoming'
			 GROUP BY m_in.conversation_id
			 HAVING first_outgoing > first_incoming
			 LIMIT 500",
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return null;
		}

		$diffs = [];
		foreach ( $rows as $row ) {
			$in_ts  = strtotime( $row['first_incoming']  . ' UTC' );
			$out_ts = strtotime( $row['first_outgoing']  . ' UTC' );
			if ( $in_ts && $out_ts && $out_ts > $in_ts ) {
				$diffs[] = ( $out_ts - $in_ts ) / 60; // minutes
			}
		}

		if ( empty( $diffs ) ) {
			return null;
		}

		return round( array_sum( $diffs ) / count( $diffs ), 1 );
	}

	/**
	 * Get top agents by conversation count.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_top_agents(): array {
		global $wpdb;

		$conv_table = $wpdb->prefix . 'tsh_wa_conversations';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT assigned_to, COUNT(*) AS total
			 FROM `{$conv_table}`
			 WHERE assigned_to IS NOT NULL
			 GROUP BY assigned_to
			 ORDER BY total DESC
			 LIMIT 10",
			ARRAY_A
		);

		$agents = [];
		foreach ( (array) $rows as $row ) {
			$user = get_user_by( 'id', (int) $row['assigned_to'] );
			if ( ! $user ) {
				continue;
			}
			$agents[] = [
				'user_id' => (int) $row['assigned_to'],
				'name'    => esc_html( $user->display_name ),
				'count'   => (int) $row['total'],
			];
		}

		return $agents;
	}

	/**
	 * Get message volume for the last 7 days (day-by-day).
	 *
	 * @return array<string, array<string, int>>
	 */
	private function get_message_volume_7days(): array {
		global $wpdb;

		$msg_table = $wpdb->prefix . 'tsh_wa_messages';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT
			   DATE(created_at)  AS day,
			   direction,
			   COUNT(*) AS cnt
			 FROM `{$msg_table}`
			 WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
			   AND is_note = 0
			 GROUP BY day, direction
			 ORDER BY day ASC",
			ARRAY_A
		);

		$volume = [];
		foreach ( (array) $rows as $row ) {
			$day = $row['day'];
			if ( ! isset( $volume[ $day ] ) ) {
				$volume[ $day ] = [ 'incoming' => 0, 'outgoing' => 0 ];
			}
			$volume[ $day ][ $row['direction'] ] = (int) $row['cnt'];
		}

		return $volume;
	}
}
