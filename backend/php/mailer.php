<?php
declare(strict_types=1);
require_once __DIR__ . '/security.php';

/**
 * Gmail shows App Passwords grouped with spaces. Gmail SMTP expects the
 * 16-character token, so remove formatting whitespace FOR GMAIL ONLY.
 * Other SMTP providers receive their passwords unchanged.
 */
function bg_smtp_password(array $config): string
{
    $password = (string) ($config['smtp_password'] ?? '');
    $host = strtolower(trim((string) ($config['smtp_host'] ?? '')));
    if ($host === 'smtp.gmail.com') {
        return preg_replace('/\s+/', '', $password) ?? $password;
    }
    return $password;
}

/**
 * Return only a safe diagnostic category (not the raw SMTP exception, which may
 * contain addresses or remote-server details). The private PHP log receives
 * the category; passwords and OTP values are never logged.
 */
function bg_mail_error_category(Throwable $error): string
{
    $description = strtolower($error->getMessage());
    if (preg_match('/authenticat|credential|app.password|invalid.login|535|5[.]7[.]8/', $description)) {
        return 'SMTP_AUTH';
    }
    if (preg_match('/connect|timeout|could not instantiate mail function|network|dns|unreachable/', $description)) {
        return 'SMTP_CONNECTION';
    }
    if (preg_match('/recipient|address|mailbox|5[.]1[.]1/', $description)) {
        return 'SMTP_RECIPIENT';
    }
    if (preg_match('/missing|autoload|phpmailer|configuration/', $description)) {
        return 'MAIL_CONFIGURATION';
    }
    return 'SMTP_OTHER';
}

/**
 * Single SMTP builder shared by the Phase 1 smoke test and account OTP mail.
 * This avoids the test passing with different settings from actual OTP sends.
 */
function bg_configured_mailer(array $config): \PHPMailer\PHPMailer\PHPMailer
{
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('PHPMailer autoload missing.');
    }
    require_once $autoload;

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = (string) ($config['smtp_host'] ?? '');
    $mail->Port = (int) ($config['smtp_port'] ?? 587);
    $security = strtolower((string) ($config['smtp_security'] ?? 'tls'));
    $mail->SMTPAuth = $security !== 'none';
    $mail->Username = (string) ($config['smtp_user'] ?? '');
    $mail->Password = bg_smtp_password($config);

    switch ($security) {
        case 'ssl':
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            break;
        case 'tls':
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            break;
        case 'none':
            // For local mail catchers approved by the instructor only.
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
            break;
        default:
            throw new RuntimeException('Unsupported SMTP security configuration.');
    }

    $mail->CharSet = 'UTF-8';
    $mail->Timeout = 20;
    $mail->setFrom((string) ($config['smtp_from'] ?? ''),
        (string) ($config['smtp_from_name'] ?? 'BillGuard'));
    return $mail;
}

/**
 * Never log, echo or persist the plaintext OTP.
 * Only send the code to the address on the account.
 */
function bg_mail_otp(string $email, string $code, string $purpose, string $expiresAt, array $config): void
{
    $labels = [
        'register' => ['Verify your account', 'account verification'],
        'login' => ['Your log-in code', 'log-in verification'],
        'reset' => ['Password reset code', 'password reset'],
    ];
    if (!isset($labels[$purpose])) {
        throw new InvalidArgumentException('Unknown OTP purpose.');
    }

    $mail = bg_configured_mailer($config);
    $mail->addAddress($email);
    $mail->Subject = 'BillGuard - ' . $labels[$purpose][0];
    $mail->Body = "BillGuard - {$labels[$purpose][0]}\n\n"
        . "Your six-digit {$labels[$purpose][1]} code is: {$code}\n"
        . "This code expires at {$expiresAt} Philippine time (PHT).\n\n"
        . "If you did not request this code, ignore this email and consider changing your password.\n"
        . "Never share this code with anyone.";
    $mail->send();
}
