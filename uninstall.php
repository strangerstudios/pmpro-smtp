<?php
/**
 * Uninstall handler for Paid Memberships Pro - SMTP.
 *
 * Removes all plugin options, including encrypted credentials, when the plugin
 * is deleted from the WordPress admin.
 *
 * @package PMProSMTP
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Connector keys whose per-connector option arrays must be removed.
 *
 * Kept in sync with pmpro_smtp_get_connectors(). Hardcoded here because plugin
 * code is not guaranteed to be loaded during uninstall.
 */
$pmpro_smtp_connector_keys = array(
	'generic',
	'sendgrid',
	'mailgun',
	'postmark',
	'brevo',
	'resend',
	'mailersend',
);

/**
 * Delete every option this plugin stores for a single site.
 *
 * @param string[] $connector_keys Connector slugs.
 * @return void
 */
function pmpro_smtp_delete_site_options( $connector_keys ) {
	delete_option( 'pmpro_smtp_active_connector' );
	delete_option( 'pmpro_smtp_backup_connector' );
	delete_option( 'pmpro_smtp_test_mode' );

	foreach ( $connector_keys as $key ) {
		delete_option( 'pmpro_smtp_connector_' . $key );
	}

	// The encrypt-unavailable notice is a per-user, 60-second transient
	// (pmpro_smtp_encrypt_unavailable_<user_id>); it self-expires long before
	// uninstall, so there is nothing durable to clean up here.
}

if ( is_multisite() ) {
	$site_ids = get_sites( array( 'fields' => 'ids' ) );
	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );
		pmpro_smtp_delete_site_options( $pmpro_smtp_connector_keys );
		restore_current_blog();
	}
} else {
	pmpro_smtp_delete_site_options( $pmpro_smtp_connector_keys );
}
