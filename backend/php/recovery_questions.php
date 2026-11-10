<?php
declare(strict_types=1);
require_once __DIR__ . '/recovery.php';

/**
 * Question + reset code are now separate proofs on the same recovery page.
 * Neither this module nor OTP verification changes the account password.
 */
const BG_RECOVERY_QUESTION_NOTICE = 'Your recovery verification has been submitted. '
    . 'If both the email code and your security answer matched, identity checks are complete. '
    . 'No password has been changed; that step will be enabled in Phase 4.4.';

/** The three questions selected using the enrollment seed. */
function bg_recovery_question_choices(array $seed): array
{
    $catalog = bg_questions();
    $result = [];
    foreach (($seed['question_ids'] ?? []) as $id) {
        if (is_int($id) && isset($catalog[$id]) && !isset($result[$id])) {
            $result[$id] = $catalog[$id];
        }
    }
    return count($result) === 3 ? $result : [];
}

/** Reproduce the original registration normalization and answer constraints. */
function bg_recovery_answers_match(string $answer, string $confirmation): bool
{
    return $answer !== '' && strlen($answer) <= 50
        && preg_match('/^[A-Za-z]+(?: [A-Za-z]+)*$/D', $answer) === 1
        && hash_equals(strtolower($answer), strtolower($confirmation));
}

/** Result page may show exactly the same text for passed/failed/unknown. */
function bg_recovery_question_result_ready(mixed $flow, int $now): bool
{
    if (!is_array($flow) || !in_array($flow['stage'] ?? null,
        ['question_verified', 'question_submitted',
            'identity_verified', 'identity_submitted'], true)) {
        return false;
    }
    $copy = $flow;
    $copy['stage'] = 'question_pending';
    return bg_recovery_flow_ready($copy, $now);
}

/** Fixed bcrypt work factor for unknown accounts to reduce timing difference. */
function bg_recovery_dummy_answer_hash(): string
{
    return '$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy';
}

/**
 * Account-aware, transaction-safe answer verification.
 * Public result never distinguishes success, incorrect answer, unknown user,
 * locked account or blocked IP. All secrets stay off logs and HTML.
 *
 * @return array{ok:bool,verified:bool,message:string}
 */
function bg_recovery_verify_question(
    PDO $db, mixed $flow, array $seed, array $config,
    int $questionId, string $answer, string $confirmation
): array {
    if (!bg_recovery_flow_ready($flow, time())) {
        return ['ok' => false, 'verified' => false, 'message' => 'Recovery session expired. Start again.'];
    }
    $candidate = $flow['candidate_id'];
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'), 0, 45);
    $now = bg_now();
    $nowSql = $now->format('Y-m-d H:i:s');
    $alert = null;
    $verified = false;
    try {
        $blockedIp = bg_ip_is_blocked($db, $ip);
        $db->beginTransaction();
        $user = null;
        if ($candidate !== null && !$blockedIp) {
            $q = $db->prepare('SELECT id_number, email, account_status, email_verified_at,
                consecutive_failures, lockout_until, last_alerted_tier
                FROM users WHERE id_number = :uid FOR UPDATE');
            $q->execute([':uid' => $candidate]);
            $user = $q->fetch() ?: null;
        }
        $eligible = $user && $user['account_status'] === 'active'
            && $user['email_verified_at'] !== null
            && ($user['lockout_until'] === null || $user['lockout_until'] <= $nowSql);
        $choices = bg_recovery_question_choices($seed);
        $storedHash = bg_recovery_dummy_answer_hash();
        $enrolled = false;
        if ($eligible && isset($choices[$questionId])) {
            $q = $db->prepare('SELECT answer_hash FROM security_answers
                WHERE user_id = :uid AND question_id = :qid LIMIT 1');
            $q->execute([':uid' => $candidate, ':qid' => $questionId]);
            $hash = $q->fetchColumn();
            if (is_string($hash)) {
                $enrolled = true;
                $storedHash = $hash;
            }
        }
        // Run hash verification for both known and unknown accounts.
        $hashMatches = password_verify(strtolower($answer), $storedHash);
        $verified = (bool) ($eligible && $enrolled
            && bg_recovery_answers_match($answer, $confirmation) && $hashMatches);
        if ($verified) {
            bg_log($db, 'RECOVERY_QUESTION_VERIFIED', null, $candidate,
                ['phase' => '4.2']);
        } else {
            if ($eligible) {
                // Same 3/6/9 account lockouts as failed username/password.
                $failures = (int) $user['consecutive_failures'] + 1;
                [$tier, $seconds] = bg_login_lockout($failures, $seed);
                $until = $seconds > 0
                    ? $now->modify('+' . $seconds . ' seconds')->format('Y-m-d H:i:s')
                    : null;
                $update = $db->prepare('UPDATE users
                    SET consecutive_failures = :fails, lockout_until = :until
                    WHERE id_number = :uid');
                $update->execute([
                    ':fails' => $failures, ':until' => $until, ':uid' => $candidate,
                ]);
                if ($tier > 0) {
                    bg_log($db, 'LOCKOUT_TIER_' . $tier, null, $candidate,
                        ['seconds' => $seconds, 'via' => 'recovery']);
                }
                $alertTier = bg_login_alert_tier($failures);
                if ($alertTier > (int) $user['last_alerted_tier']) {
                    $alert = [
                        'to' => $user['email'], 'tier' => $alertTier,
                        'fails' => $failures, 'id' => $candidate,
                    ];
                }
            }
            // Answer content and question IDs must never be logged.
            bg_log($db, 'RECOVERY_ANSWER_FAILED', null, null, ['phase' => '4.2']);
            if (!$blockedIp) {
                bg_log($db, 'LOGIN_FAILED', null, null, ['via' => 'recovery']);
            }
        }
        $db->commit();
        if (!$verified && !$blockedIp) {
            bg_check_ip_threshold($db, $ip, $seed);
        }
        if ($alert && bg_mail_lockout_alert(
            (string) $alert['to'], (int) $alert['tier'], (int) $alert['fails'],
            $ip, substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'), 0, 300),
            $config
        )) {
            $mark = $db->prepare('UPDATE users SET last_alerted_tier = :tier
                WHERE id_number = :uid AND last_alerted_tier < :min_tier');
            $mark->execute([
                ':tier' => $alert['tier'], ':uid' => $alert['id'],
                ':min_tier' => $alert['tier'],
            ]);
            bg_log($db, 'ALERT_EMAIL_SENT', null, $alert['id'],
                ['tier' => $alert['tier'], 'via' => 'recovery']);
        }
        return ['ok' => true, 'verified' => $verified,
            'message' => BG_RECOVERY_QUESTION_NOTICE];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('BillGuard: security-question processing failed.');
        return ['ok' => false, 'verified' => false,
            'message' => 'Security-question verification is temporarily unavailable. Try again later.'];
    }
}
