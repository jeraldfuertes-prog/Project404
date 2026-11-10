<?php
declare(strict_types=1);
/**
 * Runs ONLY against the ephemeral GitHub Actions MySQL service.
 * Never run this integration suite against the real local billguard_dev.
 * No real usernames, emails, hashes or OTP messages are used.
 */
if (PHP_SAPI !== 'cli' || getenv('BG_RECOVERY_ENTRY_CI') !== 'yes') {
    fwrite(STDERR, "This integration test is restricted to the isolated CI database.\n");
    exit(1);
}
require_once dirname(__DIR__) . '/php/recovery.php';
date_default_timezone_set('Asia/Manila');

$count = 0;
$failed = 0;
function verify_entry(bool $pass, string $label): void
{
    global $count, $failed;
    $count++;
    echo ($pass ? '[OK]   ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$pass) $failed++;
}

try {
    $db = new PDO(
        'mysql:host=127.0.0.1;port=3306;dbname=billguard_dev;charset=utf8mb4',
        'root',
        (string) getenv('BG_TEST_DB_PASS'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES => false]
    );
    $db->exec("SET time_zone = '+08:00'");
    $seed = bg_seed(['seed_d1' => 4, 'seed_d2' => 7]);
    if ($seed === null) throw new RuntimeException('Test seed configuration invalid.');

    $insert = $db->prepare("INSERT INTO users
        (id_number, first_name, family_name, home_address, email, username,
         password_hash, account_status, email_verified_at, consented_at)
        VALUES (:id, 'Demo', 'Recovery', 'Fictional CI address', :email, :username,
            :hash, 'active', NOW(), NOW())");
    $insert->execute([
        ':id' => '5555-5555',
        ':email' => 'recovery@example.test',
        ':username' => 'RecoveryUser5',
        ':hash' => password_hash('TestOnlyPassword!4', PASSWORD_DEFAULT),
    ]);

    $_SERVER['REMOTE_ADDR'] = '198.51.100.51';
    $knownBeforeQuestions = bg_recovery_entry($db, 'RecoveryUser5', $seed);
    verify_entry($knownBeforeQuestions['ok'] && $knownBeforeQuestions['candidate_id'] === null,
        'Existing user without three enrolled questions cannot proceed');
    $hash = password_hash('synthetic answer', PASSWORD_DEFAULT);
    $answerInsert = $db->prepare('INSERT INTO security_answers (user_id, question_id, answer_hash)
        VALUES (:uid, :qid, :hash)');
    foreach ([1, 2, 3] as $qid) {
        $answerInsert->execute([':uid' => '5555-5555', ':qid' => $qid, ':hash' => $hash]);
    }

    $_SERVER['REMOTE_ADDR'] = '198.51.100.52';
    $known = bg_recovery_entry($db, 'RecoveryUser5', $seed);
    $missing = bg_recovery_entry($db, 'MissingUser5', $seed);
    verify_entry($known['ok'] && $known['candidate_id'] === '5555-5555',
        'Verified and unlocked user with three questions creates server-only candidate');
    verify_entry($missing['ok'] && $missing['candidate_id'] === null,
        'Unknown username is handled without exposing a candidate');
    verify_entry($known['message'] === $missing['message'] && $known['message'] === BG_RECOVERY_ENTRY_NOTICE,
        'Known and unknown usernames receive identical public confirmation');
    verify_entry(!str_contains($known['message'], '5555-5555')
        && !str_contains($known['message'], 'recovery@example.test'),
        'Public confirmation contains no internal account ID or email');

    $future = bg_now()->modify('+2 minutes')->format('Y-m-d H:i:s');
    $lock = $db->prepare('UPDATE users SET lockout_until = :until WHERE id_number = :uid');
    $lock->execute([':until' => $future, ':uid' => '5555-5555']);
    $_SERVER['REMOTE_ADDR'] = '198.51.100.53';
    $locked = bg_recovery_entry($db, 'RecoveryUser5', $seed);
    verify_entry($locked['ok'] && $locked['candidate_id'] === null
        && $locked['message'] === $known['message'],
        'Account lockout prevents recovery entry without exposing account status');

    $db->exec("UPDATE users SET lockout_until = NULL WHERE id_number = '5555-5555'");
    $db->exec("UPDATE users SET account_status = 'disabled' WHERE id_number = '5555-5555'");
    $_SERVER['REMOTE_ADDR'] = '198.51.100.54';
    $inactive = bg_recovery_entry($db, 'RecoveryUser5', $seed);
    verify_entry($inactive['ok'] && $inactive['candidate_id'] === null
        && $inactive['message'] === $known['message'],
        'Disabled account gets the same non-identifying confirmation');
    $db->exec("UPDATE users SET account_status = 'active' WHERE id_number = '5555-5555'");

    $_SERVER['REMOTE_ADDR'] = '198.51.100.55';
    $until = bg_now()->modify('+10 minutes')->format('Y-m-d H:i:s');
    $ipBlock = $db->prepare('INSERT INTO ip_blocks (ip_address, blocked_until, failure_count)
        VALUES (:ip, :until, 20)');
    $ipBlock->execute([':ip' => $_SERVER['REMOTE_ADDR'], ':until' => $until]);
    $blocked = bg_recovery_entry($db, 'RecoveryUser5', $seed);
    verify_entry($blocked['ok'] && $blocked['candidate_id'] === null
        && $blocked['message'] === $known['message'],
        'Blocked IP cannot initiate recovery but sees generic confirmation');

    $_SERVER['REMOTE_ADDR'] = '198.51.100.56';
    for ($i = 0; $i < 8; $i++) {
        bg_log($db, 'RECOVERY_ENTRY_REQUESTED', null, null, ['phase' => 'test']);
    }
    $throttled = bg_recovery_entry($db, 'RecoveryUser5', $seed);
    verify_entry($throttled['ok'] && $throttled['candidate_id'] === null
        && $throttled['message'] === $known['message'],
        'Recovery entry rate-limited after eight requests per 15 minutes');

    $q = $db->prepare("SELECT COUNT(*) FROM activity_log
        WHERE event_type = 'RECOVERY_ENTRY_REQUESTED' AND username_typed IS NOT NULL
          AND ip_address IN ('198.51.100.52', '198.51.100.53', '198.51.100.54')");
    $q->execute();
    verify_entry((int) $q->fetchColumn() === 0,
        'Recovery entry activity logs do not expose supplied usernames');
    $otp = $db->prepare("SELECT COUNT(*) FROM one_time_passwords
        WHERE user_id = '5555-5555' AND purpose = 'reset'");
    $otp->execute();
    verify_entry((int) $otp->fetchColumn() === 0,
        'Phase 4.1 does not send a reset OTP');
    $currentPass = $db->prepare("SELECT password_hash FROM users WHERE id_number = '5555-5555'");
    $currentPass->execute();
    verify_entry(password_verify('TestOnlyPassword!4', (string) $currentPass->fetchColumn()),
        'Phase 4.1 does not modify the existing password');

    echo PHP_EOL . "{$count} integration checks; {$failed} failed." . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Recovery entry CI check failed: ' . get_class($e)
        . ' [code ' . (string) $e->getCode() . ']' . PHP_EOL);
    $failed++;
}
exit($failed ? 1 : 0);
