<?php
/**
 * SAMPLE ONLY. Copy to backend/config.php and enter LOCAL private values.
 * backend/config.php and vendor/ are excluded from Git.
 * Do NOT use these example values in a deployed application.
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

    // Will be generated securely before encryption work begins.
    'encryption_key' => 'REPLACE_WITH_PRIVATE_32_BYTE_KEY',
];
