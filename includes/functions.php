<?php
/**
 * Core functions: connector registry and mail interception.
 *
 * From name/email settings and email logging are handled by PMPro core.
 *
 * @package PMProSMTP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ============================================================================
// Connector registry
// ============================================================================

/**
 * Get all registered connectors.
 *
 * @return PMPRO_SMTP_Connector_Base[]
 */
function pmpro_smtp_get_connectors() {
	static $connectors = null;

	if ( null === $connectors ) {
		$connectors = array(
			'generic'    => new PMPRO_SMTP_Connector_Generic(),
			'sendgrid'   => new PMPRO_SMTP_Connector_Sendgrid(),
			'mailgun'    => new PMPRO_SMTP_Connector_Mailgun(),
			'postmark'   => new PMPRO_SMTP_Connector_Postmark(),
			'brevo'      => new PMPRO_SMTP_Connector_Brevo(),
			'resend'     => new PMPRO_SMTP_Connector_Resend(),
			'mailersend' => new PMPRO_SMTP_Connector_Mailersend(),
		);

		/**
		 * Filter the list of available connectors.
		 *
		 * @param PMPRO_SMTP_Connector_Base[] $connectors
		 */
		$connectors = apply_filters( 'pmpro_smtp_connectors', $connectors );
	}

	return $connectors;
}

/**
 * Get the active (primary) connector, or null if none is configured.
 *
 * @return PMPRO_SMTP_Connector_Base|null
 */
function pmpro_smtp_get_active_connector() {
	$active     = get_option( 'pmpro_smtp_active_connector', '' );
	$connectors = pmpro_smtp_get_connectors();
	return ( ! empty( $active ) && isset( $connectors[ $active ] ) ) ? $connectors[ $active ] : null;
}

// ============================================================================
// Mail interception
// ============================================================================

/**
 * Intercept wp_mail() for API-based connectors via the pre_wp_mail filter.
 *
 * The Generic SMTP connector does NOT use this path — it configures PHPMailer
 * directly via the phpmailer_init action so WordPress handles the full
 * PHPMailer flow natively.
 *
 * PMPro core handles From name/email settings and email logging.
 * When using API connectors, wp_mail_succeeded/wp_mail_failed won't fire
 * (because we short-circuit), but PMPro hooks those same actions so our
 * connector success/failure is transparent to PMPro's log.
 *
 * Inline embeds ($atts['embeds'], added in WordPress 6.9) are not mapped to the
 * API providers' CID/inline-attachment mechanisms, so emails relying on inline
 * embedded images are best sent through the Generic/PHPMailer connector, which
 * runs through WordPress core and handles embeds natively.
 *
 * @param null|bool $return Non-null short-circuits wp_mail().
 * @param array     $atts   { to, subject, message, headers, attachments, embeds }
 * @return null|bool
 */
function pmpro_smtp_pre_wp_mail( $return, $atts ) {
	// Another pre_wp_mail filter already short-circuited this email (the filter
	// is chained, so we still run) — sending again would deliver a duplicate.
	if ( null !== $return ) {
		return $return;
	}

	// Sandbox: discard without firing wp_mail_succeeded; clear PMPro's stash so it does not linger.
	if ( pmpro_smtp_is_test_mode() ) {
		if ( function_exists( 'pmpro_stashed_mail_data' ) ) {
			pmpro_stashed_mail_data( false );
		}
		if ( function_exists( 'pmpro_stashed_from_data' ) ) {
			pmpro_stashed_from_data( false );
		}
		return true;
	}

	$connector = pmpro_smtp_get_active_connector();

	// No connector, or Generic SMTP (handled via phpmailer_init) — pass through.
	if ( null === $connector || 'generic' === $connector->get_name() ) {
		return $return;
	}

	// Send via primary connector.
	$result = $connector->send( $atts );

	// Try backup connector on failure.
	if ( is_wp_error( $result ) ) {
		$backup_slug = get_option( 'pmpro_smtp_backup_connector', '' );
		$connectors  = pmpro_smtp_get_connectors();
		if ( ! empty( $backup_slug ) && isset( $connectors[ $backup_slug ] ) && 'generic' !== $backup_slug && $backup_slug !== $connector->get_name() ) {
			$result = $connectors[ $backup_slug ]->send( $atts );
		}
	}

	// Fire wp_mail_succeeded / wp_mail_failed so PMPro's email log picks them up.
	if ( is_wp_error( $result ) ) {
		pmpro_smtp_stash_from_data();
		$error = new WP_Error( $result->get_error_code(), $result->get_error_message(), $atts );
		do_action( 'wp_mail_failed', $error );
		return false;
	}

	pmpro_smtp_stash_from_data();
	do_action( 'wp_mail_succeeded', $atts );
	return true;
}
add_filter( 'pre_wp_mail', 'pmpro_smtp_pre_wp_mail', 10, 2 );

