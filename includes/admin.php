<?php
/**
 * Admin: menu registration, asset enqueueing, and AJAX handlers.
 *
 * @package PMProSMTP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ============================================================================
// Menu
// ============================================================================

/**
 * Register admin pages under the Memberships (PMPro) menu.
 *
 * If Paid Memberships Pro is not active, the 'pmpro-dashboard' parent menu does
 * not exist and add_submenu_page() would silently fail, hiding the entire UI.
 * In that case we fall back to registering under the Settings menu so the page
 * remains reachable.
 *
 * Design note: the plugin declares "Requires Plugins: paid-memberships-pro", so
 * on WordPress 6.5+ it cannot be ACTIVATED without PMPro. These PMPro-inactive
 * fallbacks (Settings-menu menu, options-general.php URLs, the admin notice)
 * are therefore intentionally retained only for the one reachable inactive
 * state: PMPro being DEACTIVATED while this plugin stays active. They keep the
 * UI graceful in that window rather than 500-ing or vanishing.
 */
function pmpro_smtp_admin_menu() {
	if ( defined( 'PMPRO_VERSION' ) ) {
		// Primary settings page — appears as a submenu item under Memberships.
		add_submenu_page(
			'pmpro-dashboard',
			__( 'SMTP Settings', 'pmpro-smtp' ),
			__( 'SMTP', 'pmpro-smtp' ),
			'manage_options',
			'pmpro-smtp',
			'pmpro_smtp_render_settings_page',
			999
		);
	} else {
		// Fallback: register under Settings so the page is still discoverable.
		add_options_page(
			__( 'SMTP Settings', 'pmpro-smtp' ),
			__( 'PMPro SMTP', 'pmpro-smtp' ),
			'manage_options',
			'pmpro-smtp',
			'pmpro_smtp_render_settings_page'
		);
	}
}
add_action( 'admin_menu', 'pmpro_smtp_admin_menu' );

/**
 * Show an admin notice when Paid Memberships Pro is not active.
 */
