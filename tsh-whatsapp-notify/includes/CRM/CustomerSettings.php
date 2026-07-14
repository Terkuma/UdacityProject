<?php
/**
 * Customer Settings — CRM configuration management.
 *
 * @package TSH\WhatsAppNotify\CRM
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\CRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CustomerSettings
 *
 * Manages all CRM-specific options stored in wp_options.
 */
final class CustomerSettings {

	public const OPTION_KEY = 'tsh_wa_crm_settings';

	public const DEFAULTS = [
		'vip_ltv_threshold'    => 500,
		'vip_order_threshold'  => 5,
		'inactive_days'        => 90,
		'dormant_days'         => 60,
		'health_weights'       => [
			'purchase'   => 40,
			'engagement' => 25,
			'marketing'  => 20,
			'support'    => 15,
		],
		'default_tags'         => [],
		'timeline_retention'   => 365,
		'task_reminder_hours'  => 24,
		'avatar_style'         => 'initials',
		'default_lifecycle'    => 'lead',
		'auto_sync_woo'        => true,
		'auto_score_on_order'  => true,
		'auto_lifecycle_cron'  => true,
	];

	/**
	 * Get all CRM settings (merged with defaults).
	 */
	public static function get(): array {
		$saved = get_option( self::OPTION_KEY, [] );
		return array_merge( self::DEFAULTS, is_array( $saved ) ? $saved : [] );
	}

	/**
	 * Save CRM settings.
	 */
	public static function save( array $data ): bool {
		$current = self::get();
		$merged  = array_merge( $current, $data );
		// Sanitize numeric values
		$merged['vip_ltv_threshold']   = max( 0, (float) ( $merged['vip_ltv_threshold'] ?? 500 ) );
		$merged['vip_order_threshold'] = max( 1, (int)   ( $merged['vip_order_threshold'] ?? 5 ) );
		$merged['inactive_days']       = max( 1, (int)   ( $merged['inactive_days'] ?? 90 ) );
		$merged['dormant_days']        = max( 1, (int)   ( $merged['dormant_days'] ?? 60 ) );
		$merged['timeline_retention']  = max( 30, (int)  ( $merged['timeline_retention'] ?? 365 ) );
		$merged['task_reminder_hours'] = max( 1, (int)   ( $merged['task_reminder_hours'] ?? 24 ) );
		$merged['auto_sync_woo']       = ! empty( $merged['auto_sync_woo'] );
		$merged['auto_score_on_order'] = ! empty( $merged['auto_score_on_order'] );
		return update_option( self::OPTION_KEY, $merged, false );
	}

	/**
	 * Get a single setting value.
	 */
	public static function get_setting( string $key, mixed $default = null ): mixed {
		$settings = self::get();
		return $settings[ $key ] ?? $default ?? ( self::DEFAULTS[ $key ] ?? null );
	}
}
