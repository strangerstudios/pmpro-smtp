<?php
/**
 * Resend connector.
 *
 * @package PMProSMTP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PMPRO_SMTP_Connector_Resend extends PMPRO_SMTP_Connector_Base {

	const API_URL = 'https://api.resend.com/emails';

	public function get_name() {
		return 'resend';
	}

	public function get_title() {
		return 'Resend';
	}

	public function get_description() {
		return __( 'Resend is a modern email API built for developers. It offers a generous free plan of 3,000 emails/month and straightforward pricing beyond that.', 'pmpro-smtp' );
	}

	public function get_settings_fields() {
		return array(
			array(
				'key'       => 'api_key',
				'label'     => __( 'API Key', 'pmpro-smtp' ),
				'type'      => 'password',
				'sensitive' => true,
				'desc'      => __( 'Create an API key in your Resend dashboard under API Keys.', 'pmpro-smtp' ),
			),
		);
	}

	public function send( array $atts ) {
		$api_key = $this->get_setting( 'api_key' );

		if ( empty( $api_key ) ) {
			return new WP_Error( 'pmpro_smtp_missing_api_key', __( 'Resend API key is not configured.', 'pmpro-smtp' ) );
		}

		$from    = $this->format_from( $atts );
		$to      = $this->normalize_recipients( $atts['to'] );
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

		$cc = $this->split_address_list( isset( $headers['cc'] ) ? $headers['cc'] : '' );
		if ( ! empty( $cc ) ) {
			$body['cc'] = $cc;
		}
		$bcc = $this->split_address_list( isset( $headers['bcc'] ) ? $headers['bcc'] : '' );
		if ( ! empty( $bcc ) ) {
			$body['bcc'] = $bcc;
		}
		if ( ! empty( $headers['reply-to'] ) ) {
			$body['reply_to'] = $this->split_address_list( $headers['reply-to'] );
		}

		$attachments = $this->build_base64_attachments( $atts, array( 'filename' => 'filename', 'contents' => 'content' ) );
		if ( ! empty( $attachments ) ) {
			$body['attachments'] = $attachments;
		}

		return $this->post_json( self::API_URL, array(
			'Authorization' => 'Bearer ' . $api_key,
			'Content-Type'  => 'application/json',
		), $body, 'Resend', array( 'message' ) );
	}
}
