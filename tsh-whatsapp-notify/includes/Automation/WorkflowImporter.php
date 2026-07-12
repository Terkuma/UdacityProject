<?php
/**
 * Workflow import (JSON / template library).
 *
 * @package TSH\WhatsAppNotify\Automation
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Automation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WorkflowImporter
 */
class WorkflowImporter {

	private WorkflowRepository $repo;

	public function __construct() {
		$this->repo = new WorkflowRepository();
	}

	/**
	 * Import workflows from JSON.
	 *
	 * @param string $json   Raw JSON string.
	 * @param string $mode   'merge' (add/update) or 'replace' (delete existing first).
	 * @return array{ imported: int, errors: int, messages: string[] }
	 */
	public function import( string $json, string $mode = 'merge' ): array {
		$result = [ 'imported' => 0, 'errors' => 0, 'messages' => [] ];

		$data = json_decode( $json, true );

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
			$result['errors']++;
			$result['messages'][] = __( 'Invalid JSON.', 'tsh-whatsapp-notify' );
			return $result;
		}

		// Normalise: accept a single workflow object or an array.
		$workflows = isset( $data['workflows'] ) ? $data['workflows'] : ( isset( $data['name'] ) ? [ $data ] : $data );

		if ( 'replace' === $mode ) {
			// Delete all existing workflows.
			$existing = $this->repo->get_workflows( [ 'per_page' => 500, 'status' => 'all' ] );
			foreach ( $existing['rows'] as $wf ) {
				$this->repo->delete_workflow( (int) $wf['id'] );
			}
		}

		$validator = new WorkflowValidator();

		foreach ( $workflows as $workflow_data ) {
			if ( ! is_array( $workflow_data ) ) {
				$result['errors']++;
				continue;
			}

			// Remove export-only fields.
			unset( $workflow_data['id'], $workflow_data['run_count'], $workflow_data['last_run_at'],
			       $workflow_data['created_at'], $workflow_data['updated_at'] );

			// Ensure draft on import.
			$workflow_data['status'] = 'draft';

			$validation = $validator->validate( $workflow_data );

			if ( ! $validation['valid'] ) {
				$result['errors']++;
				$name = $workflow_data['name'] ?? 'Unknown';
				$result['messages'][] = sprintf( __( 'Skipped "%s": %s', 'tsh-whatsapp-notify' ), esc_html( $name ), implode( '; ', $validation['errors'] ) );
				continue;
			}

			$id = $this->repo->create_workflow( $workflow_data );

			if ( $id ) {
				$result['imported']++;
				$result['messages'][] = sprintf( __( 'Imported: "%s" (ID %d)', 'tsh-whatsapp-notify' ), esc_html( $workflow_data['name'] ), $id );
			} else {
				$result['errors']++;
				$result['messages'][] = sprintf( __( 'Failed to save "%s".', 'tsh-whatsapp-notify' ), esc_html( $workflow_data['name'] ?? 'Unknown' ) );
			}
		}

		return $result;
	}

	/**
	 * Import a built-in template by key.
	 *
	 * @param string $template_key  Key from WorkflowExporter::get_templates().
	 * @return int|false New workflow ID, or false on failure.
	 */
	public function import_template( string $template_key ): int|false {
		$templates = WorkflowExporter::get_templates();
		$template  = $templates[ $template_key ] ?? null;

		if ( ! $template ) {
			return false;
		}

		$template['status'] = 'draft';
		unset( $template['id'] );

		return $this->repo->create_workflow( $template );
	}
}
