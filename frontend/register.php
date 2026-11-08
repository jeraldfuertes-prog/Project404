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
$reason = '';
try {
    $config = billguard_config();
    $seed = bg_seed($config);
    $ready = bg_registration_ready($config)
        && extension_loaded('gd')
        && is_file(dirname(__DIR__) . '/backend/vendor/autoload.php');
} catch (Throwable $e) {
    $reason = 'Private registration configuration is missing.';
}
if (!$ready && $reason === '') {
    $reason = 'Finish the approved seed digits, encryption key, privacy contact/retention, PHPMailer and PHP GD setup before enabling registration.';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$ready || $seed === null) {
        $errors['form'] = $reason;
    } elseif (!bg_check_csrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        try { bg_log(billguard_db(), 'CSRF_REJECTED'); } catch (Throwable $e) { error_log('Registration CSRF log failed.'); }
        $errors['form'] = 'Your form session expired. Refresh the page and try again.';
    } elseif (bg_form_input($_POST, 'company_site') !== '') {
        // Honeypot: refuse bot-generated registrations without identifying the trap.
        http_response_code(200);
        $errors['form'] = 'Registration could not be completed.';
    } else {
        $captcha = strtoupper(bg_form_input($_POST, 'captcha'));
        $expected = (string) ($_SESSION['captcha_answer'] ?? '');
        $created = (int) ($_SESSION['captcha_created'] ?? 0);
        unset($_SESSION['captcha_answer'], $_SESSION['captcha_created']);
        if ($expected === '' || time() - $created > 300 || !hash_equals($expected, $captcha)) {
            try { bg_log(billguard_db(), 'CAPTCHA_FAILED'); } catch (Throwable $e) { error_log('Registration CAPTCHA log failed.'); }
            $errors['captcha'] = 'CAPTCHA is incorrect or expired. Enter the characters shown in the new image.';
        } else {
            try {
                $db = billguard_db();
                [$errors, $values] = bg_validate_registration($_POST, $seed, $db);
                if ($errors === []) {
                    $uid = $values['id_number'];
                    [$cipher, $iv, $tag] = bg_encrypt_utility($values['utility_account_number'], $config);
                    $now = bg_now()->format('Y-m-d H:i:s');
                    $db->beginTransaction();
                    try {
                        $insert = $db->prepare(
                            'INSERT INTO users (
                                id_number, first_name, middle_name, family_name, name_extension,
                                birthdate, street, barangay, city, province, country, zip_code,
                                email, username, password_hash, consented_at,
                                utility_account_ciphertext, utility_account_iv, utility_account_tag,
                                primary_utility_type, service_start_date, monthly_utility_budget
                            ) VALUES (
                                :id, :first, :middle, :family, :extension,
                                :birthdate, :street, :barangay, :city, :province, :country, :zip,
                                :email, :username, :password, :consent,
                                :cipher, :iv, :tag, :utility, :start, :budget
                            )'
                        );
                        $insert->execute([
                            ':id' => $uid,
                            ':first' => $values['first_name'],
                            ':middle' => $values['middle_name'] === '' ? null : $values['middle_name'],
                            ':family' => $values['family_name'],
                            ':extension' => $values['name_extension'] === '' ? null : $values['name_extension'],
                            ':birthdate' => $values['birthdate'],
                            ':street' => $values['street'],
                            ':barangay' => $values['barangay'],
                            ':city' => $values['city'],
                            ':province' => $values['province'],
                            ':country' => $values['country'],
                            ':zip' => $values['zip_code'],
                            ':email' => $values['email'],
                            ':username' => $values['username'],
                            ':password' => password_hash((string) $_POST['password'], PASSWORD_DEFAULT),
                            ':consent' => $now,
                            ':cipher' => $cipher,
                            ':iv' => $iv,
                            ':tag' => $tag,
                            ':utility' => $values['primary_utility_type'],
                            ':start' => $values['service_start_date'],
                            ':budget' => $values['monthly_utility_budget'],
                        ]);
                        $questionInsert = $db->prepare('INSERT INTO security_answers
                            (user_id, question_id, answer_hash) VALUES (:uid, :question, :hash)');
                        for ($i = 1; $i <= 3; $i++) {
                            $questionInsert->execute([
                                ':uid' => $uid,
                                ':question' => $seed['question_ids'][$i - 1],
                                ':hash' => password_hash(strtolower($values["answer_{$i}"]), PASSWORD_DEFAULT),
                            ]);
                        }
                        bg_log($db, 'REGISTERED', $values['username'], $uid);
                        $db->commit();
                    } catch (Throwable $e) {
                        if ($db->inTransaction()) { $db->rollBack(); }
                        throw $e;
                    }

                    session_regenerate_id(true);
                    $_SESSION['registration_pending_id'] = $uid;
                    $sent = bg_send_otp($db, $uid, $values['email'], 'register', $config, $seed, false);
                    $_SESSION['registration_message'] = $sent['message'];
                    header('Location: verify-otp.php', true, 303);
                    exit;
                }
            } catch (PDOException $e) {
                error_log('BillGuard registration database error; check constraints and schema.');
                $errors['form'] = $e->getCode() === '23000'
                    ? 'One or more registration values are already registered. Check your ID Number, email and username.'
                    : 'Registration is temporarily unavailable. Please try again.';
            } catch (Throwable $e) {
                error_log('BillGuard registration failed; review private error log.');
                $errors['form'] = 'Registration is temporarily unavailable. Please try again.';
            }
        }
    }
    // Preserve benign fields after a failed attempt, but NEVER passwords or security answers.
    if ($values === []) {
        foreach ([
            'id_number', 'first_name', 'middle_name', 'family_name', 'name_extension',
            'extension_other', 'birthdate', 'street', 'barangay', 'city',
            'province', 'country', 'zip_code', 'email', 'username',
            'utility_account_number', 'primary_utility_type',
            'service_start_date', 'monthly_utility_budget'
        ] as $key) {
            $values[$key] = bg_form_input($_POST, $key);
        }
    }
}

