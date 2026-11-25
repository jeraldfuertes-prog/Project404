<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/backend/php/auth.php';
bg_secure_start();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}
if (!bg_check_csrf($_POST['csrf_token'] ?? null)) {
    // Denied requests are logged without any CSRF token or cookie values.
    try {
        $db = billguard_db();
        bg_log($db, 'CSRF_REJECTED', null, null, ['page' => 'logout']);
    } catch (Throwable $e) {
        error_log('BillGuard: logout CSRF audit failed.');
    }
    http_response_code(403);
    exit('Invalid request token.');
}
// An explicit, valid logout is its own event (not a LOGIN_FAILED event).
// Never trust a user id provided in POST or URL parameters.
try {
    $seed = bg_seed(billguard_config());
    if ($seed !== null) {
        $db = billguard_db();
        $user = bg_auth_user($db, $seed, false);
        if ($user !== null) {
            bg_log($db, 'LOGOUT', null, (string) $user['id_number'],
                ['reason' => 'user_initiated']);
        }
    }
} catch (Throwable $e) {
    // Audit failure must never trap someone in an authenticated session.
    error_log('BillGuard: logout activity audit failed.');
}
if (session_status() === PHP_SESSION_ACTIVE) bg_sign_out();
header('Location: login.php', true, 303);
exit;
