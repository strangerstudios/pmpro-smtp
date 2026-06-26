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
				'desc'      => __( 'Find your API key in the Brevo dashboard under SMTP & API > API Keys.', 'pmpro-smtp' ),
			),
		);
	}

	public function send( array $atts ) {
		$api_key = $this->get_setting( 'api_key' );

		if ( empty( $api_key ) ) {
			return new WP_Error( 'pmpro_smtp_missing_api_key', __( 'Brevo API key is not configured.', 'pmpro-smtp' ) );
		}

		$sender = $this->resolve_from( isset( $atts['headers'] ) ? $atts['headers'] : array() );

		$to_parsed = $this->build_recipient_objects( $atts['to'] );

		$headers = $this->parse_headers( isset( $atts['headers'] ) ? $atts['headers'] : array() );
		$is_html = $this->is_html_message( $headers );

		$body = array(
			'sender'  => array_filter( array(
				'email' => $sender['email'],
				'name'  => $sender['name'],
			) ),
			'to'      => $to_parsed,
			'subject' => $atts['subject'],
		);

		if ( $is_html ) {
			$body['htmlContent'] = $atts['message'];
		} else {
			$body['textContent'] = $atts['message'];
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
				$body['replyTo'] = $reply;
			}
		}

		$attachments = $this->build_base64_attachments( $atts, array( 'filename' => 'name', 'contents' => 'content' ) );
		if ( ! empty( $attachments ) ) {
			$body['attachment'] = $attachments;
		}

		return $this->post_json( self::API_URL, array(
			'accept'       => 'application/json',
			'api-key'      => $api_key,
			'content-type' => 'application/json',
		), $body, 'Brevo', array( 'message' ) );
	}
}
