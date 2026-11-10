<?php
declare(strict_types=1);
/**
 * CI ONLY: disposable, synthetic MySQL database and test-only credentials.
 * Never run against a user's real billguard_dev database.
 */
if (PHP_SAPI !== 'cli' || getenv('BG_RECOVERY_QUESTION_CI') !== 'yes') {
    fwrite(STDERR, "This test runs only in the isolated CI database.\n");
    exit(1);
}
require_once dirname(__DIR__) . '/php/recovery_questions.php';
date_default_timezone_set('Asia/Manila');

$total = 0;
$failed = 0;
function assert_recovery_q(bool $ok, string $label): void
{
    global $total, $failed;
    $total++;
    echo ($ok ? '[OK]   ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failed++;
}
try {
    $db = new PDO(
        'mysql:host=127.0.0.1;port=3306;dbname=billguard_dev;charset=utf8mb4',
        'root', (string) getenv('BG_TEST_DB_PASS'),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $db->exec("SET time_zone = '+08:00'");
    $seed = bg_seed(['seed_d1' => 4, 'seed_d2' => 7]);
    if ($seed === null) throw new RuntimeException('Test seed invalid.');
    $config = [
        'smtp_host' => '127.0.0.1', 'smtp_port' => 1,
        'smtp_security' => 'none', 'smtp_user' => 'fake',
        'smtp_password' => 'CI-only-not-real', 'smtp_from' => 'ci@example.test',
    ];
    $id = '6666-6666';
    $ins = $db->prepare("INSERT INTO users (id_number, first_name, family_name, home_address,
        email, username, password_hash, account_status, email_verified_at, consented_at)
        VALUES (:uid, 'Phase', 'Four', 'Synthetic CI account', :email, :username,
            :pass, 'active', NOW(), NOW())");
    $ins->execute([
        ':uid' => $id, ':email' => 'phasefour@example.test',
        ':username' => 'RecoveryFour5',
        ':pass' => password_hash('OriginalPassword!4', PASSWORD_DEFAULT),
    ]);
    $questions = bg_recovery_question_choices($seed);
    $insertQuestion = $db->prepare('INSERT INTO security_answers
        (user_id, question_id, answer_hash) VALUES (:uid, :qid, :hash)');
    foreach (array_keys($questions) as $qid) {
        $insertQuestion->execute([
            ':uid' => $id, ':qid' => $qid,
            ':hash' => password_hash('silver river', PASSWORD_DEFAULT),
        ]);
    }
    $qid = array_key_first($questions);
    $flow = ['stage' => 'question_pending', 'candidate_id' => $id, 'started_at' => time()];
    $unknown = ['stage' => 'question_pending', 'candidate_id' => null, 'started_at' => time()];
    $_SERVER['REMOTE_ADDR'] = '198.51.100.71';
    $_SERVER['HTTP_USER_AGENT'] = 'BillGuard Phase 4.2 CI';

    $valid = bg_recovery_verify_question($db, $flow, $seed, $config,
        $qid, 'Silver River', 'silver river');
    assert_recovery_q($valid['ok'] && $valid['verified'],
        'A registered question and case-insensitive answer are verified');
    assert_recovery_q($valid['message'] === BG_RECOVERY_QUESTION_NOTICE,
        'Verified answer gets same generic public message');
    $getUser = $db->prepare('SELECT consecutive_failures, lockout_until, password_hash,
        last_alerted_tier FROM users WHERE id_number = :uid');
    $getUser->execute([':uid' => $id]);
    $user = $getUser->fetch();
    assert_recovery_q((int) $user['consecutive_failures'] === 0,
        'Correct recovery answer does not increase failure count');
    assert_recovery_q(password_verify('OriginalPassword!4', $user['password_hash']),
        'Correct recovery answer does not change password');

    $_SERVER['REMOTE_ADDR'] = '198.51.100.72';
    $noUser = bg_recovery_verify_question($db, $unknown, $seed, $config,
        $qid, 'Silver River', 'silver river');
    assert_recovery_q($noUser['ok'] && !$noUser['verified']
        && $noUser['message'] === $valid['message'],
        'Unknown account receives identical response and cannot verify');
    $wrongQuestion = bg_recovery_verify_question($db, $flow, $seed, $config,
        1, 'Silver River', 'silver river');
    assert_recovery_q(!$wrongQuestion['verified']
        && $wrongQuestion['message'] === $valid['message'],
        'Non-enrolled question cannot establish identity');
    $getUser->execute([':uid' => $id]);
    assert_recovery_q((int) $getUser->fetch()['consecutive_failures'] === 1,
        'Wrong question counts against the shared login failure counter');

    $_SERVER['REMOTE_ADDR'] = '198.51.100.73';
    $wrongAnswer = bg_recovery_verify_question($db, $flow, $seed, $config,
        $qid, 'Wrong Answer', 'Wrong Answer');
    $getUser->execute([':uid' => $id]);
    assert_recovery_q(!$wrongAnswer['verified']
        && (int) $getUser->fetch()['consecutive_failures'] === 2,
        'Incorrect security answer counts toward shared account lockout');

    $third = bg_recovery_verify_question($db, $flow, $seed, $config,
        $qid, 'Wrong Answer', 'Wrong Answer');
    $getUser->execute([':uid' => $id]);
    $locked = $getUser->fetch();
    assert_recovery_q(!$third['verified'] && (int) $locked['consecutive_failures'] === 3
        && $locked['lockout_until'] > bg_now()->format('Y-m-d H:i:s'),
        'Third wrong recovery answer triggers same Tier 1 account lockout');

    $validDuringLock = bg_recovery_verify_question($db, $flow, $seed, $config,
        $qid, 'Silver River', 'silver river');
    $getUser->execute([':uid' => $id]);
    $lockedAfter = $getUser->fetch();
    assert_recovery_q(!$validDuringLock['verified']
        && (int) $lockedAfter['consecutive_failures'] === 3,
        'Even a correct answer cannot bypass an active account lockout');

    $unlock = $db->prepare('UPDATE users SET lockout_until = NULL WHERE id_number = :uid');
    $unlock->execute([':uid' => $id]);
    $_SERVER['REMOTE_ADDR'] = '198.51.100.74';
    $blockedUntil = bg_now()->modify('+10 minutes')->format('Y-m-d H:i:s');
    $addBlock = $db->prepare('INSERT INTO ip_blocks
        (ip_address, blocked_until, failure_count) VALUES (:ip, :until, 20)');
    $addBlock->execute([':ip' => $_SERVER['REMOTE_ADDR'], ':until' => $blockedUntil]);
    $fromBlocked = bg_recovery_verify_question($db, $flow, $seed, $config,
        $qid, 'Silver River', 'silver river');
    assert_recovery_q(!$fromBlocked['verified']
        && $fromBlocked['message'] === $valid['message'],
        'Blocked IP cannot verify answer, and response remains generic');

    $_SERVER['REMOTE_ADDR'] = '198.51.100.75';
    $disable = $db->prepare("UPDATE users SET account_status = 'disabled' WHERE id_number = :uid");
    $disable->execute([':uid' => $id]);
    $inactive = bg_recovery_verify_question($db, $flow, $seed, $config,
        $qid, 'Silver River', 'silver river');
    assert_recovery_q(!$inactive['verified']
        && $inactive['message'] === $valid['message'],
        'Disabled user cannot verify after starting recovery');

    $eventQuery = $db->prepare("SELECT COUNT(*) FROM activity_log
        WHERE event_type IN ('RECOVERY_ANSWER_FAILED', 'RECOVERY_QUESTION_VERIFIED')");
    $eventQuery->execute();
    assert_recovery_q((int) $eventQuery->fetchColumn() >= 5,
        'Account recovery events logged without answer values');

    $q = $db->prepare("SELECT COUNT(*) FROM one_time_passwords
        WHERE user_id = :uid AND purpose = 'reset'");
    $q->execute([':uid' => $id]);
    assert_recovery_q((int) $q->fetchColumn() === 0,
        'Phase 4.2 never creates a password-reset OTP');

    $logs = $db->prepare("SELECT COUNT(*) FROM activity_log
        WHERE event_type IN ('RECOVERY_ANSWER_FAILED','RECOVERY_QUESTION_VERIFIED')
            AND (CAST(details AS CHAR) LIKE '%silver river%'
                OR CAST(details AS CHAR) LIKE '%Wrong Answer%')");
    $logs->execute();
    assert_recovery_q((int) $logs->fetchColumn() === 0,
        'Activity log never stores security answers');

    echo PHP_EOL . "{$total} integration checks; {$failed} failed." . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Security-question CI test failed: ' . get_class($e)
        . ' [code ' . (string) $e->getCode() . "]\n");
    $failed++;
}
exit($failed ? 1 : 0);
