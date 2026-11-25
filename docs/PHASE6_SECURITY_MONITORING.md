# BillGuard — Phase 6 Admin Security Monitoring

**Status:** Implemented and CI-tested on GitHub. Local browser verification,
admin account provisioning and Windows scheduler setup must be performed by
the project owner. No database migration is necessary if activity_log and
security_flags are already present.

## Included dashboard features

- Admin-only page: http://localhost:8000/admin-security.php
- Admin-only read-only JSON: http://localhost:8000/admin-security-data.php
- Last 24-hour summary: failed logins, successful logins, failed OTPs, security flags
- 24 hourly failed-login bar chart in Philippine time (PHT), including zero hours
- Top five source IP addresses ranked by failed-login events
- Twenty recent security flags showing pattern, target, severity and PHT timestamp
- Latest 50 security events, with date, event name, username and IP filters
- Automatic refresh of metrics and visible event rows every 60 seconds
- Escaped initial HTML, JavaScript textContent updates, prepared SQL queries
- Server-side role, password version and inactivity checks on page AND JSON feed
- Security access audit: ADMIN_VIEWED_DASHBOARD / ADMIN_ACCESS_DENIED
- Activity log remains append-only. Dashboard and feed cannot edit/delete logs.

The four summary metrics and chart cover the last 24 hours regardless of event
filters. Date/event/username/IP filters apply only to the latest-events table.

**The 60-second passive JSON refresh never extends the 15-minute inactivity
timeout.** Explicit interactive admin page navigation still counts as activity.

## Four detection rules

The CLI script backend/scripts/monitor.php examines the past 24 hours of
activity_log and inserts flags in security_flags. Detection uses Philippine
time and the class-configured seed.

| Flag pattern | Threshold | Severity |
| --- | --- | --- |
| IP_FAILURE_BURST | At least 8 + d2 failed logins from one IP in any rolling ten minutes | HIGH |
| USERNAME_MULTI_IP | One username attacked from at least three distinct IPs in 24 hours | HIGH |
| OTP_FAILURE_BURST | At least five failed OTPs for one user in any rolling ten minutes | HIGH |
| NIGHT_LOGIN | Successful login between 00:00 and 04:59 PHT | MEDIUM |

An unusual login is a signal to review, not proof of compromise. Each unique
pattern + target + PHT calendar date is saved at most once, enforced by
the existing database unique key. Repeating the script is safe. The monitor
prints aggregate counts, never full usernames/IPs/passwords/OTPs.

## Step-by-step Windows testing

### 1 — Pull and run unit checks

Open VS Code PowerShell:

~~~powershell
cd C:\Users\Administrator\Desktop\Project404
git status --short
git pull --ff-only origin main
& "D:\php83\php.exe" backend\tests\monitor_rules_test.php
& "D:\php83\php.exe" backend\tests\activity_log_rules_test.php
& "D:\php83\php.exe" backend\scripts\check_activity_log.php
~~~

Expected: zero failed, activity log setup READY. If Git refuses to
fast-forward, inspect git status and resolve the local state before pulling.
Never discard local work. The synthetic MySQL integration suite belongs to
isolated GitHub Actions and must NOT be run on your local account database.

### 2 — Configure your admin account

Only a database administrator should assign admin roles. Never offer
admin status in the public registration form. Use a separate account with
a verified email inbox. In phpMyAdmin > billguard_dev > SQL:

~~~sql
UPDATE users
SET role = 'admin'
WHERE username = 'YOUR_VERIFIED_TEST_ADMIN_USERNAME'
  AND account_status = 'active'
  AND email_verified_at IS NOT NULL;
~~~

Replace the placeholder with an account you control. Confirm one intended
row affected. Use the regular BillGuard login and email OTP. An admin is
not exempt from CAPTCHA, OTP, password rules or idle expiry.

### 3 — Validate the admin security dashboard

1. Sign in as a normal household test user and visit admin-security.php.
   Expected: 403 Access denied. Repeat for admin-security-data.php.
2. Sign out and try both URLs anonymously; expected 403 and no log data.
3. Sign in using your separate verified admin account and email OTP.
4. Click Security Activity/Security Monitor in the sidebar.
5. Confirm four summary cards, 24-hour bar chart, top five IPs, recent
   flagged threats, and latest 50 event table.
