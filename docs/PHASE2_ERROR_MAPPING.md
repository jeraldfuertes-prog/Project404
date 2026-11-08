# Phase 2 — Field Validation and Exact Error Message Mapping

Every validation below is implemented in `backend/php/registration.php`, with matching browser checks in `frontend/js/registration.js`. The server always rechecks submitted values, even if JavaScript is disabled. This fulfills the handout Section 4.10 (P6).

| Field | Server-side rule | Exact error message |
| --- | --- | --- |
| ID Number | Exactly four digits, hyphen, four digits | ID Number must follow the format xxxx-xxxx. |
| ID Number | Unique primary key | This ID Number is already registered. |
| First Name / Family Name | 2 to `20 + D2` characters | `{Field} must contain between 2 and {nameMax} characters.` |
| First Name / Family Name | Digits prohibited | `Numbers are not allowed in {Field}.` |
| First Name / Family Name | Special characters prohibited | `Special characters are not allowed in {Field}.` |
| First Name / Family Name | No double spaces | `Double space is not allowed in {Field}.` |
| First Name / Family Name | No three identical letters in a row | `Three consecutive identical letters are not allowed in {Field}.` |
| First Name / Family Name | Not all capitals | `Do not use all capital letters in {Field}.` |
| First Name / Family Name | First character uppercase | `First letter of your {Field} must be capital.` |
| First Name / Family Name | Whole name in Title Case | `{Field} must use Title Case (for example: Juan Carlo).` |
| Middle Name | Optional; same nine rules above when provided | Same messages, with `Middle Name` substituted |
| Name Extension | Optional dropdown None, Jr., Sr., Other | Please select a valid Name Extension. |
| Other Name Extension | Only I, II, III, IV, IX or X | Other Name Extension must be I, II, III, IV, IX or X. |
| Birthdate | Real, non-future date | Please enter a valid Birthdate that is not in the future. |
| Birthdate | Recalculated age at least 18 | You must be at least 18 years old to register. |
| Purok / Street | Length 3 to 50 | Purok / Street must contain between 3 and 50 characters. |
| Purok / Street | Letters, digits, spaces only | Purok / Street may contain letters, digits and spaces only. |
| Purok / Street | No double spaces | Purok / Street must not contain double spaces. |
| Barangay, Municipality / City, Province, Country | 2–50 characters | `{Field} must contain between 2 and 50 characters.` |
| Barangay, Municipality / City, Province, Country | No double spaces | `{Field} must not contain double spaces.` |
| Barangay, Municipality / City, Province, Country | Letters and spaces in Title Case | `{Field} must contain letters and spaces in Title Case.` |
| ZIP Code | Four digits only | ZIP Code must contain exactly 4 digits. |
| Email | Valid address, no spaces, max 100; stored lowercase | Please enter a valid email address (maximum 100 characters). |
| Email | Unique | This email is already registered. |
| Username | Starts with letter, alphanumeric, length `6+(D1 mod 3)` to `16+(D2 mod 5)` | `Username must start with a letter and contain {min}–{max} letters or digits.` |
| Username | Unique; AJAX check while typing | This username is already taken. |
| Password | Length `8+(D2 mod 4)` to 64 | `Password must be between {min} and 64 characters.` |
| Password | At least three classes among uppercase, lowercase, digits, symbols | Password strength is Weak. Use at least three of: uppercase, lowercase, digits and symbols. |
| Re-enter Password | Exactly matches Password | Passwords do not match. |
| Utility Account Number | Exactly 8 to 12 ASCII digits | Utility Account Number must contain 8 to 12 digits. |
| Primary Utility Type | Only Electricity, Water or Internet | Please select a valid utility type. |
| Service Start Date | Real date that is not future | Service Start Date must be a valid date that is not in the future. |
| Monthly Utility Budget | Positive numeric PHP amount up to 999999.99; max two decimals | Monthly Utility Budget must be greater than ₱0.00 and no more than ₱999,999.99. |
| Security Answer 1, 2, 3 | 2–50 letters/spaces only; no double spaces | `Security Answer {i} must contain 2–50 letters and spaces without double spaces.` |
| Privacy consent | Required checkbox; unchecked by default | You must agree to the Privacy Notice to register. |
| CAPTCHA | Six-character matching image challenge; max five minutes | CAPTCHA is incorrect or expired. Enter the characters shown in the new image. |
| CSRF | Valid server-side request token | Your form session expired. Refresh the page and try again. |
| Honeypot | Hidden field must be blank | Registration could not be completed. |

### Password meter decision

The browser shows **Weak / Medium / Strong**. Weak means shorter than the seeded minimum, longer than 64 characters, or fewer than three character classes. Medium means three or four classes within the valid length, but not meeting the Strong rule. Strong requires all four classes and at least `max(12, seeded minimum + 4)` characters. The **server rejects Weak**.

### General database and SMTP errors

The user-facing registration failure is generic (for unexpected database conflicts, configuration failure or email outages), and the technical details go to a private error log outside the web root. This is not a substitute for field-level messages in the normal validation path.

### Pending instructor confirmation

The four BillGuard-specific field definitions here match the prior proposed draft. If the signed Appendix A was revised, adjust this mapping and the validation code before enabling real registration.
