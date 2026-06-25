<?php
/**
 * Microsoft 365 / Outlook connector using Microsoft Graph sendMail.
 *
 * @package PMProSMTP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PMPRO_SMTP_Connector_Microsoft365 extends PMPRO_SMTP_Connector_Base {

	const GRAPH_SENDMAIL_URL = 'https://graph.microsoft.com/v1.0/me/sendMail';
	const GRAPH_ME_URL       = 'https://graph.microsoft.com/v1.0/me';

	public function get_name() {
		return 'microsoft365';
	}

	public function get_title() {
		return __( 'Microsoft 365 / Outlook', 'pmpro-smtp' );
	}

	public function get_description() {
		return __( 'Send mail through Microsoft Graph using OAuth 2.0. No Microsoft account password is stored.', 'pmpro-smtp' );
	}

	public function get_settings_fields() {
		return array(
			array(
				'key'   => 'client_id',
				'label' => __( 'Client ID', 'pmpro-smtp' ),
				'type'  => 'text',
				'desc'  => __( 'The Application (client) ID from your Microsoft Entra app registration.', 'pmpro-smtp' ),
			),
			array(
				'key'       => 'client_secret',
				'label'     => __( 'Client Secret', 'pmpro-smtp' ),
				'type'      => 'password',
				'sensitive' => true,
				'desc'      => __( 'Create a client secret in Microsoft Entra. Client secrets expire, so note the expiration date.', 'pmpro-smtp' ),
			),
			array(
				'key'     => 'tenant_mode',
				'label'   => __( 'Tenant Mode', 'pmpro-smtp' ),
				'type'    => 'select',
				'options' => array(
					'organizations' => __( 'Work or school accounts', 'pmpro-smtp' ),
					'common'        => __( 'Any Microsoft Entra organization', 'pmpro-smtp' ),
					'tenant'        => __( 'Specific tenant ID', 'pmpro-smtp' ),
				),
				'desc'    => __( 'Most Microsoft 365 sites should use Work or school accounts. Outlook.com personal accounts are not supported by this provider.', 'pmpro-smtp' ),
			),
			array(
				'key'         => 'tenant_id',
				'label'       => __( 'Tenant ID', 'pmpro-smtp' ),
				'type'        => 'text',
				'placeholder' => '00000000-0000-0000-0000-000000000000',
				'desc'        => __( 'Required only when Tenant Mode is set to Specific tenant ID.', 'pmpro-smtp' ),
			),
			array(
				'key'         => 'mailbox',
				'label'       => __( 'From Email / Mailbox', 'pmpro-smtp' ),
				'type'        => 'email',
				'placeholder' => 'name@example.com',
				'desc'        => __( 'The Microsoft 365 mailbox used for Graph sendMail. This should match your site sender unless the authenticated account has send-as permission.', 'pmpro-smtp' ),
			),
		);
	}

	/**
	 * Get OAuth redirect URI.
	 *
	 * @return string
	 */
	public function get_redirect_uri() {
		return rest_url( 'pmpro-smtp/v1/microsoft365/callback' );
	}

	/**
	 * Get scopes requested for delegated Graph sending.
	 *
	 * @return string
	 */
	public function get_scope() {
		return 'openid profile offline_access User.Read Mail.Send Mail.Send.Shared';
	}

	/**
	 * Get Microsoft tenant path.
	 *
	 * @return string
	 */
	public function get_tenant() {
		$mode      = $this->get_setting( 'tenant_mode', 'organizations' );
		$tenant_id = trim( $this->get_setting( 'tenant_id' ) );

		if ( 'tenant' === $mode && ! empty( $tenant_id ) ) {
			return rawurlencode( $tenant_id );
		}

		if ( 'common' === $mode ) {
			return 'common';
		}

		return 'organizations';
	}

	/**
	 * Get authorize endpoint.
	 *
	 * @return string
	 */
	public function get_authorize_url() {
		return 'https://login.microsoftonline.com/' . $this->get_tenant() . '/oauth2/v2.0/authorize';
	}

	/**
	 * Get token endpoint.
	 *
	 * @return string
	 */
	public function get_token_url() {
		return 'https://login.microsoftonline.com/' . $this->get_tenant() . '/oauth2/v2.0/token';
	}

	/**
	 * Build the Microsoft authorization URL.
	 *
	 * @param string $state          OAuth state.
	 * @param string $code_challenge PKCE code challenge.
	 * @return string|WP_Error
	 */
	public function build_authorization_url( $state, $code_challenge = '' ) {
		$client_id = $this->get_setting( 'client_id' );

		if ( empty( $client_id ) ) {
			return new WP_Error( 'pmpro_smtp_microsoft_missing_client_id', __( 'Microsoft 365 Client ID is not configured.', 'pmpro-smtp' ) );
		}

		$args = array(
			'client_id'     => $client_id,
			'response_type' => 'code',
			'redirect_uri'  => $this->get_redirect_uri(),
			'response_mode' => 'query',
			'scope'         => $this->get_scope(),
			'state'         => $state,
			'prompt'        => 'select_account',
		);

		if ( ! empty( $code_challenge ) ) {
			$args['code_challenge']        = $code_challenge;
			$args['code_challenge_method'] = 'S256';
		}

		return add_query_arg(
			$args,
			$this->get_authorize_url()
		);
	}

	/**
	 * Exchange an authorization code for tokens.
	 *
	 * @param string $code          Authorization code.
	 * @param string $code_verifier PKCE code verifier.
	 * @return array|WP_Error
	 */
	public function exchange_authorization_code( $code, $code_verifier = '' ) {
		$body = array(
			'grant_type'   => 'authorization_code',
			'code'         => $code,
			'redirect_uri' => $this->get_redirect_uri(),
		);

		if ( ! empty( $code_verifier ) ) {
			$body['code_verifier'] = $code_verifier;
		}

		return $this->request_token( $body );
	}

	/**
	 * Refresh the stored access token when needed.
	 *
	 * @return string|WP_Error
	 */
	public function get_access_token() {
		$access_token = pmpro_smtp_decrypt( $this->get_setting( 'access_token' ) );
		$expires_at   = (int) $this->get_setting( 'expires_at', 0 );

		if ( ! empty( $access_token ) && $expires_at > ( time() + 300 ) ) {
			return $access_token;
		}

		$refresh_token = pmpro_smtp_decrypt( $this->get_setting( 'refresh_token' ) );
		if ( empty( $refresh_token ) ) {
			return new WP_Error( 'pmpro_smtp_microsoft_not_connected', __( 'Microsoft 365 is not connected. Reconnect the account and try again.', 'pmpro-smtp' ) );
		}

		$tokens = $this->request_token(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh_token,
			)
		);

		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}

		$this->store_tokens( $tokens );

		return $tokens['access_token'];
	}

	/**
	 * Force refresh the stored access token.
	 *
	 * @return string|WP_Error
	 */
	public function refresh_access_token() {
		$refresh_token = pmpro_smtp_decrypt( $this->get_setting( 'refresh_token' ) );
		if ( empty( $refresh_token ) ) {
			return new WP_Error( 'pmpro_smtp_microsoft_not_connected', __( 'Microsoft 365 is not connected. Reconnect the account and try again.', 'pmpro-smtp' ) );
		}

		$tokens = $this->request_token(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh_token,
			)
		);

		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}

		$this->store_tokens( $tokens );

		return $tokens['access_token'];
	}

	/**
	 * Store OAuth tokens encrypted.
	 *
	 * @param array $tokens Token response.
	 * @return void
	 */
	public function store_tokens( $tokens ) {
		$settings = get_option( 'pmpro_smtp_connector_' . $this->get_name(), array() );

		if ( ! empty( $tokens['access_token'] ) ) {
			$settings['access_token'] = pmpro_smtp_encrypt( $tokens['access_token'] );
		}

		if ( ! empty( $tokens['refresh_token'] ) ) {
			$settings['refresh_token'] = pmpro_smtp_encrypt( $tokens['refresh_token'] );
		}

		if ( ! empty( $tokens['expires_in'] ) ) {
			$settings['expires_at'] = time() + (int) $tokens['expires_in'];
		}

		update_option( 'pmpro_smtp_connector_' . $this->get_name(), $settings );
	}

	/**
	 * Clear OAuth tokens.
	 *
	 * @return void
	 */
	public function disconnect() {
		$settings = get_option( 'pmpro_smtp_connector_' . $this->get_name(), array() );

		unset(
			$settings['access_token'],
			$settings['refresh_token'],
			$settings['expires_at'],
			$settings['authenticated_email'],
			$settings['token_error'],
			$settings['authenticated_tenant']
		);

		update_option( 'pmpro_smtp_connector_' . $this->get_name(), $settings );
	}

	/**
	 * Check whether the connector has a refresh token.
	 *
	 * @return bool
	 */
	public function is_connected() {
		return '' !== pmpro_smtp_decrypt( $this->get_setting( 'refresh_token' ) );
	}

	/**
	 * Get the configured mailbox.
	 *
	 * @return string
	 */
	public function get_mailbox() {
		$mailbox = sanitize_email( $this->get_setting( 'mailbox' ) );

		if ( empty( $mailbox ) ) {
			$mailbox = sanitize_email( $this->get_from_email() );
		}

		return $mailbox;
	}

	/**
	 * Send an email through Microsoft Graph.
	 *
	 * @param array $atts Email attributes.
	 * @return true|WP_Error
	 */
	protected function do_send( array $atts ) {
		$access_token = $this->get_access_token();
		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$mailbox = $this->get_mailbox();
		if ( empty( $mailbox ) ) {
			return new WP_Error( 'pmpro_smtp_microsoft_missing_mailbox', __( 'Microsoft 365 From Email / Mailbox is not configured.', 'pmpro-smtp' ) );
		}

		$mime = $this->build_mime_message( $atts );
		if ( is_wp_error( $mime ) ) {
			return $mime;
		}

		$response = wp_safe_remote_post(
			self::GRAPH_SENDMAIL_URL,
			$this->get_graph_send_args( $access_token, $mime )
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'pmpro_smtp_microsoft_http_error', $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 401 === (int) $code ) {
			$access_token = $this->refresh_access_token();
			if ( is_wp_error( $access_token ) ) {
				return $access_token;
			}

			$response = wp_safe_remote_post(
				self::GRAPH_SENDMAIL_URL,
				$this->get_graph_send_args( $access_token, $mime )
			);

			if ( is_wp_error( $response ) ) {
				return new WP_Error( 'pmpro_smtp_microsoft_http_error', $response->get_error_message() );
			}

			$code = wp_remote_retrieve_response_code( $response );
		}

		if ( 202 !== (int) $code ) {
			return $this->parse_graph_error( $response );
		}

		return true;
	}

	/**
	 * Get Graph send request args.
	 *
	 * @param string $access_token Access token.
	 * @param string $mime         MIME message.
	 * @return array
	 */
	protected function get_graph_send_args( $access_token, $mime ) {
		return array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $access_token,
				'Content-Type'  => 'text/plain',
			),
			'body'    => base64_encode( $mime ),
			'timeout' => 20,
		);
	}

	/**
	 * Build a MIME message using WordPress-bundled PHPMailer.
	 *
	 * @param array $atts Email attributes.
	 * @return string|WP_Error
	 */
	public function build_mime_message( array $atts ) {
		require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
		require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';

		$phpmailer = new PHPMailer\PHPMailer\PHPMailer( true );

		try {
			$phpmailer->CharSet  = get_bloginfo( 'charset' );
			$phpmailer->Encoding = 'base64';
			$phpmailer->setFrom( $this->get_mailbox(), $this->get_from_name() );

			$to = is_array( $atts['to'] ) ? $atts['to'] : array( $atts['to'] );
			foreach ( $to as $recipient ) {
				$parsed = $this->parse_recipient( $recipient );
				$phpmailer->addAddress( $parsed['email'], $parsed['name'] );
			}

			$headers = $this->parse_headers( isset( $atts['headers'] ) ? $atts['headers'] : array() );
			$this->add_address_header( $phpmailer, 'addCC', isset( $headers['cc'] ) ? $headers['cc'] : '' );
			$this->add_address_header( $phpmailer, 'addBCC', isset( $headers['bcc'] ) ? $headers['bcc'] : '' );
			$this->add_address_header( $phpmailer, 'addReplyTo', isset( $headers['reply-to'] ) ? $headers['reply-to'] : '' );

			if ( ! empty( $headers['content-type'] ) && false !== strpos( $headers['content-type'], 'text/html' ) ) {
				$phpmailer->isHTML( true );
			}

			$phpmailer->Subject = $atts['subject'];
			$phpmailer->Body    = $atts['message'];

			if ( ! empty( $atts['attachments'] ) ) {
				foreach ( (array) $atts['attachments'] as $file ) {
					if ( ! file_exists( $file ) || ! is_readable( $file ) ) {
						return new WP_Error( 'pmpro_smtp_microsoft_attachment_missing', sprintf( __( 'Attachment could not be read: %s', 'pmpro-smtp' ), basename( $file ) ) );
					}

					$phpmailer->addAttachment( $file );
				}
			}

			$phpmailer->preSend();
			return $phpmailer->getSentMIMEMessage();
		} catch ( PHPMailer\PHPMailer\Exception $e ) {
			return new WP_Error( 'pmpro_smtp_microsoft_mime_failed', $e->getMessage() );
		}
	}

	/**
	 * Add comma-separated addresses from a header.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer PHPMailer instance.
	 * @param string                        $method    PHPMailer add method.
	 * @param string                        $value     Header value.
	 * @return void
	 */
	protected function add_address_header( $phpmailer, $method, $value ) {
		if ( empty( $value ) ) {
			return;
		}

		foreach ( explode( ',', $value ) as $recipient ) {
			$parsed = $this->parse_recipient( $recipient );
			if ( ! empty( $parsed['email'] ) ) {
				$phpmailer->{$method}( $parsed['email'], $parsed['name'] );
			}
		}
	}

	/**
	 * Request tokens from Microsoft.
	 *
	 * @param array $body Request body.
	 * @return array|WP_Error
	 */
	protected function request_token( $body ) {
		$client_id     = $this->get_setting( 'client_id' );
		$client_secret = pmpro_smtp_decrypt( $this->get_setting( 'client_secret' ) );

		if ( empty( $client_id ) || empty( $client_secret ) ) {
			return new WP_Error( 'pmpro_smtp_microsoft_missing_client_credentials', __( 'Microsoft 365 Client ID and Client Secret are required.', 'pmpro-smtp' ) );
		}

		$body = array_merge(
			array(
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
				'scope'         => $this->get_scope(),
			),
			$body
		);

		$response = wp_safe_remote_post(
			$this->get_token_url(),
			array(
				'body'    => $body,
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'pmpro_smtp_microsoft_token_http_error', $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code > 299 || empty( $data['access_token'] ) ) {
			return $this->parse_token_error( $response );
		}

		return $data;
	}

	/**
	 * Fetch and store authenticated Microsoft user details.
	 *
	 * @param string $access_token Access token.
	 * @return void
	 */
	public function store_authenticated_user( $access_token ) {
		$response = wp_safe_remote_get(
			self::GRAPH_ME_URL,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return;
		}

		$data  = json_decode( wp_remote_retrieve_body( $response ), true );
		$email = '';

		if ( ! empty( $data['mail'] ) ) {
			$email = sanitize_email( $data['mail'] );
		} elseif ( ! empty( $data['userPrincipalName'] ) ) {
			$email = sanitize_email( $data['userPrincipalName'] );
		}

		if ( empty( $email ) ) {
			return;
		}

		$settings                        = get_option( 'pmpro_smtp_connector_' . $this->get_name(), array() );
		$settings['authenticated_email'] = $email;

		update_option( 'pmpro_smtp_connector_' . $this->get_name(), $settings );
	}

	/**
	 * Parse OAuth token errors into sanitized, actionable messages.
	 *
	 * @param array $response HTTP response.
	 * @return WP_Error
	 */
	protected function parse_token_error( $response ) {
		$code    = (int) wp_remote_retrieve_response_code( $response );
		$data    = json_decode( wp_remote_retrieve_body( $response ), true );
		$error   = isset( $data['error'] ) ? sanitize_key( $data['error'] ) : 'token_error';
		$message = isset( $data['error_description'] ) ? $this->sanitize_remote_error( $data['error_description'] ) : __( 'Microsoft token request failed.', 'pmpro-smtp' );

		if ( false !== stripos( $message, 'AADSTS7000215' ) || false !== stripos( $message, 'invalid client secret' ) ) {
			$message = __( 'Microsoft rejected the client secret. Create a new client secret in Microsoft Entra, save it here, and reconnect.', 'pmpro-smtp' );
		} elseif ( false !== stripos( $message, 'AADSTS70000' ) || false !== stripos( $message, 'redirect' ) ) {
			$message = __( 'Microsoft rejected the redirect URI. Copy the Redirect URI shown here into the app registration and reconnect.', 'pmpro-smtp' );
		} elseif ( false !== stripos( $message, 'AADSTS65001' ) || false !== stripos( $message, 'consent' ) ) {
			$message = __( 'Microsoft requires consent for this app. An administrator may need to grant consent for Mail.Send.', 'pmpro-smtp' );
		} elseif ( false !== stripos( $message, 'AADSTS50076' ) || false !== stripos( $message, 'conditional access' ) ) {
			$message = __( 'Microsoft Conditional Access or Security Defaults blocked the sign-in. Review the tenant policy or reconnect with an allowed account.', 'pmpro-smtp' );
		} elseif ( false !== stripos( $message, 'refresh token' ) || false !== stripos( $message, 'AADSTS700082' ) ) {
			$message = __( 'Microsoft could not refresh the access token. Reconnect the Microsoft 365 account.', 'pmpro-smtp' );
		}

		return new WP_Error( 'pmpro_smtp_microsoft_' . $error, sprintf( __( 'Microsoft OAuth error (%d): %s', 'pmpro-smtp' ), $code, $message ) );
	}

	/**
	 * Parse Microsoft Graph send errors.
	 *
	 * @param array $response HTTP response.
	 * @return WP_Error
	 */
	protected function parse_graph_error( $response ) {
		$code         = (int) wp_remote_retrieve_response_code( $response );
		$data         = json_decode( wp_remote_retrieve_body( $response ), true );
		$raw_code     = isset( $data['error']['code'] ) ? (string) $data['error']['code'] : 'send_failed';
		$remote_code  = sanitize_key( $raw_code );
		$remote_error = isset( $data['error']['message'] ) ? $this->sanitize_remote_error( $data['error']['message'] ) : __( 'Microsoft Graph rejected the message.', 'pmpro-smtp' );

		$message = $remote_error;
		if ( false !== stripos( $raw_code, 'ErrorSendAsDenied' ) || false !== stripos( $remote_error, 'SendAsDenied' ) ) {
			$message = __( 'Microsoft says the authenticated account cannot send as the configured From Email / Mailbox. Use that mailbox when connecting, or grant send-as permission and reconnect.', 'pmpro-smtp' );
		} elseif ( 401 === $code ) {
			$message = __( 'Microsoft Graph rejected the access token. Reconnect the Microsoft 365 account.', 'pmpro-smtp' );
		} elseif ( 403 === $code ) {
			$message = __( 'Microsoft Graph denied permission to send. Confirm the app has delegated Mail.Send permission, admin consent if required, and that the mailbox has an Exchange Online license.', 'pmpro-smtp' );
		} elseif ( false !== stripos( $remote_error, 'MailboxNotEnabledForRESTAPI' ) || false !== stripos( $remote_error, 'mailbox' ) ) {
			$message = __( 'Microsoft could not access this mailbox. Confirm the account has an Exchange Online mailbox license.', 'pmpro-smtp' );
		}

		return new WP_Error( 'pmpro_smtp_microsoft_' . $remote_code, sprintf( __( 'Microsoft Graph error (%d): %s', 'pmpro-smtp' ), $code, $message ) );
	}

	/**
	 * Remove token-like material from remote error strings.
	 *
	 * @param string $message Error message.
	 * @return string
	 */
	public function sanitize_remote_error( $message ) {
		$message = wp_strip_all_tags( (string) $message );
		$message = preg_replace( '/(access_token|refresh_token|client_secret|code)=([^&\s]+)/i', '$1=[redacted]', $message );
		$message = preg_replace( '/("?(?:access_token|refresh_token|client_secret|authorization_code|code)"?\s*:\s*")([^"]+)(")/i', '$1[redacted]$3', $message );
		$message = preg_replace( '/((?:access_token|refresh_token|client_secret|authorization_code|code)\s*:\s*)([^\s,;]+)/i', '$1[redacted]', $message );
		$message = preg_replace( '/Bearer\s+[A-Za-z0-9._~+\/=-]+/i', 'Bearer [redacted]', $message );

		return trim( $message );
	}
}

