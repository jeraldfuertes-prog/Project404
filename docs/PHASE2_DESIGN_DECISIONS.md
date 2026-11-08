> **SCOPE UPDATE (Nov 8):** This document describes the initial fully specified Phase 2 form. The project owner changed registration to First Name, Last Name, a single Home Address, Email and account credentials, with recovery questions folded and an inline privacy checkbox. CAPTCHA now belongs to Login. Four utility-specific fields and ID/birthdate were removed from signup. The implementation therefore **does not meet all original Section 4/7 course requirements**; [see migration and approval notes](SIMPLE_REGISTER_MIGRATION.md). Some design decisions below describe the superseded design and must be revisited before final submission.\n\n# Phase 2 — Design Decisions (Required “Your call” Notes)

## Why PHP rather than a JavaScript-only form?

A browser can remove client validators, edit a dropdown, change the CAPTCHA input or forge a POST request. Therefore, JavaScript offers live guidance, while PHP independently validates and returns specific per-field errors.

## Password hashing

We chose PHP's `password_hash($password, PASSWORD_DEFAULT)` and `password_verify`. Hashes are one-way and salted; no plaintext password is stored or emailed. The algorithm may change across PHP releases, and the database column is `VARCHAR(255)` to accommodate future formats.

## Password strength classification

- **Weak:** password shorter than seeded minimum, longer than 64, or has fewer than three classes (upper/lower/digits/symbols). **Refuse**.
- **Medium:** valid length and three or more classes, but does not meet Strong.
- **Strong:** all four character classes and length at least `max(12, seeded minimum + 4)`.

The frontend displays the meter, but PHP calculates strength independently.

## Why hash security answers?

Answers are personal information. Storing lowercase-normalized one-way hashes means a database reader cannot trivially learn them. Answers are lowercased **before hashing**, so the case of a correct answer does not matter. The three exact questions are chosen from the personal seed.

## CAPTCHA selection

We selected **custom PHP GD**: six random uppercase alphanumeric characters, small positional variation, noise lines/pixels, user refresh button, short session lifetime, one-time validation and case-insensitive comparison on the server. All failed attempts invalidate the displayed challenge. The PHP GD extension must be enabled locally. For accessible alternatives beyond the academic image CAPTCHA, a future production deployment should provide a usable nonvisual verification method.

## OTP delivery and verification

PHP `random_int` generates six digits, including possible leading zeroes. The code is stored only as a password hash and bound to purpose `register`. A correct OTP is single-use, and its expiry is seeded. Three wrong entries cancel it. New codes invalidate previous ones. Resends have a server-enforced 60-second cooldown and are capped at three within 15 minutes. The email includes purpose, code, PHT expiry and a security warning. We use PHPMailer and private SMTP configuration.

## When is an account authenticated?

**Not during registration.** An account starts in `pending` status and only becomes `active` on correct registration OTP. Neither account creation nor successful registration email verification creates an authenticated user login session. The separate login-and-OTP authentication will be implemented in Phase 3.

## Privacy and retention

A required unchecked consent box links to the Privacy Notice (RA 10173). The consent timestamp is stored. Registration cannot be enabled without a real privacy contact email and a retention-policy statement in private configuration. The project owner must also implement whatever deletion or retention processes the statement promises.

## Sensitive data encryption

Utility Account Number is encrypted via OpenSSL AES-256-GCM, using a new cryptographically random 12-byte IV and a 16-byte authentication tag for every value. The key is a 32-byte value kept in the ignored `backend/config.php` outside the web root. Authenticated ownership verification is required before decryption in future bill-related pages. Passwords, security answers, and OTPs use hashing, **not** reversible encryption.

## CSRF, sessions, privacy of errors, and activity log

Every Phase 2 POST contains a random session CSRF token and is rejected server-side when missing. Session cookies use HttpOnly and SameSite Lax; Secure is set when HTTPS is active. Browser pages cannot access `backend/` when the correct document root is configured. Public errors never include SQL details, SMTP credentials, or raw exceptions. Append-only events such as `REGISTERED`, `OTP_SENT`, `OTP_FAILED`, `ACCOUNT_VERIFIED`, `CAPTCHA_FAILED`, and `CSRF_REJECTED` are inserted through prepared queries.

## Instructor-specific parameters

The length limits, three question selections and OTP expiry are computed from **D1/D2** in `backend/config.php` (not committed). These must match the signed personal seed worksheet. The four utility fields were drafted earlier and need to match the signed Appendix A before collecting real data.
