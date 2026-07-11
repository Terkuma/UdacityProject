<?php
/**
 * Meta WhatsApp Cloud API response parser.
 *
 * @package TSH\WhatsAppNotify\API
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ResponseParser
 *
 * Parses raw wp_remote_request() responses into a normalised structure.
 *
 * Normalised return shape
 * ───────────────────────
 *   bool   success        True when Meta returned a non-error payload.
 *   int    http_status    HTTP status code.
 *   array  data           Decoded JSON body on success.
 *   string error_code     Meta error code string (empty on success).
 *   string error_message  Meta error message (empty on success).
 *   bool   retry          True when the error class warrants a retry.
 *   int    response_size  Byte length of the raw body.
 *   string raw_body       Full JSON body (included when debug_mode is on).
 */
final class ResponseParser {

	/** @var bool Whether to include raw JSON bodies in parsed results. */
	private bool $debug_mode;

	/**
	 * @param bool $debug_mode Include raw body in parsed output when true.
	 */
	public function __construct( bool $debug_mode = false ) {
		$this->debug_mode = $debug_mode;
	}

	// -------------------------------------------------------------------------
	// Primary parse
	// -------------------------------------------------------------------------

	/**
	 * Parse a wp_remote_request() response.
	 *
	 * @param array<string, mixed>|\WP_Error $response wp_remote_request() return value.
	 * @return array<string, mixed> Normalised response.
	 */
	public function parse( $response ): array {
		// Network-level failure (WP_Error) — no HTTP response received.
		if ( is_wp_error( $response ) ) {
			return $this->network_error( $response );
		}

		$http_status  = (int) wp_remote_retrieve_response_code( $response );
		$raw_body     = (string) wp_remote_retrieve_body( $response );
		$body_size    = strlen( $raw_body );
		$decoded_body = json_decode( $raw_body, true );

		if ( ! is_array( $decoded_body ) ) {
			return $this->build_result(
				false,
				$http_status,
				[],
				'INVALID_JSON',
				__( 'The API returned a non-JSON response.', 'tsh-whatsapp-notify' ),
				false,
				$body_size,
				$raw_body
			);
		}

		// Check for a Meta error envelope.
		if ( isset( $decoded_body['error'] ) ) {
			return $this->parse_error( $decoded_body['error'], $http_status, $body_size, $raw_body );
		}

		// 2xx success.
		if ( $http_status >= 200 && $http_status < 300 ) {
			return $this->build_result(
				true,
				$http_status,
				$decoded_body,
				'',
				'',
				false,
				$body_size,
				$raw_body
			);
		}

		// Non-2xx without a Meta error envelope.
		return $this->build_result(
			false,
			$http_status,
			$decoded_body,
			(string) $http_status,
			sprintf( __( 'Unexpected HTTP status %d.', 'tsh-whatsapp-notify' ), $http_status ),
			$this->http_status_is_retryable( $http_status ),
			$body_size,
			$raw_body
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a normalised result from a WP_Error (network failure).
	 *
	 * @param \WP_Error $error
	 * @return array<string, mixed>
	 */
	private function network_error( \WP_Error $error ): array {
		return $this->build_result(
			false,
			0,
			[],
			'NETWORK_ERROR',
			$error->get_error_message(),
			true,
			0,
			''
		);
	}

	/**
	 * Parse a Meta error object from the response body.
	 *
	 * @param array<string, mixed> $error     The 'error' key from the decoded body.
	 * @param int                  $http_status
	 * @param int                  $body_size
	 * @param string               $raw_body
	 * @return array<string, mixed>
	 */
	private function parse_error( array $error, int $http_status, int $body_size, string $raw_body ): array {
		$code    = (string) ( $error['code']    ?? '' );
		$message = (string) ( $error['message'] ?? __( 'Unknown API error.', 'tsh-whatsapp-notify' ) );

		// Meta uses sub-error-codes for finer classification.
		$error_subcode = (string) ( $error['error_subcode'] ?? '' );
		if ( $error_subcode ) {
			$code .= ':' . $error_subcode;
		}

		$retry = $this->error_code_is_retryable( $error['code'] ?? 0, $http_status );

		return $this->build_result(
			false,
			$http_status,
			[],
			$code,
			$message,
			$retry,
			$body_size,
			$raw_body
		);
	}

	/**
	 * Assemble the normalised result array.
	 *
	 * @param bool   $success
	 * @param int    $http_status
	 * @param array<string, mixed> $data
	 * @param string $error_code
	 * @param string $error_message
	 * @param bool   $retry
	 * @param int    $response_size
	 * @param string $raw_body
	 * @return array<string, mixed>
	 */
	private function build_result(
		bool $success,
		int $http_status,
		array $data,
		string $error_code,
		string $error_message,
		bool $retry,
		int $response_size,
		string $raw_body
	): array {
		$result = [
			'success'       => $success,
			'http_status'   => $http_status,
			'data'          => $data,
			'error_code'    => $error_code,
			'error_message' => $error_message,
			'retry'         => $retry,
			'response_size' => $response_size,
		];

		if ( $this->debug_mode ) {
			$result['raw_body'] = $raw_body;
		}

		return $result;
	}

	/**
	 * Determine whether an HTTP status code warrants a retry.
	 *
	 * @param int $http_status
	 * @return bool
	 */
	private function http_status_is_retryable( int $http_status ): bool {
		return in_array( $http_status, [ 429, 500, 502, 503, 504 ], true );
	}

	/**
	 * Determine whether a Meta error code warrants a retry.
	 *
	 * Meta error codes that indicate transient failures:
	 *   - 1   API Unknown (generic, often transient)
	 *   - 2   API Service (temporary service unavailability)
	 *   - 130 Temporarily blocked (rate limit)
	 *   - 131 Too many requests per phone
	 *
	 * @param int|string $code
	 * @param int        $http_status
	 * @return bool
	 */
	private function error_code_is_retryable( int|string $code, int $http_status ): bool {
		$retryable_codes = [ 1, 2, 130, 131 ];
		return in_array( (int) $code, $retryable_codes, true )
			|| $this->http_status_is_retryable( $http_status );
	}
}
