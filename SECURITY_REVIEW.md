# Security hardening — 27 October 2026

Source review and isolated regression tests, not a production penetration test or a guarantee against compromise. No live payment, email or SMS was sent.

## Findings fixed

- Stored DOM XSS in SMS recipient search and selected-recipient chips: names and phone numbers now use text nodes and input values.
- Shared toasts no longer interpolate messages or notification types into HTML. Payout reference/status/error/source values, announcement dates, profile initials and unknown status labels are escaped.
- Admin flash messages use HTML-safe JSON rather than `addslashes`, preventing closing-script-tag breakouts.
- OTP resend and announcement mark-read require POST and session CSRF. Dashboard supplies the token. Malformed CSRF arrays are safely rejected.
- Password-reset approval is bound to the verified account and expires after 15 minutes. New recovery or OTP resend clears earlier approval. Session IDs rotate after verification and reset.
- Login attempts are capped at 60 per source IP and 20 per normalized identifier in 15 minutes. Unknown accounts and wrong passwords return the same error; unknown accounts also perform a password-hash check.
- OTP creation is capped at 3 per user/type and verification at 10 attempts per user/type in 15 minutes. Limits persist across sessions, with locked files serializing concurrent requests.
- CSP denies embedded objects, restricts base URLs/form destinations and explicitly permits existing YouTube hosts. Existing jQuery CDN is allowed. Credentialless YouTube frames and cross-origin isolation remain intact.
- Apache blocks additional uploaded executable extensions and common database/backup/editor files.

## Deployment

No migration or cron required. Deploy PHP, JavaScript and `.htaccess` together. PHP needs write access to `logs/` for private `logs/security-limits/`. The limiter fails closed if storage is unavailable; inspect the PHP log if all logins are refused. Limits count successful requests too.

Apache rules deny HTTP access to logs, configuration, tests and repository metadata. Other web servers need equivalent rules. Never expose the PHP development server publicly. Limiter files hold hashed bucket names, counts and expiry times, not passwords or OTP values.

After deployment, verify login, recovery, SMS recipient selection, announcement read state and inline video using an authorized test account. Do not test payouts with real users' funds.

## Validation

New tests: `security_hardening_test.php`, `security_hardening_ui_test.js`, `check_security_routes.py`. They exercise malicious text, script breakouts, malformed CSRF, real route guards, account-bound/expiring reset proof, persistent/concurrent limits, OTP creation/guess limits and one-use codes.

Existing staff/agent authorization, payment access, marketplace ownership/XSS, upload rejection, notifications, support, YouTube and signed-webhook tests were also exercised. Tests use temporary copies, SQLite and mocked UI. The pre-existing missing local database configuration is supplied from Git only within the test copy; the workspace deletion is preserved.

## Remaining limits

Inline scripts/event handlers still require CSP `unsafe-inline`; a strict nonce policy needs a separate frontend migration. This pass does not verify production TLS, hosting permissions, server/runtime patch levels, backups, firewall rules, dependencies or gateway account security. Password policy and session revocation across all devices deserve a dedicated authentication review. Do not describe the service as impossible to hack.

References: [OWASP DOM XSS prevention](https://cheatsheetseries.owasp.org/cheatsheets/DOM_based_XSS_Prevention_Cheat_Sheet.html), [OWASP CSP](https://cheatsheetseries.owasp.org/cheatsheets/Content_Security_Policy_Cheat_Sheet.html).