$questions = bg_questions();
$value = static fn(string $field): string => bg_safe($values[$field] ?? '');
$error = static fn(string $field): string => isset($errors[$field])
    ? '<p class="form-error" id="error-' . bg_safe($field) . '" role="alert">' . bg_safe($errors[$field]) . '</p>'
    : '<p class="form-error" id="error-' . bg_safe($field) . '"></p>';
$selected = static fn(string $field, string $choice): string =>
    ($values[$field] ?? '') === $choice ? ' selected' : '';

bg_page_start('Register', 'register');
?>
<section class="page-intro">
    <p class="eyebrow">New account / verified enrollment</p>
    <h1>CREATE YOUR ACCOUNT.</h1>
    <p>Complete each required field and verify your email with a six-digit security code. Your financial records remain private.</p>
</section>

<?php if (!$ready): ?>
<div class="notice" role="status"><strong>CONFIGURATION REQUIRED:</strong> <?= bg_safe($reason) ?>.
    Please follow <code>docs/PHASE2_SETUP.md</code> in the repository. No data can be submitted until setup is complete.
</div>
<?php endif; ?>
<?php if (isset($errors['form'])): ?>
<div class="notice notice--error" role="alert"><?= bg_safe($errors['form']) ?></div>
<?php endif; ?>

