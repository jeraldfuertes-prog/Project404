# BillGuard
**Secure Household Utility Bill Monitoring System**

IT 107 – Information Assurance and Security I · Final Laboratory Project

> **November 8 scope update:** Registration is now a compact, single-page form (First Name, Last Name, one Home Address, Email, Username and Password), followed by email OTP. Login contains CAPTCHA and Privacy Notice acknowledgment. **IMPORTANT:** This intentionally omits instructor-required registration fields, so obtain written instructor approval for the amended scope or restore them before the final checkpoint. Apply the database migration first: [Simplified registration migration guide](docs/SIMPLE_REGISTER_MIGRATION.md). A small Privacy Notice consent checkbox stays on signup because personal information is stored at account creation.

> **Project status (November 8, 2026):** Phase 0 (proposal and personal seed) **complete, as reported by the project owner**. Phase 1 (environment setup and Cyberpunk UI) **complete**. Phase 2 (registration, CAPTCHA and registration OTP) **implemented in code, pending local end-to-end testing**. The basic secure login and user dashboard are also implemented ahead of the remaining Phase 3 work. This repository is the official project repository.

## Dashboard implementation (November 8, 2026)

The authenticated BillGuard dashboard now includes a **Cyberpunk collapsible/sliding sidebar**, live household totals, add/edit/delete utility bills, manually recorded partial/full payments, exact remaining balances, seven-day and overdue reminders, and profile name/address settings. Utility types are **Electricity, Water, Internet, and Other**; the Other option requires a custom utility name.

**The owner's local database requires a one-time migration before the new features work:**
[Dashboard migration and testing guide](docs/DASHBOARD_IMPLEMENTATION.md). The upgrade is implemented in source code and covered by CI; local browser/MySQL verification remains pending.

**Important limitations:** Payment recording does not transfer money; reminders are calculated when pages are opened, not sent as email/push notifications; email/password changes and admin security monitoring remain future work. The instructor must still approve the earlier simplified registration deviations.

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

A dark, neon cyberpunk design is used throughout. The **registration form, CAPTCHA and registration email OTP are now implemented (Phase 2)**, but they remain gated until you configure the approved seed, private AES key, privacy details and GD extension. **The secure email-OTP login and account-protected dashboard are implemented.** Bill entry and payment recording are now available in source after applying the required database migration; password recovery and admin monitoring remain future phases.

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

## Simple registration and protected dashboard

The registration screen is now a **single compact form** with only first and last name, one home address field and login credentials, plus folded recovery questions and required inline privacy consent. **This no longer meets the handout's complete registration-field and registration-CAPTCHA checklist.**

The login screen now accepts username/password/CAPTCHA, emails a login OTP, and directs the verified user to the **session-protected dashboard**. Newly registered accounts start with an empty dashboard; the owner can now add bills and payment records after applying the required Other-utility database migration.

See **[Step-by-step testing and instructions](docs/SIMPLE_LOGIN_REGISTER.md)**. **The project owner confirmed registration OTP, Login CAPTCHA and Login OTP work in their browser.** The newly built bill-management and sidebar flow still need local testing.

## Phase 2 — secure registration in progress

The current Phase 2 implementation adds:
- Simplified registration with name, single-line address, email, username, password and confirm password. Required course fields were removed at the owner's direction; instructor sign-off on the change is still needed.
- Live password meter and username availability check, plus strict PHP validation with JavaScript disabled.
- Three seed-selected recovery questions, hashed passwords and answers, RA 10173 consent timestamp.
- PHP GD CAPTCHA on Login (not registration), with CSRF and honeypot protections on registration.
- AES-256-GCM helper reserved for future utility account numbers; no utility identifier is collected during simplified signup.
- Registration email OTP, hashed/expiring/single-use, three wrong attempts, 60-second resend and 3 per 15-minute limit.
- Security activity log entries for key registration/verification events.

**Local configuration required:** see [Phase 2 Setup](docs/PHASE2_SETUP.md), [Error Message Mapping](docs/PHASE2_ERROR_MAPPING.md) and [Design Decisions](docs/PHASE2_DESIGN_DECISIONS.md). Use the approved proposal and private student seed; NEVER commit credentials, AES key or a full student number.

**Next step:** follow [Simple Registration + Login + Dashboard](docs/SIMPLE_LOGIN_REGISTER.md), run `php backend/scripts/check_phase2.php`, run both registration and authentication unit tests, and perform registration/login OTP tests in Chrome. Phase 2 is **not complete until those tests pass**. Remaining Phase 3 features include further alert/lockout testing and recovery integration.

