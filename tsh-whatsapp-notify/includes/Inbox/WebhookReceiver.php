<?php
/**
 * Meta webhook receiver — registers the REST endpoint.
 *
 * @package TSH\WhatsAppNotify\Inbox
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Inbox;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WebhookReceiver
 *
 * Registers a WordPress REST API endpoint at:
 *   /wp-json/tsh-wa/v1/webhook
 *
 * GET  — Meta webhook verification challenge.
 * POST — Incoming messages and status updates from Meta Cloud API.
 *
 * Security:
 *  - GET uses verify_token comparison (timing-safe).
 *  - POST validates the X-Hub-Signature-256 HMAC-SHA256 header.
 *  - Raw body is read before WordPress parses it.
 */
final class WebhookReceiver {

	/** @var string REST namespace. */
	public const REST_NAMESPACE = 'tsh-wa/v1';

	/** @var string REST route. */
	public const REST_ROUTE = '/webhook';

	/** @var WebhookValidator */
	private WebhookValidator $validator;

	/** @var IncomingMessageProcessor */
	private IncomingMessageProcessor $processor;

	/** @var ConversationLogger */
	private ConversationLogger $logger;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->validator = new WebhookValidator();
		$this->processor = new IncomingMessageProcessor();
		$this->logger    = new ConversationLogger();
	}

	/**
	 * Register hooks — call once from Loader.
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register the REST routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			[
				// GET — challenge verification.
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'handle_verification' ],
					'permission_callback' => '__return_true',
					'args'                => [
						'hub.mode'         => [ 'type' => 'string', 'required' => true ],
						'hub.verify_token' => [ 'type' => 'string', 'required' => true ],
						'hub.challenge'    => [ 'type' => 'string', 'required' => true ],
					],
				],
				// POST — incoming events.
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'handle_event' ],
					'permission_callback' => '__return_true',
				],
			]
		);
	}

	// -------------------------------------------------------------------------
	// Handlers
	// -------------------------------------------------------------------------

	/**
	 * Handle GET — Meta webhook verification.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_verification( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$mode         = (string) $request->get_param( 'hub.mode' );
		$verify_token = (string) $request->get_param( 'hub.verify_token' );
		$challenge    = (string) $request->get_param( 'hub.challenge' );

		$result = $this->validator->verify_challenge( $mode, $verify_token, $challenge );

		if ( null === $result ) {
			$this->logger->warning( 'Webhook verification failed.', [
				'mode'  => $mode,
				'token' => '***', // Never log the token value.
			] );
			return new \WP_Error( 'forbidden', __( 'Webhook verification failed.', 'tsh-whatsapp-notify' ), [ 'status' => 403 ] );
		}

		$this->logger->success( 'Webhook verified successfully.' );
		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * Handle POST — incoming Meta event payload.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function handle_event( \WP_REST_Request $request ): \WP_REST_Response {
		// Read the raw body for signature validation — before JSON parsing.
		$raw_body  = $request->get_body();
		$signature = $request->get_header( 'x-hub-signature-256' ) ?? '';

		// Validate signature.
		if ( ! $this->validator->validate_signature( $raw_body, $signature ) ) {
			$this->logger->warning( 'Webhook signature validation failed.', [
				'signature_present' => ! empty( $signature ),
				'body_length'       => strlen( $raw_body ),
			] );
			// Return 200 to Meta even on failure — otherwise Meta will retry endlessly.
			// Log the failure for the admin.
			return new \WP_REST_Response( [ 'status' => 'invalid_signature' ], 200 );
		}

		// Decode JSON.
		$payload = json_decode( $raw_body, true );
		if ( ! is_array( $payload ) ) {
			$this->logger->warning( 'Webhook payload is not valid JSON.', [ 'raw' => substr( $raw_body, 0, 200 ) ] );
			return new \WP_REST_Response( [ 'status' => 'invalid_payload' ], 200 );
		}

		// Process asynchronously if background processing is enabled.
		$settings = get_option( 'tsh_wa_inbox_settings', [] );
		if ( ! empty( $settings['async_webhook'] ) && '1' === (string) $settings['async_webhook'] ) {
			// Schedule processing on the next cron tick.
			wp_schedule_single_event( time(), 'tsh_wa_process_webhook', [ $payload ] );
		} else {
			// Process synchronously (default — reliable, no cron delay).
			try {
				$this->processor->process( $payload );
			} catch ( \Throwable $e ) {
				$this->logger->error( 'Webhook processing exception.', [ 'error' => $e->getMessage() ] );
			}
		}

		// Always return 200 to Meta immediately.
		return new \WP_REST_Response( [ 'status' => 'ok' ], 200 );
	}

	/**
	 * Return the full webhook URL for display in settings.
	 *
	 * @return string
	 */
	public static function get_webhook_url(): string {
		return rest_url( self::REST_NAMESPACE . self::REST_ROUTE );
	}
}
