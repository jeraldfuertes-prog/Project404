<?php
declare(strict_types=1);
/**
 * Synthetic account + disposable CI MySQL only. This suite MUST NOT run on
 * real local accounts or send actual SMTP messages.
 */
if (PHP_SAPI !== 'cli' || getenv('BG_RECOVERY_EMAIL_CI') !== 'yes') {
    fwrite(STDERR, "This test runs only in isolated CI MySQL.\n");
    exit(1);
}
require_once dirname(__DIR__) . '/php/recovery_email.php';
require_once dirname(__DIR__) . '/php/recovery_code.php';

$passed = 0;
$failed = 0;
function recovery_email_assert(bool $ok, string $what): void
{
    global $passed, $failed;
    echo ($ok ? '[OK]   ' : '[FAIL] ') . $what . PHP_EOL;
    if ($ok) $passed++; else $failed++;
}
try {
    date_default_timezone_set('Asia/Manila');
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
    if ($seed === null) throw new RuntimeException('Invalid test-only seed.');
    $config = [
        'smtp_host' => '127.0.0.1', 'smtp_port' => 1,
        'smtp_security' => 'none', 'smtp_user' => 'fake',
        'smtp_password' => 'fake-password', 'smtp_from' => 'ci@example.test',
    ];
    $id = '8888-8888';
    $q = $db->prepare("INSERT INTO users (id_number, first_name, family_name,
        home_address, email, username, password_hash, account_status,
        email_verified_at, consented_at) VALUES
        (:id, 'Synthetic', 'Account', 'CI-only address', :email, 'RecoveryEmail88',
         :hash, 'active', NOW(), NOW())");
    $q->execute([
        ':id' => $id, ':email' => 'recovery-email@example.test',
        ':hash' => password_hash('OriginalPassword!4', PASSWORD_DEFAULT),
    ]);
    $choices = bg_recovery_question_choices($seed);
    $qid = array_key_first($choices);
    $answer = password_hash('silver river', PASSWORD_DEFAULT);
    $insertQ = $db->prepare('INSERT INTO security_answers
        (user_id, question_id, answer_hash) VALUES (:id, :question, :hash)');
    foreach (array_keys($choices) as $question) {
        $insertQ->execute([':id' => $id, ':question' => $question, ':hash' => $answer]);
    }
    $_SERVER['REMOTE_ADDR'] = '198.51.100.88';
    $_SERVER['HTTP_USER_AGENT'] = 'Synthetic phase 4.3 integration';

    $missing = bg_recovery_email_entry($db, 'nobody@example.test', $seed, $config);
    recovery_email_assert($missing['ok'] && $missing['candidate_id'] === null
        && !$missing['code_sent'], 'Unknown email gives no candidate/code');
    $known = bg_recovery_email_entry($db, 'Recovery-Email@Example.Test', $seed, $config);
    recovery_email_assert($known['ok'] && !$known['code_sent']
        && $known['candidate_id'] === null,
        'Failed synthetic SMTP never pretends that code was sent');
    recovery_email_assert($known['message'] === $missing['message'],
        'Known and unknown email receive same visible response');
    $checkOtp = $db->prepare("SELECT COUNT(*) FROM one_time_passwords
        WHERE user_id = :id AND purpose = 'reset'");
    $checkOtp->execute([':id' => $id]);
    recovery_email_assert((int) $checkOtp->fetchColumn() === 0,
        'Failed SMTP transaction rolls back reset code and audit');

    $flow = [
        'stage' => 'question_pending', 'started_at' => time(),
        'candidate_id' => $id, 'code_sent' => true,
    ];
    $unknownFlow = [
        'stage' => 'question_pending', 'started_at' => time(),
        'candidate_id' => null, 'code_sent' => false,
    ];
    recovery_email_assert(!bg_recovery_verify_code($db, $unknownFlow, '123456'),
        'Unknown account cannot pass email OTP verification');

    $createCode = $db->prepare("INSERT INTO one_time_passwords
        (user_id, purpose, otp_hash, expires_at, created_at)
        VALUES (:id, 'reset', :hash, :expiry, :now)");
    $now = bg_now()->format('Y-m-d H:i:s');
    $createCode->execute([
        ':id' => $id, ':hash' => password_hash('123456', PASSWORD_DEFAULT),
        ':expiry' => bg_now()->modify('+4 minutes')->format('Y-m-d H:i:s'),
        ':now' => $now,
    ]);
    recovery_email_assert(!bg_recovery_verify_code($db, $flow, '654321'),
        'Wrong reset code rejected');
    $attemptsQ = $db->prepare("SELECT failed_attempts, consumed_at
        FROM one_time_passwords WHERE user_id = :id AND purpose = 'reset'
        ORDER BY id DESC LIMIT 1");
    $attemptsQ->execute([':id' => $id]);
    recovery_email_assert((int) $attemptsQ->fetch()['failed_attempts'] === 1,
        'Wrong reset code increments attempt counter');

    $matched = bg_recovery_verify_question($db, $flow, $seed, $config,
        $qid, 'Silver River', 'silver river');
    recovery_email_assert($matched['verified'],
        'Correct registered question verified before email proof');

    $codeOk = bg_recovery_verify_code($db, $flow, '123456');
    recovery_email_assert($codeOk, 'Correct reset code accepted once');
    recovery_email_assert(!bg_recovery_verify_code($db, $flow, '123456'),
        'Consumed reset code cannot be replayed');

    $createCode->execute([
        ':id' => $id, ':hash' => password_hash('234567', PASSWORD_DEFAULT),
        ':expiry' => bg_now()->modify('-1 minute')->format('Y-m-d H:i:s'),
        ':now' => $now,
    ]);
    recovery_email_assert(!bg_recovery_verify_code($db, $flow, '234567'),
        'Expired reset code is rejected');

    $createCode->execute([
        ':id' => $id, ':hash' => password_hash('345678', PASSWORD_DEFAULT),
        ':expiry' => bg_now()->modify('+4 minutes')->format('Y-m-d H:i:s'),
        ':now' => $now,
    ]);
    foreach (['000000', '000000', '000000'] as $wrongCode) {
        recovery_email_assert(!bg_recovery_verify_code($db, $flow, $wrongCode),
            'Wrong reset code attempt rejected');
    }
    $last = $db->prepare("SELECT failed_attempts, cancelled_at FROM one_time_passwords
        WHERE user_id = :id AND purpose = 'reset' ORDER BY id DESC LIMIT 1");
    $last->execute([':id' => $id]);
    $cancelled = $last->fetch();
    recovery_email_assert((int) $cancelled['failed_attempts'] === 3
        && $cancelled['cancelled_at'] !== null,
        'Third wrong reset code cancels that code');
    recovery_email_assert(!bg_recovery_verify_code($db, $flow, '345678'),
        'Cancelled reset code cannot be used even when correct');

    $createCode->execute([
        ':id' => $id, ':hash' => password_hash('456789', PASSWORD_DEFAULT),
        ':expiry' => bg_now()->modify('+4 minutes')->format('Y-m-d H:i:s'),
        ':now' => $now,
    ]);
    $lock = $db->prepare("UPDATE users SET lockout_until = :until WHERE id_number = :id");
    $lock->execute([
        ':until' => bg_now()->modify('+2 minutes')->format('Y-m-d H:i:s'),
        ':id' => $id,
    ]);
    recovery_email_assert(!bg_recovery_verify_code($db, $flow, '456789'),
        'An active account lockout prevents code verification');
    $db->exec("UPDATE users SET lockout_until = NULL WHERE id_number = '8888-8888'");

    $blockUntil = bg_now()->modify('+10 minutes')->format('Y-m-d H:i:s');
    $blockIp = $db->prepare('INSERT INTO ip_blocks
        (ip_address, blocked_until, failure_count) VALUES (:ip, :until, 20)');
    $blockIp->execute([':ip' => $_SERVER['REMOTE_ADDR'], ':until' => $blockUntil]);
    recovery_email_assert(!bg_recovery_verify_code($db, $flow, '456789'),
        'An active IP block prevents reset code verification');

    $_SERVER['REMOTE_ADDR'] = '198.51.100.89';
    recovery_email_assert(!bg_recovery_verify_code($db,
        ['stage' => 'question_pending', 'started_at' => time()-601,
         'candidate_id' => $id, 'code_sent' => true], '456789'),
        'Expired recovery flow cannot verify code');

    $passwordQ = $db->prepare('SELECT password_hash FROM users WHERE id_number = :id');
    $passwordQ->execute([':id' => $id]);
    recovery_email_assert(password_verify('OriginalPassword!4', (string) $passwordQ->fetchColumn()),
        'Email + question + code proof does not change password yet');

    $logQ = $db->prepare("SELECT COUNT(*) FROM activity_log
        WHERE event_type = 'RECOVERY_CODE_VERIFIED' AND user_id = :id");
    $logQ->execute([':id' => $id]);
    recovery_email_assert((int) $logQ->fetchColumn() === 1,
        'Successful reset-code verification recorded without exposing code');

    echo PHP_EOL . ($passed + $failed) . " checks; {$failed} failed." . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Email recovery integration failed: '
        . get_class($e) . ' [code: ' . (string) $e->getCode() . "]\n");
    $failed++;
}
exit($failed ? 1 : 0);
