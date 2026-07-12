<?php
/**
 * Template data validator.
 *
 * @package TSH\WhatsAppNotify\Templates
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Templates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TemplateValidator
 *
 * Validates template data before persisting or sending.
 * Every validate_* method returns an array of error strings
 * (empty = valid). The top-level validate() method aggregates them.
 */
final class TemplateValidator {

	/** Maximum character counts per Meta spec. */
	private const MAX_BODY_LENGTH   = 1024;
	private const MAX_FOOTER_LENGTH = 60;
	private const MAX_BUTTON_TEXT   = 25;
	private const MAX_BUTTONS       = 10;
	private const MAX_VARIABLES     = 10;

	// -------------------------------------------------------------------------
	// Top-level validator
	// -------------------------------------------------------------------------

	/**
	 * Validate a full template data array.
	 *
	 * @param array<string, mixed> $data
	 * @return array{ valid: bool, errors: array<string, string[]> }
	 */
	public function validate( array $data ): array {
		$errors = [];

		// Template name.
		if ( empty( $data['template_name'] ) ) {
			$errors['template_name'][] = __( 'Template name is required.', 'tsh-whatsapp-notify' );
		} elseif ( ! preg_match( '/^[a-z0-9_]+$/', (string) $data['template_name'] ) ) {
			$errors['template_name'][] = __( 'Template name may only contain lowercase letters, numbers, and underscores.', 'tsh-whatsapp-notify' );
		}

		// Category.
		if ( ! empty( $data['category'] ) ) {
			$cat_errors = $this->validate_category( (string) $data['category'] );
			if ( $cat_errors ) {
				$errors['category'] = $cat_errors;
			}
		}

		// Language.
		if ( ! empty( $data['language'] ) ) {
			if ( ! TemplateLanguage::is_valid( (string) $data['language'] ) ) {
				$errors['language'][] = sprintf(
					/* translators: %s: language code */
					__( '"%s" is not a supported Meta WhatsApp language code.', 'tsh-whatsapp-notify' ),
					esc_html( (string) $data['language'] )
				);
			}
		}

		// Body.
		if ( ! empty( $data['body'] ) ) {
			$body_errors = $this->validate_body( (string) $data['body'] );
			if ( $body_errors ) {
				$errors['body'] = $body_errors;
			}
		}

		// Footer.
		if ( ! empty( $data['footer'] ) ) {
			$footer_errors = $this->validate_footer( (string) $data['footer'] );
			if ( $footer_errors ) {
				$errors['footer'] = $footer_errors;
			}
		}

		// Buttons.
		if ( ! empty( $data['buttons'] ) ) {
			$buttons = is_string( $data['buttons'] )
				? json_decode( $data['buttons'], true )
				: (array) $data['buttons'];
			if ( is_array( $buttons ) ) {
				$btn_errors = $this->validate_buttons( $buttons );
				if ( $btn_errors ) {
					$errors['buttons'] = $btn_errors;
				}
			}
		}

		// Header.
		if ( ! empty( $data['header_type'] ) || ! empty( $data['header_content'] ) ) {
			$header_errors = $this->validate_header(
				(string) ( $data['header_type'] ?? '' ),
				$data['header_content'] ?? ''
			);
			if ( $header_errors ) {
				$errors['header'] = $header_errors;
			}
		}

		return [
			'valid'  => empty( $errors ),
			'errors' => $errors,
		];
	}

	// -------------------------------------------------------------------------
	// Field-level validators
	// -------------------------------------------------------------------------

	/**
	 * Validate template body text.
	 *
	 * @param string $body
	 * @return string[]
	 */
	public function validate_body( string $body ): array {
		$errors = [];

		if ( '' === $body ) {
			$errors[] = __( 'Template body cannot be empty.', 'tsh-whatsapp-notify' );
			return $errors;
		}

		if ( mb_strlen( $body ) > self::MAX_BODY_LENGTH ) {
			$errors[] = sprintf(
				/* translators: %d: max length */
				__( 'Template body exceeds the maximum of %d characters.', 'tsh-whatsapp-notify' ),
				self::MAX_BODY_LENGTH
			);
		}

		// Detect malformed variable syntax (e.g. {{ 1 }} with spaces).
		if ( preg_match( '/\{\{\s+\d+\s*\}\}/', $body ) ) {
			$errors[] = __( 'Variable placeholders must not contain spaces (use {{1}} not {{ 1 }}).', 'tsh-whatsapp-notify' );
		}

		// Check variable count.
		$vars = $this->extract_variables( $body );
		if ( count( $vars ) > self::MAX_VARIABLES ) {
			$errors[] = sprintf(
				/* translators: %d: max vars */
				__( 'Template body contains more than %d variable placeholders.', 'tsh-whatsapp-notify' ),
				self::MAX_VARIABLES
			);
		}

		return $errors;
	}

	/**
	 * Validate template footer text.
	 *
	 * @param string $footer
	 * @return string[]
	 */
	public function validate_footer( string $footer ): array {
		$errors = [];

		if ( mb_strlen( $footer ) > self::MAX_FOOTER_LENGTH ) {
			$errors[] = sprintf(
				/* translators: %d: max length */
				__( 'Template footer exceeds the maximum of %d characters.', 'tsh-whatsapp-notify' ),
				self::MAX_FOOTER_LENGTH
			);
		}

		return $errors;
	}

