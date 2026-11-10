<?php
declare(strict_types=1);
require_once __DIR__ . '/recovery_questions.php';

/**
 * Reset code verification is the SECOND proof after a correct registered
 * security answer. This function NEVER changes a password or logs the code.
 * A later phase must independently verify both proofs before allowing reset.
 */
function bg_recovery_verify_code(PDO $db, mixed $flow, string $submitted): bool
{
    if (!bg_recovery_flow_ready($flow, time())) return false;
    $candidate = $flow['candidate_id'] ?? null;
    if (!is_string($candidate) || ($flow['code_sent'] ?? false) !== true) {
        // Consume comparable password work for unknown or ineligible accounts.
        password_verify($submitted, bg_recovery_dummy_answer_hash());
        return false;
    }
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'), 0, 45);
    try {
        if (bg_ip_is_blocked($db, $ip)) return false;
        $db->beginTransaction();
        $lock = $db->prepare('SELECT id_number, account_status, email_verified_at, lockout_until
            FROM users WHERE id_number = :uid FOR UPDATE');
        $lock->execute([':uid' => $candidate]);
        $user = $lock->fetch();
        $nowSql = bg_now()->format('Y-m-d H:i:s');
        if (!$user || $user['account_status'] !== 'active'
            || $user['email_verified_at'] === null
            || ($user['lockout_until'] !== null && $user['lockout_until'] > $nowSql)) {
            $db->rollBack();
            return false;
        }

        $q = $db->prepare('SELECT id, otp_hash, expires_at, failed_attempts,
            consumed_at, cancelled_at FROM one_time_passwords
            WHERE user_id = :uid AND purpose = :purpose
            ORDER BY id DESC LIMIT 1 FOR UPDATE');
        $q->execute([':uid' => $candidate, ':purpose' => 'reset']);
        $otp = $q->fetch();
        if (!$otp || $otp['consumed_at'] !== null || $otp['cancelled_at'] !== null) {
            bg_log($db, 'OTP_FAILED', null, $candidate,
                ['purpose' => 'reset', 'reason' => 'missing_or_consumed']);
            $db->commit();
            return false;
        }

        if ($otp['expires_at'] <= $nowSql || (int) $otp['failed_attempts'] >= 3) {
            $cancel = $db->prepare('UPDATE one_time_passwords SET cancelled_at = :now
                WHERE id = :id AND cancelled_at IS NULL');
            $cancel->execute([':now' => $nowSql, ':id' => $otp['id']]);
            bg_log($db, 'OTP_FAILED', null, $candidate,
                ['purpose' => 'reset', 'reason' => 'expired_or_locked']);
            $db->commit();
            return false;
        }
        $format = preg_match('/^[0-9]{6}$/D', $submitted) === 1;
        $valid = password_verify($submitted, (string) $otp['otp_hash']);
        if (!$format || !$valid) {
            $attempts = (int) $otp['failed_attempts'] + 1;
            $update = $db->prepare('UPDATE one_time_passwords
                SET failed_attempts = :attempts, cancelled_at = :cancelled
                WHERE id = :id');
            $update->execute([
                ':attempts' => $attempts,
                ':cancelled' => $attempts >= 3 ? $nowSql : null,
                ':id' => $otp['id'],
            ]);
            bg_log($db, 'OTP_FAILED', null, $candidate,
                ['purpose' => 'reset', 'reason' => 'incorrect', 'attempt' => $attempts]);
            $db->commit();
            return false;
        }

        $consume = $db->prepare('UPDATE one_time_passwords
            SET consumed_at = :now WHERE id = :id AND consumed_at IS NULL
                AND cancelled_at IS NULL');
        $consume->execute([':now' => $nowSql, ':id' => $otp['id']]);
        if ($consume->rowCount() !== 1) {
            throw new RuntimeException('Reset code already consumed.');
        }
        bg_log($db, 'RECOVERY_CODE_VERIFIED', null, $candidate,
            ['purpose' => 'reset']);
        $db->commit();
        return true;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('BillGuard: reset code verification failed.');
        return false;
    }
}
