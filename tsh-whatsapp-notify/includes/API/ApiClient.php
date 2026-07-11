<?php
/**
 * Core HTTP client for the WhatsApp Cloud API.
 *
 * @package TSH\WhatsAppNotify\API
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ApiClient
 *
 * Reusable, provider-agnostic HTTP client that wraps wp_remote_request().
 *
 * Features
 * ─────────
 * • GET / POST / PUT / DELETE
 * • Automatic Bearer-token authentication header
 * • Configurable timeout (seconds)
 * • Automatic retry on transient failures (429, 500–504, network errors)
 * • Exponential back-off between retries (doubles each attempt)
 * • Centralised error handling via ResponseParser
 * • Every request logged via RequestLogger
 * • Token NEVER appears in logs, error messages, or transients
 */
final class ApiClient {

	/** @var string Base API URL including trailing slash. */
	private string $base_url;

	/** @var TokenManager Credential store. */
	private TokenManager $token_manager;

	/** @var ResponseParser Response normaliser. */
	private ResponseParser $parser;

	/** @var RequestLogger Request audit log. */
	private RequestLogger $request_logger;

	/** @var int Request timeout in seconds. */
	private int $timeout;

	/** @var int Maximum number of retry attempts (not counting first try). */
	private int $max_retries;

	/** @var int Base delay between retries in seconds. */
	private int $retry_delay;

	/** @var bool Include raw bodies in logs. */
	private bool $debug_mode;

	/**
	 * @param string       $base_url      Full base URL with trailing slash.
	 * @param TokenManager $token_manager
	 * @param int          $timeout       Request timeout in seconds.
	 * @param int          $max_retries   Max retry attempts.
	 * @param int          $retry_delay   Base seconds between retries.
	 * @param bool         $debug_mode    Log response bodies.
	 */
	public function __construct(
		string $base_url,
		TokenManager $token_manager,
		int $timeout = 30,
		int $max_retries = 3,
		int $retry_delay = 5,
		bool $debug_mode = false
	) {
		$this->base_url      = rtrim( $base_url, '/' ) . '/';
		$this->token_manager = $token_manager;
		$this->timeout       = max( 5, min( $timeout, 120 ) );
		$this->max_retries   = max( 0, min( $max_retries, 10 ) );
		$this->retry_delay   = max( 1, $retry_delay );
		$this->debug_mode    = $debug_mode;
		$this->parser        = new ResponseParser( $debug_mode );
		$this->request_logger = new RequestLogger( $debug_mode );
	}

	// -------------------------------------------------------------------------
	// Public HTTP methods
	// -------------------------------------------------------------------------

	/**
	 * Send a GET request.
	 *
	 * @param string               $endpoint Path relative to base_url.
	 * @param array<string, mixed> $params   Query parameters.
	 * @return array<string, mixed> Parsed response.
	 * @throws ConnectionException|AuthException|ApiException
	 */
	public function get( string $endpoint, array $params = [] ): array {
		if ( ! empty( $params ) ) {
			$endpoint .= '?' . http_build_query( $params );
		}
		return $this->request( 'GET', $endpoint, [] );
	}

	/**
	 * Send a POST request with a JSON body.
	 *
	 * @param string               $endpoint Path relative to base_url.
	 * @param array<string, mixed> $body     Data to JSON-encode as the body.
	 * @return array<string, mixed> Parsed response.
	 * @throws ConnectionException|AuthException|ApiException
	 */
	public function post( string $endpoint, array $body = [] ): array {
		return $this->request( 'POST', $endpoint, $body );
	}

	/**
	 * Send a PUT request with a JSON body.
	 *
	 * @param string               $endpoint
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	public function put( string $endpoint, array $body = [] ): array {
		return $this->request( 'PUT', $endpoint, $body );
	}

	/**
	 * Send a DELETE request.
	 *
	 * @param string $endpoint
	 * @return array<string, mixed>
	 */
	public function delete( string $endpoint ): array {
		return $this->request( 'DELETE', $endpoint, [] );
	}

	// -------------------------------------------------------------------------
	// Core request executor
	// -------------------------------------------------------------------------

	/**
	 * Execute an HTTP request with automatic retry logic.
	 *
	 * @param string               $method   HTTP verb.
	 * @param string               $endpoint Path relative to base_url.
	 * @param array<string, mixed> $data     Body data (ignored for GET/DELETE).
	 * @return array<string, mixed> Normalised parsed response.
	 * @throws ConfigurationException|ConnectionException|AuthException|ApiException
	 */
	private function request( string $method, string $endpoint, array $data ): array {
		if ( ! $this->token_manager->has_token() ) {
			throw new ConfigurationException(
				__( 'No access token configured. Please enter your Meta access token in Settings → WhatsApp API.', 'tsh-whatsapp-notify' )
			);
		}

		$url     = $this->base_url . ltrim( $endpoint, '/' );
		$attempt = 0;
		$parsed  = [];

		do {
			if ( $attempt > 0 ) {
				// Exponential back-off: 5s, 10s, 20s, …
				$delay = $this->retry_delay * (int) pow( 2, $attempt - 1 );
				sleep( min( $delay, 120 ) );
			}

			$start    = microtime( true );
			$response = $this->send_once( $method, $url, $data );
			$elapsed  = ( microtime( true ) - $start ) * 1000; // ms

			$parsed = $this->parser->parse( $response );

			$this->request_logger->log_request(
				$endpoint,
				$method,
				$elapsed,
				$parsed['http_status'],
				$parsed['success'],
				$attempt,
				$parsed['error_code'],
				$parsed['raw_body'] ?? null,
				$parsed['response_size']
			);

			// Store timing in the parsed result for callers.
			$parsed['latency_ms'] = round( $elapsed, 1 );

			if ( $parsed['success'] ) {
				return $parsed;
			}

			// Abort immediately on auth errors.
			if ( in_array( $parsed['http_status'], [ 401, 403 ], true ) ) {
				throw new AuthException(
					__( 'Access token is invalid or does not have the required permissions.', 'tsh-whatsapp-notify' ),
					$parsed['error_code'],
					$parsed['error_message']
				);
			}

			$attempt++;

		} while ( $parsed['retry'] && $attempt <= $this->max_retries );

		return $parsed;
	}

	/**
	 * Execute a single HTTP request (no retry).
	 *
	 * @param string               $method
	 * @param string               $url    Full URL.
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>|\WP_Error wp_remote_request() return value.
	 */
	private function send_once( string $method, string $url, array $data ): mixed {
		$args = [
			'method'    => strtoupper( $method ),
			'timeout'   => $this->timeout,
			'headers'   => $this->build_headers(),
			'sslverify' => true,
		];

		if ( ! empty( $data ) && in_array( $method, [ 'POST', 'PUT', 'PATCH' ], true ) ) {
			$args['body']                     = wp_json_encode( $data );
			$args['headers']['Content-Type']  = 'application/json';
			$args['headers']['Content-Length'] = (string) strlen( $args['body'] );
		}

		return wp_remote_request( $url, $args );
	}

	/**
	 * Build the HTTP headers array (Authorization is added here).
	 * The token value is used only as a header — never logged.
	 *
	 * @return array<string, string>
	 */
	private function build_headers(): array {
		return [
			'Authorization' => 'Bearer ' . $this->token_manager->get_token(),
			'Accept'        => 'application/json',
			'User-Agent'    => 'TSHWhatsAppNotify/' . TSH_WA_VERSION . ' WordPress/' . get_bloginfo( 'version' ),
		];
	}
}
