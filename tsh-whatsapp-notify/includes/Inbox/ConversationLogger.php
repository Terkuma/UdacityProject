<?php
/**
 * Conversation-specific logger.
 *
 * @package TSH\WhatsAppNotify\Inbox
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Inbox;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TSH\WhatsAppNotify\Logger\Logger;

/**
 * Class ConversationLogger
 *
 * Thin wrapper around Logger that prefixes all entries with source 'inbox'.
 * Keeps Inbox module logging consistent and filterable in the Logs page.
 */
final class ConversationLogger {

	/** @var Logger */
	private Logger $logger;

	/** @var string Log source prefix. */
	private const SOURCE = 'inbox';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->logger = new Logger();
	}

	/**
	 * Log an informational message.
	 *
	 * @param string               $message Human-readable message.
	 * @param array<string, mixed> $context Optional context data.
	 * @param string               $phone   Optional phone number.
	 */
	public function info( string $message, array $context = [], string $phone = '' ): void {
		$this->logger->info( $message, self::SOURCE, $context, null, $phone );
	}

	/**
	 * Log a debug message.
	 *
	 * @param string               $message
	 * @param array<string, mixed> $context
	 * @param string               $phone
	 */
	public function debug( string $message, array $context = [], string $phone = '' ): void {
		$this->logger->debug( $message, self::SOURCE, $context, null, $phone );
	}

	/**
	 * Log a warning.
	 *
	 * @param string               $message
	 * @param array<string, mixed> $context
	 * @param string               $phone
	 */
	public function warning( string $message, array $context = [], string $phone = '' ): void {
		$this->logger->warning( $message, self::SOURCE, $context, null, $phone );
	}

	/**
	 * Log an error.
	 *
	 * @param string               $message
	 * @param array<string, mixed> $context
	 * @param string               $phone
	 */
	public function error( string $message, array $context = [], string $phone = '' ): void {
		$this->logger->error( $message, self::SOURCE, $context, null, $phone );
	}

	/**
	 * Log a success message.
	 *
	 * @param string               $message
	 * @param array<string, mixed> $context
	 * @param string               $phone
	 */
	public function success( string $message, array $context = [], string $phone = '' ): void {
		$this->logger->success( $message, self::SOURCE, $context, null, $phone );
	}
}
