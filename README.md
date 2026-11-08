# BillGuard
**Secure Household Utility Bill Monitoring System**

IT 107 – Information Assurance and Security I · Final Laboratory Project

> **Project status (November 8, 2026):** Phase 0 (proposal and personal seed) **complete, as reported by the project owner**. Phase 1 (environment setup and Cyberpunk UI) **complete**. Phase 2 (registration, CAPTCHA and verification OTP) **implemented in code, pending local end-to-end testing**. This repository is the official project repository.

## Purpose

BillGuard lets registered household users manage private electricity, water, and internet bill records. Each user can add bills, record payments, review due dates and monthly totals, while an administrator views security monitoring data rather than users' private bills.

The project's primary focus is the course's security requirements:
- Complete registration, four approved system fields, privacy consent, seeded security questions.
- Password strength meter, CAPTCHA, and email OTP for registration, login and reset.
- Seeded progressive lockouts, IP blocking, alert emails, secure sessions and password history.
- Thirteen defenses, AES-256 protection of sensitive data and append-only activity logs.
- Admin-only monitoring dashboard and PHP command-line security monitoring script.

## Technology stack

HTML, CSS, Bootstrap, JavaScript, PHP 8.x, MySQL 8.x with PDO prepared statements, Apache/XAMPP, PHPMailer SMTP, Chart.js and Git.

## Phases and instructor checkpoints

Read **[docs/PHASES.md](docs/PHASES.md)** for the eight-phase roadmap, detailed tasks, planned folders and required testing.

| Week | Focus | Checkpoint |
| --- | --- | --- |
| 1 | Proposal, personal seed, Git setup, SMTP test | 1 |
| 2 | Complete registration, validation, consent, CAPTCHA, email verification | 2 |
| 3 | Login with OTP, lockout, alerts, recovery and activity log | 3 |
| 4 | Thirteen protections, security dashboard, monitor script, tests and demo preparation | 4 + Demo |

**Git requirement:** Keep at least 30 *meaningful* development commits across at least four weeks. Do not invent, backdate, or mass-generate history.

## Security rules

- Never commit actual database credentials, SMTP app passwords, CAPTCHA keys, encryption keys, or private user records.
- Store real `backend/config.php` and private logs outside the web-accessible `frontend/` directory; see `.gitignore`.
- Server-side PHP must enforce all security rules; JavaScript helps usability but cannot be trusted for access control.
- Uploaded and user-entered data must be validated and safely escaped before display.
- The admin dashboard must remain read-only and unavailable to normal users.


## Phase 1 – Cyberpunk web design and local setup

Project folders are separated: **`frontend/`** is the web root for pages and static assets, and **`backend/`** holds server-side PHP and future private services. Set Apache DocumentRoot or PHP's `-t` to `frontend/`, never to the repository root.

A dark, neon cyberpunk design is used throughout. The **registration form, CAPTCHA and registration email OTP are now implemented (Phase 2)**, but they remain gated until you configure the approved seed, private AES key, privacy details and GD extension. **Login, household billing and admin monitoring remain previews/future phases.**

To preview on Windows after cloning the repository:

```powershell
cd Project404
& "D:\php83\php.exe" -S localhost:8000 -t frontend
```

Then open http://127.0.0.1:8000/.

See **[Phase 1 design notes](docs/PHASE1_DESIGN.md)** for the color palette, pages, accessibility and XAMPP/DocumentRoot guidance.

## Phase 1 local setup (verified by project owner)

PHP 8.3.36, Composer 2.11.1, PHPMailer 6.12.0, SMTP inbox delivery, MySQL PDO connectivity and all 9 tables were **successfully verified by the project owner** in Windows PowerShell.

Follow **[Phase 1 Windows Setup & Verification](docs/PHASE1_SETUP.md)** for exact PowerShell commands and phpMyAdmin instructions.

- [Appendix A proposal draft](docs/APPENDIX_A_PROPOSAL_DRAFT.md) — original template; owner confirms Phase 0 is complete
- [Student-number seed worksheet](docs/SEED_WORKSHEET.md) — use your own last two digits
- [MySQL schema](backend/database/schema.sql) — import locally
- [PHP/MySQL setup check](backend/scripts/check_setup.php) — use `--php-only` and `--db`
- [SMTP test script](backend/scripts/test_email.php) — requires your locally stored SMTP settings and PHPMailer

Security reminder: Never publish `backend/config.php`, SMTP App Passwords, or real household records. Keep `frontend/` as the only web-accessible root.

## Phase 2 — secure registration in progress

The current Phase 2 implementation adds:
- Registration with core and four BillGuard-specific fields, age calculation, red required/optional indicators and exact field errors.
- Live password meter and username availability check, plus strict PHP validation with JavaScript disabled.
- Three seed-selected recovery questions, hashed passwords and answers, RA 10173 consent timestamp.
- Server-side session CAPTCHA using PHP GD, CSRF check and honeypot.
- AES-256-GCM encryption of the utility account identifier.
- Registration email OTP, hashed/expiring/single-use, three wrong attempts, 60-second resend and 3 per 15-minute limit.
- Security activity log entries for key registration/verification events.

**Local configuration required:** see [Phase 2 Setup](docs/PHASE2_SETUP.md), [Error Message Mapping](docs/PHASE2_ERROR_MAPPING.md) and [Design Decisions](docs/PHASE2_DESIGN_DECISIONS.md). Use the approved proposal and private student seed; NEVER commit credentials, AES key or a full student number.

**Next step:** run `php backend/scripts/check_phase2.php`, run the rule tests, start the site with `-t frontend`, and test registration + inbox OTP end-to-end. Phase 2 is **not complete until those tests pass**. Login remains Phase 3.

