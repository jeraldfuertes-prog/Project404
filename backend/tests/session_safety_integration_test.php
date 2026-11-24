<?php
declare(strict_types=1);
/** Disposable GitHub Actions MySQL ONLY. Never execute on real local data. */
if (PHP_SAPI !== 'cli' || getenv('BG_SESSION_SAFETY_CI') !== 'yes') {
    fwrite(STDERR, "This test runs only against disposable CI MySQL.\n");
    exit(1);
}
require_once dirname(__DIR__) . '/php/auth.php';
ob_start();
bg_secure_start();

$checks = 0;
$failed = 0;
function verify_session(bool $pass, string $description): void
{
    global $checks, $failed;
    $checks++;
    echo ($pass ? '[OK]   ' : '[FAIL] ') . $description . PHP_EOL;
    if (!$pass) $failed++;
}
try {
    $db = new PDO(
        'mysql:host=127.0.0.1;port=3306;dbname=billguard_dev;charset=utf8mb4',
        'root',
        (string) getenv('BG_TEST_DB_PASS'),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $db->exec("SET time_zone = '+08:00'");
    $seed = bg_seed(['seed_d1' => 4, 'seed_d2' => 7]);
    if ($seed === null) throw new RuntimeException('CI seed setup failed.');
    $id = '9595-4545';
    $hash = password_hash('CI-Only-Password!9', PASSWORD_DEFAULT);
    $insert = $db->prepare("INSERT INTO users
        (id_number, first_name, family_name, home_address, email, username,
            password_hash, account_status, email_verified_at, consented_at)
        VALUES (:id, 'Session', 'CI', 'Synthetic address', 'session@example.test',
            'SessionTest95', :hash, 'active', NOW(), NOW())");
    $insert->execute([':id' => $id, ':hash' => $hash]);

    $_SESSION = [
        'user_id' => $id,
        'auth_password_fingerprint' => bg_auth_password_fingerprint($hash),
        'authenticated_at' => time() - 30,
        'last_activity' => time() - 120,
    ];
    $first = (int) $_SESSION['last_activity'];
    $passive = bg_auth_user($db, $seed, false);
    verify_session($passive !== null && $passive['id_number'] === $id,
        'Passive session status confirms valid active account');
    verify_session((int) $_SESSION['last_activity'] === $first,
        'GET/status check does NOT renew inactivity deadline');
    verify_session(bg_session_remaining_seconds((int) $_SESSION['last_activity'], $seed, time())
        <= 780, 'Passive status does not add lost inactivity time');

    $renewed = bg_auth_user($db, $seed, true);
    verify_session($renewed !== null && $renewed['id_number'] === $id,
        'Explicit server-side renewal verifies active authenticated account');
    verify_session((int) $_SESSION['last_activity'] > $first,
        'Renewal sets new last_activity timestamp');
    verify_session(bg_session_remaining_seconds((int) $_SESSION['last_activity'], $seed, time())
        >= 899, 'Renewal restores almost full 15-minute inactivity period');

    // Testing protected-page access after a simulated password reset:
    $newHash = password_hash('CI-Only-NewPassword!9', PASSWORD_DEFAULT);
    $update = $db->prepare('UPDATE users SET password_hash = :hash WHERE id_number = :id');
    $update->execute([':hash' => $newHash, ':id' => $id]);
    verify_session(bg_auth_user($db, $seed, false) === null,
        'Passive status rejects session revoked by password reset');

    // Restore a logged-in state to check hard inactivity expiry.
    bg_secure_start();
    $_SESSION = [
        'user_id' => $id,
        'auth_password_fingerprint' => bg_auth_password_fingerprint($newHash),
        'authenticated_at' => time() - 1000,
        'last_activity' => time() - bg_idle_timeout_seconds($seed),
    ];
    verify_session(bg_auth_user($db, $seed, true) === null,
        'A Stay Signed In attempt after expiry must NOT restore authentication');
    verify_session(empty($_SESSION['user_id']),
        'Expired session destroys authenticated identity');

    echo PHP_EOL . "{$checks} integration checks; {$failed} failed." . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Phase 4.5 CI session check failed: '
        . get_class($e) . ' [code ' . (string) $e->getCode() . ']' . PHP_EOL);
    $failed++;
}
ob_end_flush();
exit($failed ? 1 : 0);
