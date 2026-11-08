# BillGuard Email OTP — Repair and Safe Local Diagnosis

The user-reported symptom was: "Unable to send the code right now. Check your SMTP setup and try again." on the registration verification screen.

## What the code fix changes

- The CLI SMTP test, registration email, login OTP email, and lockout alert now share the same PHPMailer configuration.
- Gmail App Passwords copied as groups of four characters separated by spaces are converted to their original unspaced token **only for** `smtp.gmail.com`. Passwords for other SMTP providers are left unchanged.
- The OTP handler distinguishes an SMTP send failure from a SQL/logging failure in a **private** error log: `backend/logs/php-error.log`. It logs only the stage, a safe failure category, or a SQLSTATE code; it never prints OTPs, App Passwords or raw SMTP errors.
- OTP database transactions **roll back** when email sending fails. The account remains pending and **Resend OTP** can retry without registering again. Old active codes are restored by the rollback when a resend fails.
- The OTP email is sent only after the database insert and the `OTP_SENT` log write have succeeded inside the transaction.
- If the first OTP fails, the verification page displays an error notice, not a success notice.
- No database migration or extra table is required for this repair.

## On your Windows PC

**Security first:** An earlier chat included your MySQL password, Gmail App Password and encryption key. Revoke the old Gmail App Password, generate a new one, update `backend/config.php` privately, and change the exposed MySQL password. Do not share the replacements or the `config.php` contents. Replace the encryption key before using the planned encrypted utility account feature; retain securely any old keys needed to decrypt previously stored data.

1. Pull the update from the VS Code terminal at `C:\Users\Administrator\Desktop\Project404`:

   ```powershell
   git pull origin main
   ```

2. Run the email smoke test **before** clicking Resend:

   ```powershell
   & "D:\php83\php.exe" backend\scripts\test_email.php
   ```

   Expected: `SMTP test message accepted. Confirm arrival in the configured test inbox.`

   If the test fails, the terminal prints a **safe category**:
   - `SMTP_AUTH`: new Gmail App Password, correct SMTP username and account permissions; do not use your ordinary Gmail password.
   - `SMTP_CONNECTION`: connection, firewall or network problem on port 587/TLS.
   - `SMTP_RECIPIENT`: invalid recipient or rejected recipient address.
   - `MAIL_CONFIGURATION`: missing PHPMailer or configuration error.
   - `SMTP_OTHER`: an unexpected transport failure; check the private error log category.

3. Run the basic unit tests:

   ```powershell
   & "D:\php83\php.exe" backend\tests\mail_transport_test.php
   & "D:\php83\php.exe" backend\scripts\check_phase2.php
   ```

4. Restart your current PHP server in VS Code with Ctrl+C, then:

   ```powershell
   & "D:\php83\php.exe" -S localhost:8000 -t frontend
   ```

5. Return to your already open verification page: `http://localhost:8000/verify-otp.php`. **Do not submit registration again** if your account already has pending status. Select **Resend OTP**. If the old page session is lost, do not create a duplicate account; first restore the same browser session or use the account-recovery flow when implemented.

6. Check the account email Inbox and Spam folder. Use the **latest** six-digit code to verify registration.

## If it still fails

A local web request with `bg_secure_start()` directs application errors to:

```powershell
Get-Content backend\logs\php-error.log -Tail 20
```

**Do not paste the complete log here** if it includes unrelated private messages; inspect only lines beginning with `BillGuard OTP send failed`, and share just the safe bracketed category (e.g. `[SMTP] [SMTP_AUTH]` or `[database] [SQLSTATE: HY000]`) if needed.

Only a real local SMTP test and browser OTP verification can confirm delivery; GitHub Actions only checks syntax and unit tests.

## Design note

The current implementation sends email while holding a database transaction. This preserves the previous code on send failure without adding schema changes, but future production hardening should use a transactional outbox or delivery-state model to avoid long network calls within a SQL transaction.
