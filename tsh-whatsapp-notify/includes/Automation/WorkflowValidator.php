<?php
/**
 * Workflow definition validator.
 *
 * @package TSH\WhatsAppNotify\Automation
 */

declare( strict_types=1 );

namespace TSH\WhatsAppNotify\Automation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WorkflowValidator
 *
 * Validates workflow definitions before saving or activating.
 */
class WorkflowValidator {

	/**
	 * Validate a workflow definition.
	 *
	 * @param array $data {
	 *   @type string $name
	 *   @type string $trigger_type
	 *   @type array  $nodes
	 *   @type array  $edges
	 * }
	 * @return array{ valid: bool, errors: string[] }
	 */
	public function validate( array $data ): array {
		$errors = [];

		// Name.
		if ( empty( $data['name'] ) || strlen( trim( $data['name'] ) ) < 2 ) {
			$errors[] = __( 'Workflow name must be at least 2 characters.', 'tsh-whatsapp-notify' );
		}

		// Trigger type.
		if ( empty( $data['trigger_type'] ) ) {
			$errors[] = __( 'A trigger type is required.', 'tsh-whatsapp-notify' );
		} elseif ( ! array_key_exists( $data['trigger_type'], TriggerManager::get_triggers() ) ) {
			$errors[] = sprintf(
				/* translators: %s: trigger type */
				__( 'Unknown trigger type: %s', 'tsh-whatsapp-notify' ),
				esc_html( $data['trigger_type'] )
			);
		}

		// Nodes.
		$nodes = $data['nodes'] ?? [];
		if ( ! is_array( $nodes ) ) {
			$errors[] = __( 'Workflow nodes must be an array.', 'tsh-whatsapp-notify' );
		} else {
			$has_trigger = false;
			$has_action  = false;
			$node_ids    = [];

			foreach ( $nodes as $index => $node ) {
				$node_id   = $node['id'] ?? "node_{$index}";
				$node_type = $node['type'] ?? '';

				if ( in_array( $node_id, $node_ids, true ) ) {
					$errors[] = sprintf( __( 'Duplicate node ID: %s', 'tsh-whatsapp-notify' ), esc_html( (string) $node_id ) );
				}
				$node_ids[] = $node_id;

				if ( 'trigger' === $node['category'] ?? '' ) {
					$has_trigger = true;
				}

				if ( in_array( $node_type, array_keys( ActionManager::get_actions() ), true ) && 'condition' !== $node_type && 'wait' !== $node_type ) {
					$has_action = true;
				}

				// Required fields per node type.
				$this->validate_node( $node, $errors );
			}

			if ( ! $has_trigger && ! empty( $nodes ) ) {
				// Acceptable — trigger can be implicit from workflow trigger_type.
			}
		}

		// Edges.
		$edges = $data['edges'] ?? [];
		if ( ! is_array( $edges ) ) {
			$errors[] = __( 'Workflow edges must be an array.', 'tsh-whatsapp-notify' );
		}

		return [
			'valid'  => empty( $errors ),
			'errors' => $errors,
		];
	}

	// -------------------------------------------------------------------------
	// Node-level validation
	// -------------------------------------------------------------------------

	private function validate_node( array $node, array &$errors ): void {
		$type   = $node['type'] ?? '';
		$config = $node['config'] ?? $node['data'] ?? [];

		switch ( $type ) {
			case 'send_whatsapp':
			case 'queue_whatsapp':
				if ( empty( $config['message'] ) ) {
					$errors[] = sprintf(
						/* translators: %s: node type */
						__( 'Action "%s" requires a message.', 'tsh-whatsapp-notify' ),
						esc_html( $type )
					);
				}
				break;

			case 'webhook':
				if ( empty( $config['url'] ) ) {
					$errors[] = __( 'Webhook action requires a URL.', 'tsh-whatsapp-notify' );
				} elseif ( strpos( $config['url'], '{{' ) === false && ! filter_var( $config['url'], FILTER_VALIDATE_URL ) ) {
					$errors[] = __( 'Webhook URL is not a valid URL.', 'tsh-whatsapp-notify' );
				}
				break;

			case 'send_email':
				if ( empty( $config['to'] ) || empty( $config['subject'] ) ) {
					$errors[] = __( 'Email action requires "To" and "Subject" fields.', 'tsh-whatsapp-notify' );
				}
				break;

			case 'create_coupon':
				if ( ! isset( $config['amount'] ) || (float) $config['amount'] <= 0 ) {
					$errors[] = __( 'Create Coupon action requires a positive amount.', 'tsh-whatsapp-notify' );
				}
				break;
		}
	}
}
