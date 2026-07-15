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
				'desc'      => __( 'Create an API key in your SendGrid account under Settings > API Keys.', 'pmpro-smtp' ),
			),
		);
	}

	public function send( array $atts ) {
		$api_key = $this->get_setting( 'api_key' );

		if ( empty( $api_key ) ) {
			return new WP_Error( 'pmpro_smtp_missing_api_key', __( 'SendGrid API key is not configured.', 'pmpro-smtp' ) );
		}

		$headers = $this->parse_headers( isset( $atts['headers'] ) ? $atts['headers'] : array() );

		$from = $this->resolve_from( isset( $atts['headers'] ) ? $atts['headers'] : array() );

		$to_parsed = $this->build_recipient_objects( $atts['to'] );

		$is_html = $this->is_html_message( $headers );

		$content = array(
			array(
				'type'  => $is_html ? 'text/html' : 'text/plain',
				'value' => $atts['message'],
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
				'email' => $from['email'],
				'name'  => $from['name'],
			) ),
			'subject'          => $atts['subject'],
			'content'          => $content,
		);

		if ( ! empty( $headers['reply-to'] ) ) {
			$reply = $this->build_reply_to_object( $headers['reply-to'] );
			if ( ! empty( $reply ) ) {
				$body['reply_to'] = $reply;
			}
		}

		$attachments = $this->build_base64_attachments( $atts, array( 'contents' => 'content', 'filename' => 'filename', 'type' => 'type' ) );
		if ( ! empty( $attachments ) ) {
			$body['attachments'] = $attachments;
		}

		return $this->post_json( self::API_URL, array(
			'Authorization' => 'Bearer ' . $api_key,
			'Content-Type'  => 'application/json',
		), $body, 'SendGrid', array( 'errors', 0, 'message' ) );
	}
}
