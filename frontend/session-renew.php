<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/backend/php/auth.php';
bg_secure_start();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false]);
    exit;
}
if (!bg_check_csrf($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit;
}
try {
    $config = billguard_config();
    $seed = bg_seed($config);
    if ($seed === null) throw new RuntimeException('Seed configuration missing.');
    $db = billguard_db();
    // Only a deliberate CSRF-checked POST can renew. The existing auth
    // check rejects idle expiry and password-reset-revoked sessions first.
    $user = bg_auth_user($db, $seed, true);
    if ($user === null) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'expired' => true]);
        exit;
    }
    $now = time();
    echo json_encode([
        'ok' => true,
        'server_time' => $now,
        'expires_at' => (int) $_SESSION['last_activity'] + bg_idle_timeout_seconds($seed),
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    error_log('BillGuard: session renewal failed.');
    http_response_code(503);
    echo json_encode(['ok' => false]);
}
