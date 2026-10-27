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

Notifications are accessed from the existing dashboard bell popup, with separate News
and Payments categories and a combined unread badge. Clear all hides the user's items
without deleting event/outbox records, so history backfill and duplicate callbacks do
not recreate them or resend SMS. Re-run `php database/migrate_notifications.php` once
for the added `cleared_at` column; the migration is repeatable and sends no messages.


### Welcome audio and YouTube video

In Admin → Settings → Welcome audio / YouTube video, choose Hidden, Uploaded audio, or YouTube video and save. Users see a player on their dashboard and press Play. No database migration is required; settings are created on save. YouTube videos must allow embedding. Audio supports MP3, WAV, OGG and M4A up to 20 MB, subject to PHP upload_max_filesize and post_max_size. The web server must be able to create/write uploads/welcome. Replacing audio uses a new random URL; previous files are retained. Browser sound autoplay is intentionally avoided.


### Social tasks, promotion quotations and loan applications

After pulling this release, run once (repeatable):

```sh
php database/migrate_social_loans.php
```

The migration adds campaigns, one submission per user/task, promotion quotations and loan applications. It extends wallet ledger types and creates notification storage if necessary. It does not send SMS, alter balances, initiate provider payments or issue loans. Upload `database/social_loans.sql` together with the PHP migration.

- **Admin → Social campaigns:** publish an HTTPS YouTube, TikTok, Facebook, Instagram, X or LinkedIn link, a feedback/research question, reward (default TZS 100) and participant cap. Confirm the entire budget is funded. The cap includes approved submissions; unreviewed submissions are not guaranteed a reward. Review answers under Review submissions. Approval credits withdrawable earnings exactly once and notifies the user in the bell. Closed/full tasks cannot issue additional rewards. Tasks use manual review; no platform account verification or automatic subscription/like verification is claimed. Campaigns must comply with each platform's engagement policies.
- **User → Promote account:** request a quotation. Admin chooses price, reward, slots and scope. Price must cover slots × reward: 100 people at TZS 100 requires at least TZS 10,000 before margin. Customer explicitly accepts the latest terms and pays from available wallet earnings; held withdrawals and registration balances cannot fund a promotion. This release does not send a separate mobile-money push for promotion payments. Admin publishes only after payment. Owners cannot reward themselves. Customers can cancel/refund an unpublished paid campaign once; published cancellations require support review. At the last approved slot the promotion is completed. No subscriber, follower or like count is guaranteed.
- **Admin → Loan applications:** publish terms (including costs, repayment period and review steps), min/max amounts, minimum account age and paid direct referrals. Loans are disabled until explicitly enabled. Active users who meet criteria may apply and accept a snapshot of those terms. Only one pending/approved application at a time; staff review and close follow-ups. Approval is a decision only: this module does not disburse loans, calculate interest, collect repayments or change wallet balances. Lending operations require a separate agreed process before money is sent.
- **Agents & Permissions:** Social campaigns and Loan applications are separate checkbox permissions, reflected in the sidebar and enforced on direct routes and writes.

Offline checks: `php tests/social_loans_test.php`, `python tests/check_social_routes.py /path/to/php`, plus existing staff access and payment lifecycle checks. Production migration and external platform account verification are not exercised by the SQLite test fixture.


### Comprehension quizzes with automatic wallet rewards

After pulling this release, run `php database/migrate_quizzes.php` once. It is repeatable and includes the social/reward and notification prerequisites; it sends no money. Include both `database/quizzes.sql` and `database/social_loans.sql` when deploying manually.

Admin → Comprehension quizzes publishes 1–10 questions, four choices per question, one private correct choice, reward amount and maximum paid-user count. An optional HTTPS lesson link supports the six existing social platforms. Confirm the full budget before publishing. Admin → Agents & Permissions → **Comprehension quizzes** grants an agent permission to publish and pause quizzes, including setting rewards and correct answers. It does not grant promotion management or payout permissions.

