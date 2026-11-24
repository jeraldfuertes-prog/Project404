<?php
declare(strict_types=1);
require_once __DIR__ . '/recovery_code.php';
require_once __DIR__ . '/registration.php';

/**
 * Only an email-code AND registered-question verified server-side session
 * may reset a password. The actual database row and used OTP are checked
 * again under transaction locks before changing anything.
 */
function bg_reset_flow_ready(mixed $flow, int $now): bool
{
    if (!is_array($flow) || ($flow['stage'] ?? null) !== 'identity_verified'
        || ($flow['code_sent'] ?? null) !== true
        || !is_string($flow['candidate_id'] ?? null)
        || preg_match('/^[0-9]{4}-[0-9]{4}$/D', $flow['candidate_id']) !== 1
        || !is_int($flow['started_at'] ?? null)
        || !is_int($flow['identity_verified_at'] ?? null)
        || !is_int($flow['reset_otp_id'] ?? null)
        || $flow['reset_otp_id'] <= 0
        || !is_string($flow['password_fingerprint'] ?? null)
        || preg_match('/^[a-f0-9]{64}$/D', $flow['password_fingerprint']) !== 1) {
        return false;
    }
    return $flow['started_at'] > 0 && $flow['started_at'] <= $now
        && $flow['identity_verified_at'] >= $flow['started_at']
        && $flow['identity_verified_at'] <= $now
        && ($now - $flow['started_at']) <= 600;
}

/** Use exactly the registration password policy, including seeded minimum. */
function bg_reset_password_error(string $password, array $seed): ?string
{
    $min = (int) ($seed['password_min'] ?? 0);
    if ($min < 8 || $min > 64) return 'Password policy is unavailable.';
    if (strlen($password) < $min || strlen($password) > 64) {
        return "Password must be between {$min} and 64 characters.";
    }
    if (preg_match('/[\x00-\x1F\x7F]/', $password) === 1) {
        return 'Password must not contain control characters.';
    }
    if (bg_strength($password, $min) === 'Weak') {
        return 'Password is too weak. Use at least three of: uppercase, lowercase, digits, and symbols.';
    }
    return null;
}

/** Check the current password AND up to three previous hashes. */
function bg_reset_password_reused(string $password, string $current, array $history): bool
{
    if (password_verify($password, $current)) return true;
    foreach (array_slice($history, 0, 3) as $hash) {
        if (is_string($hash) && password_verify($password, $hash)) return true;
    }
    return false;
}

/**
 * Snapshot the consumed OTP row and current password version immediately
 * after BOTH proofs were checked. Never expose this structure in HTML/JS.
 * The reset transaction will independently recheck both values.
 *
 * @return array{reset_otp_id:int,password_fingerprint:string}|null
 */
