<?php
declare(strict_types=1);
/**
 * CLI-only Phase 2 preflight. Prints status labels but never private values.
 * From Project404: php backend/scripts/check_phase2.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/php/security.php';
bg_secure_start();

function report(string $label, bool $ok): bool
{
    fwrite(STDOUT, $label . ': ' . ($ok ? 'OK' : 'NOT READY') . PHP_EOL);
    return $ok;
}

fwrite(STDOUT, "BillGuard / Phase 2 configuration check" . PHP_EOL);
$good = true;
$good = report('PHP 8.1+', version_compare(PHP_VERSION, '8.1.0', '>=')) && $good;
$good = report('PDO / pdo_mysql', extension_loaded('pdo_mysql')) && $good;
$good = report('OpenSSL', extension_loaded('openssl')) && $good;
$good = report('mbstring', extension_loaded('mbstring')) && $good;
$good = report('GD CAPTCHA extension', extension_loaded('gd')) && $good;
$good = report('PHPMailer installed', is_file(dirname(__DIR__) . '/vendor/autoload.php')) && $good;

try {
    $config = billguard_config();
    $good = report('Personal seed configured', bg_seed($config) !== null) && $good;
    $contact = (string) ($config['privacy_contact'] ?? '');
    $retention = (string) ($config['privacy_retention'] ?? '');
    $privacyOkay = filter_var($contact, FILTER_VALIDATE_EMAIL) !== false
        && $retention !== '' && !str_contains($retention, 'REPLACE_WITH_');
    $good = report('Privacy contact and retention', $privacyOkay) && $good;
    $key = (string) ($config['encryption_key'] ?? '');
    $binary = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : false;
    $good = report('Private AES-256 key configured', is_string($binary) && strlen($binary) === 32) && $good;
    $good = report('SMTP and enrollment config', bg_registration_ready($config)) && $good;

    try {
        $db = billguard_db();
        $tables = (int) $db->query('SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE()')->fetchColumn();
        $good = report('MySQL connection and 9 tables', $tables >= 9) && $good;
    } catch (Throwable $e) {
        $good = report('MySQL connection and 9 tables', false) && $good;
    }
} catch (Throwable $e) {
    fwrite(STDOUT, "Private config file: NOT READY" . PHP_EOL);
    $good = false;
}

fwrite(STDOUT, $good
    ? "Preflight passed. Test actual registration, CAPTCHA and email OTP in a browser next.\n"
    : "Complete the missing items using docs/PHASE2_SETUP.md.\n");
exit($good ? 0 : 1);
