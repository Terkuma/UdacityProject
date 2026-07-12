<?php
/**
 * Message renderer — converts a raw message row into a UI-ready array.
 *
 * @package TSH\WhatsAppNotify\Inbox
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Inbox;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MessageRenderer
 *
 * Takes a raw tsh_wa_messages database row and returns a structured,
 * display-safe array that the JavaScript chat UI can render directly.
 */
final class MessageRenderer {

	/** @var array<string, string> Status icon map. */
	private const STATUS_ICONS = [
		'received'  => '',       // Incoming — no icon needed.
		'queued'    => '🕐',
		'sending'   => '🕐',
		'sent'      => '✓',
		'delivered' => '✓✓',
		'read'      => '✓✓',    // JS colours this blue.
		'failed'    => '⚠',
		'retrying'  => '🔄',
	];

	/**
	 * Render a single message row for the chat UI.
	 *
	 * @param array<string, mixed> $row Raw DB row.
	 * @return array<string, mixed>
	 */
	public function render( array $row ): array {
		$type      = sanitize_key( $row['message_type'] ?? 'text' );
		$direction = sanitize_key( $row['direction']    ?? 'incoming' );
		$status    = sanitize_key( $row['status']       ?? 'received' );
		$is_note   = (bool) ( $row['is_note']           ?? false );

		$timestamp_mysql = $row['timestamp'] ?? '';
		$timestamp_unix  = $timestamp_mysql ? strtotime( $timestamp_mysql . ' UTC' ) : 0;

		return [
			'id'            => (int) $row['id'],
			'meta_id'       => esc_html( $row['meta_message_id'] ?? '' ),
			'direction'     => $direction,
			'status'        => $status,
			'status_icon'   => self::STATUS_ICONS[ $status ] ?? '',
			'type'          => $type,
			'is_note'       => $is_note,
			'content'       => $this->render_content( $row, $type ),
			'timestamp'     => esc_html( $timestamp_mysql ),
			'timestamp_unix'=> $timestamp_unix,
			'time_human'    => $timestamp_unix
				? date_i18n( get_option( 'time_format' ), $timestamp_unix )
				: '',
			'date_human'    => $timestamp_unix
				? date_i18n( get_option( 'date_format' ), $timestamp_unix )
				: '',
			'has_media'     => ! empty( $row['media_id'] ) || ! empty( $row['local_path'] ),
			'media_url'     => $this->resolve_media_url( $row ),
			'local_path'    => esc_html( $row['local_path'] ?? '' ),
			'mime_type'     => esc_html( $row['mime_type'] ?? '' ),
		];
	}

	// -------------------------------------------------------------------------
	// Content rendering
	// -------------------------------------------------------------------------

	/**
	 * Build a display-safe content string for the given message type.
	 *
	 * @param array<string, mixed> $row
	 * @param string               $type
	 * @return string
	 */
	private function render_content( array $row, string $type ): string {
		$raw = (string) ( $row['content'] ?? '' );

		switch ( $type ) {
			case 'text':
			case 'interactive':
			case 'button':
			case 'location':
			case 'reaction':
				return nl2br( esc_html( $raw ) );

			case 'image':
				return $raw ? nl2br( esc_html( $raw ) ) : esc_html__( '📷 Photo', 'tsh-whatsapp-notify' );

			case 'audio':
				return esc_html__( '🎵 Audio message', 'tsh-whatsapp-notify' );

			case 'video':
				return $raw ? nl2br( esc_html( $raw ) ) : esc_html__( '🎬 Video', 'tsh-whatsapp-notify' );

			case 'document':
				return $raw ? nl2br( esc_html( $raw ) ) : esc_html__( '📄 Document', 'tsh-whatsapp-notify' );

			case 'sticker':
				return esc_html__( '🎉 Sticker', 'tsh-whatsapp-notify' );

			case 'note':
				return nl2br( esc_html( $raw ) );

			default:
				return nl2br( esc_html( $raw ) );
		}
	}

	/**
	 * Resolve a public-facing media URL for a message.
	 *
	 * If the media has been downloaded locally, return the WP uploads URL.
	 * Otherwise fall back to an empty string (JS will show a "download" button).
	 *
	 * @param array<string, mixed> $row
	 * @return string
	 */
	private function resolve_media_url( array $row ): string {
		if ( ! empty( $row['local_path'] ) ) {
			$upload_dir = wp_upload_dir();
			// local_path is stored relative to WP uploads base dir.
			return esc_url( $upload_dir['baseurl'] . '/' . ltrim( $row['local_path'], '/' ) );
		}
		return '';
	}
}
