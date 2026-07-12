<?php
/**
 * Campaign exporter — exports campaigns to JSON.
 *
 * @package TSH\WhatsAppNotify\Marketing
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Marketing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CampaignExporter
 */
final class CampaignExporter {

	private CampaignRepository $repo;

	public function __construct( CampaignRepository $repo ) {
		$this->repo = $repo;
	}

	/**
	 * Export a single campaign as a JSON string.
	 *
	 * @param int  $campaign_id
	 * @param bool $include_stats  Whether to include send/analytics counters.
	 * @return string  JSON string.
	 */
	public function export_campaign( int $campaign_id, bool $include_stats = false ): string {
		$campaign = $this->repo->get_campaign( $campaign_id );

		if ( ! $campaign ) {
			return '{}';
		}

		$data = $this->prepare_for_export( $campaign, $include_stats );

		return wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ?: '{}';
	}

	/**
	 * Export multiple campaigns as a JSON array.
	 *
	 * @param array<int> $campaign_ids  Empty = all campaigns.
	 * @param bool       $include_stats
	 * @return string  JSON string.
	 */
	public function export_campaigns( array $campaign_ids = [], bool $include_stats = false ): string {
		if ( $campaign_ids ) {
			$campaigns = [];
			foreach ( $campaign_ids as $id ) {
				$c = $this->repo->get_campaign( $id );
				if ( $c ) {
					$campaigns[] = $c;
				}
			}
		} else {
			$result    = $this->repo->get_campaigns( [ 'per_page' => 1000 ] );
			$campaigns = $result['rows'];
		}

		$data = array_map( fn( $c ) => $this->prepare_for_export( $c, $include_stats ), $campaigns );

		return wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ?: '[]';
	}

	/**
	 * Prepare a campaign array for export (strip IDs, reset stats).
	 *
	 * @param array<string, mixed> $campaign
	 * @param bool                 $include_stats
	 * @return array<string, mixed>
	 */
	private function prepare_for_export( array $campaign, bool $include_stats ): array {
		// Always export these fields.
		$export = [
			'name'            => $campaign['name'],
			'description'     => $campaign['description'],
			'type'            => $campaign['type'],
			'audience_config' => $campaign['audience_config'],
			'message_config'  => $campaign['message_config'],
			'schedule_config' => $campaign['schedule_config'],
			'coupon_config'   => $campaign['coupon_config'],
			'throttle_config' => $campaign['throttle_config'],
			'ab_split_ratio'  => $campaign['ab_split_ratio'],
			'exported_at'     => current_time( 'mysql' ),
			'export_version'  => '1.0',
		];

		if ( $include_stats ) {
			$export['stats'] = [
				'total_audience'  => $campaign['total_audience'],
				'total_sent'      => $campaign['total_sent'],
				'total_delivered' => $campaign['total_delivered'],
				'total_read'      => $campaign['total_read'],
				'total_failed'    => $campaign['total_failed'],
				'total_coupons'   => $campaign['total_coupons'],
			];
		}

		return $export;
	}

	/**
	 * Generate a filename for a campaign export.
	 *
	 * @param int $campaign_id
	 * @return string
	 */
	public function export_filename( int $campaign_id ): string {
		$campaign = $this->repo->get_campaign( $campaign_id );
		$slug     = $campaign ? sanitize_file_name( $campaign['name'] ) : 'campaign';

		return sprintf( 'tsh-wa-campaign-%d-%s-%s.json', $campaign_id, $slug, gmdate( 'Ymd-His' ) );
	}
}
