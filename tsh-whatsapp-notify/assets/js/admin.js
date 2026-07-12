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

/* ==========================================================================
   Phase 5 — Template Manager Modules
   ========================================================================== */

// -------------------------------------------------------------------------
// Template Manager — main orchestrator module
// -------------------------------------------------------------------------

window.TshWaAdmin = window.TshWaAdmin || {};

TshWaAdmin.initTemplateManager = function () {
	if ( ! document.getElementById( 'tsh-wa-btn-sync-templates' ) ) return;

	// Sync Now button.
	$( '#tsh-wa-btn-sync-templates, #tsh-wa-btn-sync-templates-empty' ).on( 'click', function () {
		TshWaAdmin.runTemplateSync( 'tsh_wa_sync_templates', $( this ) );
	} );

	// Full Reset Sync button.
	$( '#tsh-wa-btn-force-full-sync' ).on( 'click', function () {
		var confirmMsg = $( this ).data( 'confirm' ) || tshWaAdmin.i18n.confirm_full_sync;
		if ( ! window.confirm( confirmMsg ) ) return;
		TshWaAdmin.runTemplateSync( 'tsh_wa_force_full_sync', $( this ) );
	} );

	// Flush cache button.
	$( '#tsh-wa-btn-flush-cache' ).on( 'click', function () {
		var $btn = $( this );
		$btn.prop( 'disabled', true ).text( '…' );
		$.post( tshWaAdmin.ajaxUrl, {
			action:      'tsh_wa_flush_template_cache',
			_ajax_nonce: $btn.data( 'nonce' ) || tshWaAdmin.nonce,
		}, function ( resp ) {
			TshWaAdmin.showNotice( resp.success
				? ( resp.data.message || tshWaAdmin.i18n.cache_flushed )
				: tshWaAdmin.i18n.error, resp.success ? 'success' : 'error' );
		} ).always( function () {
			$btn.prop( 'disabled', false ).text( tshWaAdmin.i18n ? 'Flush Cache' : 'Flush Cache' );
		} );
	} );

	// Row preview buttons.
	$( document ).on( 'click', '.tsh-wa-btn-preview-template', function () {
		var templateId = $( this ).data( 'template-id' );
		TshWaAdmin.openPreviewModal( templateId );
	} );

	// Row assign buttons.
	$( document ).on( 'click', '.tsh-wa-btn-assign-template', function () {
		var templateId   = $( this ).data( 'template-id' );
		var templateName = $( this ).data( 'template-name' );
		TshWaAdmin.openPreviewModal( templateId, templateName, true );
	} );

	// Import button.
	$( '#tsh-wa-btn-import-templates' ).on( 'click', function () {
		TshWaAdmin.openImportModal();
	} );

	// Export button.
	$( '#tsh-wa-btn-export-templates' ).on( 'click', function () {
		TshWaAdmin.runTemplateExport( $( this ) );
	} );
};

// Run a template sync (manual or full).
TshWaAdmin.runTemplateSync = function ( action, $btn ) {
	var nonce    = $btn.data( 'nonce' ) || tshWaAdmin.nonce;
	var origText = $btn.text();

	$btn.prop( 'disabled', true ).text( tshWaAdmin.i18n.syncing || 'Syncing…' );
	$( '#tsh-wa-sync-status-pill' ).text( tshWaAdmin.i18n.syncing || 'Syncing…' );

	$.post( tshWaAdmin.ajaxUrl, { action: action, _ajax_nonce: nonce }, function ( resp ) {
		if ( resp.success ) {
			var stats   = resp.data.stats || {};
			var message = resp.data.message || tshWaAdmin.i18n.sync_complete;
			TshWaAdmin.showNotice( message, 'success' );
			$( '#tsh-wa-sync-status-pill' ).text( 'Just now' );

			// Refresh the page to show the updated table.
			setTimeout( function () { window.location.reload(); }, 1200 );
		} else {
			TshWaAdmin.showNotice( resp.data.message || tshWaAdmin.i18n.sync_error, 'error' );
			$( '#tsh-wa-sync-status-pill' ).text( 'Sync failed' );
		}
	} ).fail( function () {
		TshWaAdmin.showNotice( tshWaAdmin.i18n.error, 'error' );
	} ).always( function () {
		$btn.prop( 'disabled', false ).text( origText );
	} );
};

