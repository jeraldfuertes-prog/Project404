<?php
declare(strict_types=1);
require_once __DIR__ . '/security.php';

/**
 * All values are validated on the SERVER, even when JavaScript is disabled.
 * This module holds the same rules documented in docs/PHASE2_ERROR_MAPPING.md.
 */

function bg_form_input(array $input, string $field): string
{
    $value = $input[$field] ?? '';
    return is_string($value) ? trim($value) : '';
}

function bg_date(string $text): ?DateTimeImmutable
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $text)) {
        return null;
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $text, new DateTimeZone('Asia/Manila'));
    $errs = DateTimeImmutable::getLastErrors();
    return $d !== false && (!$errs || ($errs['error_count'] === 0 && $errs['warning_count'] === 0))
        && $d->format('Y-m-d') === $text ? $d : null;
}

function bg_name_error(string $value, string $label, int $max): ?string
{
    // Allow uppercase, Unicode/accented letters and printable special characters.
    // Reject only control characters/invalid text and enforce required length.
    if (!mb_check_encoding($value, 'UTF-8') || preg_match('/\p{C}/u', $value)) {
        return "{$label} contains unsupported control characters.";
    }
    $length = mb_strlen($value, 'UTF-8');
    if ($length < 2 || $length > $max) {
        return "{$label} must contain between 2 and {$max} characters.";
    }
    return null;
}

function bg_address_error(string $value, string $label, bool $street): ?string
{
    if ($street) {
        if (strlen($value) < 3 || strlen($value) > 50) {
            return 'Purok / Street must contain between 3 and 50 characters.';
        }
        if (!preg_match('/^[A-Za-z0-9 ]+$/D', $value)) {
            return 'Purok / Street may contain letters, digits and spaces only.';
        }
        if (str_contains($value, '  ')) {
            return 'Purok / Street must not contain double spaces.';
        }
        return null;
    }
    if (strlen($value) < 2 || strlen($value) > 50) {
        return "{$label} must contain between 2 and 50 characters.";
    }
    if (str_contains($value, '  ')) {
        return "{$label} must not contain double spaces.";
    }
    if (!preg_match('/^[A-Z][a-z]*(?: [A-Z][a-z]*)*$/D', $value)) {
        return "{$label} must contain letters and spaces in Title Case.";
    }
    return null;
}

function bg_strength(string $password, int $min): string
{
    if (strlen($password) < $min || strlen($password) > 64) {
        return 'Weak';
    }
    $classes = (int) (bool) preg_match('/[a-z]/', $password)
        + (int) (bool) preg_match('/[A-Z]/', $password)
        + (int) (bool) preg_match('/[0-9]/', $password)
        + (int) (bool) preg_match('/[^a-zA-Z0-9]/', $password);
    if ($classes < 3) {
        return 'Weak';
    }
    return $classes === 4 && strlen($password) >= max(12, $min + 4) ? 'Strong' : 'Medium';
}

/** @return array{0:array<string,string>,1:array<string,string>} */
function bg_validate_registration(array $input, array $seed, PDO $db): array
{
    // Simplified registration. The internal ID is generated in PHP rather than supplied.
    $clean = [];
    foreach (['first_name', 'family_name', 'home_address', 'email', 'username', 'answer_1', 'answer_2', 'answer_3'] as $key) {
        $clean[$key] = bg_form_input($input, $key);
    }
    $errors = [];
    foreach (['first_name' => 'First Name', 'family_name' => 'Family Name'] as $key => $label) {
        $message = bg_name_error($clean[$key], $label, (int) $seed['name_max']);
        if ($message !== null) $errors[$key] = $message;
    }

    $address = $clean['home_address'];
    if (!mb_check_encoding($address, 'UTF-8') || preg_match('/\p{C}/u', $address)) {
        $errors['home_address'] = 'Home Address contains unsupported control characters.';
    } elseif (mb_strlen($address, 'UTF-8') < 5 || mb_strlen($address, 'UTF-8') > 180) {
        $errors['home_address'] = 'Home Address must contain between 5 and 180 characters.';
    }

    $email = strtolower($clean['email']);
    $clean['email'] = $email;
    if (strlen($email) > 100 || str_contains($email, ' ') || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address (maximum 100 characters).';
    }

    $username = $clean['username'];
    if (strlen($username) < $seed['username_min']
        || strlen($username) > $seed['username_max']
        || !preg_match('/^[A-Za-z][A-Za-z0-9]*$/D', $username)) {
        $errors['username'] = "Username must start with a letter and contain {$seed['username_min']}–{$seed['username_max']} letters or digits.";
    }

    $password = isset($input['password']) && is_string($input['password']) ? $input['password'] : '';
    $again = isset($input['password_confirm']) && is_string($input['password_confirm']) ? $input['password_confirm'] : '';
    if (strlen($password) < $seed['password_min'] || strlen($password) > 64) {
        $errors['password'] = "Password must be between {$seed['password_min']} and 64 characters.";
    } elseif (bg_strength($password, $seed['password_min']) === 'Weak') {
        $errors['password'] = 'Password strength is Weak. Use at least three of: uppercase, lowercase, digits and symbols.';
    }
    if ($again !== $password) $errors['password_confirm'] = 'Passwords do not match.';

    // Keep the three seed-selected recovery questions; Phase 4 relies on them.
    for ($i = 1; $i <= 3; $i++) {
        $answer = $clean["answer_{$i}"];
        if (strlen($answer) < 2 || strlen($answer) > 50
            || !preg_match('/^[A-Za-z]+(?: [A-Za-z]+)*$/D', $answer)) {
            $errors["answer_{$i}"] = "Security Answer {$i} must contain 2–50 letters and spaces without double spaces.";
        }
    }

    // Consent must be obtained before any personal registration data is saved.
    if (($input['privacy_consent'] ?? null) !== 'yes') {
        $errors['privacy_consent'] = 'You must agree to the Privacy Notice to register.';
    }

    // Protect all database lookups with prepared statements.
    foreach ([
        'email' => ['email', 'This email is already registered.'],
        'username' => ['username', 'This username is already taken.'],
    ] as $field => [$column, $message]) {
        if (!isset($errors[$field])) {
            $check = $db->prepare("SELECT 1 FROM users WHERE {$column} = :value LIMIT 1");
            $check->execute([':value' => $clean[$field]]);
            if ($check->fetchColumn()) $errors[$field] = $message;
        }
    }
    return [$errors, $clean];
}

/**
 * Generate a non-identifying internal primary key for the existing xxxx-xxxx DB schema.
 * This is NOT a student ID. It intentionally differs from the handout's ID Number rule.
 */
function bg_generate_account_id(PDO $db): string
{
    $check = $db->prepare('SELECT 1 FROM users WHERE id_number = :id LIMIT 1');
    for ($try = 0; $try < 30; $try++) {
        $id = sprintf('%04d-%04d', random_int(0, 9999), random_int(0, 9999));
        $check->execute([':id' => $id]);
        if (!$check->fetchColumn()) return $id;
    }
    throw new RuntimeException('Unable to generate a unique account ID.');
}
