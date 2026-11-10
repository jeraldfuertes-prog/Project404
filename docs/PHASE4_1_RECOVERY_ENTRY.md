# BillGuard Phase 4.1 — Account Recovery Entry

> **Update for Phase 4.2:** The question-verification page is now active. After a valid username/CAPTCHA submission, BillGuard redirects to recovery-question.php instead of remaining on the entry confirmation. Correct, wrong, and unknown-account answers receive the same generic visible result. **No reset OTP or password change is active yet.** Follow the newer [Phase 4.2 guide](PHASE4_2_SECURITY_QUESTIONS.md) for current Chrome testing steps.

**Scope:** Implemented in GitHub source. **Local Chrome testing remains pending.**
Only the username/CAPTCHA entry step is active. Security-question verification,
reset email OTP, changing passwords and session-warning UI are planned for
Phases 4.2–4.5 and are **not yet functional**.

## What Phase 4.1 does

- A "Start password recovery" link appears on the Login page when the existing
  seeded failed-login threshold displays recovery guidance.
- The public URL is **http://localhost:8000/forgot-password.php**.
- Users enter their username and solve the **same** six-character GD CAPTCHA
  already used by Login. PHP verifies the CAPTCHA and consumes its answer.
- Usernames are checked against the approved seed length and format.
- A server-only candidate ID is staged for **10 minutes**, but **no identity has
  been established** and no permission to change a password has been given.
- Active verified users with three enrolled questions are eligible to proceed
  once Phase 4.2 is implemented. Unknown, disabled, locked and IP-blocked
  accounts receive the **same visible confirmation**.
- Public recovery requests are rate-limited to **eight per IP per 15 minutes**,
  require CAPTCHA and appear in the activity log without storing supplied
  usernames. Login lockout rules remain unchanged.
- The confirmation explicitly says **no OTP was sent and no password was
  changed**. No email or reset action is performed in Phase 4.1.

## How to test on Windows

1. Open VS Code terminal, in the Project404 folder. Update from GitHub:

~~~powershell
cd C:\Users\Administrator\Desktop\Project404
git pull origin main
~~~

2. Run the new password recovery entry rule tests, plus the existing preflight:

~~~powershell
& "D:\php83\php.exe" backend\tests\recovery_entry_rules_test.php
& "D:\php83\php.exe" backend\scripts\check_phase3.php
~~~

Expect: all recovery rule checks show **OK**, zero failed; Phase 3
preflight checks remain ready. The GitHub workflow separately runs MySQL
integration tests with fictional users. **Do not run** its
recovery_entry_integration_test.php against your real local database.

3. Start XAMPP MySQL. If your development server is already running, press
   Ctrl+C in its terminal first. Restart:

~~~powershell
& "D:\php83\php.exe" -S localhost:8000 -t frontend
~~~

4. In a private Chrome window, open:
   **http://localhost:8000/forgot-password.php**.
   Expected: Cyberpunk "Forgot Password?" page, username, six-character
   CAPTCHA and "Start Recovery" button.

5. Enter a valid-length **fictional** username but deliberately enter the
   wrong CAPTCHA. Submit. Expected:
   **CAPTCHA is incorrect or expired**. The previous CAPTCHA cannot be
   reused; refresh the page or image to get a new one.

6. Enter a plausible nonexistent username (use letters/digits only,
   beginning with a letter, within your local seeded length) and correctly
   solve a **fresh** CAPTCHA. Submit. Expected: a generic recovery request
   confirmation **without revealing whether the username exists**. It
   explicitly says the question step is not yet available, no reset email
   was sent, and no password was changed.

7. Repeat using your **verified test account**, with a fresh CAPTCHA.
   Expected: **exactly the same public confirmation** as the unknown
   username case. Do not use your main account for intentional lockout tests.
   No password change or email should occur.

8. Enter an invalid username (e.g. starts with a digit, contains a space).
   Expected: username validation message, with **no account lookup**.
   A valid and an invalid username may receive different *format* feedback;
   actual account existence must never be disclosed.

9. Optionally confirm the link on the Login page: using a dedicated verified
   test account, the recovery link appears when the seeded failed-login
   threshold is reached. **Avoid triggering a lockout of your main account.**
   During an existing account lockout, a recovery attempt must not bypass it.

10. To inspect safe evidence, in phpMyAdmin select **billguard_dev**, go to SQL,
    and run this read-only query:

~~~sql
SELECT event_type, created_at
FROM activity_log
WHERE event_type IN (
  'RECOVERY_ENTRY_REQUESTED',
  'CAPTCHA_FAILED',
  'CSRF_REJECTED'
)
ORDER BY id DESC
LIMIT 15;
~~~

Expected: **RECOVERY_ENTRY_REQUESTED** after a correctly solved CAPTCHA,
and **CAPTCHA_FAILED** after a wrong code. Never post private account details,
passwords, OTP codes, session cookies, or raw database backups.

## Safety checkpoints

- No migrations or database schema changes are required in Phase 4.1.
- No emails are sent by the new recovery page.
- No reset OTP is generated and no password history is modified.
- The session stores only a short-lived, server-side candidate for **future**
  Phase 4.2 checks. Neither a valid username nor a valid CAPTCHA alone is
  proof that the requester owns an account.
- Phase 4.2 must **re-check active status, account and IP lockouts**, display
  only the account's selected three security questions, and use a safe dummy
  flow for nonexistent accounts. It must not reveal account existence.
- Database-backed tests use a **disposable GitHub Actions MySQL service** and
  test identities; they are not run on your PC's billguard_dev.

## If a test fails

Share the step number, the expected vs actual message, and a redacted
screenshot. Do **not** send the private backend/config.php or a password.
