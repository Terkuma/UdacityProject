<?php
/**
 * Template exporter — JSON and CSV.
 *
 * @package TSH\WhatsAppNotify\Templates
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Templates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TemplateExporter
 *
 * Exports local Meta template records to JSON or CSV.
 */
final class TemplateExporter {

	/** @var TemplateRepository */
	private TemplateRepository $repository;

	public function __construct() {
		$this->repository = new TemplateRepository();
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Export templates as a JSON string.
	 *
	 * @param array<int, int> $template_ids Empty array = export all.
	 * @return string JSON string.
	 */
	public function export_json( array $template_ids = [] ): string {
		$data = $this->get_export_data( $template_ids );

		return (string) wp_json_encode(
			[
				'exported_at'    => current_time( 'mysql' ),
				'plugin_version' => TSH_WA_VERSION,
				'total'          => count( $data ),
				'templates'      => $data,
			],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
		);
	}

	/**
	 * Export templates as a CSV string.
	 *
	 * @param array<int, int> $template_ids Empty array = export all.
	 * @return string CSV content.
	 */
	public function export_csv( array $template_ids = [] ): string {
		$data = $this->get_export_data( $template_ids );

		if ( empty( $data ) ) {
			return '';
		}

		$output = fopen( 'php://temp', 'r+' );
		if ( false === $output ) {
			return '';
		}

		// Header row.
		$headers = [
			'meta_template_id', 'template_name', 'category', 'language',
			'status', 'quality_score', 'namespace', 'header_type',
			'body', 'footer', 'buttons', 'variables',
			'usage_count', 'send_success', 'send_failed',
			'last_synced', 'last_used',
		];
		fputcsv( $output, $headers );

		foreach ( $data as $row ) {
			fputcsv( $output, [
				$row['meta_template_id'] ?? '',
				$row['template_name']    ?? '',
				$row['category']         ?? '',
				$row['language']         ?? '',
				$row['status']           ?? '',
				$row['quality_score']    ?? '',
				$row['namespace']        ?? '',
				$row['header_type']      ?? '',
				$row['body']             ?? '',
				$row['footer']           ?? '',
				is_array( $row['buttons'] ) ? wp_json_encode( $row['buttons'] ) : ( $row['buttons'] ?? '' ),
				is_array( $row['variables'] ) ? wp_json_encode( $row['variables'] ) : ( $row['variables'] ?? '' ),
				$row['usage_count']  ?? 0,
				$row['send_success'] ?? 0,
				$row['send_failed']  ?? 0,
				$row['last_synced']  ?? '',
				$row['last_used']    ?? '',
			] );
		}

		rewind( $output );
		$csv = stream_get_contents( $output );
		fclose( $output );

		return is_string( $csv ) ? $csv : '';
	}

	/**
	 * Return the raw export data array.
	 *
	 * @param array<int, int> $template_ids Empty = all templates.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_export_data( array $template_ids = [] ): array {
		if ( $template_ids ) {
			$rows = [];
			foreach ( $template_ids as $id ) {
				$t = $this->repository->find( (int) $id );
				if ( $t ) {
					$rows[] = $t;
				}
			}
		} else {
			$rows = $this->repository->get_all( [ 'per_page' => 1000, 'page' => 1 ] )['rows'];
		}

		return array_map( [ $this, 'row_to_array' ], $rows );
	}

	// -------------------------------------------------------------------------
	// Helper
	// -------------------------------------------------------------------------

	/**
	 * Convert a DB row object to a clean export array.
	 *
	 * @param object $template
	 * @return array<string, mixed>
	 */
	private function row_to_array( object $template ): array {
		return [
			'meta_template_id' => $template->meta_template_id,
			'template_name'    => $template->template_name,
			'category'         => $template->category,
			'language'         => $template->language,
			'status'           => $template->status,
			'quality_score'    => $template->quality_score,
			'namespace'        => $template->namespace,
			'header_type'      => $template->header_type,
			'header_content'   => ! empty( $template->header_content )
				? json_decode( $template->header_content, true )
				: null,
			'body'             => $template->body,
			'footer'           => $template->footer,
			'buttons'          => ! empty( $template->buttons )
				? json_decode( $template->buttons, true )
				: null,
			'variables'        => ! empty( $template->variables )
				? json_decode( $template->variables, true )
				: null,
			'example_values'   => ! empty( $template->example_values )
				? json_decode( $template->example_values, true )
				: null,
			'variable_mapping' => ! empty( $template->variable_mapping )
				? json_decode( $template->variable_mapping, true )
				: null,
			'usage_count'      => (int) $template->usage_count,
			'send_success'     => (int) $template->send_success,
			'send_failed'      => (int) $template->send_failed,
			'last_synced'      => $template->last_synced,
			'last_used'        => $template->last_used,
			'created_at'       => $template->created_at,
		];
	}
}
