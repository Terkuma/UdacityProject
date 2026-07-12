<?php
/**
 * Meta WhatsApp template synchronisation engine.
 *
 * @package TSH\WhatsAppNotify\Templates
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Templates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TSH\WhatsAppNotify\API\ApiClient;
use TSH\WhatsAppNotify\API\TokenManager;
use TSH\WhatsAppNotify\Helpers\Helpers;

/**
 * Class TemplateSync
 *
 * Fetches approved and pending templates from the Meta Graph API and
 * persists them to tsh_wa_meta_templates via TemplateRepository.
 *
 * Sync types:
 *   manual      — triggered by admin button; full fetch + upsert.
 *   incremental — fetch only recently modified templates.
 *   full        — truncate local table + re-fetch everything.
 *   background  — schedule an immediate manual sync via WP-Cron.
 *   scheduled   — called by the hourly cron hook.
 */
final class TemplateSync {

	/** @var string Option key for last-sync metadata. */
	public const OPTION_LAST_SYNC   = 'tsh_wa_template_last_sync';
	public const OPTION_SYNC_STATUS = 'tsh_wa_template_sync_status';
	public const OPTION_LAST_ERROR  = 'tsh_wa_template_sync_last_error';

	/** @var string Cron hook fired when a background sync is requested. */
	public const HOOK_BACKGROUND_SYNC = 'tsh_wa_background_template_sync';

	/** @var int Maximum templates to fetch per API page. */
	private const PAGE_LIMIT = 100;

	/** @var TemplateRepository */
	private TemplateRepository $repository;

	/** @var TemplateCache */
	private TemplateCache $cache;

	/** @var TemplateLogger */
	private TemplateLogger $logger;

	/** @var TemplateValidator */
	private TemplateValidator $validator;

	public function __construct() {
		$this->repository = new TemplateRepository();
		$this->cache      = new TemplateCache();
		$this->logger     = new TemplateLogger();
		$this->validator  = new TemplateValidator();
	}

	// -------------------------------------------------------------------------
	// Public sync methods
	// -------------------------------------------------------------------------

	/**
	 * Manual sync — fetch all templates from Meta and upsert locally.
	 *
	 * @return array{ success: bool, stats: array<string, int>, message: string }
	 */
	public function manual_sync(): array {
		return $this->run_sync( 'manual', false );
	}

	/**
	 * Incremental sync — fetch only templates modified since last sync.
	 *
	 * @return array{ success: bool, stats: array<string, int>, message: string }
	 */
	public function incremental_sync(): array {
		return $this->run_sync( 'incremental', false, true );
	}

	/**
	 * Full sync — truncate local table and re-fetch everything from Meta.
	 *
	 * @return array{ success: bool, stats: array<string, int>, message: string }
	 */
	public function full_sync(): array {
		return $this->run_sync( 'full', true );
	}

	/**
	 * Scheduled sync — called by the hourly cron hook.
	 *
	 * @return array{ success: bool, stats: array<string, int>, message: string }
	 */
	public function scheduled_sync(): array {
		$settings = get_option( 'tsh_wa_sync_settings', [] );
		if ( empty( $settings['auto_sync'] ) || '1' !== (string) $settings['auto_sync'] ) {
			return [ 'success' => true, 'stats' => [], 'message' => 'Auto-sync disabled.' ];
		}
		return $this->run_sync( 'scheduled', false );
	}

	/**
	 * Dispatch a background sync via an immediate WP-Cron event.
	 * Returns immediately; the actual sync runs on the next cron tick.
	 */
	public function background_sync(): void {
		if ( ! wp_next_scheduled( self::HOOK_BACKGROUND_SYNC ) ) {
			wp_schedule_single_event( time(), self::HOOK_BACKGROUND_SYNC );
		}
	}

	/**
	 * Sync a single template by Meta template ID.
	 *
	 * @param string $meta_template_id
	 * @return array{ success: bool, message: string }
	 */
	public function sync_single( string $meta_template_id ): array {
		if ( ! Helpers::is_plugin_ready() ) {
			return [ 'success' => false, 'message' => __( 'API not configured.', 'tsh-whatsapp-notify' ) ];
		}

		$client = $this->build_client();
		$creds  = ( new TokenManager() )->get_credentials();
		$waba_id = $creds['business_account_id'] ?? '';

		if ( ! $waba_id ) {
			return [ 'success' => false, 'message' => __( 'Business Account ID not set.', 'tsh-whatsapp-notify' ) ];
		}

		$response = $client->get(
			$waba_id . '/message_templates',
			[ 'name' => $meta_template_id, 'fields' => $this->get_fields_param() ]
		);

		if ( ! $response['success'] ) {
			return [ 'success' => false, 'message' => $response['error_message'] ?? __( 'API error.', 'tsh-whatsapp-notify' ) ];
		}

		$templates = $response['data']['data'] ?? [];
		if ( empty( $templates ) ) {
			return [ 'success' => false, 'message' => __( 'Template not found on Meta.', 'tsh-whatsapp-notify' ) ];
		}

		$this->upsert_template( $templates[0] );
		$this->cache->bust_template( 0 ); // bust all list caches.
		$this->cache->flush_lists();

		return [ 'success' => true, 'message' => __( 'Template synced.', 'tsh-whatsapp-notify' ) ];
	}

