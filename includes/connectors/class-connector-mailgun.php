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
				'desc'      => __( 'Find your API key in the Mailgun dashboard under API Keys. Stored encrypted.', 'pmpro-smtp' ),
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

	protected function do_send( array $atts ) {
		$api_key = pmpro_smtp_decrypt( $this->get_setting( 'api_key' ) );
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

		$sender     = $this->resolve_from( isset( $atts['headers'] ) ? $atts['headers'] : array() );
		$from_email = $sender['email'];
		$from_name  = $sender['name'];
		$from       = ! empty( $from_name ) ? sprintf( '%s <%s>', $from_name, $from_email ) : $from_email;

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

		$files = array();
		if ( ! empty( $atts['attachments'] ) ) {
			foreach ( (array) $atts['attachments'] as $file ) {
				if ( file_exists( $file ) ) {
					$contents = file_get_contents( $file );
					if ( false === $contents ) {
						continue;
					}
					$files[] = array(
						'filename' => basename( $file ),
						'type'     => $this->get_mime_type( $file ),
						'contents' => $contents,
					);
				}
			}
		}

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

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code > 299 ) {
			$body_response = wp_remote_retrieve_body( $response );
			$decoded       = json_decode( $body_response, true );
			$message       = ! empty( $decoded['message'] ) ? $decoded['message'] : $body_response;
			return new WP_Error( 'pmpro_smtp_send_failed', sprintf(
				/* translators: 1: HTTP response code, 2: error message from Mailgun */
				__( 'Mailgun error (%1$d): %2$s', 'pmpro-smtp' ),
				$code,
				$message
			) );
		}

		return true;
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
			// Strip CR/LF from the value too. On the pre_wp_mail short-circuit path
			// WordPress core never runs its usual subject/header CRLF sanitization,
			// so a value containing a CRLF + "--<boundary>" could otherwise inject an
			// additional MIME part into the Mailgun request. Mirrors the filename/
			// name handling above. (Double-quotes are legal in a form-data value.)
			$value = str_replace( array( "\r", "\n" ), '', (string) $value );
			$data .= '--' . $boundary . $eol;
			$data .= 'Content-Disposition: form-data; name="' . $name . '"' . $eol . $eol;
			$data .= $value . $eol;
		}

		foreach ( $files as $file ) {
			// Strip CR/LF and double-quotes from the filename so it cannot break
			// out of the filename token and inject additional MIME part headers.
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
