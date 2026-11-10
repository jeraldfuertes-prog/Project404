# BillGuard Phase 4.2 — Security Question Verification

> **Current flow updated:** Recovery now starts with a **registered email address**, sends a six-digit recovery code to eligible verified accounts, then asks for the code and one of the three enrolled security questions. This older phase guide documents historical behavior; use [Email-First Recovery](EMAIL_FIRST_RECOVERY.md) for current testing. Password changes are not yet enabled.

**Status:** Implemented in GitHub; local Chrome testing remains to be completed.
**Scope:** Security questions only. Reset-email OTP and changing passwords are **not enabled** until Phases 4.3–4.4.

## What's implemented

- After the username and CAPTCHA step at forgot-password.php, the user is sent to recovery-question.php.
- Three seed-selected security questions are shown. These are the same questions that were asked at registration.
- The form asks for an answer and a confirmation. Answers use the original registration rules and are compared with the stored password hashes.
- Incorrect answers for an eligible account increment the **same** account failure counter used by login. Account lockouts at 3, 6 and 9 failed attempts, IP blocks, and tier alert emails are enforced.
- A user with an active account lockout or IP block cannot verify an answer.
- For privacy, **correct, incorrect, unknown-user and blocked-account submissions show the same result**. The site deliberately does not say that an answer was correct. A successful answer only creates an expiring server-side status for the future OTP stage.
- No password is changed and no password-reset OTP is sent by Phase 4.2.

## Update and test locally

From the VS Code PowerShell terminal:

~~~powershell
cd C:\Users\Administrator\Desktop\Project404
git pull origin main
& "D:\php83\php.exe" backend\tests\recovery_entry_rules_test.php
& "D:\php83\php.exe" backend\tests\recovery_question_rules_test.php
~~~

Expected: both tests end in zero failures. **Do not run the CI-only MySQL integration suite locally.**

Start XAMPP MySQL, then if necessary restart the PHP server:

~~~powershell
& "D:\php83\php.exe" -S localhost:8000 -t frontend
~~~

There is **no new SQL database migration** for Phase 4.2.

## Step-by-step Chrome checks

1. Open http://localhost:8000/login.php in a private browser window and click **Forgot Password?**.
2. Enter a separate **verified test-account username**, complete the CAPTCHA and submit. The browser should advance to http://localhost:8000/recovery-question.php.
3. Confirm the dropdown offers **exactly the three questions** asked during registration, with no fourth choice.
4. Choose one known question; enter the registered answer twice, using different capitalization if desired. Submit. You should see a **generic submission confirmation** stating that email reset verification is not available yet. This is intentional and does not reveal whether the answer was correct.
5. Open recovery-question.php in a **fresh private window** without starting recovery. It must redirect to forgot-password.php.
6. Start another recovery request using a plausible **nonexistent username**, fresh CAPTCHA and answers. The **question options and final visible confirmation** must not reveal account existence.
7. Using the separate test account (NOT your main account), start a new request and enter **one deliberately incorrect answer**, repeated in both fields. The same generic confirmation should appear. Do not repeatedly lock your main account.
8. In phpMyAdmin, select billguard_dev and run this read-only query:

~~~sql
SELECT event_type, created_at
FROM activity_log
WHERE event_type IN (
 'RECOVERY_ENTRY_REQUESTED',
 'RECOVERY_QUESTION_VERIFIED',
 'RECOVERY_ANSWER_FAILED',
 'LOCKOUT_TIER_1',
 'ALERT_EMAIL_SENT'
)
ORDER BY id DESC LIMIT 20;
~~~

Expected: correct verified answers log RECOVERY_QUESTION_VERIFIED, wrong answers log RECOVERY_ANSWER_FAILED; these records do not contain answer values.

9. Log in again normally using the **original password**, fresh CAPTCHA, and email OTP. The dashboard must still work. No password changes occurred.

## Automated CI coverage

GitHub Actions runs PHP and JavaScript syntax checks, existing project tests, the Phase 4.2 rule suite, and a special MySQL integration test using fictional accounts. It tests correct and wrong answers, unknown accounts, shared Tier 1 lockout, blocked IP, disabled accounts, no reset OTP, and unchanged passwords. This CI-only test deliberately writes synthetic data to a disposable database; do not run it on your PC's real database.

## Next steps

Phase 4.3 will issue and verify reset email OTP only after the server-side security answer check. Phase 4.4 will enforce new-password strength, prior password history and a success email. Phase 4.5 will implement the idle session warning and session revocation after password reset.

Do not share passwords, OTP codes, security answers, cookies, account numbers or private database backups when reporting errors.
