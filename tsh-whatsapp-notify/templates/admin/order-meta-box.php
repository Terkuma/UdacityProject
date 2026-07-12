<?php
/**
 * WhatsApp notification meta box — WooCommerce order edit screen.
 *
 * Variables injected by OrderMetaBox::render_meta_box():
 *
 * @var \WC_Order  $order
 * @var int        $order_id
 * @var string|null $customer_phone
 * @var int        $admin_count
 * @var string     $customer_message
 * @var string     $admin_message
 * @var array      $notifications
 * @var string     $nonce
 * @var string     $preview_event
 * @var array      $ev               Per-event settings for $preview_event.
 *
 * @package TSH\WhatsAppNotify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TSH\WhatsAppNotify\Orders\OrderStatusListener;

$all_events = OrderStatusListener::ALL_EVENTS;

$customer_enabled = ! empty( $ev['notify_customer'] );
$admin_enabled    = ! empty( $ev['notify_admin'] );
?>
<div class="tsh-wa-metabox"
	id="tsh-wa-metabox"
	data-order-id="<?php echo esc_attr( $order_id ); ?>"
	data-nonce="<?php echo esc_attr( $nonce ); ?>"
>

	<?php /* ── Recipient status row ─────────────────────────────────────── */ ?>
	<div class="tsh-wa-metabox__recipients">

		<span class="tsh-wa-metabox__recipient-pill <?php echo $customer_phone ? 'tsh-wa-metabox__recipient-pill--ok' : 'tsh-wa-metabox__recipient-pill--warn'; ?>">
			<span class="dashicons dashicons-<?php echo $customer_phone ? 'yes-alt' : 'warning'; ?>" aria-hidden="true"></span>
			<?php esc_html_e( 'Customer', 'tsh-whatsapp-notify' ); ?>
			<?php if ( $customer_phone ) : ?>
				<code><?php echo esc_html( substr( $customer_phone, 0, 7 ) . '…' ); ?></code>
			<?php else : ?>
				<em><?php esc_html_e( 'no phone', 'tsh-whatsapp-notify' ); ?></em>
			<?php endif; ?>
		</span>

		<span class="tsh-wa-metabox__recipient-pill <?php echo $admin_count > 0 ? 'tsh-wa-metabox__recipient-pill--ok' : 'tsh-wa-metabox__recipient-pill--warn'; ?>">
			<span class="dashicons dashicons-<?php echo $admin_count > 0 ? 'yes-alt' : 'warning'; ?>" aria-hidden="true"></span>
			<?php
			printf(
				/* translators: %d: number of admin recipients */
				esc_html( _n( '%d Admin', '%d Admins', $admin_count, 'tsh-whatsapp-notify' ) ),
				(int) $admin_count
			);
			?>
		</span>

	</div>

	<?php /* ── Event selector ───────────────────────────────────────────── */ ?>
	<div class="tsh-wa-metabox__event-row">
		<label for="tsh-wa-mb-event" class="tsh-wa-metabox__event-label">
			<?php esc_html_e( 'Preview event:', 'tsh-whatsapp-notify' ); ?>
		</label>
		<select id="tsh-wa-mb-event" class="tsh-wa-metabox__event-select">
			<?php foreach ( $all_events as $ev_key => $ev_label ) : ?>
				<option value="<?php echo esc_attr( $ev_key ); ?>" <?php selected( $preview_event, $ev_key ); ?>>
					<?php echo esc_html( $ev_label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<span class="spinner tsh-wa-metabox__spinner"></span>
	</div>

	<?php /* ── Message previews ─────────────────────────────────────────── */ ?>
	<div id="tsh-wa-mb-previews">

		<details class="tsh-wa-mb-preview" open>
			<summary class="tsh-wa-mb-preview__summary">
				<span class="dashicons dashicons-admin-users" aria-hidden="true"></span>
				<?php esc_html_e( 'Customer Message', 'tsh-whatsapp-notify' ); ?>
				<?php if ( ! $customer_enabled ) : ?>
					<span class="tsh-wa-mb-preview__disabled"><?php esc_html_e( '(disabled for this event)', 'tsh-whatsapp-notify' ); ?></span>
				<?php endif; ?>
			</summary>
			<div class="tsh-wa-mb-preview__body">
				<pre id="tsh-wa-mb-customer-msg" class="tsh-wa-mb-message"><?php echo esc_html( $customer_message ); ?></pre>
				<div class="tsh-wa-mb-preview__footer">
					<button type="button"
						class="button button-small tsh-wa-mb-copy"
						data-target="tsh-wa-mb-customer-msg"
					><?php esc_html_e( 'Copy', 'tsh-whatsapp-notify' ); ?></button>
					<span class="tsh-wa-mb-char-count">
						<?php
						printf(
							/* translators: %d: character count */
							esc_html__( '%d chars', 'tsh-whatsapp-notify' ),
							mb_strlen( $customer_message )
						);
						?>
					</span>
				</div>
			</div>
		</details>

		<details class="tsh-wa-mb-preview">
			<summary class="tsh-wa-mb-preview__summary">
				<span class="dashicons dashicons-store" aria-hidden="true"></span>
				<?php esc_html_e( 'Admin Message', 'tsh-whatsapp-notify' ); ?>
				<?php if ( ! $admin_enabled ) : ?>
					<span class="tsh-wa-mb-preview__disabled"><?php esc_html_e( '(disabled for this event)', 'tsh-whatsapp-notify' ); ?></span>
				<?php endif; ?>
			</summary>
			<div class="tsh-wa-mb-preview__body">
				<pre id="tsh-wa-mb-admin-msg" class="tsh-wa-mb-message"><?php echo esc_html( $admin_message ); ?></pre>
				<div class="tsh-wa-mb-preview__footer">
					<button type="button"
						class="button button-small tsh-wa-mb-copy"
						data-target="tsh-wa-mb-admin-msg"
					><?php esc_html_e( 'Copy', 'tsh-whatsapp-notify' ); ?></button>
					<span class="tsh-wa-mb-char-count">
						<?php
						printf(
							/* translators: %d: character count */
							esc_html__( '%d chars', 'tsh-whatsapp-notify' ),
							mb_strlen( $admin_message )
						);
						?>
					</span>
				</div>
			</div>
		</details>

	</div><!-- #tsh-wa-mb-previews -->

	<?php /* ── Quick action buttons ──────────────────────────────────────── */ ?>
	<div class="tsh-wa-mb-actions">
		<button type="button"
			class="button button-primary tsh-wa-mb-queue-btn"
			data-recipient="customer"
			<?php disabled( ! $customer_phone ); ?>
			title="<?php esc_attr_e( 'Queue a WhatsApp message to the customer.', 'tsh-whatsapp-notify' ); ?>"
		>
			<span class="dashicons dashicons-email-alt" aria-hidden="true"></span>
			<?php esc_html_e( 'Queue → Customer', 'tsh-whatsapp-notify' ); ?>
		</button>
		<button type="button"
			class="button tsh-wa-mb-queue-btn"
			data-recipient="admin"
			<?php disabled( $admin_count < 1 ); ?>
			title="<?php esc_attr_e( 'Queue a WhatsApp message to all admin recipients.', 'tsh-whatsapp-notify' ); ?>"
		>
			<span class="dashicons dashicons-email-alt" aria-hidden="true"></span>
			<?php esc_html_e( 'Queue → Admin(s)', 'tsh-whatsapp-notify' ); ?>
		</button>
		<button type="button"
			class="button tsh-wa-mb-resend-btn"
			title="<?php esc_attr_e( 'Force resend to all recipients, bypassing duplicate protection.', 'tsh-whatsapp-notify' ); ?>"
		>
			<span class="dashicons dashicons-update" aria-hidden="true"></span>
			<?php esc_html_e( 'Resend All', 'tsh-whatsapp-notify' ); ?>
		</button>
	</div>

	<div id="tsh-wa-mb-result" class="tsh-wa-mb-result" style="display:none;" aria-live="polite"></div>

	<?php /* ── Notification history ─────────────────────────────────────── */ ?>
	<?php if ( ! empty( $notifications ) ) : ?>
	<div class="tsh-wa-mb-history">
		<h4 class="tsh-wa-mb-history__heading">
			<?php esc_html_e( 'Notification History', 'tsh-whatsapp-notify' ); ?>
		</h4>
		<ul class="tsh-wa-mb-history__list">
			<?php foreach ( $notifications as $n ) : ?>
			<?php
			$status_classes = [
				'sent'      => 'tsh-wa-mb-history__item--sent',
				'queued'    => 'tsh-wa-mb-history__item--queued',
				'pending'   => 'tsh-wa-mb-history__item--queued',
				'failed'    => 'tsh-wa-mb-history__item--failed',
				'cancelled' => 'tsh-wa-mb-history__item--failed',
			];
			$status_icons = [
				'sent'      => 'dashicons-yes-alt',
				'queued'    => 'dashicons-clock',
				'pending'   => 'dashicons-clock',
				'failed'    => 'dashicons-dismiss',
				'cancelled' => 'dashicons-minus',
			];
			$status_class = $status_classes[ $n->status ] ?? 'tsh-wa-mb-history__item--queued';
			$status_icon  = $status_icons[ $n->status ] ?? 'dashicons-minus';
			?>
			<li class="tsh-wa-mb-history__item <?php echo esc_attr( $status_class ); ?>">
				<span class="tsh-wa-mb-history__icon">
					<span class="dashicons <?php echo esc_attr( $status_icon ); ?>" aria-hidden="true"></span>
				</span>
				<span class="tsh-wa-mb-history__detail">
					<strong><?php echo esc_html( ucfirst( $n->recipient_type ) ); ?></strong>
					<?php if ( $n->recipient_name ) : ?>
						<span class="tsh-wa-mb-history__name">(<?php echo esc_html( $n->recipient_name ); ?>)</span>
					<?php endif; ?>
					<span class="tsh-wa-mb-history__event">
						— <?php echo esc_html( OrderStatusListener::event_label( $n->event ) ); ?>
					</span>
				</span>
				<span class="tsh-wa-mb-history__time">
					<time datetime="<?php echo esc_attr( $n->created_at ); ?>">
						<?php
						echo esc_html(
							human_time_diff( strtotime( $n->created_at ), current_time( 'timestamp' ) )
							. ' '
							. __( 'ago', 'tsh-whatsapp-notify' )
						);
						?>
					</time>
				</span>
				<?php if ( 'failed' === $n->status && ! empty( $n->error_message ) ) : ?>
				<span class="tsh-wa-mb-history__error" title="<?php echo esc_attr( $n->error_message ); ?>">
					<?php echo esc_html( wp_trim_words( $n->error_message, 6, '…' ) ); ?>
				</span>
				<?php endif; ?>
			</li>
			<?php endforeach; ?>
		</ul>
	</div>
	<?php else : ?>
	<p class="tsh-wa-mb-empty">
		<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
		<?php esc_html_e( 'No notifications sent for this order yet.', 'tsh-whatsapp-notify' ); ?>
	</p>
	<?php endif; ?>

</div><!-- .tsh-wa-metabox -->
