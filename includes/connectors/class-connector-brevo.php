<?php
/**
 * Brevo (formerly Sendinblue) connector.
 *
 * @package PMProSMTP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PMPRO_SMTP_Connector_Brevo extends PMPRO_SMTP_Connector_Base {

	const API_URL = 'https://api.brevo.com/v3/smtp/email';

	public function get_name() {
		return 'brevo';
	}

	public function get_title() {
		return 'Brevo';
	}

	public function get_description() {
		return __( 'Brevo (formerly Sendinblue) offers transactional email sending via API. Free plan includes 300 emails/day with no daily sending limit on paid plans.', 'pmpro-smtp' );
	}

	public function get_settings_fields() {
		return array(
			array(
				'key'       => 'api_key',
				'label'     => __( 'API Key', 'pmpro-smtp' ),
				'type'      => 'password',
				'sensitive' => true,
				'desc'      => __( 'Find your API key in the Brevo dashboard under SMTP & API > API Keys. Stored encrypted.', 'pmpro-smtp' ),
			),
		);
	}

	protected function do_send( array $atts ) {
		$api_key = pmpro_smtp_decrypt( $this->get_setting( 'api_key' ) );

		if ( empty( $api_key ) ) {
			return new WP_Error( 'pmpro_smtp_missing_api_key', __( 'Brevo API key is not configured.', 'pmpro-smtp' ) );
		}

		$from_email = $this->get_from_email();
		$from_name  = $this->get_from_name();

		$to_parsed = array();
		$to        = is_array( $atts['to'] ) ? $atts['to'] : array( $atts['to'] );
		foreach ( $to as $recipient ) {
			$parsed   = $this->parse_recipient( $recipient );
			$to_entry = array( 'email' => $parsed['email'] );
			if ( ! empty( $parsed['name'] ) ) {
				$to_entry['name'] = $parsed['name'];
			}
			$to_parsed[] = $to_entry;
		}

		$headers = $this->parse_headers( isset( $atts['headers'] ) ? $atts['headers'] : array() );
		$is_html = ! empty( $headers['content-type'] ) && strpos( $headers['content-type'], 'text/html' ) !== false;

		$body = array(
			'sender'  => array_filter( array(
				'email' => $from_email,
				'name'  => $from_name,
			) ),
			'to'      => $to_parsed,
			'subject' => $atts['subject'],
		);

		if ( $is_html ) {
			$body['htmlContent'] = $atts['message'];
		} else {
			$body['textContent'] = $atts['message'];
		}

		if ( ! empty( $headers['cc'] ) ) {
			$body['cc'] = array( array( 'email' => $headers['cc'] ) );
		}
		if ( ! empty( $headers['bcc'] ) ) {
			$body['bcc'] = array( array( 'email' => $headers['bcc'] ) );
		}
		if ( ! empty( $headers['reply-to'] ) ) {
			$body['replyTo'] = array( 'email' => $headers['reply-to'] );
		}

		if ( ! empty( $atts['attachments'] ) ) {
			$attachments = array();
			foreach ( (array) $atts['attachments'] as $file ) {
				if ( file_exists( $file ) ) {
					$attachments[] = array(
						'name'    => basename( $file ),
						'content' => base64_encode( file_get_contents( $file ) ),
					);
				}
			}
			if ( ! empty( $attachments ) ) {
				$body['attachment'] = $attachments;
			}
		}

		$response = wp_safe_remote_post( self::API_URL, array(
			'headers' => array(
				'accept'       => 'application/json',
				'api-key'      => $api_key,
				'content-type' => 'application/json',
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
			$message       = ! empty( $decoded['message'] ) ? $decoded['message'] : $body_response;
			return new WP_Error( 'pmpro_smtp_send_failed', sprintf( __( 'Brevo error (%d): %s', 'pmpro-smtp' ), $code, $message ) );
		}

		return true;
	}
}
