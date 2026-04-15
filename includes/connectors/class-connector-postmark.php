<?php
/**
 * Postmark connector.
 *
 * @package PMProSMTP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PMPRO_SMTP_Connector_Postmark extends PMPRO_SMTP_Connector_Base {

	const API_URL = 'https://api.postmarkapp.com/email';

	public function get_name() {
		return 'postmark';
	}

	public function get_title() {
		return 'Postmark';
	}

	public function get_description() {
		return __( 'Postmark is a transactional email service known for fast, reliable delivery and detailed analytics. Plans start at $15/month for 10,000 emails.', 'pmpro-smtp' );
	}

	public function get_settings_fields() {
		return array(
			array(
				'key'       => 'server_token',
				'label'     => __( 'Server Token', 'pmpro-smtp' ),
				'type'      => 'password',
				'sensitive' => true,
				'desc'      => __( 'Find your Server Token in the Postmark dashboard under your Server > API Tokens. Stored encrypted.', 'pmpro-smtp' ),
			),
		);
	}

	protected function do_send( array $atts ) {
		$server_token = pmpro_smtp_decrypt( $this->get_setting( 'server_token' ) );

		if ( empty( $server_token ) ) {
			return new WP_Error( 'pmpro_smtp_missing_token', __( 'Postmark server token is not configured.', 'pmpro-smtp' ) );
		}

		$from_email = $this->get_from_email();
		$from_name  = $this->get_from_name();
		$from       = ! empty( $from_name ) ? sprintf( '%s <%s>', $from_name, $from_email ) : $from_email;

		$to      = is_array( $atts['to'] ) ? implode( ', ', $atts['to'] ) : $atts['to'];
		$headers = $this->parse_headers( isset( $atts['headers'] ) ? $atts['headers'] : array() );
		$is_html = ! empty( $headers['content-type'] ) && strpos( $headers['content-type'], 'text/html' ) !== false;

		$body = array(
			'From'    => $from,
			'To'      => $to,
			'Subject' => $atts['subject'],
		);

		if ( $is_html ) {
			$body['HtmlBody'] = $atts['message'];
		} else {
			$body['TextBody'] = $atts['message'];
		}

		if ( ! empty( $headers['cc'] ) ) {
			$body['Cc'] = $headers['cc'];
		}
		if ( ! empty( $headers['bcc'] ) ) {
			$body['Bcc'] = $headers['bcc'];
		}
		if ( ! empty( $headers['reply-to'] ) ) {
			$body['ReplyTo'] = $headers['reply-to'];
		}

		if ( ! empty( $atts['attachments'] ) ) {
			$attachments = array();
			foreach ( (array) $atts['attachments'] as $file ) {
				if ( file_exists( $file ) ) {
					$attachments[] = array(
						'Name'        => basename( $file ),
						'Content'     => base64_encode( file_get_contents( $file ) ),
						'ContentType' => mime_content_type( $file ),
					);
				}
			}
			if ( ! empty( $attachments ) ) {
				$body['Attachments'] = $attachments;
			}
		}

		$response = wp_safe_remote_post( self::API_URL, array(
			'headers' => array(
				'Accept'                  => 'application/json',
				'Content-Type'            => 'application/json',
				'X-Postmark-Server-Token' => $server_token,
			),
			'body'    => wp_json_encode( $body ),
			'timeout' => 15,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code > 299 ) {
			$body_response = wp_remote_retrieve_body( $response );
			$decoded       = json_decode( $body_response, true );
			$message       = ! empty( $decoded['Message'] ) ? $decoded['Message'] : $body_response;
			return new WP_Error( 'pmpro_smtp_send_failed', sprintf( __( 'Postmark error (%d): %s', 'pmpro-smtp' ), $code, $message ) );
		}

		return true;
	}
}
