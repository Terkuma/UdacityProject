<?php
/**
 * Template importer — JSON and CSV.
 *
 * @package TSH\WhatsAppNotify\Templates
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Templates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TemplateImporter
 *
 * Imports Meta template data from JSON or CSV payloads.
 *
 * Import modes:
 *   merge   — Upsert: add new, update existing (by meta_template_id).
 *   replace — Truncate local table, then insert all.
 *   skip    — Only insert templates that don't exist locally; skip duplicates.
 */
final class TemplateImporter {

	/** @var TemplateRepository */
	private TemplateRepository $repository;

	/** @var TemplateValidator */
	private TemplateValidator $validator;

	/** @var TemplateLogger */
	private TemplateLogger $logger;

	/** @var TemplateCache */
	private TemplateCache $cache;

	public function __construct() {
		$this->repository = new TemplateRepository();
		$this->validator  = new TemplateValidator();
		$this->logger     = new TemplateLogger();
		$this->cache      = new TemplateCache();
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Import templates from a JSON string.
	 *
	 * @param string $json Raw JSON string.
	 * @param string $mode merge|replace|skip
	 * @return array{ imported: int, skipped: int, errors: int, messages: string[] }
	 */
	public function import_json( string $json, string $mode = 'merge' ): array {
		$decoded = json_decode( $json, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			return [
				'imported' => 0,
				'skipped'  => 0,
				'errors'   => 1,
				'messages' => [ __( 'Invalid JSON: ', 'tsh-whatsapp-notify' ) . json_last_error_msg() ],
			];
		}

		// Support both a flat array of templates and a wrapped { templates: [...] } format.
		$templates = isset( $decoded['templates'] ) ? $decoded['templates'] : $decoded;

		if ( ! is_array( $templates ) ) {
			return [
				'imported' => 0,
				'skipped'  => 0,
				'errors'   => 1,
				'messages' => [ __( 'JSON must contain an array of template objects.', 'tsh-whatsapp-notify' ) ],
			];
		}

		return $this->import_array( $templates, $mode );
	}

	/**
	 * Import templates from a CSV string.
	 *
	 * Expected CSV columns:
	 *   meta_template_id, template_name, category, language, status,
	 *   quality_score, body, footer, namespace
	 *
	 * @param string $csv  Raw CSV content.
	 * @param string $mode merge|replace|skip
	 * @return array{ imported: int, skipped: int, errors: int, messages: string[] }
	 */
	public function import_csv( string $csv, string $mode = 'merge' ): array {
		$lines = array_filter( explode( "\n", str_replace( "\r\n", "\n", $csv ) ) );

		if ( empty( $lines ) ) {
			return [ 'imported' => 0, 'skipped' => 0, 'errors' => 0, 'messages' => [] ];
		}

		// First line is headers.
		$headers = str_getcsv( array_shift( $lines ) );
		$headers = array_map( 'trim', $headers );

		$templates = [];
		foreach ( $lines as $line ) {
			$values = str_getcsv( $line );
			if ( count( $values ) !== count( $headers ) ) {
				continue;
			}
			$templates[] = array_combine( $headers, $values );
		}

		return $this->import_array( $templates, $mode );
	}

	/**
	 * Validate an array of template data before importing.
	 *
	 * @param array<int, array<string, mixed>> $templates
	 * @return array{ valid: bool, row_errors: array<int, array<string, string[]>> }
	 */
	public function validate_import_data( array $templates ): array {
		$row_errors = [];

		foreach ( $templates as $i => $tpl ) {
			$result = $this->validator->validate( $tpl );
			if ( ! $result['valid'] ) {
				$row_errors[ $i ] = $result['errors'];
			}
		}

		return [ 'valid' => empty( $row_errors ), 'row_errors' => $row_errors ];
	}

	// -------------------------------------------------------------------------
	// Core import
	// -------------------------------------------------------------------------

	/**
	 * @param array<int, array<string, mixed>> $templates
	 * @param string                           $mode
	 * @return array{ imported: int, skipped: int, errors: int, messages: string[] }
	 */
	private function import_array( array $templates, string $mode ): array {
		$stats = [ 'imported' => 0, 'skipped' => 0, 'errors' => 0, 'messages' => [] ];

		if ( 'replace' === $mode ) {
			$this->repository->truncate();
		}

		foreach ( $templates as $i => $tpl ) {
			$tpl = array_map( 'trim', array_map( 'strval', $tpl ) );

			$meta_id = $tpl['meta_template_id'] ?? $tpl['id'] ?? '';
			if ( ! $meta_id ) {
				$stats['errors']++;
				$stats['messages'][] = sprintf(
					/* translators: %d: row number */
					__( 'Row %d: missing meta_template_id.', 'tsh-whatsapp-notify' ),
					$i + 1
				);
				continue;
			}

			// Skip mode: don't overwrite existing.
			if ( 'skip' === $mode && $this->repository->find_by_meta_id( $meta_id ) ) {
				$stats['skipped']++;
				continue;
			}

			$data = [
				'meta_template_id' => $meta_id,
				'template_name'    => $tpl['template_name'] ?? $tpl['name'] ?? '',
				'category'         => strtoupper( $tpl['category'] ?? 'UTILITY' ),
				'language'         => $tpl['language'] ?? 'en',
				'status'           => strtoupper( $tpl['status'] ?? 'PENDING' ),
				'quality_score'    => strtoupper( $tpl['quality_score'] ?? 'UNKNOWN' ),
				'namespace'        => $tpl['namespace'] ?? '',
				'header_type'      => $tpl['header_type'] ?? '',
				'body'             => $tpl['body'] ?? '',
				'footer'           => $tpl['footer'] ?? '',
				'buttons'          => $tpl['buttons'] ?? null,
				'variables'        => $tpl['variables'] ?? null,
				'example_values'   => $tpl['example_values'] ?? null,
				'variable_mapping' => $tpl['variable_mapping'] ?? null,
				'last_synced'      => current_time( 'mysql' ),
			];

			$result = $this->repository->upsert( $data );

			if ( false === $result ) {
				$stats['errors']++;
				$stats['messages'][] = sprintf(
					/* translators: %s: template name */
					__( 'Failed to import template "%s".', 'tsh-whatsapp-notify' ),
					esc_html( $data['template_name'] )
				);
			} else {
				$stats['imported']++;
			}
		}

		$this->cache->flush();
		$this->logger->import_completed( $stats );

		return $stats;
	}
}
