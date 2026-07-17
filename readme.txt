=== Paid Memberships Pro - SMTP ===
Contributors: strangerstudios
Requires at least: 6.3
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.1
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Improve email deliverability for your membership site. Connect WordPress to trusted SMTP providers and transactional email APIs without upsells.

Forked from Gravity SMTP by Gravity Forms, adapted by Paid Memberships Pro to provide a focused, no-upsells SMTP option for WordPress site owners.

== Description ==

Paid Memberships Pro - SMTP gives site owners a simple way to route WordPress email through a trusted mail provider.

Features include:

* Generic SMTP support for providers like Google Workspace and Gmail app passwords.
* API-based connectors for SendGrid, Mailgun, Postmark, Brevo, Resend, and MailerSend.
* Optional backup provider support for API connectors.
* Sandbox mode for staging and testing.
* Compatibility with Paid Memberships Pro email logging.

From name and from email settings remain managed by WordPress and Paid Memberships Pro.

Automatic backup failover applies only when the primary provider is an API connector. When the primary provider is Custom SMTP, WordPress sends through PHPMailer directly, so the configured backup provider is not used.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/` or install it as usual.
2. Activate the plugin.
3. Go to `Memberships > SMTP`.
4. Choose a provider and save your credentials.
5. Send a test email to confirm delivery.

== Frequently Asked Questions ==

= Can I use this with Google Workspace or Gmail? =

Yes. Use the `Custom SMTP` connector with:

* Host: `smtp.gmail.com`
* Port: `587`
* Encryption: `TLS`
* Authentication: enabled
* Username: your full Google email address
* Password: your Google app password

= Does this replace PMPro email logging? =

No. PMPro core continues to handle email logging.

= Are emails formatted the same on an API provider as with Custom SMTP? =

Yes. PMPro's email formatting — including the optional `email_header.html` / `email_footer.html` theme templates — is applied on both the `Custom SMTP` (PHPMailer) path and the API connector path.

== Changelog ==

= 0.1.1 - 2026-07-17 =
* BUG FIX: Fixed an issue where emails sent through API-based connectors were missing PMPro's email formatting. #4 (@dparker1005)

= 0.1 - 2026-07-15 =
* Initial release.
