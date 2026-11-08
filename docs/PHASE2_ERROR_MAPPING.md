# Current Simplified Registration — Exact Validation/Error Mapping

**This file documents what the current code actually validates.** The original IT 107 Section 4 requirements for ID Number, birthdate/age, split address, four utility-specific registration fields and registration CAPTCHA were deliberately removed from this revision at the project owner's request. This form does **not** comply with all those requirements without an approved amendment.

Rules are enforced by `backend/php/registration.php`. Browser feedback is provided by `frontend/js/registration.js`. PHP remains authoritative when JavaScript is disabled.

| Field | Rule | User-facing error |
| --- | --- | --- |
| First Name / Last Name | Any printable uppercase/lowercase letters, accents, punctuation, digits, or other special characters; length 2 to `20 + D2` Unicode characters | `{Field} must contain between 2 and {max} characters.` |
| First Name / Last Name | No control characters or invalid UTF-8 | `{Field} contains unsupported control characters.` |
| Home Address | Any printable letters, Unicode characters, numbers, or punctuation; 5–180 characters | Home Address must contain between 5 and 180 characters. |
| Home Address | No control characters or invalid UTF-8 | Home Address contains unsupported control characters. |
| Email | Valid email, max 100 characters, lowercase when saved | Please enter a valid email address (maximum 100 characters). |
| Email | Unique | This email is already registered. |
| Username | Starts with letter, letters/digits only, seeded `6+(D1 mod 3)` to `16+(D2 mod 5)` | `Username must start with a letter and contain {min}–{max} letters or digits.` |
| Username | Unique | This username is already taken. |
| Password | Length `8+(D2 mod 4)` to 64 | `Password must be between {min} and 64 characters.` |
| Password | Strength at least Medium (three character classes) | Password strength is Weak. Use at least three of: uppercase, lowercase, digits and symbols. |
| Confirm Password | Must match password | Passwords do not match. |
| Security Answer 1–3 | 2–50 letters/spaces, no doubled spaces | `Security Answer {i} must contain 2–50 letters and spaces without double spaces.` |
| Privacy Notice checkbox | Must be checked before processing personal data | You must agree to the Privacy Notice to register. |
| CSRF | Per-session request token valid | Your form session expired. Refresh the page and try again. |
| Honeypot | Hidden company website field must stay empty | Registration could not be completed. |

**Additional protections:** Passwords and recovery answers are saved as one-way hashes. The database assigns a random unique internal `xxxx-xxxx` primary key, **not** the manually entered ID Number required by the original handout. Email OTP remains mandatory to activate the account.

**On Login only:** CAPTCHA must match a server session image, and a Privacy Notice acknowledgment must be checked. Username/password is verified server-side; a second email OTP is required before the dashboard becomes accessible.

For a fresh-install schema and the required one-time migration of existing data, see [SIMPLE_REGISTER_MIGRATION.md](SIMPLE_REGISTER_MIGRATION.md).
