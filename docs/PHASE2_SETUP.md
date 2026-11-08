# BillGuard Phase 2 — Secure Registration Setup & Test Guide

**Status: code implemented, local verification needed.** This file is written for your Windows computer with PHP 8.3 at `D:\php83\php.exe`, XAMPP MySQL, Composer/PHPMailer and VS Code.

> **Approval condition:** Your instructor-approved Appendix A and personal seed table govern the live system. The code currently uses the four **previously proposed** BillGuard fields (Utility Account Number, Primary Utility Type, Service Start Date and Monthly Utility Budget). If the signed proposal uses different fields or rules, update them **before collecting any real records**.

## 1. Update your local files

Inside VS Code terminal:

```powershell
cd C:\Users\Administrator\Desktop\Project404
git pull origin main
```

The new files include:
- `frontend/register.php`, `frontend/verify-otp.php`, `frontend/verified.php`, `frontend/captcha.php`, `frontend/check-username.php`
- `frontend/js/registration.js`, `frontend/js/otp.js`, extended Cyberpunk CSS
- `backend/php/security.php`, `backend/php/registration.php`, `backend/php/otp.php`, `backend/php/mailer.php`
- A sample private config, Phase 2 checks and this guide.

## 2. Enable the GD CAPTCHA extension (PHP 8.3)

The handout permits your **own distorted image CAPTCHA generated with PHP GD**. Our code uses six random characters with noise and case-insensitive server-side verification.

Run:

```powershell
& "D:\php83\php.exe" -m | Select-String "gd"
```

If nothing appears, open `D:\php83\php.ini` and enable:

```ini
extension_dir = "D:\php83\ext"
extension=gd
```

Save, stop any running PHP development server with Ctrl+C and start it again. Confirm with `php -m` (or use the explicit PHP 8.3 path above). Do not enable a duplicate extension declaration.

## 3. KEEP your existing config.php and add Phase 2 settings

Your previously configured `backend/config.php` has working private MySQL and SMTP credentials. **Do not overwrite it with `config.sample.php`.** Open it in VS Code and add the following keys *inside its existing returned array*:

```php
// These are placeholders. Use your own approved last two student-number digits.
'seed_d1' => null,              // second-to-last digit, integer 0–9
'seed_d2' => null,              // last digit, integer 0–9

'encryption_key' => 'REPLACE_WITH_BASE64_32_BYTE_KEY',
'privacy_contact' => 'REPLACE_WITH_PRIVACY_CONTACT_EMAIL',
'privacy_retention' => 'REPLACE_WITH_RETENTION_AND_DELETION_POLICY',
```

**Change the two `null` values to integers** based on your already approved seed table. Example: `'seed_d1' => 4` and `'seed_d2' => 7` are only illustration values; do not copy them unless they are truly yours.

**Generate a new 32-byte AES key locally:**

```powershell
& "D:\php83\php.exe" -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```

Copy the full printed `base64:...` string privately into the `encryption_key` field in `backend/config.php`. **Do not share, upload, commit or screenshot this key.** If the key is lost, encrypted account numbers cannot be recovered.

**Set an actual privacy contact inbox** and a clear, truthful **data retention and deletion policy** applicable to your project. Do not claim a deletion workflow exists unless you implement it before live use. Registration remains disabled until both are set. The Privacy Notice draws these values from your private config.

Your existing SMTP keys must include `smtp_host`, `smtp_user`, `smtp_password`, and `smtp_from`. If you used the old sample config, add `smtp_from` if it is missing. In Gmail, use an App Password, not your normal account password.

## 4. Check setup and syntax

From the project root:

```powershell
& "D:\php83\php.exe" backend\scripts\check_phase2.php
& "D:\php83\php.exe" backend\tests\phase2_rules_test.php
```

All entries in `check_phase2.php` should say `OK`; the rules test should have no failures.

You can check PHP syntax for every file:

```powershell
Get-ChildItem frontend,backend -Recurse -Filter *.php | ForEach-Object {
  & "D:\php83\php.exe" -l $_.FullName
}
```

