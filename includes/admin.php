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
 */
function pmpro_smtp_admin_menu() {
	// Primary settings page — appears as a submenu item.
	add_submenu_page(
		'pmpro-dashboard',
		__( 'SMTP Settings', 'pmpro-smtp' ),
		__( 'SMTP', 'pmpro-smtp' ),
		'manage_options',
		'pmpro-smtp',
		'pmpro_smtp_render_settings_page',
		999
	);

}
add_action( 'admin_menu', 'pmpro_smtp_admin_menu' );

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

	// Only on our admin pages.
	$our_pages = array( 'memberships_page_pmpro-smtp' );
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
			'sending'     => __( 'Sending...', 'pmpro-smtp' ),
			'testSuccess' => __( 'Test email sent successfully!', 'pmpro-smtp' ),
			'testFailed'  => __( 'Test email failed: ', 'pmpro-smtp' ),
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
		'subject' => sprintf( __( '[%s] PMPro SMTP Test Email', 'pmpro-smtp' ), get_option( 'blogname' ) ),
		'message' => sprintf(
			__( "This is a test email sent from your site to confirm that PMPro SMTP is configured correctly.\n\nConnector: %s\nSite: %s\nDate: %s", 'pmpro-smtp' ),
			$connector->get_title(),
			home_url(),
			current_time( 'Y-m-d H:i:s' )
		),
		'headers'     => array( 'Content-Type: text/plain; charset=UTF-8' ),
		'attachments' => array(),
	);

	pmpro_smtp_begin_debug_capture();

	$result = wp_mail(
		$atts['to'],
		$atts['subject'],
		$atts['message'],
		$atts['headers'],
		$atts['attachments']
	);

	$debug = pmpro_smtp_end_debug_capture();

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

			if (
				! empty( $settings['host'] ) &&
				(
					false !== stripos( $settings['host'], 'office365.com' ) ||
					false !== stripos( $settings['host'], 'outlook.com' )
				) &&
				(
					false !== stripos( $message, 'authenticate' ) ||
					false !== stripos( $message, '5.7.3' ) ||
					false !== stripos( $message, '5.7.57' )
				)
			) {
				$hint = __( 'Microsoft 365 often disables SMTP AUTH for the tenant or mailbox. Use the Microsoft 365 / Outlook provider to send through Microsoft Graph without storing a mailbox password, or ask the Microsoft 365 admin to enable authenticated SMTP only for this mailbox.', 'pmpro-smtp' );
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

	wp_send_json_success( sprintf( __( 'Test email sent to %s.', 'pmpro-smtp' ), esc_html( $to ) ) );
}
add_action( 'wp_ajax_pmpro_smtp_send_test', 'pmpro_smtp_ajax_send_test' );

// ============================================================================
// Admin actions: Microsoft 365 OAuth
// ============================================================================

/**
 * Redirect an admin to Microsoft for OAuth authorization.
 */
function pmpro_smtp_admin_microsoft365_connect() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Permission denied.', 'pmpro-smtp' ) );
	}

	check_admin_referer( 'pmpro_smtp_microsoft365_connect' );

	$connector      = new PMPRO_SMTP_Connector_Microsoft365();
	$state          = wp_generate_password( 32, false, false );
	$code_verifier  = wp_generate_password( 64, false, false );
	$code_challenge = rtrim( strtr( base64_encode( hash( 'sha256', $code_verifier, true ) ), '+/', '-_' ), '=' );

	set_transient(
		'pmpro_smtp_microsoft365_state_' . $state,
		array(
			'user_id'       => get_current_user_id(),
			'session_token' => wp_get_session_token(),
			'code_verifier' => $code_verifier,
		),
		10 * MINUTE_IN_SECONDS
	);

	$url = $connector->build_authorization_url( $state, $code_challenge );
	if ( is_wp_error( $url ) ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                           => 'pmpro-smtp',
					'tab'                            => 'connection',
					'pmpro_smtp_microsoft365_error' => $url->get_error_message(),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	wp_redirect( $url );
	exit;
}
add_action( 'admin_post_pmpro_smtp_microsoft365_connect', 'pmpro_smtp_admin_microsoft365_connect' );

/**
 * Disconnect Microsoft 365 by deleting stored OAuth tokens.
 */
function pmpro_smtp_admin_microsoft365_disconnect() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Permission denied.', 'pmpro-smtp' ) );
	}

	check_admin_referer( 'pmpro_smtp_microsoft365_disconnect' );

	$connector = new PMPRO_SMTP_Connector_Microsoft365();
	$connector->disconnect();

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'                                  => 'pmpro-smtp',
				'tab'                                   => 'connection',
				'pmpro_smtp_microsoft365_disconnected' => '1',
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}
add_action( 'admin_post_pmpro_smtp_microsoft365_disconnect', 'pmpro_smtp_admin_microsoft365_disconnect' );

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
	$settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=pmpro-smtp' ) ) . '">' . __( 'Settings', 'pmpro-smtp' ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
}
add_filter( 'plugin_action_links_' . PMPRO_SMTP_BASENAME, 'pmpro_smtp_plugin_action_links' );
