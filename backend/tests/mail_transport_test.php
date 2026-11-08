<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/php/mailer.php';

$checks = [
    'Gmail App Password displayed with spaces is normalized'
        => bg_smtp_password([
            'smtp_host' => 'smtp.gmail.com',
            'smtp_password' => 'abcd efgh ijkl mnop',
        ]) === 'abcdefghijklmnop',
    'Gmail normalization accepts pasted tabs/newlines'
        => bg_smtp_password([
            'smtp_host' => 'SMTP.GMAIL.COM',
            'smtp_password' => "abcd\t efgh\n ijkl mnop",
        ]) === 'abcdefghijklmnop',
    'Unrelated SMTP passwords retain intentional whitespace'
        => bg_smtp_password([
            'smtp_host' => 'mail.example.test',
            'smtp_password' => 'secret spaces stay',
        ]) === 'secret spaces stay',
    'SMTP authentication failures receive safe category'
        => bg_mail_error_category(new RuntimeException('SMTP Error: Could not authenticate.')) === 'SMTP_AUTH',
    'SMTP connection failures receive safe category'
        => bg_mail_error_category(new RuntimeException('SMTP connect() failed.')) === 'SMTP_CONNECTION',
    'SMTP recipient failures receive safe category'
        => bg_mail_error_category(new RuntimeException('Invalid address: recipient')) === 'SMTP_RECIPIENT',
    'Other failure does not reveal raw message'
        => bg_mail_error_category(new RuntimeException('mySecretPassword=myPrivateValue')) === 'SMTP_OTHER',
];
$failed = 0;
foreach ($checks as $name => $pass) {
    echo ($pass ? '[OK]   ' : '[FAIL] ') . $name . PHP_EOL;
    if (!$pass) $failed++;
}
echo count($checks) . ' checks; ' . $failed . " failed.\n";
exit($failed ? 1 : 0);
