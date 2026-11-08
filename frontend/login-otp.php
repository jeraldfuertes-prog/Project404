<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/backend/php/auth.php';
require_once dirname(__DIR__) . '/backend/php/layout.php';
bg_secure_start();

$id = $_SESSION['login_pending_id'] ?? null;
if (!is_string($id) || !preg_match('/^\d{4}-\d{4}$/D', $id)) {
    header('Location: login.php', true, 303);
    exit;
}
$error = '';
$notice = (string) ($_SESSION['login_message'] ?? '');
unset($_SESSION['login_message']);
$wait = 0;
$emailHint = '';
$expiry = 3;

try {
    $config = billguard_config();
    $seed = bg_seed($config);
    if ($seed === null) throw new RuntimeException('Seed not configured');
    $expiry = $seed['otp_expiry'];
    $db = billguard_db();
    $query = $db->prepare("SELECT email FROM users WHERE id_number = :id
        AND account_status = 'active' AND email_verified_at IS NOT NULL LIMIT 1");
    $query->execute([':id' => $id]);
    $email = $query->fetchColumn();
    if (!$email) {
        unset($_SESSION['login_pending_id']);
        header('Location: login.php', true, 303);
        exit;
    }
    $address = explode('@', (string) $email, 2);
    $emailHint = substr($address[0], 0, 2) . '***@' . ($address[1] ?? '');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!bg_check_csrf($_POST['csrf_token'] ?? null)) {
            http_response_code(403);
            bg_log($db, 'CSRF_REJECTED', null, $id, ['page' => 'login-otp']);
            $error = 'Form session expired. Refresh the page.';
        } elseif (($_POST['action'] ?? null) === 'verify') {
            $code = is_string($_POST['otp'] ?? null) ? trim($_POST['otp']) : '';
            $result = bg_verify_login_otp($db, $id, $code);
            if ($result['ok']) {
                unset($_SESSION['login_pending_id'], $_SESSION['login_message']);
                session_regenerate_id(true);
                $_SESSION['user_id'] = $id;
                $_SESSION['authenticated_at'] = time();
                $_SESSION['last_activity'] = time();
                header('Location: dashboard.php', true, 303);
                exit;
            }
            $error = $result['message'];
        } elseif (($_POST['action'] ?? null) === 'resend') {
            $sent = bg_send_otp($db, $id, (string) $email, 'login', $config, $seed, true);
            if ($sent['ok']) $notice = $sent['message'];
            else $error = $sent['message'];
            $wait = (int) ($sent['wait'] ?? 0);
        } else {
            $error = 'Invalid action.';
        }
    }
    $latest = $db->prepare("SELECT created_at FROM one_time_passwords
        WHERE user_id = :id AND purpose = 'login' ORDER BY id DESC LIMIT 1");
    $latest->execute([':id' => $id]);
    $at = $latest->fetchColumn();
    if ($at) $wait = max($wait, max(0, 60 - (bg_now()->getTimestamp() - strtotime((string) $at))));
} catch (Throwable $e) {
    error_log('BillGuard: login verification setup failed.');
    $error = 'Email verification is temporarily unavailable. Please try again.';
}

bg_page_start('Login Verification', 'login-otp');
?>
<section class="simple-auth">
    <div class="simple-auth__intro">
        <p class="eyebrow">One more step</p>
        <h1>VERIFY YOUR LOGIN</h1>
        <p>Enter the six-digit security code sent to <strong><?= bg_safe($emailHint) ?></strong>.</p>
    </div>
    <div class="card card--dark simple-auth__card">
        <h2>Email Verification</h2>
        <p>Code expires in <?= (int) $expiry ?> minutes. Three incorrect attempts cancel the code.</p>
        <?php if ($notice): ?><div class="notice notice--cyan" role="status"><?= bg_safe($notice) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="notice notice--error" role="alert"><?= bg_safe($error) ?></div><?php endif; ?>
        <form method="post" action="login-otp.php">
            <?= bg_csrf_field() ?>
            <input type="hidden" name="action" value="verify">
            <div class="form-field">
                <label class="form-label" for="otp">Six-digit code <span class="form-required">*</span></label>
                <input class="form-control otp-input" type="text" id="otp" name="otp"
                    autocomplete="one-time-code" inputmode="numeric" pattern="[0-9]{6}"
                    minlength="6" maxlength="6" required>
            </div>
            <button class="button button--full" type="submit">Verify and Open Dashboard</button>
        </form>
        <form method="post" action="login-otp.php" class="resend-form">
            <?= bg_csrf_field() ?>
            <input type="hidden" name="action" value="resend">
            <button type="submit" id="otp-resend" class="button button--ghost button--full"
                data-wait="<?= $wait ?>">Resend Code</button>
            <p id="resend-timer" class="form-hint" aria-live="polite">
                <?= $wait > 0 ? "Resend available in {$wait} seconds." : 'You may resend now.' ?>
            </p>
        </form>
        <p class="simple-auth__links"><a href="login.php">Back to login</a></p>
    </div>
</section>
<script src="js/otp.js" defer></script>
<?php bg_page_end(); ?>
