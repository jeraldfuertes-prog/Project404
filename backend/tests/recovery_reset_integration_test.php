<?php
declare(strict_types=1);
/**
 * Phase 4.4: disposable GitHub Actions MySQL only.
 * NEVER run this integration suite on an existing local billguard_dev.
 */
if (PHP_SAPI !== 'cli' || getenv('BG_RECOVERY_RESET_CI') !== 'yes') {
    fwrite(STDERR, "Run this test only in isolated CI MySQL.\n");
    exit(1);
}
require_once dirname(__DIR__) . '/php/recovery_reset.php';

$checks = 0;
$failures = 0;
function assert_reset(bool $pass, string $label): void
{
    global $checks, $failures;
    $checks++;
    echo ($pass ? '[OK]   ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$pass) $failures++;
}
try {
    date_default_timezone_set('Asia/Manila');
    $db = new PDO(
        'mysql:host=127.0.0.1;port=3306;dbname=billguard_dev;charset=utf8mb4',
        'root', (string) getenv('BG_TEST_DB_PASS'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES => false]
    );
    $db->exec("SET time_zone = '+08:00'");
    $_SERVER['REMOTE_ADDR'] = '198.51.100.174';
    $_SERVER['HTTP_USER_AGENT'] = 'BillGuard CI 4.4 Reset';
    $seed = bg_seed(['seed_d1' => 4, 'seed_d2' => 7]);
    if ($seed === null) throw new RuntimeException('Seed invalid.');
    $smtp = ['smtp_host' => '127.0.0.1', 'smtp_port' => 1,
        'smtp_security' => 'none', 'smtp_user' => 'fake',
        'smtp_password' => 'CI-fake', 'smtp_from' => 'ci@example.test'];

    $uid = '9191-4444';
    $currentPassword = 'CurrentPhaseFour9!';
    $previous1 = 'PreviousOnePhase8!';
    $previous2 = 'PreviousTwoPhase7!';
    $previous3 = 'PreviousThreePhase6!';
    $previous4 = 'PreviousFourPhase5!';
    $currentHash = password_hash($currentPassword, PASSWORD_DEFAULT);
    $q = $db->prepare("INSERT INTO users (id_number, first_name, family_name,
        home_address, email, username, password_hash, account_status,
        email_verified_at, consented_at)
        VALUES (:uid, 'CI', 'Reset', 'Fictional test location',
                'reset4@example.test', 'ResetCI444', :hash, 'active', NOW(), NOW())");
    $q->execute([':uid' => $uid, ':hash' => $currentHash]);

    $past = $db->prepare('INSERT INTO password_history
        (user_id, password_hash) VALUES (:uid, :hash)');
    foreach ([$previous4, $previous3, $previous2, $previous1] as $value) {
        $past->execute([':uid' => $uid,
            ':hash' => password_hash($value, PASSWORD_DEFAULT)]);
    }
    $time = bg_now();
    $otp = $db->prepare("INSERT INTO one_time_passwords
        (user_id, purpose, otp_hash, expires_at, consumed_at, created_at)
        VALUES (:uid, 'reset', :hash, :expires, :consumed, :created)");
    $otp->execute([
        ':uid' => $uid, ':hash' => password_hash('123456', PASSWORD_DEFAULT),
        ':expires' => $time->modify('+4 minutes')->format('Y-m-d H:i:s'),
        ':consumed' => $time->format('Y-m-d H:i:s'),
        ':created' => $time->modify('-20 seconds')->format('Y-m-d H:i:s'),
    ]);
    $otpId = (int) $db->lastInsertId();
    $loginOtp = $db->prepare("INSERT INTO one_time_passwords
        (user_id, purpose, otp_hash, expires_at, created_at)
        VALUES (:uid, 'login', :hash, :expires, :created)");
    $loginOtp->execute([
        ':uid' => $uid, ':hash' => password_hash('654321', PASSWORD_DEFAULT),
        ':expires' => $time->modify('+4 minutes')->format('Y-m-d H:i:s'),
        ':created' => $time->format('Y-m-d H:i:s'),
    ]);

    $flow = [
        'stage' => 'identity_verified',
        'started_at' => time() - 10,
        'identity_verified_at' => time() - 5,
        'candidate_id' => $uid,
        'code_sent' => true,
        'reset_otp_id' => $otpId,
        'password_fingerprint' => bg_auth_password_fingerprint($currentHash),
    ];
    assert_reset(bg_reset_flow_ready($flow, time()),
        'Verified email and question flow accepts bound OTP ID and password version');

    foreach ([
        'Current password' => $currentPassword,
        'Most recent previous password' => $previous1,
        'Second previous password' => $previous2,
        'Third previous password' => $previous3,
    ] as $label => $candidate) {
        $attempt = bg_reset_password($db, $flow, $seed, $smtp, $candidate, $candidate);
        assert_reset(!$attempt['ok'] && str_contains($attempt['message'], 'last three'),
            $label . ' cannot be reused');
    }
    $before = $db->prepare('SELECT password_hash FROM users WHERE id_number = :uid');
    $before->execute([':uid' => $uid]);
    assert_reset(hash_equals($currentHash, $before->fetchColumn()),
        'Rejected reuse never changes account password');

    $badFlow = ['stage' => 'identity_submitted'] + $flow;
    $new = 'BrandNewResetPassword9!';
    assert_reset(!bg_reset_password($db, $badFlow, $seed, $smtp, $new, $new)['ok'],
        'Failed recovery proof cannot change password');
    assert_reset(!bg_reset_password($db, [
        'reset_otp_id' => $otpId + 99] + $flow, $seed, $smtp, $new, $new)['ok'],
        'Wrong OTP ID cannot change password');

    $lock = $db->prepare('UPDATE users SET lockout_until = :until WHERE id_number = :uid');
    $lock->execute([':until' => $time->modify('+2 minutes')->format('Y-m-d H:i:s'),
        ':uid' => $uid]);
    assert_reset(!bg_reset_password($db, $flow, $seed, $smtp, $new, $new)['ok'],
        'Locked account cannot change password');
    $db->exec("UPDATE users SET lockout_until = NULL WHERE id_number = '9191-4444'");

    $addBlock = $db->prepare('INSERT INTO ip_blocks
        (ip_address, blocked_until, failure_count) VALUES (:ip, :until, 20)');
    $addBlock->execute([
        ':ip' => $_SERVER['REMOTE_ADDR'],
        ':until' => $time->modify('+10 minutes')->format('Y-m-d H:i:s'),
    ]);
    assert_reset(!bg_reset_password($db, $flow, $seed, $smtp, $new, $new)['ok'],
        'Blocked IP cannot change password');
    $_SERVER['REMOTE_ADDR'] = '198.51.100.175';

    // Oldest previous password lies outside last-three policy. The reset
    // succeeds even though SMTP is deliberately unavailable in CI.
    $success = bg_reset_password($db, $flow, $seed, $smtp,
        $previous4, $previous4);
    assert_reset($success['ok'], 'New password (older than last-three history) is accepted');
    $after = $db->prepare('SELECT password_hash FROM users WHERE id_number = :uid');
    $after->execute([':uid' => $uid]);
    $newHash = $after->fetchColumn();
    assert_reset(is_string($newHash) && password_verify($previous4, $newHash),
        'Password hash changed atomically, despite unavailable SMTP');
    assert_reset(!password_verify($currentPassword, $newHash),
        'Original password no longer matches after reset');
    assert_reset(!hash_equals($flow['password_fingerprint'],
        bg_auth_password_fingerprint($newHash)),
        'Other logged-in sessions with previous password version are revoked');

    $pastQ = $db->prepare('SELECT password_hash FROM password_history
        WHERE user_id = :uid ORDER BY id DESC');
    $pastQ->execute([':uid' => $uid]);
    $hashes = $pastQ->fetchAll(PDO::FETCH_COLUMN, 0);
    assert_reset(count($hashes) === 3,
        'Only three previous password hashes retained');
    assert_reset(password_verify($currentPassword, $hashes[0]),
        'Former current password is most recent history item');
    assert_reset(password_verify($previous2, $hashes[2]),
        'Third retained history item is correct');

    $otpQ = $db->prepare("SELECT cancelled_at FROM one_time_passwords
        WHERE user_id = :uid AND purpose = :purpose ORDER BY id DESC LIMIT 1");
    $otpQ->execute([':uid' => $uid, ':purpose' => 'reset']);
    assert_reset($otpQ->fetchColumn() !== null, 'Consumed reset OTP retired after password change');
    $otpQ->execute([':uid' => $uid, ':purpose' => 'login']);
    assert_reset($otpQ->fetchColumn() !== null, 'Stale pending login OTP cancelled after password change');

    $repeat = bg_reset_password($db, $flow, $seed, $smtp,
        'AnotherUniqueReset9!', 'AnotherUniqueReset9!');
    assert_reset(!$repeat['ok'], 'Original reset proof cannot be reused');
    $secondQ = $db->prepare('SELECT COUNT(*) FROM activity_log
        WHERE event_type = :event AND user_id = :uid');
    $secondQ->execute([':event' => 'PASSWORD_RESET', ':uid' => $uid]);
    assert_reset((int) $secondQ->fetchColumn() === 1,
        'Exactly one successful reset is recorded');
    $secondQ->execute([':event' => 'PASSWORD_CHANGED', ':uid' => $uid]);
    assert_reset((int) $secondQ->fetchColumn() === 1,
        'Exactly one canonical PASSWORD_CHANGED event follows a valid reset');
    $detailsQ = $db->prepare("SELECT details FROM activity_log
        WHERE event_type = 'PASSWORD_CHANGED' AND user_id = :uid");
    $detailsQ->execute([':uid' => $uid]);
    $auditDetails = (string) $detailsQ->fetchColumn();
    assert_reset(!str_contains($auditDetails, $new)
        && !str_contains($auditDetails, $previous4)
        && !str_contains($auditDetails, '123456'),
        'Password-change log details never contain passwords or OTPs');
    $db->exec("UPDATE users SET account_status='disabled' WHERE id_number='9191-4444'");
    assert_reset(!bg_reset_password($db, $flow, $seed, $smtp,
        $new, $new)['ok'], 'Disabled account cannot use old proof');
    echo PHP_EOL . "{$checks} integration checks; {$failures} failed." . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Phase 4.4 CI database error: ' . get_class($e)
        . ' [code ' . (string) $e->getCode() . ']' . PHP_EOL);
    $failures++;
}
exit($failures ? 1 : 0);
