# BillGuard — Protection #9 Activity Logging (Security 1 / Section 13.4)

**Status:** Existing-route activity logging and initial admin page/feed
authorization logging are implemented. GitHub CI and local Chrome checks must
be verified before final sign-off. This is not yet the complete Phase 6
security monitoring dashboard.

## Purpose and audit schema

Activity events are inserted into the existing `activity_log` table with
prepared statements in `backend/php/security.php`. Each entry records:

- event_type: non-secret uppercase event name
- username_typed: optional username typed during a login attempt (NOT a password)
- user_id: optional verified account identifier where appropriate
- ip_address: source IP (not trusted for authentication by itself)
- user_agent: limited browser/device identifier
- details: **non-sensitive** metadata only (JSON)
- created_at: database timestamp in Philippine time (the app configures +08:00)

`bg_log()` is the shared insert-only writer. It rejects metadata with
secret-related keys (password, OTP, reset token, security answer, cookie,
encryption key, ciphertext, Utility Account Number), nested values, control
characters or oversized strings. **Do not add passwords, OTP values,
security answers, keys, session tokens, account numbers, or raw request bodies
to a log.** Logs are write-once by the application's implemented SQL routes;
this does not prevent a MySQL database administrator from altering tables.

## Active event coverage

| Security action | Event(s) |
| --- | --- |
| Registration submitted successfully | REGISTERED |
| Registration verified through email | ACCOUNT_VERIFIED |
| Verification code sent / failed | OTP_SENT / OTP_FAILED |
| CAPTCHA failed | CAPTCHA_FAILED |
| Registration honeypot filled | HONEYPOT_TRIGGERED |
| Login password rejected | LOGIN_FAILED |
| Login completed after OTP | LOGIN_SUCCESS |
| Progressive login lockout | LOCKOUT_TIER_1 / LOCKOUT_TIER_2 / LOCKOUT_TIER_3 |
| Seeded IP attack threshold reached | IP_BLOCKED |
| Lockout alert delivered | ALERT_EMAIL_SENT |
| Form CSRF rejected | CSRF_REJECTED |
| Password reset requested via email-first recovery | RESET_REQUESTED (also legacy RECOVERY_ENTRY_REQUESTED) |
| Wrong security answer | RECOVERY_ANSWER_FAILED |
| Correct recovery question / code | RECOVERY_QUESTION_VERIFIED / RECOVERY_CODE_VERIFIED |
| Password changed after both recovery proofs | PASSWORD_CHANGED (also legacy PASSWORD_RESET) |
| Explicit authenticated logout | LOGOUT |
| Session timed out | SESSION_EXPIRED |
| Old session rejected after password reset | SESSION_REVOKED |
| Encrypted utility number stored / revealed / removed | UTILITY_ACCOUNT_SAVED / UTILITY_ACCOUNT_REVEALED / UTILITY_ACCOUNT_REMOVED |
| User profile, bill or payment changes | PROFILE_UPDATED, BILL_* and PAYMENT_RECORDED |

**Important privacy behavior:** RESET_REQUESTED is generated for both known
and unknown email addresses, with NULL user_id and username_typed. It must
NOT disclose which account exists or whether SMTP sent a reset message.
PASSWORD_CHANGED is recorded **only** in the successful password-reset DB
transaction; failed resets do not emit it. The older recovery event labels
are retained for existing rate-limit queries and historical audit consumers.

**Admin activity audit is now implemented:** A minimal read-only
`admin-security.php` page and `admin-security-data.php` JSON feed have
independent authorization checks through the existing authenticated session,
password fingerprint, server-side expiry, and database role. Access to both
generates `ADMIN_VIEWED_DASHBOARD` for authenticated admins and
`ADMIN_ACCESS_DENIED` for anonymous or non-admin requests. The page only
shows event type, supplied username, IP and PHT time, with HTML escaping;
the feed returns these fields in JSON without audit metadata. Both use the
existing activity log and never expose passwords, cookies, OTPs or account
number ciphertext. **The full Phase 6 cards, graphs, filtering, flags, and
CLI monitor are separate future tasks.**

## Windows testing

### Step 1 — Pull and run unit tests

Open VS Code PowerShell:

~~~powershell
cd C:\Users\Administrator\Desktop\Project404
git status --short
git pull origin main
& "D:\php83\php.exe" backend\tests\activity_log_rules_test.php
& "D:\php83\php.exe" backend\scripts\check_activity_log.php
~~~

Expected: `0 failed`, `Activity log schema: OK`,
and `Activity logging setup: READY`. The CLI script prints **only event
types and counts**, never usernames, emails, passwords, or IP addresses.

**Important:** The synthetic MySQL integration tests only run inside
GitHub Actions, not on your real local database.

### Step 2 — Verify login and logout events

1. Log in at http://localhost:8000/login.php with your existing test user,
   complete CAPTCHA and email OTP, and then press **Logout**.
2. In phpMyAdmin select `billguard_dev` > SQL and run:

~~~sql
SELECT event_type, created_at
FROM activity_log
WHERE event_type IN ('LOGIN_SUCCESS','LOGOUT')
ORDER BY id DESC
LIMIT 20;
~~~

Expected: `LOGIN_SUCCESS` followed by `LOGOUT` for the new session.
Do not show full account identifiers, IP addresses or cookies in screenshots.

### Step 3 — Verify reset-request logging without account enumeration

1. Open Forgot Password and submit a validly formatted **fictional,
   unregistered** email address with the required CAPTCHA.
2. phpMyAdmin query:

