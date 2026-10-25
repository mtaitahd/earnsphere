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

## Automatic verification and notifications

No payment cron is required. Remove any old cPanel entry for the removed payment
reconciliation script. Save `https://earnsphere.co.tz/webhooks/abliner.php` as the
callback in Abliner Dashboard → API Keys and use the same personal webhook signing
secret in this application. Each outbound payment also supplies this callback URL.

A valid signed webhook commits the deposit activation/commissions or withdrawal
settlement exactly once. A successful outbound HTTP response binds the provider's
transaction ID to that specific request; generated provider references need not equal
our customer reference. Identity binding alone does not authorize settlement.

If the immediate payout response cannot complete settlement, one automatic status GET
checks the existing transaction. The visible withdrawal history also performs bounded
read-only checks. Webhooks continue to settle transfers after the user closes the page.
Notifications are attempted after financial commit; their failure cannot reverse a
payment. Without a background worker, notification retries occur on later events/API
requests rather than on a guaranteed schedule.

If a process stops after recording a request but before sending it, the row may
remain pending without a provider transaction. Confirm the absence with Abliner
support before resolving it manually. There is no automatic timeout refund.

## Admin wallet and fees

**Admin → Abliner Wallet** displays currencies returned by Abliner. “Add money”
requests a mobile money deposit into the company provider wallet. These deposits
do not credit user commission wallets, activate accounts or award commissions;
registration revenue reports exclude them.

Users see the registered recipient name and network and must confirm them before
withdrawal. The server checks the name again before reserving funds. Users receive the requested amount minus the saved user withdrawal charge.
Provider transfer fees are separately paid from the company Abliner wallet. Fee previews are estimates. Admin reports store actual fee and
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

## Fixed user withdrawal charge

After pulling this update, run `php database/migrate_withdrawal_charge.php` before
accepting withdrawals. This adds a per-withdrawal saved charge and a new admin
setting, defaulting to zero. Existing withdrawals remain free of the new charge.
The main Abliner migration also includes this migration for new deployments.

In Admin → Settings → Payment Settings, set **Fixed withdrawal charge (TZS)**.
Zero means free. The charge must be a whole TZS amount below the minimum withdrawal.
For a TZS 5,000 request with a TZS 500 charge, the wallet hold/debit is TZS 5,000
and the provider sends TZS 4,500. Provider fees remain separate company expenses.
Users see the gross amount, charge and net amount and must explicitly confirm.
A changed admin charge requires the user to reload and confirm before submitting.

The charge is frozen on each withdrawal; future setting changes do not affect
pending requests. Successful transfers deduct the gross amount exactly once.
Failed transfers restore the entire gross hold, including the user charge.
Admin and user history both show the saved charge and net amount.

## Agent accounts and checkbox permissions

After pulling this update, run `php database/migrate_agents.php`. It adds the
agent role, a password-reset session version, and the permission table. Existing
administrators retain full access; regular user accounts are unchanged.

Open **Admin → Agents & Permissions** to create an agent or edit an existing one.
Choose work permissions with checkboxes. Each view permission adds one work
sidebar link; a management permission automatically includes its matching view
permission without adding a duplicate link. Profile, View Site and Logout remain
available to staff. Agents with no work permissions land on a neutral staff page.

Agents log in through `/admin/login` (or the regular login) using their phone or
email and password. The landing page is selected from their actual permissions.
Only administrators can create/edit agents or change system settings. Revocation
and suspension take effect on the next request. Resetting an agent password
invalidates previous agent sessions.

Read-only permissions cannot submit modifications or send payouts. To manually
mark a user paid/unpaid, an agent needs both Manage users and Manage payments.
Company funding requires Fund Abliner wallet; payout dispatch requires Manage
withdrawals. Regular user management cannot change or delete admin/agent accounts.
The server checks permissions on pages and payment APIs independently of sidebar
visibility. Account and permission changes are recorded in activity logs.

Additional offline checks:

```bash
php tests/staff_access_test.php
python tests/check_staff_routes.py /path/to/php
node tests/agent_permissions_test.js
```

The route tests use an isolated SQLite fixture and do not access the real database
or payment provider. After migration, create a support-only test agent on the live
server and confirm the assigned links and ticket replies before distributing
staff credentials.

## Payment lifecycle safety update (25 October 2026)

Run before using the updated withdrawal form:

```sh
php database/migrate_payment_safety.php
```

The migration adds unique withdrawal request tokens and ensures wallet ledger statuses
can store failed/rejected events. It never alters balances or dispatches transfers.
Verification is webhook-driven; remove any old payment cron entry.

Provider-confirmed completed payouts close the held withdrawal exactly once even if
Abliner reports a different positive TZS amount. Requested and reported amounts remain
separate in `payouts.metadata.settlement_review`; a difference is flagged for manual
review and is never silently classified as a fee. The reserved gross wallet debit is
settled once. No additional transfer, refund or fee adjustment is issued automatically.
Deposits still require an exact amount/currency match before activation or commissions.
Malformed amounts, inconsistent cents and wrong currencies remain unverified.

Browser retries reuse a database-unique request token. A new public withdrawal is blocked
while another is unresolved. Existing payout dispatch returns the old payout, and status
checks only query Abliner. Signed duplicate callbacks do not debit wallets again.
Accounts with Abliner financial records cannot be deleted through Users management.

Launch verification still requires production webhook delivery and a real provider
transaction showing requested, received and provider-debited amounts. Offline tests do
not establish provider fee semantics or prove production MySQL concurrency behavior.

## Dashboard and SMS success notifications

Before accepting new payment events after this update, run:

```sh
php database/migrate_notifications.php
```

The migration only creates the notification/outbox table; it sends no SMS and changes
no wallet balances. Verified registration and withdrawal success events enqueue one
notification per unique payment/withdrawal inside the financial transaction. Dashboard
history includes recent older completed transactions without sending historical SMS.

SMS uses the existing Meseji settings, including `meseji_sender_id` from the database.
No sender ID or API key is hardcoded. The global Enable SMS setting and payment-success
SMS toggle remain in effect. Withdrawal messages state wallet debit, system charge,
provider deduction and provider-reported received amount. The payout goes to the
withdrawal number, but the notification SMS goes to the account owner's saved phone.

Dispatch occurs after financial commit on webhook/API responses and withdrawal receipt
requests; dashboard notification fetches also drain queued SMS. No cron is required.
A gateway acceptance is recorded as sent, not proof of handset delivery. Sending is
claimed before calling the gateway. Failed/unknown attempts are not automatically
resent because a timeout may follow a successful send; inspect existing SMS logs before
any manual resend. A process crash after claiming a send similarly needs operator
review. SMS outages never reverse settlement or erase the dashboard notification.

Mark all as read requires an authenticated, CSRF-validated POST and only affects that
user's notifications up to the latest fetched ID. New notifications remain unread.
