<?php
/**
 * SendGrid connector.
 *
 * @package PMProSMTP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PMPRO_SMTP_Connector_Sendgrid extends PMPRO_SMTP_Connector_Base {

	const API_URL = 'https://api.sendgrid.com/v3/mail/send';

	public function get_name() {
		return 'sendgrid';
	}

	public function get_title() {
		return 'SendGrid';
	}

	public function get_description() {
		return __( 'Send at scale with Twilio SendGrid, boasting an industry-leading 99% deliverability rate. SendGrid offers a free plan of 100 emails/day and paid plans starting at $19.95/month.', 'pmpro-smtp' );
	}

	public function get_settings_fields() {
		return array(
			array(
				'key'       => 'api_key',
				'label'     => __( 'API Key', 'pmpro-smtp' ),
				'type'      => 'password',
				'sensitive' => true,
				'desc'      => __( 'Create an API key in your SendGrid account under Settings > API Keys. Stored encrypted.', 'pmpro-smtp' ),
			),
		);
	}

	protected function do_send( array $atts ) {
		$api_key = pmpro_smtp_decrypt( $this->get_setting( 'api_key' ) );

		if ( empty( $api_key ) ) {
			return new WP_Error( 'pmpro_smtp_missing_api_key', __( 'SendGrid API key is not configured.', 'pmpro-smtp' ) );
		}

		$from_email = $this->get_from_email();
		$from_name  = $this->get_from_name();

		$to_parsed = array();
		$to        = is_array( $atts['to'] ) ? $atts['to'] : array( $atts['to'] );
		foreach ( $to as $recipient ) {
			$parsed      = $this->parse_recipient( $recipient );
			$to_entry    = array( 'email' => $parsed['email'] );
			if ( ! empty( $parsed['name'] ) ) {
				$to_entry['name'] = $parsed['name'];
			}
			$to_parsed[] = $to_entry;
		}

		$headers = $this->parse_headers( isset( $atts['headers'] ) ? $atts['headers'] : array() );
		$subject = $atts['subject'];
		$message = $atts['message'];

		$is_html = ! empty( $headers['content-type'] ) && strpos( $headers['content-type'], 'text/html' ) !== false;

		$content = array(
			array(
				'type'  => $is_html ? 'text/html' : 'text/plain',
				'value' => $message,
			),
		);

		$personalization = array( 'to' => $to_parsed );

		if ( ! empty( $headers['cc'] ) ) {
			$personalization['cc'] = array( array( 'email' => $headers['cc'] ) );
		}
		if ( ! empty( $headers['bcc'] ) ) {
			$personalization['bcc'] = array( array( 'email' => $headers['bcc'] ) );
		}

		$body = array(
			'personalizations' => array( $personalization ),
			'from'             => array_filter( array(
				'email' => $from_email,
				'name'  => $from_name,
			) ),
			'subject'          => $subject,
			'content'          => $content,
		);

		if ( ! empty( $headers['reply-to'] ) ) {
			$body['reply_to'] = array( 'email' => $headers['reply-to'] );
		}

		if ( ! empty( $atts['attachments'] ) ) {
			$attachments = array();
			foreach ( (array) $atts['attachments'] as $file ) {
				if ( file_exists( $file ) ) {
					$attachments[] = array(
						'content'  => base64_encode( file_get_contents( $file ) ),
						'filename' => basename( $file ),
						'type'     => mime_content_type( $file ),
					);
				}
			}
			if ( ! empty( $attachments ) ) {
				$body['attachments'] = $attachments;
			}
		}

		$response = wp_safe_remote_post( self::API_URL, array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
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
			$message       = ! empty( $decoded['errors'][0]['message'] ) ? $decoded['errors'][0]['message'] : $body_response;
			return new WP_Error( 'pmpro_smtp_send_failed', sprintf( __( 'SendGrid error (%d): %s', 'pmpro-smtp' ), $code, $message ) );
		}

		return true;
	}
}
