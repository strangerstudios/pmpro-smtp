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

		$from_email = $this->get_from_email();
		$from_name  = $this->get_from_name();
		$from       = ! empty( $from_name ) ? sprintf( '%s <%s>', $from_name, $from_email ) : $from_email;

		$to      = is_array( $atts['to'] ) ? implode( ', ', $atts['to'] ) : $atts['to'];
		$headers = $this->parse_headers( isset( $atts['headers'] ) ? $atts['headers'] : array() );
		$is_html = ! empty( $headers['content-type'] ) && strpos( $headers['content-type'], 'text/html' ) !== false;

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
					$files[] = array(
						'name'     => 'attachment[]',
						'filename' => basename( $file ),
						'contents' => file_get_contents( $file ),
					);
				}
			}
		}

		$response = wp_safe_remote_post( $api_url, array(
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( 'api:' . $api_key ),
			),
			'body'    => $body,
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
			return new WP_Error( 'pmpro_smtp_send_failed', sprintf( __( 'Mailgun error (%d): %s', 'pmpro-smtp' ), $code, $message ) );
		}

		return true;
	}
}
