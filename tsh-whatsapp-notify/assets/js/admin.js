/**
 * TSH WhatsApp Notify — Admin JavaScript
 *
 * Vanilla JS + jQuery (WP-bundled). No build step required.
 * All behaviour is progressive-enhancement; pages work without JS.
 *
 * Phase 2 additions:
 *  - Connection verifier  (initConnectionTester)
 *  - Test message sender  (initTestMessageSender)
 *  - Message sandbox      (initMessageSandbox)
 *  - API diagnostics      (initDiagnostics)
 *  - Export / reset       (initApiSettingsActions)
 *  - Health refresh       (initHealthRefresh)
 *
 * @package TSH\WhatsAppNotify
 * @version 2.0.0
 */

/* global tshWaAdmin, jQuery */

( function ( $ ) {
	'use strict';

	// -------------------------------------------------------------------------
	// Initialise on DOM ready
	// -------------------------------------------------------------------------

	$( function () {
		TSHWaAdmin.init();
	} );

	// -------------------------------------------------------------------------
	// Main namespace
	// -------------------------------------------------------------------------

	window.TSHWaAdmin = {

		/**
		 * Initialise all module components.
		 */
		init: function () {
			this.initDismissNotices();
			this.initPasswordReveal();
			this.initConfirmForms();
			this.initTabMemory();
			this.initLogContextToggle();
			this.initCopyToClipboard();

			// Phase 2
			this.initConnectionTester();
			this.initTestMessageSender();
			this.initMessageSandbox();
			this.initDiagnostics();
			this.initApiSettingsActions();
			this.initHealthRefresh();

			// Phase 3 — WooCommerce order integration.
			this.initOrderMetaBox();
			this.initAdminRecipients();
		},

		// -----------------------------------------------------------------------
		// Dismiss admin notices (transient)
		// -----------------------------------------------------------------------

		initDismissNotices: function () {
			$( document ).on( 'click', '.tsh-wa-notice-dismiss', function () {
				var $notice = $( this ).closest( '.tsh-wa-notice' );
				$notice.fadeOut( 200, function () {
					$notice.remove();
				} );
			} );
		},

		// -----------------------------------------------------------------------
		// Password field show / hide toggle
		// -----------------------------------------------------------------------

		initPasswordReveal: function () {
			// Token field: button with data-target attribute.
			$( document ).on( 'click', '.tsh-wa-pw-toggle', function () {
				var $btn   = $( this );
				var target = $btn.data( 'target' );
				var $input = target
					? $( '#' + target )
					: $btn.siblings( 'input[type="password"], input[type="text"]' ).first();

				if ( ! $input.length ) {
					return;
				}

				var type = $input.attr( 'type' ) === 'password' ? 'text' : 'password';
				$input.attr( 'type', type );
				$btn.find( '.dashicons' )
					.toggleClass( 'dashicons-visibility', type === 'password' )
					.toggleClass( 'dashicons-hidden', type === 'text' );
			} );

			// Legacy: append toggle buttons next to every password input inside our
			// forms that don't already have one.
			$( '.tsh-wa-settings-form input[type="password"]' ).each( function () {
				var $input = $( this );

				// Skip if a sibling toggle button already exists.
				if ( $input.siblings( '.tsh-wa-pw-toggle' ).length ) {
					return;
				}

				var $btn = $( '<button type="button" class="button tsh-wa-pw-toggle" style="margin-left:6px;" aria-label="' + ( tshWaAdmin.i18n.show_password || 'Show/hide' ) + '">' +
					'<span class="dashicons dashicons-visibility"></span>' +
					'</button>' );

				$btn.insertAfter( $input );
			} );
		},

		// -----------------------------------------------------------------------
		// Confirmation dialogs
		// -----------------------------------------------------------------------

		/**
		 * Any submit button with data-tsh-wa-confirm="..." will prompt before submitting.
		 */
		initConfirmForms: function () {
			$( document ).on( 'click', '[data-tsh-wa-confirm]', function ( e ) {
				var message = $( this ).data( 'tsh-wa-confirm' ) || tshWaAdmin.i18n.confirm_clear;
				if ( ! window.confirm( message ) ) {
					e.preventDefault();
					e.stopPropagation();
					return false;
				}
			} );
		},

		// -----------------------------------------------------------------------
		// Remember active settings tab in sessionStorage
		// -----------------------------------------------------------------------

		initTabMemory: function () {
			var $activeTab = $( '.tsh-wa-tab-nav__item--active' );
			if ( $activeTab.length ) {
				$activeTab[0].scrollIntoView( { inline: 'nearest', block: 'nearest' } );
			}
		},

		// -----------------------------------------------------------------------
		// Log context <details> auto-close siblings
		// -----------------------------------------------------------------------

		initLogContextToggle: function () {
			$( document ).on( 'toggle', '.tsh-wa-log-context', function () {
				if ( this.open ) {
					$( '.tsh-wa-log-context[open]' ).not( this ).each( function () {
						this.open = false;
					} );
				}
			} );
		},

		// -----------------------------------------------------------------------
		// Copy to clipboard utility
		// -----------------------------------------------------------------------

		initCopyToClipboard: function () {
			$( document ).on( 'click', '[data-tsh-wa-copy]', function () {
				var target  = $( this ).data( 'tsh-wa-copy' );
				var $target = $( '#' + target );

				if ( ! $target.length ) { return; }

				var text = $target.val() || $target.text();
				if ( ! text ) { return; }

				if ( navigator.clipboard && window.isSecureContext ) {
					navigator.clipboard.writeText( text ).then( function () {
						TSHWaAdmin.showCopySuccess( $( '[data-tsh-wa-copy="' + target + '"]' ) );
					} );
				} else {
					var $tmp = $( '<textarea style="position:absolute;left:-9999px;">' + text + '</textarea>' );
					$( 'body' ).append( $tmp );
					$tmp.select();
					document.execCommand( 'copy' );
					$tmp.remove();
					TSHWaAdmin.showCopySuccess( $( '[data-tsh-wa-copy="' + target + '"]' ) );
				}
			} );
		},

		/**
		 * Briefly change copy button text to confirm success.
		 *
		 * @param {jQuery} $btn
		 */
		showCopySuccess: function ( $btn ) {
			var original = $btn.text();
			$btn.text( '✓ Copied' ).prop( 'disabled', true );
			setTimeout( function () {
				$btn.text( original ).prop( 'disabled', false );
			}, 1500 );
		},

		// -----------------------------------------------------------------------
		// PHASE 2 — Connection Tester
		// -----------------------------------------------------------------------

		initConnectionTester: function () {
			var $btn    = $( '#tsh-wa-btn-verify' );
			var $result = $( '#tsh-wa-verify-result' );
			var $steps  = $( '#tsh-wa-verify-steps' );
			var $list   = $( '#tsh-wa-steps-list' );
			var $info   = $( '#tsh-wa-conn-info' );
			var $badge  = $( '#tsh-wa-conn-status-badge' );

			if ( ! $btn.length ) { return; }

			$btn.on( 'click', function () {
				$btn.prop( 'disabled', true ).text( tshWaAdmin.i18n.verifying || 'Verifying…' );
				$result.hide().removeClass( 'tsh-wa-ajax-result--success tsh-wa-ajax-result--error' );
				$steps.hide();
				$list.empty();
				$info.hide().empty();

				TSHWaAdmin.ajax(
					'tsh_wa_verify_connection',
					{},
					function ( response ) {
						$btn.prop( 'disabled', false ).html( '<span class="dashicons dashicons-admin-network" style="vertical-align:middle;margin-top:-2px;"></span> ' + ( tshWaAdmin.i18n.verify_connection || 'Verify Connection' ) );

						if ( ! response.success ) {
							TSHWaAdmin.showAjaxResult( $result, false, tshWaAdmin.i18n.error || 'Request failed.' );
							return;
						}

						var data       = response.data;
						var connected  = data.connected;

						// Update badge.
						$badge
							.text( connected ? ( tshWaAdmin.i18n.connected || 'Connected' ) : ( tshWaAdmin.i18n.disconnected || 'Disconnected' ) )
							.css( 'background', connected ? 'var(--tsh-wa-green)' : 'var(--tsh-wa-red)' )
							.css( 'color', '#fff' );

						// Build step list.
						if ( data.steps && data.steps.length ) {
							$steps.show();
							$.each( data.steps, function ( i, step ) {
								var icon = 'ok' === step.status ? 'dashicons-yes-alt' : ( 'warning' === step.status ? 'dashicons-warning' : 'dashicons-dismiss' );
								var $li  = $(
									'<li class="tsh-wa-health-list__item tsh-wa-health-list__item--' + TSHWaAdmin.esc( step.status ) + '">' +
									'<span class="tsh-wa-health-list__icon"><span class="dashicons ' + icon + '"></span></span>' +
									'<span class="tsh-wa-health-list__label">' + TSHWaAdmin.esc( step.label ) + '</span>' +
									'<span class="tsh-wa-health-list__value tsh-wa-health-list__value--wrap">' + TSHWaAdmin.esc( step.detail ) + '</span>' +
									'</li>'
								);
								$list.append( $li );
							} );
						}

						// Phone / business info.
						if ( connected && data.phone_number ) {
							var infoHtml =
								'<table class="tsh-wa-conn-info-table">' +
								'<tr><td><strong>' + ( tshWaAdmin.i18n.phone_number || 'Phone' ) + ':</strong></td><td>' + TSHWaAdmin.esc( data.phone_number ) + '</td></tr>' +
								( data.display_name  ? '<tr><td><strong>' + ( tshWaAdmin.i18n.business || 'Business' ) + ':</strong></td><td>' + TSHWaAdmin.esc( data.display_name ) + '</td></tr>' : '' ) +
								( data.quality_rating ? '<tr><td><strong>' + ( tshWaAdmin.i18n.quality || 'Quality' ) + ':</strong></td><td>' + TSHWaAdmin.esc( data.quality_rating ) + '</td></tr>' : '' ) +
								( data.api_version   ? '<tr><td><strong>' + ( tshWaAdmin.i18n.api_version || 'API Version' ) + ':</strong></td><td>' + TSHWaAdmin.esc( data.api_version ) + '</td></tr>' : '' ) +
								'<tr><td><strong>' + ( tshWaAdmin.i18n.latency || 'Latency' ) + ':</strong></td><td>' + data.latency_ms + ' ms</td></tr>' +
								'</table>';
							$info.html( infoHtml ).show();
						}

					},
					function () {
						$btn.prop( 'disabled', false ).text( tshWaAdmin.i18n.verify_connection || 'Verify Connection' );
						TSHWaAdmin.showAjaxResult( $result, false, tshWaAdmin.i18n.error || 'Request failed.' );
					}
				);
			} );
		},

		// -----------------------------------------------------------------------
		// PHASE 2 — Test Message (settings page)
		// -----------------------------------------------------------------------

		initTestMessageSender: function () {
			var $btn     = $( '#tsh-wa-btn-send-test' );
			var $spinner = $( '#tsh-wa-send-spinner' );
			var $result  = $( '#tsh-wa-send-result' );

			if ( ! $btn.length ) { return; }

			$btn.on( 'click', function () {
				var phone   = $( '#tsh-wa-test-phone' ).val().trim();
				var message = $( '#tsh-wa-test-message' ).val().trim();

				if ( ! phone || ! message ) {
					TSHWaAdmin.showAjaxResult( $result, false, tshWaAdmin.i18n.fill_required || 'Phone and message are required.' );
					return;
				}

				$btn.prop( 'disabled', true );
				$spinner.css( 'visibility', 'visible' );
				$result.hide();

				TSHWaAdmin.ajax(
					'tsh_wa_send_test_message',
					{ phone: phone, message: message },
					function ( response ) {
						$btn.prop( 'disabled', false );
						$spinner.css( 'visibility', 'hidden' );

						if ( response.success ) {
							var d   = response.data;
							var msg = '✓ ' + d.message +
								( d.message_id ? ' — ID: ' + d.message_id : '' ) +
								' (' + d.latency_ms + ' ms)';
							TSHWaAdmin.showAjaxResult( $result, true, msg );
							if ( d.raw_body ) {
								$result.append( '<pre class="tsh-wa-code-block" style="margin-top:8px;">' + TSHWaAdmin.esc( d.raw_body ) + '</pre>' );
							}
						} else {
							var e   = response.data || {};
							var err = ( e.message || tshWaAdmin.i18n.error || 'Send failed.' ) +
								( e.http_status        ? ' [HTTP ' + e.http_status + ']' : '' ) +
								( e.meta_error_code    ? ' [Code: ' + e.meta_error_code + ']' : '' ) +
								( e.meta_error_message && e.meta_error_message !== e.message ? ' — ' + e.meta_error_message : '' ) +
								( e.latency_ms         ? ' (' + e.latency_ms + ' ms)' : '' );
							TSHWaAdmin.showAjaxResult( $result, false, err );
							if ( e.raw_body ) {
								$result.append( '<pre class="tsh-wa-code-block" style="margin-top:8px;">' + TSHWaAdmin.esc( e.raw_body ) + '</pre>' );
							}
						}
					},
					function () {
						$btn.prop( 'disabled', false );
						$spinner.css( 'visibility', 'hidden' );
						TSHWaAdmin.showAjaxResult( $result, false, tshWaAdmin.i18n.error || 'Request failed.' );
					}
				);
			} );
		},

		// -----------------------------------------------------------------------
		// PHASE 2 — Message Sandbox (tools page)
		// -----------------------------------------------------------------------

		initMessageSandbox: function () {
			var $btn     = $( '#tsh-wa-btn-sandbox-send' );
			var $spinner = $( '#tsh-wa-sandbox-spinner' );
			var $result  = $( '#tsh-wa-sandbox-result' );
			var $json    = $( '#tsh-wa-sandbox-json' );
			var $jsonBody = $( '#tsh-wa-sandbox-json-body' );
			var $charCount = $( '#tsh-wa-sandbox-char-count' );
			var $textarea  = $( '#tsh-wa-sandbox-message' );

			if ( ! $btn.length ) { return; }

			// Character counter.
			if ( $textarea.length && $charCount.length ) {
				$textarea.on( 'input', function () {
					var len = $( this ).val().length;
					$charCount.text( len + ' / 4096' );
					$charCount.css( 'color', len > 4096 ? 'var(--tsh-wa-red)' : '' );
				} );
				$textarea.trigger( 'input' );
			}

			$btn.on( 'click', function () {
				var phone   = $( '#tsh-wa-sandbox-phone' ).val().trim();
				var message = $textarea.val().trim();

				if ( ! phone || ! message ) {
					TSHWaAdmin.showAjaxResult( $result, false, tshWaAdmin.i18n.fill_required || 'Phone and message are required.' );
					return;
				}

				if ( message.length > 4096 ) {
					TSHWaAdmin.showAjaxResult( $result, false, tshWaAdmin.i18n.message_too_long || 'Message exceeds 4096 characters.' );
					return;
				}

				$btn.prop( 'disabled', true );
				$spinner.css( 'visibility', 'visible' );
				$result.hide();
				$json.hide();

				TSHWaAdmin.ajax(
					'tsh_wa_send_test_message',
					{ phone: phone, message: message },
					function ( response ) {
						$btn.prop( 'disabled', false );
						$spinner.css( 'visibility', 'hidden' );

						if ( response.success ) {
							var d   = response.data;
							var msg = '✓ ' + ( d.message || 'Message sent.' ) +
								( d.message_id ? '\nMessage ID: ' + d.message_id : '' ) +
								'\nLatency: ' + d.latency_ms + ' ms';
							TSHWaAdmin.showAjaxResult( $result, true, msg );
							if ( $json.length && d.raw_body ) {
								$jsonBody.text( d.raw_body );
								$json.show();
							}
						} else {
							var e   = response.data || {};
							var err = ( e.message || 'Send failed.' ) +
								( e.http_status     ? '\nHTTP Status: ' + e.http_status : '' ) +
								( e.meta_error_code ? '\nError Code: ' + e.meta_error_code : '' ) +
								( e.meta_error_message && e.meta_error_message !== e.message ? '\nError: ' + e.meta_error_message : '' ) +
								( e.latency_ms      ? '\nLatency: ' + e.latency_ms + ' ms' : '' ) +
								( e.retry           ? '\n(Retry recommended)' : '' );
							TSHWaAdmin.showAjaxResult( $result, false, err );
							if ( $json.length && e.raw_body ) {
								$jsonBody.text( e.raw_body );
								$json.show();
							}
						}
					},
					function () {
						$btn.prop( 'disabled', false );
						$spinner.css( 'visibility', 'hidden' );
						TSHWaAdmin.showAjaxResult( $result, false, tshWaAdmin.i18n.error || 'Request failed.' );
					}
				);
			} );
		},

		// -----------------------------------------------------------------------
		// PHASE 2 — Diagnostics
		// -----------------------------------------------------------------------

		initDiagnostics: function () {
			var $btn      = $( '#tsh-wa-btn-diagnostics' );
			var $spinner  = $( '#tsh-wa-diag-spinner' );
			var $result   = $( '#tsh-wa-diag-result' );
			var $grid     = $( '#tsh-wa-diag-grid' );
			var $download = $( '#tsh-wa-btn-download-report' );

			if ( ! $btn.length ) { return; }

			var lastReport = null;

			$btn.on( 'click', function () {
				$btn.prop( 'disabled', true );
				$spinner.css( 'visibility', 'visible' );
				$result.hide();
				$grid.empty();
				$download.hide();

				TSHWaAdmin.ajax(
					'tsh_wa_run_diagnostics',
					{},
					function ( response ) {
						$btn.prop( 'disabled', false );
						$spinner.css( 'visibility', 'hidden' );

						if ( ! response.success ) {
							$grid.html( '<p style="color:var(--tsh-wa-red);">' + TSHWaAdmin.esc( tshWaAdmin.i18n.error || 'Diagnostics failed.' ) + '</p>' );
							$result.show();
							return;
						}

						var data   = response.data;
						lastReport = data;

						$.each( data.checks, function ( key, check ) {
							var statusClass = 'tsh-wa-diag-card--' + ( check.status || 'ok' );
							var icon = 'ok' === check.status ? 'dashicons-yes-alt' : ( 'warning' === check.status ? 'dashicons-warning' : 'dashicons-dismiss' );
							var $card = $(
								'<div class="tsh-wa-diag-card ' + statusClass + '">' +
								'<div class="tsh-wa-diag-card__icon"><span class="dashicons ' + icon + '"></span></div>' +
								'<div class="tsh-wa-diag-card__body">' +
								'<strong class="tsh-wa-diag-card__label">' + TSHWaAdmin.esc( check.label ) + '</strong>' +
								'<span class="tsh-wa-diag-card__value">' + TSHWaAdmin.esc( check.value ) + '</span>' +
								( check.detail ? '<span class="tsh-wa-diag-card__detail">' + TSHWaAdmin.esc( check.detail ) + '</span>' : '' ) +
								'</div>' +
								'</div>'
							);
							$grid.append( $card );
						} );

						$result.show();
						$download.show();
					},
					function () {
						$btn.prop( 'disabled', false );
						$spinner.css( 'visibility', 'hidden' );
						$grid.html( '<p style="color:var(--tsh-wa-red);">' + TSHWaAdmin.esc( tshWaAdmin.i18n.error || 'Request failed.' ) + '</p>' );
						$result.show();
					}
				);
			} );

			// Download report as JSON file.
			$download.on( 'click', function () {
				if ( ! lastReport ) { return; }

				var json     = JSON.stringify( lastReport, null, 2 );
				var blob     = new Blob( [ json ], { type: 'application/json' } );
				var url      = URL.createObjectURL( blob );
				var $a       = $( '<a href="' + url + '" download="' + TSHWaAdmin.esc( lastReport.filename || 'tsh-wa-diagnostics.json' ) + '" style="display:none;"></a>' );
				$( 'body' ).append( $a );
				$a[0].click();
				$a.remove();
				setTimeout( function () { URL.revokeObjectURL( url ); }, 5000 );
			} );
		},

		// -----------------------------------------------------------------------
		// PHASE 2 — Export / Reset API Settings
		// -----------------------------------------------------------------------

		initApiSettingsActions: function () {
			// Export.
			$( '#tsh-wa-btn-export' ).on( 'click', function () {
				TSHWaAdmin.ajax(
					'tsh_wa_export_api_settings',
					{},
					function ( response ) {
						if ( ! response.success ) { return; }
						var d    = response.data;
						var json = JSON.stringify( d.settings, null, 2 );
						var blob = new Blob( [ json ], { type: 'application/json' } );
						var url  = URL.createObjectURL( blob );
						var $a   = $( '<a href="' + url + '" download="' + d.filename + '" style="display:none;"></a>' );
						$( 'body' ).append( $a );
						$a[0].click();
						$a.remove();
						setTimeout( function () { URL.revokeObjectURL( url ); }, 5000 );
					}
				);
			} );

			// Reset.
			$( '#tsh-wa-btn-reset' ).on( 'click', function () {
				// confirm is handled by initConfirmForms — but we also need direct handler.
				var confirmed = window.confirm(
					$( this ).data( 'tsh-wa-confirm' ) ||
					( tshWaAdmin.i18n.confirm_reset || 'Reset all API settings to defaults?' )
				);
				if ( ! confirmed ) { return; }

				var $btn = $( this );
				$btn.prop( 'disabled', true );

				TSHWaAdmin.ajax(
					'tsh_wa_reset_api_settings',
					{},
					function ( response ) {
						$btn.prop( 'disabled', false );
						if ( response.success ) {
							window.alert( response.data.message || 'Settings reset.' );
							window.location.reload();
						} else {
							window.alert( tshWaAdmin.i18n.error || 'Reset failed.' );
						}
					},
					function () {
						$btn.prop( 'disabled', false );
						window.alert( tshWaAdmin.i18n.error || 'Request failed.' );
					}
				);
			} );
		},

		// -----------------------------------------------------------------------
		// PHASE 2 — Health Refresh (dashboard & settings)
		// -----------------------------------------------------------------------

		initHealthRefresh: function () {
			$( document ).on( 'click', '#tsh-wa-refresh-health, #tsh-wa-btn-refresh-health', function () {
				var $btn = $( this );
				$btn.prop( 'disabled', true );

				TSHWaAdmin.ajax(
					'tsh_wa_refresh_health',
					{},
					function ( response ) {
						$btn.prop( 'disabled', false );
						if ( response.success ) {
							// Simple reload to refresh the dashboard panel.
							window.location.reload();
						} else {
							window.alert( tshWaAdmin.i18n.error || 'Refresh failed.' );
						}
					},
					function () {
						$btn.prop( 'disabled', false );
					}
				);
			} );
		},

		// -----------------------------------------------------------------------
		// PHASE 3 — Order Meta Box
		// -----------------------------------------------------------------------

		/**
		 * Initialise the WhatsApp meta box on WooCommerce order edit screens.
		 *
		 * Handles:
		 *  - Event selector → reload preview via AJAX
		 *  - Queue → Customer / Admin(s) buttons
		 *  - Resend All button
		 *  - Copy-to-clipboard for message previews
		 */
		initOrderMetaBox: function () {
			var $box = $( '#tsh-wa-metabox' );
			if ( ! $box.length ) { return; }

			var orderId = $box.data( 'order-id' );
			var nonce   = $box.data( 'nonce' );

			// ── Event selector: refresh preview ──────────────────────────────
			$box.on( 'change', '.tsh-wa-metabox__event-select', function () {
				var event  = $( this ).val();
				var $spin  = $box.find( '.tsh-wa-metabox__spinner' );
				var $cust  = $( '#tsh-wa-mb-customer-msg' );
				var $adm   = $( '#tsh-wa-mb-admin-msg' );

				$spin.css( 'visibility', 'visible' );

				TSHWaAdmin.ajax(
					'tsh_wa_get_order_preview',
					{ order_id: orderId, event_key: event, _ajax_nonce: nonce },
					function ( response ) {
						$spin.css( 'visibility', 'hidden' );
						if ( ! response.success ) { return; }
						var d = response.data;
						$cust.text( d.customer_message || '' );
						$adm.text( d.admin_message || '' );
						// Update char counts.
						$cust.closest( '.tsh-wa-mb-preview__body' )
							.find( '.tsh-wa-mb-char-count' )
							.text( ( d.customer_message || '' ).length + ' chars' );
						$adm.closest( '.tsh-wa-mb-preview__body' )
							.find( '.tsh-wa-mb-char-count' )
							.text( ( d.admin_message || '' ).length + ' chars' );
					},
					function () {
						$spin.css( 'visibility', 'hidden' );
					}
				);
			} );

			// ── Queue buttons ─────────────────────────────────────────────────
			$box.on( 'click', '.tsh-wa-mb-queue-btn', function () {
				var $btn      = $( this );
				var recipient = $btn.data( 'recipient' );
				var event     = $box.find( '.tsh-wa-metabox__event-select' ).val();
				var $result   = $( '#tsh-wa-mb-result' );

				$btn.prop( 'disabled', true );

				TSHWaAdmin.ajax(
					'tsh_wa_queue_order_notification',
					{ order_id: orderId, event_key: event, recipient_type: recipient, _ajax_nonce: nonce },
					function ( response ) {
						$btn.prop( 'disabled', false );
						var ok  = !! ( response && response.success );
						var msg = ok
							? ( ( response.data && response.data.message ) || 'Queued.' )
							: ( ( response.data && response.data.message ) || ( tshWaAdmin.i18n.error || 'Failed.' ) );
						TSHWaAdmin.showAjaxResult( $result, ok, msg );
					},
					function () {
						$btn.prop( 'disabled', false );
						TSHWaAdmin.showAjaxResult( $result, false, tshWaAdmin.i18n.error || 'Request failed.' );
					}
				);
			} );

			// ── Resend All button ─────────────────────────────────────────────
			$box.on( 'click', '.tsh-wa-mb-resend-btn', function () {
				var $btn    = $( this );
				var event   = $box.find( '.tsh-wa-metabox__event-select' ).val();
				var $result = $( '#tsh-wa-mb-result' );

				$btn.prop( 'disabled', true );

				TSHWaAdmin.ajax(
					'tsh_wa_resend_order_notification',
					{ order_id: orderId, event_key: event, _ajax_nonce: nonce },
					function ( response ) {
						$btn.prop( 'disabled', false );
						var ok  = !! ( response && response.success );
						var msg = ok
							? ( ( response.data && response.data.message ) || 'Requeued.' )
							: ( ( response.data && response.data.message ) || ( tshWaAdmin.i18n.error || 'Failed.' ) );
						TSHWaAdmin.showAjaxResult( $result, ok, msg );
					},
					function () {
						$btn.prop( 'disabled', false );
						TSHWaAdmin.showAjaxResult( $result, false, tshWaAdmin.i18n.error || 'Request failed.' );
					}
				);
			} );

			// ── Copy message to clipboard ─────────────────────────────────────
			$box.on( 'click', '.tsh-wa-mb-copy', function () {
				var target = $( this ).data( 'target' );
				var $pre   = $( '#' + target );
				if ( ! $pre.length ) { return; }

				var text = $pre.text();
				var $btn = $( this );

				if ( navigator.clipboard && window.isSecureContext ) {
					navigator.clipboard.writeText( text ).then( function () {
						TSHWaAdmin.showCopySuccess( $btn );
					} );
				} else {
					var $tmp = $( '<textarea style="position:absolute;left:-9999px;">' + text + '</textarea>' );
					$( 'body' ).append( $tmp );
					$tmp.select();
					document.execCommand( 'copy' );
					$tmp.remove();
					TSHWaAdmin.showCopySuccess( $btn );
				}
			} );
		},

		// -----------------------------------------------------------------------
		// PHASE 3 — Admin Recipients Management
		// -----------------------------------------------------------------------

		/**
		 * Handles the dynamic admin phone number list on the Settings page
		 * (admin_notifications tab). Adds / removes recipients via AJAX.
		 */
		initAdminRecipients: function () {
			var $container = $( '#tsh-wa-admin-recipients' );
			if ( ! $container.length ) { return; }

			// ── Add new recipient ─────────────────────────────────────────────
			$container.on( 'click', '#tsh-wa-btn-add-recipient', function () {
				var $btn    = $( this );
				var $phone  = $( '#tsh-wa-recipient-phone' );
				var $name   = $( '#tsh-wa-recipient-name' );
				var $result = $( '#tsh-wa-recipients-result' );
				var phone   = $.trim( $phone.val() );
				var name    = $.trim( $name.val() );

				if ( ! phone ) {
					TSHWaAdmin.showAjaxResult( $result, false, tshWaAdmin.i18n.fill_required || 'Phone number is required.' );
					return;
				}

				$btn.prop( 'disabled', true );

				// Read all current recipients from the hidden field.
				var existing = [];
				try {
					existing = JSON.parse( $( '#tsh-wa-recipients-json' ).val() || '[]' );
				} catch ( e ) {}

				existing.push( { id: Date.now(), phone: phone, name: name } );

				TSHWaAdmin.ajax(
					'tsh_wa_save_admin_recipients',
					{ recipients: JSON.stringify( existing ) },
					function ( response ) {
						$btn.prop( 'disabled', false );
						if ( response.success ) {
							$phone.val( '' );
							$name.val( '' );
							$( '#tsh-wa-recipients-json' ).val( JSON.stringify( response.data.recipients || existing ) );
							TSHWaAdmin.renderRecipients( $container, response.data.recipients || existing );
							TSHWaAdmin.showAjaxResult( $result, true, response.data.message || 'Saved.' );
						} else {
							TSHWaAdmin.showAjaxResult( $result, false, ( response.data && response.data.message ) || tshWaAdmin.i18n.error );
						}
					},
					function () {
						$btn.prop( 'disabled', false );
						TSHWaAdmin.showAjaxResult( $result, false, tshWaAdmin.i18n.error || 'Request failed.' );
					}
				);
			} );

			// ── Delete recipient ──────────────────────────────────────────────
			$container.on( 'click', '.tsh-wa-recipient-delete', function () {
				var id      = $( this ).data( 'id' );
				var $result = $( '#tsh-wa-recipients-result' );

				TSHWaAdmin.ajax(
					'tsh_wa_delete_admin_recipient',
					{ recipient_id: id },
					function ( response ) {
						if ( response.success ) {
							$( '#tsh-wa-recipients-json' ).val( JSON.stringify( response.data.recipients || [] ) );
							TSHWaAdmin.renderRecipients( $container, response.data.recipients || [] );
							TSHWaAdmin.showAjaxResult( $result, true, response.data.message || 'Deleted.' );
						} else {
							TSHWaAdmin.showAjaxResult( $result, false, ( response.data && response.data.message ) || tshWaAdmin.i18n.error );
						}
					}
				);
			} );
		},

		/**
		 * Re-render the recipients list.
		 *
		 * @param {jQuery} $container
		 * @param {Array}  recipients
		 */
		renderRecipients: function ( $container, recipients ) {
			var $list = $container.find( '#tsh-wa-recipients-list' );
			$list.empty();

			if ( ! recipients || ! recipients.length ) {
				$list.html( '<li class="tsh-wa-recipients-empty">' + TSHWaAdmin.esc( 'No admin recipients configured.' ) + '</li>' );
				return;
			}

			$.each( recipients, function ( i, r ) {
				var $li = $(
					'<li class="tsh-wa-recipients-item">' +
					'<span class="tsh-wa-recipients-item__phone"><code>' + TSHWaAdmin.esc( r.phone || '' ) + '</code></span>' +
					( r.name ? '<span class="tsh-wa-recipients-item__name">' + TSHWaAdmin.esc( r.name ) + '</span>' : '' ) +
					'<button type="button" class="button button-small tsh-wa-recipient-delete" data-id="' + TSHWaAdmin.esc( r.id ) + '">' +
					'<span class="dashicons dashicons-trash" aria-hidden="true"></span> Remove' +
					'</button>' +
					'</li>'
				);
				$list.append( $li );
			} );
		},

		// -----------------------------------------------------------------------
		// Utility: show an AJAX result notice
		// -----------------------------------------------------------------------

		/**
		 * Show a success or error message in an .tsh-wa-ajax-result container.
		 *
		 * @param {jQuery}  $container Target element.
		 * @param {boolean} success    True = success (green), false = error (red).
		 * @param {string}  message    Plain-text message (will be HTML-escaped).
		 */
		showAjaxResult: function ( $container, success, message ) {
			$container
				.removeClass( 'tsh-wa-ajax-result--success tsh-wa-ajax-result--error' )
				.addClass( success ? 'tsh-wa-ajax-result--success' : 'tsh-wa-ajax-result--error' )
				.html( '<pre style="margin:0;white-space:pre-wrap;word-break:break-all;">' + TSHWaAdmin.esc( message ) + '</pre>' )
				.show();
		},

		// -----------------------------------------------------------------------
		// Utility: HTML-escape a string
		// -----------------------------------------------------------------------

		esc: function ( str ) {
			return String( str )
				.replace( /&/g, '&amp;' )
				.replace( /</g, '&lt;' )
				.replace( />/g, '&gt;' )
				.replace( /"/g, '&quot;' )
				.replace( /'/g, '&#039;' );
		},

		// -----------------------------------------------------------------------
		// AJAX helper (shared)
		// -----------------------------------------------------------------------

		/**
		 * Send an admin-ajax request with automatic nonce inclusion.
		 *
		 * @param {string}   action    WP AJAX action name.
		 * @param {Object}   data      Additional POST data.
		 * @param {Function} callback  Receives the parsed response object.
		 * @param {Function} [onError] Optional error callback.
		 */
		ajax: function ( action, data, callback, onError ) {
			$.ajax( {
				url:    tshWaAdmin.ajaxUrl,
				method: 'POST',
				data:   $.extend( {}, data, {
					action:      action,
					_ajax_nonce: tshWaAdmin.nonce,
				} ),
				success: function ( response ) {
					if ( typeof callback === 'function' ) {
						callback( response );
					}
				},
				error: function ( xhr, status, error ) {
					if ( typeof onError === 'function' ) {
						onError( xhr, status, error );
					} else {
						window.console && console.error( '[TSH WA] AJAX error:', status, error );
					}
				},
			} );
		},

	};

	// ===========================================================================
	// Phase 4 — Queue Dashboard: live stats refresh + AJAX controls
	// ===========================================================================

	/**
	 * initQueueDashboard
	 *
	 * Polls `tsh_wa_get_queue_stats` every 30 seconds while the admin is on the
	 * Queue page.  Updates stat cards, performance metrics, and the health grid
	 * in-place without a full page reload.
	 */
	TshWaAdmin.initQueueDashboard = function () {
		var $wrap = $( '.tsh-wa-wrap' );

		// Only activate on the Queue admin page.
		if ( ! $wrap.length || ! $( '#tsh-wa-queue-stats' ).length ) {
			return;
		}

		var POLL_INTERVAL = 30000; // 30 seconds
		var pollTimer;

		// -------------------------------------------------------------------
		// Stat-card live update
		// -------------------------------------------------------------------

		function refreshStats() {
			$( '#tsh-wa-queue-stats' ).addClass( 'tsh-wa-stats-refreshing' );

			TshWaAdmin.ajax(
				'tsh_wa_get_queue_stats',
				{},
				function ( response ) {
					$( '#tsh-wa-queue-stats' ).removeClass( 'tsh-wa-stats-refreshing' );

					if ( ! response.success || ! response.data ) {
						return;
					}

					var d = response.data;

					// Update stat card values by data-stat attribute.
					updateStatCard( 'pending',    d.counts && d.counts.pending    ? d.counts.pending    : 0 );
					updateStatCard( 'processing', d.counts && d.counts.processing ? d.counts.processing : 0 );
					updateStatCard( 'retrying',   d.retrying   || 0 );
					updateStatCard( 'sent_today', d.sent_today || 0 );
					updateStatCard( 'dead_letter',d.dead_letter || 0 );
					updateStatCard( 'throughput', d.throughput_hour || 0 );

					// Update performance metric cells.
					var latency = d.avg_latency_ms > 0
						? TshWaAdmin.formatNumber( d.avg_latency_ms, 0 ) + ' ms'
						: '–';
					$( '#tsh-wa-metric-latency' ).text( latency );

					var proc = d.avg_process_ms > 0
						? TshWaAdmin.formatNumber( d.avg_process_ms, 0 ) + ' ms'
						: '–';
					$( '#tsh-wa-metric-process' ).text( proc );

					// Pause / resume button visibility.
					var $pauseBtn  = $( '[name="tsh_wa_action"][value="pause_queue"]' );
					var $resumeBtn = $( '[name="tsh_wa_action"][value="resume_queue"]' );
					if ( d.is_paused ) {
						$pauseBtn.closest( 'form' ).hide();
						$resumeBtn.closest( 'form' ).show();
					} else {
						$pauseBtn.closest( 'form' ).show();
						$resumeBtn.closest( 'form' ).hide();
					}
				},
				function () {
					$( '#tsh-wa-queue-stats' ).removeClass( 'tsh-wa-stats-refreshing' );
				}
			);
		}

		// Map stat position indices to data keys (matches card order in template).
		var STAT_KEYS = [ 'pending', 'processing', 'retrying', 'sent_today', 'dead_letter', 'throughput' ];

		function updateStatCard( key, value ) {
			var idx = STAT_KEYS.indexOf( key );
			if ( idx < 0 ) { return; }
			var $card = $( '#tsh-wa-queue-stats .tsh-wa-stat-card' ).eq( idx );
			if ( $card.length ) {
				$card.find( '.tsh-wa-stat-card__value' ).text( formatCount( value ) );
			}
		}

		function formatCount( n ) {
			return parseInt( n, 10 ).toLocaleString();
		}

		// -------------------------------------------------------------------
		// AJAX manual controls (progressive enhancement — forms work without JS)
		// -------------------------------------------------------------------

		// "Process Now" button — fire AJAX, show spinner, refresh stats when done.
		$( document ).on( 'click', '[name="tsh_wa_action"][value="process_now"]', function ( e ) {
			e.preventDefault();

			var $btn = $( this );
			var origText = $btn.html();
			$btn.html( '<span class="dashicons dashicons-update tsh-wa-spin"></span> ' +
				( tshWaAdmin.i18n && tshWaAdmin.i18n.processing ? tshWaAdmin.i18n.processing : 'Processing…' ) );
			$btn.prop( 'disabled', true );

			TshWaAdmin.ajax(
				'tsh_wa_queue_process_now',
				{},
				function ( response ) {
					$btn.html( origText );
					$btn.prop( 'disabled', false );

					if ( response.success ) {
						TshWaAdmin.showNotice( response.data.message || 'Done.', 'success' );
						refreshStats();
					} else {
						TshWaAdmin.showNotice( ( response.data && response.data.message ) || 'Error.', 'error' );
					}
				},
				function () {
					$btn.html( origText );
					$btn.prop( 'disabled', false );
				}
			);
		} );

		// "Pause Queue" / "Resume Queue" buttons — AJAX toggles.
		$( document ).on( 'click', '[name="tsh_wa_action"][value="pause_queue"],' +
			'[name="tsh_wa_action"][value="resume_queue"]', function ( e ) {

			e.preventDefault();

			var action = $( this ).val() === 'pause_queue'
				? 'tsh_wa_queue_pause'
				: 'tsh_wa_queue_resume';

			TshWaAdmin.ajax(
				action,
				{},
				function ( response ) {
					if ( response.success ) {
						TshWaAdmin.showNotice( response.data.message, 'success' );
						refreshStats();
					}
				}
			);
		} );

		// -------------------------------------------------------------------
		// Dead Letter Queue AJAX actions (DLQ retry / delete)
		// -------------------------------------------------------------------

		$( document ).on( 'click', '.tsh-wa-dlq-retry-btn', function ( e ) {
			e.preventDefault();

			var $btn  = $( this );
			var qid   = $btn.data( 'queue-id' );

			TshWaAdmin.ajax(
				'tsh_wa_dlq_retry',
				{ queue_id: qid },
				function ( response ) {
					if ( response.success ) {
						$btn.closest( 'tr' ).fadeOut( 300, function () { $( this ).remove(); } );
						TshWaAdmin.showNotice( response.data.message, 'success' );
						refreshStats();
					} else {
						TshWaAdmin.showNotice( ( response.data && response.data.message ) || 'Error.', 'error' );
					}
				}
			);
		} );

		$( document ).on( 'click', '.tsh-wa-dlq-delete-btn', function ( e ) {
			e.preventDefault();

			if ( ! window.confirm( tshWaAdmin.i18n && tshWaAdmin.i18n.confirmDelete
				? tshWaAdmin.i18n.confirmDelete
				: 'Delete this item permanently?' ) ) {
				return;
			}

			var $btn = $( this );
			var qid  = $btn.data( 'queue-id' );

			TshWaAdmin.ajax(
				'tsh_wa_dlq_delete',
				{ queue_id: qid },
				function ( response ) {
					if ( response.success ) {
						$btn.closest( 'tr' ).fadeOut( 300, function () { $( this ).remove(); } );
						TshWaAdmin.showNotice( response.data.message, 'success' );
						refreshStats();
					} else {
						TshWaAdmin.showNotice( ( response.data && response.data.message ) || 'Error.', 'error' );
					}
				}
			);
		} );

		// -------------------------------------------------------------------
		// Auto-polling
		// -------------------------------------------------------------------

		// Initial poll after 5 seconds (page just loaded, don't hammer immediately).
		setTimeout( function () {
			refreshStats();
			pollTimer = setInterval( refreshStats, POLL_INTERVAL );
		}, 5000 );

		// Stop polling if the page is hidden (tab switching).
		document.addEventListener( 'visibilitychange', function () {
			if ( document.hidden ) {
				clearInterval( pollTimer );
			} else {
				refreshStats();
				pollTimer = setInterval( refreshStats, POLL_INTERVAL );
			}
		} );

		// -------------------------------------------------------------------
		// Utility: show a transient admin notice
		// -------------------------------------------------------------------

		TshWaAdmin.showNotice = TshWaAdmin.showNotice || function ( message, type ) {
			var cls  = 'notice-' + ( type || 'info' );
			var $n   = $( '<div class="notice ' + cls + ' is-dismissible tsh-wa-ajax-notice"><p></p></div>' );
			$n.find( 'p' ).text( message );
			$( '.tsh-wa-wrap .tsh-wa-page-header' ).after( $n );

			setTimeout( function () {
				$n.fadeOut( 400, function () { $( this ).remove(); } );
			}, 4000 );
		};

		TshWaAdmin.formatNumber = TshWaAdmin.formatNumber || function ( num, decimals ) {
			return parseFloat( num ).toFixed( decimals || 0 );
		};
	};

	// Auto-init on DOM ready.
	$( function () {
		TshWaAdmin.initQueueDashboard();
	} );

} )( jQuery );
