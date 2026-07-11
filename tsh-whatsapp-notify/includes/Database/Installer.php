<?php
/**
 * Database installer.
 *
 * @package TSH\WhatsAppNotify\Database
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Installer
 *
 * Creates and migrates all plugin database tables using dbDelta().
 *
 * Tables managed:
 *  - {prefix}tsh_wa_logs       — message and event log entries
 *  - {prefix}tsh_wa_queue      — outbound message queue
 *  - {prefix}tsh_wa_templates  — message templates
 *  - {prefix}tsh_wa_settings   — per-row plugin settings (future use)
 *
 * Versioning:
 *  - DB_VERSION is incremented on any schema change.
 *  - ::needs_upgrade() compares DB_VERSION against the stored value.
 *  - ::run() is safe to call on every activation; dbDelta() is idempotent.
 */
final class Installer {

	/**
	 * Current database schema version.
	 * Increment this constant whenever tables are altered.
	 */
	public const DB_VERSION = '2.0.0';

	/**
	 * Run the installer — create or upgrade all tables.
	 */
	public function run(): void {
		$this->create_tables();
		update_option( 'tsh_wa_db_version', self::DB_VERSION, false );
	}

	/**
	 * Return true if the stored DB version is behind DB_VERSION.
	 */
	public function needs_upgrade(): bool {
		$installed = get_option( 'tsh_wa_db_version', '0' );
		return version_compare( $installed, self::DB_VERSION, '<' );
	}

	// -------------------------------------------------------------------------
	// Table creation
	// -------------------------------------------------------------------------

	/**
	 * Build all tables via dbDelta().
	 */
	private function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$sql = [];

