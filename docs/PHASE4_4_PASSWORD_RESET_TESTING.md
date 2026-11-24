# BillGuard Phase 4.4 — Change Password and Password History

## Implementation

The email-first recovery flow is now:

1. Start on login.php -> **Forgot Password?**.
2. Enter registered email and CAPTCHA at forgot-password.php.
3. If eligible, a six-digit, hashed reset-purpose code is emailed.
4. On recovery-question.php, enter that code, choose one of the three enrolled
   security questions, and enter the answer twice.
5. After submitting, click **Continue to Change Password**. Both successful and
   unsuccessful verification submissions have the same visible continuation.
   The server authorizes the reset ONLY when both proofs were correct.
6. The change-password.php form checks the **original seed-derived minimum**
   and the registration strength rule. It rejects the current password and
   the last three previously used passwords.
7. On success, BillGuard stores the old hash in password_history, retains
   exactly the last three previous hashes, updates the password in one
   transaction, cancels pending login/reset OTPs, logs PASSWORD_RESET,
   and sends a change-notification email. SMTP notification failure must not
   roll back a committed password change.
8. The user returns to Login. Other authenticated sessions carry a version
   fingerprint of their original password hash and are rejected on their
   next protected-page request.

### Important migration note

**NO database migration is required.** The existing password_history table
from backend/database/schema.sql is reused. Session revocation uses the
stored password hash as the version. Existing logins made before this upgrade
do not have the required fingerprint; they will need to sign in again.
Do **not** import the schema into an existing database.

### Security boundary

- Only an active, email-verified, unlocked account from a verified two-proof
  server-side recovery session can change its password.
- The reset proof is bound to the most recently consumed reset OTP ID, the
  original password fingerprint, and a ten-minute recovery session lifetime.
- Direct URL navigation, expired flow, wrong code, incorrect question, account
  lockout, IP block, changed password or reused OTP cannot authorize reset.
- No password, OTP, or question answer is ever included in activity logs.
- Passwords are never stored in plaintext. Historical password comparisons
  use password_verify() against current/previous hashes.
- Recovery does **not** grant a dashboard login.
- The old password can no longer be used after a successful reset.

## Local update: Windows + VS Code

Make a normal database backup **before testing password changes**, because
the test will legitimately update the chosen account's password.

In VS Code PowerShell:

~~~powershell
cd C:\Users\Administrator\Desktop\Project404
git pull origin main
& "D:\php83\php.exe" backend\tests\recovery_reset_rules_test.php
& "D:\php83\php.exe" backend\tests\recovery_email_rules_test.php
& "D:\php83\php.exe" backend\scripts\check_phase3.php
~~~

Expected: rules tests show **0 failed** and preflight is ready.

Keep XAMPP MySQL running. If the PHP server is stopped:

~~~powershell
& "D:\php83\php.exe" -S localhost:8000 -t frontend
~~~

If an earlier PHP server is running, stop it with Ctrl+C before restarting.
Do not publish the server or expose the backend directory.

### Browser tests — use a separate test account and working test inbox

1. Open http://localhost:8000/change-password.php **in a fresh Incognito
   window**. Expected: redirected to forgot-password.php (no direct URL bypass).
2. At http://localhost:8000/login.php, click **Forgot Password?**.
3. Enter the test account's **registered email**, solve CAPTCHA, and submit.
   Expected: recovery-question.php opens. Receive a six-digit code in the
   test inbox. Never share the code.
4. Select the original enrolled security question. Enter its answer twice
   and the emailed code, then click **Verify Security Answer**.
5. Click **Continue to Change Password**.
6. Enter the test account's **current password** as the new password and
   matching confirmation. Expected: reuse rejected without changing password.
7. Try a short/Weak password. Expected: policy rejection. Try mismatched
   confirmation; expected: a clear validation error.
8. Enter a genuinely **new, strong password** matching your seeded minimum.
   Submit. Expected: redirect to login.php with a password-changed notice.
9. Check your test inbox for a **password-change notification** (different
   from the OTP email). No password should appear in that message.
10. Try logging in using the **old password**. Expected: rejected.
    Then use your new password, CAPTCHA and login OTP to open the dashboard.
11. After successful change, try opening change-password.php again or
    using the old recovery code. Expected: denied; a new recovery is required.
12. Optional session test: before resetting, log in on another browser
    profile and leave the dashboard open. After resetting, refresh that
    dashboard. Expected: redirect to Login; no private data remains visible.
13. Optional history test: after additional valid resets, try using any of
    the last three previous passwords. Expected: rejected. Use only the test
    account, as repeated recovery email sends are rate-limited.

Read-only audit query in phpMyAdmin / billguard_dev / SQL:

~~~sql
SELECT event_type, created_at
FROM activity_log
WHERE event_type IN (
    'RECOVERY_ENTRY_REQUESTED',
    'RECOVERY_QUESTION_VERIFIED',
    'RECOVERY_CODE_VERIFIED',
    'PASSWORD_RESET',
    'OTP_FAILED',
    'ALERT_EMAIL_SENT'
)
ORDER BY id DESC
LIMIT 30;
~~~

Expected: a successful password reset logs PASSWORD_RESET exactly once
for that transaction. Do not copy raw password_history hashes.

## Automated security tests

GitHub Actions runs:

- PHP and JavaScript syntax checks.
- recovery_reset_rules_test.php: proof/session validation, password strength,
  current/last-three password reuse, fingerprint behavior.
- recovery_reset_integration_test.php: disposable CI MySQL transactions
  covering real password_hash updates, old password rejection,
  history pruning, OTP cancellation, account/IP lockouts, replay rejection,
  success despite a deliberately unavailable notification SMTP server.
- All previously installed registration, billing, login/OTP and recovery tests.

**Never run recovery_reset_integration_test.php on the local real database.**
It is guarded by BG_RECOVERY_RESET_CI=yes and inserts synthetic accounts.
The local rules tests are safe and do not write to your account database.

Phase 4.5 (one-minute session expiry warning) is still pending. The
seeded backend idle logout remains active.
