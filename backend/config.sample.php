<?php
/**
 * SAMPLE SETTINGS ONLY. No real credentials or personal student number here!
 *
 * Copy to backend/config.php (excluded from Git) and complete the local values.
 * Existing users: add the NEW seed/privacy/encryption keys to your current
 * backend/config.php instead of overwriting working DB and SMTP credentials.
 */
return [
    'db_host' => '127.0.0.1',
    'db_name' => 'billguard_dev',
    'db_user' => 'REPLACE_WITH_LOCAL_DB_USER',
    'db_password' => 'REPLACE_WITH_LOCAL_DB_PASSWORD',

    'smtp_host' => 'smtp.gmail.com',
    'smtp_port' => 587,
    'smtp_security' => 'tls',
    'smtp_user' => 'REPLACE_WITH_SENDER_EMAIL',
    'smtp_password' => 'REPLACE_WITH_SMTP_APP_PASSWORD',
    'smtp_from' => 'REPLACE_WITH_SENDER_EMAIL',
    'smtp_from_name' => 'BillGuard',
    'smtp_test_recipient' => 'REPLACE_WITH_YOUR_TEST_INBOX',

    // Section 2.2: only the LAST TWO DIGITS of the student number, kept PRIVATE.
    // Replace each null with a digit (integer 0..9), based on approved seed table.
    'seed_d1' => null, // second-to-last digit
    'seed_d2' => null, // last digit

    // Use a fresh 32-byte key; generate LOCALLY:
    // php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
    // Never store this key in Git, email, or screenshots. Back it up securely.
    'encryption_key' => 'REPLACE_WITH_BASE64_32_BYTE_KEY',

    // RA 10173: real data-protection contact and an HONEST, implemented retention policy.
    'privacy_contact' => 'REPLACE_WITH_PRIVACY_CONTACT_EMAIL',
    'privacy_retention' => 'REPLACE_WITH_RETENTION_AND_DELETION_POLICY',
];
