/* global pmproSMTP, jQuery */
( function( $ ) {
	'use strict';

	// =========================================================================
	// Connection tab: show/hide connector settings panels
	// =========================================================================

	function syncConnectorPanels() {
		var selected = $( 'input[name="pmpro_smtp_active_connector"]:checked' ).val();

		$( '.pmpro-smtp-connector-fields' ).hide();
		$( '.pmpro-smtp-connector-card' ).removeClass( 'is-selected' );

		if ( selected ) {
			$( '#pmpro-smtp-fields-' + selected ).show();
		}

		$( 'input[name="pmpro_smtp_active_connector"]:checked' )
			.closest( '.pmpro-smtp-connector-card' )
			.addClass( 'is-selected' );
	}

	// Run on load.
	syncConnectorPanels();

	// Run on change.
	$( document ).on( 'change', 'input[name="pmpro_smtp_active_connector"]', syncConnectorPanels );

	// =========================================================================
	// Test Email tab: AJAX send
	// =========================================================================

	$( document ).on( 'click', '#pmpro-smtp-send-test', function() {
		var $button = $( this );
		var $result = $( '#pmpro-smtp-test-result' );
		var email   = $( '#pmpro-smtp-test-email' ).val();

		function escapeHtml( text ) {
			return $( '<div />' ).text( text || '' ).html();
		}

		function renderError( data ) {
			var message = pmproSMTP.i18n.testFailed;
			var details = '';
			var hint    = '';

			if ( typeof data === 'string' ) {
				message += data;
			} else {
				message += ( data && data.message ) ? data.message : pmproSMTP.i18n.requestFailed;
				details = data && data.details ? data.details : '';
				hint    = data && data.hint ? data.hint : '';
			}

			var html = '<p>' + escapeHtml( message ) + '</p>';

			if ( hint ) {
				html += '<p><strong>' + escapeHtml( pmproSMTP.i18n.hintLabel ) + '</strong> ' + escapeHtml( hint ) + '</p>';
			}

			if ( details ) {
				html += '<details class="pmpro-smtp-debug-details"><summary>' + escapeHtml( pmproSMTP.i18n.showDebug ) + '</summary><pre>' + escapeHtml( details ) + '</pre></details>';
			}

			$result
				.addClass( 'notice notice-error' )
				.html( html )
				.show();
		}

		$button.prop( 'disabled', true ).text( pmproSMTP.i18n.sending );
		$result.hide().removeClass( 'notice-success notice-error' );

		$.post( pmproSMTP.ajaxUrl, {
			action:     'pmpro_smtp_send_test',
			nonce:      pmproSMTP.nonce,
			test_email: email,
		} )
		.done( function( response ) {
			if ( response.success ) {
				$result
					.addClass( 'notice notice-success' )
					.html( '<p>' + escapeHtml( response.data ) + '</p>' )
					.show();
			} else {
				renderError( response.data );
			}
		} )
		.fail( function() {
			renderError( pmproSMTP.i18n.requestFailed );
		} )
		.always( function() {
			$button.prop( 'disabled', false ).text( pmproSMTP.i18n.sendButton );
		} );
	} );

} )( jQuery );
