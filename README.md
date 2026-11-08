# BillGuard
**Secure Household Utility Bill Monitoring System**

IT 107 – Information Assurance and Security I · Final Laboratory Project

> **Project status (November 8, 2026):** Phase 0 (proposal and personal seed) **complete, as reported by the project owner**. Phase 1 (environment setup and Cyberpunk UI) **complete**. Phase 2 (registration, CAPTCHA and verification OTP) **next**. This repository is the official project repository.

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


## Phase 1 – Cyberpunk web design preview

Project folders are separated: **`frontend/`** is the web root for pages and static assets, and **`backend/`** holds server-side PHP and future private services. Set Apache DocumentRoot or PHP's `-t` to `frontend/`, never to the repository root.

A dark, neon cyberpunk design has been added to the public homepage and guest screens. The current PHP pages are **visual previews only**: registration, log-in, billing, OTPs, and account monitoring have **not** been implemented yet. The registration form is intentionally not started until the proposal is approved.

To preview on Windows after cloning the repository:

```powershell
cd Project404
C:\xampp\php\php.exe -S 127.0.0.1:8000 -t frontend
```

Then open http://127.0.0.1:8000/.

See **[Phase 1 design notes](docs/PHASE1_DESIGN.md)** for the color palette, pages, accessibility and XAMPP/DocumentRoot guidance.

## Phase 1 local setup (verification still required)

Code for the database schema, PDO connection, CLI environment check and PHPMailer test email is now in the repository. **The tests have not been run on your Windows/XAMPP installation**, and a real SMTP message has not been verified in your inbox.

Follow **[Phase 1 Windows Setup & Verification](docs/PHASE1_SETUP.md)** for exact PowerShell commands and phpMyAdmin instructions.

- [Appendix A proposal draft](docs/APPENDIX_A_PROPOSAL_DRAFT.md) — **not approved yet**
- [Student-number seed worksheet](docs/SEED_WORKSHEET.md) — use your own last two digits
- [MySQL schema](backend/database/schema.sql) — import locally
- [PHP/MySQL setup check](backend/scripts/check_setup.php) — use `--php-only` and `--db`
- [SMTP test script](backend/scripts/test_email.php) — requires your locally stored SMTP settings and PHPMailer

Security reminder: Never publish `backend/config.php`, SMTP App Passwords, or real household records. Keep `frontend/` as the only web-accessible root.

## Next step

Begin **Phase 2**: confirm the instructor-approved four registration fields and the personal seed-derived limits before implementing the secure registration flow. Keep any full student number, credentials, and personal records out of the public repository.
