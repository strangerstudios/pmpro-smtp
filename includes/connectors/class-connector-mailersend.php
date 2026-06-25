<?php
/**
 * MailerSend connector.
 *
 * @package PMProSMTP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PMPRO_SMTP_Connector_Mailersend extends PMPRO_SMTP_Connector_Base {

	const API_URL = 'https://api.mailersend.com/v1/email';

	public function get_name() {
		return 'mailersend';
	}

	public function get_title() {
		return 'MailerSend';
	}

	public function get_description() {
		return __( 'MailerSend is a transactional email platform with a generous free plan of 3,000 emails/month, detailed analytics, and simple API pricing.', 'pmpro-smtp' );
	}

	public function get_settings_fields() {
		return array(
			array(
				'key'       => 'api_token',
				'label'     => __( 'API Token', 'pmpro-smtp' ),
				'type'      => 'password',
				'sensitive' => true,
				'desc'      => __( 'Create an API token in the MailerSend dashboard under Domains > your domain > API Tokens. Stored encrypted.', 'pmpro-smtp' ),
			),
		);
	}

	protected function do_send( array $atts ) {
		$api_token = pmpro_smtp_decrypt( $this->get_setting( 'api_token' ) );

		if ( empty( $api_token ) ) {
			return new WP_Error( 'pmpro_smtp_missing_token', __( 'MailerSend API token is not configured.', 'pmpro-smtp' ) );
		}

		$sender     = $this->resolve_from( isset( $atts['headers'] ) ? $atts['headers'] : array() );
		$from_email = $sender['email'];
		$from_name  = $sender['name'];

		$to_parsed = array();
		foreach ( $this->normalize_recipients( $atts['to'] ) as $recipient ) {
			$parsed   = $this->parse_recipient( $recipient );
			$to_entry = array( 'email' => $parsed['email'] );
			if ( ! empty( $parsed['name'] ) ) {
				$to_entry['name'] = $parsed['name'];
			}
			$to_parsed[] = $to_entry;
		}

		$headers = $this->parse_headers( isset( $atts['headers'] ) ? $atts['headers'] : array() );
		$is_html = $this->is_html_message( $headers );

		$from_field = array( 'email' => $from_email );
		if ( ! empty( $from_name ) ) {
			$from_field['name'] = $from_name;
		}

		$body = array(
			'from'    => $from_field,
			'to'      => $to_parsed,
			'subject' => $atts['subject'],
		);

		if ( $is_html ) {
			$body['html'] = $atts['message'];
		} else {
			$body['text'] = $atts['message'];
		}

		$cc = $this->build_address_objects( isset( $headers['cc'] ) ? $headers['cc'] : '' );
		if ( ! empty( $cc ) ) {
			$body['cc'] = $cc;
		}
		$bcc = $this->build_address_objects( isset( $headers['bcc'] ) ? $headers['bcc'] : '' );
		if ( ! empty( $bcc ) ) {
			$body['bcc'] = $bcc;
		}
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
						'filename' => basename( $file ),
						'content'  => base64_encode( $contents ),
					);
				}
			}
			if ( ! empty( $attachments ) ) {
				$body['attachments'] = $attachments;
			}
		}

		$response = wp_safe_remote_post( self::API_URL, array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_token,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
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
			return new WP_Error( 'pmpro_smtp_send_failed', sprintf(
				/* translators: 1: HTTP response code, 2: error message from MailerSend */
				__( 'MailerSend error (%1$d): %2$s', 'pmpro-smtp' ),
				$code,
				$message
			) );
		}

		return true;
	}
}
