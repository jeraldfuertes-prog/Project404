<?php
declare(strict_types=1);
/**
 * Admin audit integration checks against disposable GitHub CI MySQL ONLY.
 * Do not run on a live database; creates synthetic user accounts.
 */
if (PHP_SAPI !== 'cli' || getenv('BG_ADMIN_AUDIT_CI') !== 'yes') {
    fwrite(STDERR, "This integration suite is restricted to disposable CI MySQL.\n");
    exit(1);
}
require_once dirname(__DIR__) . '/php/admin_audit.php';
ob_start();
bg_secure_start();

$checks = 0;
$failures = 0;
function audit_verify(bool $ok, string $description): void
{
    global $checks, $failures;
    $checks++;
    echo ($ok ? '[OK] ' : '[FAIL] ') . $description . PHP_EOL;
    if (!$ok) $failures++;
}
try {
    $pass = (string) getenv('BG_TEST_DB_PASS');
    if ($pass === '') throw new RuntimeException('Test database password not configured.');
    $db = new PDO(
        'mysql:host=127.0.0.1;port=3306;dbname=billguard_dev;charset=utf8mb4',
        'root', $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $db->exec("SET time_zone = '+08:00'");
    $seed = bg_seed(['seed_d1' => 4, 'seed_d2' => 7]);
    if ($seed === null) throw new RuntimeException('Test seed absent.');

    $insert = $db->prepare("INSERT INTO users
        (id_number, first_name, family_name, home_address, email, username,
         password_hash, role, account_status, email_verified_at, consented_at)
        VALUES (:id, 'Activity', 'Test', 'Synthetic CI address', :email, :uname,
                :hash, :role, 'active', NOW(), NOW())");
    $ordinary = '7272-9002';
    $adminId = '7272-9001';
    $ordinaryHash = password_hash('CI-Ordinary-Password!9', PASSWORD_DEFAULT);
    $adminHash = password_hash('CI-Admin-Password!9', PASSWORD_DEFAULT);
    $insert->execute([
        ':id' => $ordinary, ':email' => 'audit-user@example.test',
        ':uname' => 'AuditUser92', ':hash' => $ordinaryHash, ':role' => 'user',
    ]);
    $insert->execute([
        ':id' => $adminId, ':email' => 'audit-admin@example.test',
        ':uname' => 'AuditAdmin91', ':hash' => $adminHash, ':role' => 'admin',
    ]);

    $_SERVER['REMOTE_ADDR'] = '198.51.100.222';
    $_SERVER['HTTP_USER_AGENT'] = 'CI Safe Admin Audit';
    $_SESSION = [];
    audit_verify(bg_admin_audit_authorize($db, $seed, 'page') === null,
        'Anonymous visitor cannot view admin activity page');
    audit_verify(bg_admin_audit_authorize($db, $seed, 'feed') === null,
        'Anonymous visitor cannot view JSON feed');

    $_SESSION = [
        'user_id' => $ordinary,
        'auth_password_fingerprint' => bg_auth_password_fingerprint($ordinaryHash),
        'last_activity' => time(),
    ];
    audit_verify(bg_admin_audit_authorize($db, $seed, 'page') === null,
        'Logged-in ordinary account cannot view admin page');
    audit_verify(bg_admin_audit_authorize($db, $seed, 'feed') === null,
        'Logged-in ordinary account cannot read JSON activity feed');
    $rejected = false;
    try {
        bg_admin_audit_viewed($db, ['id_number' => $ordinary, 'role' => 'user'], 'page');
    } catch (InvalidArgumentException $e) {
        $rejected = true;
    }
    audit_verify($rejected, 'Ordinary role cannot generate successful admin view event');

    $_SESSION = [
        'user_id' => $adminId,
        'auth_password_fingerprint' => bg_auth_password_fingerprint($adminHash),
        'last_activity' => time(),
    ];
    $admin = bg_admin_audit_authorize($db, $seed, 'page');
    audit_verify($admin !== null && $admin['role'] === 'admin',
        'Verified admin role authorized on activity page');
    $feedAdmin = bg_admin_audit_authorize($db, $seed, 'feed');
    audit_verify($feedAdmin !== null && $feedAdmin['id_number'] === $adminId,
        'Verified admin role authorized on JSON activity feed');

    $events = bg_admin_audit_latest($db);
    audit_verify(count($events) > 0 && count($events) <= 50,
        'Admin read-only query returns at most latest 50 events');
    audit_verify(!array_key_exists('details', $events[0])
        && !array_key_exists('user_id', $events[0]),
        'Admin view does not expose detailed metadata or account identifier');
    bg_admin_audit_viewed($db, $admin, 'page');
    bg_admin_audit_viewed($db, $feedAdmin, 'feed');

    $audit = $db->prepare("SELECT event_type, user_id, details FROM activity_log
        WHERE event_type IN ('ADMIN_ACCESS_DENIED', 'ADMIN_VIEWED_DASHBOARD')
          AND ip_address = :ip ORDER BY id");
    $audit->execute([':ip' => $_SERVER['REMOTE_ADDR']]);
    $adminAudit = $audit->fetchAll();
    $denied = array_values(array_filter($adminAudit,
        static fn (array $x): bool => $x['event_type'] === 'ADMIN_ACCESS_DENIED'));
    $viewed = array_values(array_filter($adminAudit,
        static fn (array $x): bool => $x['event_type'] === 'ADMIN_VIEWED_DASHBOARD'));
    audit_verify(count($denied) === 4, 'All four anonymous and user denials logged');
    audit_verify(count($viewed) === 2, 'Page and feed admin views logged once each');
    audit_verify(count(array_filter($viewed,
        static fn(array $x): bool => $x['user_id'] === $adminId)) === 2,
        'Successful admin views are tied to verified admin identity');
    audit_verify(count(array_filter($denied,
        static fn(array $x): bool => $x['user_id'] === null)) === 2,
        'Anonymous denial never invents an authenticated account ID');
    $logText = json_encode($adminAudit, JSON_THROW_ON_ERROR);
    audit_verify(!str_contains($logText, 'CI-Admin-Password')
        && !str_contains($logText, 'CI-Ordinary-Password'),
        'Admin audit events do not contain credentials');

    // Password reset invalidates the bound session fingerprint.
    $newHash = password_hash('CI-Admin-New-Password!9', PASSWORD_DEFAULT);
    $update = $db->prepare('UPDATE users SET password_hash = :hash WHERE id_number = :id');
    $update->execute([':hash' => $newHash, ':id' => $adminId]);
    audit_verify(bg_admin_audit_authorize($db, $seed, 'feed') === null,
        'Old admin session denied after a password change');

    bg_secure_start();
    $_SESSION = [
        'user_id' => $adminId,
        'auth_password_fingerprint' => bg_auth_password_fingerprint($newHash),
        'last_activity' => time() - bg_idle_timeout_seconds($seed),
    ];
    audit_verify(bg_admin_audit_authorize($db, $seed, 'page') === null,
        'Expired admin session denied despite correct stored role');
    audit_verify(empty($_SESSION['user_id']),
        'Expired admin session is cleared before a protected log read');

    echo "{$checks} admin audit integration checks; {$failures} failed." . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Admin audit CI error: ' . get_class($e)
        . ' [code ' . (string) $e->getCode() . ']' . PHP_EOL);
    $failures++;
}
ob_end_flush();
exit($failures ? 1 : 0);
