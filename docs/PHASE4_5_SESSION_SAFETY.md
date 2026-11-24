# BillGuard Phase 4.5 — One-Minute Session Warning & Stay Signed In

**Status:** Implemented in GitHub; local Chrome tests pending. This stage
preserves the Phase 3 seeded server-side inactivity timeout. No database
migration is required.

## How it works

1. The existing authenticated session's idle duration remains
   **5 + (d1 % 5) minutes**. Do NOT replace with a fixed five-minute timeout.
2. All authenticated pages using the shared BillGuard dashboard layout
   (Dashboard / Utility Bills / Payments / Reminders / My Profile) include a
   Cyberpunk warning dialog. It first appears when **60 seconds remain**
   until the server-side inactivity deadline.
3. The dialog shows a countdown and two actions: **Log Out** or
   **Stay Signed In**. Keyboard focus is placed on the renewal action.
4. **Stay Signed In** sends an explicit, same-origin, CSRF-verified POST
   to session-renew.php. The backend uses the existing authentication check:
   expired and password-reset-revoked sessions cannot be renewed. A successful
   response returns the new server expiry; only then does the dialog close.
5. Status checks to session-status.php are **GET and read-only**. They do
   **not** update last_activity. They re-check authoritative expiry when
   opening a suspended tab, returning to a tab, or at the timer deadline.
   This prevents cross-tab false logout and handles browser timer throttling.
6. If the server confirms expiry, the browser navigates to Login with a
   session-expired notice. No background heartbeat silently renews login.
7. Opening session-renew.php with GET returns **405**. Invalid/missing CSRF
   returns **403** without renewing. Expired sessions receive **401**.
8. A successful password reset invalidates previous sessions through
   their bound password-hash fingerprint, as implemented in Phase 4.4.
9. The no-store headers and protected route authorization remain active;
   browser Back cannot restore access to authenticated server routes.

### Important semantics

Session inactivity means **time without an authenticated server request**.
Simply moving the mouse or typing in a non-submitted form does **not**
extend the server-side session. Only actual authenticated requests (navigation,
form submissions) and the explicit Stay Signed In action renew it.

Your personal d1 seed value is private and should not be posted.

## Windows setup

In VS Code PowerShell:

~~~powershell
cd C:\Users\Administrator\Desktop\Project404
git pull origin main
& "D:\php83\php.exe" backend\tests\session_safety_rules_test.php
& "D:\php83\php.exe" backend\scripts\check_phase3.php
~~~

**Expected:** new session rules end with zero failed checks.
The preflight reports local security configuration.

Start XAMPP MySQL. If PHP server is stopped, use:

~~~powershell
& "D:\php83\php.exe" -S localhost:8000 -t frontend
~~~

Do **not** run session_safety_integration_test.php against your real
billguard_dev database. That test is guarded and runs with synthetic users in
the disposable GitHub Actions MySQL instance.

## Chrome browser checks

Use a registered test account and normal login email OTP.

### A. One-minute warning

1. Visit http://localhost:8000/login.php, sign in and complete login OTP.
2. Leave the dashboard **untouched** (no navigation, reload, or form submit).
3. Wait until the last minute before the **seeded** timeout:
   the original server idle period is 5–9 minutes based on your approved d1.
4. Expect the dark Cyberpunk **SESSION EXPIRING SOON** dialog to appear at
   about **01:00**. The visible timer should count down.

Screenshot to submit: the dashboard with the warning dialog and countdown,
with account information redacted.

### B. Stay Signed In

1. While the warning is visible, click **Stay Signed In**.
2. Expect the dialog to close. The browser should remain on the dashboard.
3. Stay on the page past the **original** expiry time.
4. Expect the dashboard to remain valid; the renewed session uses a new
   full seeded inactivity period. The warning should reappear in the
   final minute of the **new** deadline.

Screenshot to submit: normal dashboard after clicking Stay Signed In.

### C. Automatic expiry

1. Log in again and open Dashboard.
2. Leave the page untouched and **do not** click Stay Signed In when
   the warning appears.
3. At the actual server-side timeout, expect automatic navigation to
   login.php?session=expired with the session-expired notice.
4. Try opening http://localhost:8000/bills.php directly. Expect redirect to
   Login; expired sessions must not grant authenticated data.

Screenshot to submit: Login page with expired-session notice.

### D. Log Out

1. Log in, let the warning appear and click **Log Out**.
2. Expect a normal, CSRF-protected logout followed by Login.
3. Browser Back or a direct dashboard URL must not reveal private data.

Screenshot to submit: Login after intentional Log Out.

### E. HTTP safety (optional)

1. In a logged-in session, opening
   http://localhost:8000/session-renew.php as a **normal URL (GET)**
   must **not** renew the session. It returns HTTP 405.
2. In Chrome DevTools > Network while your dashboard is open, check that
   session-status.php uses GET and session-renew.php uses POST **only when you
   explicitly click Stay Signed In**. Never share session cookies or CSRF tokens.
3. Switching tabs can cause a **read-only** session-status.php request.
   It must not extend the idle deadline.

## Regression checklist

- Normal login CAPTCHA and login email OTP still work.
- Dashboard, Bills, Payments, Reminders and Profile render correctly.
- Existing logout continues to work.
- Password-reset session invalidation from Phase 4.4 still works.
- No new database migration is required.

## Automated CI

The GitHub Actions workflow checks PHP and JavaScript syntax, plus:
- session_safety_rules_test.php: seeded timeout, 60-second boundary,
  hard expiry, missing/future timestamps, CSRF token checks.
- session_safety_integration_test.php (disposable CI MySQL):
  passive status doesn't renew, explicit authenticated request renews,
  password reset revokes sessions, and expired sessions cannot renew.
- All pre-existing Phase 2–4.4 and billing tests.

If a test fails, report the step and redacted screenshot; do not share
passwords, emails, OTPs, CSRF tokens, cookies, or raw logs.
