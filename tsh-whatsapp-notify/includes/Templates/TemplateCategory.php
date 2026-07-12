<?php
/**
 * Template category constants and helpers.
 *
 * @package TSH\WhatsAppNotify\Templates
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Templates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TemplateCategory
 *
 * Provides constants, labels, badge CSS classes, and validation for
 * Meta WhatsApp template categories.
 */
final class TemplateCategory {

	// Meta-defined category slugs (uppercase as returned by the API).
	public const UTILITY        = 'UTILITY';
	public const MARKETING      = 'MARKETING';
	public const AUTHENTICATION = 'AUTHENTICATION';

	/**
	 * Return all supported category slugs.
	 *
	 * @return array<int, string>
	 */
	public static function get_all(): array {
		return [ self::UTILITY, self::MARKETING, self::AUTHENTICATION ];
	}

	/**
	 * Return a human-readable label for a category slug.
	 *
	 * @param string $category
	 * @return string
	 */
	public static function get_label( string $category ): string {
		$labels = [
			self::UTILITY        => __( 'Utility', 'tsh-whatsapp-notify' ),
			self::MARKETING      => __( 'Marketing', 'tsh-whatsapp-notify' ),
			self::AUTHENTICATION => __( 'Authentication', 'tsh-whatsapp-notify' ),
		];

		return $labels[ strtoupper( $category ) ] ?? ucfirst( strtolower( $category ) );
	}

	/**
	 * Return the CSS modifier class for a category badge.
	 *
	 * @param string $category
	 * @return string
	 */
	public static function get_badge_class( string $category ): string {
		$classes = [
			self::UTILITY        => 'tsh-wa-badge--blue',
			self::MARKETING      => 'tsh-wa-badge--purple',
			self::AUTHENTICATION => 'tsh-wa-badge--orange',
		];

		return $classes[ strtoupper( $category ) ] ?? 'tsh-wa-badge--grey';
	}

	/**
	 * Return true if the given category slug is valid.
	 *
	 * @param string $category
	 * @return bool
	 */
	public static function is_valid( string $category ): bool {
		return in_array( strtoupper( $category ), self::get_all(), true );
	}

	/**
	 * Return a map of slug → label for use in select fields.
	 *
	 * @return array<string, string>
	 */
	public static function get_select_options(): array {
		$out = [];
		foreach ( self::get_all() as $slug ) {
			$out[ $slug ] = self::get_label( $slug );
		}
		return $out;
	}
}
