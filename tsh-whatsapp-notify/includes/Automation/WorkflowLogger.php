<?php
/**
 * Workflow execution logger.
 *
 * @package TSH\WhatsAppNotify\Automation
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Automation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WorkflowLogger
 *
 * Thin wrapper around WorkflowRepository log methods,
 * providing levelled logging with structured data.
 */
class WorkflowLogger {

	private WorkflowRepository $repo;
	private int $workflow_id;
	private int $run_id;

	public function __construct( int $workflow_id, int $run_id ) {
		$this->repo        = new WorkflowRepository();
		$this->workflow_id = $workflow_id;
		$this->run_id      = $run_id;
	}

	public function info( string $message, string $node_id = '', string $node_type = '', array $data = [] ): void {
		$this->log( 'info', $message, $node_id, $node_type, $data );
	}

	public function warning( string $message, string $node_id = '', string $node_type = '', array $data = [] ): void {
		$this->log( 'warning', $message, $node_id, $node_type, $data );
	}

	public function error( string $message, string $node_id = '', string $node_type = '', array $data = [] ): void {
		$this->log( 'error', $message, $node_id, $node_type, $data );
	}

	public function debug( string $message, string $node_id = '', string $node_type = '', array $data = [] ): void {
		$this->log( 'debug', $message, $node_id, $node_type, $data );
	}

	private function log( string $level, string $message, string $node_id, string $node_type, array $data ): void {
		$this->repo->create_log( [
			'run_id'      => $this->run_id,
			'workflow_id' => $this->workflow_id,
			'node_id'     => $node_id,
			'node_type'   => $node_type,
			'level'       => $level,
			'message'     => $message,
			'data'        => $data,
		] );
	}
}
