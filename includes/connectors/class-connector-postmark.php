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
				'desc'      => __( 'Find your Server Token in the Postmark dashboard under your Server > API Tokens.', 'pmpro-smtp' ),
			),
		);
	}

	public function send( array $atts ) {
		$server_token = $this->get_setting( 'server_token' );

		if ( empty( $server_token ) ) {
			return new WP_Error( 'pmpro_smtp_missing_token', __( 'Postmark server token is not configured.', 'pmpro-smtp' ) );
		}

		$from    = $this->format_from( $atts );
		$to      = implode( ', ', $this->normalize_recipients( $atts['to'] ) );
		$headers = $this->parse_headers( isset( $atts['headers'] ) ? $atts['headers'] : array() );
		$is_html = $this->is_html_message( $headers );

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

		$attachments = $this->build_base64_attachments( $atts, array( 'filename' => 'Name', 'contents' => 'Content', 'type' => 'ContentType' ) );
		if ( ! empty( $attachments ) ) {
			$body['Attachments'] = $attachments;
		}

		return $this->post_json( self::API_URL, array(
			'Accept'                  => 'application/json',
			'Content-Type'            => 'application/json',
			'X-Postmark-Server-Token' => $server_token,
		), $body, 'Postmark', array( 'Message' ) );
	}
}
