/**
 * TSH WhatsApp Notify — Admin JavaScript
 *
 * Vanilla JS + jQuery (WP-bundled). No build step required for Phase 1.
 * All behaviour is progressive-enhancement; pages work without JS.
 *
 * @package TSH\WhatsAppNotify
 * @version 1.0.0
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
			// Append toggle buttons next to every password input inside our forms.
			$( '.tsh-wa-settings-form input[type="password"]' ).each( function () {
				var $input  = $( this );
				var $btn    = $( '<button type="button" class="button tsh-wa-pw-toggle" style="margin-left:6px;" aria-label="' + ( tshWaAdmin.i18n.show_password || 'Show/hide' ) + '">' +
					'<span class="dashicons dashicons-visibility"></span>' +
					'</button>' );

				$btn.insertAfter( $input );

				$btn.on( 'click', function () {
					var type = $input.attr( 'type' ) === 'password' ? 'text' : 'password';
					$input.attr( 'type', type );
					$( this ).find( '.dashicons' )
						.toggleClass( 'dashicons-visibility', type === 'password' )
						.toggleClass( 'dashicons-hidden', type === 'text' );
				} );
			} );
		},

		// -----------------------------------------------------------------------
		// Confirmation dialogs
		// -----------------------------------------------------------------------

		/**
		 * Any submit button with data-confirm="..." will prompt before submitting.
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
			// On settings page: highlight & scroll to the active tab.
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
					// Close all other open context details in the same table.
					$( '.tsh-wa-log-context[open]' ).not( this ).each( function () {
						this.open = false;
					} );
				}
			} );
		},

		// -----------------------------------------------------------------------
		// Copy to clipboard utility (used on webhook token field, etc.)
		// -----------------------------------------------------------------------

		initCopyToClipboard: function () {
			$( document ).on( 'click', '[data-tsh-wa-copy]', function () {
				var target = $( this ).data( 'tsh-wa-copy' );
				var $target = $( '#' + target );

				if ( ! $target.length ) {
					return;
				}

				var text = $target.val() || $target.text();

				if ( ! text ) {
					return;
				}

				if ( navigator.clipboard && window.isSecureContext ) {
					navigator.clipboard.writeText( text ).then( function () {
						TSHWaAdmin.showCopySuccess( $( '[data-tsh-wa-copy="' + target + '"]' ) );
					} );
				} else {
					// Fallback: select + execCommand.
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
		// AJAX helper (available for Phase 2+)
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
					action:    action,
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

} )( jQuery );
