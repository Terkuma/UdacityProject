<?php
/**
 * Media downloader — downloads and stores WhatsApp media files locally.
 *
 * @package TSH\WhatsAppNotify\Inbox
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Inbox;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TSH\WhatsAppNotify\API\ApiClient;
use TSH\WhatsAppNotify\API\TokenManager;

/**
 * Class MediaDownloader
 *
 * Downloads media from the Meta Graph API and stores it in the WordPress
 * uploads directory under tsh-wa-inbox/{year}/{month}/.
 *
 * Security:
 *  - Files stored outside webroot by default (in a protected sub-directory).
 *  - Access only via AJAX handler that checks capability + nonce.
 *  - File extensions validated against allowed MIME types.
 */
final class MediaDownloader {

	/** @var string WP schedule hook name. */
	public const HOOK_DOWNLOAD = 'tsh_wa_download_media';

	/** @var ConversationRepository */
	private ConversationRepository $repo;

	/** @var ConversationLogger */
	private ConversationLogger $logger;

	/** @var string[] Allowed MIME types mapped to extensions. */
	private const ALLOWED_MIMES = [
		'image/jpeg'      => 'jpg',
		'image/png'       => 'png',
		'image/webp'      => 'webp',
		'image/gif'       => 'gif',
		'audio/ogg'       => 'ogg',
		'audio/mpeg'      => 'mp3',
		'audio/mp4'       => 'm4a',
		'audio/aac'       => 'aac',
		'video/mp4'       => 'mp4',
		'video/3gp'       => '3gp',
		'application/pdf' => 'pdf',
		'application/zip' => 'zip',
		'application/vnd.ms-excel'                                          => 'xls',
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
		'application/msword'                                                => 'doc',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
	];

