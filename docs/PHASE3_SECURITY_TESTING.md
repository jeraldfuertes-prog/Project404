# BillGuard Phase 3 — Login, OTP, Lockouts and Alerts: Testing Guide

**Testing status:** PHP/JS lint, seeded rule tests, and a separate MySQL integration suite run in GitHub Actions. This does **not** prove real Gmail alert delivery or local Chrome behavior. Perform the manual checks below on a separately registered and email-verified **test account**.

## A. Update and preflight (safe, read-only)

In VS Code PowerShell:

~~~powershell
cd C:\Users\Administrator\Desktop\Project404
git pull origin main
& "D:\php83\php.exe" backend\scripts\check_phase3.php
& "D:\php83\php.exe" backend\tests\auth_rules_test.php
~~~

Expected: preflight items and unit tests show OK; the preflight prints your local **seed-derived** Tier 1, 2 and 3 lockout durations, 10-minute shared-IP threshold, OTP expiry, and idle timeout. It prints no personal seed digits, account credentials or email addresses.

Keep your local MySQL running. Stop any current PHP development server in the terminal with Ctrl+C and restart:

~~~powershell
& "D:\php83\php.exe" -S localhost:8000 -t frontend
~~~

Use http://localhost:8000/login.php. Use an **incognito window** to test unauthenticated access while keeping any main-account session separate.

Do not share private backend/config.php, real passwords, OTP values, browser cookies or raw database backups.

## B. Manual Chrome test sequence

### Test 1 — Direct URL protection (without logging in)

In a new Incognito window, manually open each URL:

- http://localhost:8000/dashboard.php
- http://localhost:8000/bills.php
- http://localhost:8000/payments.php
- http://localhost:8000/reminders.php
- http://localhost:8000/profile.php

**Expected:** every page redirects to login.php. No private bill or account details should appear.

### Test 2 — CAPTCHA, password and OTP login

1. Open login.php in your test-account browser.
2. Intentionally type an incorrect CAPTCHA with an otherwise valid username/password.
   **Expected:** CAPTCHA error, no successful login and no login OTP.
3. Refresh the CAPTCHA. Type a correct CAPTCHA but wrong password.
   **Expected:** "Invalid username or password."
4. Separately submit a nonexistent username, wrong password and correct fresh CAPTCHA.
   **Expected:** the **same generic message** (unless the test IP has already been blocked).
5. Enter the correct test username/password, complete the CAPTCHA and privacy checkbox. Submit.
   **Expected:** redirect to login-otp.php and email message delivered.
6. **Before entering OTP**, try dashboard.php in this same browser.
   **Expected:** redirect to login.php; a valid password alone cannot open the dashboard.
7. Return to the active login-otp.php page if your session remains valid, enter the newest code, and finish login.
   **Expected:** dashboard.php opens. The session starts only after successful email OTP.

**Remember:** An unsuccessful fresh-code email may now show an error instead of an incorrect success notice. Login verification must complete within **10 minutes** of the password step; afterward repeat username/password/CAPTCHA.

### Test 3 — Logout and back-button protection

1. From a logged-in test account, click **Logout** on the sidebar.
2. Manually browse to dashboard.php, bills.php and profile.php.
   **Expected:** redirect to login.
3. Press the browser Back button, then refresh.
   **Expected:** a protected page must not remain accessible with private data after refresh.
4. Open logout.php with a direct **GET**.
   **Expected:** HTTP 405 Method Not Allowed (logout is a CSRF-protected POST).

### Test 4 — Tier 1 lockout and security alert (separate test account ONLY)

**This intentionally locks the test account temporarily. Do not use your normal account.**

1. Go to login.php in a fresh browser tab.
2. Submit the correct **test username** with a wrong password and fresh valid CAPTCHA **three times**. The CAPTCHA changes after every submission.
3. **Expected:** Tier 1 lockout after the third incorrect password. The Sign In button is disabled and a countdown shows the remaining seconds. A security alert email should arrive in the test account's Inbox or Spam/Junk folder.
4. Try the correct password while the server lockout is still active. **Expected:** still blocked.
5. After the server's lockout has expired, use a fresh CAPTCHA and the correct password, then complete email OTP. **Expected:** successful sign-in resets the failure count.

