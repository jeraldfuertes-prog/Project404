<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/backend/php/billing.php';
require_once dirname(__DIR__) . '/backend/php/app_layout.php';

try {
    [$db, $user, $seed] = bg_app_context();
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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    foreach (['first_name', 'family_name', 'home_address'] as $key) {
        $values[$key] = bg_form_input($_POST, $key);
    }
    if (!bg_check_csrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        $message = 'Your request token expired. Please refresh and try again.';
    } else {
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
    }
}
$v = static fn(string $key): string => bg_safe((string) ($values[$key] ?? ''));
$err = static fn(string $key): string => isset($errors[$key])
    ? '<p class="app-form__error" role="alert">' . bg_safe($errors[$key]) . '</p>' : '';
bg_app_start('My Profile', 'profile', $user);
?>
<header class="app-page__header">
    <div><p class="eyebrow">Account preferences</p><h1>MY PROFILE.</h1>
        <p>Manage your name and household address without changing your secure login.</p></div>
</header>
<?php if ($notice): ?><div class="notice notice--cyan" role="status"><?= bg_safe($notice) ?></div><?php endif; ?>
<?php if ($message): ?><div class="notice notice--error" role="alert"><?= bg_safe($message) ?></div><?php endif; ?>
<div class="app-split">
    <section class="app-panel" aria-labelledby="profile-form-title">
        <div class="app-panel__top"><h2 id="profile-form-title">PERSONAL INFORMATION</h2></div>
        <form class="app-form" method="post" action="profile.php" novalidate>
            <?= bg_csrf_field() ?>
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
        <p class="app-mini-note">Password changes and account recovery require additional identity verification and will be added separately. Do not share passwords or OTPs.</p>
        <p class="app-mini-note"><a href="privacy.php">Read the Privacy Notice</a></p>
    </section>
</div>
<?php bg_app_end(); ?>
