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
	public const DB_VERSION = '9.0.0';

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
			status          VARCHAR(20)          NOT NULL DEFAULT 'pending'  COMMENT 'pending|processing|sent|failed|cancelled',
			priority        TINYINT(3) UNSIGNED  NOT NULL DEFAULT 5          COMMENT '1 = highest, 10 = lowest',
			attempts        TINYINT(3) UNSIGNED  NOT NULL DEFAULT 0,
			max_attempts    TINYINT(3) UNSIGNED  NOT NULL DEFAULT 3,
			scheduled_at    DATETIME             NOT NULL,
			processed_at    DATETIME                      DEFAULT NULL,
			error_message   TEXT                          DEFAULT NULL,
			meta            LONGTEXT                      DEFAULT NULL       COMMENT 'JSON-encoded extra data',
			retry_after     DATETIME                      DEFAULT NULL       COMMENT 'Phase 4: earliest time for next retry attempt',
			message_id      VARCHAR(100)                  DEFAULT NULL       COMMENT 'Phase 4: WhatsApp message ID from API response',
			delivery_status VARCHAR(20)          NOT NULL DEFAULT 'queued'   COMMENT 'Phase 4: queued|sending|sent|delivered|read|failed|expired|retrying',
			worker_id       VARCHAR(64)                   DEFAULT NULL       COMMENT 'Phase 4: worker ID that processed this item',
			latency_ms      DECIMAL(10,3)                 DEFAULT NULL       COMMENT 'Phase 4: API call latency in milliseconds',
			created_at      DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at      DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY     (id),
			KEY             idx_status           (status),
			KEY             idx_phone            (phone),
			KEY             idx_order_id         (order_id),
			KEY             idx_scheduled_at     (scheduled_at),
			KEY             idx_priority         (priority),
			KEY             idx_delivery_status  (delivery_status),
			KEY             idx_retry_after      (retry_after)
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

		// ------------------------------------------------------------------
		// Notifications table (Phase 3) — permanent dispatch record + dedup
		// ------------------------------------------------------------------
		$notifications = $wpdb->prefix . 'tsh_wa_notifications';
		$sql[] = "CREATE TABLE {$notifications} (
			id               BIGINT(20) UNSIGNED  NOT NULL AUTO_INCREMENT,
			order_id         BIGINT(20) UNSIGNED  NOT NULL,
			event            VARCHAR(50)          NOT NULL                   COMMENT 'Plugin event key',
			recipient_phone  VARCHAR(30)          NOT NULL,
			recipient_type   VARCHAR(20)          NOT NULL DEFAULT 'customer' COMMENT 'customer|admin',
			recipient_name   VARCHAR(200)                  DEFAULT NULL,
			template_slug    VARCHAR(200)                  DEFAULT NULL,
			message_hash     VARCHAR(64)          NOT NULL                   COMMENT 'SHA-256 of message body for duplicate detection',
			queue_id         BIGINT(20) UNSIGNED           DEFAULT NULL,
			status           VARCHAR(20)          NOT NULL DEFAULT 'queued'  COMMENT 'queued|sent|failed|skipped',
			error_message    TEXT                          DEFAULT NULL,
			created_at       DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at       DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY      (id),
			KEY              idx_order_id     (order_id),
			KEY              idx_event        (event),
			KEY              idx_status       (status),
			KEY              idx_queue_id     (queue_id),
			KEY              idx_dedup        (order_id, event, recipient_phone, status)
		) ENGINE=InnoDB {$charset_collate};";

		// ------------------------------------------------------------------
		// Delivery events table (Phase 4) — per-item status timeline
		// ------------------------------------------------------------------
		$delivery_events = $wpdb->prefix . 'tsh_wa_delivery_events';
		$sql[] = "CREATE TABLE {$delivery_events} (
			id              BIGINT(20) UNSIGNED  NOT NULL AUTO_INCREMENT,
			queue_id        BIGINT(20) UNSIGNED  NOT NULL                    COMMENT 'FK → tsh_wa_queue.id',
			status          VARCHAR(20)          NOT NULL                    COMMENT 'queued|sending|sent|delivered|read|failed|expired|retrying',
			response_data   LONGTEXT                      DEFAULT NULL       COMMENT 'JSON: raw API response or context',
			created_at      DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY     (id),
			KEY             idx_queue_id   (queue_id),
			KEY             idx_status     (status),
			KEY             idx_created_at (created_at)
		) ENGINE=InnoDB {$charset_collate};";

		// ------------------------------------------------------------------
		// Worker log table (Phase 4) — one row per cron batch run
		// ------------------------------------------------------------------
		$worker_log = $wpdb->prefix . 'tsh_wa_worker_log';
		$sql[] = "CREATE TABLE {$worker_log} (
			id               BIGINT(20) UNSIGNED  NOT NULL AUTO_INCREMENT,
			worker_id        VARCHAR(64)          NOT NULL,
			started_at       DATETIME             NOT NULL,
			finished_at      DATETIME                      DEFAULT NULL,
			batch_size       TINYINT(3) UNSIGNED  NOT NULL DEFAULT 10,
			items_processed  INT(10) UNSIGNED     NOT NULL DEFAULT 0,
			items_sent       INT(10) UNSIGNED     NOT NULL DEFAULT 0,
			items_failed     INT(10) UNSIGNED     NOT NULL DEFAULT 0,
			items_retried    INT(10) UNSIGNED     NOT NULL DEFAULT 0,
			items_skipped    INT(10) UNSIGNED     NOT NULL DEFAULT 0,
			duration_ms      DECIMAL(10,3)                 DEFAULT NULL,
			rate_limited     TINYINT(1)           NOT NULL DEFAULT 0        COMMENT '1 if batch was cut short by rate limiter',
			created_at       DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY      (id),
			KEY              idx_worker_id  (worker_id),
			KEY              idx_started_at (started_at)
		) ENGINE=InnoDB {$charset_collate};";

		// ------------------------------------------------------------------
		// Meta templates table (Phase 5) — synced from Meta Graph API
		// ------------------------------------------------------------------
		$meta_templates = $wpdb->prefix . 'tsh_wa_meta_templates';
		$sql[] = "CREATE TABLE {$meta_templates} (
			id                BIGINT(20) UNSIGNED  NOT NULL AUTO_INCREMENT,
			meta_template_id  VARCHAR(100)         NOT NULL                   COMMENT 'Template ID from Meta Graph API',
			template_name     VARCHAR(200)         NOT NULL                   COMMENT 'Meta template name (lowercase, underscores)',
			category          VARCHAR(30)          NOT NULL DEFAULT 'UTILITY' COMMENT 'UTILITY|MARKETING|AUTHENTICATION',
			language          VARCHAR(20)          NOT NULL DEFAULT 'en',
			status            VARCHAR(20)          NOT NULL DEFAULT 'PENDING' COMMENT 'APPROVED|PENDING|REJECTED|PAUSED|DISABLED|DELETED|DRAFT|EXPIRED',
			quality_score     VARCHAR(20)          NOT NULL DEFAULT 'UNKNOWN' COMMENT 'HIGH|MEDIUM|LOW|UNKNOWN',
			namespace         VARCHAR(200)                  DEFAULT NULL       COMMENT 'WhatsApp Business API namespace',
			header_type       VARCHAR(20)                   DEFAULT NULL       COMMENT 'TEXT|IMAGE|VIDEO|DOCUMENT|LOCATION',
			header_content    LONGTEXT                      DEFAULT NULL       COMMENT 'JSON: header component data from Meta',
			body              LONGTEXT                      DEFAULT NULL       COMMENT 'Template body text with {{N}} placeholders',
			footer            VARCHAR(60)                   DEFAULT NULL,
			buttons           LONGTEXT                      DEFAULT NULL       COMMENT 'JSON array of button objects',
			variables         LONGTEXT                      DEFAULT NULL       COMMENT 'JSON array of example variable values',
			example_values    LONGTEXT                      DEFAULT NULL       COMMENT 'JSON: full components array from Meta for example rendering',
			variable_mapping  LONGTEXT                      DEFAULT NULL       COMMENT 'JSON: admin-configured {N: {wc_field, example}} map',
			usage_count       INT(10) UNSIGNED     NOT NULL DEFAULT 0,
			send_success      INT(10) UNSIGNED     NOT NULL DEFAULT 0,
			send_failed       INT(10) UNSIGNED     NOT NULL DEFAULT 0,
			raw_data          LONGTEXT                      DEFAULT NULL       COMMENT 'JSON: raw API response object',
			last_synced       DATETIME                      DEFAULT NULL,
			last_used         DATETIME                      DEFAULT NULL,
			created_at        DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at        DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY       (id),
			UNIQUE KEY        uq_meta_id      (meta_template_id),
			KEY               idx_name        (template_name),
			KEY               idx_category    (category),
			KEY               idx_language    (language),
			KEY               idx_status      (status),
			KEY               idx_quality     (quality_score),
			KEY               idx_last_synced (last_synced),
			KEY               idx_usage       (usage_count)
		) ENGINE=InnoDB {$charset_collate};";

		// ------------------------------------------------------------------
		// Template assignments table (Phase 5) — WC event → template mapping
		// ------------------------------------------------------------------
		$template_assignments = $wpdb->prefix . 'tsh_wa_template_assignments';
		$sql[] = "CREATE TABLE {$template_assignments} (
			id              BIGINT(20) UNSIGNED  NOT NULL AUTO_INCREMENT,
			event           VARCHAR(100)         NOT NULL                   COMMENT 'WooCommerce event key e.g. order_processing',
			template_id     BIGINT(20) UNSIGNED  NOT NULL                   COMMENT 'FK → tsh_wa_meta_templates.id',
			recipient_type  VARCHAR(20)          NOT NULL DEFAULT 'customer' COMMENT 'customer|admin',
			language        VARCHAR(20)                   DEFAULT NULL       COMMENT 'Language preference override',
			active          TINYINT(1)           NOT NULL DEFAULT 1,
			created_at      DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at      DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY     (id),
			UNIQUE KEY      uq_event_recipient  (event, recipient_type),
			KEY             idx_template_id     (template_id),
			KEY             idx_active          (active)
		) ENGINE=InnoDB {$charset_collate};";

		// ------------------------------------------------------------------
		// Conversations table (Phase 6) — one row per unique phone number
		// ------------------------------------------------------------------
		$conversations = $wpdb->prefix . 'tsh_wa_conversations';
		$sql[] = "CREATE TABLE {$conversations} (
			id                BIGINT(20) UNSIGNED  NOT NULL AUTO_INCREMENT,
			phone             VARCHAR(30)          NOT NULL                    COMMENT 'E.164 phone number',
			contact_name      VARCHAR(200)                  DEFAULT NULL       COMMENT 'Display name from webhook contact profile',
			customer_id       BIGINT(20) UNSIGNED           DEFAULT NULL       COMMENT 'WP user ID (null = guest)',
			order_id          BIGINT(20) UNSIGNED           DEFAULT NULL       COMMENT 'Most recently linked WC order ID',
			status            VARCHAR(20)          NOT NULL DEFAULT 'open'     COMMENT 'open|closed|archived',
			assigned_to       BIGINT(20) UNSIGNED           DEFAULT NULL       COMMENT 'WP user ID of assigned agent',
			labels            LONGTEXT                      DEFAULT NULL       COMMENT 'JSON array of label slugs',
			is_pinned         TINYINT(1)           NOT NULL DEFAULT 0,
			is_archived       TINYINT(1)           NOT NULL DEFAULT 0,
			unread_count      INT(10) UNSIGNED     NOT NULL DEFAULT 0,
			last_message_at   DATETIME                      DEFAULT NULL,
			last_message_text VARCHAR(500)                  DEFAULT NULL       COMMENT 'Preview text for conversation list',
			created_at        DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at        DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY       (id),
			UNIQUE KEY        uq_phone          (phone),
			KEY               idx_status        (status),
			KEY               idx_customer_id   (customer_id),
			KEY               idx_assigned_to   (assigned_to),
			KEY               idx_last_msg_at   (last_message_at),
			KEY               idx_is_archived   (is_archived),
			KEY               idx_is_pinned     (is_pinned)
		) ENGINE=InnoDB {$charset_collate};";

		// ------------------------------------------------------------------
		// Messages table (Phase 6) — every individual message
		// ------------------------------------------------------------------
		$messages = $wpdb->prefix . 'tsh_wa_messages';
		$sql[] = "CREATE TABLE {$messages} (
			id                BIGINT(20) UNSIGNED  NOT NULL AUTO_INCREMENT,
			conversation_id   BIGINT(20) UNSIGNED  NOT NULL                    COMMENT 'FK → tsh_wa_conversations.id',
			meta_message_id   VARCHAR(100)                  DEFAULT NULL       COMMENT 'WhatsApp message ID from Meta (unique per message)',
			phone             VARCHAR(30)          NOT NULL,
			direction         VARCHAR(10)          NOT NULL DEFAULT 'incoming' COMMENT 'incoming|outgoing',
			status            VARCHAR(20)          NOT NULL DEFAULT 'received' COMMENT 'received|queued|sending|sent|delivered|read|failed|retrying',
			message_type      VARCHAR(30)          NOT NULL DEFAULT 'text'     COMMENT 'text|image|audio|video|document|sticker|location|interactive|button|reaction|note',
			content           LONGTEXT                      DEFAULT NULL       COMMENT 'Text body or caption',
			media_id          VARCHAR(200)                  DEFAULT NULL       COMMENT 'Meta media object ID',
			media_url         VARCHAR(2000)                 DEFAULT NULL       COMMENT 'Temporary Meta media URL (expires)',
			local_path        VARCHAR(500)                  DEFAULT NULL       COMMENT 'Path relative to WP uploads base after download',
			mime_type         VARCHAR(100)                  DEFAULT NULL,
			is_note           TINYINT(1)           NOT NULL DEFAULT 0          COMMENT '1 = internal note visible to admins only',
			is_deleted        TINYINT(1)           NOT NULL DEFAULT 0,
			timestamp         DATETIME             NOT NULL                    COMMENT 'WhatsApp message timestamp',
			raw_payload       LONGTEXT                      DEFAULT NULL       COMMENT 'Full JSON webhook payload',
			created_at        DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY       (id),
			UNIQUE KEY        uq_meta_message_id  (meta_message_id),
			KEY               idx_conversation_id (conversation_id),
			KEY               idx_phone           (phone),
			KEY               idx_direction       (direction),
			KEY               idx_timestamp       (timestamp),
			KEY               idx_status          (status),
			KEY               idx_message_type    (message_type),
			KEY               idx_is_note         (is_note)
		) ENGINE=InnoDB {$charset_collate};";

		// ------------------------------------------------------------------
		// Workflows table (Phase 7)
		// ------------------------------------------------------------------
		$workflows = $wpdb->prefix . 'tsh_wa_workflows';
		$sql[] = "CREATE TABLE {$workflows} (
			id               BIGINT(20) UNSIGNED  NOT NULL AUTO_INCREMENT,
			name             VARCHAR(200)         NOT NULL                    COMMENT 'Workflow display name',
			description      TEXT                          DEFAULT NULL,
			status           VARCHAR(20)          NOT NULL DEFAULT 'draft'    COMMENT 'active|inactive|draft',
			trigger_type     VARCHAR(100)         NOT NULL DEFAULT ''         COMMENT 'Trigger key from TriggerManager',
			trigger_config   LONGTEXT                      DEFAULT NULL       COMMENT 'JSON trigger configuration',
			nodes            LONGTEXT                      DEFAULT NULL       COMMENT 'JSON node graph array',
			edges            LONGTEXT                      DEFAULT NULL       COMMENT 'JSON edge connections array',
			settings         LONGTEXT                      DEFAULT NULL       COMMENT 'JSON per-workflow settings',
			run_count        BIGINT(20) UNSIGNED  NOT NULL DEFAULT 0,
			last_run_at      DATETIME                      DEFAULT NULL,
			created_by       BIGINT(20) UNSIGNED           DEFAULT NULL       COMMENT 'WP user ID',
			created_at       DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at       DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY      (id),
			KEY              idx_status        (status),
			KEY              idx_trigger_type  (trigger_type),
			KEY              idx_created_by    (created_by),
			KEY              idx_last_run_at   (last_run_at)
		) ENGINE=InnoDB {$charset_collate};";

		// ------------------------------------------------------------------
		// Workflow runs table (Phase 7) — one row per execution
		// ------------------------------------------------------------------
		$runs = $wpdb->prefix . 'tsh_wa_workflow_runs';
		$sql[] = "CREATE TABLE {$runs} (
			id                  BIGINT(20) UNSIGNED  NOT NULL AUTO_INCREMENT,
			workflow_id         BIGINT(20) UNSIGNED  NOT NULL,
			trigger_type        VARCHAR(100)         NOT NULL DEFAULT '',
			trigger_data        LONGTEXT                      DEFAULT NULL   COMMENT 'JSON trigger payload',
			status              VARCHAR(20)          NOT NULL DEFAULT 'pending' COMMENT 'pending|running|completed|failed|cancelled',
			started_at          DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
			completed_at        DATETIME                      DEFAULT NULL,
			execution_time_ms   INT(10) UNSIGNED              DEFAULT NULL,
			steps_completed     INT(10) UNSIGNED     NOT NULL DEFAULT 0,
			error_message       TEXT                          DEFAULT NULL,
			context             LONGTEXT                      DEFAULT NULL   COMMENT 'JSON execution context (step outputs, etc.)',
			PRIMARY KEY         (id),
			KEY                 idx_workflow_id   (workflow_id),
			KEY                 idx_status        (status),
			KEY                 idx_started_at    (started_at)
		) ENGINE=InnoDB {$charset_collate};";

		// ------------------------------------------------------------------
		// Workflow logs table (Phase 7) — per-node execution log entries
		// ------------------------------------------------------------------
		$logs = $wpdb->prefix . 'tsh_wa_workflow_logs';
		$sql[] = "CREATE TABLE {$logs} (
			id           BIGINT(20) UNSIGNED  NOT NULL AUTO_INCREMENT,
			run_id       BIGINT(20) UNSIGNED  NOT NULL,
			workflow_id  BIGINT(20) UNSIGNED  NOT NULL,
			node_id      VARCHAR(100)                  DEFAULT NULL,
			node_type    VARCHAR(100)                  DEFAULT NULL,
			level        VARCHAR(20)          NOT NULL DEFAULT 'info'   COMMENT 'info|warning|error|debug',
			message      TEXT                 NOT NULL,
			data         LONGTEXT                      DEFAULT NULL     COMMENT 'JSON extra data',
			created_at   DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY          idx_run_id       (run_id),
			KEY          idx_workflow_id  (workflow_id),
			KEY          idx_level        (level),
			KEY          idx_created_at   (created_at)
		) ENGINE=InnoDB {$charset_collate};";

		// ------------------------------------------------------------------
		// Campaigns table (Phase 8) — marketing campaign definitions
		// ------------------------------------------------------------------
		$campaigns = $wpdb->prefix . 'tsh_wa_campaigns';
		$sql[] = "CREATE TABLE {$campaigns} (
			id                BIGINT(20) UNSIGNED  NOT NULL AUTO_INCREMENT,
			name              VARCHAR(200)         NOT NULL,
			description       TEXT                          DEFAULT NULL,
			status            VARCHAR(30)          NOT NULL DEFAULT 'draft'     COMMENT 'draft|scheduled|running|paused|completed|failed|cancelled|archived',
			type              VARCHAR(30)          NOT NULL DEFAULT 'onetime'   COMMENT 'onetime|scheduled|recurring',
			audience_config   LONGTEXT                      DEFAULT NULL        COMMENT 'JSON: segment rules',
			template_id       BIGINT(20) UNSIGNED           DEFAULT NULL        COMMENT 'FK → tsh_wa_meta_templates.id (variant A)',
			template_b_id     BIGINT(20) UNSIGNED           DEFAULT NULL        COMMENT 'FK → tsh_wa_meta_templates.id (variant B for A/B)',
			ab_split_ratio    TINYINT(3) UNSIGNED  NOT NULL DEFAULT 50          COMMENT '% of audience receiving template B',
			message_config    LONGTEXT                      DEFAULT NULL        COMMENT 'JSON: body, variables, media',
			schedule_config   LONGTEXT                      DEFAULT NULL        COMMENT 'JSON: recurrence, day_of_week, time, timezone',
			coupon_config     LONGTEXT                      DEFAULT NULL        COMMENT 'JSON: discount_type, amount, expiry_days, etc.',
			throttle_config   LONGTEXT                      DEFAULT NULL        COMMENT 'JSON: msgs_per_minute, msgs_per_hour, batch_size, retry_attempts',
			send_at           DATETIME                      DEFAULT NULL        COMMENT 'Scheduled send time (NULL = immediate)',
			sent_at           DATETIME                      DEFAULT NULL        COMMENT 'Actual send start time',
			completed_at      DATETIME                      DEFAULT NULL,
			total_audience    BIGINT(20) UNSIGNED  NOT NULL DEFAULT 0,
			total_sent        BIGINT(20) UNSIGNED  NOT NULL DEFAULT 0,
			total_delivered   BIGINT(20) UNSIGNED  NOT NULL DEFAULT 0,
			total_read        BIGINT(20) UNSIGNED  NOT NULL DEFAULT 0,
			total_failed      BIGINT(20) UNSIGNED  NOT NULL DEFAULT 0,
			total_coupons     BIGINT(20) UNSIGNED  NOT NULL DEFAULT 0,
			created_by        BIGINT(20) UNSIGNED           DEFAULT NULL        COMMENT 'WP user ID',
			created_at        DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at        DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY       (id),
			KEY               idx_status      (status),
			KEY               idx_type        (type),
			KEY               idx_send_at     (send_at),
			KEY               idx_created_at  (created_at)
		) ENGINE=InnoDB {$charset_collate};";

		// ------------------------------------------------------------------
		// Campaign runs table (Phase 8) — one row per execution instance
		// ------------------------------------------------------------------
		$campaign_runs = $wpdb->prefix . 'tsh_wa_campaign_runs';
		$sql[] = "CREATE TABLE {$campaign_runs} (
			id              BIGINT(20) UNSIGNED  NOT NULL AUTO_INCREMENT,
			campaign_id     BIGINT(20) UNSIGNED  NOT NULL                   COMMENT 'FK → tsh_wa_campaigns.id',
			status          VARCHAR(30)          NOT NULL DEFAULT 'pending'  COMMENT 'pending|running|completed|failed|paused',
			started_at      DATETIME                      DEFAULT NULL,
			completed_at    DATETIME                      DEFAULT NULL,
			total_queued    BIGINT(20) UNSIGNED  NOT NULL DEFAULT 0,
			total_sent      BIGINT(20) UNSIGNED  NOT NULL DEFAULT 0,
			total_failed    BIGINT(20) UNSIGNED  NOT NULL DEFAULT 0,
			error_message   TEXT                          DEFAULT NULL,
			batch_offset    BIGINT(20) UNSIGNED  NOT NULL DEFAULT 0          COMMENT 'Resume offset into audience table',
			context         LONGTEXT                      DEFAULT NULL        COMMENT 'JSON: stage, audience_total, etc.',
			created_at      DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY     (id),
			KEY             idx_campaign_id  (campaign_id),
			KEY             idx_status       (status),
			KEY             idx_started_at   (started_at)
		) ENGINE=InnoDB {$charset_collate};";

		// ------------------------------------------------------------------
		// Campaign audience table (Phase 8) — per-recipient rows for each run
		// ------------------------------------------------------------------
		$campaign_audience = $wpdb->prefix . 'tsh_wa_campaign_audience';
		$sql[] = "CREATE TABLE {$campaign_audience} (
			id                BIGINT(20) UNSIGNED  NOT NULL AUTO_INCREMENT,
			campaign_id       BIGINT(20) UNSIGNED  NOT NULL,
			run_id            BIGINT(20) UNSIGNED  NOT NULL,
			customer_id       BIGINT(20) UNSIGNED  NOT NULL DEFAULT 0,
			phone             VARCHAR(30)          NOT NULL DEFAULT '',
			email             VARCHAR(200)                  DEFAULT '',
			name              VARCHAR(200)                  DEFAULT '',
			coupon_code       VARCHAR(100)                  DEFAULT NULL,
			queue_id          BIGINT(20) UNSIGNED           DEFAULT NULL        COMMENT 'FK → tsh_wa_queue.id',
			status            VARCHAR(30)          NOT NULL DEFAULT 'pending'   COMMENT 'pending|queued|sent|failed|skipped',
			template_variant  VARCHAR(5)           NOT NULL DEFAULT 'a'         COMMENT 'a|b',
			sent_at           DATETIME                      DEFAULT NULL,
			error_message     TEXT                          DEFAULT NULL,
			created_at        DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY       (id),
			KEY               idx_campaign_run  (campaign_id, run_id),
			KEY               idx_run_status    (run_id, status),
			KEY               idx_customer_id   (customer_id),
			KEY               idx_status        (status)
		) ENGINE=InnoDB {$charset_collate};";

		// ------------------------------------------------------------------
		// Campaign logs table (Phase 8) — per-campaign audit/event log
		// ------------------------------------------------------------------
		$campaign_logs = $wpdb->prefix . 'tsh_wa_campaign_logs';
		$sql[] = "CREATE TABLE {$campaign_logs} (
			id           BIGINT(20) UNSIGNED  NOT NULL AUTO_INCREMENT,
			campaign_id  BIGINT(20) UNSIGNED  NOT NULL,
			run_id       BIGINT(20) UNSIGNED           DEFAULT NULL,
			level        VARCHAR(20)          NOT NULL DEFAULT 'info'   COMMENT 'info|warning|error|debug',
			message      TEXT                 NOT NULL,
			data         LONGTEXT                      DEFAULT NULL     COMMENT 'JSON extra data',
			created_at   DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY          idx_campaign_id  (campaign_id),
			KEY          idx_run_id       (run_id),
			KEY          idx_level        (level),
			KEY          idx_created_at   (created_at)
		) ENGINE=InnoDB {$charset_collate};";

		// ==================================================================
		// CRM tables (Phase 9)
		// ==================================================================

		// Main CRM customer records
		$customers = $wpdb->prefix . 'tsh_wa_customers';
		$sql[] = "CREATE TABLE {$customers} (
			id                  BIGINT(20) UNSIGNED  NOT NULL AUTO_INCREMENT,
			wp_user_id          BIGINT(20) UNSIGNED           DEFAULT NULL        COMMENT 'FK → wp_users.ID',
			wc_customer_id      BIGINT(20) UNSIGNED           DEFAULT NULL        COMMENT 'WooCommerce customer ID',
			phone               VARCHAR(30)          NOT NULL DEFAULT ''          COMMENT 'Primary contact phone',
			whatsapp_phone      VARCHAR(30)          NOT NULL DEFAULT ''          COMMENT 'WhatsApp number (may differ from phone)',
			email               VARCHAR(200)                  DEFAULT NULL,
			first_name          VARCHAR(100)         NOT NULL DEFAULT '',
			last_name           VARCHAR(100)         NOT NULL DEFAULT '',
			full_name           VARCHAR(200)         NOT NULL DEFAULT '',
			avatar_url          VARCHAR(500)                  DEFAULT NULL,
			country             VARCHAR(5)           NOT NULL DEFAULT '',
			state               VARCHAR(100)         NOT NULL DEFAULT '',
			city                VARCHAR(100)         NOT NULL DEFAULT '',
			address             TEXT                          DEFAULT NULL,
			language            VARCHAR(10)          NOT NULL DEFAULT '',
			timezone            VARCHAR(50)          NOT NULL DEFAULT '',
			birthday            DATE                          DEFAULT NULL,
			anniversary         DATE                          DEFAULT NULL,
			lifecycle           VARCHAR(30)          NOT NULL DEFAULT 'lead'      COMMENT 'lead|new|active|returning|vip|dormant|inactive|lost',
			is_vip              TINYINT(1) UNSIGNED  NOT NULL DEFAULT 0,
			vip_manual          TINYINT(1) UNSIGNED  NOT NULL DEFAULT 0           COMMENT 'Manual VIP override',
			is_blocked          TINYINT(1) UNSIGNED  NOT NULL DEFAULT 0,
			is_subscribed       TINYINT(1) UNSIGNED  NOT NULL DEFAULT 1,
			marketing_consent   TINYINT(1) UNSIGNED  NOT NULL DEFAULT 0,
			communication_prefs LONGTEXT                      DEFAULT NULL        COMMENT 'JSON: preferred channel, times, etc.',
			tags                LONGTEXT                      DEFAULT NULL        COMMENT 'JSON array of tag IDs',
			custom_fields       LONGTEXT                      DEFAULT NULL        COMMENT 'JSON key=>value custom fields',
			total_orders        INT(10) UNSIGNED     NOT NULL DEFAULT 0,
			completed_orders    INT(10) UNSIGNED     NOT NULL DEFAULT 0,
			cancelled_orders    INT(10) UNSIGNED     NOT NULL DEFAULT 0,
			refunded_orders     INT(10) UNSIGNED     NOT NULL DEFAULT 0,
			pending_orders      INT(10) UNSIGNED     NOT NULL DEFAULT 0,
			lifetime_value      DECIMAL(15,4)        NOT NULL DEFAULT 0,
			avg_order_value     DECIMAL(15,4)        NOT NULL DEFAULT 0,
			health_score        DECIMAL(5,2)         NOT NULL DEFAULT 0,
			rfm_recency         TINYINT(1) UNSIGNED  NOT NULL DEFAULT 0,
			rfm_frequency       TINYINT(1) UNSIGNED  NOT NULL DEFAULT 0,
			rfm_monetary        DECIMAL(15,4)        NOT NULL DEFAULT 0,
			first_order_at      DATETIME                      DEFAULT NULL,
			last_order_at       DATETIME                      DEFAULT NULL,
			last_message_at     DATETIME                      DEFAULT NULL,
			last_campaign_at    DATETIME                      DEFAULT NULL,
			last_coupon_at      DATETIME                      DEFAULT NULL,
			notes_count         INT(10) UNSIGNED     NOT NULL DEFAULT 0,
			tasks_count         INT(10) UNSIGNED     NOT NULL DEFAULT 0,
			source              VARCHAR(50)          NOT NULL DEFAULT 'manual',
			created_at          DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at          DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY         (id),
			KEY idx_phone       (phone),
			KEY idx_wa_phone    (whatsapp_phone),
			KEY idx_email       (email(100)),
			KEY idx_lifecycle   (lifecycle),
			KEY idx_is_vip      (is_vip),
			KEY idx_is_blocked  (is_blocked),
			KEY idx_ltv         (lifetime_value),
			KEY idx_health      (health_score),
			KEY idx_wp_user     (wp_user_id),
			KEY idx_wc_customer (wc_customer_id),
			KEY idx_created_at  (created_at),
			KEY idx_last_order  (last_order_at)
		) ENGINE=InnoDB {$charset_collate};";

		// Customer notes
		$customer_notes = $wpdb->prefix . 'tsh_wa_customer_notes';
		$sql[] = "CREATE TABLE {$customer_notes} (
			id              BIGINT(20) UNSIGNED  NOT NULL AUTO_INCREMENT,
			customer_id     BIGINT(20) UNSIGNED  NOT NULL,
			user_id         BIGINT(20) UNSIGNED  NOT NULL DEFAULT 0 COMMENT 'WP user who wrote the note',
			content         LONGTEXT             NOT NULL,
			is_pinned       TINYINT(1) UNSIGNED  NOT NULL DEFAULT 0,
			is_private      TINYINT(1) UNSIGNED  NOT NULL DEFAULT 1,
			attachment_url  VARCHAR(500)                  DEFAULT NULL,
			created_at      DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at      DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY     (id),
			KEY idx_customer_id (customer_id),
			KEY idx_is_pinned   (is_pinned)
		) ENGINE=InnoDB {$charset_collate};";

		// Customer tasks
		$customer_tasks = $wpdb->prefix . 'tsh_wa_customer_tasks';
		$sql[] = "CREATE TABLE {$customer_tasks} (
			id                  BIGINT(20) UNSIGNED  NOT NULL AUTO_INCREMENT,
			customer_id         BIGINT(20) UNSIGNED  NOT NULL,
			assigned_to         BIGINT(20) UNSIGNED  NOT NULL DEFAULT 0 COMMENT 'WP user ID',
			title               VARCHAR(500)         NOT NULL DEFAULT '',
			description         TEXT                          DEFAULT NULL,
			status              VARCHAR(30)          NOT NULL DEFAULT 'pending' COMMENT 'pending|in_progress|completed|cancelled',
			priority            VARCHAR(20)          NOT NULL DEFAULT 'medium'  COMMENT 'low|medium|high|urgent',
			due_at              DATETIME                      DEFAULT NULL,
			completed_at        DATETIME                      DEFAULT NULL,
			is_recurring        TINYINT(1) UNSIGNED  NOT NULL DEFAULT 0,
			recurrence_config   LONGTEXT                      DEFAULT NULL COMMENT 'JSON: interval, day_of_week, etc.',
			reminder_sent       TINYINT(1) UNSIGNED  NOT NULL DEFAULT 0,
			created_by          BIGINT(20) UNSIGNED  NOT NULL DEFAULT 0,
			created_at          DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at          DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY         (id),
			KEY idx_customer_id (customer_id),
			KEY idx_assigned_to (assigned_to),
			KEY idx_status      (status),
			KEY idx_due_at      (due_at),
			KEY idx_priority    (priority)
		) ENGINE=InnoDB {$charset_collate};";

		// Customer activity log
		$customer_activity = $wpdb->prefix . 'tsh_wa_customer_activity';
		$sql[] = "CREATE TABLE {$customer_activity} (
			id               BIGINT(20) UNSIGNED  NOT NULL AUTO_INCREMENT,
			customer_id      BIGINT(20) UNSIGNED  NOT NULL,
			type             VARCHAR(50)          NOT NULL DEFAULT 'note',
			subject          VARCHAR(200)         NOT NULL DEFAULT '',
			description      TEXT                          DEFAULT NULL,
			data             LONGTEXT                      DEFAULT NULL COMMENT 'JSON extra context',
			reference_type   VARCHAR(50)                   DEFAULT NULL COMMENT 'order|message|campaign|note|task',
			reference_id     BIGINT(20) UNSIGNED           DEFAULT NULL,
			created_by       BIGINT(20) UNSIGNED  NOT NULL DEFAULT 0 COMMENT '0 = system',
			created_at       DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY      (id),
			KEY idx_customer_id  (customer_id),
			KEY idx_type         (type),
			KEY idx_created_at   (created_at),
			KEY idx_reference    (reference_type, reference_id)
		) ENGINE=InnoDB {$charset_collate};";

		// Dynamic segments
		$customer_segments = $wpdb->prefix . 'tsh_wa_customer_segments';
		$sql[] = "CREATE TABLE {$customer_segments} (
			id             BIGINT(20) UNSIGNED  NOT NULL AUTO_INCREMENT,
			name           VARCHAR(200)         NOT NULL DEFAULT '',
			description    TEXT                          DEFAULT NULL,
			rules          LONGTEXT             NOT NULL,
			match_count    BIGINT(20) UNSIGNED  NOT NULL DEFAULT 0,
			auto_refresh   TINYINT(1) UNSIGNED  NOT NULL DEFAULT 1,
			last_computed  DATETIME                      DEFAULT NULL,
			created_by     BIGINT(20) UNSIGNED  NOT NULL DEFAULT 0,
			created_at     DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at     DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY    (id),
			KEY idx_name   (name(100))
		) ENGINE=InnoDB {$charset_collate};";

		// Tag definitions
		$customer_tags = $wpdb->prefix . 'tsh_wa_customer_tags';
		$sql[] = "CREATE TABLE {$customer_tags} (
			id           BIGINT(20) UNSIGNED  NOT NULL AUTO_INCREMENT,
			name         VARCHAR(100)         NOT NULL DEFAULT '',
			color        VARCHAR(20)          NOT NULL DEFAULT '#6b7280',
			type         VARCHAR(30)          NOT NULL DEFAULT 'manual' COMMENT 'manual|auto|workflow|campaign',
			description  TEXT                          DEFAULT NULL,
			usage_count  INT(10) UNSIGNED     NOT NULL DEFAULT 0,
			created_at   DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_name (name),
			KEY idx_type (type)
		) ENGINE=InnoDB {$charset_collate};";

		// Score snapshots
		$customer_scores = $wpdb->prefix . 'tsh_wa_customer_scores';
		$sql[] = "CREATE TABLE {$customer_scores} (
			id                BIGINT(20) UNSIGNED  NOT NULL AUTO_INCREMENT,
			customer_id       BIGINT(20) UNSIGNED  NOT NULL,
			engagement_score  DECIMAL(5,2)         NOT NULL DEFAULT 0,
			purchase_score    DECIMAL(5,2)         NOT NULL DEFAULT 0,
			support_score     DECIMAL(5,2)         NOT NULL DEFAULT 0,
			marketing_score   DECIMAL(5,2)         NOT NULL DEFAULT 0,
			health_score      DECIMAL(5,2)         NOT NULL DEFAULT 0,
			rfm_score         VARCHAR(10)          NOT NULL DEFAULT '111',
			computed_at       DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY       (id),
			KEY idx_customer_id (customer_id),
			KEY idx_health      (health_score),
			KEY idx_computed_at (computed_at)
		) ENGINE=InnoDB {$charset_collate};";

		// Custom field definitions
		$customer_custom_fields = $wpdb->prefix . 'tsh_wa_customer_custom_fields';
		$sql[] = "CREATE TABLE {$customer_custom_fields} (
			id            BIGINT(20) UNSIGNED  NOT NULL AUTO_INCREMENT,
			field_key     VARCHAR(100)         NOT NULL DEFAULT '',
			field_label   VARCHAR(200)         NOT NULL DEFAULT '',
			field_type    VARCHAR(30)          NOT NULL DEFAULT 'text' COMMENT 'text|textarea|number|checkbox|date|select|radio|url|email',
			options       LONGTEXT                      DEFAULT NULL COMMENT 'JSON: options for select/radio fields',
			is_required   TINYINT(1) UNSIGNED  NOT NULL DEFAULT 0,
			sort_order    INT(10)              NOT NULL DEFAULT 0,
			created_at    DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY   (id),
			UNIQUE KEY    uk_field_key (field_key)
		) ENGINE=InnoDB {$charset_collate};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}
}
