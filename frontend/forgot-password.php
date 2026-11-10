<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/backend/php/recovery_email.php';
require_once dirname(__DIR__) . '/backend/php/layout.php';
bg_secure_start();

$error = '';
$notice = (string) ($_SESSION['bg_recovery_entry_notice'] ?? '');
unset($_SESSION['bg_recovery_entry_notice']);
$email = '';
$ready = true;
try {
    $config = billguard_config();
    $seed = bg_seed($config);
    if ($seed === null) throw new RuntimeException('Seed not configured.');
    $db = billguard_db();
} catch (Throwable $errorObject) {
    error_log('BillGuard: recovery page configuration failed.');
    $ready = false;
    $error = 'Account recovery is temporarily unavailable. Check the local setup.';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // A new attempt must invalidate an earlier, incomplete candidate flow.
    unset($_SESSION['bg_recovery_flow']);
    $email = is_string($_POST['email'] ?? null) ? bg_recovery_normalize_email($_POST['email']) : '';
    if (!$ready) {
        $error = 'Account recovery is temporarily unavailable. Please try again later.';
    } elseif (!bg_check_csrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        bg_log($db, 'CSRF_REJECTED', null, null, ['page' => 'forgot-password']);
        $error = 'Your form session expired. Refresh this page and try again.';
    } elseif (($emailError = bg_recovery_email_error($email)) !== null) {
        $error = $emailError;
    } else {
        $answer = is_string($_POST['captcha'] ?? null) ? $_POST['captcha'] : '';
        if (!bg_recovery_check_captcha($answer, time())) {
            bg_log($db, 'CAPTCHA_FAILED', null, null, ['page' => 'forgot-password']);
            $error = 'CAPTCHA is incorrect or expired. Refresh the image and try again.';
        } else {
            $result = bg_recovery_email_entry($db, $email, $seed, $config);
            if ($result['ok']) {
                // A new PHP session ID protects this pre-verification workflow.
                // This is NOT authentication and grants NO ability to reset a password.
                session_regenerate_id(true);
                $_SESSION['bg_recovery_flow'] = [
                    'stage' => 'question_pending',
                    'started_at' => time(),
                    'candidate_id' => $result['candidate_id'],
                    'code_sent' => $result['code_sent'],
                ];
                $_SESSION['bg_recovery_question_notice'] = $result['message'];
                header('Location: recovery-question.php', true, 303);
                exit;
            }
            $error = $result['message'];
        }
    }
}
bg_page_start('Forgot Password', 'forgot-password');
?>
<section class="simple-auth" aria-labelledby="recovery-heading">
    <div class="simple-auth__intro">
        <p class="eyebrow">Account recovery / Email + security questions</p>
        <h1 id="recovery-heading">FORGOT PASSWORD?</h1>
        <p>Enter the email address registered with BillGuard and solve the CAPTCHA. If eligible, a six-digit recovery code will be emailed to you.</p>
    </div>
    <div class="card card--dark simple-auth__card">
        <div class="simple-auth__symbol" aria-hidden="true">B</div>
        <h2>Recovery Request</h2>
        <p>For your privacy, the result will not reveal whether an email address is registered.</p>
        <?php if ($error !== ''): ?>
            <div class="notice notice--error" role="alert"><?= bg_safe($error) ?></div>
        <?php endif; ?>
        <?php if ($notice !== ''): ?>
            <div class="notice notice--cyan" role="status">
                <?= bg_safe($notice) ?>
            </div>
        <?php endif; ?>
        <form action="forgot-password.php" method="post">
            <?= bg_csrf_field() ?>
            <div class="form-field">
                <label class="form-label" for="email">
                    Registered Email Address <span class="form-required">*</span>
                </label>
                <input class="form-control" id="email" name="email"
                    type="email" autocomplete="email" maxlength="100"
                    placeholder="Enter your registered email" required
                    value="<?= bg_safe($email) ?>">
            </div>
            <div class="form-field">
                <label class="form-label" for="captcha">
                    Security code <span class="form-required">*</span>
                </label>
                <div class="captcha-frame">
                    <img src="captcha.php" id="captcha-image" width="232" height="78"
                        alt="CAPTCHA with six random characters. Refresh to get a different image.">
                    <button class="button button--ghost button--small"
                        type="button" id="captcha-refresh">Refresh image</button>
                </div>
                <input class="form-control" id="captcha" name="captcha" type="text"
                    autocomplete="off" minlength="6" maxlength="6"
                    placeholder="Enter the characters above" required>
            </div>
            <button class="button button--full" type="submit" <?= !$ready ? 'disabled' : '' ?>>
                Start Recovery
            </button>
        </form>
        <p class="form-hint">
            After submitting, check your email for a six-digit code and
            answer one of the three questions you set at registration.
            Both checks are required. Changing the password is not enabled yet.
        </p>
        <p class="simple-auth__links"><a href="login.php">Return to Login</a></p>
    </div>
</section>
<script src="js/login.js" defer></script>
<?php bg_page_end(); ?>
