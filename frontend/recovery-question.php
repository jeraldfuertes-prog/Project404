<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/backend/php/recovery_questions.php';
require_once dirname(__DIR__) . '/backend/php/layout.php';
bg_secure_start();

$flow = $_SESSION['bg_recovery_flow'] ?? null;
$now = time();
$pending = bg_recovery_flow_ready($flow, $now);
$complete = bg_recovery_question_result_ready($flow, $now);
if (!$pending && !$complete) {
    unset($_SESSION['bg_recovery_flow'], $_SESSION['bg_recovery_question_notice']);
    header('Location: forgot-password.php', true, 303);
    exit;
}

$error = '';
$notice = (string) ($_SESSION['bg_recovery_question_notice'] ?? '');
unset($_SESSION['bg_recovery_question_notice'], $_SESSION['bg_recovery_entry_notice']);
$ready = true;
$chosen = '';
try {
    $config = billguard_config();
    $seed = bg_seed($config);
    if ($seed === null) throw new RuntimeException('Seed missing.');
    $db = billguard_db();
    $choices = bg_recovery_question_choices($seed);
    if (count($choices) !== 3) throw new RuntimeException('Question configuration missing.');
} catch (Throwable $e) {
    error_log('BillGuard: recovery-question setup failed.');
    $ready = false;
    $error = 'Security-question verification is temporarily unavailable.';
    $choices = [];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$pending || !$ready) {
        http_response_code(403);
        $error = 'Recovery session is no longer available. Start again.';
    } elseif (!bg_check_csrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        bg_log($db, 'CSRF_REJECTED', null, null, ['page' => 'recovery-question']);
        $error = 'Your form token expired. Refresh the page and try again.';
    } else {
        $chosen = is_string($_POST['question_id'] ?? null)
            ? $_POST['question_id'] : '';
        $questionId = filter_var($chosen, FILTER_VALIDATE_INT);
        $answer = is_string($_POST['answer'] ?? null) ? trim($_POST['answer']) : '';
        $confirm = is_string($_POST['answer_confirm'] ?? null)
            ? trim($_POST['answer_confirm']) : '';
        if ($questionId === false || !array_key_exists($questionId, $choices)) {
            $error = 'Choose one of the three recovery questions.';
        } elseif ($answer === '' || strlen($answer) > 50
            || preg_match('/^[A-Za-z]+(?: [A-Za-z]+)*$/D', $answer) !== 1) {
            $error = 'Enter the answer using 2–50 letters and single spaces.';
        } elseif (strlen($answer) < 2) {
            $error = 'The security answer must be at least two characters.';
        } elseif (strtolower($answer) !== strtolower($confirm)) {
            $error = 'The two answers do not match. Please enter them again.';
        } else {
            $result = bg_recovery_verify_question(
                $db, $flow, $seed, $config, (int) $questionId, $answer, $confirm
            );
            if ($result['ok']) {
                // Only the server knows which stage is verified. Both outcomes
                // display the same public page to prevent username enumeration.
                $flow['stage'] = $result['verified']
                    ? 'question_verified' : 'question_submitted';
                if ($result['verified']) $flow['question_verified_at'] = time();
                $_SESSION['bg_recovery_flow'] = $flow;
                $_SESSION['bg_recovery_question_notice'] = $result['message'];
                header('Location: recovery-question.php', true, 303);
                exit;
            }
            $error = $result['message'];
        }
    }
}
bg_page_start('Security Question', 'recovery-question');
?>
<section class="simple-auth" aria-labelledby="recovery-question-title">
    <div class="simple-auth__intro">
        <p class="eyebrow">Account recovery / Phase 4.2</p>
        <h1 id="recovery-question-title">SECURITY QUESTION.</h1>
        <p>Select one of the three security questions and confirm your registered answer.</p>
    </div>
    <div class="card card--dark simple-auth__card">
        <div class="simple-auth__symbol" aria-hidden="true">B</div>
        <h2>Identity Verification</h2>
        <?php if ($error !== ''): ?>
            <div class="notice notice--error" role="alert"><?= bg_safe($error) ?></div>
        <?php endif; ?>
        <?php if ($notice !== ''): ?>
            <div class="notice notice--cyan" role="status"><?= bg_safe($notice) ?></div>
        <?php endif; ?>
        <?php if ($complete): ?>
            <p class="form-hint">This recovery-question submission is complete.
                The reset email OTP stage will be added in Phase 4.3.
                You can start a new recovery request or return to Login.</p>
            <a class="button button--ghost button--full" href="forgot-password.php">
                Start Another Recovery Request
            </a>
        <?php elseif ($pending && $ready): ?>
            <form method="post" action="recovery-question.php" autocomplete="off">
                <?= bg_csrf_field() ?>
                <div class="form-field">
                    <label class="form-label" for="question_id">
                        Security Question <span class="form-required">*</span>
                    </label>
                    <select class="form-control" id="question_id" name="question_id" required>
                        <option value="">Choose one of your three questions</option>
                        <?php foreach ($choices as $id => $prompt): ?>
                            <option value="<?= (int) $id ?>"
                                <?= $chosen === (string) $id ? 'selected' : '' ?>>
                                <?= bg_safe($prompt) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-field">
                    <label class="form-label" for="answer">
                        Security Answer <span class="form-required">*</span>
                    </label>
                    <input class="form-control" id="answer" type="password"
                        name="answer" required minlength="2" maxlength="50"
                        autocomplete="off" placeholder="Your registered answer">
                </div>
                <div class="form-field">
                    <label class="form-label" for="answer_confirm">
                        Re-enter Security Answer <span class="form-required">*</span>
                    </label>
                    <input class="form-control" id="answer_confirm" type="password"
                        name="answer_confirm" required minlength="2" maxlength="50"
                        autocomplete="off" placeholder="Confirm your answer">
                </div>
                <button type="submit" class="button button--full">
                    Verify Security Answer
                </button>
            </form>
            <p class="form-hint">
                Answers are case-insensitive. An incorrect answer counts toward
                the same lockouts used by login. Do not share security answers.
            </p>
        <?php endif; ?>
        <p class="form-hint">
            No email reset code or password change is available yet.
            This step does not sign you in.
        </p>
        <p class="simple-auth__links">
            <a href="forgot-password.php">Back to Recovery</a>
            &nbsp; · &nbsp;
            <a href="login.php">Return to Login</a>
        </p>
    </div>
</section>
<?php bg_page_end(); ?>