// Export templates (download file).
TshWaAdmin.runTemplateExport = function ( $btn ) {
	var nonce = $btn.data( 'nonce' ) || tshWaAdmin.nonce;

	$btn.prop( 'disabled', true );

	$.post( tshWaAdmin.ajaxUrl, {
		action:      'tsh_wa_export_templates',
		format:      'json',
		_ajax_nonce: nonce,
	}, function ( resp ) {
		if ( resp.success && resp.data.data ) {
			var blob     = new Blob( [ resp.data.data ], { type: 'application/json' } );
			var url      = URL.createObjectURL( blob );
			var link     = document.createElement( 'a' );
			link.href     = url;
			link.download = resp.data.filename || 'tsh-wa-templates.json';
			document.body.appendChild( link );
			link.click();
			document.body.removeChild( link );
			URL.revokeObjectURL( url );
		} else {
			TshWaAdmin.showNotice( tshWaAdmin.i18n.error, 'error' );
		}
	} ).always( function () {
		$btn.prop( 'disabled', false );
	} );
};

// -------------------------------------------------------------------------
// Template Preview Modal
// -------------------------------------------------------------------------

TshWaAdmin.initTemplatePreviewModal = function () {
	$( document )
		.on( 'click', '#tsh-wa-preview-modal-close, #tsh-wa-preview-modal-close-footer', function () {
			TshWaAdmin.closePreviewModal();
		} )
		.on( 'click', '#tsh-wa-preview-modal-backdrop', function () {
			TshWaAdmin.closePreviewModal();
		} )
		.on( 'keydown', function ( e ) {
			if ( 27 === e.keyCode && $( '#tsh-wa-template-preview-modal' ).is( ':visible' ) ) {
				TshWaAdmin.closePreviewModal();
			}
		} )
		.on( 'click', '#tsh-wa-btn-refresh-preview', function () {
			var id = $( '#tsh-wa-template-preview-modal' ).data( 'template-id' );
			if ( id ) TshWaAdmin.loadPreviewData( id );
		} );
};

TshWaAdmin.openPreviewModal = function ( templateId, templateName, scrollToAssign ) {
	var $modal = $( '#tsh-wa-template-preview-modal' );
	$modal.data( 'template-id', templateId ).fadeIn( 150 );
	$( 'body' ).addClass( 'tsh-wa-modal-open' );

	if ( templateName ) {
		$( '#tsh-wa-preview-template-name' ).text( '— ' + templateName );
	}

	TshWaAdmin.loadPreviewData( templateId );

	if ( scrollToAssign ) {
		setTimeout( function () {
			$( '#tsh-wa-assignment-panel' )[0].scrollIntoView( { behavior: 'smooth' } );
		}, 400 );
	}
};

TshWaAdmin.closePreviewModal = function () {
	$( '#tsh-wa-template-preview-modal' ).fadeOut( 120 );
	$( 'body' ).removeClass( 'tsh-wa-modal-open' );
	$( '#tsh-wa-assignment-result' ).hide();
};

TshWaAdmin.loadPreviewData = function ( templateId ) {
	var $modal   = $( '#tsh-wa-template-preview-modal' );
	var $loading = $( '#tsh-wa-preview-loading' );
	var $content = $( '#tsh-wa-preview-content' );
	var $error   = $( '#tsh-wa-preview-error' );

	// Collect current variable values.
	var variables = {};
	$modal.find( '.tsh-wa-variable-input' ).each( function () {
		variables[ $( this ).data( 'var-num' ) ] = $( this ).val();
	} );

	$loading.show();
	$content.hide();
	$error.hide();

	$.post( tshWaAdmin.ajaxUrl, {
		action:      'tsh_wa_get_template_preview',
		template_id: templateId,
		variables:   variables,
		_ajax_nonce: tshWaAdmin.nonce,
	}, function ( resp ) {
		$loading.hide();
		if ( resp.success ) {
			TshWaAdmin.renderPreviewData( resp.data );
			$content.show();
		} else {
			$( '#tsh-wa-preview-error-msg' ).text( resp.data.message || tshWaAdmin.i18n.error );
			$error.show();
		}
	} ).fail( function () {
		$loading.hide();
		$( '#tsh-wa-preview-error-msg' ).text( tshWaAdmin.i18n.error );
		$error.show();
	} );
};

