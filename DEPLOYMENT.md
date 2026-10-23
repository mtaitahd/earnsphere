# Production security update

Deploy the committed PHP files and root `.htaccess` together. Retired installer,
diagnostic, mail-test, payment-debug and payout-test URLs now return 404.

Before enabling payments:

- Rotate the previously hardcoded SMTP password at the email provider. Set the
  replacement in `SMTP_PASS` in the server environment or private `.env` file.
  Removing a password from current code does not remove it from Git history.
- Configure the correct Snippe webhook secret in server configuration or payment
  settings. Unsigned requests and requests without a configured secret are rejected.
- Use `APP_ENV=production` and the actual HTTPS site URL for `APP_URL` and
  `PAYOUT_WEBHOOK_URL` on the server.
- Reconcile existing pending transfers with the payment provider before retrying
  or refunding them. Delayed transfers retain their holds; visiting the dashboard
  no longer refunds transfers after one hour. Admin payout verification remains
  available for checking provider status.

Check login, uploaded images and signed payment notifications after deployment.
Use a provider sandbox to test payment and payout flows before real transfers.

Local regression checks: `php tests/security_regression.php`.
