# BillGuard – Current Simple Registration and Login Flow

**Current source of truth:** [Simplified registration changes, database migration and test steps](SIMPLE_REGISTER_MIGRATION.md).

The earlier three-step registration wizard has been replaced with a compact, single-page form. Please use the linked current guide; its migration is mandatory on an existing local database.

**Current fields:** First Name, Last Name, a single Home Address, Email, Username, Password, Confirm Password, plus three collapsible security-question answers and a small required privacy-consent checkbox. Registration email verification with a one-time code is retained. On Login, users enter Username, Password, image CAPTCHA and acknowledge the Privacy Notice, then verify the login email OTP before reaching the protected dashboard.

**Course compliance warning:** Removing the original handout's mandatory ID, birthdate/age, detailed address, four unique utility registration fields and registration CAPTCHA will fail those requirements unless your instructor explicitly approves an alternative. Seek revised approval before the next security checkpoint.

**Run from VS Code terminal:**

```powershell
git pull origin main
& "D:\php83\php.exe" backend\scripts\check_phase2.php
& "D:\php83\php.exe" -S localhost:8000 -t frontend
```

Open http://localhost:8000/register.php or http://localhost:8000/login.php. The dashboard at http://localhost:8000/dashboard.php requires completed email login OTP and will show no bills until the bill-management phase.

See also: [Exact validation errors](PHASE2_ERROR_MAPPING.md).
