<?php
declare(strict_types=1);
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/otp.php';

/** Authentication and access helpers: never grant a session before login OTP. */
function bg_auth_user(PDO $db, array $seed): ?array
{
    $id = $_SESSION['user_id'] ?? null;
    if (!is_string($id) || !preg_match('/^\d{4}-\d{4}$/D', $id)) {
        return null;
    }
    $last = (int) ($_SESSION['last_activity'] ?? 0);
    $idle = (5 + ($seed['d1'] % 5)) * 60;
    if ($last < 1 || time() - $last >= $idle) {
        bg_sign_out();
        return null;
    }
    $query = $db->prepare("SELECT id_number, first_name, family_name, email, role FROM users
        WHERE id_number = :id AND account_status = 'active' AND email_verified_at IS NOT NULL LIMIT 1");
    $query->execute([':id' => $id]);
    $user = $query->fetch();
    if (!$user) {
        bg_sign_out();
        return null;
    }
    $_SESSION['last_activity'] = time();
    return $user;
}

function bg_sign_out(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $settings = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => $settings['path'],
            'domain' => $settings['domain'],
            'secure' => $settings['secure'],
            'httponly' => $settings['httponly'],
            'samesite' => $settings['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
}

function bg_login_lockout(int $failures, array $seed): array
{
    $d1 = $seed['d1'];
    if ($failures >= 9) return [3, 60 + 4 * $d1];
    if ($failures >= 6) return [2, 30 + 2 * $d1];
    if ($failures >= 3) return [1, 15 + $d1];
    return [0, 0];
}

function bg_ip_is_blocked(PDO $db, string $ip): bool
{
    $q = $db->prepare('SELECT blocked_until FROM ip_blocks WHERE ip_address = :ip');
    $q->execute([':ip' => $ip]);
    $until = $q->fetchColumn();
    return $until !== false && $until > bg_now()->format('Y-m-d H:i:s');
}

function bg_check_ip_threshold(PDO $db, string $ip, array $seed): bool
{
    $window = bg_now()->modify('-10 minutes')->format('Y-m-d H:i:s');
    $q = $db->prepare("SELECT COUNT(*) FROM activity_log
        WHERE ip_address = :ip AND event_type = 'LOGIN_FAILED' AND created_at >= :start");
    $q->execute([':ip' => $ip, ':start' => $window]);
    $total = (int) $q->fetchColumn();
    $threshold = 8 + $seed['d2'];
    if ($total < $threshold) return false;
    $until = bg_now()->modify('+10 minutes')->format('Y-m-d H:i:s');
    $set = $db->prepare("INSERT INTO ip_blocks (ip_address, blocked_until, failure_count)
        VALUES (:ip, :until, :count)
        ON DUPLICATE KEY UPDATE blocked_until = GREATEST(blocked_until, VALUES(blocked_until)),
            failure_count = VALUES(failure_count)");
    $set->execute([':ip' => $ip, ':until' => $until, ':count' => $total]);
    bg_log($db, 'IP_BLOCKED', null, null, ['threshold' => $threshold, 'count' => $total]);
    return true;
}

function bg_mail_lockout_alert(string $to, int $tier, int $failures, string $ip, string $agent, array $config): bool
{
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) return false;
    require_once $autoload;
    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = (string) $config['smtp_host'];
        $mail->Port = (int) ($config['smtp_port'] ?? 587);
        $mode = (string) ($config['smtp_security'] ?? 'tls');
        $mail->SMTPAuth = $mode !== 'none';
        $mail->Username = (string) $config['smtp_user'];
        $mail->Password = (string) $config['smtp_password'];
        $mail->SMTPSecure = $mode === 'ssl' ?
            PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS :
            ($mode === 'tls' ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS : '');
        if ($mode === 'none') $mail->SMTPAutoTLS = false;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom((string) $config['smtp_from'], (string) ($config['smtp_from_name'] ?? 'BillGuard'));
        $mail->addAddress($to);
        $mail->Subject = "BillGuard - Security alert (Tier {$tier})";
        $mail->Body = "An account protection lockout was triggered.\n"
            . "Consecutive failed logins: {$failures}\n"
            . "Time (PHT): " . bg_now()->format('Y-m-d H:i:s') . "\n"
            . "IP address: {$ip}\n"
            . "Browser/device: {$agent}\n\n"
            . "If this was not you, review account access and use the approved account recovery process. "
            . "Never share passwords or OTPs. No password is included in this message.";
        $mail->send();
        return true;
    } catch (Throwable $e) {
        error_log('BillGuard: failed to send tier security alert.');
        return false;
    }
}

/**
 * Verify username/password and enforce staged lockout, IP blocking, and audit logs.
 * Returns a user record ONLY when the password is correct and account verified.
 */
function bg_login_password(PDO $db, array $config, array $seed, string $username, string $password): array
{
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'), 0, 45);
    if (bg_ip_is_blocked($db, $ip)) {
        return ['ok' => false, 'message' => 'Too many login failures from this IP address. Try again after 10 minutes.'];
    }

    $alert = null;
    try {
        $db->beginTransaction();
        $q = $db->prepare('SELECT id_number, username, email, password_hash, account_status,
            email_verified_at, consecutive_failures, lockout_until, last_alerted_tier
            FROM users WHERE username = :username LIMIT 1 FOR UPDATE');
        $q->execute([':username' => $username]);
        $user = $q->fetch();
        $now = bg_now();
        $nowSql = $now->format('Y-m-d H:i:s');

        if ($user && $user['lockout_until'] && $user['lockout_until'] > $nowSql) {
            $remaining = max(1, strtotime((string) $user['lockout_until']) - $now->getTimestamp());
            $db->commit();
            return ['ok' => false, 'message' => "Account temporarily locked. Try again in {$remaining} seconds.", 'lockout' => $remaining];
        }

        // A fixed dummy password hash reduces timing differences for unknown usernames.
        $dummyHash = '$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy';
        $match = password_verify($password, $user ? (string) $user['password_hash'] : $dummyHash);
        $verified = $user && $user['account_status'] === 'active'
            && $user['email_verified_at'] !== null && $match;

        if ($verified) {
            $db->commit();
            return ['ok' => true, 'user' => $user];
        }

        $failures = $user ? (int) $user['consecutive_failures'] + 1 : 0;
        if ($user) {
            [$tier, $seconds] = bg_login_lockout($failures, $seed);
            $lockoutUntil = $seconds ? $now->modify("+{$seconds} seconds")->format('Y-m-d H:i:s') : null;
            $lastAlerted = (int) $user['last_alerted_tier'];
            $shouldAlert = $tier > $lastAlerted && $tier > 0
                && $user['account_status'] === 'active'
                && $user['email_verified_at'] !== null;
            $update = $db->prepare('UPDATE users
                SET consecutive_failures = :fails, lockout_until = :until,
                    last_alerted_tier = :alert WHERE id_number = :uid');
            $update->execute([
                ':fails' => $failures,
                ':until' => $lockoutUntil,
                ':alert' => $shouldAlert ? $tier : $lastAlerted,
                ':uid' => $user['id_number'],
            ]);
            if ($tier > 0) bg_log($db, 'LOCKOUT_TIER_' . $tier, $username, $user['id_number'], ['seconds' => $seconds]);
            if ($shouldAlert) {
                $alert = ['to' => $user['email'], 'tier' => $tier, 'fails' => $failures, 'id' => $user['id_number']];
            }
        }

        // Do not disclose account existence in messages or to the attacker.
        bg_log($db, 'LOGIN_FAILED', $username, $user ? $user['id_number'] : null);
        $db->commit();

        $ipBlocked = bg_check_ip_threshold($db, $ip, $seed);
        if ($alert && bg_mail_lockout_alert(
            (string) $alert['to'], $alert['tier'], $alert['fails'], $ip,
            substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'), 0, 300), $config
        )) {
            bg_log($db, 'ALERT_EMAIL_SENT', null, $alert['id'], ['tier' => $alert['tier']]);
        }
        if ($ipBlocked) {
            return ['ok' => false, 'message' => 'Too many login failures from this IP address. Try again after 10 minutes.'];
        }

        return [
            'ok' => false,
            'message' => 'Invalid username or password.',
            'failures' => $failures,
            'lockout' => isset($seconds) ? $seconds : 0,
        ];
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('BillGuard: password login processing failed.');
        return ['ok' => false, 'message' => 'Login is temporarily unavailable. Please try again.'];
    }
}

function bg_verify_login_otp(PDO $db, string $userId, string $code): array
{
    try {
        $db->beginTransaction();
        $lock = $db->prepare("SELECT id_number, account_status, email_verified_at
            FROM users WHERE id_number = :uid FOR UPDATE");
        $lock->execute([':uid' => $userId]);
        $user = $lock->fetch();
        if (!$user || $user['account_status'] !== 'active' || !$user['email_verified_at']) {
            throw new RuntimeException('Account not active.');
        }

        $q = $db->prepare("SELECT id, otp_hash, expires_at, failed_attempts, consumed_at, cancelled_at
            FROM one_time_passwords WHERE user_id = :uid AND purpose = 'login'
            ORDER BY id DESC LIMIT 1 FOR UPDATE");
        $q->execute([':uid' => $userId]);
        $otp = $q->fetch();
        $now = bg_now()->format('Y-m-d H:i:s');

        if (!$otp || $otp['consumed_at'] !== null || $otp['cancelled_at'] !== null) {
            bg_log($db, 'OTP_FAILED', null, $userId, ['purpose' => 'login', 'reason' => 'missing_or_cancelled']);
            $db->commit();
            return ['ok' => false, 'message' => 'Code is invalid or already used. Request a new code.'];
        }
        if ($otp['expires_at'] <= $now || (int) $otp['failed_attempts'] >= 3) {
            $update = $db->prepare('UPDATE one_time_passwords SET cancelled_at = :now WHERE id = :id');
            $update->execute([':now' => $now, ':id' => $otp['id']]);
            bg_log($db, 'OTP_FAILED', null, $userId, ['purpose' => 'login', 'reason' => 'expired']);
            $db->commit();
            return ['ok' => false, 'message' => 'Code expired. Request a new code.'];
        }
        if (!preg_match('/^\d{6}$/D', $code) || !password_verify($code, (string) $otp['otp_hash'])) {
            $failures = (int) $otp['failed_attempts'] + 1;
            $update = $db->prepare('UPDATE one_time_passwords SET failed_attempts = :fails,
                cancelled_at = :cancelled WHERE id = :id');
            $update->execute([
                ':fails' => $failures, ':cancelled' => $failures >= 3 ? $now : null, ':id' => $otp['id']
            ]);
            bg_log($db, 'OTP_FAILED', null, $userId, ['purpose' => 'login', 'attempt' => $failures]);
            $db->commit();
            return ['ok' => false, 'message' => $failures >= 3
                ? 'Three incorrect codes. Request a new code.' : 'Incorrect code. Try again.'];
        }

        $consume = $db->prepare('UPDATE one_time_passwords SET consumed_at = :now WHERE id = :id');
        $consume->execute([':now' => $now, ':id' => $otp['id']]);
        $reset = $db->prepare('UPDATE users SET consecutive_failures = 0,
            lockout_until = NULL, last_alerted_tier = 0 WHERE id_number = :uid');
        $reset->execute([':uid' => $userId]);
        bg_log($db, 'LOGIN_SUCCESS', null, $userId);
        $db->commit();
        return ['ok' => true];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('BillGuard: login OTP verification failed.');
        return ['ok' => false, 'message' => 'Verification unavailable. Please try again.'];
    }
}
