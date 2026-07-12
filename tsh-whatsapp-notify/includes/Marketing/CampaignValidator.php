<?php
/**
 * Campaign validator.
 *
 * @package TSH\WhatsAppNotify\Marketing
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Marketing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CampaignValidator
 *
 * Validates campaign definitions before save or launch.
 */
final class CampaignValidator {

	/**
	 * Validate a campaign definition.
	 *
	 * @param array<string, mixed> $data
	 * @return array{ valid: bool, errors: array<string> }
	 */
	public function validate( array $data ): array {
		$errors = [];

		// Name.
		if ( empty( $data['name'] ) || strlen( trim( $data['name'] ) ) < 2 ) {
			$errors[] = __( 'Campaign name is required (minimum 2 characters).', 'tsh-whatsapp-notify' );
		}

		// Type.
		$valid_types = [ CampaignRepository::TYPE_ONETIME, CampaignRepository::TYPE_SCHEDULED, CampaignRepository::TYPE_RECURRING ];
		if ( ! empty( $data['type'] ) && ! in_array( $data['type'], $valid_types, true ) ) {
			$errors[] = __( 'Invalid campaign type.', 'tsh-whatsapp-notify' );
		}

		// Template.
		if ( empty( $data['template_id'] ) ) {
			$errors[] = __( 'A WhatsApp template must be selected.', 'tsh-whatsapp-notify' );
		}

		// Audience.
		$audience = $data['audience_config'] ?? [];
		if ( empty( $audience['type'] ) ) {
			$errors[] = __( 'Audience type is required.', 'tsh-whatsapp-notify' );
		}

		// Schedule.
		$type = $data['type'] ?? CampaignRepository::TYPE_ONETIME;
		if ( CampaignRepository::TYPE_ONETIME !== $type ) {
			if ( empty( $data['send_at'] ) ) {
				$errors[] = __( 'Scheduled campaigns require a send date/time.', 'tsh-whatsapp-notify' );
			}
		}

		// A/B split ratio must be 0–100.
		if ( isset( $data['ab_split_ratio'] ) ) {
			$ratio = (int) $data['ab_split_ratio'];
			if ( $ratio < 0 || $ratio > 100 ) {
				$errors[] = __( 'A/B split ratio must be between 0 and 100.', 'tsh-whatsapp-notify' );
			}
		}

		// Throttle config sanity.
		$throttle = $data['throttle_config'] ?? [];
		if ( ! empty( $throttle['msgs_per_minute'] ) && (int) $throttle['msgs_per_minute'] < 1 ) {
			$errors[] = __( 'Messages per minute must be at least 1.', 'tsh-whatsapp-notify' );
		}

		return [
			'valid'  => empty( $errors ),
			'errors' => $errors,
		];
	}

	/**
	 * Validate that a campaign is ready to launch (stricter than save).
	 *
	 * @param array<string, mixed> $campaign  Decoded campaign row.
	 * @return array{ valid: bool, errors: array<string> }
	 */
	public function validate_for_launch( array $campaign ): array {
		$errors = $this->validate( $campaign )['errors'];

		// Must not already be running.
		if ( CampaignRepository::STATUS_RUNNING === $campaign['status'] ) {
			$errors[] = __( 'Campaign is already running.', 'tsh-whatsapp-notify' );
		}

		if ( CampaignRepository::STATUS_COMPLETED === $campaign['status'] ) {
			$errors[] = __( 'Campaign has already completed. Duplicate it to send again.', 'tsh-whatsapp-notify' );
		}

		if ( CampaignRepository::STATUS_ARCHIVED === $campaign['status'] ) {
			$errors[] = __( 'Archived campaigns cannot be launched.', 'tsh-whatsapp-notify' );
		}

		// Phone Number ID must be configured.
		$api = get_option( 'tsh_wa_api_settings', [] );
		if ( empty( $api['enable_api'] ) || '1' !== (string) $api['enable_api'] ) {
			$errors[] = __( 'WhatsApp API must be enabled and configured before launching a campaign.', 'tsh-whatsapp-notify' );
		}

		return [
			'valid'  => empty( $errors ),
			'errors' => $errors,
		];
	}
}
