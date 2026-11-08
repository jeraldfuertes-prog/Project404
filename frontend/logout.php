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
    http_response_code(403);
    exit('Invalid request token.');
}
bg_sign_out();
header('Location: login.php', true, 303);
exit;
