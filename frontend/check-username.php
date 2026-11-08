<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/backend/php/security.php';
bg_secure_start();
header('Content-Type: application/json; charset=utf-8');

try {
    $config = billguard_config();
    $seed = bg_seed($config);
    if ($seed === null) {
        http_response_code(503);
        echo json_encode(['valid' => false, 'available' => false, 'message' => 'Registration is not configured.']);
        exit;
    }

    $username = (string) ($_GET['username'] ?? '');
    if (strlen($username) < $seed['username_min']
        || strlen($username) > $seed['username_max']
        || !preg_match('/^[A-Za-z][A-Za-z0-9]*$/D', $username)) {
        echo json_encode([
            'valid' => false,
            'available' => false,
            'message' => "Use {$seed['username_min']}–{$seed['username_max']} letters or digits; start with a letter.",
        ]);
        exit;
    }

    $db = billguard_db();
    $q = $db->prepare('SELECT 1 FROM users WHERE username = :username LIMIT 1');
    $q->execute([':username' => $username]);
    $taken = (bool) $q->fetchColumn();
    echo json_encode([
        'valid' => true,
        'available' => !$taken,
        'message' => $taken ? 'This username is already taken.' : 'Username is available.',
    ]);
} catch (Throwable $e) {
    error_log('BillGuard: username availability check failed.');
    http_response_code(503);
    echo json_encode(['valid' => false, 'available' => false, 'message' => 'Unable to check availability right now.']);
}