/**
 * Configure PHPMailer for the Generic SMTP connector.
 *
 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer
 */
function pmpro_smtp_phpmailer_init( $phpmailer ) {
	$connector = pmpro_smtp_get_active_connector();

	if ( null === $connector || 'generic' !== $connector->get_name() ) {
		return;
	}

	$connector->configure_phpmailer( $phpmailer );
}
add_action( 'phpmailer_init', 'pmpro_smtp_phpmailer_init' );

// ============================================================================
// Status helpers
// ============================================================================

/**
 * Check whether sandbox / test mode is enabled.
 *
 * When enabled, no emails are delivered. PMPro's email log will not receive
 * wp_mail_succeeded or wp_mail_failed events for sandboxed emails.
 *
 * @return bool
 */
function pmpro_smtp_is_test_mode() {
	return (bool) get_option( 'pmpro_smtp_test_mode', false );
}

/**
 * Prime PMPro's from-data stash for send paths that bypass PHPMailer.
 *
 * PMPro's email log normally captures the final sender via phpmailer_init. API
 * connectors and sandbox mode short-circuit before PHPMailer runs, so we stash
 * the same values here when PMPro's helper is available.
 *
 * @return void
 */
function pmpro_smtp_stash_from_data() {
	if ( ! function_exists( 'pmpro_stashed_from_data' ) ) {
		return;
	}

	// Seed the filters with the same default WordPress core uses
	// ('wordpress@<sitename>' / 'WordPress') so PMPro's wp_mail_from override,
	// which only substitutes its configured sender on that exact default, fires
	// here too — matching the address API connectors actually send from.
	pmpro_stashed_from_data(
		array(
			'from'      => apply_filters( 'wp_mail_from', PMPRO_SMTP_Connector_Base::default_from_email() ),
			'from_name' => apply_filters( 'wp_mail_from_name', 'WordPress' ),
		)
	);
}

/**
 * Get or set SMTP debug data for the current request.
 *
 * @param array|null|false $set Optional. Array to set, false to clear, null to get.
 * @return array
 */
function pmpro_smtp_debug_data( $set = null ) {
	$default = array(
		'active' => false,
		'log'    => array(),
		'error'  => '',
	);
	static $data = null;
	if ( null === $data ) {
		$data = $default;
	}

	if ( null !== $set ) {
		$data = ( false === $set ) ? $default : array_merge( $data, $set );
	}

	return $data;
}

/**
 * Capture wp_mail failure details for the current debug request.
 *
 * @param WP_Error $error Failure object from wp_mail.
 * @return void
 */
function pmpro_smtp_capture_wp_mail_failed( $error ) {
	$data = pmpro_smtp_debug_data();
	if ( empty( $data['active'] ) ) {
		return;
	}

	$data['error'] = $error->get_error_message();
	pmpro_smtp_debug_data( $data );
}
add_action( 'wp_mail_failed', 'pmpro_smtp_capture_wp_mail_failed' );

/**
 * Capture low-level PHPMailer SMTP debug output for the current debug request.
 *
 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer PHPMailer instance.
 * @return void
 */
function pmpro_smtp_capture_phpmailer_debug( $phpmailer ) {
	$data = pmpro_smtp_debug_data();
	if ( empty( $data['active'] ) ) {
		return;
	}

	// DEBUG_SERVER (level 2) records the server side of the handshake, which is
	// enough to diagnose connection/TLS/auth-rejection failures without emitting
	// the client AUTH lines that carry credentials (those only appear at
	// DEBUG_CLIENT, level 3+).
	$phpmailer->SMTPDebug = 2;
	$phpmailer->Debugoutput = static function( $message, $level ) {
		$data = pmpro_smtp_debug_data();
		// Re-check at capture time: the global PHPMailer (and this closure)
		// outlives the test-email flow that armed it, so a later send in the
		// same request must not record into the deactivated buffer.
		if ( empty( $data['active'] ) ) {
			return;
		}
		$data['log'][] = sprintf( '[%s] %s', $level, trim( $message ) );
		pmpro_smtp_debug_data( $data );
	};
}
add_action( 'phpmailer_init', 'pmpro_smtp_capture_phpmailer_debug', 5 );