TshWaAdmin.renderPreviewData = function ( data ) {
	// Name.
	$( '#tsh-wa-preview-template-name' ).text( '— ' + ( data.template_name || '' ) );

	// Header.
	var $headerArea = $( '#tsh-wa-preview-header-area' );
	$headerArea.empty();
	if ( data.header && data.header.type === 'TEXT' ) {
		$headerArea.text( data.header.text || '' );
	} else if ( data.header && data.header.type ) {
		$headerArea.html( '<span class="tsh-wa-badge tsh-wa-badge--grey">[' + data.header.type + ']</span>' );
	}

	// Body.
	$( '#tsh-wa-preview-body-text' ).text( data.body_rendered || data.body || '' );

	// Footer.
	var $footer = $( '#tsh-wa-preview-footer-text' );
	data.footer ? $footer.text( data.footer ).show() : $footer.hide();

	// Buttons.
	var $btns = $( '#tsh-wa-preview-buttons-area' ).empty();
	if ( data.buttons && data.buttons.length ) {
		$.each( data.buttons, function ( i, btn ) {
			var cls = 'tsh-wa-preview-button';
			if ( 'URL' === btn.type )          cls += ' tsh-wa-preview-button--url';
			if ( 'PHONE_NUMBER' === btn.type )  cls += ' tsh-wa-preview-button--phone';
			if ( 'COPY_CODE' === btn.type )     cls += ' tsh-wa-preview-button--copy';
			$btns.append( '<div class="' + cls + '">' + $( '<span>' ).text( btn.text || btn.type ).html() + '</div>' );
		} );
	}

	// Meta.
	$( '#tsh-wa-meta-category' ).text( data.category || '—' );
	$( '#tsh-wa-meta-language' ).text( data.language  || '—' );
	$( '#tsh-wa-meta-status' ).text( data.status       || '—' );
	$( '#tsh-wa-meta-quality' ).text( data.quality_score || '—' );
	$( '#tsh-wa-meta-usage' ).text( ( data.usage_count || 0 ).toLocaleString() );
	$( '#tsh-wa-meta-chars' ).text( ( data.char_count  || 0 ).toLocaleString() );

	// Variable inspector.
	var $inspector = $( '#tsh-wa-variable-inspector-body' ).empty();
	if ( data.variable_map && data.variable_map.length ) {
		$.each( data.variable_map, function ( i, v ) {
			var $row = $( '<div class="tsh-wa-variable-row"></div>' );
			$row.append( '<div class="tsh-wa-variable-row__label">{{' + v.number + '}}</div>' );
			var $input = $( '<input type="text" class="tsh-wa-variable-input tsh-wa-variable-row__input" />' )
				.attr( 'data-var-num', v.number )
				.attr( 'placeholder', v.example || '' )
				.val( v.value || '' );
			$row.append( $input );
			if ( v.wc_field ) {
				$row.append( '<div class="tsh-wa-variable-row__wc-field">WC: ' + v.wc_field + '</div>' );
			}
			$inspector.append( $row );
		} );
		// Live refresh on variable input.
		$inspector.find( '.tsh-wa-variable-input' ).on( 'input', TshWaAdmin.debounce( function () {
			TshWaAdmin.loadPreviewData( $( '#tsh-wa-template-preview-modal' ).data( 'template-id' ) );
		}, 600 ) );
	} else {
		$inspector.html( '<p class="tsh-wa-text--muted tsh-wa-text--small">' + ( tshWaAdmin.i18n.no_variables || 'No variables.' ) + '</p>' );
	}
};

// -------------------------------------------------------------------------
// Template Assignment Module
// -------------------------------------------------------------------------

