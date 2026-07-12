<?php
/**
 * Language helpers for WhatsApp template management.
 *
 * @package TSH\WhatsAppNotify\Templates
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Templates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TemplateLanguage
 *
 * Provides language code validation, labelling, customer-language
 * detection from WC orders, and fallback resolution.
 */
final class TemplateLanguage {

	/** @var string Default fallback language code. */
	public const DEFAULT_LANGUAGE = 'en';

	/**
	 * Return all supported Meta WhatsApp language codes mapped to labels.
	 *
	 * @return array<string, string>
	 */
	public static function get_supported(): array {
		return [
			'af'    => __( 'Afrikaans', 'tsh-whatsapp-notify' ),
			'sq'    => __( 'Albanian', 'tsh-whatsapp-notify' ),
			'ar'    => __( 'Arabic', 'tsh-whatsapp-notify' ),
			'az'    => __( 'Azerbaijani', 'tsh-whatsapp-notify' ),
			'bn'    => __( 'Bengali', 'tsh-whatsapp-notify' ),
			'bg'    => __( 'Bulgarian', 'tsh-whatsapp-notify' ),
			'ca'    => __( 'Catalan', 'tsh-whatsapp-notify' ),
			'zh_CN' => __( 'Chinese (Simplified)', 'tsh-whatsapp-notify' ),
			'zh_TW' => __( 'Chinese (Traditional)', 'tsh-whatsapp-notify' ),
			'hr'    => __( 'Croatian', 'tsh-whatsapp-notify' ),
			'cs'    => __( 'Czech', 'tsh-whatsapp-notify' ),
			'da'    => __( 'Danish', 'tsh-whatsapp-notify' ),
			'nl'    => __( 'Dutch', 'tsh-whatsapp-notify' ),
			'en'    => __( 'English', 'tsh-whatsapp-notify' ),
			'en_GB' => __( 'English (UK)', 'tsh-whatsapp-notify' ),
			'en_US' => __( 'English (US)', 'tsh-whatsapp-notify' ),
			'et'    => __( 'Estonian', 'tsh-whatsapp-notify' ),
			'fi'    => __( 'Finnish', 'tsh-whatsapp-notify' ),
			'fr'    => __( 'French', 'tsh-whatsapp-notify' ),
			'ka'    => __( 'Georgian', 'tsh-whatsapp-notify' ),
			'de'    => __( 'German', 'tsh-whatsapp-notify' ),
			'el'    => __( 'Greek', 'tsh-whatsapp-notify' ),
			'gu'    => __( 'Gujarati', 'tsh-whatsapp-notify' ),
			'ha'    => __( 'Hausa', 'tsh-whatsapp-notify' ),
			'he'    => __( 'Hebrew', 'tsh-whatsapp-notify' ),
			'hi'    => __( 'Hindi', 'tsh-whatsapp-notify' ),
			'hu'    => __( 'Hungarian', 'tsh-whatsapp-notify' ),
			'id'    => __( 'Indonesian', 'tsh-whatsapp-notify' ),
			'ga'    => __( 'Irish', 'tsh-whatsapp-notify' ),
			'it'    => __( 'Italian', 'tsh-whatsapp-notify' ),
			'ja'    => __( 'Japanese', 'tsh-whatsapp-notify' ),
			'kn'    => __( 'Kannada', 'tsh-whatsapp-notify' ),
			'kk'    => __( 'Kazakh', 'tsh-whatsapp-notify' ),
			'ko'    => __( 'Korean', 'tsh-whatsapp-notify' ),
			'lo'    => __( 'Lao', 'tsh-whatsapp-notify' ),
			'lv'    => __( 'Latvian', 'tsh-whatsapp-notify' ),
			'lt'    => __( 'Lithuanian', 'tsh-whatsapp-notify' ),
			'mk'    => __( 'Macedonian', 'tsh-whatsapp-notify' ),
			'ms'    => __( 'Malay', 'tsh-whatsapp-notify' ),
			'ml'    => __( 'Malayalam', 'tsh-whatsapp-notify' ),
			'mr'    => __( 'Marathi', 'tsh-whatsapp-notify' ),
			'nb'    => __( 'Norwegian', 'tsh-whatsapp-notify' ),
			'fa'    => __( 'Persian', 'tsh-whatsapp-notify' ),
			'pl'    => __( 'Polish', 'tsh-whatsapp-notify' ),
			'pt_BR' => __( 'Portuguese (Brazil)', 'tsh-whatsapp-notify' ),
			'pt_PT' => __( 'Portuguese (Portugal)', 'tsh-whatsapp-notify' ),
			'pa'    => __( 'Punjabi', 'tsh-whatsapp-notify' ),
			'ro'    => __( 'Romanian', 'tsh-whatsapp-notify' ),
			'ru'    => __( 'Russian', 'tsh-whatsapp-notify' ),
			'sr'    => __( 'Serbian', 'tsh-whatsapp-notify' ),
			'sk'    => __( 'Slovak', 'tsh-whatsapp-notify' ),
			'sl'    => __( 'Slovenian', 'tsh-whatsapp-notify' ),
			'es'    => __( 'Spanish', 'tsh-whatsapp-notify' ),
			'es_AR' => __( 'Spanish (Argentina)', 'tsh-whatsapp-notify' ),
			'es_ES' => __( 'Spanish (Spain)', 'tsh-whatsapp-notify' ),
			'es_MX' => __( 'Spanish (Mexico)', 'tsh-whatsapp-notify' ),
			'sw'    => __( 'Swahili', 'tsh-whatsapp-notify' ),
			'sv'    => __( 'Swedish', 'tsh-whatsapp-notify' ),
			'ta'    => __( 'Tamil', 'tsh-whatsapp-notify' ),
			'te'    => __( 'Telugu', 'tsh-whatsapp-notify' ),
			'th'    => __( 'Thai', 'tsh-whatsapp-notify' ),
			'tr'    => __( 'Turkish', 'tsh-whatsapp-notify' ),
			'uk'    => __( 'Ukrainian', 'tsh-whatsapp-notify' ),
			'ur'    => __( 'Urdu', 'tsh-whatsapp-notify' ),
			'uz'    => __( 'Uzbek', 'tsh-whatsapp-notify' ),
			'vi'    => __( 'Vietnamese', 'tsh-whatsapp-notify' ),
			'zu'    => __( 'Zulu', 'tsh-whatsapp-notify' ),
		];
	}