Users open **Maswali ya uelewa** from their dashboard or account service navigation. Each quiz allows one complete attempt per user. Missing or invalid answers do not consume an attempt. An incorrect complete attempt records the score without payment or retry. A perfect score atomically credits withdrawable `social_reward` earnings, inserts a ledger transaction, increments the paid-user counter and posts a bell notification. The correct-answer key is never sent to the user page. Repeated POSTs return the same result without another credit. When the paid-user cap is reached, the quiz closes; a later first-time submission receives no reward. Pausing blocks new attempts; published questions/answers cannot be edited, so publish a new quiz for corrections.

Offline validation: `php tests/quizzes_test.php` and `python tests/check_quiz_routes.py /path/to/php`. Tests include incorrect/partial answers, repeat submissions, caps, publisher permissions and full rollback after an injected ledger failure. Existing manual-feedback campaigns remain manually reviewed.


### Soko / Buy Goods: paid, moderated product advertising

Run once after pulling the release (repeatable):

```sh
php database/migrate_marketplace.php
```

The migration creates listing, report and moderation-event tables and adds fee/refund ledger types. It includes reward/notification prerequisites but changes no wallet balances and sends no provider requests. Deploy `database/marketplace.sql` and its PHP migration together. The web server must be able to create/write `uploads/marketplace`.

1. **Admin → Marketplace ads → Fees & rules:** set the advertising fee, duration (1–90 days), seller rules, and enable new advertisements. New advertising is disabled by default; the suggested initial values are TZS 1,500 and seven days. Existing drafts retain the fee and duration accepted when created.
2. **User → Sell products:** create a product name, category, whole-TZS price, description/condition, location, Tanzanian WhatsApp contact and 1–4 JPG/PNG/WebP photos. Photos are verified by MIME and dimensions, up to 5 MB each and 6000 × 6000 pixels; PHP hosting upload/post limits also apply. Original client filenames are discarded. Seller accepts publication of the contact and the current rules. The draft remains private. Limit: five unpublished ads and ten new ads per user/day.
3. User explicitly pays the snapshotted advertising fee from available wallet earnings. Registration balances and withdrawal holds are unavailable for this payment. This release does not request a separate mobile-money push for advertising. The fee is debited once, appears in transaction history, and moves the ad to Pending review; it does not publish the product.
4. A moderator reviews photos and full listing details. Approval requires the confirmed fee ledger record and an active seller; it starts the advertising period and makes the ad public. Repeated approval does not extend the duration. Rejection before publication refunds the full fee exactly once. Seller can also cancel an unpublished ad for the same refund. Published ads cannot be edited or automatically refunded; mark sold or contact support for moderation. Published removals keep the original advertising fee.
5. **Soko / Buy Goods** (`/marketplace`) and approved product detail pages (`/product?id=...`) are public. They provide category/search filters, photos, price/location and an encoded WhatsApp chat link. Unpaid, pending, rejected, sold, removed and expired ads are hidden; expiry is checked on every public read without cron. Suspended sellers are hidden automatically. Active users can report another seller's ad; moderators resolve reports or remove published ads. Product price/payment/delivery are agreed directly between buyer and seller; EarnSphere only collects the advertising fee.
6. **Agents & Permissions → Marketplace ads** permits reviewing paid advertisements, rejecting/refunding unpublished ads and resolving reports. A separate **Marketplace fee settings** checkbox permits changing global advertising fees, duration and rules. Unauthorized direct routes and writes are blocked. Moderators cannot approve their own former user listings.

Tests: `php tests/marketplace_test.php`, `python tests/check_marketplace_routes.py /path/to/php`, and `python tests/check_marketplace_upload.py /path/to/php`. They use isolated databases/local multipart requests, remove generated test photos, and never move real money. Financial checks include fee idempotence, protected withdrawal holds, refund idempotence, visibility and ledger-failure rollback.


### Compact dashboard links and removed member loans

The dashboard now uses short, responsive icon links (Quiz, Social Media Tasks, Promote Account) and a compact Soko row. The old long descriptions and platform-link row are removed. Loan links and the member loan form are removed; old `/loans` GET/POST requests redirect to the dashboard. Existing administrative records are retained. No migration is required for this interface update. The dashboard link component was visually checked at 320, 360 and 430 px widths.

