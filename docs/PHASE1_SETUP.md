# Phase 1 — Windows/XAMPP Setup and Verification

This setup guide supplements the **IT 107 Student Handout**, especially Checkpoint 1 and Section 15.

**Current status:** Repo setup code is committed; the local Windows installation, real MySQL database, SMTP test delivery, and instructor approval are not yet verified. Complete the commands below **on your own computer**. Do not paste passwords or app passwords into chat, GitHub, screenshots, or documentation.

## 1. Get the latest source

Open **PowerShell**:

```powershell
cd C:\path\to\Project404
git pull origin main
```

If this is your first time working with Project404 locally, clone it instead:

```powershell
git clone https://github.com/jeraldfuertes-prog/Project404.git
cd Project404
```

## 2. Test PHP and the website

Install XAMPP if needed. Start **Apache** and **MySQL** in the XAMPP Control Panel (or a separate MySQL 8 server, if required). In PowerShell from the repository root:

```powershell
& "C:\xampp\php\php.exe" -v
& "C:\xampp\php\php.exe" backend\scripts\check_setup.php --php-only
```

Expected: PHP 8.1+, with PDO, pdo_mysql, OpenSSL and mbstring available. Check `C:\xampp\php\php.ini` if a needed extension is disabled, then restart XAMPP.

To preview the Cyberpunk UI, use XAMPP's PHP binary as a simple local development server:

```powershell
& "C:\xampp\php\php.exe" -S 127.0.0.1:8000 -t frontend
```

Open `http://127.0.0.1:8000/` in your browser. Confirm Home, Log-in preview, Registration preview, Privacy Notice and CSS all display correctly. The login and registration screens are not functional yet.

**Warning:** Do not browse from the repository root; `frontend/` must be the ONLY web root. If using XAMPP Apache instead of the PHP built-in server, set its virtual-host DocumentRoot to the absolute `Project404/frontend` path, restart Apache and keep backend files out of the served directory.

## 3. Create the database

Open `http://localhost/phpmyadmin/` once Apache and your database server are running. Use phpMyAdmin's SQL/Import feature to execute:

```text
backend/database/schema.sql
```

The file creates the **billguard_dev** database and **nine empty tables**:
`users`, `security_answers`, `one_time_passwords`, `password_history`, `activity_log`, `ip_blocks`, `security_flags`, `utility_bills`, `utility_payments`.

**Database engine note:** Many XAMPP bundles ship MariaDB rather than Oracle MySQL. Since the handout says *MySQL*, confirm with your instructor whether the XAMPP database is acceptable or install MySQL 8 separately. The SQL was written for MySQL 8; do not assume every MariaDB version is identical.

For local development use a dedicated MySQL application login rather than a root login. While signed in as an administrative DB user, an example for the **local machine only** is:

```sql
CREATE USER 'billguard_user'@'127.0.0.1' IDENTIFIED BY 'CHOOSE_A_PRIVATE_PASSWORD';
GRANT SELECT, INSERT, UPDATE, DELETE ON billguard_dev.* TO 'billguard_user'@'127.0.0.1';
```

Replace `CHOOSE_A_PRIVATE_PASSWORD` with a strong password **privately**. Do not commit it. This temporary development account uses broad table privileges; before final submission, review restrictions for append-only `activity_log` writes (especially avoiding UPDATE/DELETE), and keep the application code strictly insert-only for logs.

## 4. Configure and verify PDO/MySQL

From the repository root:

```powershell
Copy-Item backend\config.sample.php backend\config.php
```

Open **backend/config.php** locally and edit:
- `db_host` = `127.0.0.1`
- `db_name` = `billguard_dev`
- `db_user` = `billguard_user`
- `db_password` = the private password you created

Leave other secrets as placeholders until needed. Never push `backend/config.php`; it is ignored by `.gitignore`.

Run:

```powershell
& "C:\xampp\php\php.exe" backend\scripts\check_setup.php --db
```

Expected: `MySQL/PDO connection: OK` and `Database tables: 9`. If it fails, check MySQL service status, PHP `pdo_mysql` extension, the database import, and your locally stored credentials.

## 5. Configure and test SMTP

The handout requires **PHPMailer via SMTP**, a delivered test message for Checkpoint 1, and secure private credentials.

Make sure **Composer** is installed. In PowerShell:

```powershell
cd backend
composer install
cd ..
```

Composer uses `backend/composer.json`, installing PHPMailer in ignored `backend/vendor/`.

For Gmail SMTP, first turn on 2-Step Verification on the sending account and create a Gmail **App Password** (where available). Configure these values privately in `backend/config.php`:

```text
smtp_host           smtp.gmail.com
smtp_port           587
smtp_security       tls
smtp_user           your sending Gmail address
smtp_password       your private Gmail App Password (NOT your normal password)
smtp_from           your sending Gmail address
smtp_test_recipient an inbox you can open in the laboratory demo
```

Run:

```powershell
& "C:\xampp\php\php.exe" backend\scripts\test_email.php
```

Then actually check the recipient inbox, including Spam/Junk. A message accepted by SMTP does **not** by itself prove inbox delivery. If the lab network blocks outbound email, ask the instructor for approval to use Mailpit/MailHog, as allowed by the handout.

No real OTPs or passwords are sent in this test. Do not upload or share your SMTP App Password.

## 6. Proposal and personalized seed

- Open `docs/APPENDIX_A_PROPOSAL_DRAFT.md`.
- Transfer the proposed details onto **Appendix A of the instructor's handout** and obtain the instructor's signature.
- Compute your exact values using `docs/SEED_WORKSHEET.md`. You only need the last two digits of your student number for the formulas; do not post the full number publicly.
- Do **not** implement registration until the proposal is approved.

## Phase 1 completion evidence

| Item | Evidence needed | Status |
| --- | --- | --- |
| Cyberpunk pages and safe folder structure | Files/commit in GitHub | Ready |
| PHP/XAMPP works on your PC | CLI result + pages working in browser | Not independently verified |
| MySQL database created | 9 tables shown in phpMyAdmin | Schema prepared, import not independently verified |
| PHP/MySQL connection | Successful `--db` CLI report | Helper prepared, not independently verified |
| SMTP configured and received | Success output and message in inbox | Test script prepared, not independently verified |
| Proposal approved | Instructor's signed Appendix A | Pending |

**Reminder:** Phase 1 source preparation is not the same as a verified Checkpoint 1. Actual test and instructor approval evidence must come from your computer and instructor.
