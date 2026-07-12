<?php
/**
 * Campaign logger — thin wrapper around CampaignRepository::log().
 *
 * @package TSH\WhatsAppNotify\Marketing
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Marketing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CampaignLogger
 */
final class CampaignLogger {

	private CampaignRepository $repo;

	public function __construct( CampaignRepository $repo ) {
		$this->repo = $repo;
	}

	public function info( int $campaign_id, int $run_id, string $message, array $data = [] ): void {
		$this->repo->log( $campaign_id, $run_id, 'info', $message, $data );
	}

	public function warning( int $campaign_id, int $run_id, string $message, array $data = [] ): void {
		$this->repo->log( $campaign_id, $run_id, 'warning', $message, $data );
	}

	public function error( int $campaign_id, int $run_id, string $message, array $data = [] ): void {
		$this->repo->log( $campaign_id, $run_id, 'error', $message, $data );
	}

	public function debug( int $campaign_id, int $run_id, string $message, array $data = [] ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$this->repo->log( $campaign_id, $run_id, 'debug', $message, $data );
		}
	}
}
