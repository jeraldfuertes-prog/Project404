<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

/**
 * BillGuard Phase 4.1: password recovery entry only.
 *
 * NO recovery proof is granted here. The question, reset-email OTP, and
 * password-change stages MUST be implemented and independently checked
 * before a password is ever modified.
 */
const BG_RECOVERY_ENTRY_NOTICE = 'Request received. Continue to security-question verification. '
    . 'No email was sent and no password was changed.';

function bg_recovery_username_error(string $username, array $seed): ?string
{
    $min = (int) ($seed['username_min'] ?? 0);
    $max = (int) ($seed['username_max'] ?? 0);
    if ($min < 1 || $max < $min || strlen($username) < $min || strlen($username) > $max
        || preg_match('/^[A-Za-z][A-Za-z0-9]*$/D', $username) !== 1) {
        return "Enter a username of {$min}–{$max} letters or digits, starting with a letter.";
    }
    return null;
}

/** CAPTCHA is consumed on every verification attempt, preventing replay. */
function bg_recovery_check_captcha(string $answer, int $now): bool
{
    $expected = $_SESSION['captcha_answer'] ?? null;
    $issuedAt = $_SESSION['captcha_created'] ?? null;
    unset($_SESSION['captcha_answer'], $_SESSION['captcha_created']);
    return is_string($expected) && strlen($expected) === 6
        && is_int($issuedAt) && $issuedAt > 0 && $issuedAt <= $now
        && ($now - $issuedAt) <= 300
        && hash_equals($expected, strtoupper(trim($answer)));
}

/** Phase 4.2 will check both this state AND the account's CURRENT lockout. */
function bg_recovery_flow_ready(mixed $flow, int $now): bool
{
    if (!is_array($flow) || ($flow['stage'] ?? null) !== 'question_pending') return false;
    $startedAt = $flow['started_at'] ?? null;
    $candidate = $flow['candidate_id'] ?? null;
    return is_int($startedAt) && $startedAt > 0 && $startedAt <= $now
        && $now - $startedAt <= 600
        && ($candidate === null || (is_string($candidate)
            && preg_match('/^[0-9]{4}-[0-9]{4}$/D', $candidate) === 1));
}

/**
 * Perform a candidate check, but NEVER distinguish registered/unknown,
 * inactive/locked, or rate-limited usernames in the visible result.
 *
 * @return array{ok:bool,message:string,candidate_id:?string}
 */
function bg_recovery_entry(PDO $db, string $username, array $seed): array
{
    if (bg_recovery_username_error($username, $seed) !== null) {
        return ['ok' => false, 'message' => 'Enter a valid username.', 'candidate_id' => null];
    }
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'), 0, 45);
    $candidate = null;
    try {
        // Fixed limit for this public recovery entry endpoint, independent of
        // login failure counters. CAPTCHA is required by the calling page.
        $window = bg_now()->modify('-15 minutes')->format('Y-m-d H:i:s');
        $count = $db->prepare("SELECT COUNT(*) FROM activity_log
            WHERE ip_address = :ip AND event_type = 'RECOVERY_ENTRY_REQUESTED'
                AND created_at >= :window");
        $count->execute([':ip' => $ip, ':window' => $window]);
        $throttled = (int) $count->fetchColumn() >= 8;
        $blocked = bg_ip_is_blocked($db, $ip);
        if (!$blocked && !$throttled) {
            $q = $db->prepare('SELECT id_number, account_status, email_verified_at, lockout_until
                FROM users WHERE username = :username LIMIT 1');
            $q->execute([':username' => $username]);
            $user = $q->fetch();
            if ($user && $user['account_status'] === 'active'
                && $user['email_verified_at'] !== null
                && ($user['lockout_until'] === null
                    || $user['lockout_until'] <= bg_now()->format('Y-m-d H:i:s'))) {
                $answers = $db->prepare('SELECT COUNT(*) FROM security_answers WHERE user_id = :uid');
                $answers->execute([':uid' => $user['id_number']]);
                if ((int) $answers->fetchColumn() >= 3) {
                    $candidate = (string) $user['id_number'];
                }
            }
        }

        // No account ID/email/answer is included in a public response or log.
        bg_log($db, 'RECOVERY_ENTRY_REQUESTED', null, null,
            ['phase' => '4.1', 'rate_limited' => $throttled, 'ip_blocked' => $blocked]);

        return ['ok' => true, 'message' => BG_RECOVERY_ENTRY_NOTICE,
            'candidate_id' => $candidate];
    } catch (Throwable $error) {
        // This is an operational failure, not a username-not-found condition.
        // Never print raw database exceptions or account details.
        error_log('BillGuard: recovery entry database operation failed.');
        return ['ok' => false, 'message' => 'Account recovery is temporarily unavailable. Please try again later.',
            'candidate_id' => null];
    }
}