	// -------------------------------------------------------------------------
	// Status helpers
	// -------------------------------------------------------------------------

	/**
	 * Return the timestamp of the last successful sync.
	 *
	 * @return string|null MySQL datetime or null.
	 */
	public function get_last_sync_time(): ?string {
		$meta = get_option( self::OPTION_LAST_SYNC, null );
		return is_string( $meta ) ? $meta : null;
	}

	/**
	 * Return sync status metadata.
	 *
	 * @return array<string, mixed>
	 */
	public function get_sync_status(): array {
		return [
			'last_sync'    => $this->get_last_sync_time(),
			'status'       => get_option( self::OPTION_SYNC_STATUS, 'never' ),
			'last_error'   => get_option( self::OPTION_LAST_ERROR, '' ),
			'total_local'  => $this->repository->count(),
			'total_approved' => $this->repository->count( [ 'status' => TemplateRepository::STATUS_APPROVED ] ),
		];
	}

	// -------------------------------------------------------------------------
	// Core sync runner
	// -------------------------------------------------------------------------

	/**
	 * @param string $type          Sync type label.
	 * @param bool   $truncate_first Whether to clear local table before fetching.
	 * @param bool   $incremental   Only fetch since last sync timestamp.
	 * @return array{ success: bool, stats: array<string, int>, message: string }
	 */
	private function run_sync( string $type, bool $truncate_first = false, bool $incremental = false ): array {
		if ( ! Helpers::is_plugin_ready() ) {
			return [
				'success' => false,
				'stats'   => [],
				'message' => __( 'WhatsApp API is not configured. Please add credentials in Settings → WhatsApp API.', 'tsh-whatsapp-notify' ),
			];
		}

		$this->logger->sync_started( $type );
		update_option( self::OPTION_SYNC_STATUS, 'running', false );

		$stats = [
			'added'     => 0,
			'updated'   => 0,
			'deleted'   => 0,
			'unchanged' => 0,
			'errors'    => 0,
		];

		try {
			$client = $this->build_client();

			if ( $truncate_first ) {
				$this->repository->truncate();
			}

			$existing_meta_ids = $truncate_first ? [] : $this->repository->get_all_meta_ids();

			$fetched_meta_ids = [];
			$after            = null;

			$creds   = ( new TokenManager() )->get_credentials();
			$waba_id = $creds['business_account_id'] ?? '';

			if ( ! $waba_id ) {
				throw new \RuntimeException( __( 'Business Account ID is not configured.', 'tsh-whatsapp-notify' ) );
			}

			$settings    = get_option( 'tsh_wa_sync_settings', [] );
			$max_templates = (int) ( $settings['max_templates'] ?? 500 );
			$total_fetched = 0;

			// Paginate through all templates.
			do {
				$params = [
					'fields' => $this->get_fields_param(),
					'limit'  => self::PAGE_LIMIT,
				];

				if ( $after ) {
					$params['after'] = $after;
				}

				if ( $incremental ) {
					$last_sync = $this->get_last_sync_time();
					if ( $last_sync ) {
						$params['after_timestamp'] = strtotime( $last_sync );
					}
				}

				$response = $client->get( $waba_id . '/message_templates', $params );

				if ( ! $response['success'] ) {
					throw new \RuntimeException(
						$response['error_message'] ?? __( 'Meta API returned an error.', 'tsh-whatsapp-notify' )
					);
				}

				$page_templates = $response['data']['data'] ?? [];

				foreach ( $page_templates as $tpl ) {
					if ( $total_fetched >= $max_templates ) {
						break 2;
					}

					$meta_id            = $tpl['id'] ?? null;
					$fetched_meta_ids[] = $meta_id;

					$result = $this->upsert_template( $tpl );
					$stats[ $result ]++;
					$total_fetched++;
				}

				// Pagination cursor.
				$after = $response['data']['paging']['cursors']['after'] ?? null;

			} while ( $after && count( $page_templates ) === self::PAGE_LIMIT );

			// Detect locally stored templates that no longer exist on Meta.
			if ( ! $truncate_first && ! $incremental ) {
				$removed_ids = array_diff( $existing_meta_ids, $fetched_meta_ids );
				foreach ( $removed_ids as $gone_id ) {
					$this->repository->mark_deleted( $gone_id );
					$stats['deleted']++;
				}
			}

			// Flush cache after sync.
			$this->cache->flush();

			// Persist sync metadata.
			update_option( self::OPTION_LAST_SYNC, current_time( 'mysql' ), false );
			update_option( self::OPTION_SYNC_STATUS, 'success', false );
			delete_option( self::OPTION_LAST_ERROR );

			$this->logger->sync_completed( $type, $stats );

			return [
				'success' => true,
				'stats'   => $stats,
				'message' => sprintf(
					/* translators: %1$d: added, %2$d: updated, %3$d: deleted */
					__( 'Sync complete. Added: %1$d | Updated: %2$d | Deleted: %3$d', 'tsh-whatsapp-notify' ),
					$stats['added'],
					$stats['updated'],
					$stats['deleted']
				),
			];

		} catch ( \Throwable $e ) {
			$error_message = $e->getMessage();
			update_option( self::OPTION_SYNC_STATUS, 'error', false );
			update_option( self::OPTION_LAST_ERROR, $error_message, false );
			$this->logger->sync_failed( $type, $error_message );

			return [
				'success' => false,
				'stats'   => $stats,
				'message' => $error_message,
			];
		}
	}

