<?php
/**
 * Plugin Name: Paid Memberships Pro - SMTP
 * Plugin URI: https://www.paidmembershipspro.com/add-ons/pmpro-smtp
 * Description: Improve email deliverability for your membership site. Connect to SMTP providers and transactional email APIs with email logging and diagnostics.
 * Version: 0.1
 * Author: Paid Memberships Pro
 * Author URI: https://www.paidmembershipspro.com
 * Text Domain: pmpro-smtp
 * Domain Path: /languages
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Forked from Gravity SMTP by Gravity Forms, adapted by Paid Memberships Pro
 * to provide a focused, no-upsells SMTP option for WordPress site owners.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PMPRO_SMTP_BASE_FILE', __FILE__ );
define( 'PMPRO_SMTP_BASENAME', plugin_basename( __FILE__ ) );
define( 'PMPRO_SMTP_DIR', dirname( __FILE__ ) );
define( 'PMPRO_SMTP_VERSION', '0.1' );

/**
 * Load plugin textdomain.
 */
function pmpro_smtp_load_textdomain() {
	load_plugin_textdomain( 'pmpro-smtp', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'pmpro_smtp_load_textdomain' );

/**
 * Load plugin includes.
 */
function pmpro_smtp_includes() {
	// Connectors.
	require_once PMPRO_SMTP_DIR . '/includes/connectors/class-connector-base.php';
	require_once PMPRO_SMTP_DIR . '/includes/connectors/class-connector-generic.php';
	require_once PMPRO_SMTP_DIR . '/includes/connectors/class-connector-sendgrid.php';
	require_once PMPRO_SMTP_DIR . '/includes/connectors/class-connector-mailgun.php';
	require_once PMPRO_SMTP_DIR . '/includes/connectors/class-connector-postmark.php';
	require_once PMPRO_SMTP_DIR . '/includes/connectors/class-connector-brevo.php';
	require_once PMPRO_SMTP_DIR . '/includes/connectors/class-connector-resend.php';
	require_once PMPRO_SMTP_DIR . '/includes/connectors/class-connector-mailersend.php';

	// Core.
	require_once PMPRO_SMTP_DIR . '/includes/functions.php';

	// Admin only.
	if ( is_admin() ) {
		require_once PMPRO_SMTP_DIR . '/includes/admin.php';
	}
}
add_action( 'plugins_loaded', 'pmpro_smtp_includes' );
