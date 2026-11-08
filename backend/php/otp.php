<?php
declare(strict_types=1);
require_once __DIR__ . '/mailer.php';

/**
 * OTP lifecycle. Codes are random, hashed, purpose-bound, single-use,
 * time-limited, and capped at three wrong entries.
 */

function bg_send_otp(PDO $db, string $userId, string $email, string $purpose, array $config, array $seed, bool $resend): array
{
    if (!in_array($purpose, ['register', 'login', 'reset'], true)) {
        return ['ok' => false, 'message' => 'Unsupported OTP purpose.'];
    }
    $now = bg_now();
    $nowSql = $now->format('Y-m-d H:i:s');
    $window = $now->modify('-15 minutes')->format('Y-m-d H:i:s');
    $latest = $db->prepare('SELECT created_at FROM one_time_passwords
                            WHERE user_id = :uid AND purpose = :purpose
                            ORDER BY id DESC LIMIT 1');
    $latest->execute([':uid' => $userId, ':purpose' => $purpose]);
    $last = $latest->fetch();

    if ($resend) {
        $countQ = $db->prepare('SELECT COUNT(*) FROM one_time_passwords
            WHERE user_id = :uid AND purpose = :purpose AND created_at >= :window');
        $countQ->execute([':uid' => $userId, ':purpose' => $purpose, ':window' => $window]);
        // Initial code + at most 3 resends in any 15-minute window.
        if ((int) $countQ->fetchColumn() >= 4) {
            return ['ok' => false, 'message' => 'Maximum of three resends in 15 minutes reached. Try again later.'];
        }
        if ($last) {
            $seconds = $now->getTimestamp() - strtotime((string) $last['created_at']);
            if ($seconds < 60) {
                $left = max(1, 60 - $seconds);
                return ['ok' => false, 'message' => "Please wait {$left} seconds before resending.", 'wait' => $left];
            }
        }
    }
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $hash = password_hash($code, PASSWORD_DEFAULT);
    $expires = $now->modify('+' . $seed['otp_expiry'] . ' minutes');
    $expiry = $expires->format('Y-m-d H:i:s');

    try {
        $db->beginTransaction();
        $cancel = $db->prepare('UPDATE one_time_passwords SET cancelled_at = :now
            WHERE user_id = :uid AND purpose = :purpose AND consumed_at IS NULL AND cancelled_at IS NULL');
        $cancel->execute([':now' => $nowSql, ':uid' => $userId, ':purpose' => $purpose]);

        $insert = $db->prepare('INSERT INTO one_time_passwords
            (user_id, purpose, otp_hash, expires_at, created_at)
            VALUES (:uid, :purpose, :hash, :expiry, :created)');
        $insert->execute([
            ':uid' => $userId, ':purpose' => $purpose, ':hash' => $hash,
            ':expiry' => $expiry, ':created' => $nowSql,
        ]);
        bg_mail_otp($email, $code, $purpose, $expiry, $config);
        bg_log($db, 'OTP_SENT', null, $userId, ['purpose' => $purpose, 'resend' => $resend]);
        $db->commit();
        return ['ok' => true, 'message' => 'A six-digit code was sent to your email.', 'wait' => 60, 'expiry' => $seed['otp_expiry']];
    } catch (Throwable $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        error_log('BillGuard: OTP email delivery or database transaction failed.');
        return ['ok' => false, 'message' => 'Unable to send the code right now. Check your SMTP setup and try again.'];
    }
}

function bg_verify_registration_otp(PDO $db, string $userId, string $submitted): array
{
    if (!preg_match('/^\d{6}$/D', $submitted)) {
        return ['ok' => false, 'message' => 'Enter the six-digit code from your email.'];
    }
    try {
        $db->beginTransaction();
        $q = $db->prepare('SELECT id, otp_hash, expires_at, failed_attempts, consumed_at, cancelled_at
            FROM one_time_passwords WHERE user_id = :uid AND purpose = :purpose
            ORDER BY id DESC LIMIT 1 FOR UPDATE');
        $q->execute([':uid' => $userId, ':purpose' => 'register']);
        $record = $q->fetch();
        $nowSql = bg_now()->format('Y-m-d H:i:s');

        if (!$record || $record['consumed_at'] !== null || $record['cancelled_at'] !== null) {
            bg_log($db, 'OTP_FAILED', null, $userId, ['purpose' => 'register', 'reason' => 'missing_or_cancelled']);
            $db->commit();
            return ['ok' => false, 'message' => 'This code is no longer valid. Request a new code.'];
        }

        if ($record['expires_at'] <= $nowSql || (int) $record['failed_attempts'] >= 3) {
            $cancel = $db->prepare('UPDATE one_time_passwords SET cancelled_at = :now WHERE id = :id');
            $cancel->execute([':now' => $nowSql, ':id' => $record['id']]);
            bg_log($db, 'OTP_FAILED', null, $userId, ['purpose' => 'register', 'reason' => 'expired_or_locked']);
            $db->commit();
            return ['ok' => false, 'message' => 'This code has expired or been cancelled. Request a new code.'];
        }

        if (!password_verify($submitted, (string) $record['otp_hash'])) {
            $failCount = (int) $record['failed_attempts'] + 1;
            $update = $db->prepare('UPDATE one_time_passwords SET failed_attempts = :attempts,
                cancelled_at = :cancelled WHERE id = :id');
            $update->execute([
                ':attempts' => $failCount,
                ':cancelled' => $failCount >= 3 ? $nowSql : null,
                ':id' => $record['id'],
            ]);
            bg_log($db, 'OTP_FAILED', null, $userId, ['purpose' => 'register', 'attempt' => $failCount]);
            $db->commit();
            return ['ok' => false, 'message' => $failCount >= 3
                ? 'Three wrong codes entered. Request a new code.'
                : 'Incorrect OTP. Please try again.'];
        }

        $consume = $db->prepare('UPDATE one_time_passwords SET consumed_at = :now WHERE id = :id');
        $consume->execute([':now' => $nowSql, ':id' => $record['id']]);
        $activate = $db->prepare("UPDATE users SET account_status = 'active',
            email_verified_at = :now WHERE id_number = :uid AND account_status = 'pending'");
        $activate->execute([':now' => $nowSql, ':uid' => $userId]);
        if ($activate->rowCount() !== 1) {
            throw new RuntimeException('Account not pending or already activated.');
        }
        bg_log($db, 'ACCOUNT_VERIFIED', null, $userId);
        $db->commit();
        return ['ok' => true, 'message' => 'Your BillGuard account has been verified.'];
    } catch (Throwable $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        error_log('BillGuard: registration OTP verification failed.');
        return ['ok' => false, 'message' => 'Verification is temporarily unavailable. Please try again.'];
    }
}
