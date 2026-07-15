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
				'desc'      => __( 'Your SMTP password or app password.', 'pmpro-smtp' ),
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
		$port       = (int) $this->get_setting( 'port' );
		$encryption = $this->get_setting( 'encryption', '' );
		$auth       = (bool) $this->get_setting( 'auth', false );
		$username   = $this->get_setting( 'username' );
		$password   = $this->get_setting( 'password' );

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
		if ( (bool) $this->get_setting( 'force_from_email', false ) && is_email( $username ) ) {
			// Preserve the resolved From name, override only the address.
			$from_name = '' !== $phpmailer->FromName ? $phpmailer->FromName : apply_filters( 'wp_mail_from_name', 'WordPress' );
			$phpmailer->setFrom( $username, $from_name, false );
		}
	}
}
