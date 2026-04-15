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
	 * For the Generic connector the actual phpmailer configuration happens in
	 * pmpro_smtp_phpmailer_init() via the 'phpmailer_init' hook so that WordPress's
	 * standard wp_mail() flow handles recipients, body, attachments, etc.
	 *
	 * This do_send() implementation is only used for the Send Test Email tool.
	 *
	 * @param array $atts
	 * @return true|WP_Error
	 */
	protected function do_send( array $atts ) {
		require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
		require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
		require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';

		$phpmailer = new PHPMailer\PHPMailer\PHPMailer( true );

		try {
			$this->configure_phpmailer( $phpmailer );

			$phpmailer->setFrom( $this->get_from_email(), $this->get_from_name() );

			$to = is_array( $atts['to'] ) ? $atts['to'] : array( $atts['to'] );
			foreach ( $to as $recipient ) {
				$parsed = $this->parse_recipient( $recipient );
				$phpmailer->addAddress( $parsed['email'], $parsed['name'] );
			}

			$headers = $this->parse_headers( isset( $atts['headers'] ) ? $atts['headers'] : array() );
			if ( ! empty( $headers['cc'] ) ) {
				$phpmailer->addCC( $headers['cc'] );
			}
			if ( ! empty( $headers['bcc'] ) ) {
				$phpmailer->addBCC( $headers['bcc'] );
			}
			if ( ! empty( $headers['reply-to'] ) ) {
				$phpmailer->addReplyTo( $headers['reply-to'] );
			}
			if ( ! empty( $headers['content-type'] ) && strpos( $headers['content-type'], 'text/html' ) !== false ) {
				$phpmailer->isHTML( true );
			}

			$phpmailer->Subject = $atts['subject'];
			$phpmailer->Body    = $atts['message'];

			if ( ! empty( $atts['attachments'] ) ) {
				foreach ( (array) $atts['attachments'] as $file ) {
					if ( file_exists( $file ) ) {
						$phpmailer->addAttachment( $file );
					}
				}
			}

			$phpmailer->send();
			return true;

		} catch ( PHPMailer\PHPMailer\Exception $e ) {
			return new WP_Error( 'pmpro_smtp_send_failed', $e->getMessage() );
		}
	}

	/**
	 * Configure an existing PHPMailer instance with our SMTP settings.
	 *
	 * Used by both do_send() (test email) and the phpmailer_init hook.
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
