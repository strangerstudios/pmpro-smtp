# Microsoft 365 / Outlook Setup

PMPro SMTP uses Microsoft Graph `sendMail` for the Microsoft 365 / Outlook provider.

## Technical Recommendation

Use Microsoft Graph `sendMail` instead of SMTP XOAUTH2 for the first-class Microsoft 365 integration.

Microsoft documents SMTP OAuth, including the `https://outlook.office.com/SMTP.Send` scope and SASL XOAUTH2 format, but SMTP AUTH is commonly disabled at the tenant or mailbox level and Microsoft recommends enabling it only for accounts that still require it. Graph `sendMail` avoids that SMTP AUTH dependency, uses OAuth authorization code flow, and fits PMPro SMTP's existing API-provider architecture.

The provider uses delegated Microsoft Graph permissions:

* `User.Read`
* `Mail.Send`
* `Mail.Send.Shared`
* `offline_access`

`Mail.Send` lets the authenticated user send mail. `Mail.Send.Shared` supports delegated shared/send-as scenarios when Microsoft grants the account the needed mailbox permissions. Some tenants may require admin consent before the connection can send.

The connector sends delegated mail through Graph's `/me/sendMail` endpoint and sets the configured From Email / Mailbox in the MIME message. This matches Microsoft's delegated send-as/send-on-behalf model. Sending through `/users/{from-mailbox}/sendMail` can require Full Access mailbox permission in addition to Send As or Send on Behalf, so PMPro SMTP does not use that stricter endpoint.

References:

* Microsoft Graph sendMail: https://learn.microsoft.com/en-us/graph/api/user-sendmail
* Microsoft Graph permissions: https://learn.microsoft.com/en-us/graph/permissions-reference
* Microsoft Graph sending from another user: https://learn.microsoft.com/en-us/graph/outlook-send-mail-from-other-user
* Microsoft OAuth authorization code flow: https://learn.microsoft.com/en-us/entra/identity-platform/v2-oauth2-auth-code-flow
* Microsoft refresh tokens: https://learn.microsoft.com/en-us/entra/identity-platform/refresh-tokens
* SMTP OAuth reference: https://learn.microsoft.com/en-us/exchange/client-developer/legacy-protocols/how-to-authenticate-an-imap-pop-smtp-application-by-using-oauth
* SMTP AUTH policy reference: https://learn.microsoft.com/en-us/exchange/clients-and-mobile-in-exchange-online/authenticated-client-smtp-submission

## Implementation Plan

1. Register a Microsoft 365 / Outlook provider in PMPro SMTP.
2. Collect Client ID, Client Secret, tenant mode, optional Tenant ID, and From Email / Mailbox.
3. Display the site's redirect URI for copying into Microsoft Entra.
4. Use OAuth 2.0 authorization code flow to connect the Microsoft account.
5. Store access and refresh tokens encrypted with the plugin's existing sensitive-setting encryption.
6. Refresh access tokens automatically before sending.
7. Build MIME with WordPress-bundled PHPMailer and send it through Microsoft Graph `sendMail`.
8. Surface actionable errors for consent, redirect URI, expired secret, token refresh, missing mailbox license, and send-as mismatch.

## Microsoft Entra App Setup

1. Go to Microsoft Entra admin center > App registrations > New registration.
2. Choose a name such as `PMPro SMTP`.
3. Supported account types:
   * Use "Accounts in this organizational directory only" for one Microsoft 365 tenant.
   * Use "Accounts in any organizational directory" if this app registration must support multiple Microsoft 365 tenants.
   * Do not choose personal Microsoft accounts. This provider requests Microsoft 365 work/school delegated permissions, including `Mail.Send.Shared`.
4. Add a Web redirect URI. Copy the Redirect URI shown in `Memberships > SMTP > Microsoft 365 / Outlook Settings`.
5. After creating the app, copy the Application (client) ID into PMPro SMTP.
6. Create a client secret under Certificates & secrets. Copy the secret value into PMPro SMTP and note its expiration date.
7. Under API permissions, add delegated Microsoft Graph permissions:
   * `User.Read`
   * `Mail.Send`
   * `Mail.Send.Shared`
   * `offline_access`
8. If Microsoft asks for admin consent, have a tenant admin grant consent.

## WordPress Setup

1. Go to `Memberships > SMTP > Connection`.
2. Select `Microsoft 365 / Outlook`.
3. Enter the Client ID and Client Secret.
4. Set Tenant Mode:
   * `Work or school accounts` for most Microsoft 365 tenants.
   * `Specific tenant ID` if the organization requires tenant-specific sign-in.
5. Enter the From Email / Mailbox. This should usually be the mailbox that signs in.
6. Save settings.
7. Click `Connect Microsoft 365`.
8. Sign in with the Microsoft account that should send mail.
9. Send a test email from the Test Email tab.

## Common Failure Diagnostics

* Invalid redirect URI: Copy the exact Redirect URI from PMPro SMTP into the Microsoft app registration.
* Expired client secret: Create a new secret in Microsoft Entra, save it in PMPro SMTP, then reconnect.
* Missing API permissions or admin consent: Confirm delegated `Mail.Send` is present and grant admin consent if Microsoft requires it.
* Conditional Access or Security Defaults blocked auth: Review the tenant policy or connect with an allowed account.
* Token refresh failure: Reconnect the Microsoft 365 account.
* Missing Exchange Online mailbox license: Ensure the authenticated account/mailbox has an Exchange Online mailbox.
* Send-as mismatch: The authenticated account must be allowed to send as the configured From Email / Mailbox.
* Shared mailbox sent items: PMPro SMTP sends through `/me/sendMail`, so Microsoft normally saves sent mail in the authenticated user's Sent Items. Microsoft 365 administrators can configure shared mailbox sent-item copy behavior if needed.
* SMTP AUTH disabled: This Graph provider does not require SMTP AUTH. If a legacy SMTP connection fails against `smtp.office365.com`, switch to this provider or have a Microsoft 365 admin enable SMTP AUTH only for that mailbox.

## Migration From smtp.office365.com Username/Password

Microsoft 365 basic SMTP username/password is frequently disabled by tenant policy, mailbox policy, Security Defaults, or Conditional Access. Do not store the Microsoft account password in PMPro SMTP.

To migrate:

1. Create the Microsoft Entra app registration above.
2. Switch PMPro SMTP from `Custom SMTP` to `Microsoft 365 / Outlook`.
3. Enter the app credentials and mailbox.
4. Connect with Microsoft OAuth.
5. Send a test email.
6. Remove any saved Microsoft 365 password from the old Custom SMTP connection.

Keep DNS authentication such as SPF, DKIM, and DMARC configured for the sending domain. OAuth controls authentication to Microsoft; it does not replace domain authentication.
