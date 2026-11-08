<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/backend/php/otp.php';
require_once dirname(__DIR__) . '/backend/php/layout.php';
bg_secure_start();

$userId = $_SESSION['registration_pending_id'] ?? null;
if (!is_string($userId) || !preg_match('/^\d{4}-\d{4}$/D', $userId)) {
    header('Location: register.php', true, 303);
    exit;
}

$message = (string) ($_SESSION['registration_message'] ?? '');
unset($_SESSION['registration_message']);
$error = '';
$wait = 0;
$seed = null;
$emailHint = '';

try {
    $config = billguard_config();
    $seed = bg_seed($config);
    if ($seed === null) { throw new RuntimeException('Personal seed missing'); }
    $db = billguard_db();
    $userQ = $db->prepare('SELECT email, account_status FROM users WHERE id_number = :id LIMIT 1');
    $userQ->execute([':id' => $userId]);
    $account = $userQ->fetch();
    if (!$account || $account['account_status'] !== 'pending') {
        unset($_SESSION['registration_pending_id']);
        header('Location: register.php', true, 303);
        exit;
    }
    $email = (string) $account['email'];
    $parts = explode('@', $email, 2);
    $emailHint = substr($parts[0], 0, 2) . str_repeat('*', max(2, strlen($parts[0]) - 2)) . '@' . ($parts[1] ?? '');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!bg_check_csrf($_POST['csrf_token'] ?? null)) {
            http_response_code(403);
            bg_log($db, 'CSRF_REJECTED', null, $userId, ['page' => 'verify-otp']);
            $error = 'Your form session expired. Refresh the page and try again.';
        } elseif (($_POST['action'] ?? null) === 'resend') {
            $sent = bg_send_otp($db, $userId, $email, 'register', $config, $seed, true);
            if ($sent['ok']) { $message = $sent['message']; }
            else { $error = $sent['message']; }
            $wait = (int) ($sent['wait'] ?? 0);
        } elseif (($_POST['action'] ?? null) === 'verify') {
            $code = is_string($_POST['otp'] ?? null) ? trim($_POST['otp']) : '';
            $result = bg_verify_registration_otp($db, $userId, $code);
            if ($result['ok']) {
                unset($_SESSION['registration_pending_id']);
                session_regenerate_id(true);
                $_SESSION['registration_verified_notice'] = true;
                header('Location: verified.php', true, 303);
                exit;
            }
            $error = $result['message'];
        } else {
            $error = 'Please choose a valid verification action.';
        }
    }

    $latest = $db->prepare('SELECT created_at FROM one_time_passwords
        WHERE user_id = :id AND purpose = :purpose ORDER BY id DESC LIMIT 1');
    $latest->execute([':id' => $userId, ':purpose' => 'register']);
    $timestamp = $latest->fetchColumn();
    if ($timestamp) {
        $wait = max($wait, max(0, 60 - (bg_now()->getTimestamp() - strtotime((string) $timestamp))));
    }
} catch (Throwable $e) {
    error_log('BillGuard: verification page setup failed.');
    $error = 'Verification is temporarily unavailable. Check the local setup and try again.';
}
bg_page_start('Verify Email', 'verify-otp');
?>
<section class="page-intro">
    <p class="eyebrow">Identity confirmation / Step 2 of 2</p>
    <h1>VERIFY YOUR EMAIL.</h1>
    <p>Enter the six-digit code sent to <strong><?= bg_safe($emailHint) ?></strong>. The code expires after
        <strong><?= (int) ($seed['otp_expiry'] ?? 3) ?> minutes</strong> and is valid for one use only.</p>
</section>
<section class="auth-layout">
    <aside class="auth-aside">
        <p class="eyebrow">Verification protocol</p>
        <h2>CONFIRM ACCESS.</h2>
        <ul>
            <li>Six randomly generated digits</li>
            <li>Stored as a secure hash</li>
            <li>Expires automatically</li>
            <li>Cancelled after three incorrect attempts</li>
            <li>One new code invalidates the previous one</li>
        </ul>
    </aside>
    <div class="card card--dark auth-card">
        <p class="eyebrow">Secure account activation</p>
        <h2>EMAIL OTP</h2>
        <?php if ($message !== ''): ?><div class="notice notice--cyan" role="status"><?= bg_safe($message) ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="notice notice--error" role="alert"><?= bg_safe($error) ?></div><?php endif; ?>
        <form method="post" action="verify-otp.php">
            <?= bg_csrf_field() ?>
            <input type="hidden" name="action" value="verify">
            <div class="form-field">
                <label class="form-label" for="otp">Six-digit verification code <span class="form-required" aria-hidden="true">*</span></label>
                <input class="form-control otp-input" id="otp" name="otp" type="text" inputmode="numeric"
                    pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="one-time-code" required>
                <p class="form-hint">Check your email inbox and Spam/Junk folder. Do not share the code.</p>
            </div>
            <button class="button button--full" type="submit">Verify account</button>
        </form>
        <form class="resend-form" method="post" action="verify-otp.php">
            <?= bg_csrf_field() ?>
            <input type="hidden" name="action" value="resend">
            <button class="button button--ghost button--full" type="submit" id="otp-resend"
                data-wait="<?= $wait ?>">Resend OTP</button>
            <p class="form-hint" id="resend-timer" aria-live="polite">
                <?= $wait > 0 ? 'Resend available in ' . $wait . ' seconds.' : 'You may resend now.' ?>
            </p>
            <p class="form-hint">Maximum 3 resends per 15 minutes. Each resend invalidates the previous code.</p>
        </form>
    </div>
</section>
<script src="js/otp.js" defer></script>
<?php bg_page_end(); ?>
