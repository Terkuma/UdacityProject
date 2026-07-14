<?php
/**
 * Customer Campaigns — campaign history for CRM customers.
 *
 * @package TSH\WhatsAppNotify\CRM
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\CRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CustomerCampaigns
 *
 * Reads Phase 8 campaign_audience table to surface a customer's
 * campaign history within their CRM profile.
 */
final class CustomerCampaigns {

	private CustomerRepository $repo;

	public function __construct( CustomerRepository $repo ) {
		$this->repo = $repo;
	}

	/**
	 * Summary for the profile sidebar.
	 */
	public function get_summary( array $customer ): array {
		global $wpdb;

		$phone = $customer['whatsapp_phone'] ?: $customer['phone'];
		if ( ! $phone ) return [ 'total' => 0, 'sent' => 0, 'failed' => 0 ];

		$aud_table = $wpdb->prefix . 'tsh_wa_campaign_audience';
		$cmp_table = $wpdb->prefix . 'tsh_wa_campaigns';

		if ( ! $this->table_exists( $aud_table ) ) {
			return [ 'total' => 0, 'sent' => 0, 'failed' => 0 ];
		}

		$total  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$aud_table} WHERE phone = %s", $phone ) );
		$sent   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$aud_table} WHERE phone = %s AND status = 'sent'", $phone ) );
		$failed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$aud_table} WHERE phone = %s AND status = 'failed'", $phone ) );

		return [ 'total' => $total, 'sent' => $sent, 'failed' => $failed ];
	}

	/**
	 * Timeline events from campaign audience records.
	 */
	public function get_timeline_events( array $customer ): array {
		global $wpdb;

		$phone = $customer['whatsapp_phone'] ?: $customer['phone'];
		if ( ! $phone ) return [];

		$aud_table = $wpdb->prefix . 'tsh_wa_campaign_audience';
		$cmp_table = $wpdb->prefix . 'tsh_wa_campaigns';

		if ( ! $this->table_exists( $aud_table ) ) return [];

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT a.*, c.name as campaign_name
			 FROM {$aud_table} a
			 LEFT JOIN {$cmp_table} c ON c.id = a.campaign_id
			 WHERE a.phone = %s
			 ORDER BY a.created_at DESC LIMIT 50",
			$phone
		), ARRAY_A ) ?: [];

		$events = [];
		foreach ( $rows as $r ) {
			$events[] = [
				'id'          => 'cmp_aud_' . $r['id'],
				'type'        => CustomerActivity::TYPE_CAMPAIGN_RECEIVED,
				'label'       => __( 'Campaign', 'tsh-whatsapp-notify' ),
				'icon'        => '📢',
				'subject'     => sprintf( __( 'Campaign: %s', 'tsh-whatsapp-notify' ), $r['campaign_name'] ?? '#' . $r['campaign_id'] ),
				'description' => sprintf( __( 'Status: %s', 'tsh-whatsapp-notify' ), $r['status'] ?? '' ),
				'data'        => [ 'campaign_id' => $r['campaign_id'], 'status' => $r['status'] ],
				'created_at'  => $r['sent_at'] ?: $r['created_at'],
				'source'      => 'campaign',
			];
		}
		return $events;
	}

	private function table_exists( string $table ): bool {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}
}