~~~sql
SELECT event_type, created_at
FROM activity_log
WHERE event_type IN ('RESET_REQUESTED','RECOVERY_ENTRY_REQUESTED')
ORDER BY id DESC
LIMIT 20;
~~~

Expected: RESET_REQUESTED (and the compatible legacy event). The event
does **not** contain the submitted email. Test a registered inbox only if
you need to perform a real recovery demonstration; do not flood mail or
trigger lockouts.

### Step 4 — Verify password-change logging

After a **successful** email-code + security-question + password-reset
transaction, run:

~~~sql
SELECT event_type, created_at
FROM activity_log
WHERE event_type IN ('PASSWORD_CHANGED','PASSWORD_RESET')
ORDER BY id DESC
LIMIT 20;
~~~

Expected: a PASSWORD_CHANGED event for each successful password change
and **no new** PASSWORD_CHANGED for a rejected/reused password attempt.
Never reveal passwords or recovery codes in screenshots.

### Step 5 — Test denied actions and session expiration

- Try a bad password on a test account: LOGIN_FAILED.
- Wrong OTP on a test flow: OTP_FAILED.
- Invalid CSRF on a protected form, e.g. profile: CSRF_REJECTED.
- Fill the hidden registration honeypot during an approved test:
  HONEYPOT_TRIGGERED; the account must not be created.
- Let a signed-in test session idle until the configured 15-minute
  timeout: SESSION_EXPIRED. An old session after a password reset should
  produce SESSION_REVOKED when it attempts a protected request.
- The browser must still block unauthorized access. Logging does not
  grant permission, renew sessions, or weaken security checks.

Query each event's **name and date only** using:

~~~sql
SELECT event_type, created_at
FROM activity_log
WHERE event_type IN
  ('LOGIN_FAILED','OTP_FAILED','CSRF_REJECTED','HONEYPOT_TRIGGERED',
   'SESSION_EXPIRED','SESSION_REVOKED')
ORDER BY id DESC LIMIT 30;
~~~

### Step 6 — Verify audit metadata does not expose secrets

Review only metadata **field names**, not their values:

~~~sql
SELECT event_type, JSON_KEYS(details) AS metadata_fields
FROM activity_log
WHERE details IS NOT NULL
ORDER BY id DESC LIMIT 30;
~~~

Expected: descriptive safe keys like `page`, `method`, `reason`,
`purpose`, `attempt`, `tier`, `rate_limited`. No password, OTP,
security answer, key, cookie, ciphertext or full utility account number
field names. GitHub CI also tests rejected sensitive metadata directly.

### Step 7 — Verify admin-only access and admin audit events

Admin roles **must never be offered in open registration**. For a local
instructor demonstration, use a **separate, existing verified test account**
and grant its role **only in phpMyAdmin**, after confirming which test
username you intend to use. This is an explicit administrator operation,
not a public registration option:

~~~sql
UPDATE users
SET role = 'admin'
WHERE username = 'YOUR_SEPARATE_VERIFIED_TEST_USERNAME'
  AND account_status = 'active'
  AND email_verified_at IS NOT NULL;
~~~

Confirm one row was affected and the selected account belongs to you.
**Never use this query with an unknown or production account.** For the
handout's final admin-account deliverable, maintain a dedicated account
provisioned administratively (not through a public admin signup).

1. Sign in as an **ordinary** household user with OTP and open
   http://localhost:8000/admin-security.php; expect **403 Access denied**.
2. While still signed in, open
   http://localhost:8000/admin-security-data.php; expect **403** and
   JSON `{"ok":false}`, not any log records.
3. Sign out; repeat both addresses without an authenticated session.
   Both must deny access.
4. Sign in as the separate admin test account with OTP. The sidebar now
   offers **Security Activity**. The page should list at most 50 recent
   events, read-only, including timestamps in Philippine time.
5. Open the admin JSON endpoint while signed in as admin. It should
   return `{"ok":true,"events":[...]}` with event metadata but no secrets.
6. In phpMyAdmin select `billguard_dev` and execute:

~~~sql
SELECT event_type, created_at,
       JSON_UNQUOTE(JSON_EXTRACT(details, '$.resource')) AS resource
FROM activity_log
WHERE event_type IN ('ADMIN_VIEWED_DASHBOARD', 'ADMIN_ACCESS_DENIED')
ORDER BY id DESC
LIMIT 30;
~~~

Expect `ADMIN_ACCESS_DENIED` for unauthorized page/feed requests and
`ADMIN_VIEWED_DASHBOARD` for successful admin page/feed visits.
Each should include `resource = page` or `resource = feed` and should
not contain passwords or cookies. Do not share complete IPs/usernames
in screenshots.

**Never manually INSERT audit events to make the screenshots appear**.
Generate them through real browser requests. If you do not have a verified
admin test account, leave this step as pending instead of bypassing security.

## Evidence to submit

1. Screenshot: CLI showing activity log schema READY and event counts.
2. Screenshot: phpMyAdmin list showing recent LOGIN_SUCCESS + LOGOUT
   (event names and dates only).
3. Screenshot: phpMyAdmin showing RESET_REQUESTED + PASSWORD_CHANGED
   (after a successful reset test).
4. Screenshot: denied CSRF, failed login, or SESSION_EXPIRED logs.
5. Admin test: 403 for ordinary users and anonymous visitors, admin-only
   Security Activity page, and both admin-view and access-denied log events.

## Next work

Protection #9 logging and admin access events are **implemented**.
Protection #9 remains pending **owner local browser verification** of normal
logging and both admin/denied paths. The complete Phase 6 monitoring dashboard
and scheduled monitoring script remain separate projects. The 15-minute
session timeout is unchanged.
