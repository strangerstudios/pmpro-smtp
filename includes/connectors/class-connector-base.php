<?php
/**
 * Base class for all PMPro SMTP connectors.
 *
 * @package PMProSMTP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class PMPRO_SMTP_Connector_Base {

	/**
	 * Get the connector's unique slug (e.g. 'sendgrid').
	 *
	 * @return string
	 */
	abstract public function get_name();

	/**
	 * Get the connector's display title (e.g. 'SendGrid').
	 *
	 * @return string
	 */
	abstract public function get_title();

	/**
	 * Get a short description of this connector.
	 *
	 * @return string
	 */
	public function get_description() {
		return '';
	}

	/**
	 * Get the settings fields for this connector.
	 *
	 * Each field is an array with keys:
	 *   - key        (string)  Option key within the connector's settings array.
	 *   - label      (string)  Human-readable label.
	 *   - type       (string)  'text', 'password', 'number', 'select', 'checkbox'.
	 *   - options    (array)   For select fields: value => label pairs.
	 *   - desc       (string)  Optional help text.
	 *   - placeholder (string) Optional placeholder.
	 *   - sensitive  (bool)    If true, value is encrypted at rest and obfuscated in UI.
	 *
	 * @return array
	 */
	abstract public function get_settings_fields();

	/**
	 * Perform the actual email send.
	 *
	 * @param array $atts {
	 *     @type string|string[] $to          Recipient(s).
	 *     @type string          $subject     Subject line.
	 *     @type string          $message     Email body.
	 *     @type string|string[] $headers     Extra headers.
	 *     @type string[]        $attachments File paths.
	 * }
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	abstract protected function do_send( array $atts );

	/**
	 * Send an email.
	 *
	 * Email logging is handled by PMPro core via wp_mail_succeeded / wp_mail_failed,
	 * which are fired by pmpro_smtp_pre_wp_mail() after this returns.
	 *
	 * @param array $atts Email attributes.
	 * @return true|WP_Error
	 */
	public function send( array $atts ) {
		return $this->do_send( $atts );
	}

	/**
	 * Get a saved setting value for this connector.
	 *
	 * @param string $key
	 * @param mixed  $default
	 * @return mixed
	 */
	public function get_setting( $key, $default = '' ) {
		$settings = get_option( 'pmpro_smtp_connector_' . $this->get_name(), array() );
		return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
	}

	/**
	 * Get the "from" email address, respecting the wp_mail_from filter.
	 *
	 * PMPro (and other plugins) set this via the wp_mail_from filter.
	 * We honour that rather than maintaining a parallel setting.
	 *
	 * @return string
	 */
	protected function get_from_email() {
		$forced_from_email = $this->get_forced_from_email();
		if ( ! empty( $forced_from_email ) ) {
			return $forced_from_email;
		}

		return apply_filters( 'wp_mail_from', get_option( 'admin_email' ) );
	}

	/**
	 * Get the "from" name, respecting the wp_mail_from_name filter.
	 *
	 * @return string
	 */
	protected function get_from_name() {
		return apply_filters( 'wp_mail_from_name', get_option( 'blogname' ) );
	}

	/**
	 * Allow connectors to override the sender email for strict SMTP servers.
	 *
	 * @return string
	 */
	protected function get_forced_from_email() {
		return '';
	}

	/**
	 * Parse a raw headers string or array into a key => value array.
	 *
	 * @param string|array $headers
	 * @return array
	 */
	protected function parse_headers( $headers ) {
		$parsed = array();
		if ( ! is_array( $headers ) ) {
			$headers = explode( "\n", str_replace( "\r\n", "\n", $headers ) );
		}
		foreach ( $headers as $header ) {
			if ( strpos( $header, ':' ) === false ) {
				continue;
			}
			list( $name, $value ) = explode( ':', trim( $header ), 2 );
			$parsed[ strtolower( trim( $name ) ) ] = trim( $value );
		}
		return $parsed;
	}

	/**
	 * Extract a To name from a "Name <email@example.com>" formatted string.
	 *
	 * @param string $recipient
	 * @return array { name: string, email: string }
	 */
	protected function parse_recipient( $recipient ) {
		$recipient = trim( $recipient );
		if ( preg_match( '/^(.+?)\s*<(.+?)>\s*$/', $recipient, $matches ) ) {
			return array( 'name' => trim( $matches[1] ), 'email' => trim( $matches[2] ) );
		}
		return array( 'name' => '', 'email' => $recipient );
	}
}