	// -------------------------------------------------------------------------
	// Template upsert
	// -------------------------------------------------------------------------

	/**
	 * Parse a single Meta API template object and upsert it locally.
	 *
	 * @param array<string, mixed> $tpl Raw template from Meta API response.
	 * @return string 'added'|'updated'|'unchanged'|'errors'
	 */
	private function upsert_template( array $tpl ): string {
		$meta_id = sanitize_text_field( $tpl['id'] ?? '' );
		if ( ! $meta_id ) {
			return 'errors';
		}

		$existing = $this->repository->find_by_meta_id( $meta_id );

		// Parse components.
		$components = $tpl['components'] ?? [];
		$body       = '';
		$header_type    = '';
		$header_content = null;
		$footer         = '';
		$buttons        = [];
		$variables      = [];

		foreach ( $components as $component ) {
			$comp_type = strtoupper( $component['type'] ?? '' );

			switch ( $comp_type ) {
				case 'BODY':
					$body = $component['text'] ?? '';
					// Extract variable example values.
					if ( ! empty( $component['example']['body_text'] ) ) {
						$variables = $component['example']['body_text'][0] ?? [];
					}
					break;

				case 'HEADER':
					$header_type    = strtoupper( $component['format'] ?? 'TEXT' );
					$header_content = $component;
					break;

				case 'FOOTER':
					$footer = $component['text'] ?? '';
					break;

				case 'BUTTONS':
					$buttons = $component['buttons'] ?? [];
					break;
			}
		}

		$data = [
			'meta_template_id' => $meta_id,
			'template_name'    => sanitize_text_field( $tpl['name']     ?? '' ),
			'category'         => strtoupper( sanitize_text_field( $tpl['category'] ?? 'UTILITY' ) ),
			'language'         => sanitize_text_field( $tpl['language'] ?? 'en' ),
			'status'           => strtoupper( sanitize_text_field( $tpl['status']   ?? 'PENDING' ) ),
			'quality_score'    => strtoupper( sanitize_text_field( $tpl['quality_score'] ?? 'UNKNOWN' ) ),
			'namespace'        => sanitize_text_field( $tpl['message_template_namespace'] ?? '' ),
			'header_type'      => $header_type,
			'header_content'   => $header_content ? wp_json_encode( $header_content ) : null,
			'body'             => wp_kses_post( $body ),
			'footer'           => sanitize_text_field( $footer ),
			'buttons'          => $buttons ? wp_json_encode( $buttons ) : null,
			'variables'        => $variables ? wp_json_encode( $variables ) : null,
			'example_values'   => ! empty( $tpl['components'] ) ? wp_json_encode( $tpl['components'] ) : null,
			'raw_data'         => wp_json_encode( $tpl ),
			'last_synced'      => current_time( 'mysql' ),
		];

		if ( $existing ) {
			// Check if anything meaningful changed.
			if (
				$existing->status        === $data['status'] &&
				$existing->quality_score === $data['quality_score'] &&
				$existing->body          === $data['body']
			) {
				// Just update last_synced.
				$this->repository->update( (int) $existing->id, [ 'last_synced' => current_time( 'mysql' ) ] );
				return 'unchanged';
			}
			$this->repository->update( (int) $existing->id, $data );
			return 'updated';
		}

		$this->repository->insert( $data );
		return 'added';
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Build an ApiClient instance from current settings.
	 */
	private function build_client(): ApiClient {
		$token_manager = new TokenManager();
		$settings      = get_option( 'tsh_wa_api_settings', [] );
		$api_version   = sanitize_text_field( $settings['api_version'] ?? 'v23.0' );
		$timeout       = absint( $settings['request_timeout']  ?? 30 );
		$retries       = absint( $settings['retry_attempts']   ?? 3 );
		$delay         = absint( $settings['retry_delay']      ?? 5 );
		$debug         = Helpers::is_debug_mode();

		return new ApiClient(
			"https://graph.facebook.com/{$api_version}/",
			$token_manager,
			$timeout,
			$retries,
			$delay,
			$debug
		);
	}

	/**
	 * Return the Meta API fields parameter string.
	 */
	private function get_fields_param(): string {
		return 'id,name,category,language,status,quality_score,message_template_namespace,components';
	}
}
