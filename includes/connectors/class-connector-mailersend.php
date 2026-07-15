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
				'desc'      => __( 'Create an API token in the MailerSend dashboard under Domains > your domain > API Tokens.', 'pmpro-smtp' ),
			),
		);
	}

	public function send( array $atts ) {
		$api_token = $this->get_setting( 'api_token' );

		if ( empty( $api_token ) ) {
			return new WP_Error( 'pmpro_smtp_missing_token', __( 'MailerSend API token is not configured.', 'pmpro-smtp' ) );
		}

		$sender = $this->resolve_from( isset( $atts['headers'] ) ? $atts['headers'] : array() );

		$to_parsed = $this->build_recipient_objects( $atts['to'] );

		$headers = $this->parse_headers( isset( $atts['headers'] ) ? $atts['headers'] : array() );
		$is_html = $this->is_html_message( $headers );

		$from_field = array( 'email' => $sender['email'] );
		if ( ! empty( $sender['name'] ) ) {
			$from_field['name'] = $sender['name'];
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

		$attachments = $this->build_base64_attachments( $atts, array( 'filename' => 'filename', 'contents' => 'content' ) );
		if ( ! empty( $attachments ) ) {
			$body['attachments'] = $attachments;
		}

		return $this->post_json( self::API_URL, array(
			'Authorization' => 'Bearer ' . $api_token,
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
		), $body, 'MailerSend', array( 'message' ) );
	}
}