function bg_reset_create_proof(PDO $db, string $id): ?array
{
    if (preg_match('/^[0-9]{4}-[0-9]{4}$/D', $id) !== 1) return null;
    try {
        $q = $db->prepare("SELECT password_hash FROM users WHERE id_number = :id
            AND account_status = 'active' AND email_verified_at IS NOT NULL LIMIT 1");
        $q->execute([':id' => $id]);
        $hash = $q->fetchColumn();
        if (!is_string($hash) || $hash === '') return null;
        $otp = $db->prepare("SELECT id FROM one_time_passwords
            WHERE user_id = :id AND purpose = 'reset'
            ORDER BY id DESC LIMIT 1");
        $otp->execute([':id' => $id]);
        $otpId = $otp->fetchColumn();
        if ($otpId === false) return null;
        return [
            'reset_otp_id' => (int) $otpId,
            'password_fingerprint' => hash('sha256', $hash),
        ];
    } catch (Throwable $e) {
        error_log('BillGuard: reset proof preparation failed.');
        return null;
    }
}

/** Notification is best effort AFTER commit; failure does not undo password change. */
function bg_reset_success_email(string $email, array $config): bool
{
    try {
        $mail = bg_configured_mailer($config);
        $mail->addAddress($email);
        $mail->Subject = 'BillGuard - Your password was changed';
        $mail->Body = "Your BillGuard password was changed successfully.\n"
            . 'Time (PHT): ' . bg_now()->format('Y-m-d H:i:s') . "\n"
            . "If you did not request this change, contact your administrator immediately.\n"
            . 'BillGuard will never ask you to share your password or verification codes.';
        $mail->send();
        return true;
    } catch (Throwable $e) {
        error_log('BillGuard: password-change notification email failed.');
        return false;
    }
}

/**
 * Atomic reset: account/OTP proof -> current and last three history checks
 * -> password hash update -> history pruning -> invalidate pending codes.
 * This function NEVER uses a raw username, email or user ID from the browser.
 *
 * @return array{ok:bool,message:string}
 */
function bg_reset_password(
    PDO $db, mixed $flow, array $seed, array $config,
    string $newPassword, string $confirmation
): array {
    $denied = [
        'ok' => false,
        'message' => 'The recovery verification is invalid or expired. Start recovery again.',
    ];
    if (!bg_reset_flow_ready($flow, time())) return $denied;
    if (!hash_equals($newPassword, $confirmation)) {
        return ['ok' => false, 'message' => 'New password and confirmation do not match.'];
    }
    $policy = bg_reset_password_error($newPassword, $seed);
    if ($policy !== null) return ['ok' => false, 'message' => $policy];

    $id = $flow['candidate_id'];
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'), 0, 45);
    try {
        if (bg_ip_is_blocked($db, $ip)) return $denied;
        $db->beginTransaction();
        $q = $db->prepare('SELECT id_number, email, password_hash, account_status,
            email_verified_at, lockout_until FROM users WHERE id_number = :uid FOR UPDATE');
        $q->execute([':uid' => $id]);
        $user = $q->fetch();
        $now = bg_now()->format('Y-m-d H:i:s');
        if (!$user || $user['account_status'] !== 'active'
            || $user['email_verified_at'] === null
            || ($user['lockout_until'] !== null && $user['lockout_until'] > $now)
            || !hash_equals(
                $flow['password_fingerprint'], hash('sha256', (string) $user['password_hash'])
            )) {
            $db->rollBack();
            return $denied;
        }

        // The very OTP accepted by the two-proof session must be the latest
        // reset-purpose OTP, consumed, and not cancelled or replaced.
        $q = $db->prepare("SELECT id, created_at, expires_at, consumed_at, cancelled_at
            FROM one_time_passwords WHERE user_id = :uid AND purpose = 'reset'
            ORDER BY id DESC LIMIT 1 FOR UPDATE");
        $q->execute([':uid' => $id]);
        $code = $q->fetch();
        $started = (new DateTimeImmutable('@' . $flow['started_at']))
            ->setTimezone(new DateTimeZone('Asia/Manila'))->format('Y-m-d H:i:s');
        if (!$code || (int) $code['id'] !== $flow['reset_otp_id']
            || $code['consumed_at'] === null || $code['cancelled_at'] !== null
            || $code['created_at'] < $started
            || $code['consumed_at'] < $started) {
            $db->rollBack();
            return $denied;
        }

        $hist = $db->prepare('SELECT id, password_hash FROM password_history
            WHERE user_id = :uid ORDER BY id DESC FOR UPDATE');
        $hist->execute([':uid' => $id]);
        $previous = $hist->fetchAll();
        $hashes = array_map(static fn(array $row): string => (string) $row['password_hash'], $previous);
        if (bg_reset_password_reused(
            $newPassword, (string) $user['password_hash'], $hashes
        )) {
            $db->rollBack();
            return [
                'ok' => false,
                'message' => 'Choose a password different from the current and last three passwords.',
            ];
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        if (!is_string($newHash)) throw new RuntimeException('Hashing failed.');
        $record = $db->prepare('INSERT INTO password_history
            (user_id, password_hash) VALUES (:uid, :old)');
        $record->execute([':uid' => $id, ':old' => $user['password_hash']]);

        $update = $db->prepare('UPDATE users SET password_hash = :new,
            consecutive_failures = 0, lockout_until = NULL, last_alerted_tier = 0
            WHERE id_number = :uid');
        $update->execute([':new' => $newHash, ':uid' => $id]);

        // Save only the last THREE previous hashes, newest first.
        $historyIds = $db->prepare('SELECT id FROM password_history
            WHERE user_id = :uid ORDER BY id DESC');
        $historyIds->execute([':uid' => $id]);
        $older = array_slice($historyIds->fetchAll(PDO::FETCH_COLUMN, 0), 3);
        $remove = $db->prepare('DELETE FROM password_history WHERE id = :id AND user_id = :uid');
        foreach ($older as $oldId) {
            $remove->execute([':id' => $oldId, ':uid' => $id]);
        }

        // A password change invalidates all outstanding login and reset OTPs.
        // Existing authenticated PHP sessions are rejected via password version.
        $cancel = $db->prepare("UPDATE one_time_passwords SET cancelled_at = :now
            WHERE user_id = :uid AND purpose IN ('login','reset')
              AND cancelled_at IS NULL");
        $cancel->execute([':now' => $now, ':uid' => $id]);
        bg_log($db, 'PASSWORD_RESET', null, $id,
            ['method' => 'verified_email_code_and_security_answer']);
        $db->commit();
        bg_reset_success_email((string) $user['email'], $config);
        return ['ok' => true, 'message' => 'Password changed. Sign in with your new password.'];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('BillGuard: password reset transaction failed.');
        return ['ok' => false, 'message' => 'Password reset is temporarily unavailable. Please try again.'];
    }
}
