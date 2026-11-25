<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/backend/php/admin_audit.php';
bg_secure_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['ok' => false]);
    exit;
}
try {
    $seed = bg_seed(billguard_config());
    if ($seed === null) throw new RuntimeException('Seed configuration missing.');
    $db = billguard_db();
    $admin = bg_admin_audit_authorize($db, $seed, 'feed');
    if ($admin === null) {
        http_response_code(403);
        echo json_encode(['ok' => false]);
        exit;
    }
    $events = bg_admin_audit_latest($db);
    bg_admin_audit_viewed($db, $admin, 'feed');
    echo json_encode(['ok' => true, 'events' => $events], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    error_log('BillGuard: admin security event feed unavailable.');
    http_response_code(503);
    echo json_encode(['ok' => false]);
}
