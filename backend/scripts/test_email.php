<?php
declare(strict_types=1);

/**
 * CLI SMTP smoke test; same mail transport configuration as OTP delivery.
 * Command: php backend/scripts/test_email.php
 * Never print credentials, 6-digit OTP values or raw SMTP exception details.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/php/mailer.php';
date_default_timezone_set('Asia/Manila');

try {
    $config = billguard_config();
    foreach (['smtp_host', 'smtp_port', 'smtp_security', 'smtp_user',
              'smtp_password', 'smtp_from', 'smtp_test_recipient'] as $key) {
        if (!isset($config[$key]) || trim((string) $config[$key]) === ''
            || str_contains((string) $config[$key], 'REPLACE_WITH_')) {
            throw new RuntimeException('SMTP configuration missing.');
        }
    }
    $recipient = (string) $config['smtp_test_recipient'];
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('SMTP test recipient address invalid.');
    }

    $mail = bg_configured_mailer($config);
    $mail->addAddress($recipient);
    $mail->Subject = 'BillGuard - SMTP Delivery Test';
    $mail->Body = "This is a transport test for BillGuard IT 107.\n"
        . 'Sent at ' . date('Y-m-d H:i:s') . " Philippine time.\n"
        . "No OTP, login credential or personal account data is included.";
    $mail->send();
    fwrite(STDOUT, "SMTP test message accepted. Confirm arrival in the configured test inbox.\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'SMTP test failed. Safe diagnostic category: '
        . bg_mail_error_category($error) . ".\n");
    fwrite(STDERR, "Check the sender App Password, SMTP access, and test recipient privately.\n");
    exit(1);
}
