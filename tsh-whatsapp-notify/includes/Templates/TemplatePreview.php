<?php
/**
 * Template preview engine.
 *
 * @package TSH\WhatsAppNotify\Templates
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Templates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TemplatePreview
 *
 * Builds structured preview data for a Meta WhatsApp template.
 * Substitutes {{N}} variable placeholders with real or example values.
 */
final class TemplatePreview {

	/** @var TemplateRepository */
	private TemplateRepository $repository;

	/** @var TemplateValidator */
	private TemplateValidator $validator;

	public function __construct( TemplateRepository $repository ) {
		$this->repository = $repository;
		$this->validator  = new TemplateValidator();
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Build a full preview data structure for a template.
	 *
	 * @param object               $template  Row from tsh_wa_meta_templates.
	 * @param array<string, mixed> $variables Optional variable overrides keyed by {{N}} number.
	 * @return array{
	 *   id: int,
	 *   template_name: string,
	 *   category: string,
	 *   language: string,
	 *   status: string,
	 *   quality_score: string,
	 *   header: array|null,
	 *   body: string,
	 *   body_rendered: string,
	 *   footer: string,
	 *   buttons: array,
	 *   variables: array,
	 *   char_count: int,
	 *   variable_map: array,
	 * }
	 */
	public function render( object $template, array $variables = [] ): array {
		$header  = $this->parse_header( $template );
		$body    = (string) ( $template->body ?? '' );
		$footer  = (string) ( $template->footer ?? '' );
		$buttons = $this->parse_buttons( $template );

		// Merge example values with caller-supplied overrides.
		$resolved_vars = $this->resolve_variables( $template, $variables );
		$body_rendered = $this->substitute( $body, $resolved_vars );

		$var_numbers = $this->validator->extract_variables( $body );
		$var_map     = [];
		foreach ( $var_numbers as $num ) {
			$mapping     = $this->get_variable_mapping( $template, $num );
			$var_map[]   = [
				'number'   => $num,
				'wc_field' => $mapping['wc_field'] ?? '',
				'example'  => $mapping['example']  ?? '',
				'value'    => $resolved_vars[ (string) $num ] ?? '',
			];
		}

		return [
			'id'            => (int) $template->id,
			'template_name' => $template->template_name,
			'category'      => $template->category,
			'language'      => $template->language,
			'status'        => $template->status,
			'quality_score' => $template->quality_score,
			'header'        => $header,
			'body'          => $body,
			'body_rendered' => $body_rendered,
			'footer'        => $footer,
			'buttons'       => $buttons,
			'variables'     => $resolved_vars,
			'char_count'    => mb_strlen( $body_rendered ),
			'variable_map'  => $var_map,
		];
	}

	/**
	 * Build modal data for the preview modal.
	 *
	 * @param int                  $template_id
	 * @param array<string, mixed> $variables
	 * @return array{ success: bool, data?: array, message?: string }
	 */
	public function build_modal_data( int $template_id, array $variables = [] ): array {
		$template = $this->repository->find( $template_id );
		if ( ! $template ) {
			return [ 'success' => false, 'message' => __( 'Template not found.', 'tsh-whatsapp-notify' ) ];
		}
		return [ 'success' => true, 'data' => $this->render( $template, $variables ) ];
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Substitute {{N}} placeholders with resolved values.
	 *
	 * @param string               $text
	 * @param array<string, string> $variables Keyed by numeric string "1", "2", etc.
	 * @return string
	 */
	public function substitute( string $text, array $variables ): string {
		return preg_replace_callback(
			'/\{\{(\d+)\}\}/',
			static function ( array $m ) use ( $variables ): string {
				$val = $variables[ $m[1] ] ?? $m[0];
				return esc_html( $val );
			},
			$text
		) ?? $text;
	}

	/**
	 * Resolve variable values from variable_mapping, example_values, and overrides.
	 *
	 * @param object               $template
	 * @param array<string, mixed> $overrides Caller-supplied {N => value} map.
	 * @return array<string, string>
	 */
	private function resolve_variables( object $template, array $overrides ): array {
		$resolved = [];

		// 1. Load saved variable mapping (admin-configured values).
		if ( ! empty( $template->variable_mapping ) ) {
			$mapping = json_decode( (string) $template->variable_mapping, true ) ?: [];
			foreach ( $mapping as $num => $entry ) {
				$example = $entry['example'] ?? '';
				if ( $example ) {
					$resolved[ (string) $num ] = $example;
				}
			}
		}

		// 2. Load example values from Meta API response.
		if ( ! empty( $template->variables ) ) {
			$examples = json_decode( (string) $template->variables, true ) ?: [];
			foreach ( $examples as $idx => $val ) {
				$num = (string) ( $idx + 1 );
				if ( ! isset( $resolved[ $num ] ) ) {
					$resolved[ $num ] = is_string( $val ) ? $val : '';
				}
			}
		}

		// 3. Apply caller-supplied overrides (highest priority).
		foreach ( $overrides as $num => $val ) {
			$resolved[ (string) $num ] = sanitize_text_field( (string) $val );
		}

		return $resolved;
	}

	/**
	 * Parse the header component from the template row.
	 *
	 * @param object $template
	 * @return array<string, mixed>|null
	 */
	private function parse_header( object $template ): ?array {
		if ( empty( $template->header_type ) ) {
			return null;
		}

		$header = [
			'type' => (string) $template->header_type,
		];

		if ( ! empty( $template->header_content ) ) {
			$content = json_decode( (string) $template->header_content, true );
			if ( is_array( $content ) ) {
				$header = array_merge( $header, $content );
			}
		}

		return $header;
	}

	/**
	 * Parse the buttons JSON from the template row.
	 *
	 * @param object $template
	 * @return array<int, array<string, mixed>>
	 */
	private function parse_buttons( object $template ): array {
		if ( empty( $template->buttons ) ) {
			return [];
		}
		$buttons = json_decode( (string) $template->buttons, true );
		return is_array( $buttons ) ? $buttons : [];
	}

	/**
	 * Get the variable mapping entry for a specific variable number.
	 *
	 * @param object $template
	 * @param int    $num
	 * @return array<string, string>
	 */
	private function get_variable_mapping( object $template, int $num ): array {
		if ( empty( $template->variable_mapping ) ) {
			return [];
		}
		$mapping = json_decode( (string) $template->variable_mapping, true ) ?: [];
		return $mapping[ (string) $num ] ?? [];
	}
}