To inspect safe activity evidence in phpMyAdmin, select billguard_dev and run this READ-ONLY query:

~~~sql
SELECT event_type, created_at
FROM activity_log
WHERE event_type IN ('LOGIN_FAILED', 'LOCKOUT_TIER_1',
    'LOCKOUT_TIER_2', 'LOCKOUT_TIER_3', 'ALERT_EMAIL_SENT',
    'IP_BLOCKED', 'OTP_FAILED', 'LOGIN_SUCCESS')
ORDER BY id DESC
LIMIT 25;
~~~

Expected near the test: LOGIN_FAILED, LOCKOUT_TIER_1, and ALERT_EMAIL_SENT **if** Gmail accepted the security alert. Do not share full account IDs or IP addresses from the activity log.

**If no email arrives:** check Spam and run the existing CLI SMTP test, without revealing credentials:

~~~powershell
& "D:\php83\php.exe" backend\scripts\test_email.php
~~~

For email troubleshooting, inspect only safe error categories in backend/logs/php-error.log; never paste the entire private file.

### Test 5 — OTP cancellation, replay and resend

On the test account in an **unlocked** state:

1. Complete the password/CAPTCHA step to receive an OTP.
2. Submit three incorrect six-digit OTPs. **Expected:** third attempt cancels that code, no dashboard access.
3. Select Resend Code once the 60-second cooldown permits. **Expected:** a new emailed code; old code remains invalid.
4. Use the newest code once. **Expected:** login succeeds.
5. Reuse the consumed code in another pending login session. **Expected:** rejected.
6. Verify a fresh code expires according to the preflight's local expiry duration.

Fresh password logins and Resend Code requests share a rate limit of four emailed codes per 15 minutes. Do not repeatedly click and flood your inbox.

### Test 6 — IP blocking and higher alert tiers (automated)

**Do not manually force 15+ failed logins from your main PC**: a shared-IP block intentionally prevents you from signing in to all accounts from that IP for 10 minutes. The GitHub CI MySQL suite checks the IP threshold without using your live accounts.

The same integration tests exercise Tier 2 on the sixth failure, Tier 3 on the ninth, and alert retry after simulated SMTP failure; manual verification of genuine Tier 2/3 alert email delivery remains optional and requires a dedicated test account.

### Test 7 — Seeded session inactivity (later session checkpoint)

After a verified test login, leave the account idle for the number of minutes shown by check_phase3.php (do not refresh any page while idle). Attempt to navigate to a protected page.

**Expected:** the server clears the session and redirects to login. The planned **one-minute warning before expiry** belongs to Phase 4 and has not been added yet.

## C. Features repaired during Phase 3 review

- SMTP alerts now update last_alerted_tier only after mail is accepted; an undelivered alert is eligible for retry at a subsequent failed password attempt.
- An OTP sent before a later account lockout cannot be used or resent to bypass that lockout.
- Pending login verification expires after 10 minutes; the expired state gives a login-page notice.
- Initial login OTP delivery failures appear as errors rather than success notices.
- Login OTP requests enforce server-side rate limits even when triggered by repeated correct-password submissions.
- Login lockout displays a countdown; when it expires, the UI requires a fresh CAPTCHA. The actual security enforcement remains on the server.
- No real credentials, OTPs or test-account personal data are committed to GitHub.

## D. Reporting failures

For each unexpected result, report:
1. **Test number and action** (e.g. Test 4, third incorrect password).
2. **Expected behavior vs actual visible message**.
3. A screenshot with **email addresses, IDs, OTPs, passwords, cookies, and private details hidden**.

We will correct the specific problem in GitHub and rerun automated tests before repeating the local browser check.
