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
 * Inline embeds ($atts['embeds'], added in WordPress 6.9) are not mapped to the
 * API providers' CID/inline-attachment mechanisms, so emails relying on inline
 * embedded images are best sent through the Generic/PHPMailer connector, which
 * runs through WordPress core and handles embeds natively. See the do_send()
 * contract on PMPRO_SMTP_Connector_Base for details.
 *
 * @param null|bool $return Non-null short-circuits wp_mail().
 * @param array     $atts   { to, subject, message, headers, attachments, embeds }
 * @return null|bool
 */
function pmpro_smtp_pre_wp_mail( $return, $atts ) {
	// Sandbox mode: short-circuit without sending.
	//
	// We intentionally do NOT fire wp_mail_succeeded here: the message was
	// discarded, not delivered, so recording it as a normal successful send in
	// PMPro's email log would mislead admins reviewing the log on staging.
	//
	// PMPro also runs a fallback logger on the pmpro_after_email_sent action that
	// keys off its stashed mail data, but that fallback early-returns as a no-op
	// when pmpro_stashed_mail_data() is empty. Because we clear that stash below
	// (before wp_mail() returns and before pmpro_after_email_sent fires), the
	// fallback finds an empty stash and does NOT log the sandboxed PMPro email as
	// "sent". So sandboxed PMPro emails are not recorded as delivered.
	if ( pmpro_smtp_is_test_mode() ) {
		// PMPro stashes the outgoing mail/from data on the wp_mail filter (which
		// core runs before pre_wp_mail) and only clears it from its
		// wp_mail_succeeded / wp_mail_failed handlers. Since we fire neither for
		// discarded mail, clear the stash here so a stale value does not linger
		// between sends.
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

	// Seed the filters with the same default WordPress core uses
	// ('wordpress@<sitename>' / 'WordPress') so PMPro's wp_mail_from override,
	// which only substitutes its configured sender on that exact default, fires
	// here too — matching the address API connectors actually send from. The
	// default is derived by the shared connector helper so all three call sites
	// (here, the connectors, and the settings "Current Sender" block) stay in
	// exact sync with wp-includes/pluggable.php.
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
	static $data = array(
		'active'              => false,
		'log'                 => array(),
		'error'               => '',
		'exception'           => '',
		'auth_payload_lines'  => 0,
	);

	if ( null !== $set ) {
		if ( false === $set ) {
			$data = array(
				'active'              => false,
				'log'                 => array(),
				'error'               => '',
				'exception'           => '',
				'auth_payload_lines'  => 0,
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
			'active'              => true,
			'log'                 => array(),
			'error'               => '',
			'exception'           => '',
			'auth_payload_lines'  => 0,
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

	// DEBUG_SERVER (level 2) records the server side of the handshake, which is
	// enough to diagnose connection/TLS/auth-rejection failures without ever
	// emitting the client AUTH lines that carry credentials. PHPMailer only logs
	// those client lines at DEBUG_CLIENT (level 3+). The credential redaction in
	// pmpro_smtp_redact_smtp_debug_line() is therefore inert at this level by
	// design; it is kept as defense-in-depth so that raising SMTPDebug to 3+ for
	// deeper diagnostics can never leak secrets to the browser.
	$phpmailer->SMTPDebug = 2;
	$phpmailer->Debugoutput = static function( $message, $level ) {
		$data = pmpro_smtp_debug_data();
		if ( empty( $data['active'] ) ) {
			return;
		}

		$line = pmpro_smtp_redact_smtp_debug_line( trim( $message ) );
		if ( null === $line ) {
			return;
		}

		// Re-read the store: pmpro_smtp_redact_smtp_debug_line() mutates the
		// auth_payload_lines counter, so the $data captured above is stale.
		// Writing it back would clobber that counter and break redaction of the
		// AUTH payload line(s) that follow.
		$data          = pmpro_smtp_debug_data();
		$data['log'][] = sprintf( '[%s] %s', $level, $line );
		pmpro_smtp_debug_data( $data );
	};
}
add_action( 'phpmailer_init', 'pmpro_smtp_capture_phpmailer_debug', 5 );

/**
 * Redact SMTP credentials from a PHPMailer DEBUG_SERVER log line.
 *
 * PHPMailer's level-2 debug logs the full CLIENT->SERVER conversation, which
 * during authentication exposes the SMTP credentials:
 *   - 'AUTH LOGIN' is followed by two client lines: base64(username) then
 *     base64(password).
 *   - 'AUTH PLAIN <base64>' carries the credentials inline.
 *   - 'AUTH XOAUTH2 <base64>' carries a bearer token inline.
 * These must never be echoed back to the browser. We keep the protocol command
 * lines for diagnostics but redact/drop the payload lines that contain secrets.
 *
 * State (how many post-AUTH-LOGIN payload lines remain to drop) is tracked in
 * the debug data store so it survives across the static Debugoutput closure.
 *
 * @param string $line Trimmed debug line.
 * @return string|null Sanitized line, or null to drop the line entirely.
 */
function pmpro_smtp_redact_smtp_debug_line( $line ) {
	$data    = pmpro_smtp_debug_data();
	$pending = isset( $data['auth_payload_lines'] ) ? (int) $data['auth_payload_lines'] : 0;

	// Strip the PHPMailer direction prefix to inspect the raw command.
	$content = preg_replace( '/^(CLIENT -> SERVER:|SERVER -> CLIENT:|SMTP ->|.*?->\s*server:)\s*/i', '', $line );
	$content = trim( $content );

	// We're inside an AUTH LOGIN handshake: the next client line(s) are the
	// base64 username/password. Drop them.
	if ( $pending > 0 ) {
		// Server challenge/response lines (e.g. "334 ...") are not the secret;
		// only client payload lines are. PHPMailer logs the client base64 lines
		// without a "334"/"235" status code, so treat coded lines as protocol.
		if ( preg_match( '/^\d{3}\b/', $content ) ) {
			return $line;
		}
		$data['auth_payload_lines'] = $pending - 1;
		pmpro_smtp_debug_data( $data );
		return '[redacted credential]';
	}

	// 'AUTH LOGIN' begins a two-step exchange (username, then password).
	if ( preg_match( '/\bAUTH\s+LOGIN\b/i', $content ) ) {
		$data['auth_payload_lines'] = 2;
		pmpro_smtp_debug_data( $data );
		return $line;
	}

	// 'AUTH PLAIN <payload>' / 'AUTH XOAUTH2 <payload>' carry the secret inline.
	if ( preg_match( '/\bAUTH\s+(PLAIN|XOAUTH2|CRAM-MD5|NTLM)\b/i', $content ) ) {
		$redacted = preg_replace( '/(\bAUTH\s+(?:PLAIN|XOAUTH2|CRAM-MD5|NTLM)\b)\s+\S.*$/i', '$1 [redacted credential]', $line );
		return $redacted;
	}

	return $line;
}

/*
 * PMPro Hosting integration.
 *
 * NOTE: As of this writing, PMPro Hosting does NOT expose a
 * 'pmpro_hosting_has_third_party_mailer' filter (verified against the installed
 * pmpro-hosting plugin — the only mail handling it performs is removing the
 * wp_staticize_emoji_for_email filter and a __return_false on wp_mail during
 * site suspension). It also does not force its own SMTP/phpmailer transport, so
 * there is no competing transport for this plugin to coordinate with.
 *
 * The previously-registered add_filter() therefore never fired and has been
 * removed to avoid shipping dead code. If/when PMPro Hosting adds a real hook
 * for third-party mailer detection, wire pmpro_smtp_get_active_connector() to
 * it here and cite the exact hook name in this comment.
 */

// ============================================================================
// Encryption
// ============================================================================

/**
 * Version marker prefixed to values encrypted by this plugin.
 *
 * Lets pmpro_smtp_decrypt() distinguish genuinely-encrypted values from legacy
 * plaintext (and from a failed decryption) instead of guessing by length.
 */
define( 'PMPRO_SMTP_ENC_PREFIX', 'pmprosmtp:enc:v2:' );

/**
 * Whether OpenSSL is available for credential encryption.
 *
 * @return bool
 */
function pmpro_smtp_can_encrypt() {
	return function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' );
}

/**
 * Derive the encryption key from WordPress's auth salts.
 *
 * @return string 32-byte key.
 */
function pmpro_smtp_encryption_key() {
	// $binary = true returns 32 raw bytes (256 bits of entropy) rather than a
	// 64-char hex string; taking the first 32 hex chars would yield only ~128
	// bits of real entropy for an AES-256 key.
	return hash( 'sha256', wp_salt( 'auth' ) . wp_salt( 'secure_auth' ), true );
}

/**
 * Derive a separate MAC key (different salt context than the encryption key).
 *
 * @return string 32-byte key.
 */
function pmpro_smtp_mac_key() {
	// $binary = true returns 32 raw bytes (256 bits) for the HMAC key; see
	// pmpro_smtp_encryption_key() for why the hex string is avoided.
	return hash( 'sha256', wp_salt( 'secure_auth' ) . wp_salt( 'logged_in' ) . 'pmpro-smtp-mac', true );
}

/**
 * Encrypt a sensitive string for database storage.
 *
 * Uses AES-256-CBC (key derived from WordPress's auth salts) with an
 * encrypt-then-MAC (HMAC-SHA256) wrapper for integrity, and a version prefix so
 * decryption can tell encrypted values apart from legacy plaintext.
 *
 * If OpenSSL is unavailable the value cannot be encrypted; an empty string is
 * returned and the caller is expected to surface a notice rather than store the
 * secret in plaintext.
 *
 * @param string $value Plain text value.
 * @return string Prefixed, base64-encoded encrypted value, or empty string on failure.
 */
function pmpro_smtp_encrypt( $value ) {
	if ( '' === $value || false === $value ) {
		return '';
	}

	if ( ! pmpro_smtp_can_encrypt() ) {
		// Refuse to silently store plaintext secrets.
		return '';
	}

	$key    = pmpro_smtp_encryption_key();
	$iv_len = openssl_cipher_iv_length( 'AES-256-CBC' );
	$iv     = openssl_random_pseudo_bytes( $iv_len );

	$encrypted = openssl_encrypt( $value, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );

	if ( false === $encrypted ) {
		return '';
	}

	$payload = $iv . $encrypted;
	$mac     = hash_hmac( 'sha256', $payload, pmpro_smtp_mac_key(), true );

	return PMPRO_SMTP_ENC_PREFIX . base64_encode( $mac . $payload );
}

/**
 * Decrypt a value encrypted with pmpro_smtp_encrypt().
 *
 * Values that are not marked as encrypted (legacy plaintext stored before
 * encryption was added) are returned unchanged. Values that ARE marked
 * encrypted but fail integrity/decryption (e.g. after a salt rotation or DB
 * migration to a site with different salts) return an empty string rather than
 * handing back ciphertext to be used as a credential.
 *
 * @param string $value Stored value.
 * @return string Plain text, '' on decryption failure, or the original value if it was never encrypted.
 */
function pmpro_smtp_decrypt( $value ) {
	if ( empty( $value ) ) {
		return '';
	}

	// Not marked as encrypted by this plugin — treat as legacy plaintext.
	if ( 0 !== strpos( $value, PMPRO_SMTP_ENC_PREFIX ) ) {
		return $value;
	}

	if ( ! pmpro_smtp_can_encrypt() ) {
		return '';
	}

	$decoded = base64_decode( substr( $value, strlen( PMPRO_SMTP_ENC_PREFIX ) ), true );
	$iv_len  = openssl_cipher_iv_length( 'AES-256-CBC' );

	// MAC (32) + IV + at least one ciphertext block.
	if ( false === $decoded || strlen( $decoded ) <= ( 32 + $iv_len ) ) {
		return '';
	}

	$mac     = substr( $decoded, 0, 32 );
	$payload = substr( $decoded, 32 );

	$expected = hash_hmac( 'sha256', $payload, pmpro_smtp_mac_key(), true );
	if ( ! hash_equals( $expected, $mac ) ) {
		// Tampered or undecryptable (e.g. salts changed) — do not return ciphertext.
		return '';
	}

	$iv        = substr( $payload, 0, $iv_len );
	$encrypted = substr( $payload, $iv_len );
	$decrypted = openssl_decrypt( $encrypted, 'AES-256-CBC', pmpro_smtp_encryption_key(), OPENSSL_RAW_DATA, $iv );

	return ( false === $decrypted ) ? '' : $decrypted;
}
