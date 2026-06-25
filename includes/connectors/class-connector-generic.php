<?php
/**
 * Generic/Custom SMTP connector using PHPMailer.
 *
 * @package PMProSMTP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PMPRO_SMTP_Connector_Generic extends PMPRO_SMTP_Connector_Base {

	public function get_name() {
		return 'generic';
	}

	public function get_title() {
		return __( 'Custom SMTP', 'pmpro-smtp' );
	}

	public function get_description() {
		return __( 'Connect to any SMTP server using a host, port, username, and password. Compatible with any mail server or service that supports SMTP.', 'pmpro-smtp' );
	}

	public function get_settings_fields() {
		return array(
			array(
				'key'         => 'host',
				'label'       => __( 'SMTP Host', 'pmpro-smtp' ),
				'type'        => 'text',
				'placeholder' => 'smtp.example.com',
				'desc'        => __( 'The hostname of your outgoing mail server.', 'pmpro-smtp' ),
			),
			array(
				'key'         => 'port',
				'label'       => __( 'SMTP Port', 'pmpro-smtp' ),
				'type'        => 'number',
				'placeholder' => '587',
				'desc'        => __( 'Common ports: 587 (TLS), 465 (SSL), 25 (no encryption).', 'pmpro-smtp' ),
			),
			array(
				'key'     => 'encryption',
				'label'   => __( 'Encryption', 'pmpro-smtp' ),
				'type'    => 'select',
				'options' => array(
					''    => __( 'None', 'pmpro-smtp' ),
					'tls' => 'TLS',
					'ssl' => 'SSL',
				),
			),
			array(
				'key'   => 'auth',
				'label' => __( 'Authentication', 'pmpro-smtp' ),
				'type'  => 'checkbox',
				'desc'  => __( 'Use SMTP authentication (recommended).', 'pmpro-smtp' ),
			),
			array(
				'key'   => 'username',
				'label' => __( 'Username', 'pmpro-smtp' ),
				'type'  => 'text',
				'desc'  => __( 'Usually your full email address.', 'pmpro-smtp' ),
			),
			array(
				'key'       => 'password',
				'label'     => __( 'Password', 'pmpro-smtp' ),
				'type'      => 'password',
				'sensitive' => true,
				'desc'      => __( 'Your SMTP password or app password. Stored encrypted.', 'pmpro-smtp' ),
			),
			array(
				'key'   => 'force_from_email',
				'label' => __( 'Sender Address Compatibility', 'pmpro-smtp' ),
				'type'  => 'checkbox',
				'desc'  => __( 'Use the SMTP username as the From email address for this connection. Some SMTP servers require the sender address to match the authenticated account.', 'pmpro-smtp' ),
			),
		);
	}

	/**
	 * The Generic connector never sends through this method.
	 *
	 * Real mail and the Send Test Email tool both call WordPress's wp_mail(),
	 * which for the Generic connector is configured via the phpmailer_init hook
	 * (see pmpro_smtp_phpmailer_init() -> configure_phpmailer()). The
	 * pre_wp_mail interception in pmpro_smtp_pre_wp_mail() also passes the
	 * Generic connector straight through without calling send()/do_send(). This
	 * implementation only exists to satisfy the abstract base class.
	 *
	 * @param array $atts
	 * @return WP_Error
	 */
	protected function do_send( array $atts ) {
		return new WP_Error(
			'pmpro_smtp_generic_uses_phpmailer',
			__( 'The Custom SMTP connector sends via WordPress core (phpmailer_init), not the API send path.', 'pmpro-smtp' )
		);
	}

	/**
	 * Configure an existing PHPMailer instance with our SMTP settings.
	 *
	 * Runs on the phpmailer_init hook for every email (real and test) when the
	 * Generic connector is active, so the From-address handling here applies to
	 * both production and test mail.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer
	 */
	public function configure_phpmailer( $phpmailer ) {
		$host       = $this->get_setting( 'host' );
		$port       = (int) $this->get_setting( 'port', 587 );
		$encryption = $this->get_setting( 'encryption', '' );
		$auth       = (bool) $this->get_setting( 'auth', false );
		$username   = $this->get_setting( 'username' );
		$password   = pmpro_smtp_decrypt( $this->get_setting( 'password' ) );

		if ( empty( $host ) ) {
			return;
		}

		$phpmailer->isSMTP();
		$phpmailer->Host     = $host;
		$phpmailer->Port     = $port ? $port : 587;
		$phpmailer->SMTPAuth = $auth;

		if ( 'ssl' === $encryption ) {
			$phpmailer->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
		} elseif ( 'tls' === $encryption ) {
			$phpmailer->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
		} else {
			$phpmailer->SMTPSecure  = '';
			$phpmailer->SMTPAutoTLS = false;
		}

		if ( $auth ) {
			$phpmailer->Username = $username;
			$phpmailer->Password = $password;
		}

		// Sender Address Compatibility: force the From address to the SMTP
		// username for strict servers. This must run on the phpmailer_init path
		// (not just a test-only send) so it affects real outgoing mail too.
		$forced_from = $this->get_forced_from_email();
		if ( ! empty( $forced_from ) ) {
			// WordPress core has already resolved the From name (from the
			// message's From: header and/or the wp_mail_from_name filter) and set
			// it on the PHPMailer instance before phpmailer_init runs. Preserve
			// that name and only override the address. The third argument (false)
			// prevents PHPMailer from auto-overriding the address again.
			$from_name = '' !== $phpmailer->FromName ? $phpmailer->FromName : $this->get_from_name();
			$phpmailer->setFrom( $forced_from, $from_name, false );
		}
	}

	/**
	 * Force the sender email to the SMTP username when enabled.
	 *
	 * @return string
	 */
	protected function get_forced_from_email() {
		$force_from_email = (bool) $this->get_setting( 'force_from_email', false );
		$username         = $this->get_setting( 'username' );

		if ( $force_from_email && is_email( $username ) ) {
			return $username;
		}

		return '';
	}
}
