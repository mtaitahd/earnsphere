# Payment inspection — 25 October 2026

## Findings addressed

- Completed payouts reporting a different valid TZS amount previously stayed pending.
  A verified completed transfer now consumes the reserved gross wallet amount exactly
  once. The requested and provider-reported amounts and their difference are retained
  separately and displayed for review. No fee is inferred, no difference is resent,
  and no automatic refund is created. Deposit activation still requires exact payment.
- Duplicate form submissions could create separate withdrawal requests. A session-issued,
  database-unique request key now identifies the original request across retries and
  page resubmissions. Another public request is blocked while a withdrawal is unresolved.
- A timeout followed by a reference-only payout callback could not find the local row.
  Callback matching now supports the exact unique outbound payout reference, with
  type, provider identity, recipient (when supplied), numeric amount and currency checks.
- Transient/retryable provider errors and HTTP 408 retain the hold instead of making
  the balance available for another transfer.
- Wallet ledger status enums on an older install could reject failed/rejected statuses.
  The repeatable safety migration adds them and the unique request-key column/index.
- Withdrawal result pages could access absent form elements and throw a browser error.
  Form listeners are now conditional. Status checks navigate using GET, avoiding POST
  resubmission prompts; limited read-only polling updates visible withdrawal history.
- User deletion could cascade away Abliner records needed for financial reconciliation.
  Such accounts now retain their records and should be suspended instead.
- Receipts described the requested amount as guaranteed received money. The UI now
  distinguishes the requested payout and the provider-reported amount when different.

## Verification

Offline simulated HTTP/SQLite tests cover collection activation, two-level commissions,
wrong/duplicate callbacks, provider signatures, atomic rollback, payout holds, completed
and failed transfers, exact and net-reported amounts, duplicate browser submissions,
timeouts, reference-only recovery, malformed values, currency/cents inconsistencies,
recipient validation and endpoint authorization. PHP syntax and browser-script checks
are run separately. No live funds are moved by these tests or the reconciliation job.

## Production status and limits

The reported live payout #169 has a matched, completed TZS provider transaction with
requested 16000 and reported 15360. After this update and migration, reconciliation can
close the existing payout and settle its hold without dispatching another transfer.
The 640 difference remains an amount discrepancy, not an established provider fee.
The business still needs Abliner to confirm gross/net/fee semantics and the amount
actually received. Provider credentials and production data have not been accessed
from this development workspace.

Production webhook delivery, the minute reconciliation cron, provider funding and MySQL
concurrency behavior require hosting verification. No audit can establish absence of all
bugs or certify launch readiness solely from offline tests. Inspect the live verbose
reconciliation output and a verified collection/withdrawal before opening broadly.

## Deployment

```sh
git pull --ff-only origin main
php database/migrate_payment_safety.php
php cron/verify-pending-payments.php --verbose
```

Run the migration before accepting new withdrawals. It adds schema support without
changing balances. Reconciliation reads existing transactions, records their verified
outcome and never invokes a transfer POST. Do not create a replacement for payout #169.
