<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Internal Phase 2 security helpers. These functions NEVER trust browser input.
 * The document root is frontend/, so this file is not a public endpoint.
 */

function bg_secure_start(): void
{
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    $privateLogs = dirname(__DIR__) . '/logs';
    if (!is_dir($privateLogs)) {
        @mkdir($privateLogs, 0700, true);
    }
    if (is_dir($privateLogs)) {
        ini_set('error_log', $privateLogs . '/php-error.log');
    }

    date_default_timezone_set('Asia/Manila');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');

    if (session_status() !== PHP_SESSION_ACTIVE) {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name('BILLGUARDSESSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function bg_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
}

function bg_seed(array $config): ?array
{
    $d1 = $config['seed_d1'] ?? null;
    $d2 = $config['seed_d2'] ?? null;

    if (!is_int($d1) || !is_int($d2) || $d1 < 0 || $d1 > 9 || $d2 < 0 || $d2 > 9) {
        return null;
    }
    $q = [
        ($d2 % 8) + 1,
        (($d2 + 3) % 8) + 1,
        (($d2 + 5) % 8) + 1,
    ];

    return [
        'd1' => $d1,
        'd2' => $d2,
        'username_min' => 6 + ($d1 % 3),
        'username_max' => 16 + ($d2 % 5),
        'password_min' => 8 + ($d2 % 4),
        'name_max' => 20 + $d2,
        'otp_expiry' => 3 + ($d2 % 3),
        'question_ids' => $q,
    ];
}

function bg_registration_ready(array $config): bool
{
    if (bg_seed($config) === null) {
        return false;
    }
    foreach (['privacy_contact', 'privacy_retention'] as $required) {
        if (!is_string($config[$required] ?? null)
            || trim($config[$required]) === ''
            || str_contains($config[$required], 'REPLACE_WITH_')) {
            return false;
        }
    }
    if (!filter_var($config['privacy_contact'], FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $key = (string) ($config['encryption_key'] ?? '');
    if (!str_starts_with($key, 'base64:')) {
        return false;
    }
    $binaryKey = base64_decode(substr($key, 7), true);
    if ($binaryKey === false || strlen($binaryKey) !== 32) {
        return false;
    }
    foreach (['smtp_host', 'smtp_user', 'smtp_password', 'smtp_from'] as $k) {
        if (!is_string($config[$k] ?? null)
            || trim($config[$k]) === ''
            || str_contains($config[$k], 'REPLACE_WITH_')) {
            return false;
        }
    }
    return true;
}

function bg_csrf_token(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function bg_check_csrf(mixed $token): bool
{
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function bg_csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(bg_csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . '">';
}

function bg_safe(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function bg_log(PDO $db, string $event, ?string $username = null, ?string $userId = null, array $details = []): void
{
    $sql = 'INSERT INTO activity_log (event_type, username_typed, user_id, ip_address, user_agent, details)
            VALUES (:event, :username, :userid, :ip, :agent, :details)';
    $query = $db->prepare($sql);
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'), 0, 45);
    $agent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'CLI'), 0, 512);
    $query->execute([
        ':event' => $event,
        ':username' => $username === null ? null : substr($username, 0, 255),
        ':userid' => $userId,
        ':ip' => $ip,
        ':agent' => $agent,
        ':details' => empty($details) ? null : json_encode($details, JSON_THROW_ON_ERROR),
    ]);
}

function bg_encrypt_utility(string $account, array $config): array
{
    $key = base64_decode(substr((string) $config['encryption_key'], 7), true);
    if ($key === false || strlen($key) !== 32) {
        throw new RuntimeException('Encryption setup is incomplete.');
    }
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($account, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($ciphertext === false || strlen($tag) !== 16) {
        throw new RuntimeException('Sensitive data encryption failed.');
    }
    return [$ciphertext, $iv, $tag];
}

/** Future account pages must verify ownership BEFORE calling this decryptor. */
function bg_decrypt_utility(string $ciphertext, string $iv, string $tag, array $config): string
{
    $key = base64_decode(substr((string) $config['encryption_key'], 7), true);
    if ($key === false || strlen($key) !== 32) {
        throw new RuntimeException('Encryption setup is incomplete.');
    }
    $text = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($text === false) {
        throw new RuntimeException('Sensitive data could not be decrypted.');
    }
    return $text;
}

function bg_questions(): array
{
    return [
        1 => 'What was the name of your first pet?',
        2 => 'Who was your best friend in elementary school?',
        3 => 'Who was your favorite teacher in high school?',
        4 => "What is your mother's maiden first name?",
        5 => 'What street did you live on in Grade 1?',
        6 => 'What was the first song you learned by heart?',
        7 => 'What was the name of your elementary school?',
        8 => 'What dish did you cook for the first time?',
    ];
}
