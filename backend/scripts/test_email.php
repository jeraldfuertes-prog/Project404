<?php
declare(strict_types=1);

/**
 * CLI SMTP smoke test. Never call this from a browser.
 * Install PHPMailer first: cd backend && composer install
 * Then: php backend/scripts/test_email.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
date_default_timezone_set('Asia/Manila');

require_once dirname(__DIR__) . '/php/db.php';
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "PHPMailer not installed. Run composer install in backend/.\n");
    exit(1);
}
require_once $autoload;

try {
    $config = billguard_config();
    $keys = [
        'smtp_host', 'smtp_port', 'smtp_security', 'smtp_user',
        'smtp_password', 'smtp_from', 'smtp_from_name', 'smtp_test_recipient'
    ];
    foreach ($keys as $key) {
        if (!isset($config[$key]) || (string) $config[$key] === ''
            || str_contains((string) $config[$key], 'REPLACE_WITH_')) {
            throw new RuntimeException('SMTP configuration is incomplete.');
        }
    }

    $recipient = $config['smtp_test_recipient'];
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)
        || !filter_var($config['smtp_from'], FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('SMTP sender or test recipient email is not valid.');
    }

    $security = strtolower((string) $config['smtp_security']);
    if (!in_array($security, ['tls', 'ssl', 'none'], true)) {
        throw new RuntimeException('smtp_security must be tls, ssl, or none.');
    }

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = (string) $config['smtp_host'];
    $mail->Port = (int) $config['smtp_port'];
    $mail->SMTPAuth = true;
    $mail->Username = (string) $config['smtp_user'];
    $mail->Password = (string) $config['smtp_password'];
    if ($security === 'tls') {
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    } elseif ($security === 'ssl') {
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    } else {
        // For an instructor-approved local mail catcher only.
        $mail->SMTPSecure = '';
        $mail->SMTPAutoTLS = false;
    }
    $mail->CharSet = 'UTF-8';
    $mail->setFrom((string) $config['smtp_from'], (string) $config['smtp_from_name']);
    $mail->addAddress((string) $recipient);
    $mail->Subject = 'BillGuard - Phase 1 Email Test';
    $mail->Body = "This is a setup test for BillGuard IT 107.\n"
        . "Sent at " . date('Y-m-d H:i:s') . " Philippine time.\n"
        . "No OTP or personal account data is included.";
    $mail->send();
    fwrite(STDOUT, "SMTP test message accepted. Check the configured test inbox to confirm delivery.\n");
} catch (Throwable $error) {
    // Do not print server credentials or transport debug output.
    fwrite(STDERR, "SMTP test failed. Check PHPMailer, SMTP settings, app password, and network access.\n");
    exit(1);
}
