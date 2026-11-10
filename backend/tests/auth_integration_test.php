<?php
declare(strict_types=1);
/**
 * Phase 3 integration suite for an EPHEMERAL CI MySQL database.
 * This uses fictitious identities and deliberately disables external SMTP.
 * NEVER run it against a local real-account database.
 */
if (PHP_SAPI !== 'cli' || (string) getenv('BG_AUTH_INTEGRATION_CI') !== 'yes') {
    fwrite(STDERR, "This test runs only in the isolated CI database.\n");
    exit(1);
}
require_once dirname(__DIR__) . '/php/auth.php';
date_default_timezone_set('Asia/Manila');

$checks = 0;
$failures = 0;
function verify_auth(bool $pass, string $label): void
{
    global $checks, $failures;
    $checks++;
    echo ($pass ? '[OK]   ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$pass) $failures++;
}
try {
    $db = new PDO(
        'mysql:host=127.0.0.1;port=3306;dbname=billguard_dev;charset=utf8mb4',
        'root', (string) getenv('BG_TEST_DB_PASS'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES => false]
    );
    $db->exec("SET time_zone = '+08:00'");
    $_SERVER['REMOTE_ADDR'] = '198.51.100.101';
    $_SERVER['HTTP_USER_AGENT'] = 'BillGuard CI Security Test';
    $seed = bg_seed(['seed_d1' => 6, 'seed_d2' => 7]);
    if ($seed === null) throw new RuntimeException('CI test seed invalid');

    $pass = 'CorrectPasswordCI!20';
    $insert = $db->prepare("INSERT INTO users (id_number, first_name, family_name,
        home_address, email, username, password_hash, account_status,
        email_verified_at, consented_at) VALUES
        (:id, 'Test', 'Security', 'CI test account', :email, :username,
        :hash, 'active', NOW(), NOW())");
    $insert->execute([
        ':id' => '3333-3333', ':email' => 'security@example.test',
        ':username' => 'security_ci', ':hash' => password_hash($pass, PASSWORD_DEFAULT),
    ]);
    $config = ['smtp_host' => '127.0.0.1', 'smtp_port' => 1,
        'smtp_security' => 'none', 'smtp_user' => 'fake',
        'smtp_password' => 'CI-only-not-real', 'smtp_from' => 'ci@example.test'];

    $unknown = bg_login_password($db, $config, $seed, 'unknown_ci', 'wrong');
    $bad = bg_login_password($db, $config, $seed, 'security_ci', 'wrong');
    verify_auth(!$unknown['ok'] && $unknown['message'] === $bad['message'],
        'Unknown username and incorrect password share the same response');
    $second = bg_login_password($db, $config, $seed, 'security_ci', 'wrong');
    verify_auth(!$second['ok'] && ($second['failures'] ?? 0) === 2,
        'Second incorrect password increments failure count');

    $third = bg_login_password($db, $config, $seed, 'security_ci', 'wrong');
    verify_auth(!$third['ok'] && ($third['lockout'] ?? 0) === 21,
        'Third incorrect password triggers seeded Tier 1 lockout');
    $q = $db->prepare('SELECT consecutive_failures, lockout_until, last_alerted_tier
        FROM users WHERE id_number = :id');
    $q->execute([':id' => '3333-3333']);
    $row = $q->fetch();
    verify_auth((int) $row['consecutive_failures'] === 3
        && $row['lockout_until'] > bg_now()->format('Y-m-d H:i:s'),
        'Tier 1 lockout persisted server-side');
    verify_auth((int) $row['last_alerted_tier'] === 0,
        'Failed security email does not mark an alert as delivered');

    $blocked = bg_login_password($db, $config, $seed, 'security_ci', $pass);
    verify_auth(!$blocked['ok'] && isset($blocked['lockout']),
        'Even the correct password is rejected during a lockout');

    $db->exec("UPDATE users SET lockout_until = NULL WHERE id_number = '3333-3333'");
    $fourth = bg_login_password($db, $config, $seed, 'security_ci', 'wrong');
    verify_auth(!$fourth['ok'] && (int) ($fourth['failures'] ?? 0) === 4
        && bg_login_alert_tier(4) === 1,
        'After lockout expiry, failed Tier 1 security email is eligible for retry');

    $db->exec("UPDATE users SET consecutive_failures = 5, lockout_until = NULL
        WHERE id_number = '3333-3333'");
    $sixth = bg_login_password($db, $config, $seed, 'security_ci', 'wrong');
    verify_auth(!$sixth['ok'] && ($sixth['lockout'] ?? 0) === 42,
        'Sixth incorrect password triggers seeded Tier 2 lockout');

    $db->exec("UPDATE users SET consecutive_failures = 8, lockout_until = NULL
        WHERE id_number = '3333-3333'");
    $ninth = bg_login_password($db, $config, $seed, 'security_ci', 'wrong');
    verify_auth(!$ninth['ok'] && ($ninth['lockout'] ?? 0) === 84,
        'Ninth incorrect password triggers seeded Tier 3 lockout');

    // A valid email OTP, already issued BEFORE the lockout, must not bypass it.
    $hash = password_hash('123456', PASSWORD_DEFAULT);
    $expires = bg_now()->modify('+5 minutes')->format('Y-m-d H:i:s');
    $insertOtp = $db->prepare("INSERT INTO one_time_passwords
        (user_id, purpose, otp_hash, expires_at, created_at)
        VALUES ('3333-3333', 'login', :hash, :expires, :created)");
    $insertOtp->execute([':hash' => $hash, ':expires' => $expires,
        ':created' => bg_now()->format('Y-m-d H:i:s')]);
    $lockedOtp = bg_verify_login_otp($db, '3333-3333', '123456');
    verify_auth(!$lockedOtp['ok'], 'Login OTP cannot bypass account lockout');
    $attemptedResend = bg_send_otp($db, '3333-3333',
        'security@example.test', 'login', $config, $seed, true);
    verify_auth(!$attemptedResend['ok'],
        'Login OTP resend cannot bypass account lockout');

    $db->exec("UPDATE users SET lockout_until = NULL WHERE id_number = '3333-3333'");
    $badOtp = bg_verify_login_otp($db, '3333-3333', '000000');
    verify_auth(!$badOtp['ok'], 'Invalid login OTP is rejected');
    $goodOtp = bg_verify_login_otp($db, '3333-3333', '123456');
    verify_auth($goodOtp['ok'], 'Valid login OTP is accepted once');
    $reusedOtp = bg_verify_login_otp($db, '3333-3333', '123456');
    verify_auth(!$reusedOtp['ok'], 'Consumed login OTP is rejected on replay');
    $q->execute([':id' => '3333-3333']);
    $reset = $q->fetch();
    verify_auth((int) $reset['consecutive_failures'] === 0
        && $reset['lockout_until'] === null && (int) $reset['last_alerted_tier'] === 0,
        'Successful login OTP resets failure and lockout state');

    // Use an unrelated reserved test IP to avoid interfering with above account.
    $_SERVER['REMOTE_ADDR'] = '198.51.100.202';
    for ($i = 1; $i <= 14; $i++) {
        bg_log($db, 'LOGIN_FAILED', 'nonexistent_ci', null);
    }
    verify_auth(!bg_check_ip_threshold($db, $_SERVER['REMOTE_ADDR'], $seed),
        'IP not blocked below seeded ten-minute threshold');
    bg_log($db, 'LOGIN_FAILED', 'nonexistent_ci', null);
    verify_auth(bg_check_ip_threshold($db, $_SERVER['REMOTE_ADDR'], $seed),
        'IP blocked at seeded ten-minute threshold');
    verify_auth(bg_ip_is_blocked($db, $_SERVER['REMOTE_ADDR']),
        'IP blocking is persisted server-side');
    $ipDenied = bg_login_password($db, $config, $seed, 'security_ci', $pass);
    verify_auth(!$ipDenied['ok'] && str_contains($ipDenied['message'], 'IP address'),
        'Correct password cannot bypass IP block');

    echo "\n{$checks} CI security checks; {$failures} failed.\n";
} catch (Throwable $e) {
    // Test data is synthetic. Never log real secrets or full SQL error details.
    fwrite(STDERR, 'Phase 3 integration test error: '
        . get_class($e) . ' [code: ' . (string) $e->getCode() . "]\n");
    $failures++;
}
exit($failures ? 1 : 0);