	/**
	 * Validate template buttons array.
	 *
	 * @param array<int, array<string, mixed>> $buttons
	 * @return string[]
	 */
	public function validate_buttons( array $buttons ): array {
		$errors = [];

		if ( count( $buttons ) > self::MAX_BUTTONS ) {
			$errors[] = sprintf(
				/* translators: %d: max buttons */
				__( 'Templates may not have more than %d buttons.', 'tsh-whatsapp-notify' ),
				self::MAX_BUTTONS
			);
		}

		$allowed_types = [ 'QUICK_REPLY', 'URL', 'PHONE_NUMBER', 'COPY_CODE', 'OTP' ];

		foreach ( $buttons as $i => $btn ) {
			$num = $i + 1;

			if ( empty( $btn['type'] ) ) {
				$errors[] = sprintf(
					/* translators: %d: button number */
					__( 'Button %d is missing a type.', 'tsh-whatsapp-notify' ),
					$num
				);
				continue;
			}

			if ( ! in_array( strtoupper( $btn['type'] ), $allowed_types, true ) ) {
				$errors[] = sprintf(
					/* translators: %1$d: button number, %2$s: type */
					__( 'Button %1$d has unsupported type "%2$s".', 'tsh-whatsapp-notify' ),
					$num,
					esc_html( $btn['type'] )
				);
			}

			if ( ! empty( $btn['text'] ) && mb_strlen( (string) $btn['text'] ) > self::MAX_BUTTON_TEXT ) {
				$errors[] = sprintf(
					/* translators: %1$d: button number, %2$d: max */
					__( 'Button %1$d text exceeds %2$d characters.', 'tsh-whatsapp-notify' ),
					$num,
					self::MAX_BUTTON_TEXT
				);
			}

			if ( 'URL' === strtoupper( $btn['type'] ?? '' ) && empty( $btn['url'] ) ) {
				$errors[] = sprintf(
					/* translators: %d: button number */
					__( 'URL button %d is missing a URL.', 'tsh-whatsapp-notify' ),
					$num
				);
			}

			if ( 'PHONE_NUMBER' === strtoupper( $btn['type'] ?? '' ) && empty( $btn['phone_number'] ) ) {
				$errors[] = sprintf(
					/* translators: %d: button number */
					__( 'Phone number button %d is missing a phone number.', 'tsh-whatsapp-notify' ),
					$num
				);
			}
		}

		return $errors;
	}

	/**
	 * Validate template header.
	 *
	 * @param string       $header_type
	 * @param string|array $header_content
	 * @return string[]
	 */
	public function validate_header( string $header_type, $header_content ): array {
		$errors       = [];
		$allowed_types = [ 'TEXT', 'IMAGE', 'VIDEO', 'DOCUMENT', 'LOCATION', '' ];

		if ( ! in_array( strtoupper( $header_type ), $allowed_types, true ) ) {
			$errors[] = sprintf(
				/* translators: %s: header type */
				__( 'Unsupported header type "%s".', 'tsh-whatsapp-notify' ),
				esc_html( $header_type )
			);
		}

		if ( 'TEXT' === strtoupper( $header_type ) ) {
			$text = is_array( $header_content ) ? ( $header_content['text'] ?? '' ) : (string) $header_content;
			if ( mb_strlen( $text ) > 60 ) {
				$errors[] = __( 'Header text exceeds 60 characters.', 'tsh-whatsapp-notify' );
			}
		}

		return $errors;
	}

	/**
	 * Validate a category string.
	 *
	 * @param string $category
	 * @return string[]
	 */
	public function validate_category( string $category ): array {
		if ( ! TemplateCategory::is_valid( $category ) ) {
			return [
				sprintf(
					/* translators: %s: category */
					__( '"%s" is not a valid Meta template category.', 'tsh-whatsapp-notify' ),
					esc_html( $category )
				),
			];
		}
		return [];
	}

	/**
	 * Validate a language code.
	 *
	 * @param string $language
	 * @return string[]
	 */
	public function validate_language( string $language ): array {
		if ( ! TemplateLanguage::is_valid( $language ) ) {
			return [
				sprintf(
					/* translators: %s: language code */
					__( '"%s" is not a supported language code.', 'tsh-whatsapp-notify' ),
					esc_html( $language )
				),
			];
		}
		return [];
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Extract all unique {{N}} variable numbers from a body string.
	 *
	 * @param string $body
	 * @return int[]
	 */
	public function extract_variables( string $body ): array {
		preg_match_all( '/\{\{(\d+)\}\}/', $body, $matches );
		if ( empty( $matches[1] ) ) {
			return [];
		}
		$nums = array_map( 'intval', $matches[1] );
		return array_values( array_unique( $nums ) );
	}

	/**
	 * Return the variable mapping for a template body.
	 * Detects all {{N}} placeholders and returns a structured array.
	 *
	 * @param string               $body
	 * @param array<string, mixed> $existing_mapping Optional pre-existing mapping.
	 * @return array<int, array{ number: int, wc_field: string, example: string }>
	 */
	public function build_variable_map( string $body, array $existing_mapping = [] ): array {
		$vars = $this->extract_variables( $body );
		sort( $vars );

		$map = [];
		foreach ( $vars as $num ) {
			$key    = (string) $num;
			$map[] = [
				'number'   => $num,
				'wc_field' => $existing_mapping[ $key ]['wc_field'] ?? '',
				'example'  => $existing_mapping[ $key ]['example']  ?? '',
			];
		}
		return $map;
	}
}
