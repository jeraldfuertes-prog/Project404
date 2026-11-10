# BillGuard – Development Phase Structure

This roadmap follows the IT 107 **Security1 Student Handout**. **Scope change (Nov 8):** The user requested a shorter signup form without mandatory ID, birthdate, split address, four custom utility fields or registration CAPTCHA. This is **not fully handout-compliant**. Obtain approval of the changed scope or restore those required fields before Checkpoint 2. See [simplified registration migration](SIMPLE_REGISTER_MIGRATION.md). **Status (November 8, 2026): Phase 0 and Phase 1 complete according to the project owner; Phase 2 is next.** Before implementing the registration form, use the instructor-approved Appendix A fields and student's personal seed values rather than placeholder defaults.

## Phase 0 — Proposal and personal seed (Week 1) — COMPLETED (owner-confirmed)

**Goal (completed per owner's update):** Prove the project qualifies and obtain permission to code the registration form.

- [x] Confirm that no classmate has already reserved the proposed system title.
- [x] Fill out Appendix A: system name, users, stored information, reason accounts need protection, sensitive data field, and four system-specific fields.
- [x] Proposed unique registration fields (subject to approval):
  1. Utility Account Number — formatted text; 8–12 digits; required; encrypt at rest.
  2. Primary Utility Type — dropdown: Electricity, Water, Internet; required.
  3. Service Start Date — date; required; cannot be in the future.
  4. Monthly Utility Budget — decimal amount in PHP; required; greater than 0.
- [x] Compute the Section 2.2 seed from the last two digits of **your own student number**, without including your full student number in public Git files.
- [x] Prepare the required table of seed-derived username/password limits, lockout times, OTP expiry, idle timeout, IP threshold, and three questions.
- [x] Submit Appendix A and obtain instructor signature/approval.

**Deliverable (reported complete):** Approved proposal + personal seed table.
**Checkpoint 1:** Proposal approved, seed table filled, Git repository and `.gitignore` created, and one test email sent.

## Phase 1 — Repository and development environment (Week 1) — COMPLETED

**Repository preparation:** database schema, PDO helper, PHP preflight, PHPMailer dependency and SMTP test script are committed. Follow [Phase 1 local setup](PHASE1_SETUP.md); keep the tests unchecked until the Windows installation and test inbox are actually verified.


**Goal:** Prepare safe, clear infrastructure; no application functionality until approval.

- [ ] Install and test XAMPP (Apache/PHP/MySQL), Composer, and VS Code.
- [ ] Create a MySQL database and version-controlled database schema (no real secrets or real user data).
- [ ] Add `config.sample.php` with **fake** settings. Keep actual `config.php` outside the web root, ignored by Git.
- [ ] Connect PHP to MySQL using PDO prepared statements.
- [ ] Set up PHPMailer SMTP; prove a test message is received.
- [ ] Prepare the shared page layout: system name ABOVE header, context-aware navigation, footer.
- [ ] Ensure all sensitive server-side modules and error logs are not directly served.

**Deliverable:** Local application shell + successful DB and email connectivity.

## Phase 2 — Registration and email verification (Week 2) — IMPLEMENTED / TESTING

**Goal:** Complete Checkpoint 2 end-to-end. **Code is committed; local GD, seed, privacy setup, registration and OTP tests remain.** See [Phase 2 Setup](PHASE2_SETUP.md), [Error Mapping](PHASE2_ERROR_MAPPING.md), and [Design Decisions](PHASE2_DESIGN_DECISIONS.md).

- [ ] Core registration fields from Section 4.1, plus instructor-approved system fields.
- [ ] Individual browser AND server validators, correct error under each field, and full error-message mapping.
- [ ] ID format `xxxx-xxxx` (primary key), unique ID/email/username, seeded lengths and name rules, birthdate/age, and six address fields.
- [ ] Privacy Notice page, required unchecked consent checkbox, consent timestamp.
- [ ] Three seed-selected authentication questions; hash lowercase answers.
- [ ] Password-strength meter (Weak/Medium/Strong); reject Weak; password confirmation.
- [ ] CAPTCHA on Registration (verified in PHP).
- [ ] Honeypot, CSRF token, and secure password hashing.
- [ ] Save account unverified; send six-digit securely generated, hashed, expiring registration OTP.
- [ ] Implement single-use OTP, three wrong attempts cancellation, resend cooldown and limit.
- [ ] Make login impossible until account email is verified.

**Checkpoint 2:** Registration, privacy consent, OTP, CAPTCHA, and both validation layers work.

## Phase 3 — Login, OTP, lockouts, and alerts (Week 3) — PARTIAL IMPLEMENTATION

**Goal:** Complete the first half of Checkpoint 3. **Current progress:** username/password/CAPTCHA login, email OTP, initial seeded lockout/IP block/alert logic, protected household dashboard and logout are committed. Full live testing, session idle warning, password recovery and instructor validation remain.

- [ ] Login form with username, password, show/hide toggle, and server-verified CAPTCHA.
- [ ] Identical message for unknown account and bad password.
- [ ] Email login OTP after valid password; no authenticated session until OTP succeeds.
- [ ] Regenerate session ID; use HttpOnly and SameSite cookies; prevent protected-page URL bypass.
- [ ] Progressive server-side lockout at 3, 6, 9 consecutive failed attempts using **seeded** durations.
- [ ] Show lockout countdown; disable login and registration link during lockout.
- [ ] Show Forgot Password link after the **seeded** number of failures.
- [ ] Aggregate failures from one IP across accounts; block for 10 minutes at the seeded threshold.
- [ ] Send owner alert email once per lockout tier (including PHT time, IP, device, and recovery guidance).
- [ ] Log relevant activity events without secrets.

**Deliverable:** Login secured with CAPTCHA, OTP, server lockout and alerts.

## Phase 4 — Password recovery and session safety (Week 3) — IN PROGRESS

**Goal:** Finish Checkpoint 3.

**Phase 4.1 implemented in source:** the new Forgot Password page accepts username + server-verified CAPTCHA, checks account/IP lockout status, applies a public recovery-entry request limit and gives identical visible confirmation for registered and unknown usernames. This is **only an entry step**, not a completed reset; security questions, reset email OTP, password updates and session warnings remain disabled until subsequent phases. GitHub automated tests pass; local browser tests are still required. See [Phase 4.1 guide](PHASE4_1_RECOVERY_ENTRY.md).

- [x] Phase 4.1 — Forgot Password entry with username, CAPTCHA, non-enumerating response and lockout-aware candidate staging.
- [ ] Phase 4.2 — Verify one of only the account's three stored security questions, without enumeration.
- [ ] Forgot Password: username + CAPTCHA, security question choice from only the account's three questions.
- [ ] Confirm re-entered answer; verify against hash without revealing whether a username exists.
- [ ] Count wrong answers toward the same lockout as login.
- [ ] Send/reset hashed, single-use email OTP.
- [ ] Allow Change Password ONLY after the reset flow completes; block direct URL access.
- [ ] Enforce seeded password length/strength, confirmation, and history of last 3 password hashes.
- [ ] Invalidate reset permission after use and send successful password-change email.
- [ ] Enforce seeded idle logout, 1-minute warning, and prevent showing protected pages after logout/back navigation.

**Checkpoint 3:** Login + OTP, lockout, owner alerts, Forgot/Change Password, and logging.

## Phase 5 — All thirteen security protections (Week 4)

**Goal:** Meet Section 12 and prepare to pass instructor's attack tests.

1. [ ] PDO prepared statements for **every** database query.
2. [ ] Escape untrusted output including security log fields (XSS).
3. [ ] PHP-verified CSRF tokens on every POST form.
4. [ ] Prevent username enumeration in login/recovery.
5. [ ] Seeded IP blocking across accounts.
6. [ ] Secure sessions, ID regeneration, seeded idle timeout, logout destruction.
7. [ ] Last three password hashes checked before accepting a change.
8. [ ] Hidden honeypot on registration; silently reject if filled.
9. [ ] Complete activity logging (Section 13.4).
10. [ ] Generic user-facing errors; private server error log outside web root.
11. [ ] Keep DB/SMTP/CAPTCHA/encryption secrets outside web root and Git history.
12. [ ] Apply `X-Frame-Options: DENY` and `X-Content-Type-Options: nosniff` on every page.
13. [ ] Encrypt Utility Account Number at rest using AES-256 with random IV; decrypt only for authorized owner.

**Deliverable:** Manual tests showing each mandatory protection works.

## Phase 6 — Admin security monitoring (Week 4)

**Goal:** Complete Checkpoint 4.

- [ ] Add an admin account directly to the database, never through open registration.
- [ ] Protect admin dashboard AND its JSON endpoint; log both permitted and denied access.
- [ ] Read-only dashboard: summary cards, failed-login hourly bar chart, top 5 IPs, script flags, latest 50 events with date/event/username/IP filters.
- [ ] Auto-refresh dashboard JSON every 60 seconds; protect from HTML/JS injection.
- [ ] Keep activity log append-only: never provide log edit/delete operations.
- [ ] Build `backend/scripts/monitor.php`, runnable only by CLI and inaccessible from the browser.
- [ ] Scan latest 24 hours, flag:
  - IP above seeded 10-minute failure threshold.
  - One username attacked from >=3 IPs.
  - >=5 OTP failures for one user within 10 minutes.
  - Successful login between 12:00 AM and 5:00 AM Philippine time.
- [ ] Avoid duplicate same-day flags; print a console summary.
- [ ] Document how Windows Task Scheduler or cron could run it every 15 minutes.

**Checkpoint 4:** Thirteen defenses, monitoring dashboard, and monitoring script.

## Phase 7 — Bill tracking, validation, documentation, and final demo

**Goal:** Provide simple real household functionality and prove all security requirements.

**Household features:** Create bills, record payments, view bill list with due dates and paid/unpaid statuses, and view monthly totals. Every query must be scoped to the authenticated owner. These should be introduced as early as feasible after the approved account workflow is working, then completed and tested here.

**Testing:**
- [ ] Run every item in Section 18 self-test checklist.
- [ ] Verify invalid inputs with JavaScript disabled; test SQL injection, XSS, CSRF, CAPTCHA bypass, OTP expiration and replay, direct URL bypass, idle timeout, and back button.
- [ ] Show encrypted account number unreadable in raw MySQL rows.
- [ ] Create one admin and two test users with working inboxes for demo.
- [ ] Test a narrow screen and all required header/footer/form rules.

**Documentation and submission:**
- [ ] SQL dump including all required tables and at least 5 test records.
- [ ] Signed proposal, seed table, threat model (one row per 13 defenses with C/I/A), Design Decisions for every 'Your call', Privacy Note, error-message mapping, weekly development journal.
- [ ] At least 30 **real, meaningful** Git commits over **at least four weeks**. Do not backdate or manufacture commits.
- [ ] Practice the 20-minute demo: 6-minute feature demo, 6-minute code explanation, 8-minute instructor's new requirement (without AI).

## Planned folder structure (not all folders created yet)

```text
Project404/
├── frontend/                  # Apache/PHP DocumentRoot: only browser-accessible assets
│   ├── index.php
│   ├── login.php
│   ├── register.php
│   ├── privacy.php
│   ├── verify-otp.php         # Future phase
│   ├── forgot-password.php    # Future phase
│   ├── change-password.php    # Future phase
│   ├── dashboard.php          # Future phase
│   ├── bills.php              # Future phase
│   ├── admin-monitor.php      # Future phase
│   ├── css/
│   │   └── cyberpunk.css
│   ├── js/                   # Browser-visible; no secrets
│   └── images/
├── backend/                   # NOT served by Apache/PHP
│   ├── php/
│   │   └── layout.php        # Internal PHP modules go here
│   ├── database/
│   │   └── schema.sql        # Future phase
│   ├── scripts/
│   │   └── monitor.php       # Future CLI only, not reachable via browser
│   ├── config.sample.php     # Future fake secrets only
│   ├── config.php            # Future real secrets; ignored, never commit
│   ├── logs/                 # Server error logs; ignored
│   └── vendor/               # Composer dependencies; ignored
├── docs/
│   ├── PHASES.md
│   ├── PHASE1_DESIGN.md
│   └── ...                   # Proposal, threat model, journal in future phases
├── .gitignore
└── README.md
```

**Important:** Launch the PHP development server with `-t frontend`; configure Apache DocumentRoot to `Project404/frontend`. Never serve the repository root or backend directory. Internal PHP modules, secrets, error logs and CLI scripts must not be directly accessible. Client-side JS cannot be hidden from the browser, so all security enforcement remains in server-side PHP.

## Practical four-week pacing

| Week | Milestone | Suggested progress goal |
| --- | --- | --- |
| 1 | Proposal + seed + safe repository setup | ~7 meaningful commits |
| 2 | Registration + consent + verification | ~8 meaningful commits |
| 3 | Login + recovery + alerts | ~8 meaningful commits |
| 4 | Security tests + monitoring + documentation | ~7 meaningful commits |

These are planning targets, **not a substitute for documenting actual development work**.
