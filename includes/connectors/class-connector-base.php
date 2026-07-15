<?php
/**
 * Base class for all PMPro SMTP connectors.
 *
 * @package PMProSMTP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class PMPRO_SMTP_Connector_Base {

	/**
	 * Get the connector's unique slug (e.g. 'sendgrid').
	 *
	 * @return string
	 */
	abstract public function get_name();

	/**
	 * Get the connector's display title (e.g. 'SendGrid').
	 *
	 * @return string
	 */
	abstract public function get_title();

	/**
	 * Get the settings fields for this connector.
	 *
	 * Each field is an array with keys:
	 *   - key        (string)  Option key within the connector's settings array.
	 *   - label      (string)  Human-readable label.
	 *   - type       (string)  'text', 'password', 'number', 'select', 'checkbox'.
	 *   - options    (array)   For select fields: value => label pairs.
	 *   - desc       (string)  Optional help text.
	 *   - placeholder (string) Optional placeholder.
	 *   - sensitive  (bool)    If true, the value is stored raw (trimmed, CR/LF
	 *                          stripped) instead of via sanitize_text_field(), and
	 *                          rendered like PMPro core's gateway secret fields: a
	 *                          text input with the saved value echoed back, masked
	 *                          with CSS.
	 *
	 * @return array
	 */
	abstract public function get_settings_fields();

	/**
	 * Send an email.
	 *
	 * Email logging is handled by PMPro core via wp_mail_succeeded / wp_mail_failed,
	 * which are fired by pmpro_smtp_pre_wp_mail() after this returns. API connectors
	 * override this; the Generic/Custom SMTP connector does not (it sends via
	 * WordPress core on the phpmailer_init path and is passed straight through).
	 *
	 * @param array $atts {
	 *     @type string|string[] $to          Recipient(s).
	 *     @type string          $subject     Subject line.
	 *     @type string          $message     Email body.
	 *     @type string|string[] $headers     Extra headers.
	 *     @type string[]        $attachments File paths.
	 * }
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public function send( array $atts ) {
		return new WP_Error( 'pmpro_smtp_no_send', __( 'This connector does not support direct sending.', 'pmpro-smtp' ) );
	}

	/**
	 * Get a saved setting value for this connector.
	 *
	 * @param string $key
	 * @param mixed  $default
	 * @return mixed
	 */
	public function get_setting( $key, $default = '' ) {
		$settings = get_option( 'pmpro_smtp_connector_' . $this->get_name(), array() );
		return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
	}

	/**
	 * Decide whether a message should be sent as HTML.
	 *
	 * Mirrors what WordPress core does on the PHPMailer path: a Content-Type
	 * header of text/html wins, but in its absence the wp_mail_content_type
	 * filter is consulted (the documented, header-less way to declare HTML mail).
	 * Because API connectors short-circuit wp_mail() via pre_wp_mail — which runs
	 * before core applies that filter on the PHPMailer path — each connector must
	 * resolve the content type itself so filter-only HTML emails are not sent as
	 * plain text.
	 *
	 * @param array $headers Parsed headers from parse_headers().
	 * @return bool True if the message should be treated as HTML.
	 */
	protected function is_html_message( array $headers ) {
		if ( ! empty( $headers['content-type'] ) && false !== strpos( $headers['content-type'], 'text/html' ) ) {
			return true;
		}

		$filtered = apply_filters( 'wp_mail_content_type', 'text/plain' );

		return false !== stripos( (string) $filtered, 'text/html' );
	}

	/**
	 * Resolve the sender (From) for an outgoing message.
	 *
	 * Order of precedence, matching what WordPress core's PHPMailer path does:
	 *   1. An explicit From: header on the message. PMPro sets its configured
	 *      sender as a real From: header (PMProEmail::add_from_to_headers()),
	 *      and WordPress core honours From: headers, so API connectors must too.
	 *   2. The wp_mail_from / wp_mail_from_name filters, seeded with the SAME
	 *      default WordPress core uses ('wordpress@<sitename>' / 'WordPress')
	 *      so PMPro's pmpro_wp_mail_from() override actually fires.
	 *
	 * @param array|string $headers Raw message headers (string or array).
	 * @return array { email: string, name: string }
	 */
	public function resolve_from( $headers = array() ) {
		$parsed = $this->parse_headers( $headers );
		if ( ! empty( $parsed['from'] ) ) {
			$from = $this->parse_recipient( $parsed['from'] );
			return array(
				'email' => $from['email'],
				'name'  => $from['name'],
			);
		}

		return array(
			'email' => apply_filters( 'wp_mail_from', self::default_from_email() ),
			'name'  => apply_filters( 'wp_mail_from_name', 'WordPress' ),
		);
	}

	/**
	 * Build the default From email address used to seed the wp_mail_from filter,
	 * matching WordPress core's default in wp-includes/pluggable.php
	 * ('wordpress@<sitename>'). Plugins like PMPro that override the sender only do
	 * so when they see this default on the filter.
	 *
	 * @return string
	 */
	public static function default_from_email() {
		$sitename = strtolower( (string) wp_parse_url( network_home_url(), PHP_URL_HOST ) );
		if ( 0 === strpos( $sitename, 'www.' ) ) {
			$sitename = substr( $sitename, 4 );
		}

		return 'wordpress@' . $sitename;
	}

	/**
	 * Read the message attachments into { filename, contents, type } parts.
	 *
	 * Each connector maps these parts to its own attachment shape (base64 for the
	 * JSON connectors, raw bytes for Mailgun's multipart upload).
	 *
	 * @param array $atts Email attributes.
	 * @return array[] Each part { filename: string, contents: string, type: string }.
	 */
	protected function read_attachments( array $atts ) {
		$parts = array();
		if ( empty( $atts['attachments'] ) ) {
			return $parts;
		}
		foreach ( (array) $atts['attachments'] as $file ) {
			if ( ! is_readable( $file ) ) {
				continue;
			}
			$contents = file_get_contents( $file );
			if ( false === $contents ) {
				continue;
			}
			$type = function_exists( 'mime_content_type' ) ? mime_content_type( $file ) : '';
			$parts[] = array(
				'filename' => basename( $file ),
				'contents' => $contents,
				'type'     => ! empty( $type ) ? $type : 'application/octet-stream',
			);
		}
		return $parts;
	}

	/**
	 * Parse a raw headers string or array into a key => value array.
	 *
	 * Repeated headers (e.g. multiple Cc:/Bcc: lines) are accumulated and
	 * joined with a comma so callers can split them into individual addresses.
	 *
	 * @param string|array $headers
	 * @return array
	 */
	protected function parse_headers( $headers ) {
		$parsed = array();
		if ( ! is_array( $headers ) ) {
			$headers = explode( "\n", str_replace( "\r\n", "\n", $headers ) );
		}
		foreach ( $headers as $header ) {
			if ( strpos( $header, ':' ) === false ) {
				continue;
			}
			list( $name, $value ) = explode( ':', trim( $header ), 2 );
			$name  = strtolower( trim( $name ) );
			$value = trim( $value );

			if ( isset( $parsed[ $name ] ) && '' !== $parsed[ $name ] ) {
				// Accumulate repeated headers (e.g. multiple Cc lines).
				$parsed[ $name ] .= ', ' . $value;
			} else {
				$parsed[ $name ] = $value;
			}
		}
		return $parsed;
	}

	/**
	 * Normalize a $to value (which may be a comma-separated string) into an
	 * array of individual recipient strings.
	 *
	 * WordPress's wp_mail() accepts $to as either an array or a comma-delimited
	 * string. API connectors that build per-recipient JSON objects must split
	 * the string first so each address is parsed individually.
	 *
	 * @param string|array $to
	 * @return string[]
	 */
	protected function normalize_recipients( $to ) {
		if ( is_array( $to ) ) {
			$list = array();
			foreach ( $to as $entry ) {
				$list = array_merge( $list, $this->split_address_list( $entry ) );
			}
		} else {
			$list = $this->split_address_list( $to );
		}

		return array_values( array_filter( array_map( 'trim', $list ), 'strlen' ) );
	}

	/**
	 * Split a comma-separated address list into individual addresses.
	 *
	 * Commas inside a quoted display name (e.g. "Doe, John" <john@x.com>) or
	 * inside angle brackets are NOT treated as separators, matching how
	 * WordPress core / PHPMailer parse address lists. This keeps quoted-comma
	 * display names intact on the API connector path.
	 *
	 * @param string $value
	 * @return string[]
	 */
	protected function split_address_list( $value ) {
		$value = (string) $value;
		if ( '' === trim( $value ) ) {
			return array();
		}

		$addresses = array();
		$current   = '';
		$in_quotes = false;
		$in_angle  = false;
		$length    = strlen( $value );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $value[ $i ];

			if ( '"' === $char ) {
				$in_quotes = ! $in_quotes;
				$current  .= $char;
				continue;
			}

			if ( ! $in_quotes && '<' === $char ) {
				$in_angle = true;
			} elseif ( ! $in_quotes && '>' === $char ) {
				$in_angle = false;
			}

			if ( ',' === $char && ! $in_quotes && ! $in_angle ) {
				$addresses[] = trim( $current );
				$current     = '';
				continue;
			}

			$current .= $char;
		}

		if ( '' !== trim( $current ) ) {
			$addresses[] = trim( $current );
		}

		return $addresses;
	}

	/**
	 * Build an array of { email, name } objects from a comma-separated address
	 * list, for JSON API connectors (SendGrid/Brevo/MailerSend cc/bcc shape).
	 *
	 * @param string $value
	 * @return array[]
	 */
	protected function build_address_objects( $value ) {
		$objects = array();
		foreach ( $this->split_address_list( $value ) as $address ) {
			$recipient = $this->parse_recipient( $address );
			if ( empty( $recipient['email'] ) ) {
				continue;
			}
			$entry = array( 'email' => $recipient['email'] );
			if ( ! empty( $recipient['name'] ) ) {
				$entry['name'] = $recipient['name'];
			}
			$objects[] = $entry;
		}
		return $objects;
	}

	/**
	 * Build an array of { email, name? } objects from a $to value (string or
	 * array), for JSON API connectors that build per-recipient objects.
	 *
	 * @param string|array $to
	 * @return array[]
	 */
	protected function build_recipient_objects( $to ) {
		$objects = array();
		foreach ( $this->normalize_recipients( $to ) as $recipient ) {
			$parsed = $this->parse_recipient( $recipient );
			$entry  = array( 'email' => $parsed['email'] );
			if ( ! empty( $parsed['name'] ) ) {
				$entry['name'] = $parsed['name'];
			}
			$objects[] = $entry;
		}
		return $objects;
	}

	/**
	 * Resolve the From into a "Name <email>" (or bare email) string, for
	 * connectors that send the sender as a single combined string.
	 *
	 * @param array $atts Email attributes.
	 * @return string
	 */
	protected function format_from( array $atts ) {
		$sender = $this->resolve_from( isset( $atts['headers'] ) ? $atts['headers'] : array() );
		if ( empty( $sender['name'] ) ) {
			return $sender['email'];
		}
		$name = $sender['name'];
		// parse_recipient() strips quoted-string wrappers, so names containing
		// RFC 5322 specials (e.g. the comma in 'Acme, Inc.') must be re-quoted
		// here or providers will parse the from string as an address list and
		// split the name into bogus addresses.
		if ( preg_match( '/[()<>\[\]:;@\\\\,."]/', $name ) ) {
			$name = '"' . addcslashes( $name, '"\\' ) . '"';
		}
		return sprintf( '%s <%s>', $name, $sender['email'] );
	}

	/**
	 * POST a JSON body to a provider API and resolve the response.
	 *
	 * The JSON connectors all send the same request shape (JSON body, 15s timeout)
	 * and differ only in URL, auth/content headers, provider label, and the path to
	 * the error message in the response.
	 *
	 * @param string $url        Endpoint URL.
	 * @param array  $headers    Request headers.
	 * @param array  $body       Body to JSON-encode.
	 * @param string $label      Human-readable provider name.
	 * @param array  $error_keys Path into the decoded JSON to the error message.
	 * @return true|WP_Error
	 */
	protected function post_json( $url, array $headers, array $body, $label, array $error_keys ) {
		$response = wp_safe_remote_post( $url, array(
			'headers' => $headers,
			'body'    => wp_json_encode( $body ),
			'timeout' => 15,
		) );

		return $this->handle_api_response( $response, $label, $error_keys );
	}

	/**
	 * Validate an API response and return true on success or a WP_Error on failure.
	 *
	 * @param array|WP_Error $response   Result from wp_safe_remote_post().
	 * @param string         $label      Human-readable provider name.
	 * @param array          $error_keys Path into the decoded JSON to the error message.
	 * @return true|WP_Error
	 */
	protected function handle_api_response( $response, $label, array $error_keys ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code <= 299 ) {
			return true;
		}

		$body_response = wp_remote_retrieve_body( $response );
		$decoded       = json_decode( $body_response, true );
		$value         = $decoded;
		foreach ( $error_keys as $key ) {
			if ( is_array( $value ) && isset( $value[ $key ] ) ) {
				$value = $value[ $key ];
			} else {
				$value = '';
				break;
			}
		}
		$message = ! empty( $value ) ? $value : $body_response;

		return new WP_Error( 'pmpro_smtp_send_failed', sprintf(
			/* translators: 1: HTTP response code, 2: error message from the provider */
			__( '%1$s error (%2$d): %3$s', 'pmpro-smtp' ),
			$label,
			$code,
			$message
		) );
	}

	/**
	 * Build base64-encoded attachment parts keyed for a JSON connector.
	 *
	 * The five JSON connectors all base64-encode each attachment and differ only
	 * in the array keys they use. $keys maps part fields (filename, contents, type)
	 * to the connector's key names; omit a field to leave it out of the output.
	 *
	 * @param array $atts Email attributes.
	 * @param array $keys Map of part field => output key (e.g. array( 'filename' => 'name', 'contents' => 'content' )).
	 * @return array[]
	 */
	protected function build_base64_attachments( array $atts, array $keys ) {
		$attachments = array();
		foreach ( $this->read_attachments( $atts ) as $part ) {
			$entry = array();
			foreach ( $keys as $field => $out ) {
				$entry[ $out ] = ( 'contents' === $field ) ? base64_encode( $part['contents'] ) : $part[ $field ];
			}
			$attachments[] = $entry;
		}
		return $attachments;
	}

	/**
	 * Build a single { email, name } object from a Reply-To header value.
	 *
	 * parse_headers() comma-joins repeated Reply-To headers, so the value may be
	 * a list. JSON API connectors only accept one reply-to object, so we split
	 * the list first and use the first valid address rather than passing the
	 * whole comma-joined string to parse_recipient() (which would yield a single
	 * malformed { email: 'a@x, b@y' } object).
	 *
	 * @param string $value
	 * @return array { email, name? }|array() Empty array when no valid address.
	 */
	protected function build_reply_to_object( $value ) {
		$objects = $this->build_address_objects( $value );
		return empty( $objects ) ? array() : $objects[0];
	}

	/**
	 * Extract a To name from a "Name <email@example.com>" formatted string.
	 *
	 * @param string $recipient
	 * @return array { name: string, email: string }
	 */
	protected function parse_recipient( $recipient ) {
		$recipient = trim( $recipient );
		// Match "Name <email>" as well as a bare "<email>" with no display name.
		if ( preg_match( '/^(?:(.*?)\s*)?<(.+?)>\s*$/', $recipient, $matches ) ) {
			$name = trim( $matches[1] );
			// Unwrap an RFC 5322 quoted-string display name (e.g. '"Doe, John"
			// <j@x.com>') so APIs receive the bare name: remove the single
			// wrapping quote pair and undo quoted-pair escapes (\" and \\).
			// format_from() re-quotes names that need it when re-serializing.
			if ( strlen( $name ) >= 2 && '"' === $name[0] && '"' === substr( $name, -1 ) ) {
				$name = trim( stripslashes( substr( $name, 1, -1 ) ) );
			}
			return array( 'name' => $name, 'email' => trim( $matches[2] ) );
		}
		return array( 'name' => '', 'email' => $recipient );
	}
}