<div class="registration-layout">
<form class="card card--dark registration-form" method="post" action="register.php" id="register-form" novalidate
      data-username-min="<?= $seed['username_min'] ?? 6 ?>"
      data-username-max="<?= $seed['username_max'] ?? 20 ?>"
      data-password-min="<?= $seed['password_min'] ?? 8 ?>"
      data-name-max="<?= $seed['name_max'] ?? 29 ?>">
    <?= bg_csrf_field() ?>
    <div class="trap-field" aria-hidden="true">
        <label for="company_site">Company website (leave blank)</label>
        <input type="text" id="company_site" name="company_site" autocomplete="off" tabindex="-1">
    </div>

    <div class="form-section">
        <div class="form-section__heading"><span>01 / Identity</span><h2>PERSONAL DETAILS</h2></div>
        <div class="form-grid form-grid--2">
            <div class="form-field">
                <label class="form-label" for="id_number">ID Number <span class="form-required" aria-hidden="true">*</span></label>
                <input class="form-control" id="id_number" name="id_number" type="text" inputmode="numeric"
                       placeholder="xxxx-xxxx" maxlength="9" pattern="[0-9]{4}-[0-9]{4}"
                       value="<?= $value('id_number') ?>" required aria-describedby="error-id_number">
                <?= $error('id_number') ?>
            </div>
            <div class="form-field">
                <label class="form-label" for="birthdate">Birthdate <span class="form-required" aria-hidden="true">*</span></label>
                <input class="form-control" id="birthdate" name="birthdate" type="date" required
                       max="<?= bg_safe(bg_now()->format('Y-m-d')) ?>"
                       value="<?= $value('birthdate') ?>" aria-describedby="error-birthdate">
                <?= $error('birthdate') ?>
            </div>
            <div class="form-field">
                <label class="form-label" for="first_name">First Name <span class="form-required" aria-hidden="true">*</span></label>
                <input class="form-control" id="first_name" name="first_name" type="text" required
                       maxlength="<?= $seed['name_max'] ?? 29 ?>" value="<?= $value('first_name') ?>" aria-describedby="error-first_name">
                <?= $error('first_name') ?>
            </div>
            <div class="form-field">
                <label class="form-label" for="middle_name">Middle Name <span class="form-optional">optional</span></label>
                <input class="form-control" id="middle_name" name="middle_name" type="text"
                       maxlength="<?= $seed['name_max'] ?? 29 ?>" value="<?= $value('middle_name') ?>" aria-describedby="error-middle_name">
                <?= $error('middle_name') ?>
            </div>
            <div class="form-field">
                <label class="form-label" for="family_name">Family Name <span class="form-required" aria-hidden="true">*</span></label>
                <input class="form-control" id="family_name" name="family_name" type="text" required
                       maxlength="<?= $seed['name_max'] ?? 29 ?>" value="<?= $value('family_name') ?>" aria-describedby="error-family_name">
                <?= $error('family_name') ?>
            </div>
            <div class="form-field">
                <label class="form-label" for="age">Age <span class="form-optional">calculated</span></label>
                <input class="form-control" id="age" type="number" readonly value="" placeholder="Automatic" aria-describedby="age-hint">
                <p id="age-hint" class="form-hint">Calculated from Birthdate. The server independently checks your age.</p>
            </div>
            <div class="form-field">
                <label class="form-label" for="name_extension">Name Extension <span class="form-optional">optional</span></label>
                <select class="form-control" id="name_extension" name="name_extension" aria-describedby="error-name_extension">
                    <option value="">None</option>
                    <option value="Jr."<?= $selected('name_extension', 'Jr.') ?>>Jr.</option>
                    <option value="Sr."<?= $selected('name_extension', 'Sr.') ?>>Sr.</option>
                    <option value="Other"<?= $selected('name_extension', 'Other') ?>>Other (Roman numeral)</option>
                </select>
                <?= $error('name_extension') ?>
            </div>
            <div class="form-field" id="extension-other-wrap">
                <label class="form-label" for="extension_other">Other Name Extension <span class="form-optional">only if Other</span></label>
                <input class="form-control" id="extension_other" name="extension_other" type="text" maxlength="3"
                       placeholder="I, II, III, IV, IX, X" value="<?= $value('extension_other') ?>" aria-describedby="error-extension_other">
                <?= $error('extension_other') ?>
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="form-section__heading"><span>02 / Location</span><h2>HOME ADDRESS</h2></div>
        <div class="form-grid form-grid--2">
            <?php foreach ([
                'street' => 'Purok / Street', 'barangay' => 'Barangay', 'city' => 'Municipality / City',
                'province' => 'Province', 'country' => 'Country', 'zip_code' => 'ZIP Code',
            ] as $key => $label): ?>
            <div class="form-field">
                <label class="form-label" for="<?= bg_safe($key) ?>"><?= bg_safe($label) ?> <span class="form-required" aria-hidden="true">*</span></label>
                <input class="form-control" id="<?= bg_safe($key) ?>" name="<?= bg_safe($key) ?>" type="text" required
                       maxlength="<?= $key === 'zip_code' ? '4' : '50' ?>"
                       <?= $key === 'zip_code' ? 'inputmode="numeric" pattern="[0-9]{4}"' : '' ?>
                       value="<?= $value($key) ?>" aria-describedby="error-<?= bg_safe($key) ?>">
                <?= $error($key) ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="form-section">
        <div class="form-section__heading"><span>03 / BillGuard setup</span><h2>UTILITY PROFILE</h2></div>
        <div class="form-grid form-grid--2">
            <div class="form-field">
                <label class="form-label" for="utility_account_number">Utility Account Number <span class="form-required" aria-hidden="true">*</span></label>
                <input class="form-control" id="utility_account_number" name="utility_account_number"
                       type="text" inputmode="numeric" minlength="8" maxlength="12" required
                       value="<?= $value('utility_account_number') ?>" aria-describedby="error-utility_account_number">
                <p class="form-hint">8–12 digits. Encrypted before database storage.</p><?= $error('utility_account_number') ?>
            </div>
            <div class="form-field">
                <label class="form-label" for="primary_utility_type">Primary Utility Type <span class="form-required" aria-hidden="true">*</span></label>
                <select class="form-control" id="primary_utility_type" name="primary_utility_type" required aria-describedby="error-primary_utility_type">
                    <option value="">Select a utility</option>
                    <?php foreach (['Electricity', 'Water', 'Internet'] as $type): ?>
                    <option value="<?= bg_safe($type) ?>"<?= $selected('primary_utility_type', $type) ?>><?= bg_safe($type) ?></option>
                    <?php endforeach; ?>
                </select><?= $error('primary_utility_type') ?>
            </div>
            <div class="form-field">
                <label class="form-label" for="service_start_date">Service Start Date <span class="form-required" aria-hidden="true">*</span></label>
                <input class="form-control" id="service_start_date" name="service_start_date" type="date" required
                       max="<?= bg_safe(bg_now()->format('Y-m-d')) ?>" value="<?= $value('service_start_date') ?>" aria-describedby="error-service_start_date">
                <?= $error('service_start_date') ?>
            </div>
            <div class="form-field">
                <label class="form-label" for="monthly_utility_budget">Monthly Utility Budget (₱) <span class="form-required" aria-hidden="true">*</span></label>
                <input class="form-control" id="monthly_utility_budget" name="monthly_utility_budget"
                       type="number" inputmode="decimal" min="0.01" max="999999.99" step="0.01"
                       required value="<?= $value('monthly_utility_budget') ?>" aria-describedby="error-monthly_utility_budget">
                <?= $error('monthly_utility_budget') ?>
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="form-section__heading"><span>04 / Credentials</span><h2>ACCOUNT ACCESS</h2></div>
        <div class="form-grid form-grid--2">
            <div class="form-field">
                <label class="form-label" for="email">Email Address <span class="form-required" aria-hidden="true">*</span></label>
                <input class="form-control" id="email" name="email" type="email" autocomplete="email" maxlength="100"
                       required value="<?= $value('email') ?>" aria-describedby="error-email">
                <?= $error('email') ?>
            </div>
            <div class="form-field">
                <label class="form-label" for="username">Username <span class="form-required" aria-hidden="true">*</span></label>
                <input class="form-control" id="username" name="username" type="text" autocomplete="username"
                       minlength="<?= $seed['username_min'] ?? 6 ?>" maxlength="<?= $seed['username_max'] ?? 20 ?>"
                       required value="<?= $value('username') ?>" aria-describedby="error-username username-availability">
                <?= $error('username') ?>
                <p class="form-hint" id="username-availability" aria-live="polite">Availability will be checked as you type.</p>
            </div>
            <div class="form-field">
                <label class="form-label" for="password">Password <span class="form-required" aria-hidden="true">*</span></label>
                <input class="form-control" id="password" name="password" type="password" autocomplete="new-password"
                       minlength="<?= $seed['password_min'] ?? 8 ?>" maxlength="64" required aria-describedby="error-password password-strength-text">
                <label class="form-check form-check--small"><input id="password-show" type="checkbox"> Show Password</label>
                <div class="strength-meter" aria-label="Password strength"><span id="strength-bar"></span></div>
                <p class="form-hint" id="password-strength-text" aria-live="polite">Strength: enter a password.</p>
                <?= $error('password') ?>
            </div>
            <div class="form-field">
                <label class="form-label" for="password_confirm">Re-enter Password <span class="form-required" aria-hidden="true">*</span></label>
                <input class="form-control" id="password_confirm" name="password_confirm" type="password"
                       autocomplete="new-password" required aria-describedby="error-password_confirm">
                <?= $error('password_confirm') ?>
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="form-section__heading"><span>05 / Recovery</span><h2>SECURITY QUESTIONS</h2></div>
        <p class="form-hint">Three questions are chosen from your approved student-number seed. Answers are stored as hashes and never displayed again.</p>
        <div class="form-grid">
            <?php for ($i = 1; $i <= 3; $i++): ?>
            <div class="form-field">
                <label class="form-label" for="answer_<?= $i ?>">
                    Q<?= (int) ($seed['question_ids'][$i - 1] ?? $i) ?> —
                    <?= bg_safe($questions[$seed['question_ids'][$i - 1] ?? $i]) ?>
                    <span class="form-required" aria-hidden="true">*</span>
                </label>
                <input class="form-control" id="answer_<?= $i ?>" name="answer_<?= $i ?>" type="text"
                       autocomplete="off" minlength="2" maxlength="50" required aria-describedby="error-answer_<?= $i ?>">
                <?= $error("answer_{$i}") ?>
            </div>
            <?php endfor; ?>
        </div>
    </div>

    <div class="form-section">
        <div class="form-section__heading"><span>06 / Protection</span><h2>CAPTCHA & CONSENT</h2></div>
        <div class="form-field">
            <label class="form-label" for="captcha">Enter the CAPTCHA characters <span class="form-required" aria-hidden="true">*</span></label>
            <div class="captcha-frame">
                <img id="captcha-image" src="captcha.php" width="232" height="78" alt="CAPTCHA with six letters and digits. Refresh for a new image.">
                <button class="button button--ghost button--small" type="button" id="captcha-refresh">Refresh image</button>
            </div>
            <input class="form-control" id="captcha" name="captcha" type="text" maxlength="6" minlength="6"
                   autocapitalize="characters" autocomplete="off" required aria-describedby="error-captcha">
            <?= $error('captcha') ?>
        </div>
        <div class="form-field">
            <label class="form-check" for="privacy_consent">
                <input id="privacy_consent" name="privacy_consent" type="checkbox" value="yes" required
                    <?= ($ready && ($_POST['privacy_consent'] ?? null) === 'yes') ? 'checked' : '' ?>>
                <span>I have read the <a href="privacy.php" target="_blank" rel="noopener noreferrer">Privacy Notice</a> and agree to the
                collection and processing of my personal data in accordance with the Data Privacy Act of 2012 (RA 10173).
                <span class="form-required" aria-hidden="true">*</span></span>
            </label><?= $error('privacy_consent') ?>
        </div>
        <button class="button button--full" type="submit" <?= !$ready ? 'disabled' : '' ?>>
            Create account & send verification code
        </button>
        <p class="preview-note">Your account stays <strong>unverified</strong> until the email OTP is accepted.
            Already registered? <a href="login.php">View Log-in</a>.</p>
    </div>
</form>
<aside class="registration-aside">
    <div class="card card--dark">
        <p class="eyebrow">Registration protocol</p>
        <h2>SECURE BY DESIGN.</h2>
        <p>Your account will be protected by multiple security layers:</p>
        <ul class="registration-steps">
            <li><span>01</span> Validated identity and household details</li>
            <li><span>02</span> Password and recovery answer hashing</li>
            <li><span>03</span> Encrypted utility account number</li>
            <li><span>04</span> Server-verified CAPTCHA</li>
            <li><span>05</span> Six-digit, expiring email verification OTP</li>
        </ul>
    </div>
    <div class="notice notice--cyan">Browser checks improve usability; PHP validates every field again before saving.</div>
</aside>
</div>
<script src="js/registration.js" defer></script>
<?php bg_page_end(); ?>
