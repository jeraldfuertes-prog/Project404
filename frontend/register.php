<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/backend/php/registration.php';
require_once dirname(__DIR__) . '/backend/php/otp.php';
require_once dirname(__DIR__) . '/backend/php/layout.php';
bg_secure_start();

$errors = [];
$values = [];
$seed = null;
$ready = false;
$reason = 'Registration setup is incomplete.';
try {
    $config = billguard_config();
    $seed = bg_seed($config);
    $db = billguard_db();
    $columnCheck = $db->prepare("SELECT COUNT(*) FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = :name");
    $columnCheck->execute([':name' => 'home_address']);
    $schemaReady = (int) $columnCheck->fetchColumn() === 1;
    $ready = bg_registration_ready($config)
        && $schemaReady
        && is_file(dirname(__DIR__) . '/backend/vendor/autoload.php');
    if (!$schemaReady) {
        $reason = 'Run the one-time simple-registration database migration in docs/SIMPLE_REGISTER_MIGRATION.md.';
    } elseif (!$ready) {
        $reason = 'Configure the approved seed, privacy contact and retention, and SMTP settings in your private backend/config.php.';
    }
} catch (Throwable $e) {
    error_log('BillGuard: simplified registration setup failed.');
    $reason = 'Check the database, private configuration and Phase 2 setup.';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$ready || $seed === null) {
        $errors['form'] = $reason;
    } elseif (!bg_check_csrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        try { bg_log($db, 'CSRF_REJECTED', null, null, ['page' => 'register']); }
        catch (Throwable $e) { error_log('BillGuard registration CSRF logging failed.'); }
        $errors['form'] = 'Your form session expired. Refresh the page and try again.';
    } elseif (bg_form_input($_POST, 'company_site') !== '') {
        // Honeypot: automatic submissions are refused.
        $errors['form'] = 'Registration could not be completed.';
    } else {
        try {
            [$errors, $values] = bg_validate_registration($_POST, $seed, $db);
            if ($errors === []) {
                $accountId = bg_generate_account_id($db);
                $now = bg_now()->format('Y-m-d H:i:s');

                $db->beginTransaction();
                try {
                    $insert = $db->prepare('INSERT INTO users (
                        id_number, first_name, family_name, home_address,
                        email, username, password_hash, consented_at
                    ) VALUES (:id, :first, :family, :address, :email, :username, :password, :consent)');
                    $insert->execute([
                        ':id' => $accountId,
                        ':first' => $values['first_name'],
                        ':family' => $values['family_name'],
                        ':address' => $values['home_address'],
                        ':email' => $values['email'],
                        ':username' => $values['username'],
                        ':password' => password_hash((string) $_POST['password'], PASSWORD_DEFAULT),
                        ':consent' => $now,
                    ]);

                    $answerInsert = $db->prepare(
                        'INSERT INTO security_answers (user_id, question_id, answer_hash)
                        VALUES (:user, :question, :answer)'
                    );
                    for ($i = 1; $i <= 3; $i++) {
                        $answerInsert->execute([
                            ':user' => $accountId,
                            ':question' => $seed['question_ids'][$i - 1],
                            ':answer' => password_hash(strtolower($values["answer_{$i}"]), PASSWORD_DEFAULT),
                        ]);
                    }
                    bg_log($db, 'REGISTERED', $values['username'], $accountId);
                    $db->commit();
                } catch (Throwable $e) {
                    if ($db->inTransaction()) $db->rollBack();
                    throw $e;
                }

                session_regenerate_id(true);
                $_SESSION['registration_pending_id'] = $accountId;
                $result = bg_send_otp($db, $accountId, $values['email'], 'register', $config, $seed, false);
                $_SESSION['registration_message'] = $result['message'];
                header('Location: verify-otp.php', true, 303);
                exit;
            }
        } catch (PDOException $e) {
            error_log('BillGuard: simplified registration database insert failed.');
            $errors['form'] = $e->getCode() === '23000'
                ? 'This email or username is already registered. Choose a different value.'
                : 'Registration is temporarily unavailable. Check the database migration and try again.';
        } catch (Throwable $e) {
            error_log('BillGuard: simplified registration failed.');
            $errors['form'] = 'Registration could not be completed. Please try again.';
        }
    }
    // Preserve only nonsecret fields after an error. Passwords and answers are never repopulated.
    if ($values === []) {
        foreach (['first_name', 'family_name', 'home_address', 'email', 'username'] as $key) {
            $values[$key] = bg_form_input($_POST, $key);
        }
    }
}

