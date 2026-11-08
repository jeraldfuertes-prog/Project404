<?php
declare(strict_types=1);
require_once __DIR__ . '/security.php';

/**
 * Send security codes through the same private SMTP settings tested in Phase 1.
 * Never log or display the code, an SMTP App Password, or a password hash.
 */
function bg_mail_otp(string $email, string $code, string $purpose, string $expiresAt, array $config): void
{
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('PHPMailer is not installed in backend/vendor.');
    }
    require_once $autoload;

    $labels = [
        'register' => ['Verify your account', 'account verification'],
        'login' => ['Your log-in code', 'log-in verification'],
        'reset' => ['Password reset code', 'password reset'],
    ];
    if (!isset($labels[$purpose])) {
        throw new InvalidArgumentException('Unknown OTP purpose.');
    }

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = (string) $config['smtp_host'];
    $mail->Port = (int) ($config['smtp_port'] ?? 587);
    $security = (string) ($config['smtp_security'] ?? 'tls');
    $mail->SMTPAuth = $security !== 'none';
    $mail->Username = (string) $config['smtp_user'];
    $mail->Password = (string) $config['smtp_password'];
    if ($security === 'ssl') {
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    } elseif ($security === 'tls') {
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    } elseif ($security === 'none') {
        $mail->SMTPSecure = '';
        $mail->SMTPAutoTLS = false;
    } else {
        throw new RuntimeException('Unsupported SMTP security mode.');
    }

    $mail->CharSet = 'UTF-8';
    $mail->setFrom((string) $config['smtp_from'], (string) ($config['smtp_from_name'] ?? 'BillGuard'));
    $mail->addAddress($email);
    $mail->Subject = 'BillGuard - ' . $labels[$purpose][0];
    $mail->Body = "BillGuard - {$labels[$purpose][0]}\n\n"
        . "Your six-digit {$labels[$purpose][1]} code is: {$code}\n"
        . "This code expires at {$expiresAt} Philippine time (PHT).\n\n"
        . "If you did not request this code, ignore this email and consider changing your password.\n"
        . "Never share this code with anyone.";
    $mail->send();
}
