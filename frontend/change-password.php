<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/backend/php/recovery_reset.php';
require_once dirname(__DIR__) . '/backend/php/layout.php';
bg_secure_start();

$flow = $_SESSION['bg_recovery_flow'] ?? null;
// Unknown-account/wrong-answer recovery attempts show the SAME form as
// successful verification. Only server-side two-proof state grants POST access.
if (!bg_recovery_question_result_ready($flow, time())) {
    unset($_SESSION['bg_recovery_flow']);
    header('Location: forgot-password.php', true, 303);
    exit;
}

$error = '';
$min = 8;
try {
    $config = billguard_config();
    $seed = bg_seed($config);
    if ($seed === null) throw new RuntimeException('Personal seed missing');
    $min = (int) $seed['password_min'];
    $db = billguard_db();
} catch (Throwable $e) {
    error_log('BillGuard: password reset page setup failed.');
    $error = 'Password reset is temporarily unavailable. Please try again later.';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!isset($db, $seed)) {
        http_response_code(503);
    } elseif (!bg_check_csrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        bg_log($db, 'CSRF_REJECTED', null, null, ['page' => 'change-password']);
        $error = 'The form session expired. Refresh this page and try again.';
    } else {
        $password = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
        $confirmation = is_string($_POST['confirm_password'] ?? null)
            ? $_POST['confirm_password'] : '';
        $result = bg_reset_password($db, $flow, $seed, $config, $password, $confirmation);
        if ($result['ok']) {
            // No current login is carried forward; other authenticated sessions
            // will fail the stored password-fingerprint comparison.
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['login_notice'] = 'Your password was changed. Sign in using your new password.';
            header('Location: login.php', true, 303);
            exit;
        }
        $error = $result['message'];
    }
}

bg_page_start('Change Password', 'change-password');
?>
<section class="simple-auth" aria-labelledby="change-password-title">
    <div class="simple-auth__intro">
        <p class="eyebrow">Account recovery / Phase 4.4</p>
        <h1 id="change-password-title">CHANGE PASSWORD.</h1>
        <p>Enter a new password after email-code and security-question verification.</p>
    </div>
    <div class="card card--dark simple-auth__card">
        <div class="simple-auth__symbol" aria-hidden="true">B</div>
        <h2>Set New Password</h2>
        <?php if ($error !== ''): ?>
            <div class="notice notice--error" role="alert"><?= bg_safe($error) ?></div>
        <?php endif; ?>
        <form action="change-password.php" method="post" autocomplete="off">
            <?= bg_csrf_field() ?>
            <div class="form-field">
                <label class="form-label" for="new_password">
                    New Password <span class="form-required">*</span>
                </label>
                <input class="form-control" id="new_password" name="new_password"
                    type="password" autocomplete="new-password"
                    minlength="<?= (int) $min ?>" maxlength="64" required>
            </div>
            <div class="form-field">
                <label class="form-label" for="confirm_password">
                    Confirm New Password <span class="form-required">*</span>
                </label>
                <input class="form-control" id="confirm_password" name="confirm_password"
                    type="password" autocomplete="new-password"
                    minlength="<?= (int) $min ?>" maxlength="64" required>
            </div>
            <label class="form-check form-check--small">
                <input id="show-reset-passwords" type="checkbox"> Show passwords
            </label>
            <p class="form-hint">
                <?= (int) $min ?>–64 characters. Use at least three types:
                uppercase, lowercase, numbers or symbols. You cannot reuse
                the current password or the last three previous passwords.
            </p>
            <button class="button button--full" type="submit" <?= !isset($db) ? 'disabled' : '' ?>>
                Save New Password
            </button>
        </form>
        <p class="form-hint">
            For your security, this form expires ten minutes after recovery started.
            If verification was unsuccessful, start the recovery process again.
        </p>
        <p class="simple-auth__links">
            <a href="forgot-password.php">Restart Recovery</a>
            &nbsp; · &nbsp; <a href="login.php">Return to Login</a>
        </p>
    </div>
</section>
<script src="js/change-password.js" defer></script>
<?php bg_page_end(); ?>