$questions = bg_questions();
$value = static fn(string $key): string => bg_safe($values[$key] ?? '');
$error = static fn(string $key): string => isset($errors[$key])
    ? '<p class="form-error" id="error-' . bg_safe($key) . '" role="alert">' . bg_safe($errors[$key]) . '</p>'
    : '<p class="form-error" id="error-' . bg_safe($key) . '"></p>';
bg_page_start('Register', 'register');
?>
<section class="simple-auth simple-auth--register">
    <div class="simple-auth__intro">
        <p class="eyebrow">Create a BillGuard account</p>
        <h1>SIMPLE REGISTRATION.</h1>
        <p>Enter your name, home address, and login details. The next step verifies your email.</p>
    </div>

    <div class="card card--dark simple-auth__card">
        <?php if (!$ready): ?>
            <div class="notice" role="status"><strong>SETUP NEEDED:</strong> <?= bg_safe($reason) ?></div>
        <?php endif; ?>
        <?php if (isset($errors['form'])): ?>
            <div class="notice notice--error" role="alert"><?= bg_safe($errors['form']) ?></div>
        <?php endif; ?>

        <form method="post" action="register.php" id="register-form" novalidate
            data-username-min="<?= (int) ($seed['username_min'] ?? 6) ?>"
            data-username-max="<?= (int) ($seed['username_max'] ?? 20) ?>"
            data-password-min="<?= (int) ($seed['password_min'] ?? 8) ?>"
            data-name-max="<?= (int) ($seed['name_max'] ?? 29) ?>">
            <?= bg_csrf_field() ?>
            <div class="trap-field" aria-hidden="true">
                <label for="company_site">Company website (leave blank)</label>
                <input type="text" id="company_site" name="company_site" autocomplete="off" tabindex="-1">
            </div>

            <h2 class="simple-register__heading">PERSONAL INFORMATION</h2>
            <div class="form-grid form-grid--2">
                <div class="form-field">
                    <label class="form-label" for="first_name">First Name <span class="form-required">*</span></label>
                    <input class="form-control" id="first_name" name="first_name" type="text"
                        autocomplete="given-name" placeholder="Enter your first name" required
                        maxlength="<?= (int) ($seed['name_max'] ?? 29) ?>"
                        value="<?= $value('first_name') ?>" aria-describedby="error-first_name">
                    <?= $error('first_name') ?>
                </div>
                <div class="form-field">
                    <label class="form-label" for="family_name">Last Name <span class="form-required">*</span></label>
                    <input class="form-control" id="family_name" name="family_name" type="text"
                        autocomplete="family-name" placeholder="Enter your last name" required
                        maxlength="<?= (int) ($seed['name_max'] ?? 29) ?>"
                        value="<?= $value('family_name') ?>" aria-describedby="error-family_name">
                    <?= $error('family_name') ?>
                </div>
            </div>

            <div class="form-field">
                <label class="form-label" for="home_address">Home Address <span class="form-required">*</span></label>
                <input class="form-control" id="home_address" name="home_address" type="text"
                    autocomplete="street-address" placeholder="Enter your complete home address"
                    minlength="5" maxlength="180" required
                    value="<?= $value('home_address') ?>" aria-describedby="error-home_address">
                <?= $error('home_address') ?>
            </div>

            <h2 class="simple-register__heading">ACCOUNT ACCESS</h2>
            <div class="form-field">
                <label class="form-label" for="email">Email Address <span class="form-required">*</span></label>
                <input class="form-control" id="email" name="email" type="email" autocomplete="email"
                    placeholder="you@example.com" maxlength="100" required
                    value="<?= $value('email') ?>" aria-describedby="error-email">
                <?= $error('email') ?>
            </div>
            <div class="form-field">
                <label class="form-label" for="username">Username <span class="form-required">*</span></label>
                <input class="form-control" id="username" name="username" type="text" autocomplete="username"
                    placeholder="Choose a username" required
                    minlength="<?= (int) ($seed['username_min'] ?? 6) ?>"
                    maxlength="<?= (int) ($seed['username_max'] ?? 20) ?>"
                    value="<?= $value('username') ?>" aria-describedby="error-username username-availability">
                <?= $error('username') ?>
                <p class="form-hint" id="username-availability" aria-live="polite">Username availability is checked while typing.</p>
            </div>
            <div class="form-grid form-grid--2">
                <div class="form-field">
                    <label class="form-label" for="password">Password <span class="form-required">*</span></label>
                    <input class="form-control" id="password" name="password" type="password"
                        autocomplete="new-password" placeholder="Create a strong password"
                        minlength="<?= (int) ($seed['password_min'] ?? 8) ?>" maxlength="64"
                        required aria-describedby="error-password password-strength-text">
                    <div class="strength-meter" aria-hidden="true"><span></span></div>
                    <p class="form-hint" id="password-strength-text" aria-live="polite">Strength: enter a password.</p>
                    <?= $error('password') ?>
                </div>
                <div class="form-field">
                    <label class="form-label" for="password_confirm">Confirm Password <span class="form-required">*</span></label>
                    <input class="form-control" id="password_confirm" name="password_confirm"
                        type="password" autocomplete="new-password" placeholder="Repeat your password"
                        required aria-describedby="error-password_confirm">
                    <?= $error('password_confirm') ?>
                    <label class="form-check form-check--small">
                        <input id="password-show" type="checkbox"> Show passwords
                    </label>
                </div>
            </div>

            <details class="recovery-details">
                <summary>Account Recovery Questions <span class="form-required">*</span></summary>
                <p class="form-hint">Three answers are required to securely recover your account if you forget your password.</p>
                <?php for ($i = 1; $i <= 3; $i++): ?>
                    <div class="form-field">
                        <label class="form-label" for="answer_<?= $i ?>">
                            <?= bg_safe($questions[$seed['question_ids'][$i - 1] ?? $i]) ?>
                            <span class="form-required">*</span>
                        </label>
                        <input class="form-control" id="answer_<?= $i ?>" name="answer_<?= $i ?>"
                            type="text" autocomplete="off" minlength="2" maxlength="50" required
                            aria-describedby="error-answer_<?= $i ?>">
                        <?= $error("answer_{$i}") ?>
                    </div>
                <?php endfor; ?>
            </details>

            <label class="form-check simple-register__consent" for="privacy_consent">
                <input id="privacy_consent" name="privacy_consent" type="checkbox" value="yes"
                    <?= ($_POST['privacy_consent'] ?? null) === 'yes' ? 'checked' : '' ?> required>
                <span>I agree to the <a href="privacy.php" target="_blank" rel="noopener noreferrer">Privacy Notice</a>
                    before my information is saved. <span class="form-required">*</span></span>
            </label>
            <?= $error('privacy_consent') ?>

            <button class="button button--full" type="submit" <?= !$ready ? 'disabled' : '' ?>>
                Create Account
            </button>
            <p class="simple-auth__links">Already registered? <a href="login.php">Log in</a></p>
            <p class="form-hint simple-register__footnote">
                Email verification is required after registration. CAPTCHA appears on the login screen.
            </p>
        </form>
    </div>
</section>
<script src="js/registration.js" defer></script>
<?php bg_page_end(); ?>
