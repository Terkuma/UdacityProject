<?php
/**
 * Campaign importer — imports campaigns from JSON export files.
 *
 * @package TSH\WhatsAppNotify\Marketing
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Marketing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CampaignImporter
 */
final class CampaignImporter {

	private CampaignRepository $repo;
	private CampaignValidator  $validator;

	public function __construct( CampaignRepository $repo, CampaignValidator $validator ) {
		$this->repo      = $repo;
		$this->validator = $validator;
	}

	/**
	 * Import one or more campaigns from a JSON string.
	 *
	 * @param string $json        Raw JSON (single campaign object or array of campaigns).
	 * @param bool   $replace_all If true, deletes all existing campaigns before import.
	 * @return array{ imported: int, skipped: int, errors: array<string> }
	 */
	public function import_from_json( string $json, bool $replace_all = false ): array {
		$data = json_decode( $json, true );

		if ( null === $data || json_last_error() !== JSON_ERROR_NONE ) {
			return [ 'imported' => 0, 'skipped' => 0, 'errors' => [ __( 'Invalid JSON.', 'tsh-whatsapp-notify' ) ] ];
		}

		// Support both single object and array.
		if ( isset( $data['name'] ) ) {
			$data = [ $data ];
		}

		if ( ! is_array( $data ) ) {
			return [ 'imported' => 0, 'skipped' => 0, 'errors' => [ __( 'JSON must be an object or array of campaigns.', 'tsh-whatsapp-notify' ) ] ];
		}

		if ( $replace_all ) {
			$existing = $this->repo->get_campaigns( [ 'per_page' => 1000 ] );
			foreach ( $existing['rows'] as $row ) {
				$this->repo->delete_campaign( (int) $row['id'] );
			}
		}

		$imported = 0;
		$skipped  = 0;
		$errors   = [];

		foreach ( $data as $campaign_data ) {
			if ( ! is_array( $campaign_data ) ) {
				++$skipped;
				continue;
			}

			// Strip internal fields that must not be imported verbatim.
			unset( $campaign_data['id'], $campaign_data['created_at'], $campaign_data['updated_at'] );

			// Always import as draft.
			$campaign_data['status'] = CampaignRepository::STATUS_DRAFT;

			$validation = $this->validator->validate( $campaign_data );
			if ( ! $validation['valid'] ) {
				++$skipped;
				$name = sanitize_text_field( $campaign_data['name'] ?? 'Unknown' );
				foreach ( $validation['errors'] as $err ) {
					$errors[] = "{$name}: {$err}";
				}
				continue;
			}

			$id = $this->repo->create_campaign( $campaign_data );

			if ( $id ) {
				++$imported;
			} else {
				++$skipped;
				$errors[] = sprintf(
					/* translators: %s: campaign name */
					__( 'Failed to import campaign: %s', 'tsh-whatsapp-notify' ),
					sanitize_text_field( $campaign_data['name'] ?? 'Unknown' )
				);
			}
		}

		return compact( 'imported', 'skipped', 'errors' );
	}

	/**
	 * Import from a built-in campaign template preset.
	 *
	 * @param string $template_id  Built-in template identifier.
	 * @return int|false  New campaign ID or false on failure.
	 */
	public function import_from_template( string $template_id ): int|false {
		$templates = new CampaignTemplates();
		$tpl       = $templates->get( $template_id );

		if ( ! $tpl ) {
			return false;
		}

		$data = $tpl;
		unset( $data['id'], $data['category'] );
		$data['status'] = CampaignRepository::STATUS_DRAFT;

		return $this->repo->create_campaign( $data ) ?: false;
	}
}
