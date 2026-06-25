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

		$headers = $this->parse_headers( isset( $atts['headers'] ) ? $atts['headers'] : array() );

		$from       = $this->resolve_from( isset( $atts['headers'] ) ? $atts['headers'] : array() );
		$from_email = $from['email'];
		$from_name  = $from['name'];

		$to_parsed = array();
		foreach ( $this->normalize_recipients( $atts['to'] ) as $recipient ) {
			$parsed      = $this->parse_recipient( $recipient );
			$to_entry    = array( 'email' => $parsed['email'] );
			if ( ! empty( $parsed['name'] ) ) {
				$to_entry['name'] = $parsed['name'];
			}
			$to_parsed[] = $to_entry;
		}

		$subject = $atts['subject'];
		$message = $atts['message'];

		$is_html = $this->is_html_message( $headers );

		$content = array(
			array(
				'type'  => $is_html ? 'text/html' : 'text/plain',
				'value' => $message,
			),
		);

		$personalization = array( 'to' => $to_parsed );

		$cc = $this->build_address_objects( isset( $headers['cc'] ) ? $headers['cc'] : '' );
		if ( ! empty( $cc ) ) {
			$personalization['cc'] = $cc;
		}
		$bcc = $this->build_address_objects( isset( $headers['bcc'] ) ? $headers['bcc'] : '' );
		if ( ! empty( $bcc ) ) {
			$personalization['bcc'] = $bcc;
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
			$reply = $this->build_reply_to_object( $headers['reply-to'] );
			if ( ! empty( $reply ) ) {
				$body['reply_to'] = $reply;
			}
		}

		if ( ! empty( $atts['attachments'] ) ) {
			$attachments = array();
			foreach ( (array) $atts['attachments'] as $file ) {
				if ( file_exists( $file ) ) {
					$contents = file_get_contents( $file );
					if ( false === $contents ) {
						continue;
					}
					$attachments[] = array(
						'content'  => base64_encode( $contents ),
						'filename' => basename( $file ),
						'type'     => $this->get_mime_type( $file ),
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
			return new WP_Error( 'pmpro_smtp_send_failed', sprintf(
				/* translators: 1: HTTP response code, 2: error message from SendGrid */
				__( 'SendGrid error (%1$d): %2$s', 'pmpro-smtp' ),
				$code,
				$message
			) );
		}

		return true;
	}
}
