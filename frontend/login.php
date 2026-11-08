<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/backend/php/auth.php';
require_once dirname(__DIR__) . '/backend/php/layout.php';
bg_secure_start();

$error = '';
$notice = '';
$locked = 0;
$forgotShown = false;
try {
    $config = billguard_config();
    $seed = bg_seed($config);
    if ($seed === null) throw new RuntimeException('Personal seed missing');
    $db = billguard_db();

    if (bg_auth_user($db, $seed)) {
        header('Location: dashboard.php', true, 303);
        exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!bg_check_csrf($_POST['csrf_token'] ?? null)) {
            http_response_code(403);
            bg_log($db, 'CSRF_REJECTED', null, null, ['page' => 'login']);
            $error = 'Your form session expired. Refresh and try again.';
        } elseif (($_POST['privacy_ack'] ?? null) !== 'yes') {
            $error = 'Please confirm the Privacy Notice to continue.';
        } else {
            $username = is_string($_POST['username'] ?? null) ? trim($_POST['username']) : '';
            $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
            $answer = strtoupper(is_string($_POST['captcha'] ?? null) ? trim($_POST['captcha']) : '');
            $expected = (string) ($_SESSION['captcha_answer'] ?? '');
            $created = (int) ($_SESSION['captcha_created'] ?? 0);
            unset($_SESSION['captcha_answer'], $_SESSION['captcha_created']);

            if ($expected === '' || time() - $created > 300 || !hash_equals($expected, $answer)) {
                bg_log($db, 'CAPTCHA_FAILED', $username, null, ['page' => 'login']);
                $error = 'CAPTCHA is incorrect or expired. Refresh the image.';
            } else {
                $result = bg_login_password($db, $config, $seed, $username, $password);
                if (!$result['ok']) {
                    $error = (string) $result['message'];
                    $locked = (int) ($result['lockout'] ?? 0);
                    $forgotShown = (int) ($result['failures'] ?? 0) >= 2 + ($seed['d1'] % 2);
                } else {
                    $user = $result['user'];
                    session_regenerate_id(true);
                    $_SESSION['login_pending_id'] = $user['id_number'];
                    $sent = bg_send_otp($db, $user['id_number'], $user['email'], 'login', $config, $seed, false);
                    $_SESSION['login_message'] = $sent['message'];
                    header('Location: login-otp.php', true, 303);
                    exit;
                }
            }
        }
    }
} catch (Throwable $e) {
    error_log('BillGuard: login setup failed.');
    $error = 'Login is temporarily unavailable. Complete private configuration and MySQL setup.';
}
bg_page_start('Log-in', 'login');
?>
<section class="simple-auth">
    <div class="simple-auth__intro">
        <p class="eyebrow">Welcome back</p>
        <h1>LOG IN TO BILLGUARD</h1>
        <p>Manage your household bills and payment history from one secure dashboard.</p>
    </div>
    <div class="card card--dark simple-auth__card">
        <div class="simple-auth__symbol" aria-hidden="true">B</div>
        <h2>Account Login</h2>
        <p>Enter your username and password to receive your email verification code.</p>
        <?php if ($error): ?><div class="notice notice--error" role="alert"><?= bg_safe($error) ?></div><?php endif; ?>
        <?php if ($notice): ?><div class="notice notice--cyan" role="status"><?= bg_safe($notice) ?></div><?php endif; ?>
        <form method="post" action="login.php">
            <?= bg_csrf_field() ?>
            <div class="form-field">
                <label class="form-label" for="username">Username <span class="form-required">*</span></label>
                <input class="form-control" name="username" id="username" type="text"
                    autocomplete="username" placeholder="Your username" maxlength="40" required
                    value="<?= bg_safe(is_string($_POST['username'] ?? null) ? trim($_POST['username']) : '') ?>">
            </div>
            <div class="form-field">
                <label class="form-label" for="password">Password <span class="form-required">*</span></label>
                <input class="form-control" name="password" id="password" type="password"
                    autocomplete="current-password" placeholder="Enter your password" required>
                <label class="form-check form-check--small">
                    <input id="show-login-password" type="checkbox"> Show password
                </label>
            </div>
            <div class="form-field">
                <label class="form-label" for="captcha">Security code <span class="form-required">*</span></label>
                <div class="captcha-frame">
                    <img src="captcha.php" id="captcha-image" width="232" height="78"
                        alt="CAPTCHA with six random characters. Refresh to get a different image.">
                    <button type="button" id="captcha-refresh" class="button button--ghost button--small">Refresh image</button>
                </div>
                <input class="form-control" id="captcha" name="captcha" type="text" minlength="6" maxlength="6"
                    autocomplete="off" placeholder="Enter the characters above" required>
            </div>
            <label class="form-check simple-register__consent" for="privacy_ack">
                <input id="privacy_ack" name="privacy_ack" type="checkbox" value="yes" required
                    <?= ($_POST['privacy_ack'] ?? null) === 'yes' ? 'checked' : '' ?>>
                <span>I have read the <a href="privacy.php" target="_blank" rel="noopener noreferrer">Privacy Notice</a>.
                    <span class="form-required">*</span></span>
            </label>
            <button class="button button--full" type="submit" <?= $locked > 0 ? 'disabled' : '' ?>>Sign In</button>
        </form>
        <?php if ($forgotShown): ?>
            <p class="form-hint">Account recovery is planned for Phase 4. Ask the project administrator for assistance until the secure recovery flow is available.</p>
        <?php endif; ?>
        <p class="simple-auth__links">Don't have an account? <a href="register.php">Register here</a></p>
        <p class="form-hint">For your security, the dashboard opens only after your email OTP is verified.</p>
    </div>
</section>
<script src="js/login.js" defer></script>
<?php bg_page_end(); ?>
