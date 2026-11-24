<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/backend/php/auth.php';
bg_secure_start();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['ok' => false]);
    exit;
}
try {
    $config = billguard_config();
    $seed = bg_seed($config);
    if ($seed === null) throw new RuntimeException('Seed configuration missing.');
    $db = billguard_db();
    // Read-only session check: MUST NOT extend last_activity.
    $user = bg_auth_user($db, $seed, false);
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
    error_log('BillGuard: session status check failed.');
    http_response_code(503);
    echo json_encode(['ok' => false]);
}
