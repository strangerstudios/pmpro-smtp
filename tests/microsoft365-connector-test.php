<?php
/**
 * Lightweight tests for the Microsoft 365 connector.
 *
 * Run with: php tests/microsoft365-connector-test.php
 */

define( 'ABSPATH', __DIR__ . '/wordpress/' );
define( 'WPINC', 'wp-includes' );

$GLOBALS['pmpro_smtp_test_options']       = array();
$GLOBALS['pmpro_smtp_test_http_response'] = null;
$GLOBALS['pmpro_smtp_test_http_request']  = null;
$GLOBALS['pmpro_smtp_test_http_requests'] = array();
$GLOBALS['pmpro_smtp_test_http_queue']    = array();

class WP_Error {
	private $code;
	private $message;

	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}
}

function __return_true() {
	return true;
}

function __( $text ) {
	return $text;
}

function add_action() {}
function add_filter() {}
function apply_filters( $tag, $value ) {
	return $value;
}

function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['pmpro_smtp_test_options'] ) ? $GLOBALS['pmpro_smtp_test_options'][ $key ] : $default;
}

function update_option( $key, $value ) {
	$GLOBALS['pmpro_smtp_test_options'][ $key ] = $value;
	return true;
}

function wp_salt( $scheme = 'auth' ) {
	return 'unit-test-salt-' . $scheme;
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

function sanitize_key( $key ) {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $key ) );
}

function sanitize_email( $email ) {
	return trim( $email );
}

function sanitize_text_field( $value ) {
	return trim( wp_strip_all_tags( $value ) );
}

function wp_strip_all_tags( $value ) {
	return strip_tags( $value );
}

function wp_safe_remote_post( $url, $args = array() ) {
	$GLOBALS['pmpro_smtp_test_http_request'] = array(
		'url'  => $url,
		'args' => $args,
	);
	$GLOBALS['pmpro_smtp_test_http_requests'][] = $GLOBALS['pmpro_smtp_test_http_request'];
	if ( ! empty( $GLOBALS['pmpro_smtp_test_http_queue'] ) ) {
		return array_shift( $GLOBALS['pmpro_smtp_test_http_queue'] );
	}
	return $GLOBALS['pmpro_smtp_test_http_response'];
}

function wp_remote_retrieve_response_code( $response ) {
	return isset( $response['response']['code'] ) ? $response['response']['code'] : 0;
}

function wp_remote_retrieve_body( $response ) {
	return isset( $response['body'] ) ? $response['body'] : '';
}

function rest_url( $path = '' ) {
	return 'https://example.test/wp-json/' . ltrim( $path, '/' );
}

function add_query_arg( $args, $url = '' ) {
	return $url . '?' . http_build_query( $args );
}

function wp_json_encode( $value ) {
	return json_encode( $value );
}

require_once __DIR__ . '/../includes/connectors/class-connector-base.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/connectors/class-connector-microsoft365.php';

class PMPRO_SMTP_Test_Microsoft365_Connector extends PMPRO_SMTP_Connector_Microsoft365 {
	public function expose_parse_graph_error( $response ) {
		return $this->parse_graph_error( $response );
	}

	public function build_mime_message( array $atts ) {
		return "From: sender@example.com\r\n\r\nBody";
	}
}

function pmpro_smtp_assert_true( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

function pmpro_smtp_assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, "FAIL: {$message}\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
		exit( 1 );
	}
}

$connector = new PMPRO_SMTP_Test_Microsoft365_Connector();

update_option(
	'pmpro_smtp_connector_microsoft365',
	array(
		'client_id' => 'client-id',
	)
);

$auth_url = $connector->build_authorization_url( 'state123', 'challenge456' );
pmpro_smtp_assert_true( false !== strpos( $auth_url, 'code_challenge=challenge456' ), 'Authorization URL should include PKCE challenge.' );
pmpro_smtp_assert_true( false !== strpos( $auth_url, 'code_challenge_method=S256' ), 'Authorization URL should use S256 PKCE.' );

update_option(
	'pmpro_smtp_connector_microsoft365',
	array(
		'client_id'     => 'client-id',
		'client_secret' => pmpro_smtp_encrypt( 'client-secret' ),
		'refresh_token' => pmpro_smtp_encrypt( 'old-refresh-token' ),
		'access_token'  => pmpro_smtp_encrypt( 'expired-access-token' ),
		'expires_at'    => time() - 60,
	)
);