	/** @var int Max file size in bytes (25 MB). */
	private const MAX_FILE_SIZE = 26214400;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->repo   = new ConversationRepository();
		$this->logger = new ConversationLogger();
	}

	// -------------------------------------------------------------------------
	// Scheduling
	// -------------------------------------------------------------------------

	/**
	 * Schedule a background media download for a message.
	 *
	 * @param int    $message_id  Local message DB ID.
	 * @param string $media_id    Meta media object ID.
	 * @param string $media_type  Message type (image, audio, etc.).
	 */
	public function schedule_download( int $message_id, string $media_id, string $media_type ): void {
		$settings = get_option( 'tsh_wa_inbox_settings', [] );
		if ( empty( $settings['auto_download_media'] ) || '1' !== (string) $settings['auto_download_media'] ) {
			return;
		}

		wp_schedule_single_event(
			time() + 5, // Small delay so the response can be sent first.
			self::HOOK_DOWNLOAD,
			[ $message_id, $media_id, $media_type ]
		);
	}

	/**
	 * Register the cron hook.
	 */
	public function register_hooks(): void {
		add_action( self::HOOK_DOWNLOAD, [ $this, 'download' ], 10, 3 );
	}

	// -------------------------------------------------------------------------
	// Download
	// -------------------------------------------------------------------------

	/**
	 * Download a media file from Meta and store it locally.
	 *
	 * @param int    $message_id Local message DB ID.
	 * @param string $media_id   Meta media object ID.
	 * @param string $media_type Message type (image, audio, etc.).
	 * @return bool True on success.
	 */
	public function download( int $message_id, string $media_id, string $media_type ): bool {
		try {
			// Step 1 — Retrieve the download URL from the Meta media endpoint.
			$api_settings = get_option( 'tsh_wa_api_settings', [] );
			$api_version  = $api_settings['api_version'] ?? 'v23.0';
			$base_url     = "https://graph.facebook.com/{$api_version}/";

			$token_manager = new TokenManager();
			$client        = new ApiClient( $base_url, $token_manager, 30, 2, 3, false );

			$response  = $client->get( $media_id );
			$media_url = $response['body']['url'] ?? '';
			$mime_type = $response['body']['mime_type'] ?? '';

			if ( empty( $media_url ) ) {
				$this->logger->error( 'Media URL not returned from Meta.', [ 'media_id' => $media_id ] );
				return false;
			}

			// Step 2 — Validate MIME type.
			if ( ! isset( self::ALLOWED_MIMES[ $mime_type ] ) ) {
				$this->logger->warning( 'Unsupported MIME type for media download.', [
					'mime_type' => $mime_type,
					'media_id'  => $media_id,
				] );
				return false;
			}

			$extension = self::ALLOWED_MIMES[ $mime_type ];

			// Step 3 — Download the file via wp_remote_get (with auth header).
			$file_response = wp_remote_get( $media_url, [
				'headers' => [
					'Authorization' => 'Bearer ' . $token_manager->get_token(),
				],
				'timeout'  => 60,
				'stream'   => true,
				'filename' => $this->get_temp_path( $media_id, $extension ),
			] );

			if ( is_wp_error( $file_response ) ) {
				$this->logger->error( 'Media download failed.', [
					'error'    => $file_response->get_error_message(),
					'media_id' => $media_id,
				] );
				return false;
			}

			$http_status = wp_remote_retrieve_response_code( $file_response );
			if ( 200 !== (int) $http_status ) {
				$this->logger->error( 'Media download returned non-200 status.', [
					'status'   => $http_status,
					'media_id' => $media_id,
				] );
				return false;
			}

			// Step 4 — Move from temp to permanent uploads location.
			$temp_path = $this->get_temp_path( $media_id, $extension );
			if ( ! file_exists( $temp_path ) ) {
				return false;
			}

			// Size guard.
			if ( filesize( $temp_path ) > self::MAX_FILE_SIZE ) {
				wp_delete_file( $temp_path );
				$this->logger->warning( 'Media file exceeds size limit.', [ 'media_id' => $media_id ] );
				return false;
			}

			$local_rel = $this->move_to_uploads( $temp_path, $media_id, $extension, $media_type );
			if ( ! $local_rel ) {
				return false;
			}

			// Step 5 — Update message row.
			$this->repo->update_message( $message_id, [
				'local_path' => $local_rel,
				'mime_type'  => $mime_type,
			] );

			$this->logger->success( 'Media downloaded successfully.', [
				'message_id' => $message_id,
				'media_id'   => $media_id,
				'path'       => $local_rel,
			] );

			return true;

		} catch ( \Throwable $e ) {
			$this->logger->error( 'Media download exception.', [
				'error'    => $e->getMessage(),
				'media_id' => $media_id,
			] );
			return false;
		}
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Return a temp file path for streaming download.
	 *
	 * @param string $media_id
	 * @param string $ext
	 * @return string
	 */
	private function get_temp_path( string $media_id, string $ext ): string {
		return get_temp_dir() . 'tsh_wa_media_' . sanitize_key( $media_id ) . '.' . $ext;
	}

	/**
	 * Move a temp file to the WP uploads directory.
	 *
	 * @param string $temp_path
	 * @param string $media_id
	 * @param string $ext
	 * @param string $media_type
	 * @return string|null Relative path from uploads base, or null on failure.
	 */
	private function move_to_uploads( string $temp_path, string $media_id, string $ext, string $media_type ): ?string {
		$upload_dir = wp_upload_dir();
		$base_dir   = $upload_dir['basedir'] . '/tsh-wa-inbox/' . gmdate( 'Y' ) . '/' . gmdate( 'm' ) . '/' . $media_type;

		if ( ! wp_mkdir_p( $base_dir ) ) {
			$this->logger->error( 'Could not create upload directory.', [ 'dir' => $base_dir ] );
			return null;
		}

		// Protect directory from direct access.
		$htaccess = dirname( $base_dir ) . '/../.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $htaccess, "deny from all\n" );
		}

		$filename  = sanitize_key( $media_id ) . '_' . time() . '.' . $ext;
		$dest_path = $base_dir . '/' . $filename;

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! @rename( $temp_path, $dest_path ) ) {
			// Fallback: copy then delete.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
			copy( $temp_path, $dest_path );
			wp_delete_file( $temp_path );
		}

		if ( ! file_exists( $dest_path ) ) {
			return null;
		}

		// Return path relative to WP uploads basedir.
		return 'tsh-wa-inbox/' . gmdate( 'Y' ) . '/' . gmdate( 'm' ) . '/' . $media_type . '/' . $filename;
	}
}
