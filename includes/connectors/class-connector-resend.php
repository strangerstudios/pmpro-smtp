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
				'desc'      => __( 'Create an API key in your Resend dashboard under API Keys. Stored encrypted.', 'pmpro-smtp' ),
			),
		);
	}

	protected function do_send( array $atts ) {
		$api_key = pmpro_smtp_decrypt( $this->get_setting( 'api_key' ) );

		if ( empty( $api_key ) ) {
			return new WP_Error( 'pmpro_smtp_missing_api_key', __( 'Resend API key is not configured.', 'pmpro-smtp' ) );
		}

		$sender     = $this->resolve_from( isset( $atts['headers'] ) ? $atts['headers'] : array() );
		$from_email = $sender['email'];
		$from_name  = $sender['name'];
		$from       = ! empty( $from_name ) ? sprintf( '%s <%s>', $from_name, $from_email ) : $from_email;

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
			$message       = ! empty( $decoded['message'] ) ? $decoded['message'] : $body_response;
			return new WP_Error( 'pmpro_smtp_send_failed', sprintf(
				/* translators: 1: HTTP response code, 2: error message from Resend */
				__( 'Resend error (%1$d): %2$s', 'pmpro-smtp' ),
				$code,
				$message
			) );
		}

		return true;
	}
}