$GLOBALS['pmpro_smtp_test_http_response'] = array(
	'response' => array( 'code' => 200 ),
	'body'     => wp_json_encode(
		array(
			'access_token'  => 'new-access-token',
			'refresh_token' => 'new-refresh-token',
			'expires_in'    => 3600,
		)
	),
);

$access_token = $connector->get_access_token();
pmpro_smtp_assert_same( 'new-access-token', $access_token, 'Expired access token should refresh.' );

$stored = get_option( 'pmpro_smtp_connector_microsoft365' );
pmpro_smtp_assert_same( 'new-refresh-token', pmpro_smtp_decrypt( $stored['refresh_token'] ), 'Refresh token should be replaced when Microsoft returns a new one.' );
pmpro_smtp_assert_true( false === strpos( serialize( $stored ), 'new-refresh-token' ), 'Refresh token should not be stored in plain text.' );

$sanitized = $connector->sanitize_remote_error( 'bad access_token=abc123 refresh_token=def456 Authorization: Bearer secret.jwt.value' );
pmpro_smtp_assert_true( false === strpos( $sanitized, 'abc123' ), 'Access token query value should be redacted.' );
pmpro_smtp_assert_true( false === strpos( $sanitized, 'def456' ), 'Refresh token query value should be redacted.' );
pmpro_smtp_assert_true( false === strpos( $sanitized, 'secret.jwt.value' ), 'Bearer token should be redacted.' );
$sanitized_json = $connector->sanitize_remote_error( '{"refresh_token":"json-secret","authorization_code":"code-secret"}' );
pmpro_smtp_assert_true( false === strpos( $sanitized_json, 'json-secret' ), 'JSON refresh token should be redacted.' );
pmpro_smtp_assert_true( false === strpos( $sanitized_json, 'code-secret' ), 'JSON authorization code should be redacted.' );

$send_as_error = $connector->expose_parse_graph_error(
	array(
		'response' => array( 'code' => 403 ),
		'body'     => wp_json_encode(
			array(
				'error' => array(
					'code'    => 'ErrorSendAsDenied',
					'message' => 'The user account which was used to submit this request does not have the right to send mail on behalf of the specified sending account.',
				),
			)
		),
	)
);
pmpro_smtp_assert_true( is_wp_error( $send_as_error ), 'Graph send-as failure should return WP_Error.' );
pmpro_smtp_assert_true( false !== strpos( $send_as_error->get_error_message(), 'cannot send as' ), 'Send-as failure should be actionable.' );

update_option(
	'pmpro_smtp_connector_microsoft365',
	array(
		'client_id'     => 'client-id',
		'client_secret' => pmpro_smtp_encrypt( 'client-secret' ),
		'refresh_token' => pmpro_smtp_encrypt( 'retry-refresh-token' ),
		'access_token'  => pmpro_smtp_encrypt( 'cached-access-token' ),
		'expires_at'    => time() + 3600,
		'mailbox'       => 'sender@example.com',
	)
);

$GLOBALS['pmpro_smtp_test_http_requests'] = array();
$GLOBALS['pmpro_smtp_test_http_queue']    = array(
	array(
		'response' => array( 'code' => 401 ),
		'body'     => wp_json_encode(
			array(
				'error' => array(
					'code'    => 'InvalidAuthenticationToken',
					'message' => 'Expired token',
				),
			)
		),
	),
	array(
		'response' => array( 'code' => 200 ),
		'body'     => wp_json_encode(
			array(
				'access_token'  => 'retried-access-token',
				'refresh_token' => 'retried-refresh-token',
				'expires_in'    => 3600,
			)
		),
	),
	array(
		'response' => array( 'code' => 202 ),
		'body'     => '',
	),
);

$send_result = $connector->send(
	array(
		'to'          => 'recipient@example.com',
		'subject'     => 'Test',
		'message'     => 'Body',
		'headers'     => array(),
		'attachments' => array(),
	)
);

pmpro_smtp_assert_true( true === $send_result, 'Send should retry once after a Graph 401 and succeed.' );
pmpro_smtp_assert_same( 'https://graph.microsoft.com/v1.0/me/sendMail', $GLOBALS['pmpro_smtp_test_http_requests'][0]['url'], 'Graph send should use /me/sendMail.' );
pmpro_smtp_assert_same( 'https://graph.microsoft.com/v1.0/me/sendMail', $GLOBALS['pmpro_smtp_test_http_requests'][2]['url'], 'Graph retry should use /me/sendMail.' );

echo "Microsoft 365 connector tests passed.\n";
