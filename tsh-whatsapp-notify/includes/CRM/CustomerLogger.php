<?php
/**
 * Customer Logger — CRM log wrapper.
 *
 * @package TSH\WhatsAppNotify\CRM
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\CRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CustomerLogger
 *
 * Thin wrapper around WooCommerce's logger for CRM-specific messages.
 */
final class CustomerLogger {

	private string $source = 'tsh-wa-crm';

	public function info( string $message, array $context = [] ): void {
		$this->log( 'info', $message, $context );
	}

	public function warning( string $message, array $context = [] ): void {
		$this->log( 'warning', $message, $context );
	}

	public function error( string $message, array $context = [] ): void {
		$this->log( 'error', $message, $context );
	}

	public function debug( string $message, array $context = [] ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$this->log( 'debug', $message, $context );
		}
	}

	private function log( string $level, string $message, array $context = [] ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			$logger = wc_get_logger();
			$logger->$level( $message, array_merge( $context, [ 'source' => $this->source ] ) );
		}
	}
}
