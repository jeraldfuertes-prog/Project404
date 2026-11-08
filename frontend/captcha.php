<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/backend/php/security.php';
bg_secure_start();

if (!extension_loaded('gd')) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'CAPTCHA unavailable: enable the GD extension in PHP.';
    exit;
}

// Every new image invalidates the previous answer.
$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
$answer = '';
for ($i = 0; $i < 6; $i++) {
    $answer .= $alphabet[random_int(0, strlen($alphabet) - 1)];
}
$_SESSION['captcha_answer'] = $answer;
$_SESSION['captcha_created'] = time();

$w = 232;
$h = 78;
$im = imagecreatetruecolor($w, $h);
if ($im === false) {
    http_response_code(503);
    exit;
}
$bg = imagecolorallocate($im, 9, 19, 22);
$green = imagecolorallocate($im, 0, 245, 102);
$cyan = imagecolorallocate($im, 0, 218, 218);
$magenta = imagecolorallocate($im, 190, 38, 160);
$noise = imagecolorallocate($im, 48, 93, 92);
imagefilledrectangle($im, 0, 0, $w, $h, $bg);
for ($i = 0; $i < 14; $i++) {
    imageline($im, random_int(0, $w), random_int(0, $h),
        random_int(0, $w), random_int(0, $h), $noise);
}
for ($i = 0; $i < 95; $i++) {
    imagesetpixel($im, random_int(0, $w - 1), random_int(0, $h - 1), $noise);
}
for ($i = 0; $i < strlen($answer); $i++) {
    $x = 14 + ($i * 35) + random_int(-3, 3);
    $y = 20 + random_int(-9, 9);
    imagestring($im, 5, $x, $y, $answer[$i], [$green, $cyan, $magenta][$i % 3]);
}
// Noise crossing letters makes basic OCR harder; backend always validates.
for ($i = 0; $i < 3; $i++) {
    imageline($im, random_int(0, 30), random_int(28, 65),
        random_int(200, 231), random_int(15, 75), $noise);
}
header('Content-Type: image/png');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
imagepng($im);
imagedestroy($im);
