<?php
/**
 * API exception hierarchy.
 *
 * @package TSH\WhatsAppNotify\API
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ApiException
 *
 * Base exception for all WhatsApp API errors.
 * Carries structured diagnostic data so callers can build
 * human-readable and developer-facing error displays.
 */
class ApiException extends \RuntimeException {

	/** @var int HTTP status code returned by the provider (0 if no request). */
	private int $http_status;

	/** @var string Provider-specific error code (e.g. Meta error code). */
	private string $meta_error_code;

	/** @var string Provider error message text (never logged with the token). */
	private string $meta_error_message;

	/** @var bool True when the caller should schedule a retry. */
	private bool $retry_recommended;

	/** @var string Technical detail string safe for developer logs. */
	private string $developer_message;

	/**
	 * @param string    $message            Human-readable summary.
	 * @param int       $http_status        HTTP status (0 = no request).
	 * @param string    $meta_error_code    Provider error code.
	 * @param string    $meta_error_message Provider error message.
	 * @param bool      $retry_recommended  Whether a retry may succeed.
	 * @param string    $developer_message  Technical detail for logs.
	 * @param \Throwable|null $previous     Previous exception.
	 */
	public function __construct(
		string $message = '',
		int $http_status = 0,
		string $meta_error_code = '',
		string $meta_error_message = '',
		bool $retry_recommended = false,
		string $developer_message = '',
		?\Throwable $previous = null
	) {
		parent::__construct( $message, $http_status, $previous );

		$this->http_status        = $http_status;
		$this->meta_error_code    = $meta_error_code;
		$this->meta_error_message = $meta_error_message;
		$this->retry_recommended  = $retry_recommended;
		$this->developer_message  = $developer_message;
	}

	// -------------------------------------------------------------------------
	// Accessors
	// -------------------------------------------------------------------------

	public function get_http_status(): int {
		return $this->http_status;
	}

	public function get_meta_error_code(): string {
		return $this->meta_error_code;
	}

	public function get_meta_error_message(): string {
		return $this->meta_error_message;
	}

	public function is_retry_recommended(): bool {
		return $this->retry_recommended;
	}

	public function get_developer_message(): string {
		return $this->developer_message;
	}

	/**
	 * Return a structured array representation suitable for logs and UI.
	 * The access token MUST NEVER appear here.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return [
			'message'           => $this->getMessage(),
			'developer_message' => $this->developer_message,
			'http_status'       => $this->http_status,
			'meta_error_code'   => $this->meta_error_code,
			'meta_error_message'=> $this->meta_error_message,
			'retry_recommended' => $this->retry_recommended,
			'exception_class'   => static::class,
		];
	}
}

/**
 * Class ConfigurationException
 *
 * Thrown when required credentials or settings are missing before a request
 * is even attempted. HTTP status is always 0.
 */
class ConfigurationException extends ApiException {

	public function __construct( string $message = 'Missing or invalid plugin configuration.', ?\Throwable $previous = null ) {
		parent::__construct(
			$message,
			0,
			'CONFIGURATION_ERROR',
			$message,
			false,
			$message,
			$previous
		);
	}
}

/**
 * Class ConnectionException
 *
 * Thrown when a network-level failure occurs (DNS, timeout, TLS error).
 * A retry is almost always recommended.
 */
class ConnectionException extends ApiException {

	public function __construct(
		string $message = 'Unable to connect to the WhatsApp API.',
		string $developer_message = '',
		?\Throwable $previous = null
	) {
		parent::__construct(
			$message,
			0,
			'CONNECTION_ERROR',
			$message,
			true,
			$developer_message ?: $message,
			$previous
		);
	}
}

/**
 * Class AuthException
 *
 * Thrown on HTTP 401 / 403 — the access token is invalid or expired.
 * Retrying without rotating credentials will not help.
 */
class AuthException extends ApiException {

	public function __construct(
		string $message = 'Authentication failed. Check your Access Token.',
		string $meta_error_code = '',
		string $meta_error_message = '',
		?\Throwable $previous = null
	) {
		parent::__construct(
			$message,
			401,
			$meta_error_code ?: 'AUTH_ERROR',
			$meta_error_message ?: $message,
			false,
			$message,
			$previous
		);
	}
}

/**
 * Class RateLimitException
 *
 * Thrown on HTTP 429 — the API rate limit has been hit.
 * A retry after the retry-after interval is recommended.
 */
class RateLimitException extends ApiException {

	/** @var int Seconds to wait before retrying (from Retry-After header). */
	private int $retry_after;

	public function __construct(
		string $message = 'API rate limit exceeded. Please wait before retrying.',
		int $retry_after = 60,
		?\Throwable $previous = null
	) {
		parent::__construct(
			$message,
			429,
			'RATE_LIMIT_EXCEEDED',
			$message,
			true,
			sprintf( 'Rate limited. Retry after %d seconds.', $retry_after ),
			$previous
		);

		$this->retry_after = $retry_after;
	}

	public function get_retry_after(): int {
		return $this->retry_after;
	}
}

/**
 * Class InvalidResponseException
 *
 * Thrown when the API responds with an unexpected body — malformed JSON,
 * unexpected structure, or a non-success status without a parseable error.
 */
class InvalidResponseException extends ApiException {

	public function __construct(
		string $message = 'The API returned an unexpected response.',
		int $http_status = 0,
		string $developer_message = '',
		?\Throwable $previous = null
	) {
		parent::__construct(
			$message,
			$http_status,
			'INVALID_RESPONSE',
			$message,
			false,
			$developer_message ?: $message,
			$previous
		);
	}
}
