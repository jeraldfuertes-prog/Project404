<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/php/registration.php';

$checks = 0;
$failed = 0;
function check(bool $condition, string $label): void {
    global $checks, $failed;
    $checks++;
    if ($condition) { echo "[OK]   {$label}\n"; }
    else { echo "[FAIL] {$label}\n"; $failed++; }
}

// These are illustrative test digits, NOT the student's personal seed.
$seed = bg_seed(['seed_d1' => 4, 'seed_d2' => 7]);
check($seed !== null, 'Seed calculator accepts two digits');
check($seed['username_min'] === 7 && $seed['username_max'] === 18, 'Username limits match formulas');
check($seed['password_min'] === 11 && $seed['name_max'] === 27, 'Password/name limits match formulas');
check($seed['otp_expiry'] === 4, 'OTP expiry matches formula');
check($seed['question_ids'] === [8, 3, 5], 'Question sequence matches formula');

check(bg_name_error('Juan Carlo', 'First Name', 27) === null, 'Title Case name accepted');
check(bg_name_error('juan', 'First Name', 27) === null, 'Lowercase name accepted');
check(bg_name_error('FUERTES', 'Family Name', 27) === null, 'All-capitals name accepted');
check(bg_name_error("O'Connor", 'Family Name', 27) === null, 'Apostrophe name accepted');
check(bg_name_error('María-José', 'First Name', 27) === null, 'Accents and hyphen accepted');
check(bg_name_error('Ju4n@', 'First Name', 27) === null, 'Printable special characters accepted');
check(bg_name_error('Juan  Carlo', 'First Name', 27) === null, 'Double spaces permitted');
check(bg_name_error('Juaaan', 'First Name', 27) === null, 'Repeated letters permitted');
check(bg_name_error('J', 'First Name', 27) !== null, 'Too-short name rejected');
check(bg_name_error("Line\nBreak", 'First Name', 27) !== null, 'Control characters in names rejected');
check(bg_home_address_error('Purok 3, San Isidro, Cabadbaran City') === null, 'Ordinary comma-separated address accepted');
check(bg_home_address_error('Bl. #2-A / Rúa São João (Unit #4)') === null, 'Special characters and accents accepted in address');
check(bg_home_address_error("Apt. 1\nLine 2") !== null, 'Control characters in address rejected');
check(bg_home_address_error('A') !== null, 'Too-short address rejected');
check(bg_address_error('Purok 3', 'Purok / Street', true) === null, 'Street with digit valid');
check(bg_address_error('Purok@3', 'Purok / Street', true) !== null, 'Special character street rejected');
check(bg_address_error('San Isidro', 'Barangay', false) === null, 'Address Title Case valid');
check(bg_address_error('san isidro', 'Barangay', false) !== null, 'Address lowercase rejected');
check(bg_date('2024-02-29') !== null, 'Leap day accepted');
check(bg_date('2023-02-29') === null, 'Invalid day rejected');
check(bg_strength('abc', 11) === 'Weak', 'Too-short password is Weak');
check(bg_strength('abcDEF1234!@#$%$', 11) === 'Strong', 'Long 4-class password is Strong');

echo "\n{$checks} checks; {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
