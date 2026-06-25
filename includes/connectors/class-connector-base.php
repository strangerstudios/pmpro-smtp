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
	 * Get a short description of this connector.
	 *
	 * @return string
	 */
	public function get_description() {
		return '';
	}

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
	 *   - sensitive  (bool)    If true, value is encrypted at rest and obfuscated in UI.
	 *
	 * @return array
	 */
	abstract public function get_settings_fields();

	/**
	 * Perform the actual email send.
	 *
	 * Note on inline embeds: WordPress 6.9+ threads an $atts['embeds'] array of
	 * inline (CID) attachments through wp_mail()/pre_wp_mail. The API connectors
	 * reconstruct the message from $atts and do not map embeds to each provider's
	 * inline-attachment/CID mechanism, so inline embeds are not supported on the
	 * API-connector path (they ARE delivered on the Generic/PHPMailer path, which
	 * runs through WordPress core). This is documented here so the limitation is
	 * explicit rather than a silent surprise; see pmpro_smtp_pre_wp_mail().
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
	abstract protected function do_send( array $atts );

	/**
	 * Send an email.
	 *
	 * Email logging is handled by PMPro core via wp_mail_succeeded / wp_mail_failed,
	 * which are fired by pmpro_smtp_pre_wp_mail() after this returns.
	 *
	 * @param array $atts Email attributes.
	 * @return true|WP_Error
	 */
	public function send( array $atts ) {
		return $this->do_send( $atts );
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

		return is_string( $filtered ) && false !== stripos( $filtered, 'text/html' );
	}

	/**
	 * Resolve the sender (From) for an outgoing message.
	 *
	 * Order of precedence, matching what WordPress core's PHPMailer path does:
	 *   1. A connector-forced From email (e.g. Generic "Sender Address
	 *      Compatibility") always wins for the email, falling back to the
	 *      header/filter name.
	 *   2. An explicit From: header on the message. PMPro sets its configured
	 *      sender as a real From: header (PMProEmail::add_from_to_headers()),
	 *      and WordPress core honours From: headers, so API connectors must too.
	 *   3. The wp_mail_from / wp_mail_from_name filters, seeded with the SAME
	 *      default WordPress core uses ('wordpress@<sitename>' / 'WordPress')
	 *      so PMPro's pmpro_wp_mail_from() override actually fires.
	 *
	 * @param array|string $headers Raw message headers (string or array).
	 * @return array { email: string, name: string }
	 */
	/**
	 * Public accessor for the From address that would be used for a message with
	 * the given headers. Used by the Send Test Email tool to display the sender
	 * that production mail will actually use.
	 *
	 * @param array|string $headers
	 * @return array { email: string, name: string }
	 */
	public function get_resolved_from( $headers = array() ) {
		return $this->resolve_from( $headers );
	}

	protected function resolve_from( $headers = array() ) {
		// NOTE: get_forced_from_email() only returns non-empty for the Generic
		// connector, which sends via configure_phpmailer() on the phpmailer_init
		// path and never reaches resolve_from()/get_from_email(). The API
		// connectors all inherit the base '' implementation, so the forced-from
		// branches below are effectively unreachable for shipped connectors today.
		// They are retained as defensive support for any future connector that
		// both overrides get_forced_from_email() and sends via the API path.
		$forced_from_email = $this->get_forced_from_email();

		$parsed = $this->parse_headers( $headers );
		if ( ! empty( $parsed['from'] ) ) {
			$from = $this->parse_recipient( $parsed['from'] );
			return array(
				'email' => ! empty( $forced_from_email ) ? $forced_from_email : $from['email'],
				'name'  => $from['name'],
			);
		}

		return array(
			'email' => ! empty( $forced_from_email ) ? $forced_from_email : $this->get_from_email(),
			'name'  => $this->get_from_name(),
		);
	}

	/**
	 * Get the "from" email address, respecting the wp_mail_from filter.
	 *
	 * The filter is seeded with the same default WordPress core uses
	 * ('wordpress@<sitename>') so plugins like PMPro that only substitute their
	 * configured sender when they see that default value will fire correctly.
	 *
	 * @return string
	 */
	protected function get_from_email() {
		$forced_from_email = $this->get_forced_from_email();
		if ( ! empty( $forced_from_email ) ) {
			return $forced_from_email;
		}

		return apply_filters( 'wp_mail_from', $this->get_default_from_email() );
	}

	/**
	 * Get the "from" name, respecting the wp_mail_from_name filter.
	 *
	 * Seeded with WordPress core's default ('WordPress') for the same reason as
	 * get_from_email().
	 *
	 * @return string
	 */
	protected function get_from_name() {
		return apply_filters( 'wp_mail_from_name', 'WordPress' );
	}

	/**
	 * Build the default From email address used to seed the wp_mail_from filter.
	 *
	 * This value matters because plugins that override the sender only do so when
	 * they see their OWN expected default on the filter. WordPress core derives its
	 * default from network_home_url() (wp-includes/pluggable.php), but PMPro's
	 * pmpro_wp_mail_from() (paid-memberships-pro/includes/email.php) derives it from
	 * strtolower( $_SERVER['SERVER_NAME'] ), falling back to the lowercased siteurl
	 * host, with any leading 'www.' stripped, and only substitutes the configured
	 * pmpro_from_email when the seeded value EXACTLY equals that.
	 *
	 * To keep the configured PMPro sender actually applying for API connectors, we
	 * mirror PMPro's derivation when PMPro is active (so the exact-match guard
	 * cannot miss on hosts where SERVER_NAME differs from the home host, or where
	 * the home host contains uppercase), and otherwise mirror WordPress core. In
	 * both cases the host is lowercased, matching the comparisons both perform.
	 *
	 * Limitation: if the seeded value still does not equal what PMPro computes (an
	 * inherent PMPro-side quirk that also affects the PHPMailer path), PMPro's
	 * substitution will not fire and mail goes from 'wordpress@<host>'. We minimise
	 * that window here but cannot eliminate it without coupling to PMPro internals.
	 *
	 * @return string
	 */
	public static function default_from_email() {
		$sitename = '';

		// When PMPro is active, derive the host exactly as pmpro_wp_mail_from()
		// does so PMPro's wp_mail_from substitution reliably triggers.
		if ( defined( 'PMPRO_VERSION' ) && isset( $_SERVER['SERVER_NAME'] ) ) {
			$sitename = strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_NAME'] ) ) );
		}

		if ( '' === $sitename ) {
			$host = wp_parse_url( network_home_url(), PHP_URL_HOST );
			if ( is_string( $host ) && '' !== $host ) {
				$sitename = strtolower( $host );
			}
		}

		if ( '' === $sitename ) {
			$sitename = 'localhost.localdomain';
		}

		if ( 0 === strpos( $sitename, 'www.' ) ) {
			$sitename = substr( $sitename, 4 );
		}

		return 'wordpress@' . $sitename;
	}

	/**
	 * Instance accessor for the default From email (kept for back-compat with
	 * subclasses/callers that invoke it on an instance).
	 *
	 * @return string
	 */
	protected function get_default_from_email() {
		return self::default_from_email();
	}

	/**
	 * Get the MIME type for a file, guarded for hosts without the fileinfo
	 * extension (mime_content_type()).
	 *
	 * @param string $file Absolute path to the file.
	 * @return string MIME type, or 'application/octet-stream' as a safe default.
	 */
	protected function get_mime_type( $file ) {
		if ( function_exists( 'mime_content_type' ) ) {
			$type = mime_content_type( $file );
			if ( ! empty( $type ) ) {
				return $type;
			}
		}
		return 'application/octet-stream';
	}

	/**
	 * Allow connectors to override the sender email for strict SMTP servers.
	 *
	 * @return string
	 */
	protected function get_forced_from_email() {
		return '';
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
				// Respect a backslash-escaped quote inside a quoted string.
				if ( $in_quotes && $i > 0 && '\\' === $value[ $i - 1 ] ) {
					$current .= $char;
					continue;
				}
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
	 * Parse a comma-separated header value into an array of { name, email } entries.
	 *
	 * @param string $value
	 * @return array[] Array of arrays each with 'name' and 'email' keys.
	 */
	protected function parse_recipient_list( $value ) {
		$parsed = array();
		foreach ( $this->split_address_list( $value ) as $address ) {
			$parsed[] = $this->parse_recipient( $address );
		}
		return $parsed;
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
		foreach ( $this->parse_recipient_list( $value ) as $recipient ) {
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
			return array( 'name' => trim( $matches[1] ), 'email' => trim( $matches[2] ) );
		}
		return array( 'name' => '', 'email' => $recipient );
	}
}