TshWaAdmin.initTemplateAssignment = function () {
	if ( ! document.getElementById( 'tsh-wa-btn-save-assignment' ) ) return;

	$( '#tsh-wa-btn-save-assignment' ).on( 'click', function () {
		var templateId     = $( '#tsh-wa-template-preview-modal' ).data( 'template-id' );
		var event          = $( '#tsh-wa-assign-event' ).val();
		var recipientType  = $( 'input[name="tsh_wa_recipient_type"]:checked' ).val() || 'customer';
		var nonce          = $( this ).data( 'nonce' ) || tshWaAdmin.nonce;
		var $result        = $( '#tsh-wa-assignment-result' );

		if ( ! event ) {
			TshWaAdmin.showAssignmentResult( tshWaAdmin.i18n.select_event || 'Select an event.', 'error' );
			return;
		}

		var $btn = $( this ).prop( 'disabled', true );

		$.post( tshWaAdmin.ajaxUrl, {
			action:         'tsh_wa_assign_template',
			template_id:    templateId,
			event:          event,
			recipient_type: recipientType,
			_ajax_nonce:    nonce,
		}, function ( resp ) {
			var msg = resp.success
				? ( resp.data.message || tshWaAdmin.i18n.template_assigned )
				: ( resp.data.message || tshWaAdmin.i18n.error );
			TshWaAdmin.showAssignmentResult( msg, resp.success ? 'success' : 'error' );
			if ( resp.success ) {
				$( '#tsh-wa-btn-remove-assignment' ).show();
			}
		} ).always( function () {
			$btn.prop( 'disabled', false );
		} );
	} );

	$( '#tsh-wa-btn-remove-assignment' ).on( 'click', function () {
		var event         = $( '#tsh-wa-assign-event' ).val();
		var recipientType = $( 'input[name="tsh_wa_recipient_type"]:checked' ).val() || 'customer';
		var nonce         = $( this ).data( 'nonce' ) || tshWaAdmin.nonce;

		$.post( tshWaAdmin.ajaxUrl, {
			action:         'tsh_wa_unassign_template',
			event:          event,
			recipient_type: recipientType,
			_ajax_nonce:    nonce,
		}, function ( resp ) {
			var msg = resp.success
				? ( resp.data.message || tshWaAdmin.i18n.template_unassigned )
				: ( resp.data.message || tshWaAdmin.i18n.error );
			TshWaAdmin.showAssignmentResult( msg, resp.success ? 'success' : 'error' );
			if ( resp.success ) {
				$( '#tsh-wa-btn-remove-assignment' ).hide();
			}
		} );
	} );
};

TshWaAdmin.showAssignmentResult = function ( msg, type ) {
	var $el = $( '#tsh-wa-assignment-result' );
	$el.text( msg )
		.removeClass( 'tsh-wa-inline-notice--success tsh-wa-inline-notice--error tsh-wa-inline-notice--warning' )
		.addClass( 'tsh-wa-inline-notice tsh-wa-inline-notice--' + type )
		.show();
};

// -------------------------------------------------------------------------
// Template Search Module
// -------------------------------------------------------------------------

TshWaAdmin.initTemplateSearch = function () {
	var $searchInput = $( '#tsh-wa-template-search' );
	if ( ! $searchInput.length ) return;

	$searchInput.on( 'input', TshWaAdmin.debounce( function () {
		$searchInput.closest( 'form' ).submit();
	}, 600 ) );
};

// -------------------------------------------------------------------------
// Import Modal
// -------------------------------------------------------------------------

TshWaAdmin.initTemplateImportModal = function () {
	if ( ! document.getElementById( 'tsh-wa-import-modal' ) ) return;

	$( document )
		.on( 'click', '#tsh-wa-import-modal-close, #tsh-wa-import-modal-close-footer', function () {
			$( '#tsh-wa-import-modal' ).fadeOut( 120 );
		} )
		.on( 'click', '#tsh-wa-import-modal-backdrop', function () {
			$( '#tsh-wa-import-modal' ).fadeOut( 120 );
		} );
};