function pmpro_smtp_pmpro_inactive_notice() {
	if ( defined( 'PMPRO_VERSION' ) || ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	// Our own settings page renders its own in-page notice (see
	// pmpro_smtp_settings_page()), so suppress this global one there to avoid a
	// duplicate stacked warning.
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( $screen && in_array( $screen->id, array( 'memberships_page_pmpro-smtp', 'settings_page_pmpro-smtp' ), true ) ) {
		return;
	}

	echo '<div class="notice notice-warning"><p>';
	echo esc_html__( 'PMPro SMTP works best with Paid Memberships Pro, which provides sender settings and email logging. Please install and activate Paid Memberships Pro.', 'pmpro-smtp' );
	echo '</p></div>';
}
add_action( 'admin_notices', 'pmpro_smtp_pmpro_inactive_notice' );

/**
 * Render the settings page (delegates to adminpages/settings.php).
 */
function pmpro_smtp_render_settings_page() {
	require_once PMPRO_SMTP_DIR . '/adminpages/settings.php';
	pmpro_smtp_settings_page();
}

// ============================================================================
// Assets
// ============================================================================

/**
 * Enqueue admin CSS and JS on our pages only.
 */
function pmpro_smtp_admin_enqueue_scripts() {
	$screen = get_current_screen();
	if ( ! $screen ) {
		return;
	}

	// Only on our admin pages. The screen ID depends on the parent menu the
	// page is registered under (Memberships when PMPro is active, otherwise
	// Settings via the fallback).
	$our_pages = array( 'memberships_page_pmpro-smtp', 'settings_page_pmpro-smtp' );
	if ( ! in_array( $screen->id, $our_pages, true ) ) {
		return;
	}

	wp_enqueue_style(
		'pmpro-smtp-admin',
		plugins_url( 'assets/css/admin.css', PMPRO_SMTP_BASE_FILE ),
		array(),
		PMPRO_SMTP_VERSION
	);

	wp_enqueue_script(
		'pmpro-smtp-admin',
		plugins_url( 'assets/js/admin.js', PMPRO_SMTP_BASE_FILE ),
		array( 'jquery' ),
		PMPRO_SMTP_VERSION,
		true
	);

	wp_localize_script( 'pmpro-smtp-admin', 'pmproSMTP', array(
		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		'nonce'   => wp_create_nonce( 'pmpro_smtp_admin' ),
		'i18n'    => array(
			'sending'       => __( 'Sending...', 'pmpro-smtp' ),
			'testFailed'    => __( 'Test email failed: ', 'pmpro-smtp' ),
			'sendButton'    => __( 'Send Test Email', 'pmpro-smtp' ),
			'requestFailed' => __( 'Request failed.', 'pmpro-smtp' ),
			'hintLabel'     => __( 'Hint:', 'pmpro-smtp' ),
			'showDebug'     => __( 'Show debug details', 'pmpro-smtp' ),
		),
	) );
}
add_action( 'admin_enqueue_scripts', 'pmpro_smtp_admin_enqueue_scripts' );

// ============================================================================
// AJAX: Send test email
// ============================================================================

/**
 * Handle AJAX request to send a test email.
 */
function pmpro_smtp_ajax_send_test() {
	check_ajax_referer( 'pmpro_smtp_admin', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( __( 'Permission denied.', 'pmpro-smtp' ) );
	}

	$to = isset( $_POST['test_email'] ) ? sanitize_email( wp_unslash( $_POST['test_email'] ) ) : '';
	if ( empty( $to ) ) {
		$to = get_option( 'admin_email' );
	}

	$connector = pmpro_smtp_get_active_connector();
	if ( null === $connector ) {
		wp_send_json_error( __( 'No SMTP connector is configured. Please save your settings first.', 'pmpro-smtp' ) );
	}

	$atts = array(
		'to'      => $to,
		/* translators: %s: site name */
		'subject' => sprintf( __( '[%s] PMPro SMTP Test Email', 'pmpro-smtp' ), get_option( 'blogname' ) ),
		'message' => sprintf(
			/* translators: 1: connector title, 2: site URL, 3: date and time */
			__( "This is a test email sent from your site to confirm that PMPro SMTP is configured correctly.\n\nConnector: %1\$s\nSite: %2\$s\nDate: %3\$s", 'pmpro-smtp' ),
			$connector->get_title(),
			home_url(),
			current_time( 'Y-m-d H:i:s' )
		),
		'headers'     => array( 'Content-Type: text/plain; charset=UTF-8' ),
		'attachments' => array(),
	);

	pmpro_smtp_begin_debug_capture();

	// Exclude this diagnostic test send from PMPro's email log. Without this,
	// PMPro's wp_mail_succeeded / wp_mail_failed handlers record every admin
	// click of "Send Test Email" into the customer-visible email log. The flag
	// is request-scoped and cleared immediately after wp_mail() returns.
	$GLOBALS['pmpro_smtp_sending_test'] = true;
	add_filter( 'pmpro_should_log_email', 'pmpro_smtp_exclude_test_from_log', 99 );

	$result = wp_mail(
		$atts['to'],
		$atts['subject'],
		$atts['message'],
		$atts['headers'],
		$atts['attachments']
	);

	remove_filter( 'pmpro_should_log_email', 'pmpro_smtp_exclude_test_from_log', 99 );
	unset( $GLOBALS['pmpro_smtp_sending_test'] );

	$debug = pmpro_smtp_end_debug_capture();

	// Resolve the From address the same way a real send would, so the admin can
	// confirm production mail will use the expected sender. The test payload has
	// no From: header, so this reflects the no-header fallback (the WP/PMPro
	// configured sender) that most PMPro emails ultimately resolve against.
	$resolved_from = $connector->get_resolved_from( $atts['headers'] );
	$from_display  = ! empty( $resolved_from['name'] )
		? sprintf( '%s <%s>', $resolved_from['name'], $resolved_from['email'] )
		: $resolved_from['email'];

	if ( pmpro_smtp_is_test_mode() ) {
		wp_send_json_success( __( 'Sandbox mode is active. The test email was not delivered.', 'pmpro-smtp' ) );
	}

	if ( ! $result ) {
		$message = ! empty( $debug['error'] ) ? $debug['error'] : __( 'WordPress could not send the test email.', 'pmpro-smtp' );
		$details = array();

		if ( ! empty( $debug['log'] ) ) {
			$details[] = implode( "\n", $debug['log'] );
		}

		$hint = '';
		if ( 'generic' === $connector->get_name() ) {
			$settings = get_option( 'pmpro_smtp_connector_generic', array() );
			if (
				! empty( $settings['host'] ) &&
				false !== stripos( $settings['host'], 'gmail.com' ) &&
				false !== stripos( $message, 'authenticate' )
			) {
				$hint = __( 'Gmail and Google Workspace usually require an app password here, not your normal account password. If the account is managed by Google Workspace, app passwords may also need to be enabled by the Workspace admin.', 'pmpro-smtp' );
			}
		}

		wp_send_json_error(
			array(
				'message' => $message,
				'details' => implode( "\n\n", array_filter( $details ) ),
				'hint'    => $hint,
			)
		);
	}

	wp_send_json_success(
		sprintf(
			/* translators: 1: recipient email address, 2: resolved From sender (both escaped client-side at render time) */
			__( 'Test email sent to %1$s from %2$s.', 'pmpro-smtp' ),
			$to,
			$from_display
		)
	);
}
add_action( 'wp_ajax_pmpro_smtp_send_test', 'pmpro_smtp_ajax_send_test' );

/**
 * Prevent the "Send Test Email" diagnostic send from being written to PMPro's
 * email log. Hooked onto pmpro_should_log_email only while a test send is in
 * flight (see pmpro_smtp_ajax_send_test()).
 *
 * @param bool $should_log Whether PMPro intends to log this email.
 * @return bool
 */
function pmpro_smtp_exclude_test_from_log( $should_log ) {
	if ( ! empty( $GLOBALS['pmpro_smtp_sending_test'] ) ) {
		return false;
	}
	return $should_log;
}

// ============================================================================
// Settings link on Plugins page
// ============================================================================

/**
 * Add a Settings link to the plugin row on the Plugins page.
 *
 * @param array $links
 * @return array
 */
function pmpro_smtp_plugin_action_links( $links ) {
	// menu_page_url() resolves to whichever parent the page was registered
	// under (Memberships when PMPro is active, otherwise Settings via the
	// fallback), so the link is correct in both cases.
	$url = menu_page_url( 'pmpro-smtp', false );
	if ( empty( $url ) ) {
		$base = defined( 'PMPRO_VERSION' ) ? 'admin.php' : 'options-general.php';
		$url  = admin_url( $base . '?page=pmpro-smtp' );
	}
	$settings_link = '<a href="' . esc_url( $url ) . '">' . __( 'Settings', 'pmpro-smtp' ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
}
add_filter( 'plugin_action_links_' . PMPRO_SMTP_BASENAME, 'pmpro_smtp_plugin_action_links' );