Fix any `Parse error` or `Errors parsing` result before proceeding.

**Database:** Phase 1 already confirmed nine tables. Phase 2 reuses the same `backend/database/schema.sql`; **do not import the same schema again into a production database or delete tables**. The code expects the original nine tables and related columns.

## 5. Start the website in VS Code

```powershell
& "D:\php83\php.exe" -S localhost:8000 -t frontend
```

In Chrome:
- Registration: `http://localhost:8000/register.php`
- Privacy Notice: `http://localhost:8000/privacy.php`
- Email verification: `http://localhost:8000/verify-otp.php` — direct access without an active registration **redirects to registration**
- Home: `http://localhost:8000/`

**Only `frontend/` may be served from the PHP development server.**

## 6. Test a registration end-to-end

Use **synthetic but valid** household data and a real inbox you control. For your approved field rules:
- ID Number formatted like `2026-0147` (unique).
- Names such as `Juan Carlo` and `Dela Cruz`, in Title Case.
- Birthdate belonging to an adult age 18 or older.
- Address: `Purok 3`, `San Isidro`, `Cabadbaran`, `Agusan Del Norte`, `Philippines`, ZIP `8605`.
- BillGuard data: fictitious 8–12 digit utility account number, approved utility type, past start date and positive PHP budget.
- Valid username within your seeded range and a password rated at least Medium.
- Answers 2–50 letters/spaces for your three seed-selected security questions.
- Read Privacy Notice, tick consent, enter image CAPTCHA.

Expected workflow:
1. Browser displays field errors, age calculation, password strength and username availability.
2. Server validates every field again, encrypts the utility account number and stores **hashes** of password/answers.
3. Account is saved with status `pending`, and a hashed registration OTP is emailed.
4. `verify-otp.php` displays the masked destination and expected expiry.
5. Enter the **correct six-digit OTP** from your inbox. The account switches to `active`; a used OTP cannot be reused.
6. Visit `verified.php`. Verification does **not** log you in; secure login will be built in Phase 3.

**OTP edge cases:** A wrong code three times cancels it; after a new code is sent, the older code fails. The OTP expires after your **seeded** number of minutes. Server-enforced resend cooldown is 60 seconds, with at most **three resends per 15-minute window**.

## 7. Check the database, without exposing private values

In phpMyAdmin, inspect the `users` row status and verify that `password_hash` is a hash. The encrypted utility account number is in `utility_account_ciphertext` (not stored as plaintext). Inspect `security_answers.answer_hash` and `one_time_passwords.otp_hash` — never plaintext.

Examples of safe evidence to provide your instructor:
- Registration page with a correctly styled validation error.
- The verification email in your own inbox (obscure the code when sharing screenshots).
- The `active` account status and hashed/encrypted columns with sensitive values masked.
- Activity events: `REGISTERED`, `OTP_SENT`, `ACCOUNT_VERIFIED`.

## 8. Required negative tests

| Test | Expected result |
| --- | --- |
| JavaScript disabled, incorrect ID/age/name or fake utility type | PHP refuses |
| Existing ID/email/username | Specific error under matching field |
| Password weak / confirmation mismatch | Refused; appropriate field error |
| Privacy consent unchecked | Refused |
| Browser tools remove CSRF token | HTTP 403; `CSRF_REJECTED` event |
| Wrong/missing CAPTCHA | Refused; `CAPTCHA_FAILED` event |
| Autofill hidden `company_site` field | Registration refused (honeypot) |
| Old OTP after resend | Refused |
| Three wrong OTP entries | Code cancelled, new code required |
| Expired OTP | Refused |
| Directly visit `verify-otp.php` without registration session | Redirect to `register.php` |
| Directly visit `verified.php` | No success claim or login granted |

**Important:** Registration must remain disabled while your approved seed, privacy and encryption setup are incomplete. No real student number, App Password or sensitive financial data belongs in Git.
