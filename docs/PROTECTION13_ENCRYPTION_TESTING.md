# Protection #13 — AES-256 encryption at rest (BillGuard)

**Security 1 handout:** Section 12, Protection #13 (page 19): Encrypt at least
one sensitive system field with AES-256 and a random IV. Decrypt only to show
it to its owner. This is implemented for **Utility Account Number**.

**Status:** Code implemented; CI and local browser proof are required before
marking the protection fully verified.

## What is implemented

- The existing **users** table has three required binary columns:
  **utility_account_ciphertext**, **utility_account_iv** and
  **utility_account_tag**. The existing schema or simplified-registration
  migration already includes them; no new migration is required.
- **My Profile** offers an optional owner-only Utility Account Number form.
  The exact rule is **8–12 ASCII digits**.
- **Save Encrypted Number:** PHP authenticates the session, checks CSRF,
  validates the number, generates a fresh random 12-byte IV and encrypts
  using AES-256-GCM with a 16-byte authentication tag. It writes only
  binary ciphertext, IV and tag to MySQL using prepared SQL.
- **Reveal My Number:** Only the currently authenticated owner can reveal
  their number using a CSRF-protected POST. The value appears only in
  the protected, no-store response and is escaped for HTML.
- **Remove Saved Number:** Owner-only CSRF-protected POST clears all three
  encrypted columns. Log events for saved, revealed and removed do not
  contain the number, key or ciphertext.
- **Private encryption key:** Read only from ignored backend/config.php,
  outside the frontend document root. Invalid or missing keys **fail closed**
  without storing plaintext. Keep a safe backup of the key outside GitHub.
- The backend adds a separate authenticated ownership guard to prevent
  forged ID parameters from reading or modifying another account.

**Scope note:** Implementing a field in Profile does not restore the
other mandatory registration fields from the original IT 107 handout.
That remains a separate compliance task.

## Windows local testing

### 1. Pull the latest source

In VS Code PowerShell:

~~~powershell
cd C:\Users\Administrator\Desktop\Project404
git status --short
git pull origin main
~~~

If a conflict is still in progress, resolve it first. Do not force-reset
your project files.

### 2. Add a private AES-256 key (only if missing)

Look inside your PRIVATE backend/config.php. If an encryption_key already
exists and is valid, **retain it**. Changing it makes previously encrypted
records unreadable.

To create a new 32-byte key in a private terminal:

~~~powershell
& "D:\php83\php.exe" -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
~~~

The output is secret. Set this array entry in backend/config.php (not Git):

~~~php
'encryption_key' => 'base64:PASTE_GENERATED_KEY_HERE',
~~~

Paste the complete generated string including its **base64:** prefix.
Never paste a key into chat, Git, screenshots or public logs.

### 3. Test key setup, database columns and cryptography rules

~~~powershell
& "D:\php83\php.exe" backend\scripts\check_utility_encryption.php
& "D:\php83\php.exe" backend\tests\utility_account_rules_test.php
~~~

Expected: **Private AES-256 key: OK**, **MySQL connection: OK**,
**Encrypted users columns: OK**, **Protection 13 local setup: READY**,
and **0 failed** in the encryption rules. Do not run the synthetic MySQL integration test locally:
it is reserved for GitHub Actions' disposable database.

Start the PHP server if needed:

~~~powershell
& "D:\php83\php.exe" -S localhost:8000 -t frontend
~~~

### 4. Test in Chrome

1. Login through username, password, CAPTCHA and email OTP.
2. Open **My Profile > ENCRYPTED UTILITY ACCOUNT**.
3. Enter fictitious **12345678**, choose **Save Encrypted Number**. The
   normal page must show only **encrypted at rest**, not the plaintext.
4. Choose **Reveal My Number**. The logged-in owner should see the same
   fake number on this one response. Refresh or navigate away to hide it.
5. Replace with fictitious **87654321**, save, then reveal. The new number
   appears and the old number no longer does.
6. Select **Remove Saved Number**. The status becomes **None saved**.
7. Log out and open profile.php directly. You must be redirected to Login.
8. Login using a separate test user. It must not reveal or change the
   other user's utility account number.
9. Submit bad values such as 1234567, 1234567890123, letters or hyphens.
   The server must reject them.
10. Remove the CSRF token in DevTools before submission. Expect HTTP 403.

Use fake digits for demonstration. Never submit screenshots showing a
real utility account number or private keys.

### 5. Show the encrypted database value in phpMyAdmin

In phpMyAdmin > billguard_dev > SQL, after saving fake digits:

~~~sql
SELECT id_number,
       HEX(utility_account_ciphertext) AS encrypted_hex,
       OCTET_LENGTH(utility_account_iv) AS iv_bytes,
       OCTET_LENGTH(utility_account_tag) AS tag_bytes
FROM users
WHERE utility_account_ciphertext IS NOT NULL;
~~~

Expected: **encrypted_hex is unreadable** (it is not the input digits),
**iv_bytes = 12** and **tag_bytes = 16**. Saving the same number a second
time must result in a different IV. After removal all three encrypted
columns become NULL for that owner.

## Instructor demonstration explanation

- Hashes are one-way: appropriate for passwords, OTPs and security answers.
  Encryption is reversible: necessary for the account number the owner
  needs to see again.
- AES-256-GCM uses a secret 32-byte key; the authentication tag detects
  tampering.
- Random 12-byte IVs stop repeated values from producing identical
  ciphertext on every save.
- The decryption function is only called after session and owner checks,
  triggered by an explicit CSRF-protected Reveal request.
- The key must stay outside the web root and outside Git history; losing
  the key makes stored numbers unreadable.

**Note:** This protection is separate from the unresolved original-handout
registration fields, seeded idle timeout and admin security monitoring.
