<?php
/**
 * Settings admin page.
 *
 * From name/email and email log settings are managed by PMPro core.
 *
 * @package PMProSMTP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the settings page.
 */
function pmpro_smtp_settings_page() {
	$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'connection';
	$tabs       = array(
		'connection' => __( 'Connection', 'pmpro-smtp' ),
		'test'       => __( 'Test Email', 'pmpro-smtp' ),
	);

	$saved = false;
	if (
		isset( $_POST['pmpro_smtp_settings_nonce'] ) &&
		wp_verify_nonce( wp_unslash( $_POST['pmpro_smtp_settings_nonce'] ), 'pmpro_smtp_settings_' . $active_tab )
	) {
		if ( 'connection' === $active_tab ) {
			$saved = pmpro_smtp_save_connection_settings();
		}
	}
	?>

	<div class="wrap pmpro_admin">

		<?php if ( $saved ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'pmpro-smtp' ); ?></p></div>
		<?php endif; ?>

		<?php settings_errors( 'pmpro_smtp' ); ?>

		<?php pmpro_smtp_render_microsoft365_notices(); ?>

		<?php if ( pmpro_smtp_is_test_mode() ) : ?>
			<div class="notice notice-warning">
				<p><strong><?php esc_html_e( 'Sandbox Mode Active', 'pmpro-smtp' ); ?></strong> &mdash; <?php esc_html_e( 'Emails are not being delivered. Disable sandbox mode when you are ready to send.', 'pmpro-smtp' ); ?></p>
			</div>
		<?php endif; ?>

		<nav class="pmpro-nav-primary" aria-labelledby="pmpro-smtp-menu">
			<h2 id="pmpro-smtp-menu" class="screen-reader-text"><?php esc_html_e( 'Settings Sections', 'pmpro-smtp' ); ?></h2>
			<ul>
				<?php foreach ( $tabs as $tab_key => $tab_label ) :
					$url   = add_query_arg( array( 'page' => 'pmpro-smtp', 'tab' => $tab_key ), admin_url( 'admin.php' ) );
					?>
					<li>
						<a href="<?php echo esc_url( $url ); ?>"<?php echo ( $tab_key === $active_tab ) ? ' class="current"' : '' ?> ><?php echo esc_html( $tab_label ); ?></a>
					</li>
				<?php endforeach; ?>
			</ul>
		</nav>

		<hr class="wp-header-end" />

		<div class="tab-content">
			<?php
			if ( 'connection' === $active_tab ) {
				pmpro_smtp_render_connection_tab();
			} else {
				pmpro_smtp_render_test_tab();
			}
			?>
		</div>
	
	</div>
	<?php
}

// ============================================================================
// Connection tab
// ============================================================================

