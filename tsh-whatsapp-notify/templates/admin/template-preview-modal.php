<?php
/**
 * Template preview modal + assignment panel.
 *
 * Included at the bottom of templates/admin/templates.php.
 * JavaScript in admin.js drives the show/hide and AJAX loading.
 *
 * @package TSH\WhatsAppNotify
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// WooCommerce event options for assignment dropdown.
$wc_events = [
	''                          => __( '— Select Event —', 'tsh-whatsapp-notify' ),
	'order_pending'             => __( 'Order: Pending', 'tsh-whatsapp-notify' ),
	'order_processing'          => __( 'Order: Processing', 'tsh-whatsapp-notify' ),
	'order_on-hold'             => __( 'Order: On Hold', 'tsh-whatsapp-notify' ),
	'order_completed'           => __( 'Order: Completed', 'tsh-whatsapp-notify' ),
	'order_cancelled'           => __( 'Order: Cancelled', 'tsh-whatsapp-notify' ),
	'order_refunded'            => __( 'Order: Refunded', 'tsh-whatsapp-notify' ),
	'order_failed'              => __( 'Order: Failed', 'tsh-whatsapp-notify' ),
	'order_partially_refunded'  => __( 'Order: Partially Refunded', 'tsh-whatsapp-notify' ),
	'order_checkout_payment_complete' => __( 'Order: Payment Complete', 'tsh-whatsapp-notify' ),
	'order_shipped'             => __( 'Order: Shipped', 'tsh-whatsapp-notify' ),
	'order_out_for_delivery'    => __( 'Order: Out for Delivery', 'tsh-whatsapp-notify' ),
];

$nonce = wp_create_nonce( \TSH\WhatsAppNotify\Admin\Ajax::NONCE_ACTION );
?>

<!-- ============================================================
     Template Preview Modal
     ============================================================ -->
<div id="tsh-wa-template-preview-modal"
	class="tsh-wa-modal"
	role="dialog"
	aria-modal="true"
	aria-labelledby="tsh-wa-preview-modal-title"
	style="display:none;">

	<div class="tsh-wa-modal__backdrop" id="tsh-wa-preview-modal-backdrop"></div>

	<div class="tsh-wa-modal__container tsh-wa-modal__container--wide">

		<!-- Modal header -->
		<div class="tsh-wa-modal__header">
			<h2 class="tsh-wa-modal__title" id="tsh-wa-preview-modal-title">
				<?php esc_html_e( 'Template Preview', 'tsh-whatsapp-notify' ); ?>
				<span class="tsh-wa-modal__template-name" id="tsh-wa-preview-template-name"></span>
			</h2>
			<button type="button" class="tsh-wa-modal__close" id="tsh-wa-preview-modal-close"
				aria-label="<?php esc_attr_e( 'Close modal', 'tsh-whatsapp-notify' ); ?>">✕</button>
		</div>

		<!-- Modal body -->
		<div class="tsh-wa-modal__body">

			<!-- Loading state -->
			<div class="tsh-wa-modal__loading" id="tsh-wa-preview-loading" style="display:none;">
				<div class="tsh-wa-spinner"></div>
				<span><?php esc_html_e( 'Loading preview…', 'tsh-whatsapp-notify' ); ?></span>
			</div>

			<!-- Preview content -->
			<div class="tsh-wa-preview-layout" id="tsh-wa-preview-content" style="display:none;">

				<!-- Left: WhatsApp message bubble preview -->
				<div class="tsh-wa-preview-layout__left">
					<h3 class="tsh-wa-section-heading">
						<?php esc_html_e( 'Message Preview', 'tsh-whatsapp-notify' ); ?>
					</h3>

					<div class="tsh-wa-whatsapp-preview">
						<div class="tsh-wa-whatsapp-preview__header" id="tsh-wa-preview-header-area"></div>
						<div class="tsh-wa-whatsapp-preview__bubble">
							<div class="tsh-wa-whatsapp-preview__body" id="tsh-wa-preview-body-text"></div>
							<div class="tsh-wa-whatsapp-preview__footer" id="tsh-wa-preview-footer-text"></div>
							<div class="tsh-wa-whatsapp-preview__timestamp">
								<?php echo esc_html( current_time( 'H:i' ) ); ?> ✓✓
							</div>
						</div>
						<div class="tsh-wa-whatsapp-preview__buttons" id="tsh-wa-preview-buttons-area"></div>
					</div>

					<!-- Template meta -->
					<div class="tsh-wa-preview-meta" id="tsh-wa-preview-meta-area">
						<dl class="tsh-wa-definition-list">
							<dt><?php esc_html_e( 'Category', 'tsh-whatsapp-notify' ); ?></dt>
							<dd id="tsh-wa-meta-category">—</dd>
							<dt><?php esc_html_e( 'Language', 'tsh-whatsapp-notify' ); ?></dt>
							<dd id="tsh-wa-meta-language">—</dd>
							<dt><?php esc_html_e( 'Status', 'tsh-whatsapp-notify' ); ?></dt>
							<dd id="tsh-wa-meta-status">—</dd>
							<dt><?php esc_html_e( 'Quality', 'tsh-whatsapp-notify' ); ?></dt>
							<dd id="tsh-wa-meta-quality">—</dd>
							<dt><?php esc_html_e( 'Usage Count', 'tsh-whatsapp-notify' ); ?></dt>
							<dd id="tsh-wa-meta-usage">—</dd>
							<dt><?php esc_html_e( 'Characters', 'tsh-whatsapp-notify' ); ?></dt>
							<dd id="tsh-wa-meta-chars">—</dd>
						</dl>
					</div>
				</div><!-- /.tsh-wa-preview-layout__left -->

				<!-- Right: Variable inspector + assignment panel -->
				<div class="tsh-wa-preview-layout__right">

					<!-- Variable inspector -->
					<div class="tsh-wa-variable-inspector" id="tsh-wa-variable-inspector-panel">
						<h3 class="tsh-wa-section-heading">
							<?php esc_html_e( 'Variable Inspector', 'tsh-whatsapp-notify' ); ?>
						</h3>

						<div id="tsh-wa-variable-inspector-body">
							<p class="tsh-wa-text--muted tsh-wa-text--small">
								<?php esc_html_e( 'No variables in this template.', 'tsh-whatsapp-notify' ); ?>
							</p>
						</div>

						<button type="button" class="button button-small" id="tsh-wa-btn-refresh-preview"
							data-nonce="<?php echo esc_attr( $nonce ); ?>">
							<?php esc_html_e( 'Refresh Preview', 'tsh-whatsapp-notify' ); ?>
						</button>
					</div>

					<!-- Assignment panel -->
					<div class="tsh-wa-assignment-panel" id="tsh-wa-assignment-panel">
						<h3 class="tsh-wa-section-heading">
							<?php esc_html_e( 'Assign to WooCommerce Event', 'tsh-whatsapp-notify' ); ?>
						</h3>

						<div class="tsh-wa-assignment-panel__row">
							<label class="tsh-wa-label">
								<?php esc_html_e( 'Event', 'tsh-whatsapp-notify' ); ?>
							</label>
							<select id="tsh-wa-assign-event" class="tsh-wa-select tsh-wa-select--full">
								<?php foreach ( $wc_events as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>

						<div class="tsh-wa-assignment-panel__row">
							<label class="tsh-wa-label">
								<?php esc_html_e( 'Recipient Type', 'tsh-whatsapp-notify' ); ?>
							</label>
							<div class="tsh-wa-radio-group">
								<label class="tsh-wa-radio-label">
									<input type="radio" name="tsh_wa_recipient_type" value="customer" checked />
									<?php esc_html_e( 'Customer', 'tsh-whatsapp-notify' ); ?>
								</label>
								<label class="tsh-wa-radio-label">
									<input type="radio" name="tsh_wa_recipient_type" value="admin" />
									<?php esc_html_e( 'Admin', 'tsh-whatsapp-notify' ); ?>
								</label>
							</div>
						</div>

						<div class="tsh-wa-assignment-panel__actions">
							<button type="button" class="button button-primary" id="tsh-wa-btn-save-assignment"
								data-nonce="<?php echo esc_attr( $nonce ); ?>">
								<?php esc_html_e( 'Assign Template', 'tsh-whatsapp-notify' ); ?>
							</button>
							<button type="button" class="button tsh-wa-btn--danger" id="tsh-wa-btn-remove-assignment"
								data-nonce="<?php echo esc_attr( $nonce ); ?>"
								style="display:none;">
								<?php esc_html_e( 'Remove Assignment', 'tsh-whatsapp-notify' ); ?>
							</button>
						</div>

						<div id="tsh-wa-assignment-result" class="tsh-wa-inline-notice" style="display:none;"></div>
					</div>

				</div><!-- /.tsh-wa-preview-layout__right -->

			</div><!-- /#tsh-wa-preview-content -->

			<!-- Error state -->
			<div class="tsh-wa-modal__error" id="tsh-wa-preview-error" style="display:none;">
				<span class="tsh-wa-text--danger" id="tsh-wa-preview-error-msg"></span>
			</div>

		</div><!-- /.tsh-wa-modal__body -->

		<!-- Modal footer -->
		<div class="tsh-wa-modal__footer">
			<button type="button" class="button" id="tsh-wa-preview-modal-close-footer">
				<?php esc_html_e( 'Close', 'tsh-whatsapp-notify' ); ?>
			</button>
		</div>

	</div><!-- /.tsh-wa-modal__container -->
</div><!-- /#tsh-wa-template-preview-modal -->

<!-- ============================================================
     Import Modal
     ============================================================ -->
<div id="tsh-wa-import-modal"
	class="tsh-wa-modal"
	role="dialog"
	aria-modal="true"
	aria-labelledby="tsh-wa-import-modal-title"
	style="display:none;">

	<div class="tsh-wa-modal__backdrop" id="tsh-wa-import-modal-backdrop"></div>

	<div class="tsh-wa-modal__container">
		<div class="tsh-wa-modal__header">
			<h2 class="tsh-wa-modal__title" id="tsh-wa-import-modal-title">
				<?php esc_html_e( 'Import Templates', 'tsh-whatsapp-notify' ); ?>
			</h2>
			<button type="button" class="tsh-wa-modal__close" id="tsh-wa-import-modal-close">✕</button>
		</div>

		<div class="tsh-wa-modal__body">
			<div class="tsh-wa-form-row">
				<label class="tsh-wa-label" for="tsh-wa-import-format">
					<?php esc_html_e( 'Format', 'tsh-whatsapp-notify' ); ?>
				</label>
				<select id="tsh-wa-import-format" class="tsh-wa-select">
					<option value="json">JSON</option>
					<option value="csv">CSV</option>
				</select>
			</div>

			<div class="tsh-wa-form-row">
				<label class="tsh-wa-label" for="tsh-wa-import-mode">
					<?php esc_html_e( 'Mode', 'tsh-whatsapp-notify' ); ?>
				</label>
				<select id="tsh-wa-import-mode" class="tsh-wa-select">
					<option value="merge"><?php esc_html_e( 'Merge (add + update)', 'tsh-whatsapp-notify' ); ?></option>
					<option value="skip"><?php esc_html_e( 'Skip duplicates', 'tsh-whatsapp-notify' ); ?></option>
					<option value="replace"><?php esc_html_e( 'Replace all (danger)', 'tsh-whatsapp-notify' ); ?></option>
				</select>
			</div>

			<div class="tsh-wa-form-row">
				<label class="tsh-wa-label" for="tsh-wa-import-data">
					<?php esc_html_e( 'Paste Data', 'tsh-whatsapp-notify' ); ?>
				</label>
				<textarea id="tsh-wa-import-data" class="tsh-wa-textarea tsh-wa-textarea--code" rows="10"
					placeholder="<?php esc_attr_e( 'Paste JSON or CSV content here…', 'tsh-whatsapp-notify' ); ?>"></textarea>
			</div>

			<div id="tsh-wa-import-result" class="tsh-wa-inline-notice" style="display:none;"></div>
		</div>

		<div class="tsh-wa-modal__footer">
			<button type="button" class="button button-primary" id="tsh-wa-btn-run-import"
				data-nonce="<?php echo esc_attr( $nonce ); ?>">
				<?php esc_html_e( 'Import', 'tsh-whatsapp-notify' ); ?>
			</button>
			<button type="button" class="button" id="tsh-wa-import-modal-close-footer">
				<?php esc_html_e( 'Cancel', 'tsh-whatsapp-notify' ); ?>
			</button>
		</div>
	</div>
</div>
