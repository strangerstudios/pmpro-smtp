<?php
/**
 * Core functions: connector registry, mail interception, encryption.
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
			'mailersend'   => new PMPRO_SMTP_Connector_Mailersend(),
			'microsoft365' => new PMPRO_SMTP_Connector_Microsoft365(),
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

/**
 * Get the backup connector, or null if none is configured.
 *
 * @return PMPRO_SMTP_Connector_Base|null
 */
function pmpro_smtp_get_backup_connector() {
	$backup     = get_option( 'pmpro_smtp_backup_connector', '' );
	$connectors = pmpro_smtp_get_connectors();
	return ( ! empty( $backup ) && isset( $connectors[ $backup ] ) ) ? $connectors[ $backup ] : null;
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
 * @param null|bool $return Non-null short-circuits wp_mail().
 * @param array     $atts   { to, subject, message, headers, attachments }
 * @return null|bool
 */
function pmpro_smtp_pre_wp_mail( $return, $atts ) {
	// Sandbox mode: short-circuit without sending.
	if ( pmpro_smtp_is_test_mode() ) {
		pmpro_smtp_stash_from_data();
		do_action( 'wp_mail_succeeded', $atts );
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
		$backup = pmpro_smtp_get_backup_connector();
		if ( $backup && 'generic' !== $backup->get_name() ) {
			$result = $backup->send( $atts );
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

	pmpro_stashed_from_data(
		array(
			'from'      => apply_filters( 'wp_mail_from', get_option( 'admin_email' ) ),
			'from_name' => apply_filters( 'wp_mail_from_name', get_option( 'blogname' ) ),
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
	static $data = array(
		'active'    => false,
		'log'       => array(),
		'error'     => '',
		'exception' => '',
	);

	if ( null !== $set ) {
		if ( false === $set ) {
			$data = array(
				'active'    => false,
				'log'       => array(),
				'error'     => '',
				'exception' => '',
			);
		} else {
			$data = array_merge( $data, $set );
		}
	}

	return $data;
}

/**
 * Begin collecting SMTP debug information for a test email request.
 *
 * @return void
 */
function pmpro_smtp_begin_debug_capture() {
	pmpro_smtp_debug_data(
		array(
			'active'    => true,
			'log'       => array(),
			'error'     => '',
			'exception' => '',
		)
	);
}

/**
 * Stop collecting SMTP debug information.
 *
 * @return array
 */
function pmpro_smtp_end_debug_capture() {
	$data = pmpro_smtp_debug_data();
	pmpro_smtp_debug_data( false );
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

	$phpmailer->SMTPDebug = 2;
	$phpmailer->Debugoutput = static function( $message, $level ) {
		$data = pmpro_smtp_debug_data();
		if ( empty( $data['active'] ) ) {
			return;
		}

		$data['log'][] = sprintf( '[%s] %s', $level, trim( $message ) );
		pmpro_smtp_debug_data( $data );
	};
}
add_action( 'phpmailer_init', 'pmpro_smtp_capture_phpmailer_debug', 5 );

/**
 * Tell PMPro Hosting not to force its fallback SMTP transport when this plugin
 * is actively configured.
 *
 * PMPro Hosting uses local SMTP by default on hosted production sites. When a
 * third-party mailer plugin is configured, we want PMPro Hosting to stand down
 * so only one transport handles the message.
 *
 * @param bool $detected Whether a third-party mailer has already been detected.
 * @return bool
 */
function pmpro_smtp_mark_as_third_party_mailer( $detected ) {
	if ( $detected ) {
		return true;
	}

	return null !== pmpro_smtp_get_active_connector();
}
add_filter( 'pmpro_hosting_has_third_party_mailer', 'pmpro_smtp_mark_as_third_party_mailer' );

// ============================================================================
// Encryption
// ============================================================================

/**
 * Encrypt a sensitive string for database storage.
 *
 * Uses AES-256-CBC with a key derived from WordPress's auth salts.
 *
 * @param string $value Plain text value.
 * @return string Base64-encoded encrypted value, or empty string.
 */
function pmpro_smtp_encrypt( $value ) {
	if ( '' === $value || false === $value ) {
		return '';
	}

	if ( ! function_exists( 'openssl_encrypt' ) ) {
		return $value;
	}

	$key    = substr( hash( 'sha256', wp_salt( 'auth' ) . wp_salt( 'secure_auth' ) ), 0, 32 );
	$iv_len = openssl_cipher_iv_length( 'AES-256-CBC' );
	$iv     = openssl_random_pseudo_bytes( $iv_len );

	$encrypted = openssl_encrypt( $value, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );

	if ( false === $encrypted ) {
		return $value;
	}

	return base64_encode( $iv . $encrypted );
}

/**
 * Decrypt a value encrypted with pmpro_smtp_encrypt().
 *
 * @param string $value Encrypted value.
 * @return string Plain text, or the original value if decryption fails.
 */
function pmpro_smtp_decrypt( $value ) {
	if ( empty( $value ) ) {
		return '';
	}

	if ( ! function_exists( 'openssl_decrypt' ) ) {
		return $value;
	}

	$key     = substr( hash( 'sha256', wp_salt( 'auth' ) . wp_salt( 'secure_auth' ) ), 0, 32 );
	$iv_len  = openssl_cipher_iv_length( 'AES-256-CBC' );
	$decoded = base64_decode( $value, true );

	if ( false === $decoded || strlen( $decoded ) <= $iv_len ) {
		return $value; // Not encrypted — stored as plain text before encryption was added.
	}

	$iv        = substr( $decoded, 0, $iv_len );
	$encrypted = substr( $decoded, $iv_len );
	$decrypted = openssl_decrypt( $encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );

	return ( false === $decrypted ) ? $value : $decrypted;
}
