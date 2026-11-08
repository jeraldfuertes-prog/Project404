# BillGuard Dashboard — Sidebar, Bills, Payments, Reminders and Profile

**Status:** Features implemented in GitHub source code. The owner must update the local MySQL database and test the browser flow in Windows. Passing automated tests alone does not confirm local browser operation.

## Navigation and features

| Sidebar | Page | Features |
| --- | --- | --- |
| Dashboard | frontend/dashboard.php | Billed this month, outstanding balances, payments this month, due-soon alerts and outstanding bill list |
| Utility Bills | frontend/bills.php | Add, view, edit and delete bills of Electricity, Water, Internet or **Other** type |
| Payments | frontend/payments.php | Record partial/full payments; calculate balances and view last 100 payments |
| Reminders | frontend/reminders.php | Overdue, Due Today, Due Soon (7 days), Scheduled |
| My Profile | frontend/profile.php | Update first/last name and home address; email and username remain read-only |
| Logout | frontend/logout.php (POST) | CSRF-checked secure session logout |

Sidebar: collapsible on desktop, sliding panel on mobile. Same black, green and cyan Cyberpunk style.

## IMPORTANT: One-time database migration on your PC

**Do not re-import the full schema.sql into an existing database. Do not run the migration more than once.**

1. In VS Code terminal from the Project404 folder, pull the latest repository:

~~~powershell
cd C:\Users\Administrator\Desktop\Project404
git pull origin main
~~~

2. In http://localhost/phpmyadmin/ select **billguard_dev**. Use **Export → Quick → SQL → Export** to save a secure backup outside the Git repository.
3. Go to **Import**, choose:
   C:\Users\Administrator\Desktop\Project404\backend\database\migrations\2026-11-08_add_other_utility.sql
4. Import the file **once**. It preserves existing bills and payments, adds Other to the allowed utility categories, and adds the nullable custom utility name.
5. Run the dashboard setup check:

~~~powershell
& "D:\php83\php.exe" backend\scripts\check_dashboard.php
~~~

Expected summary: **MySQL connection: OK**, **Required dashboard tables: OK**, **Other utility database migration: OK**.

For a brand-new database, the current backend/database/schema.sql already includes these changes. If the old simplified-registration migration was not applied, apply it before this one.

## Test in Chrome

Stop your existing PHP server with Ctrl+C, then run:

~~~powershell
& "D:\php83\php.exe" -S localhost:8000 -t frontend
~~~

1. Log in at http://localhost:8000/login.php and complete email OTP. Open http://localhost:8000/dashboard.php and test the desktop/sidebar collapse and mobile sliding menu.
2. On **Utility Bills**, add an **Other** bill named **LPG / Gas**, description **Cooking gas**, current billing month, amount **2500.00**, due 7–14 days in the future. The custom name should appear in the bill list.
3. On **Payments**, record a payment of **800.25**. The remaining balance must be **₱1,699.75** and status **Partial**. Try an amount larger than the balance; it must be rejected.
4. Record **1699.75** as the final payment. The remaining balance must be **₱0.00** and status **Paid**; further payments are blocked.
5. Add an unpaid bill due tomorrow. On **Reminders**, it appears under **Due Soon**. Past-due unpaid/partial bills appear under **Overdue**. Paid bills never trigger payment alerts.
6. On **My Profile**, edit your name and home address. The new name should appear in the dashboard greeting. Email and username should stay read-only.
7. **Logout**, then manually open dashboard.php, bills.php, payments.php, reminders.php and profile.php. Each must redirect unauthenticated visitors to Login.
8. Sign in with a second approved test account. It must not be able to see or modify the first account's bills or payments.

## Accounting and security rules

- All money uses MySQL DECIMAL(12,2) and integer centavos in PHP to avoid floating-point rounding.
- Remaining balance = maximum of zero and (original bill amount minus payments).
- **Unpaid:** no payments. **Partial:** at least one payment, balance still outstanding. **Paid:** balance zero.
- **Overdue** is a due-date warning and can coexist with Partial or Unpaid status.
- Reminders compare stored dates with today's date in **Asia/Manila**. Due Soon includes the next 7 days.
- The Dashboard's Billed This Month uses billing-month dates; Payments This Month uses actual payment dates; Remaining Balance spans all bills.
- **Other** makes Specify Utility Type mandatory (2–80 printable characters). The same name appears throughout the dashboard.
- Every bill and payment query uses the authenticated user's account ID. All writes use CSRF protection, server validation, prepared statements, database transactions, and security event logs.
- Editing/deleting a bill is blocked once payments are recorded, preserving payment history.
- These payment records **do not transfer money**. Users must still pay utility providers using their real payment channels.

## Current limitations and transparency

- Due-date reminders appear on the dashboard or Reminders page when opened; email/push/background reminders are not implemented.
- Profile name/address edits work; email/password/username changes are not implemented yet because stronger identity verification is required.
- The admin security dashboard, password recovery, actual bill-provider integrations and monthly charts are separate future tasks.
- The simplified registration still departs from original IT 107 requirements; get instructor reapproval or restore mandatory fields before final submission.
- The GitHub CI rule checks and integration tests are distinct from local browser and MySQL testing.

Key source: backend/php/billing.php, backend/php/app_layout.php, frontend/js/dashboard-nav.js, frontend/css/cyberpunk.css. Tests: backend/tests/billing_rules_test.php, backend/tests/billing_integration_test.php. Private runtime credentials stay in gitignored backend/config.php; never share the file or real passwords.
