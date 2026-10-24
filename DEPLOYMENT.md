# Abliner deployment

This update replaces Snippe for new collections and mobile money withdrawals.
Historical Snippe rows and reference columns remain for auditing. They are never
sent to Abliner. The old Snippe callback returns HTTP 410.

## Before the switch

1. Back up the production database and private `.env`. Reconcile outstanding
   Snippe deposits and payouts with Snippe before deploying this replacement.
   Do not release old holds or repeat old transfers without confirming status.
2. Use a supported, security-patched PHP release with PDO MySQL, cURL and JSON.
3. Set `APP_ENV=production` and `APP_URL=https://earnsphere.co.tz` in the server's
   private `.env`. The callback is derived from `APP_URL`.

## Pull and migrate

During a maintenance window, run in the cPanel terminal:

```bash
cd ~/earnsphere.co.tz
git pull --ff-only origin main
php database/migrate_abliner.php
```

Run the migration before opening new payment/admin pages. It adds gateway IDs,
recipient verification fields, webhook receipts and a notification outbox. It is
safe to run again and does not change old payment amounts or wallet balances.
The existing payouts table from the prior integration is required.

## Connect Abliner

Open **Admin → Settings → Abliner Payments** and save both the API key and the
separate personal webhook signing secret from **Abliner Dashboard → API Keys**.
Fields stay blank after saving; “Configured” confirms a saved secret. Blank
submissions keep the previous secret. Environment variables `ABLINER_API_KEY`
and `ABLINER_WEBHOOK_SECRET` are also supported; a nonempty saved admin setting
has priority. Never put secrets in Git or browser code.

Save this callback in **Abliner Dashboard → API Keys → Add Your URL callback**:

```text
https://earnsphere.co.tz/webhooks/abliner.php
```

Requests also include this URL. Deposits use idempotency keys and HMAC request
signatures. Incoming events require a valid raw-body HMAC and a timestamp within
five minutes. Keep server time synchronized.

## Reconciliation and notifications

Add this cPanel cron job every minute, adjusting the path if necessary:

```bash
php /home/atimscot/earnsphere.co.tz/cron/verify-pending-payments.php
```

The job checks pending Abliner records by exact ID or customer reference and sends
queued activation SMS/email. It never automatically sends another deposit push
or payout. Timeouts retain holds until a verified final result arrives. Admins
can also check existing payouts from the withdrawal screen.

If a process stops after recording a request but before sending it, the row may
remain pending without a provider transaction. Confirm the absence with Abliner
support before resolving it manually. There is no automatic timeout refund.

## Admin wallet and fees

**Admin → Abliner Wallet** displays currencies returned by Abliner. “Add money”
requests a mobile money deposit into the company provider wallet. These deposits
do not credit user commission wallets, activate accounts or award commissions;
registration revenue reports exclude them.

Users see the registered recipient name and network and must confirm them before
withdrawal. The server checks the name again before reserving funds. Users receive
the requested withdrawal amount; company transfer fees come from the Abliner
provider wallet. Fee previews are estimates. Admin reports store actual fee and
total fields when the provider returns them.

## Checks

```bash
php tests/abliner_security_test.php
php tests/abliner_payment_test.php
```

Optional local endpoint-access checks: `python tests/check_payment_access.py /path/to/php`.

Tests use simulated HTTP and an in-memory SQLite database, with no live transfers.
They cover signed request bytes, signature rejection, duplicate callbacks,
commission rollback, recipient verification, payout holds, failures and timeout
reconciliation. Production concurrency uses MySQL row locks; SQLite tests do not
exercise those locks.

After configuring secrets, check the live admin balance and recipient lookup.
Abliner documents no sandbox. An end-to-end payment/payout check therefore uses
real money and must be deliberately performed with a small amount by the operator.

Rotate the earlier hardcoded email password at the provider and supply its
replacement through `SMTP_PASS`. Removing it from current code does not erase Git
history. Retired installer/debug/test URLs remain disabled.

## Two-level commission settings

New registrations pay only Level 1 and Level 2. In Admin → Settings, edit the
registration fee and those two amounts. Total commissions and the company share
are calculated automatically; Level 1 + Level 2 cannot exceed the fee. Saving is
atomic and changes appear on the registration/payment screens on the next load.
No database migration is needed for this change. Save Settings once to persist
the recalculated totals and set the retired Level 3 setting to zero.

New payment requests retain a snapshot of their fee and commission amounts.
Historical records and balances are retained. Existing pending requests without
a snapshot use the current two-level plan, but stop for manual reconciliation if
those commissions exceed the amount paid.
