# BillGuard — Simplified Registration (Change Log & Migration)

## What changed

The sign-up screen has been simplified as requested:

| Section | Fields now shown |
| --- | --- |
| Personal information | First Name, Last Name |
| Home address | One Home Address text field |
| Account access | Email, Username, Password, Confirm Password |
| Account recovery | Three approved seed-selected security questions, hidden in an expandable section until opened |
| Data privacy | Small required Privacy Notice checkbox below the form |

There is **no Birthdate, Age, manual ID Number, Name Extension, split address, ZIP Code, Country, or Utility Profile** on the registration page. CAPTCHA now appears **on Login**, together with a Privacy Notice acknowledgment. Registration still verifies the email with an OTP after account creation.

### Why privacy consent is still in registration

BillGuard stores the user's name, home address and email as soon as the account is created. The user therefore needs to see and accept the Privacy Notice **before** registration is saved. The separate, large “CAPTCHA & CONSENT” section is removed. This small checkbox is the only intentional exception to the requested removal of consent from registration.

## Important for IT 107 assessment

**This simplified form does not meet all the registration requirements of the IT 107 Student Handout.** The handout explicitly requires manual ID Number, Birthdate and Age, six-part address, at least four system-specific registration fields, and CAPTCHA on registration. Generating an internal ID is not equivalent to collecting the required ID. Since Phase 0 was already approved, **ask the instructor to approve this change** or restore those required fields before presenting it as a fully compliant final project.

We have deliberately **not** invented fake birthdates, fabricated personal identity values or fictitious utility account numbers to satisfy the old database columns. The values omitted from registration now remain NULL in the database. The AES-256 helper remains for the future bill module, where a genuinely supplied utility account number could be encrypted.

## One-time migration for your existing MySQL database

**You must do this on your own Windows/XAMPP machine.** The GitHub repository cannot modify your local MySQL data.

1. Back up your database before altering anything. In `http://localhost/phpmyadmin/` select `billguard_dev` and use **Export → Quick → SQL → Export**. Save the resulting SQL file securely, outside the Git repository.
2. In phpMyAdmin, open the **Import** tab.
3. Select:
   `C:\Users\Administrator\Desktop\Project404\backend\database\migrations\2026-11-08_simple_registration.sql`
4. Click **Import**. It adds `users.home_address`, makes legacy registration columns nullable, and assembles a single-line address from any existing users' address parts.
5. **Run the migration only once.** Running it again would raise a duplicate-column error.
6. Confirm you still have 9 database tables and existing records. Do not delete or reimport the whole `schema.sql` on an existing database.

For a **brand-new database only**, `backend/database/schema.sql` already includes the new column and nullable legacy fields; no migration is required after a fresh import.

## Update your code

In the VS Code terminal:

```powershell
cd C:\Users\Administrator\Desktop\Project404
git pull origin main
& "D:\php83\php.exe" backend\scripts\check_phase2.php
```

If `Personal seed configured`, `Privacy contact and retention`, or `SMTP and enrollment config` remain **NOT READY**, add the required entries privately to `backend/config.php` (do not share the file or credentials). Registration will stay disabled until the configuration is complete.

The AES key is now reported as optional for **basic registration only**; the required cryptographic protection still needs an AES key before collecting sensitive utility account numbers in a future module.

## Test in Chrome

Start the server from your Project404 root:

```powershell
& "D:\php83\php.exe" -S localhost:8000 -t frontend
```

Open these pages:
- `http://localhost:8000/register.php` — the simplified registration form
- `http://localhost:8000/login.php` — username, password, CAPTCHA and Privacy Notice acknowledgment
- `http://localhost:8000/dashboard.php` — only available after login email OTP

Test a fake but valid record, using an email inbox you control:
1. Enter First Name (e.g. Juan), Last Name (e.g. Dela Cruz), a single Home Address.
2. Enter a unique email and username within your approved seed limits; use at least a Medium-strength password.
3. Open recovery questions; enter three answers. Accept the Privacy Notice.
4. Submit registration and check for the **registration email OTP**.
5. Enter the correct OTP to activate your account.
6. On Login, enter your username/password, CAPTCHA and Privacy acknowledgment.
7. Enter the **login email OTP** and confirm you reach the protected dashboard.
8. Click Logout. Reopen `dashboard.php` to confirm it redirects to Login.

Never paste your private `backend/config.php`, an OTP, SMTP app password or encryption key into chat or GitHub.

## Development status

GitHub Actions runs PHP syntax checks and selected client/server rule tests. Your local database migration, actual registration email delivery, and browser-to-dashboard flow must still be tested on your PC.