### One-time social task links
After pulling this update, run `php database/migrate_social_task_opens.php` once before using Social Tasks. This adds per-user opened tracking and does not change balances. The first successful open records the task before returning its approved platform URL; repeat requests are blocked server-side. Refreshing or switching devices preserves Opened. Existing submissions also show Opened; earlier clicks without a submission were not tracked. Feedback and admin reward approval remain unchanged.

### Know Your Account media library
No migration required. In Admin → Settings → Know Your Account, keep or rename saved items, select multiple MP4/WebM videos or audio files, and add one HTTPS link per line. The existing enabled audio/YouTube item is preserved on the first library save. Maximum: 30 items, 10 new uploads per save, 50 MB per video and 20 MB per audio file, subject to hosting upload/post limits. Use MP4 with browser-compatible video/audio codecs for broad playback support. Removed items are unlisted; their files are retained to avoid interrupting open players.
YouTube uses the standard embed endpoint with the recommended referrer policy. YouTube instruction videos stay in the popup. The inline retry recreates the isolated iframe on the standard YouTube embed host; it never navigates the user away. Embedding restrictions or network blocks still cannot be overridden by the application. On phones, the dialog fills the viewport and uses a tall portrait player. External HTTPS guide links open as links, never arbitrary iframes.

### YouTube embeds with production isolation headers
The production `.htaccess` sends `Cross-Origin-Embedder-Policy: require-corp` and `Cross-Origin-Opener-Policy: same-origin`. Ordinary YouTube iframes are blocked in that environment even when they work on a plain development server. Instruction YouTube iframes now use `credentialless`, which allows compatible browsers to load the player in an ephemeral context without disabling production isolation. Retry clones preserve this attribute. Test media previews with both production headers enabled; a plain static-server preview does not reproduce this restriction. The feature is supported in current Chromium browsers; sign-in-only videos cannot use an anonymous player.

### Home layout and member help
The public home uses a compact hero, Soko discovery, a short getting-started section, recent payment proof and collapsible weekly contest details. Existing live proof and contest APIs still populate their sections. The shared public footer adds Need Help for signed-in user accounts (not guests or staff); it opens a support form and the user's existing replies on any member page, with unread badges and CSRF-protected send/read actions through the existing support API. No migration or payment changes are required.

### Security alerts and referral code lookup
Deploy all files together. No new database migration or cron is needed. PHP must be able to write to the private `logs/` directory for shared login/OTP limits and alert deduplication. Administrators receive a Security Alerts sidebar link/badge, per-admin Mark all as read, and Referral Code Lookup. Normal users and agents cannot open these new pages, even by direct URL. General error-log clearing retains security alerts.

Security email requires `APP_ENV=production` and working email configuration; recipient comes from the existing `admin_email` setting, with the existing administrator notification address as fallback. Email is deduplicated and capped at four attempts per hour; the account shows accepted/failed/grouped status. Acceptance does not prove inbox delivery. Verify the hosting CA trust store and SMTP TLS certificate; insecure TLS fallback is no longer allowed. See `SECURITY_REVIEW.md` for thresholds, test coverage and production checks.

Referral Code Lookup's Level 1 and Level 2 counts open a popup without navigating away from the search. Each member shows name, code, phone, status, join date and direct inviter. Paging and retry stay inside the popup. Lists are scoped to the searched account, paginated at 25 members and remain administrator-only, including the private API. Deploy the page, admin CSS, popup JavaScript and `api/admin_referrals.php` together. No migration is needed.

Admin flash messages already displayed within a page do not generate another empty toast. Pending keyed or legacy messages use a readable text-only toast with a close button; if the toast library is unavailable, they appear as inline text alerts. No migration is needed.

### Agent privacy on Users
View users does not grant financial access. On Users, the Referrals, Earnings and Status columns are administrator-only; agent access to Commissions does not enable these columns. Agents also cannot use the status filter. Referral counts and earnings are not selected for agents; user status is selected internally only when required for explicitly granted management actions. Total Paid requires Dashboard reports; per-user payment amounts/paid styling require View payments. Edit/delete controls require Manage users; activation and mark-paid/unpaid additionally require Manage payments. Administrator access is unchanged. Existing database grants apply immediately on the next request; no migration is needed.
