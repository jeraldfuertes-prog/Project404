<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

/**
 * Protection #13: per-owner AES-256-GCM encrypted Utility Account Number.
 * Only call these helpers with the authenticated user's ID from bg_app_context,
 * NEVER with an ID supplied by a form, URL parameter, or another account.
 * No plaintext account numbers, keys or ciphertext are written to activity logs.
 */

function bg_utility_account_error(string $value): ?string
{
    return preg_match('/^[0-9]{8,12}$/D', $value) === 1
        ? null : 'Utility Account Number must contain 8 to 12 digits.';
}

/** Require a correctly formatted private 256-bit key; never fall back to plaintext. */
function bg_utility_key_ready(array $config): bool
{
    $encoded = $config['encryption_key'] ?? null;
    if (!is_string($encoded) || !str_starts_with($encoded, 'base64:')) return false;
    $key = base64_decode(substr($encoded, 7), true);
    return is_string($key) && strlen($key) === 32;
}

/** Compatible with existing full and simplified BillGuard users tables. */
function bg_utility_schema_ready(PDO $db): bool
{
    $q = $db->query("SELECT column_name FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'users'
          AND column_name IN ('utility_account_ciphertext',
                              'utility_account_iv', 'utility_account_tag')");
    return count($q->fetchAll(PDO::FETCH_COLUMN)) === 3;
}

/** True for the current authenticated owner only; no ciphertext sent to client. */
function bg_utility_account_present(PDO $db, string $ownerId): bool
{
    $q = $db->prepare("SELECT utility_account_ciphertext IS NOT NULL
        FROM users WHERE id_number = :uid AND account_status = 'active' LIMIT 1");
    $q->execute([':uid' => $ownerId]);
    return (int) $q->fetchColumn() === 1;
}

/**
 * Encrypt before storage using a NEW random IV on every save.
 * All database writes, including the audit event, are one transaction.
 * The owner ID must come from the authenticated session (not $_POST).
 *
 * @return array{ok:bool,message:string}
 */
function bg_utility_account_save(PDO $db, string $ownerId, string $number, array $config): array
{
    if (($error = bg_utility_account_error($number)) !== null) {
        return ['ok' => false, 'message' => $error];
    }
    if (!bg_utility_key_ready($config)) {
        return ['ok' => false, 'message' => 'Encryption is not configured. Contact the administrator.'];
    }
    try {
        [$ciphertext, $iv, $tag] = bg_encrypt_utility($number, $config);
        $db->beginTransaction();
        $lock = $db->prepare("SELECT id_number FROM users
            WHERE id_number = :uid AND account_status = 'active' FOR UPDATE");
        $lock->execute([':uid' => $ownerId]);
        if (!$lock->fetchColumn()) {
            $db->rollBack();
            return ['ok' => false, 'message' => 'Your account session is no longer valid.'];
        }
        $q = $db->prepare("UPDATE users SET
            utility_account_ciphertext = :ciphertext,
            utility_account_iv = :iv,
            utility_account_tag = :tag
            WHERE id_number = :uid AND account_status = 'active'");
        $q->execute([
            ':ciphertext' => $ciphertext,
            ':iv' => $iv,
            ':tag' => $tag,
            ':uid' => $ownerId,
        ]);
        bg_log($db, 'UTILITY_ACCOUNT_SAVED', null, $ownerId);
        $db->commit();
        return ['ok' => true, 'message' => 'Utility Account Number encrypted and saved securely.'];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('BillGuard: encrypted utility account save failed.');
        return ['ok' => false, 'message' => 'Could not save the utility account number. Please try again.'];
    }
}

/**
 * Decrypt only AFTER authenticated profile authorization and a CSRF-protected
 * Reveal POST. The plaintext is ephemeral and must never be placed in a
 * log, URL, hidden field, session, cookie, or browser local storage.
 */
function bg_utility_account_reveal(PDO $db, string $ownerId, array $config): ?string
{
    if (!bg_utility_key_ready($config)) {
        throw new RuntimeException('Encryption is not configured.');
    }
    $q = $db->prepare("SELECT utility_account_ciphertext,
        utility_account_iv, utility_account_tag FROM users
        WHERE id_number = :uid AND account_status = 'active' LIMIT 1");
    $q->execute([':uid' => $ownerId]);
    $row = $q->fetch();
    if (!$row || $row['utility_account_ciphertext'] === null) return null;
    if (!is_string($row['utility_account_iv']) || !is_string($row['utility_account_tag'])
        || strlen($row['utility_account_iv']) !== 12 || strlen($row['utility_account_tag']) !== 16) {
        throw new RuntimeException('Encrypted utility account record is incomplete.');
    }
    return bg_decrypt_utility(
        (string) $row['utility_account_ciphertext'],
        $row['utility_account_iv'], $row['utility_account_tag'], $config
    );
}

/** Owner-controlled removal; the encryption key is not deleted. */
function bg_utility_account_remove(PDO $db, string $ownerId): bool
{
    try {
        $db->beginTransaction();
        $lock = $db->prepare("SELECT id_number FROM users
            WHERE id_number = :uid AND account_status = 'active' FOR UPDATE");
        $lock->execute([':uid' => $ownerId]);
        if (!$lock->fetchColumn()) {
            $db->rollBack();
            return false;
        }
        $q = $db->prepare("UPDATE users SET
            utility_account_ciphertext = NULL, utility_account_iv = NULL,
            utility_account_tag = NULL
            WHERE id_number = :uid AND account_status = 'active'");
        $q->execute([':uid' => $ownerId]);
        bg_log($db, 'UTILITY_ACCOUNT_REMOVED', null, $ownerId);
        $db->commit();
        return true;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('BillGuard: encrypted utility account removal failed.');
        return false;
    }
}
