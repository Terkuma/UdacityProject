<?php
/**
 * Template-specific logging service.
 *
 * @package TSH\WhatsAppNotify\Templates
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Templates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TSH\WhatsAppNotify\Logger\Logger;

/**
 * Class TemplateLogger
 *
 * Thin wrapper around the central Logger that prefixes the source
 * and attaches template-relevant context to every log entry.
 */
final class TemplateLogger {

	private const SOURCE_PREFIX = 'template';

	/** @var Logger */
	private Logger $logger;

	public function __construct() {
		$this->logger = new Logger();
	}

	// -------------------------------------------------------------------------
	// Sync events
	// -------------------------------------------------------------------------

	/**
	 * Log that a sync operation has started.
	 *
	 * @param string $type manual|incremental|full|background|scheduled
	 */
	public function sync_started( string $type ): void {
		$this->logger->info(
			sprintf(
				/* translators: %s: sync type */
				__( 'Template sync started (type: %s).', 'tsh-whatsapp-notify' ),
				$type
			),
			[ 'sync_type' => $type ],
			self::SOURCE_PREFIX . '.sync'
		);
	}

	/**
	 * Log that a sync completed successfully.
	 *
	 * @param string               $type
	 * @param array<string, mixed> $stats added, updated, deleted, unchanged, errors
	 */
	public function sync_completed( string $type, array $stats ): void {
		$this->logger->success(
			sprintf(
				/* translators: %1$s: sync type, %2$d: added, %3$d: updated, %4$d: deleted */
				__( 'Template sync completed (type: %1$s). Added: %2$d | Updated: %3$d | Deleted: %4$d.', 'tsh-whatsapp-notify' ),
				$type,
				(int) ( $stats['added']   ?? 0 ),
				(int) ( $stats['updated'] ?? 0 ),
				(int) ( $stats['deleted'] ?? 0 )
			),
			$stats,
			self::SOURCE_PREFIX . '.sync'
		);
	}

	/**
	 * Log a sync failure.
	 *
	 * @param string $type
	 * @param string $error
	 */
	public function sync_failed( string $type, string $error ): void {
		$this->logger->error(
			sprintf(
				/* translators: %1$s: sync type, %2$s: error message */
				__( 'Template sync failed (type: %1$s): %2$s', 'tsh-whatsapp-notify' ),
				$type,
				$error
			),
			[ 'sync_type' => $type, 'error' => $error ],
			self::SOURCE_PREFIX . '.sync'
		);
	}

	// -------------------------------------------------------------------------
	// Assignment events
	// -------------------------------------------------------------------------

	/**
	 * Log that a template was assigned to a WooCommerce event.
	 *
	 * @param string $event
	 * @param int    $template_id
	 * @param string $recipient_type customer|admin
	 */
	public function template_assigned( string $event, int $template_id, string $recipient_type = 'customer' ): void {
		$this->logger->info(
			sprintf(
				/* translators: %1$d: template ID, %2$s: event, %3$s: recipient type */
				__( 'Template #%1$d assigned to event "%2$s" (%3$s).', 'tsh-whatsapp-notify' ),
				$template_id,
				$event,
				$recipient_type
			),
			[ 'template_id' => $template_id, 'event' => $event, 'recipient_type' => $recipient_type ],
			self::SOURCE_PREFIX . '.assignment'
		);
	}

	/**
	 * Log that a template assignment was removed.
	 *
	 * @param string $event
	 * @param string $recipient_type
	 */
	public function template_unassigned( string $event, string $recipient_type = 'customer' ): void {
		$this->logger->info(
			sprintf(
				/* translators: %1$s: event, %2$s: recipient type */
				__( 'Template unassigned from event "%1$s" (%2$s).', 'tsh-whatsapp-notify' ),
				$event,
				$recipient_type
			),
			[ 'event' => $event, 'recipient_type' => $recipient_type ],
			self::SOURCE_PREFIX . '.assignment'
		);
	}

	// -------------------------------------------------------------------------
	// Usage events
	// -------------------------------------------------------------------------

	/**
	 * Log template usage.
	 *
	 * @param int   $template_id
	 * @param bool  $success
	 * @param float $latency_ms
	 */
	public function template_used( int $template_id, bool $success, float $latency_ms = 0.0 ): void {
		if ( $success ) {
			$this->logger->success(
				sprintf(
					/* translators: %d: template ID */
					__( 'Template #%d used successfully.', 'tsh-whatsapp-notify' ),
					$template_id
				),
				[ 'template_id' => $template_id, 'latency_ms' => $latency_ms ],
				self::SOURCE_PREFIX . '.usage'
			);
		} else {
			$this->logger->warning(
				sprintf(
					/* translators: %d: template ID */
					__( 'Template #%d usage failed.', 'tsh-whatsapp-notify' ),
					$template_id
				),
				[ 'template_id' => $template_id ],
				self::SOURCE_PREFIX . '.usage'
			);
		}
	}

	// -------------------------------------------------------------------------
	// Validation events
	// -------------------------------------------------------------------------

	/**
	 * Log validation errors.
	 *
	 * @param array<string, string> $errors
	 */
	public function validation_failed( array $errors ): void {
		$this->logger->warning(
			__( 'Template validation failed.', 'tsh-whatsapp-notify' ),
			[ 'errors' => $errors ],
			self::SOURCE_PREFIX . '.validator'
		);
	}

	// -------------------------------------------------------------------------
	// Cache events
	// -------------------------------------------------------------------------

	/**
	 * Log cache flush.
	 */
	public function cache_flushed(): void {
		$this->logger->info(
			__( 'Template cache flushed.', 'tsh-whatsapp-notify' ),
			[],
			self::SOURCE_PREFIX . '.cache'
		);
	}

	// -------------------------------------------------------------------------
	// Import / Export events
	// -------------------------------------------------------------------------

	/**
	 * Log import result.
	 *
	 * @param array<string, mixed> $stats
	 */
	public function import_completed( array $stats ): void {
		$this->logger->success(
			sprintf(
				/* translators: %1$d: imported, %2$d: skipped, %3$d: errors */
				__( 'Template import completed. Imported: %1$d | Skipped: %2$d | Errors: %3$d.', 'tsh-whatsapp-notify' ),
				(int) ( $stats['imported'] ?? 0 ),
				(int) ( $stats['skipped']  ?? 0 ),
				(int) ( $stats['errors']   ?? 0 )
			),
			$stats,
			self::SOURCE_PREFIX . '.import'
		);
	}

	/**
	 * Log a generic debug message.
	 *
	 * @param string               $message
	 * @param array<string, mixed> $context
	 */
	public function debug( string $message, array $context = [] ): void {
		$this->logger->debug( $message, $context, self::SOURCE_PREFIX );
	}

	/**
	 * Log a generic error.
	 *
	 * @param string               $message
	 * @param array<string, mixed> $context
	 */
	public function error( string $message, array $context = [] ): void {
		$this->logger->error( $message, $context, self::SOURCE_PREFIX );
	}
}
