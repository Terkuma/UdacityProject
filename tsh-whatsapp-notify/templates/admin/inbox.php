<?php
/**
 * Inbox admin page template.
 *
 * Variables available (extracted by Pages\Inbox::render()):
 *
 * @var array  $initial_data  { conversations: [], total: int, unread: [] }
 * @var array  $analytics     Full analytics overview array
 * @var string $webhook_url   Webhook endpoint URL
 * @var array  $labels        All available labels
 * @var array  $agents        Available agents
 * @var string $webhook_token Stored webhook verify token
 *
 * @package TSH\WhatsAppNotify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$conversations  = $initial_data['conversations'] ?? [];
$total_convs    = $initial_data['total']         ?? 0;
$unread         = $initial_data['unread']        ?? [];
$open_count     = $analytics['conversations']['open']   ?? 0;
$closed_count   = $analytics['conversations']['closed'] ?? 0;
$msgs_today     = $analytics['messages_today']['total'] ?? 0;
$avg_response   = $analytics['avg_response_minutes']    ?? null;
?>
<div class="wrap tsh-wa-inbox-wrap">

	<!-- Page header -->
	<div class="tsh-wa-page-header tsh-wa-inbox-header">
		<div class="tsh-wa-page-header__left">
			<h1 class="tsh-wa-page-title">
				<span class="dashicons dashicons-format-chat"></span>
				<?php esc_html_e( 'Inbox', 'tsh-whatsapp-notify' ); ?>
				<?php if ( ! empty( $unread['total'] ) ) : ?>
					<span class="tsh-wa-inbox-badge tsh-wa-inbox-badge--red"><?php echo esc_html( $unread['total'] ); ?></span>
				<?php endif; ?>
			</h1>
		</div>
		<div class="tsh-wa-page-header__right">
			<span class="tsh-wa-inbox-webhook-label"><?php esc_html_e( 'Webhook URL:', 'tsh-whatsapp-notify' ); ?></span>
			<code class="tsh-wa-inbox-webhook-url" id="tsh-wa-webhook-url"><?php echo esc_html( $webhook_url ); ?></code>
			<button type="button" class="button tsh-wa-btn-copy" data-copy="tsh-wa-webhook-url" title="<?php esc_attr_e( 'Copy', 'tsh-whatsapp-notify' ); ?>">
				<span class="dashicons dashicons-clipboard"></span>
			</button>
		</div>
	</div>

	<!-- Stats row -->
	<div class="tsh-wa-inbox-stats-row">
		<div class="tsh-wa-inbox-stat-card">
			<span class="tsh-wa-inbox-stat-icon dashicons dashicons-format-chat tsh-wa-color-green"></span>
			<div class="tsh-wa-inbox-stat-data">
				<span class="tsh-wa-inbox-stat-value" id="tsh-wa-stat-open"><?php echo esc_html( $open_count ); ?></span>
				<span class="tsh-wa-inbox-stat-label"><?php esc_html_e( 'Open', 'tsh-whatsapp-notify' ); ?></span>
			</div>
		</div>
		<div class="tsh-wa-inbox-stat-card">
			<span class="tsh-wa-inbox-stat-icon dashicons dashicons-yes-alt tsh-wa-color-grey"></span>
			<div class="tsh-wa-inbox-stat-data">
				<span class="tsh-wa-inbox-stat-value" id="tsh-wa-stat-closed"><?php echo esc_html( $closed_count ); ?></span>
				<span class="tsh-wa-inbox-stat-label"><?php esc_html_e( 'Closed', 'tsh-whatsapp-notify' ); ?></span>
			</div>
		</div>
		<div class="tsh-wa-inbox-stat-card">
			<span class="tsh-wa-inbox-stat-icon dashicons dashicons-email tsh-wa-color-blue"></span>
			<div class="tsh-wa-inbox-stat-data">
				<span class="tsh-wa-inbox-stat-value" id="tsh-wa-stat-today"><?php echo esc_html( $msgs_today ); ?></span>
				<span class="tsh-wa-inbox-stat-label"><?php esc_html_e( 'Messages Today', 'tsh-whatsapp-notify' ); ?></span>
			</div>
		</div>
		<div class="tsh-wa-inbox-stat-card">
			<span class="tsh-wa-inbox-stat-icon dashicons dashicons-clock tsh-wa-color-orange"></span>
			<div class="tsh-wa-inbox-stat-data">
				<span class="tsh-wa-inbox-stat-value" id="tsh-wa-stat-response">
					<?php echo null !== $avg_response ? esc_html( $avg_response . ' ' . __( 'min', 'tsh-whatsapp-notify' ) ) : '—'; ?>
				</span>
				<span class="tsh-wa-inbox-stat-label"><?php esc_html_e( 'Avg Response', 'tsh-whatsapp-notify' ); ?></span>
			</div>
		</div>
	</div>

	<!-- Main inbox layout -->
	<div class="tsh-wa-inbox-layout" id="tsh-wa-inbox-layout">

		<!-- LEFT: Conversation list panel -->
		<div class="tsh-wa-inbox-sidebar" id="tsh-wa-inbox-sidebar">

			<!-- Search bar -->
			<div class="tsh-wa-inbox-search-bar">
				<span class="dashicons dashicons-search tsh-wa-inbox-search-icon"></span>
				<input type="text" id="tsh-wa-inbox-search" class="tsh-wa-inbox-search-input"
					placeholder="<?php esc_attr_e( 'Search conversations…', 'tsh-whatsapp-notify' ); ?>" />
				<button type="button" id="tsh-wa-inbox-search-clear" class="tsh-wa-inbox-search-clear" style="display:none;">
					<span class="dashicons dashicons-no-alt"></span>
				</button>
			</div>

			<!-- Filter tabs -->
			<div class="tsh-wa-inbox-filter-tabs" id="tsh-wa-inbox-tabs">
				<button type="button" class="tsh-wa-inbox-tab tsh-wa-inbox-tab--active" data-status="open">
					<?php esc_html_e( 'Open', 'tsh-whatsapp-notify' ); ?>
					<span class="tsh-wa-inbox-badge" id="tsh-wa-tab-badge-open"><?php echo esc_html( $open_count ); ?></span>
				</button>
				<button type="button" class="tsh-wa-inbox-tab" data-status="closed">
					<?php esc_html_e( 'Closed', 'tsh-whatsapp-notify' ); ?>
				</button>
				<button type="button" class="tsh-wa-inbox-tab" data-status="archived">
					<?php esc_html_e( 'Archived', 'tsh-whatsapp-notify' ); ?>
				</button>
			</div>

			<!-- Conversation list -->
			<div class="tsh-wa-inbox-conv-list" id="tsh-wa-inbox-conv-list">
				<?php if ( empty( $conversations ) ) : ?>
					<div class="tsh-wa-inbox-empty-state" id="tsh-wa-inbox-empty-state">
						<span class="dashicons dashicons-format-chat tsh-wa-inbox-empty-icon"></span>
						<p><?php esc_html_e( 'No conversations yet.', 'tsh-whatsapp-notify' ); ?></p>
						<p class="tsh-wa-inbox-empty-hint">
							<?php esc_html_e( 'Configure your webhook URL in Meta to start receiving messages.', 'tsh-whatsapp-notify' ); ?>
						</p>
					</div>
				<?php else : ?>
					<div id="tsh-wa-conv-items">
						<?php foreach ( $conversations as $conv ) : ?>
							<div class="tsh-wa-conv-item<?php echo $conv['is_pinned'] ? ' tsh-wa-conv-item--pinned' : ''; ?><?php echo $conv['unread_count'] > 0 ? ' tsh-wa-conv-item--unread' : ''; ?>"
								data-id="<?php echo esc_attr( $conv['id'] ); ?>"
								data-phone="<?php echo esc_attr( $conv['phone'] ); ?>">

								<div class="tsh-wa-conv-item__avatar">
									<img src="<?php echo esc_url( $conv['avatar_url'] ); ?>" alt="" class="tsh-wa-conv-item__avatar-img" />
									<?php if ( $conv['unread_count'] > 0 ) : ?>
										<span class="tsh-wa-conv-item__unread-dot"><?php echo esc_html( $conv['unread_count'] ); ?></span>
									<?php endif; ?>
								</div>

								<div class="tsh-wa-conv-item__body">
									<div class="tsh-wa-conv-item__header">
										<span class="tsh-wa-conv-item__name"><?php echo esc_html( $conv['display_name'] ); ?></span>
										<span class="tsh-wa-conv-item__time"><?php echo esc_html( $conv['last_message_human'] ); ?></span>
									</div>
									<div class="tsh-wa-conv-item__preview">
										<span class="tsh-wa-conv-item__text"><?php echo esc_html( $conv['last_message_text'] ); ?></span>
									</div>
									<?php if ( ! empty( $conv['labels'] ) ) : ?>
										<div class="tsh-wa-conv-item__labels">
											<?php foreach ( $conv['labels'] as $label ) : ?>
												<span class="tsh-wa-label tsh-wa-label--<?php echo esc_attr( $label ); ?>"><?php echo esc_html( $label ); ?></span>
											<?php endforeach; ?>
										</div>
									<?php endif; ?>
								</div>

								<?php if ( $conv['is_pinned'] ) : ?>
									<span class="tsh-wa-conv-item__pin dashicons dashicons-sticky" title="<?php esc_attr_e( 'Pinned', 'tsh-whatsapp-notify' ); ?>"></span>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>
					<!-- Load more -->
					<?php if ( $total_convs > count( $conversations ) ) : ?>
						<div class="tsh-wa-inbox-load-more">
							<button type="button" class="button" id="tsh-wa-load-more-convs" data-page="2">
								<?php esc_html_e( 'Load more', 'tsh-whatsapp-notify' ); ?>
							</button>
						</div>
					<?php endif; ?>
				<?php endif; ?>
			</div><!-- /.tsh-wa-inbox-conv-list -->

		</div><!-- /.tsh-wa-inbox-sidebar -->

		<!-- MIDDLE: Chat view -->
		<div class="tsh-wa-inbox-chat" id="tsh-wa-inbox-chat">

			<!-- Empty state (no conversation selected) -->
			<div class="tsh-wa-inbox-chat-empty" id="tsh-wa-chat-empty">
				<span class="dashicons dashicons-format-chat tsh-wa-inbox-empty-icon"></span>
				<p><?php esc_html_e( 'Select a conversation to start chatting.', 'tsh-whatsapp-notify' ); ?></p>
			</div>

			<!-- Chat window (hidden until a conversation is selected) -->
			<div class="tsh-wa-inbox-chat-window" id="tsh-wa-chat-window" style="display:none;">

				<!-- Chat header -->
				<div class="tsh-wa-chat-header" id="tsh-wa-chat-header">
					<div class="tsh-wa-chat-header__avatar">
						<img src="" alt="" id="tsh-wa-chat-avatar" />
					</div>
					<div class="tsh-wa-chat-header__info">
						<span class="tsh-wa-chat-header__name" id="tsh-wa-chat-name"></span>
						<span class="tsh-wa-chat-header__phone" id="tsh-wa-chat-phone"></span>
					</div>
					<div class="tsh-wa-chat-header__actions">
						<!-- Status -->
						<select id="tsh-wa-chat-status" class="tsh-wa-chat-select" title="<?php esc_attr_e( 'Status', 'tsh-whatsapp-notify' ); ?>">
							<option value="open"><?php esc_html_e( 'Open', 'tsh-whatsapp-notify' ); ?></option>
							<option value="closed"><?php esc_html_e( 'Closed', 'tsh-whatsapp-notify' ); ?></option>
							<option value="archived"><?php esc_html_e( 'Archived', 'tsh-whatsapp-notify' ); ?></option>
						</select>
						<!-- Assign -->
						<select id="tsh-wa-chat-assign" class="tsh-wa-chat-select" title="<?php esc_attr_e( 'Assign to', 'tsh-whatsapp-notify' ); ?>">
							<option value=""><?php esc_html_e( '— Assign —', 'tsh-whatsapp-notify' ); ?></option>
							<?php foreach ( $agents as $agent ) : ?>
								<option value="<?php echo esc_attr( $agent['id'] ); ?>"><?php echo esc_html( $agent['name'] ); ?></option>
							<?php endforeach; ?>
						</select>
						<!-- Pin -->
						<button type="button" id="tsh-wa-chat-pin" class="button tsh-wa-chat-action-btn" title="<?php esc_attr_e( 'Pin / Unpin', 'tsh-whatsapp-notify' ); ?>">
							<span class="dashicons dashicons-sticky"></span>
						</button>
						<!-- Labels dropdown -->
						<div class="tsh-wa-dropdown-wrap">
							<button type="button" class="button tsh-wa-chat-action-btn" id="tsh-wa-chat-label-btn" title="<?php esc_attr_e( 'Labels', 'tsh-whatsapp-notify' ); ?>">
								<span class="dashicons dashicons-tag"></span>
							</button>
							<div class="tsh-wa-dropdown" id="tsh-wa-label-dropdown" style="display:none;">
								<?php foreach ( $labels as $label ) : ?>
									<label class="tsh-wa-dropdown-item">
										<input type="checkbox" class="tsh-wa-label-toggle"
											value="<?php echo esc_attr( $label['slug'] ); ?>"
											data-label="<?php echo esc_attr( $label['slug'] ); ?>"
										/>
										<?php echo esc_html( $label['name'] ); ?>
									</label>
								<?php endforeach; ?>
							</div>
						</div>
					</div>
				</div><!-- /.tsh-wa-chat-header -->

				<!-- Message area -->
				<div class="tsh-wa-chat-messages" id="tsh-wa-chat-messages">
					<div class="tsh-wa-chat-loading" id="tsh-wa-chat-loading" style="display:none;">
						<span class="spinner is-active"></span>
						<?php esc_html_e( 'Loading messages…', 'tsh-whatsapp-notify' ); ?>
					</div>
					<div id="tsh-wa-messages-container"></div>
					<!-- Load older messages -->
					<div class="tsh-wa-chat-load-older" id="tsh-wa-load-older-wrap" style="display:none;">
						<button type="button" class="button-secondary" id="tsh-wa-load-older">
							<?php esc_html_e( 'Load older messages', 'tsh-whatsapp-notify' ); ?>
						</button>
					</div>
				</div>

				<!-- Composer -->
				<div class="tsh-wa-chat-composer" id="tsh-wa-chat-composer">
					<!-- Composer tabs: Reply / Note -->
					<div class="tsh-wa-composer-tabs">
						<button type="button" class="tsh-wa-composer-tab tsh-wa-composer-tab--active" id="tsh-wa-tab-reply" data-mode="reply">
							<span class="dashicons dashicons-share"></span>
							<?php esc_html_e( 'Reply', 'tsh-whatsapp-notify' ); ?>
						</button>
						<button type="button" class="tsh-wa-composer-tab" id="tsh-wa-tab-note" data-mode="note">
							<span class="dashicons dashicons-edit"></span>
							<?php esc_html_e( 'Note', 'tsh-whatsapp-notify' ); ?>
						</button>
					</div>

					<div class="tsh-wa-composer-body">
						<textarea id="tsh-wa-composer-text" class="tsh-wa-composer-textarea"
							placeholder="<?php esc_attr_e( 'Type a message…', 'tsh-whatsapp-notify' ); ?>"
							rows="3" maxlength="4096"></textarea>

						<div class="tsh-wa-composer-footer">
							<span class="tsh-wa-composer-char-count" id="tsh-wa-char-count">0 / 4096</span>
							<div class="tsh-wa-composer-actions">
								<button type="button" class="button button-primary" id="tsh-wa-send-btn">
									<span class="dashicons dashicons-share"></span>
									<span id="tsh-wa-send-label"><?php esc_html_e( 'Send', 'tsh-whatsapp-notify' ); ?></span>
								</button>
							</div>
						</div>
					</div>
				</div><!-- /.tsh-wa-chat-composer -->

			</div><!-- /.tsh-wa-inbox-chat-window -->

		</div><!-- /.tsh-wa-inbox-chat -->

		<!-- RIGHT: Customer sidebar -->
		<div class="tsh-wa-inbox-customer-sidebar" id="tsh-wa-customer-sidebar" style="display:none;">

			<div class="tsh-wa-customer-sidebar-inner">

				<!-- Customer info -->
				<div class="tsh-wa-customer-profile" id="tsh-wa-customer-profile">
					<div class="tsh-wa-customer-avatar-wrap">
						<img src="" alt="" id="tsh-wa-customer-avatar" class="tsh-wa-customer-avatar" />
					</div>
					<h3 class="tsh-wa-customer-name" id="tsh-wa-customer-name"></h3>
					<p class="tsh-wa-customer-phone" id="tsh-wa-customer-phone"></p>
					<p class="tsh-wa-customer-email" id="tsh-wa-customer-email"></p>
					<a href="#" id="tsh-wa-customer-edit-link" class="button button-small tsh-wa-customer-edit-btn" target="_blank" style="display:none;">
						<?php esc_html_e( 'Edit Customer', 'tsh-whatsapp-notify' ); ?>
					</a>
				</div>

				<!-- Customer stats -->
				<div class="tsh-wa-customer-stats" id="tsh-wa-customer-stats">
					<div class="tsh-wa-customer-stat">
						<span class="tsh-wa-customer-stat__label"><?php esc_html_e( 'Total Orders', 'tsh-whatsapp-notify' ); ?></span>
						<span class="tsh-wa-customer-stat__value" id="tsh-wa-cust-orders">—</span>
					</div>
					<div class="tsh-wa-customer-stat">
						<span class="tsh-wa-customer-stat__label"><?php esc_html_e( 'Lifetime Value', 'tsh-whatsapp-notify' ); ?></span>
						<span class="tsh-wa-customer-stat__value" id="tsh-wa-cust-value">—</span>
					</div>
				</div>

				<!-- Recent orders -->
				<div class="tsh-wa-customer-orders-section">
					<h4 class="tsh-wa-customer-section-title">
						<span class="dashicons dashicons-cart"></span>
						<?php esc_html_e( 'Recent Orders', 'tsh-whatsapp-notify' ); ?>
					</h4>
					<div id="tsh-wa-customer-orders-list" class="tsh-wa-customer-orders-list">
						<p class="tsh-wa-customer-orders-empty"><?php esc_html_e( 'No orders found.', 'tsh-whatsapp-notify' ); ?></p>
					</div>
				</div>

			</div><!-- /.tsh-wa-customer-sidebar-inner -->

		</div><!-- /.tsh-wa-inbox-customer-sidebar -->

	</div><!-- /.tsh-wa-inbox-layout -->

</div><!-- /.wrap -->

<!-- Message template (cloned by JS) -->
<script type="text/html" id="tsh-wa-msg-template">
	<div class="tsh-wa-msg tsh-wa-msg--{{direction}}{{is_note_class}}" data-id="{{id}}" data-type="{{type}}">
		<div class="tsh-wa-msg__bubble">
			<div class="tsh-wa-msg__content">{{{content}}}</div>
			{{#has_media}}
			<div class="tsh-wa-msg__media">
				{{#media_url}}
				<a href="{{media_url}}" target="_blank" class="tsh-wa-msg__media-link">
					<span class="dashicons dashicons-download"></span>
					<?php esc_html_e( 'View / Download', 'tsh-whatsapp-notify' ); ?>
				</a>
				{{/media_url}}
				{{^media_url}}
				<button type="button" class="tsh-wa-msg__download-btn button-link" data-msg-id="{{id}}">
					<span class="dashicons dashicons-download"></span>
					<?php esc_html_e( 'Download', 'tsh-whatsapp-notify' ); ?>
				</button>
				{{/media_url}}
			</div>
			{{/has_media}}
			<div class="tsh-wa-msg__meta">
				<span class="tsh-wa-msg__time">{{time_human}}</span>
				{{#is_outgoing}}
				<span class="tsh-wa-msg__status tsh-wa-msg__status--{{status}}" title="{{status}}">{{status_icon}}</span>
				{{/is_outgoing}}
			</div>
		</div>
	</div>
</script>

<script type="text/html" id="tsh-wa-date-separator-template">
	<div class="tsh-wa-date-separator">
		<span class="tsh-wa-date-separator__label">{{date}}</span>
	</div>
</script>

<script type="text/html" id="tsh-wa-note-template">
	<div class="tsh-wa-msg tsh-wa-msg--note" data-id="{{id}}">
		<div class="tsh-wa-msg__bubble tsh-wa-msg__bubble--note">
			<span class="tsh-wa-msg__note-tag">
				<span class="dashicons dashicons-edit"></span>
				<?php esc_html_e( 'Internal Note', 'tsh-whatsapp-notify' ); ?>
			</span>
			<div class="tsh-wa-msg__content">{{{content}}}</div>
			<div class="tsh-wa-msg__meta">
				<span class="tsh-wa-msg__time">{{time_human}}</span>
			</div>
		</div>
	</div>
</script>

<script type="text/html" id="tsh-wa-order-card-template">
	<div class="tsh-wa-order-card">
		<div class="tsh-wa-order-card__header">
			<a href="{{edit_url}}" target="_blank" class="tsh-wa-order-card__number">#{{number}}</a>
			<span class="tsh-wa-order-card__status tsh-wa-order-status--{{status}}">{{status_label}}</span>
		</div>
		<div class="tsh-wa-order-card__meta">
			<span>{{total}}</span>
			<span>{{date_human}}</span>
		</div>
	</div>
</script>

<script>
	/* Localize inbox data for JS. */
	var tshWaInbox = <?php echo wp_json_encode( [
		'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
		'nonce'         => wp_create_nonce( \TSH\WhatsAppNotify\Admin\Ajax::NONCE_ACTION ),
		'pollInterval'  => 15000,
		'conversations' => $conversations,
		'totalConvs'    => $total_convs,
		'labels'        => $labels,
		'agents'        => $agents,
		'i18n'          => [
			'sending'         => __( 'Sending…', 'tsh-whatsapp-notify' ),
			'send'            => __( 'Send', 'tsh-whatsapp-notify' ),
			'add_note'        => __( 'Add Note', 'tsh-whatsapp-notify' ),
			'note'            => __( 'Note', 'tsh-whatsapp-notify' ),
			'message_sent'    => __( 'Message sent.', 'tsh-whatsapp-notify' ),
			'note_added'      => __( 'Note added.', 'tsh-whatsapp-notify' ),
			'error'           => __( 'An error occurred. Please try again.', 'tsh-whatsapp-notify' ),
			'type_message'    => __( 'Type a message…', 'tsh-whatsapp-notify' ),
			'type_note'       => __( 'Type an internal note (only admins see this)…', 'tsh-whatsapp-notify' ),
			'just_now'        => __( 'Just now', 'tsh-whatsapp-notify' ),
			'status_updated'  => __( 'Status updated.', 'tsh-whatsapp-notify' ),
			'assigned'        => __( 'Conversation assigned.', 'tsh-whatsapp-notify' ),
			'label_added'     => __( 'Label added.', 'tsh-whatsapp-notify' ),
			'label_removed'   => __( 'Label removed.', 'tsh-whatsapp-notify' ),
			'no_conversations'=> __( 'No conversations found.', 'tsh-whatsapp-notify' ),
			'confirm_rebuild' => __( 'This will permanently delete ALL conversation and message data and cannot be undone. Continue?', 'tsh-whatsapp-notify' ),
			'rebuild_done'    => __( 'Inbox rebuilt.', 'tsh-whatsapp-notify' ),
			'resync_done'     => __( 'Resync complete.', 'tsh-whatsapp-notify' ),
			'cache_cleared'   => __( 'Cache cleared.', 'tsh-whatsapp-notify' ),
			'copied'          => __( 'Copied!', 'tsh-whatsapp-notify' ),
			'today'           => __( 'Today', 'tsh-whatsapp-notify' ),
			'yesterday'       => __( 'Yesterday', 'tsh-whatsapp-notify' ),
			'loading'         => __( 'Loading…', 'tsh-whatsapp-notify' ),
			'load_older'      => __( 'Load older messages', 'tsh-whatsapp-notify' ),
			'downloaded'      => __( 'Media downloaded.', 'tsh-whatsapp-notify' ),
			'pinned'          => __( 'Conversation pinned.', 'tsh-whatsapp-notify' ),
			'unpinned'        => __( 'Conversation unpinned.', 'tsh-whatsapp-notify' ),
		],
	] ); ?>;
</script>