function pmpro_smtp_render_connection_tab() {
	$active_connector_key = get_option( 'pmpro_smtp_active_connector', '' );
	$backup_connector_key = get_option( 'pmpro_smtp_backup_connector', '' );
	$test_mode            = get_option( 'pmpro_smtp_test_mode', false );
	$connectors           = pmpro_smtp_get_connectors();
	$effective_from_email = apply_filters( 'wp_mail_from', get_option( 'admin_email' ) );
	$effective_from_name  = apply_filters( 'wp_mail_from_name', get_option( 'blogname' ) );
	$pmpro_active         = defined( 'PMPRO_VERSION' );
	$sender_settings_url  = $pmpro_active
		? admin_url( 'admin.php?page=pmpro-emailsettings' )
		: admin_url( 'options-general.php' );
	$sender_settings_text = $pmpro_active
		? __( 'Edit sender settings in PMPro Email Settings', 'pmpro-smtp' )
		: __( 'Edit sender settings in WordPress General Settings', 'pmpro-smtp' );
	?>
	<form method="post">
		<?php wp_nonce_field( 'pmpro_smtp_settings_connection', 'pmpro_smtp_settings_nonce' ); ?>

		<div id="pmpro-smtp-connection" class="pmpro_section" data-visibility="shown">
			<div class="pmpro_section_toggle">
				<button class="pmpro_section-toggle-button" type="button" aria-expanded="true">
					<span class="dashicons dashicons-arrow-up-alt2"></span>
					<?php esc_html_e( 'Email Provider', 'pmpro-smtp' ); ?>
				</button>
			</div>
			<div class="pmpro_section_inside">
				<p><?php esc_html_e( 'Choose the service you want to use to send your site\'s emails. Select a provider and enter its credentials below.', 'pmpro-smtp' ); ?></p>

				<table class="form-table">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Current Sender', 'pmpro-smtp' ); ?></th>
							<td>
								<p>
									<strong><?php echo esc_html( $effective_from_name ); ?></strong>
									&lt;<?php echo esc_html( $effective_from_email ); ?>&gt;
								</p>
								<p class="description">
									<?php esc_html_e( 'By default, PMPro SMTP uses your existing WordPress or Paid Memberships Pro sender settings.', 'pmpro-smtp' ); ?>
									<a href="<?php echo esc_url( $sender_settings_url ); ?>"><?php echo esc_html( $sender_settings_text ); ?></a>
								</p>
							</td>
						</tr>
					</tbody>
				</table>

				<div class="pmpro-smtp-connector-grid">
					<?php foreach ( $connectors as $key => $connector ) : ?>
						<label class="pmpro-smtp-connector-card <?php echo ( $key === $active_connector_key ) ? 'is-selected' : ''; ?>">
							<input type="radio" name="pmpro_smtp_active_connector" value="<?php echo esc_attr( $key ); ?>" <?php checked( $key, $active_connector_key ); ?> />
							<span class="pmpro-smtp-connector-title"><?php echo esc_html( $connector->get_title() ); ?></span>
						</label>
					<?php endforeach; ?>
					<label class="pmpro-smtp-connector-card <?php echo empty( $active_connector_key ) ? 'is-selected' : ''; ?>">
						<input type="radio" name="pmpro_smtp_active_connector" value="" <?php checked( '', $active_connector_key ); ?> />
						<span class="pmpro-smtp-connector-title"><?php esc_html_e( 'None (WordPress default)', 'pmpro-smtp' ); ?></span>
					</label>
				</div>

				<?php foreach ( $connectors as $key => $connector ) :
					$fields = $connector->get_settings_fields();
					if ( empty( $fields ) ) {
						continue;
					}
					$connector_settings = get_option( 'pmpro_smtp_connector_' . $key, array() );
					?>
					<div class="pmpro-smtp-connector-fields" id="pmpro-smtp-fields-<?php echo esc_attr( $key ); ?>" <?php echo ( $key !== $active_connector_key ) ? 'style="display:none;"' : ''; ?>>
						<hr />
						<h3><?php echo esc_html( $connector->get_title() ); ?> <?php esc_html_e( 'Settings', 'pmpro-smtp' ); ?></h3>
						<?php if ( $connector->get_description() ) : ?>
							<p class="description"><?php echo esc_html( $connector->get_description() ); ?></p>
						<?php endif; ?>
						<table class="form-table">
							<tbody>
								<?php foreach ( $fields as $field ) :
									$field_key    = 'pmpro_smtp_connector_' . $key . '_' . $field['key'];
									$saved_val    = isset( $connector_settings[ $field['key'] ] ) ? $connector_settings[ $field['key'] ] : '';
									$is_sensitive = ! empty( $field['sensitive'] );
									?>
									<tr>
										<th scope="row">
											<label for="<?php echo esc_attr( $field_key ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
										</th>
										<td>
											<?php if ( 'select' === $field['type'] ) : ?>
												<select name="<?php echo esc_attr( $field_key ); ?>" id="<?php echo esc_attr( $field_key ); ?>">
													<?php foreach ( $field['options'] as $opt_val => $opt_label ) : ?>
														<option value="<?php echo esc_attr( $opt_val ); ?>" <?php selected( $saved_val, $opt_val ); ?>><?php echo esc_html( $opt_label ); ?></option>
													<?php endforeach; ?>
												</select>
											<?php elseif ( 'checkbox' === $field['type'] ) : ?>
												<label>
													<input type="checkbox" name="<?php echo esc_attr( $field_key ); ?>" id="<?php echo esc_attr( $field_key ); ?>" value="1" <?php checked( '1', $saved_val ); ?> />
													<?php echo isset( $field['desc'] ) ? esc_html( $field['desc'] ) : ''; ?>
												</label>
											<?php elseif ( 'password' === $field['type'] ) : ?>
												<input
													type="password"
													name="<?php echo esc_attr( $field_key ); ?>"
													id="<?php echo esc_attr( $field_key ); ?>"
													value=""
													placeholder="<?php echo ( $is_sensitive && ! empty( $saved_val ) ) ? esc_attr__( '(stored — enter to change)', 'pmpro-smtp' ) : ''; ?>"
													class="regular-text"
													autocomplete="new-password"
												/>
											<?php else : ?>
												<input
													type="<?php echo esc_attr( $field['type'] ); ?>"
													name="<?php echo esc_attr( $field_key ); ?>"
													id="<?php echo esc_attr( $field_key ); ?>"
													value="<?php echo esc_attr( $is_sensitive ? '' : $saved_val ); ?>"
													placeholder="<?php echo isset( $field['placeholder'] ) ? esc_attr( $field['placeholder'] ) : ''; ?>"
													class="regular-text"
												/>
											<?php endif; ?>
											<?php if ( ! empty( $field['desc'] ) && 'checkbox' !== $field['type'] ) : ?>
												<p class="description"><?php echo esc_html( $field['desc'] ); ?></p>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<?php
						if ( 'microsoft365' === $key ) {
							pmpro_smtp_render_microsoft365_oauth_controls( $connector );
						}
						?>
					</div>
				<?php endforeach; ?>
			</div>
		</div>

		<div id="pmpro-smtp-backup" class="pmpro_section" data-visibility="shown">
			<div class="pmpro_section_toggle">
				<button class="pmpro_section-toggle-button" type="button" aria-expanded="true">
					<span class="dashicons dashicons-arrow-up-alt2"></span>
					<?php esc_html_e( 'Backup Provider', 'pmpro-smtp' ); ?>
				</button>
			</div>
			<div class="pmpro_section_inside">
				<p><?php esc_html_e( 'Optionally configure a secondary provider. If your primary provider fails, PMPro SMTP will automatically retry with the backup.', 'pmpro-smtp' ); ?></p>
				<table class="form-table">
					<tbody>
						<tr>
							<th scope="row"><label for="pmpro_smtp_backup_connector"><?php esc_html_e( 'Backup Provider', 'pmpro-smtp' ); ?></label></th>
							<td>
								<select name="pmpro_smtp_backup_connector" id="pmpro_smtp_backup_connector">
									<option value=""><?php esc_html_e( '— None —', 'pmpro-smtp' ); ?></option>
									<?php foreach ( $connectors as $key => $connector ) :
										if ( $key === $active_connector_key || 'generic' === $key ) {
											continue;
										}
										?>
										<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $backup_connector_key, $key ); ?>><?php echo esc_html( $connector->get_title() ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Custom SMTP cannot be used as a backup provider.', 'pmpro-smtp' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>

		<div id="pmpro-smtp-sandbox" class="pmpro_section" data-visibility="shown">
			<div class="pmpro_section_toggle">
				<button class="pmpro_section-toggle-button" type="button" aria-expanded="true">
					<span class="dashicons dashicons-arrow-up-alt2"></span>
					<?php esc_html_e( 'Sandbox Mode', 'pmpro-smtp' ); ?>
				</button>
			</div>
			<div class="pmpro_section_inside">
				<table class="form-table">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Enable Sandbox Mode', 'pmpro-smtp' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="pmpro_smtp_test_mode" value="1" <?php checked( '1', $test_mode ); ?> />
									<?php esc_html_e( 'Prevent all emails from being delivered.', 'pmpro-smtp' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'When enabled, emails are intercepted and discarded — nothing is sent. Useful for staging and development environments. Disable before going live.', 'pmpro-smtp' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>

		<?php submit_button( __( 'Save Settings', 'pmpro-smtp' ) ); ?>
	</form>
	<?php
}

/**
 * Render Microsoft 365 OAuth notices.
 *
 * @return void
 */
function pmpro_smtp_render_microsoft365_notices() {
	if ( ! empty( $_GET['pmpro_smtp_microsoft365_connected'] ) ) {
		?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Microsoft 365 account connected.', 'pmpro-smtp' ); ?></p>
		</div>
		<?php
	}

	if ( ! empty( $_GET['pmpro_smtp_microsoft365_disconnected'] ) ) {
		?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Microsoft 365 account disconnected.', 'pmpro-smtp' ); ?></p>
		</div>
		<?php
	}

	if ( ! empty( $_GET['pmpro_smtp_microsoft365_error'] ) ) {
		$error = sanitize_text_field( wp_unslash( $_GET['pmpro_smtp_microsoft365_error'] ) );
		?>
		<div class="notice notice-error">
			<p><?php echo esc_html( $error ); ?></p>
		</div>
		<?php
	}
}

/**
 * Render Microsoft 365 redirect URI and OAuth buttons.
 *
 * @param PMPRO_SMTP_Connector_Microsoft365 $connector Connector.
 * @return void
 */
function pmpro_smtp_render_microsoft365_oauth_controls( $connector ) {
	$settings            = get_option( 'pmpro_smtp_connector_microsoft365', array() );
	$authenticated_email = isset( $settings['authenticated_email'] ) ? sanitize_email( $settings['authenticated_email'] ) : '';
	$mailbox             = $connector->get_mailbox();
	$is_connected        = $connector->is_connected();
	$connect_url         = wp_nonce_url(
		add_query_arg(
			array(
				'action' => 'pmpro_smtp_microsoft365_connect',
			),
			admin_url( 'admin-post.php' )
		),
		'pmpro_smtp_microsoft365_connect'
	);
	$disconnect_url      = wp_nonce_url(
		add_query_arg(
			array(
				'action' => 'pmpro_smtp_microsoft365_disconnect',
			),
			admin_url( 'admin-post.php' )
		),
		'pmpro_smtp_microsoft365_disconnect'
	);
	?>
	<table class="form-table">
		<tbody>
			<tr>
				<th scope="row"><label for="pmpro-smtp-microsoft365-redirect-uri"><?php esc_html_e( 'Redirect URI', 'pmpro-smtp' ); ?></label></th>
				<td>
					<input type="text" id="pmpro-smtp-microsoft365-redirect-uri" class="regular-text code" readonly value="<?php echo esc_attr( $connector->get_redirect_uri() ); ?>" onclick="this.select();" />
					<p class="description"><?php esc_html_e( 'Copy this exact URI into the Redirect URI list for your Microsoft Entra app registration.', 'pmpro-smtp' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Connection Status', 'pmpro-smtp' ); ?></th>
				<td>
					<?php if ( $is_connected ) : ?>
						<p>
							<strong><?php esc_html_e( 'Connected', 'pmpro-smtp' ); ?></strong>
							<?php if ( ! empty( $authenticated_email ) ) : ?>
								<?php
								printf(
									/* translators: %s: authenticated Microsoft email */
									esc_html__( 'as %s', 'pmpro-smtp' ),
									esc_html( $authenticated_email )
								);
								?>
							<?php endif; ?>
						</p>
						<?php if ( ! empty( $authenticated_email ) && ! empty( $mailbox ) && strtolower( $authenticated_email ) !== strtolower( $mailbox ) ) : ?>
							<p class="notice notice-warning inline">
								<?php esc_html_e( 'The authenticated account differs from the configured mailbox. Sending will only work if Microsoft grants this account permission to send as that mailbox.', 'pmpro-smtp' ); ?>
							</p>
						<?php endif; ?>
					<?php else : ?>
						<p><strong><?php esc_html_e( 'Not connected', 'pmpro-smtp' ); ?></strong></p>
					<?php endif; ?>
					<p>
						<a class="button button-primary" href="<?php echo esc_url( $connect_url ); ?>">
							<?php echo $is_connected ? esc_html__( 'Reconnect Microsoft 365', 'pmpro-smtp' ) : esc_html__( 'Connect Microsoft 365', 'pmpro-smtp' ); ?>
						</a>
						<?php if ( $is_connected ) : ?>
							<a class="button" href="<?php echo esc_url( $disconnect_url ); ?>"><?php esc_html_e( 'Disconnect', 'pmpro-smtp' ); ?></a>
						<?php endif; ?>
					</p>
					<p class="description"><?php esc_html_e( 'Save Client ID, Client Secret, tenant settings, and mailbox before connecting.', 'pmpro-smtp' ); ?></p>
				</td>
			</tr>
		</tbody>
	</table>
	<?php
}

function pmpro_smtp_save_connection_settings() {
	$connectors = pmpro_smtp_get_connectors();

	// Active connector.
	$active = isset( $_POST['pmpro_smtp_active_connector'] ) ? sanitize_key( wp_unslash( $_POST['pmpro_smtp_active_connector'] ) ) : '';
	if ( ! empty( $active ) && ! isset( $connectors[ $active ] ) ) {
		$active = '';
	}
	update_option( 'pmpro_smtp_active_connector', $active );

	// Backup connector.
	$backup = isset( $_POST['pmpro_smtp_backup_connector'] ) ? sanitize_key( wp_unslash( $_POST['pmpro_smtp_backup_connector'] ) ) : '';
	if ( ! empty( $backup ) && ( ! isset( $connectors[ $backup ] ) || 'generic' === $backup || $backup === $active ) ) {
		$backup = '';
	}
	update_option( 'pmpro_smtp_backup_connector', $backup );

	// Sandbox mode.
	update_option( 'pmpro_smtp_test_mode', isset( $_POST['pmpro_smtp_test_mode'] ) ? '1' : '0' );

	// Per-connector settings.
	foreach ( $connectors as $key => $connector ) {
		$fields         = $connector->get_settings_fields();
		$existing       = get_option( 'pmpro_smtp_connector_' . $key, array() );
		$connector_data = $existing;

		foreach ( $fields as $field ) {
			$post_key = 'pmpro_smtp_connector_' . $key . '_' . $field['key'];

			if ( 'checkbox' === $field['type'] ) {
				$connector_data[ $field['key'] ] = isset( $_POST[ $post_key ] ) ? '1' : '0';
				continue;
			}

			if ( ! isset( $_POST[ $post_key ] ) ) {
				continue;
			}

			$raw_value = wp_unslash( $_POST[ $post_key ] );

			if ( 'password' === $field['type'] && ! empty( $field['sensitive'] ) ) {
				if ( '' === $raw_value ) {
					continue; // Empty = keep existing.
				}
				$connector_data[ $field['key'] ] = pmpro_smtp_encrypt( $raw_value );
			} elseif ( 'email' === $field['type'] ) {
				$value = sanitize_email( $raw_value );
				if ( '' !== $value && ! is_email( $value ) ) {
					add_settings_error(
						'pmpro_smtp',
						'pmpro_smtp_invalid_' . $key . '_' . $field['key'],
						sprintf(
							/* translators: %s: field label */
							__( '%s must be a valid email address.', 'pmpro-smtp' ),
							$field['label']
						),
						'error'
					);
					continue;
				}
				$connector_data[ $field['key'] ] = $value;
			} else {
				$connector_data[ $field['key'] ] = sanitize_text_field( $raw_value );
			}
		}

		update_option( 'pmpro_smtp_connector_' . $key, $connector_data );
	}

	return true;
}

// ============================================================================
// Test Email tab
// ============================================================================

function pmpro_smtp_render_test_tab() {
	$connector = pmpro_smtp_get_active_connector();
	?>
	<div id="pmpro-smtp-test" class="pmpro_section" data-visibility="shown">
		<div class="pmpro_section_toggle">
			<button class="pmpro_section-toggle-button" type="button" aria-expanded="true">
				<span class="dashicons dashicons-arrow-up-alt2"></span>
				<?php esc_html_e( 'Send a Test Email', 'pmpro-smtp' ); ?>
			</button>
		</div>
		<div class="pmpro_section_inside">
			<?php if ( null === $connector ) : ?>
				<p><?php printf(
					/* translators: %s: link to Connection tab */
					esc_html__( 'No provider is configured. %s to set up an email provider before sending a test.', 'pmpro-smtp' ),
					'<a href="' . esc_url( add_query_arg( array( 'page' => 'pmpro-smtp', 'tab' => 'connection' ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Go to the Connection tab', 'pmpro-smtp' ) . '</a>'
				); ?></p>
			<?php else : ?>
				<p>
					<?php printf(
						/* translators: %s: connector title */
						esc_html__( 'Send a test email using your configured provider: %s.', 'pmpro-smtp' ),
						'<strong>' . esc_html( $connector->get_title() ) . '</strong>'
					); ?>
				</p>
				<table class="form-table">
					<tbody>
						<tr>
							<th scope="row"><label for="pmpro-smtp-test-email"><?php esc_html_e( 'Send To', 'pmpro-smtp' ); ?></label></th>
							<td>
								<input type="email" id="pmpro-smtp-test-email" value="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" class="regular-text" />
							</td>
						</tr>
					</tbody>
				</table>
				<p>
					<button type="button" id="pmpro-smtp-send-test" class="button button-primary"><?php esc_html_e( 'Send Test Email', 'pmpro-smtp' ); ?></button>
				</p>
				<div id="pmpro-smtp-test-result" style="display:none;" class="notice inline"></div>
			<?php endif; ?>
		</div>
	</div>
	<?php
}
