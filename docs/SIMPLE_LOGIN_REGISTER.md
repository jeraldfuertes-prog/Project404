# BillGuard – Simplified Registration and Secure Dashboard Flow

**Status:** Implemented in source code, syntax/unit checks passing in GitHub Actions. **Your local browser/database/SMTP end-to-end test is still required.**

## What changed?

Registration is now a **three-step wizard** rather than an overwhelming single-page form:

1. **Your information:** first name, family name, optional middle name, birthday, email, required ID, calculated age, optional name extension.
2. **Address & utility:** the six required address fields and the four approved BillGuard-specific registration fields.
3. **Account security:** username, password, confirmation, three seeded recovery questions, CAPTCHA and required unchecked privacy consent.

**Important:** The user experience is simpler, but we **did not remove required fields** from the IT 107 handout. Both JavaScript and PHP still validate all necessary information. When JavaScript is disabled, the complete form remains visible and submits through server-side validation.

## Login flow

```text
Register → Validate required fields → Email registration OTP
         → Activate account → Log in with username/password + CAPTCHA
         → Email login OTP → Verify one-time code → Protected Dashboard
         → Logout (CSRF-protected)
```

Registration verification does **not** authenticate the user; only the login OTP creates the authenticated PHP session.

Login protections already included in this revision:
- Generic unknown/wrong-password error
- PHP-verified image CAPTCHA
- Seeded tier lockouts after 3, 6, 9 password failures
- IP blocking after `8 + D2` login failures in ten minutes
- Tier email alerts with time (Philippine time), IP, browser and advice
- Six-digit login OTP, private hashed storage, expiry, three wrong attempts, single-use code
- 60-second resend cooldown and three resends/15 minutes
- HttpOnly/SameSite session cookies, regenerated ID, server-enforced seeded idle expiry
- Protected dashboard; no dashboard access by simply typing the URL
- CSRF-protected logout and audit records

**Not yet complete:** Password recovery, the one-minute idle timeout warning, all final security attack tests, and admin monitoring are still later project milestones. Do not mark the entire Phase 3 complete.

## Dashboard behavior

The dashboard only reads `utility_bills` and `utility_payments` owned by the current logged-in user. It shows the month totals, unpaid bill value, payments recorded this month, and up to eight household bills. A new account correctly shows `No bills recorded yet` — adding/editing/paying bills comes in the Bill Management phase.

## Windows: update and run

In the **VS Code terminal**, from the Project404 root:

```powershell
git pull origin main
& "D:\php83\php.exe" backend\scripts\check_phase2.php
& "D:\php83\php.exe" backend\tests\phase2_rules_test.php
& "D:\php83\php.exe" backend\tests\auth_rules_test.php
& "D:\php83\php.exe" -S localhost:8000 -t frontend
```

Open Chrome:
- Registration: http://localhost:8000/register.php
- Login: http://localhost:8000/login.php
- Dashboard: http://localhost:8000/dashboard.php

A direct visit to the Dashboard without a valid authenticated session should redirect to Login.

**Do not overwrite `backend/config.php`** during `git pull`. Your existing private MySQL and Gmail SMTP settings must remain local. Complete `seed_d1`, `seed_d2`, `encryption_key`, `privacy_contact` and `privacy_retention` according to `docs/PHASE2_SETUP.md` if not already configured.

## CAPTCHA troubleshooting

If you see a broken image where CAPTCHA should appear, enable PHP GD in `D:\php83\php.ini`:

```ini
extension_dir = "D:\php83\ext"
extension=gd
```

Restart the development server and verify:

```powershell
& "D:\php83\php.exe" -m | Select-String gd
```

Open http://localhost:8000/captcha.php directly to check if an image is produced.

## Demo checklist

- [ ] First registration step contains your name, birthday, email, and minimal additional identity information.
- [ ] Continue and Back preserve entered values and show field-specific errors.
- [ ] CAPTCHA loads and regenerates correctly.
- [ ] Registration OTP arrives in the test inbox.
- [ ] Correct OTP activates the account; wrong/expired/used codes are rejected.
- [ ] New user logs in using username, password, CAPTCHA, then *login* OTP.
- [ ] Valid code sends user to the protected dashboard.
- [ ] Direct dashboard visit without login redirects to login.
- [ ] Logout destroys session; browser Back cannot reopen protected data.
- [ ] Invalid passwords trigger logged failed attempts and lockout tiers.
- [ ] User dashboard does not show another account's bills.

**Testing caution:** GitHub Actions checks PHP syntax and selected rules, but do not simulate Windows + XAMPP, Gmail delivery, or the actual browser. You still need to verify those flows locally before the next class demo.