	/**
	 * Return a human-readable label for a language code.
	 *
	 * @param string $code
	 * @return string
	 */
	public static function get_label( string $code ): string {
		$supported = self::get_supported();
		return $supported[ $code ] ?? strtoupper( $code );
	}

	/**
	 * Return true if the given code is supported.
	 *
	 * @param string $code
	 * @return bool
	 */
	public static function is_valid( string $code ): bool {
		return array_key_exists( $code, self::get_supported() );
	}

	/**
	 * Attempt to detect the preferred language from a WooCommerce order.
	 *
	 * Checks WPML/Polylang order meta, then falls back to the site locale.
	 *
	 * @param \WC_Order $order
	 * @return string Language code; falls back to DEFAULT_LANGUAGE.
	 */
	public static function detect_from_order( \WC_Order $order ): string {
		// WPML order language meta.
		$wpml_lang = $order->get_meta( 'wpml_language', true );
		if ( $wpml_lang && self::is_valid( (string) $wpml_lang ) ) {
			return (string) $wpml_lang;
		}

		// Polylang order language meta.
		$pll_lang = $order->get_meta( 'pll_language', true );
		if ( $pll_lang && self::is_valid( (string) $pll_lang ) ) {
			return (string) $pll_lang;
		}

		// WooCommerce order locale (available in some setups).
		$order_locale = $order->get_meta( '_order_locale', true );
		if ( $order_locale ) {
			$code = self::locale_to_code( (string) $order_locale );
			if ( $code ) {
				return $code;
			}
		}

		// Site locale as last resort.
		$site_locale = get_locale();
		$code        = self::locale_to_code( $site_locale );
		if ( $code ) {
			return $code;
		}

		return self::DEFAULT_LANGUAGE;
	}

	/**
	 * Convert a WordPress locale string (e.g. en_US) to a Meta language code.
	 *
	 * @param string $locale
	 * @return string Empty string when no match found.
	 */
	public static function locale_to_code( string $locale ): string {
		$locale = str_replace( '-', '_', $locale );

		// Direct match (e.g. pt_BR, zh_CN).
		if ( self::is_valid( $locale ) ) {
			return $locale;
		}

		// Try the base language part only (e.g. en_US → en).
		$parts = explode( '_', $locale, 2 );
		$base  = $parts[0];
		if ( self::is_valid( $base ) ) {
			return $base;
		}

		return '';
	}

	/**
	 * Return the configured fallback language.
	 *
	 * @return string
	 */
	public static function get_fallback(): string {
		$settings = get_option( 'tsh_wa_sync_settings', [] );
		$fallback = $settings['fallback_language'] ?? self::DEFAULT_LANGUAGE;
		return self::is_valid( $fallback ) ? $fallback : self::DEFAULT_LANGUAGE;
	}
}
