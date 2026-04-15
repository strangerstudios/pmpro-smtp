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

	function syncBackupPanels() {
		var selected = $( '#pmpro_smtp_backup_connector' ).val();

		$( '.pmpro-smtp-backup-fields' ).hide();

		if ( selected ) {
			$( '#pmpro-smtp-backup-fields-' + selected ).show();
		}
	}

	// Run on load.
	syncConnectorPanels();
	syncBackupPanels();

	// Run on change.
	$( document ).on( 'change', 'input[name="pmpro_smtp_active_connector"]', syncConnectorPanels );
	$( document ).on( 'change', '#pmpro_smtp_backup_connector', syncBackupPanels );

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
				message += ( data && data.message ) ? data.message : 'Request failed.';
				details = data && data.details ? data.details : '';
				hint    = data && data.hint ? data.hint : '';
			}

			var html = '<p>' + escapeHtml( message ) + '</p>';

			if ( hint ) {
				html += '<p><strong>Hint:</strong> ' + escapeHtml( hint ) + '</p>';
			}

			if ( details ) {
				html += '<details class="pmpro-smtp-debug-details"><summary>Show debug details</summary><pre>' + escapeHtml( details ) + '</pre></details>';
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
			renderError( 'Request failed.' );
		} )
		.always( function() {
			$button.prop( 'disabled', false ).text( 'Send Test Email' );
		} );
	} );

	// =========================================================================
	// Email log: select-all checkbox
	// =========================================================================

	$( document ).on( 'change', '#pmpro-smtp-select-all', function() {
		$( 'input[name="log_ids[]"]' ).prop( 'checked', $( this ).is( ':checked' ) );
	} );

	// Uncheck "select all" if any row is unchecked.
	$( document ).on( 'change', 'input[name="log_ids[]"]', function() {
		if ( ! $( this ).is( ':checked' ) ) {
			$( '#pmpro-smtp-select-all' ).prop( 'checked', false );
		}
	} );

	// Guard: prevent bulk delete with nothing selected.
	$( document ).on( 'click', '#pmpro-smtp-bulk-apply', function( e ) {
		var action  = $( '#pmpro-smtp-bulk-action' ).val();
		var checked = $( 'input[name="log_ids[]"]:checked' ).length;

		if ( 'delete' === action && checked === 0 ) {
			e.preventDefault();
			alert( 'Please select at least one entry to delete.' );
		}
	} );

	// =========================================================================
	// PMPro collapsible sections (if not already handled by PMPro core)
	// =========================================================================

	if ( typeof window.pmpro !== 'undefined' && typeof window.pmpro.initSections === 'function' ) {
		// PMPro core handles it.
		return;
	}

	$( document ).on( 'click', '.pmpro_section-toggle-button', function() {
		var $section = $( this ).closest( '.pmpro_section' );
		var $inside  = $section.find( '.pmpro_section_inside' );
		var $icon    = $( this ).find( '.dashicons' );
		var isOpen   = $section.data( 'visibility' ) === 'shown';

		if ( isOpen ) {
			$inside.slideUp( 200 );
			$section.data( 'visibility', 'hidden' );
			$icon.removeClass( 'dashicons-arrow-up-alt2' ).addClass( 'dashicons-arrow-down-alt2' );
			$( this ).attr( 'aria-expanded', 'false' );
		} else {
			$inside.slideDown( 200 );
			$section.data( 'visibility', 'shown' );
			$icon.removeClass( 'dashicons-arrow-down-alt2' ).addClass( 'dashicons-arrow-up-alt2' );
			$( this ).attr( 'aria-expanded', 'true' );
		}
	} );

} )( jQuery );
