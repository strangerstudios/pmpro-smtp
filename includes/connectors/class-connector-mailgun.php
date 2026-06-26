<?php
/**
 * Mailgun connector.
 *
 * @package PMProSMTP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PMPRO_SMTP_Connector_Mailgun extends PMPRO_SMTP_Connector_Base {

	public function get_name() {
		return 'mailgun';
	}

	public function get_title() {
		return 'Mailgun';
	}

	public function get_description() {
		return __( 'Mailgun is a transactional email service built for developers. It offers a free trial and competitive pricing based on email volume.', 'pmpro-smtp' );
	}

	public function get_settings_fields() {
		return array(
			array(
				'key'     => 'region',
				'label'   => __( 'Region', 'pmpro-smtp' ),
				'type'    => 'select',
				'options' => array(
					'us' => __( 'US (api.mailgun.net)', 'pmpro-smtp' ),
					'eu' => __( 'EU (api.eu.mailgun.net)', 'pmpro-smtp' ),
				),
				'desc'    => __( 'Select the region where your Mailgun account is hosted.', 'pmpro-smtp' ),
			),
			array(
				'key'       => 'api_key',
				'label'     => __( 'API Key', 'pmpro-smtp' ),
				'type'      => 'password',
				'sensitive' => true,
				'desc'      => __( 'Find your API key in the Mailgun dashboard under API Keys.', 'pmpro-smtp' ),
			),
			array(
				'key'         => 'domain',
				'label'       => __( 'Sending Domain', 'pmpro-smtp' ),
				'type'        => 'text',
				'placeholder' => 'mg.yourdomain.com',
				'desc'        => __( 'The Mailgun sending domain you have configured.', 'pmpro-smtp' ),
			),
		);
	}

	public function send( array $atts ) {
		$api_key = $this->get_setting( 'api_key' );
		$domain  = $this->get_setting( 'domain' );
		$region  = $this->get_setting( 'region', 'us' );

		if ( empty( $api_key ) ) {
			return new WP_Error( 'pmpro_smtp_missing_api_key', __( 'Mailgun API key is not configured.', 'pmpro-smtp' ) );
		}
		if ( empty( $domain ) ) {
			return new WP_Error( 'pmpro_smtp_missing_domain', __( 'Mailgun sending domain is not configured.', 'pmpro-smtp' ) );
		}

		$base_url = ( 'eu' === $region ) ? 'https://api.eu.mailgun.net' : 'https://api.mailgun.net';
		$api_url  = $base_url . '/v3/' . $domain . '/messages';

		$from    = $this->format_from( $atts );
		$to      = implode( ', ', $this->normalize_recipients( $atts['to'] ) );
		$headers = $this->parse_headers( isset( $atts['headers'] ) ? $atts['headers'] : array() );
		$is_html = $this->is_html_message( $headers );

		$body = array(
			'from'    => $from,
			'to'      => $to,
			'subject' => $atts['subject'],
		);

		if ( $is_html ) {
			$body['html'] = $atts['message'];
		} else {
			$body['text'] = $atts['message'];
		}

		if ( ! empty( $headers['cc'] ) ) {
			$body['cc'] = $headers['cc'];
		}
		if ( ! empty( $headers['bcc'] ) ) {
			$body['bcc'] = $headers['bcc'];
		}
		if ( ! empty( $headers['reply-to'] ) ) {
			$body['h:Reply-To'] = $headers['reply-to'];
		}

		$files = $this->read_attachments( $atts );

		$request_headers = array(
			'Authorization' => 'Basic ' . base64_encode( 'api:' . $api_key ),
		);

		// Mailgun expects file attachments as multipart/form-data uploads. The
		// WordPress HTTP API does not build multipart bodies from an array, so
		// when there are attachments we construct the multipart body manually
		// and set the matching Content-Type boundary header.
		if ( ! empty( $files ) ) {
			$boundary       = wp_generate_password( 24, false );
			$request_body   = $this->build_multipart_body( $body, $files, $boundary );
			$request_headers['Content-Type'] = 'multipart/form-data; boundary=' . $boundary;
		} else {
			$request_body = $body;
		}

		$response = wp_safe_remote_post( $api_url, array(
			'headers' => $request_headers,
			'body'    => $request_body,
			'timeout' => 15,
		) );

		return $this->handle_api_response( $response, 'Mailgun', array( 'message' ) );
	}

	/**
	 * Build a multipart/form-data request body for Mailgun (fields + file uploads).
	 *
	 * @param array  $fields   Scalar form fields (from, to, subject, body, etc.).
	 * @param array  $files    File parts, each { filename, type, contents }.
	 * @param string $boundary Multipart boundary string.
	 * @return string
	 */
	protected function build_multipart_body( array $fields, array $files, $boundary ) {
		$eol  = "\r\n";
		$data = '';

		foreach ( $fields as $name => $value ) {
			$name = str_replace( array( "\r", "\n", '"' ), '', (string) $name );
			// Strip CR/LF to prevent MIME part injection (double-quotes are legal here).
			$value = str_replace( array( "\r", "\n" ), '', (string) $value );
			$data .= '--' . $boundary . $eol;
			$data .= 'Content-Disposition: form-data; name="' . $name . '"' . $eol . $eol;
			$data .= $value . $eol;
		}

		foreach ( $files as $file ) {
			// Strip CR/LF and double-quotes from the filename to prevent MIME header injection.
			$filename = str_replace( array( "\r", "\n", '"' ), '', (string) $file['filename'] );
			$type     = str_replace( array( "\r", "\n", '"' ), '', (string) $file['type'] );
			$data    .= '--' . $boundary . $eol;
			$data    .= 'Content-Disposition: form-data; name="attachment"; filename="' . $filename . '"' . $eol;
			$data    .= 'Content-Type: ' . $type . $eol . $eol;
			$data    .= $file['contents'] . $eol;
		}

		$data .= '--' . $boundary . '--' . $eol;

		return $data;
	}
}
