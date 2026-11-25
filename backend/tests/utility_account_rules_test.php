<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/php/utility_account.php';

$checks = 0;
$failures = 0;
function utility_check(bool $condition, string $name): void
{
    global $checks, $failures;
    $checks++;
    echo ($condition ? '[OK]   ' : '[FAIL] ') . $name . PHP_EOL;
    if (!$condition) $failures++;
}
utility_check(bg_utility_account_error('12345678') === null, '8-digit minimum accepted');
utility_check(bg_utility_account_error('123456789012') === null, '12-digit maximum accepted');
foreach (['', '1234567', '1234567890123', 'AB123456', '1234-5678',
          '1234 5678', "12345678\n", '１２３４５６７８'] as $invalid) {
    utility_check(bg_utility_account_error($invalid) !== null,
        'Nonconforming utility number is rejected');
}
utility_check(!bg_utility_key_ready(['encryption_key' => 'REPLACE_WITH_KEY']),
    'Placeholder key rejected; feature fails closed');
utility_check(!bg_utility_key_ready(['encryption_key' => 'base64:!!!']),
    'Invalid base64 key rejected');
utility_check(!bg_utility_key_ready(['encryption_key' => 'base64:' . base64_encode(random_bytes(16))]),
    'Non-256-bit key rejected');

$config = ['encryption_key' => 'base64:' . base64_encode(random_bytes(32))];
utility_check(bg_utility_key_ready($config), 'Generated private 256-bit key accepted');
$account = '012345678901';
[$cipher1, $iv1, $tag1] = bg_encrypt_utility($account, $config);
[$cipher2, $iv2, $tag2] = bg_encrypt_utility($account, $config);
utility_check(strlen($iv1) === 12 && strlen($iv2) === 12,
    'Fresh 96-bit AES-GCM IV on both saves');
utility_check(strlen($tag1) === 16 && strlen($tag2) === 16,
    'Authenticated AES-GCM tag is 128 bits');
utility_check(!hash_equals($iv1, $iv2),
    'Encrypting identical account values uses different random IVs');
utility_check(!str_contains($cipher1, $account) && !str_contains($cipher2, $account),
    'Ciphertext never contains the plaintext value');
utility_check(bg_decrypt_utility($cipher1, $iv1, $tag1, $config) === $account,
    'Authorized decrypt returns exact original digits');
utility_check(bg_decrypt_utility($cipher2, $iv2, $tag2, $config) === $account,
    'Second encrypted record decrypts correctly');
$tampered = $tag1;
$tampered[0] = chr(ord($tampered[0]) ^ 1);
$rejected = false;
try {
    bg_decrypt_utility($cipher1, $iv1, $tampered, $config);
} catch (RuntimeException $e) {
    $rejected = true;
}
utility_check($rejected, 'Altered authentication tag fails closed');
$rejected = false;
try {
    $wrong = ['encryption_key' => 'base64:' . base64_encode(random_bytes(32))];
    bg_decrypt_utility($cipher1, $iv1, $tag1, $wrong);
} catch (RuntimeException $e) {
    $rejected = true;
}
utility_check($rejected, 'Wrong encryption key never reveals the account');

echo PHP_EOL . "{$checks} encryption checks; {$failures} failed." . PHP_EOL;
exit($failures ? 1 : 0);
