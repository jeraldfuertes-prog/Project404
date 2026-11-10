# BillGuard — Email-First Forgot Password and Security Questions

## Current implementation

**Scope:** Email-first recovery entry, six-digit reset email OTP, and verification of one of the three registered security questions. **Changing passwords is not enabled yet**; that remains Phase 4.4.

Flow:

1. On login.php, click **Forgot Password?**.
2. At forgot-password.php, enter the account's **registered email address** (not its username) and solve CAPTCHA.
3. For eligible, active and verified accounts, BillGuard **immediately emails a six-digit recovery code**. Ineligible/unknown addresses receive the same public response, without confirmation of registration.
4. In recovery-question.php, enter that emailed code, select one of the **three original registration security questions**, then enter and confirm the security answer.
5. Both the correct security answer and valid reset OTP are required. For privacy, the visible submission result does not say whether either proof matched. **No password is changed yet.**
6. Codes have seed-based expiry, a 60-second resend cooldown, four sends per 15 minutes, three wrong attempts, replay prevention, and separate reset-purpose hashes. Incorrect answers count toward existing account and IP lockouts.

The security questions are displayed on the website, **not included in the email**. Only the one-time recovery code is emailed.

### Security characteristics

- CAPTCHA and CSRF protect the entry and verification POSTs.
- Request limit: 8 recovery entries per IP per 15 minutes; existing IP blocks apply.
- All sensitive hashes and candidate user IDs stay on the server; accounts' email addresses and answer text are never disclosed in public confirmation or logs.
- Email codes are hashed, time-limited, single-use, and bound to reset purpose in the existing one_time_passwords table.
- A reset code cannot be used to log in. The login OTP purpose remains separate.
- Login 3/6/9 account lockouts and tier alert notifications apply to incorrect eligible security answers.
- A blocked IP, disabled account or locked account cannot bypass verification, even with a correct code and answer.
- No new SQL migration is required; current tables already support reset OTP and security answers.

## Update your Windows local Project404

Open VS Code PowerShell:

~~~powershell
cd C:\Users\Administrator\Desktop\Project404
git pull origin main
& "D:\php83\php.exe" backend\tests\recovery_email_rules_test.php
& "D:\php83\php.exe" backend\tests\recovery_question_rules_test.php
& "D:\php83\php.exe" backend\scripts\check_phase3.php
~~~

Expected: zero failed tests and a working SMTP/CAPTCHA setup.

Make sure XAMPP MySQL is running. If your PHP development server is stopped, start it:

~~~powershell
& "D:\php83\php.exe" -S localhost:8000 -t frontend
~~~

## Test the workflow in Chrome (use a separate verified test account)

1. Visit http://localhost:8000/login.php and click **Forgot Password?**.
2. Confirm the recovery form asks for **Registered Email Address** rather than Username.
3. Enter your verified **test account's email**, solve a fresh CAPTCHA, and submit.
4. Expected: BillGuard opens http://localhost:8000/recovery-question.php. Check the test email inbox (and Spam/Junk). A **six-digit recovery code** should arrive. Do not share it.
5. Confirm the dropdown contains exactly your three registered questions.
6. Select one of those questions, enter the registered answer twice, and enter the received six-digit code.
7. Submit. Expected: a **generic verification-result notice**. This is intentional and doesn't reveal account correctness. It must clearly state that no password was changed.
8. Test an unknown but validly formatted email with a fresh CAPTCHA. Expected: the **same public confirmation and same question choices**, but no account information is disclosed and no email is sent.
9. Verify a wrong CAPTCHA is rejected. Do not repeatedly guess real security answers on your main account, since wrong answers contribute to login lockouts.
10. Confirm your **original password still works** with login CAPTCHA and login OTP. The reset-code step is not the actual password-change operation.
11. Optionally test **Resend Recovery Code** after the 60-second cooldown. An earlier code must no longer work after a new one is sent.

### Read-only audit query

In phpMyAdmin choose the database billguard_dev and SQL:

~~~sql
SELECT event_type, created_at
FROM activity_log
WHERE event_type IN (
  'RECOVERY_ENTRY_REQUESTED',
  'RECOVERY_QUESTION_VERIFIED',
  'RECOVERY_CODE_VERIFIED',
  'RECOVERY_ANSWER_FAILED',
  'OTP_FAILED',
  'OTP_SENT'
)
ORDER BY id DESC
LIMIT 30;
~~~

Never share your security answers, OTP digits, password, session cookie, private configuration or raw database dump.

## Automation and limits

GitHub Actions runs PHP/JS syntax and unit tests plus an isolated disposable MySQL suite with **fictional** accounts. It verifies generic responses, SMTP failure rollback, reset-code expiry, replay protection, three wrong-code cancellation, lockout/IP protection and unchanged passwords. **Do not run integration tests against your real local database.**

Real Gmail delivery cannot be confirmed by CI because the CI config deliberately has no working SMTP credentials; verify receipt using your separate test email address in Chrome.

**Next:** Phase 4.4 implements actual password updating, password-history checks and prior-session invalidation. Until then, the emailed code and question verification do not reset or recover a password.