TshWaAdmin.openImportModal = function () {
	$( '#tsh-wa-import-result' ).hide();
	$( '#tsh-wa-import-data' ).val( '' );
	$( '#tsh-wa-import-modal' ).fadeIn( 150 );
};

$( document ).on( 'click', '#tsh-wa-btn-run-import', function () {
	var nonce  = $( this ).data( 'nonce' ) || tshWaAdmin.nonce;
	var format = $( '#tsh-wa-import-format' ).val();
	var mode   = $( '#tsh-wa-import-mode' ).val();
	var data   = $( '#tsh-wa-import-data' ).val();
	var $btn   = $( this ).prop( 'disabled', true );

	if ( ! data.trim() ) {
		TshWaAdmin.showInlineNotice( '#tsh-wa-import-result', 'Please paste your import data.', 'error' );
		$btn.prop( 'disabled', false );
		return;
	}

	$.post( tshWaAdmin.ajaxUrl, {
		action:      'tsh_wa_import_templates',
		format:      format,
		mode:        mode,
		data:        data,
		_ajax_nonce: nonce,
	}, function ( resp ) {
		if ( resp.success ) {
			var stats = resp.data;
			var msg   = 'Imported: ' + stats.imported + ' | Skipped: ' + stats.skipped + ' | Errors: ' + stats.errors;
			TshWaAdmin.showInlineNotice( '#tsh-wa-import-result', msg, 'success' );
			setTimeout( function () { window.location.reload(); }, 1500 );
		} else {
			var errMsg = resp.data.messages ? resp.data.messages.join( ' ' ) : tshWaAdmin.i18n.error;
			TshWaAdmin.showInlineNotice( '#tsh-wa-import-result', errMsg, 'error' );
		}
	} ).always( function () {
		$btn.prop( 'disabled', false );
	} );
} );

TshWaAdmin.showInlineNotice = function ( selector, msg, type ) {
	var $el = $( selector );
	$el.text( msg )
		.removeClass( 'tsh-wa-inline-notice--success tsh-wa-inline-notice--error tsh-wa-inline-notice--warning' )
		.addClass( 'tsh-wa-inline-notice tsh-wa-inline-notice--' + type )
		.show();
};

// -------------------------------------------------------------------------
// Analytics Module (lightweight stats refresh)
// -------------------------------------------------------------------------

TshWaAdmin.initTemplateAnalytics = function () {
	// Nothing to do on page load — stats are server-rendered.
	// AJAX refresh happens when tsh_wa_sync_templates reloads the page.
};

// -------------------------------------------------------------------------
// Utility: debounce
// -------------------------------------------------------------------------

TshWaAdmin.debounce = function ( fn, wait ) {
	var timer;
	return function () {
		var args = arguments;
		clearTimeout( timer );
		timer = setTimeout( function () { fn.apply( this, args ); }, wait );
	};
};

// -------------------------------------------------------------------------
// Utility: show admin notice bar
// -------------------------------------------------------------------------

TshWaAdmin.showNotice = function ( message, type ) {
	var $notice = $( '#tsh-wa-page-notice' );
	if ( ! $notice.length ) {
		$notice = $( '<div id="tsh-wa-page-notice" class="notice is-dismissible"></div>' );
		$( '.wrap h1' ).first().after( $notice );
	}
	$notice
		.removeClass( 'notice-success notice-error notice-warning notice-info' )
		.addClass( 'notice-' + ( type === 'success' ? 'success' : ( type === 'error' ? 'error' : 'warning' ) ) )
		.html( '<p>' + $( '<span>' ).text( message ).html() + '</p>' )
		.show();

	setTimeout( function () { $notice.fadeOut( 400 ); }, 5000 );
};

// -------------------------------------------------------------------------
// Bootstrap: add Phase 5 modules to init()
// -------------------------------------------------------------------------

$( function () {
	TshWaAdmin.initTemplateManager();
	TshWaAdmin.initTemplatePreviewModal();
	TshWaAdmin.initTemplateAssignment();
	TshWaAdmin.initTemplateSearch();
	TshWaAdmin.initTemplateImportModal();
	TshWaAdmin.initTemplateAnalytics();
} );
