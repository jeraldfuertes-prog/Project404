<?php
declare(strict_types=1);
require_once __DIR__ . '/recovery.php';

/**
 * Email-first account recovery. Keep account existence and mail delivery
 * private; no account data, code or answers are returned to the browser.
 */
const BG_RECOVERY_EMAIL_NOTICE = 'If this email has an eligible BillGuard account, '
    . 'a six-digit recovery code was sent. '
    . 'Choose one of your three security questions and enter the code to continue.';

function bg_recovery_email_error(string $email): ?string
{
    if ($email === '' || strlen($email) > 100
        || str_contains($email, ' ')
        || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return 'Enter a valid email address (maximum 100 characters).';
    }
    return null;
}

/** Email addresses are normalized exactly as at registration. */
function bg_recovery_normalize_email(string $email): string
{
    return strtolower(trim($email));
}

/**
 * Called only by the CAPTCHA- and CSRF-checked entry endpoint.
 * A false/unknown result must not have a different visible response.
 *
 * @return array{ok:bool,message:string,candidate_id:?string,code_sent:bool}
 */
function bg_recovery_email_entry(PDO $db, string $email, array $seed, array $config): array
{
    $email = bg_recovery_normalize_email($email);
    if (bg_recovery_email_error($email) !== null) {
        return [
            'ok' => false, 'message' => 'Enter a valid email address.',
            'candidate_id' => null, 'code_sent' => false,
        ];
    }

    $candidate = null;
    $sent = false;
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'), 0, 45);
    try {
        $window = bg_now()->modify('-15 minutes')->format('Y-m-d H:i:s');
        $count = $db->prepare("SELECT COUNT(*) FROM activity_log
            WHERE ip_address = :ip AND event_type = 'RECOVERY_ENTRY_REQUESTED'
                AND created_at >= :window");
        $count->execute([':ip' => $ip, ':window' => $window]);
        $throttled = (int) $count->fetchColumn() >= 8;
        $blocked = bg_ip_is_blocked($db, $ip);
        if (!$throttled && !$blocked) {
            $q = $db->prepare('SELECT id_number, email, account_status,
                email_verified_at, lockout_until
                FROM users WHERE email = :email LIMIT 1');
            $q->execute([':email' => $email]);
            $user = $q->fetch();
            $nowSql = bg_now()->format('Y-m-d H:i:s');
            if ($user && $user['account_status'] === 'active'
                && $user['email_verified_at'] !== null
                && ($user['lockout_until'] === null
                    || $user['lockout_until'] <= $nowSql)) {
                $answers = $db->prepare('SELECT COUNT(*) FROM security_answers
                    WHERE user_id = :uid');
                $answers->execute([':uid' => $user['id_number']]);
                if ((int) $answers->fetchColumn() >= 3) {
                    // Rate-limited, expiring and purpose-bound OTP. The code
                    // is never printed, logged or persisted in plain text.
                    $delivery = bg_send_otp(
                        $db, (string) $user['id_number'], (string) $user['email'],
                        'reset', $config, $seed, false
                    );
                    $sent = $delivery['ok'] === true;
                    if ($sent) $candidate = (string) $user['id_number'];
                }
            }
        }

        bg_log($db, 'RECOVERY_ENTRY_REQUESTED', null, null, [
            'phase' => 'email-first', 'rate_limited' => $throttled,
            'ip_blocked' => $blocked,
        ]);
        return [
            'ok' => true, 'message' => BG_RECOVERY_EMAIL_NOTICE,
            'candidate_id' => $candidate, 'code_sent' => $sent,
        ];
    } catch (Throwable $e) {
        error_log('BillGuard: email recovery request failed.');
        return [
            'ok' => false,
            'message' => 'Account recovery is temporarily unavailable. Try again later.',
            'candidate_id' => null, 'code_sent' => false,
        ];
    }
}

/**
 * A resend never reveals if an account exists or whether SMTP delivered.
 * Return the same generic response for known, unknown or ineligible flows.
 */
function bg_recovery_resend_code(PDO $db, mixed $flow, array $seed, array $config): string
{
    if (!bg_recovery_flow_ready($flow, time())) return BG_RECOVERY_EMAIL_NOTICE;
    $id = $flow['candidate_id'] ?? null;
    if (!is_string($id) || ($flow['code_sent'] ?? false) !== true) {
        return BG_RECOVERY_EMAIL_NOTICE;
    }
    try {
        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'), 0, 45);
        if (bg_ip_is_blocked($db, $ip)) return BG_RECOVERY_EMAIL_NOTICE;
        $q = $db->prepare('SELECT email FROM users
            WHERE id_number = :uid AND account_status = :status
              AND email_verified_at IS NOT NULL LIMIT 1');
        $q->execute([':uid' => $id, ':status' => 'active']);
        $user = $q->fetch();
        if ($user) {
            bg_send_otp($db, $id, (string) $user['email'], 'reset', $config, $seed, true);
        }
    } catch (Throwable $e) {
        error_log('BillGuard: reset code resend attempt failed.');
    }
    return BG_RECOVERY_EMAIL_NOTICE;
}
