<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/backend/php/billing.php';
require_once dirname(__DIR__) . '/backend/php/app_layout.php';
require_once dirname(__DIR__) . '/backend/php/utility_account.php';

try {
    [$db, $user, $seed] = bg_app_context();
    $config = billguard_config();
    $utilitySchemaReady = bg_utility_schema_ready($db);
    $utilityKeyReady = bg_utility_key_ready($config);
    $utilityStored = $utilitySchemaReady
        ? bg_utility_account_present($db, (string) $user['id_number']) : false;
    $q = $db->prepare("SELECT first_name, family_name, home_address, email, username, created_at
        FROM users WHERE id_number = :uid AND account_status = 'active' LIMIT 1");
    $q->execute([':uid' => $user['id_number']]);
    $profile = $q->fetch();
    if (!$profile) throw new RuntimeException('Active user not found.');
} catch (Throwable $e) {
    error_log('BillGuard: profile page setup failed.');
    http_response_code(503);
    exit('Profile settings are temporarily unavailable.');
}
$errors = [];
$message = '';
$notice = (string) ($_SESSION['bg_profile_notice'] ?? '');
unset($_SESSION['bg_profile_notice']);
$values = $profile;
$utilityReveal = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    if (!bg_check_csrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        try {
            bg_log($db, 'CSRF_REJECTED', null, (string) $user['id_number'],
                ['page' => 'profile']);
        } catch (Throwable $e) {
            error_log('BillGuard: profile CSRF activity audit failed.');
        }
        $message = 'Your request token expired. Please refresh and try again.';
    } elseif ($action === 'utility_save') {
        if (!$utilitySchemaReady || !$utilityKeyReady) {
            $message = 'Encrypted utility account storage needs private setup before use.';
        } else {
            $number = is_string($_POST['utility_account_number'] ?? null)
                ? trim($_POST['utility_account_number']) : '';
            $result = bg_utility_account_save(
                $db, (string) $user['id_number'], $number, $config
            );
            if ($result['ok']) {
                $_SESSION['bg_profile_notice'] = $result['message'];
                header('Location: profile.php', true, 303);
                exit;
            }
            $errors['utility_account_number'] = $result['message'];
        }
    } elseif ($action === 'utility_reveal') {
        if (!$utilitySchemaReady || !$utilityKeyReady) {
            $message = 'Encrypted utility account storage needs private setup before use.';
        } else {
            try {
                // Authenticated user ID is server-derived; ignore any posted ID.
                $utilityReveal = bg_utility_account_reveal(
                    $db, (string) $user['id_number'], $config
                );
                if ($utilityReveal === null) {
                    $message = 'No Utility Account Number has been saved yet.';
                } else {
                    bg_log($db, 'UTILITY_ACCOUNT_REVEALED', null, (string) $user['id_number']);
                }
            } catch (Throwable $e) {
                // Any audit or decryption failure must not render plaintext.
                $utilityReveal = null;
                error_log('BillGuard: secure utility account reveal failed.');
                $message = 'Could not reveal the encrypted utility account number.';
            }
        }
    } elseif ($action === 'utility_remove') {
        if (!$utilitySchemaReady) {
            $message = 'Encrypted utility account storage is not available.';
        } elseif (bg_utility_account_remove($db, (string) $user['id_number'])) {
            $_SESSION['bg_profile_notice'] = 'Utility Account Number removed.';
            header('Location: profile.php', true, 303);
            exit;
        } else {
            $message = 'Could not remove the utility account number. Please try again.';
        }
    } elseif ($action === 'profile') {
        foreach (['first_name', 'family_name', 'home_address'] as $key) {
            $values[$key] = bg_form_input($_POST, $key);
        }
        foreach (['first_name' => 'First Name', 'family_name' => 'Last Name'] as $key => $label) {
            $error = bg_name_error($values[$key], $label, (int) $seed['name_max']);
            if ($error !== null) $errors[$key] = $error;
        }
        $addressError = bg_home_address_error($values['home_address']);
        if ($addressError !== null) $errors['home_address'] = $addressError;
        if (!$errors) {
            try {
                $db->beginTransaction();
                $locked = $db->prepare("SELECT id_number FROM users
                    WHERE id_number = :uid AND account_status = 'active' FOR UPDATE");
                $locked->execute([':uid' => $user['id_number']]);
                if (!$locked->fetchColumn()) throw new RuntimeException('Account not active.');
                $update = $db->prepare('UPDATE users SET first_name = :first,
                    family_name = :last, home_address = :address WHERE id_number = :uid');
                $update->execute([
                    ':first' => $values['first_name'], ':last' => $values['family_name'],
                    ':address' => $values['home_address'], ':uid' => $user['id_number'],
                ]);
                bg_log($db, 'PROFILE_UPDATED', null, $user['id_number']);
                $db->commit();
                $_SESSION['bg_profile_notice'] = 'Your profile was updated successfully.';
                header('Location: profile.php', true, 303);
                exit;
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                error_log('BillGuard: profile update failed.');
                $message = 'Could not update your profile. Please try again.';
            }
        }
    } else {
        http_response_code(400);
        $message = 'Unknown profile action.';
    }
}
$v = static fn(string $key): string => bg_safe((string) ($values[$key] ?? ''));
$err = static fn(string $key): string => isset($errors[$key])
    ? '<p class="app-form__error" role="alert">' . bg_safe($errors[$key]) . '</p>' : '';
bg_app_start('My Profile', 'profile', $user);
?>
<header class="app-page__header">
    <div><p class="eyebrow">Account preferences</p><h1>MY PROFILE.</h1>
        <p>Manage your household details and encrypted utility account number securely.</p></div>
</header>
<?php if ($notice): ?><div class="notice notice--cyan" role="status"><?= bg_safe($notice) ?></div><?php endif; ?>
<?php if ($message): ?><div class="notice notice--error" role="alert"><?= bg_safe($message) ?></div><?php endif; ?>
<div class="app-split">
    <section class="app-panel" aria-labelledby="profile-form-title">
        <div class="app-panel__top"><h2 id="profile-form-title">PERSONAL INFORMATION</h2></div>
        <form class="app-form" method="post" action="profile.php" novalidate>
            <?= bg_csrf_field() ?>
            <input type="hidden" name="action" value="profile">
            <div class="app-form__grid">
                <div class="form-field">
                    <label class="form-label" for="first_name">First Name *</label>
                    <input class="form-control" id="first_name" type="text" name="first_name" required
                        maxlength="<?= (int) $seed['name_max'] ?>" value="<?= $v('first_name') ?>">
                    <?= $err('first_name') ?>
                </div>
                <div class="form-field">
                    <label class="form-label" for="family_name">Last Name *</label>
                    <input class="form-control" id="family_name" type="text" name="family_name" required
                        maxlength="<?= (int) $seed['name_max'] ?>" value="<?= $v('family_name') ?>">
                    <?= $err('family_name') ?>
                </div>
                <div class="form-field app-form__wide">
                    <label class="form-label" for="home_address">Home Address *</label>
                    <input class="form-control" id="home_address" type="text" name="home_address"
                        minlength="5" maxlength="180" required value="<?= $v('home_address') ?>">
                    <?= $err('home_address') ?>
                </div>
            </div>
            <p class="app-form__help">Capital letters, accents, and printable special characters are permitted.</p>
            <div class="app-form__actions">
                <button type="submit" class="button button--small">Save Profile Changes</button>
            </div>
        </form>
    </section>
    <section class="app-panel" aria-labelledby="security-profile-title">
        <div class="app-panel__top"><h2 id="security-profile-title">ACCOUNT SECURITY</h2></div>
        <div class="form-field">
            <label class="form-label" for="profile-email">Verified Email Address</label>
            <input class="form-control" id="profile-email" type="email" readonly value="<?= $v('email') ?>">
            <span class="app-profile-lock">Email changes are locked until a new email verification process is implemented.</span>
        </div>
        <div class="form-field">
            <label class="form-label" for="profile-username">Username</label>
            <input class="form-control" id="profile-username" type="text" readonly value="<?= $v('username') ?>">
            <span class="app-profile-lock">Your username cannot be changed from this page.</span>
        </div>
        <p class="app-mini-note">Password recovery requires your registered email code and security question. Do not share passwords or OTPs.</p>
        <p class="app-mini-note"><a href="privacy.php">Read the Privacy Notice</a></p>
    </section>
</div>
<section class="app-panel app-utility-security" aria-labelledby="utility-security-title">
    <div class="app-panel__top">
        <h2 id="utility-security-title">ENCRYPTED UTILITY ACCOUNT</h2>
    </div>
    <p class="app-mini-note">
        Your Utility Account Number is encrypted using AES-256-GCM with a fresh
        random IV each time it is saved. The number belongs to your account only.
        It is never displayed publicly or included in security logs.
    </p>
    <?php if (!$utilitySchemaReady): ?>
        <div class="notice notice--error" role="alert">
            Encrypted account storage columns are missing. Check your local database
            setup before enabling this feature.
        </div>
    <?php elseif (!$utilityKeyReady): ?>
        <div class="notice notice--error" role="alert">
            Encryption is not configured. Add a private 32-byte encryption key
            to backend/config.php before saving an account number.
            Never place the key in GitHub.
        </div>
    <?php endif; ?>
    <p class="app-utility-security__status">
        <strong>Stored value:</strong>
        <?= $utilityStored ? '•••• •••• (encrypted at rest)' : 'None saved' ?>
    </p>
    <form class="app-form" method="post" action="profile.php" autocomplete="off">
        <?= bg_csrf_field() ?>
        <input type="hidden" name="action" value="utility_save">
        <div class="form-field">
            <label class="form-label" for="utility_account_number">
                Utility Account Number <span class="form-required">*</span>
            </label>
            <input class="form-control" id="utility_account_number"
                name="utility_account_number" type="password" inputmode="numeric"
                pattern="[0-9]{8,12}" minlength="8" maxlength="12"
                autocomplete="off" placeholder="Enter 8–12 digits"
                aria-describedby="utility-account-help" required>
            <p class="app-form__help" id="utility-account-help">
                Enter 8–12 digits. Saving a new number securely replaces the previous one.
                For demonstration, use a fictitious number.
            </p>
            <?= $err('utility_account_number') ?>
        </div>
        <div class="app-form__actions">
            <button type="submit" class="button button--small"
                <?= !$utilitySchemaReady || !$utilityKeyReady ? 'disabled' : '' ?>>
                Save Encrypted Number
            </button>
        </div>
    </form>
    <?php if ($utilityStored && $utilitySchemaReady): ?>
        <div class="app-utility-security__actions">
            <form action="profile.php" method="post">
                <?= bg_csrf_field() ?>
                <input type="hidden" name="action" value="utility_reveal">
                <button class="button button--ghost button--small" type="submit"
                    <?= !$utilityKeyReady ? 'disabled' : '' ?>>
                    Reveal My Number
                </button>
            </form>
            <form action="profile.php" method="post">
                <?= bg_csrf_field() ?>
                <input type="hidden" name="action" value="utility_remove">
                <button class="button button--ghost button--small" type="submit">
                    Remove Saved Number
                </button>
            </form>
        </div>
    <?php endif; ?>
    <?php if ($utilityReveal !== null): ?>
        <div class="notice notice--cyan app-utility-security__reveal" role="status">
            <strong>Your decrypted Utility Account Number:</strong>
            <code><?= bg_safe($utilityReveal) ?></code>
            <p class="app-form__help">
                Visible only in this authenticated response. Leave or refresh
                the page to hide it again. Do not share screenshots of this value.
            </p>
        </div>
    <?php endif; ?>
</section>
<?php bg_app_end(); ?>
