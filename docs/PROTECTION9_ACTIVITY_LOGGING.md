# BillGuard — Protection #9 Activity Logging (Security 1 / Section 13.4)

**Status:** Implemented and CI-tested for existing public and authenticated
routes; **local Chrome evidence and Phase 6 admin access events are still
pending**. This is NOT a claim that the complete Section 13.4 / monitoring
dashboard is finished.

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

**Pending Phase 6:** The handout's ADMIN_VIEWED_DASHBOARD and
ADMIN_ACCESS_DENIED events require real authorization checks on the
admin-only monitoring dashboard and its JSON endpoint. Since Phase 6 has
not built these endpoints yet, these admin event requirements are **not
implemented**. They must be added and tested before Protection #9 is
marked fully complete.

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

## Evidence to submit

1. Screenshot: CLI showing activity log schema READY and event counts.
2. Screenshot: phpMyAdmin list showing recent LOGIN_SUCCESS + LOGOUT
   (event names and dates only).
3. Screenshot: phpMyAdmin showing RESET_REQUESTED + PASSWORD_CHANGED
   (after a successful reset test).
4. Screenshot: denied CSRF, failed login, or SESSION_EXPIRED logs.
5. Later Phase 6: admin dashboard access and rejection events.

## Next work

Protection #9 remains **IN PROGRESS** until local activity logging tests
pass and Phase 6 implements and verifies ADMIN_VIEWED_DASHBOARD and
ADMIN_ACCESS_DENIED for both admin pages and their JSON data endpoint.
The user-requested 15-minute session setting is not changed by this work.