/**
 * Register Microsoft 365 OAuth callback route.
 *
 * @return void
 */
function pmpro_smtp_microsoft365_register_rest_route() {
	register_rest_route(
		'pmpro-smtp/v1',
		'/microsoft365/callback',
		array(
			'methods'             => 'GET',
			'callback'            => 'pmpro_smtp_microsoft365_handle_callback',
			'permission_callback' => '__return_true',
		)
	);
}
add_action( 'rest_api_init', 'pmpro_smtp_microsoft365_register_rest_route' );

/**
 * Handle Microsoft OAuth callback.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response
 */
function pmpro_smtp_microsoft365_handle_callback( $request ) {
	$state = sanitize_text_field( $request->get_param( 'state' ) );
	$code  = $request->get_param( 'code' );
	$saved = empty( $state ) ? false : get_transient( 'pmpro_smtp_microsoft365_state_' . $state );

	$redirect_url = add_query_arg(
		array(
			'page' => 'pmpro-smtp',
			'tab'  => 'connection',
		),
		admin_url( 'admin.php' )
	);

	if ( empty( $state ) || empty( $saved ) || ! is_array( $saved ) ) {
		wp_safe_redirect( add_query_arg( 'pmpro_smtp_microsoft365_error', __( 'Microsoft sign-in state expired or did not match. Start the connection again.', 'pmpro-smtp' ), $redirect_url ) );
		exit;
	}

	if (
		! is_user_logged_in() ||
		! current_user_can( 'manage_options' ) ||
		empty( $saved['user_id'] ) ||
		empty( $saved['session_token'] ) ||
		(int) $saved['user_id'] !== get_current_user_id() ||
		! hash_equals( (string) $saved['session_token'], (string) wp_get_session_token() )
	) {
		wp_safe_redirect( add_query_arg( 'pmpro_smtp_microsoft365_error', __( 'Microsoft sign-in must be completed by the same administrator who started it.', 'pmpro-smtp' ), $redirect_url ) );
		exit;
	}

	delete_transient( 'pmpro_smtp_microsoft365_state_' . $state );

	if ( empty( $code ) ) {
		$error = $request->get_param( 'error_description' );
		$error = $error ? $error : __( 'Microsoft did not return an authorization code.', 'pmpro-smtp' );
		wp_safe_redirect( add_query_arg( 'pmpro_smtp_microsoft365_error', pmpro_smtp_microsoft365_sanitize_callback_error( $error ), $redirect_url ) );
		exit;
	}

	$connector = new PMPRO_SMTP_Connector_Microsoft365();
	$tokens    = $connector->exchange_authorization_code( sanitize_text_field( $code ), isset( $saved['code_verifier'] ) ? $saved['code_verifier'] : '' );

	if ( is_wp_error( $tokens ) ) {
		wp_safe_redirect( add_query_arg( 'pmpro_smtp_microsoft365_error', $tokens->get_error_message(), $redirect_url ) );
		exit;
	}

	$connector->store_tokens( $tokens );
	$connector->store_authenticated_user( $tokens['access_token'] );

	wp_safe_redirect( add_query_arg( 'pmpro_smtp_microsoft365_connected', '1', $redirect_url ) );
	exit;
}

/**
 * Sanitize callback error text without needing a saved connector instance.
 *
 * @param string $message Error message.
 * @return string
 */
function pmpro_smtp_microsoft365_sanitize_callback_error( $message ) {
	$connector = new PMPRO_SMTP_Connector_Microsoft365();
	return $connector->sanitize_remote_error( $message );
}