6. Enter LOGIN_FAILED in the Event name filter and apply. Only those
   event rows should remain; cards and hourly graph remain unchanged.
7. Clear the filters; optionally try a PHT date, username substring or
   IP substring. The table must never reveal HTML scripts or private codes.
8. Wait for the auto-refresh cycle, or create a normal, safe login event
   in a different browser, and confirm updated events appear.
9. Visit admin-security-data.php while logged in as admin. Expected a
   JSON object with ok=true plus summary, hourly, top_ips, flags, events,
   updated_at. It must not contain raw activity_log.details, user_id,
   passwords, OTP values, cookies or encryption key.
10. Let an admin session expire by inactivity. Passive refreshes must not
    keep it alive; subsequent requests must be denied.

### 4 — Execute the CLI monitor

Start MySQL in XAMPP, then run:

~~~powershell
& "D:\php83\php.exe" backend\scripts\monitor.php
~~~

Expected console fields: Security events checked (24h), threat patterns
detected, new flags, already flagged today, and DONE. Zero flags is
normal when test data contains no qualifying attack patterns. Do not
deliberately attack or lock out your real accounts to generate flags.
GitHub Actions covers the four detection rules using synthetic CI data.

phpMyAdmin queries to capture evidence:

~~~sql
SELECT pattern, target, severity, flagged_date, created_at
FROM security_flags
ORDER BY id DESC LIMIT 20;
~~~

~~~sql
SELECT event_type, created_at,
       JSON_UNQUOTE(JSON_EXTRACT(details, '$.resource')) AS resource
FROM activity_log
WHERE event_type IN ('ADMIN_VIEWED_DASHBOARD','ADMIN_ACCESS_DENIED')
ORDER BY id DESC LIMIT 30;
~~~

Avoid screenshots containing complete account identifiers, IPs or usernames.

## Schedule every 15 minutes in Windows Task Scheduler

1. Search Windows for Task Scheduler, then select Create Task.
2. Name the task "BillGuard Security Monitor".
3. On Triggers, add a Daily trigger. Enable Repeat task every 15 minutes,
   for a duration of Indefinitely.
4. On Actions select Start a program.
   Program/script: D:\php83\php.exe
   Add arguments: "C:\Users\Administrator\Desktop\Project404\backend\scripts\monitor.php"
   Start in: C:\Users\Administrator\Desktop\Project404
5. Run as a Windows user with read access to the private project configuration
   and permission to access the local MySQL server.
6. In Settings choose to avoid overlapping instances. Save and click Run
   to test; check the Last Run Result and the security_flags table.

The PC and database must be running for scheduled scans to succeed.
Keep configuration and logs outside any public web root. The script checks
PHP_SAPI and refuses non-CLI execution.

## Suggested instructor screenshots

1. Regular account blocked from admin-security.php, HTTP 403
2. Admin monitoring page showing KPI cards and 24-hour chart
3. Top five IPs and Security Flags area (mask sensitive addresses/IDs)
4. Latest events table filtered by LOGIN_FAILED or date
5. phpMyAdmin showing ADMIN_VIEWED_DASHBOARD and ADMIN_ACCESS_DENIED
6. PowerShell showing monitor_rules_test.php passes
7. PowerShell showing monitor.php count-only report
8. Task Scheduler 15-minute repeat trigger (if locally configured)

## Files implemented

- frontend/admin-security.php — admin dashboard
- frontend/admin-security-data.php — protected JSON feed
- frontend/js/admin-security.js — 60-second refresh with safe DOM text nodes
- frontend/css/cyberpunk.css — responsive dashboard styling
- backend/php/admin_audit.php — role-based access and audit events
- backend/php/admin_monitor_data.php — metrics/filters
- backend/php/monitor_rules.php — detection/deduplication
- backend/scripts/monitor.php — CLI-only scheduled scanner
- backend/tests/monitor_rules_test.php — rule and input validation tests
- backend/tests/monitor_integration_test.php — isolated MySQL tests

**Do not re-import backend/database/schema.sql into your existing database.**