		// ------------------------------------------------------------------
		// Logs table
		// ------------------------------------------------------------------
		$logs = $wpdb->prefix . 'tsh_wa_logs';
		$sql[] = "CREATE TABLE {$logs} (
			id            BIGINT(20) UNSIGNED    NOT NULL AUTO_INCREMENT,
			level         VARCHAR(20)            NOT NULL DEFAULT 'info'   COMMENT 'success|info|warning|error|debug',
			source        VARCHAR(100)           NOT NULL DEFAULT 'system' COMMENT 'Originating class or hook',
			message       TEXT                   NOT NULL,
			context       LONGTEXT                        DEFAULT NULL     COMMENT 'JSON-encoded contextual data',
			order_id      BIGINT(20) UNSIGNED             DEFAULT NULL,
			phone         VARCHAR(30)                     DEFAULT NULL,
			created_at    DATETIME               NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY   (id),
			KEY           idx_level      (level),
			KEY           idx_source     (source),
			KEY           idx_order_id   (order_id),
			KEY           idx_created_at (created_at)
		) ENGINE=InnoDB {$charset_collate};";

		// ------------------------------------------------------------------
		// Queue table
		// ------------------------------------------------------------------
		$queue = $wpdb->prefix . 'tsh_wa_queue';
		$sql[] = "CREATE TABLE {$queue} (
			id              BIGINT(20) UNSIGNED  NOT NULL AUTO_INCREMENT,
			phone           VARCHAR(30)          NOT NULL,
			message         LONGTEXT             NOT NULL,
			template_id     BIGINT(20) UNSIGNED           DEFAULT NULL,
			order_id        BIGINT(20) UNSIGNED           DEFAULT NULL,
			status          VARCHAR(20)          NOT NULL DEFAULT 'pending' COMMENT 'pending|processing|sent|failed|cancelled',
			priority        TINYINT(3) UNSIGNED  NOT NULL DEFAULT 5         COMMENT '1 = highest, 10 = lowest',
			attempts        TINYINT(3) UNSIGNED  NOT NULL DEFAULT 0,
			max_attempts    TINYINT(3) UNSIGNED  NOT NULL DEFAULT 3,
			scheduled_at    DATETIME             NOT NULL,
			processed_at    DATETIME                      DEFAULT NULL,
			error_message   TEXT                          DEFAULT NULL,
			meta            LONGTEXT                      DEFAULT NULL      COMMENT 'JSON-encoded extra data',
			created_at      DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at      DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY     (id),
			KEY             idx_status       (status),
			KEY             idx_phone        (phone),
			KEY             idx_order_id     (order_id),
			KEY             idx_scheduled_at (scheduled_at),
			KEY             idx_priority     (priority)
		) ENGINE=InnoDB {$charset_collate};";

		// ------------------------------------------------------------------
		// Templates table
		// ------------------------------------------------------------------
		$templates = $wpdb->prefix . 'tsh_wa_templates';
		$sql[] = "CREATE TABLE {$templates} (
			id              BIGINT(20) UNSIGNED  NOT NULL AUTO_INCREMENT,
			name            VARCHAR(200)         NOT NULL,
			slug            VARCHAR(200)         NOT NULL,
			type            VARCHAR(50)          NOT NULL DEFAULT 'custom'  COMMENT 'custom|order|system',
			trigger_event   VARCHAR(100)                  DEFAULT NULL      COMMENT 'WC hook or custom event name',
			language        VARCHAR(10)          NOT NULL DEFAULT 'en',
			message_body    LONGTEXT             NOT NULL,
			variables       LONGTEXT                      DEFAULT NULL      COMMENT 'JSON array of supported variable names',
			status          VARCHAR(20)          NOT NULL DEFAULT 'active'  COMMENT 'active|inactive|draft',
			meta            LONGTEXT                      DEFAULT NULL      COMMENT 'JSON-encoded extra metadata',
			created_at      DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at      DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY     (id),
			UNIQUE KEY      uq_slug         (slug),
			KEY             idx_status      (status),
			KEY             idx_type        (type),
			KEY             idx_trigger     (trigger_event)
		) ENGINE=InnoDB {$charset_collate};";

		// ------------------------------------------------------------------
		// Settings table (key/value store for future fine-grained settings)
		// ------------------------------------------------------------------
		$settings = $wpdb->prefix . 'tsh_wa_settings';
		$sql[] = "CREATE TABLE {$settings} (
			id              BIGINT(20) UNSIGNED  NOT NULL AUTO_INCREMENT,
			option_key      VARCHAR(200)         NOT NULL,
			option_value    LONGTEXT                      DEFAULT NULL,
			autoload        VARCHAR(3)           NOT NULL DEFAULT 'yes',
			created_at      DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at      DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY     (id),
			UNIQUE KEY      uq_option_key (option_key)
		) ENGINE=InnoDB {$charset_collate};";

		// ------------------------------------------------------------------
		// API requests log (Phase 2)
		// ------------------------------------------------------------------
		$api_requests = $wpdb->prefix . 'tsh_wa_api_requests';
		$sql[] = "CREATE TABLE {$api_requests} (
			id              BIGINT(20) UNSIGNED  NOT NULL AUTO_INCREMENT,
			endpoint        VARCHAR(500)         NOT NULL               COMMENT 'Relative endpoint path, e.g. 123456/messages',
			method          VARCHAR(10)          NOT NULL DEFAULT 'POST' COMMENT 'HTTP verb',
			latency_ms      DECIMAL(10,3)        NOT NULL DEFAULT 0.000  COMMENT 'Round-trip time in milliseconds',
			http_status     SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
			success         TINYINT(1)           NOT NULL DEFAULT 0,
			retry_count     TINYINT(3) UNSIGNED  NOT NULL DEFAULT 0,
			error_code      VARCHAR(100)                  DEFAULT NULL,
			response_size   INT(10) UNSIGNED     NOT NULL DEFAULT 0      COMMENT 'Byte length of response body',
			response_body   LONGTEXT                      DEFAULT NULL   COMMENT 'Raw JSON body — stored only in debug mode',
			created_at      DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY     (id),
			KEY             idx_success    (success),
			KEY             idx_created_at (created_at),
			KEY             idx_http_status(http_status)
		) ENGINE=InnoDB {$charset_collate};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}
}
