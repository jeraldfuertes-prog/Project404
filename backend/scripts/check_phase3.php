<?php
declare(strict_types=1);
/** CLI-only, read-only security preflight. Never prints secrets or user data. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/php/auth.php';
date_default_timezone_set('Asia/Manila');

function check_phase3_item(string $label, bool $ready): void
{
    global $allReady;
    fwrite(STDOUT, $label . ': ' . ($ready ? 'OK' : 'NOT READY') . PHP_EOL);
    $allReady = $allReady && $ready;
}

$allReady = true;
fwrite(STDOUT, "BillGuard / Phase 3 Security Preflight\n");
check_phase3_item('PHP 8.1+', version_compare(PHP_VERSION, '8.1.0', '>='));
check_phase3_item('pdo_mysql', extension_loaded('pdo_mysql'));
check_phase3_item('GD (CAPTCHA)', extension_loaded('gd'));
check_phase3_item('PHPMailer autoload', is_file(dirname(__DIR__) . '/vendor/autoload.php'));

try {
    $config = billguard_config();
    $seed = bg_seed($config);
    check_phase3_item('Approved seed present', $seed !== null);
    $mailReady = true;
    foreach (['smtp_host', 'smtp_user', 'smtp_password', 'smtp_from'] as $key) {
        $part = $config[$key] ?? null;
        if (!is_string($part) || trim($part) === '' || str_contains($part, 'REPLACE_WITH_')) {
            $mailReady = false;
        }
    }
    check_phase3_item('SMTP settings entered', $mailReady);
    if ($seed !== null) {
        fwrite(STDOUT, 'Tier 1 lockout (3 failures): ' . (15 + $seed['d1']) . " seconds\n");
        fwrite(STDOUT, 'Tier 2 lockout (6 failures): ' . (30 + 2 * $seed['d1']) . " seconds\n");
        fwrite(STDOUT, 'Tier 3 lockout (9 failures): ' . (60 + 4 * $seed['d1']) . " seconds\n");
        fwrite(STDOUT, 'Shared-IP failure threshold: ' . (8 + $seed['d2']) . " failures in 10 minutes\n");
        fwrite(STDOUT, 'Session idle timeout: ' . (5 + $seed['d1'] % 5) . " minutes\n");
        fwrite(STDOUT, 'Email OTP expiry: ' . $seed['otp_expiry'] . " minutes\n");
    }
    try {
        $db = billguard_db();
        check_phase3_item('MySQL connection', true);
        $q = $db->query("SELECT table_name AS checked_table FROM information_schema.tables
            WHERE table_schema = DATABASE()");
        $names = array_column($q->fetchAll(), 'checked_table');
        $needed = ['users', 'one_time_passwords', 'activity_log', 'ip_blocks'];
        check_phase3_item('Phase 3 security tables', count(array_diff($needed, $names)) === 0);
    } catch (Throwable $e) {
        check_phase3_item('MySQL connection and security tables', false);
    }
} catch (Throwable $e) {
    check_phase3_item('Private configuration', false);
}
fwrite(STDOUT, $allReady
    ? "Preflight passed. Continue with manual Chrome testing on a test account.\n"
    : "Fix only the NOT READY items. Never share backend/config.php or secrets.\n");
exit($allReady ? 0 : 1);
