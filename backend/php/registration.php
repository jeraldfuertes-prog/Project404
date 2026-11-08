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
    $length = mb_strlen($value, 'UTF-8');
    if ($length < 2 || $length > $max) {
        return "{$label} must contain between 2 and {$max} characters.";
    }
    if (preg_match('/\d/u', $value)) {
        return "Numbers are not allowed in {$label}.";
    }
    if (preg_match('/[^A-Za-z ]/', $value)) {
        return "Special characters are not allowed in {$label}.";
    }
    if (str_contains($value, '  ')) {
        return "Double space is not allowed in {$label}.";
    }
    if (preg_match('/(.)\1\1/i', $value)) {
        return "Three consecutive identical letters are not allowed in {$label}.";
    }
    if (strtoupper($value) === $value) {
        return 'Do not use all capital letters in ' . $label . '.';
    }
    if (!preg_match('/^[A-Z]/', $value)) {
        return "First letter of your {$label} must be capital.";
    }
    if (!preg_match('/^[A-Z][a-z]*(?: [A-Z][a-z]*)*$/D', $value)) {
        return "{$label} must use Title Case (for example: Juan Carlo).";
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
    $errors = [];
    $clean = [];
    $fields = [
        'id_number', 'first_name', 'middle_name', 'family_name',
        'name_extension', 'extension_other', 'birthdate',
        'street', 'barangay', 'city', 'province', 'country', 'zip_code',
        'email', 'username', 'utility_account_number', 'primary_utility_type',
        'service_start_date', 'monthly_utility_budget',
        'answer_1', 'answer_2', 'answer_3',
    ];
    foreach ($fields as $key) {
        $clean[$key] = bg_form_input($input, $key);
    }
    $password = isset($input['password']) && is_string($input['password']) ? $input['password'] : '';
    $passwordAgain = isset($input['password_confirm']) && is_string($input['password_confirm']) ? $input['password_confirm'] : '';

    if (!preg_match('/^\d{4}-\d{4}$/D', $clean['id_number'])) {
        $errors['id_number'] = 'ID Number must follow the format xxxx-xxxx.';
    }
    foreach (['first_name' => 'First Name', 'family_name' => 'Family Name'] as $field => $label) {
        $errors[$field] = bg_name_error($clean[$field], $label, $seed['name_max']);
        if ($errors[$field] === null) { unset($errors[$field]); }
    }
    if ($clean['middle_name'] !== '') {
        $error = bg_name_error($clean['middle_name'], 'Middle Name', $seed['name_max']);
        if ($error !== null) { $errors['middle_name'] = $error; }
    }
    if (!in_array($clean['name_extension'], ['', 'Jr.', 'Sr.', 'Other'], true)) {
        $errors['name_extension'] = 'Please select a valid Name Extension.';
    } elseif ($clean['name_extension'] === 'Other') {
        if (!in_array($clean['extension_other'], ['I', 'II', 'III', 'IV', 'IX', 'X'], true)) {
            $errors['extension_other'] = 'Other Name Extension must be I, II, III, IV, IX or X.';
        } else {
            $clean['name_extension'] = $clean['extension_other'];
        }
    }

    $birthdate = bg_date($clean['birthdate']);
    if ($birthdate === null || $birthdate > bg_now()) {
        $errors['birthdate'] = 'Please enter a valid Birthdate that is not in the future.';
    } elseif ($birthdate->diff(bg_now())->y < 18) {
        $errors['birthdate'] = 'You must be at least 18 years old to register.';
    }

    $addressFields = [
        'street' => 'Purok / Street', 'barangay' => 'Barangay',
        'city' => 'Municipality / City', 'province' => 'Province', 'country' => 'Country',
    ];
    foreach ($addressFields as $field => $label) {
        $error = bg_address_error($clean[$field], $label, $field === 'street');
        if ($error !== null) { $errors[$field] = $error; }
    }
    if (!preg_match('/^\d{4}$/D', $clean['zip_code'])) {
        $errors['zip_code'] = 'ZIP Code must contain exactly 4 digits.';
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

    if (strlen($password) < $seed['password_min'] || strlen($password) > 64) {
        $errors['password'] = "Password must be between {$seed['password_min']} and 64 characters.";
    } elseif (bg_strength($password, $seed['password_min']) === 'Weak') {
        $errors['password'] = 'Password strength is Weak. Use at least three of: uppercase, lowercase, digits and symbols.';
    }
    if ($passwordAgain !== $password) {
        $errors['password_confirm'] = 'Passwords do not match.';
    }

    if (!preg_match('/^\d{8,12}$/D', $clean['utility_account_number'])) {
        $errors['utility_account_number'] = 'Utility Account Number must contain 8 to 12 digits.';
    }
    if (!in_array($clean['primary_utility_type'], ['Electricity', 'Water', 'Internet'], true)) {
        $errors['primary_utility_type'] = 'Please select a valid utility type.';
    }
    $start = bg_date($clean['service_start_date']);
    if ($start === null || $start > bg_now()) {
        $errors['service_start_date'] = 'Service Start Date must be a valid date that is not in the future.';
    }
    $budget = $clean['monthly_utility_budget'];
    if (!preg_match('/^\d{1,6}(?:\.\d{1,2})?$/D', $budget)
        || (float) $budget <= 0.0 || (float) $budget > 999999.99) {
        $errors['monthly_utility_budget'] = 'Monthly Utility Budget must be greater than ₱0.00 and no more than ₱999,999.99.';
    }

    for ($i = 1; $i <= 3; $i++) {
        $answer = $clean["answer_{$i}"];
        if (strlen($answer) < 2 || strlen($answer) > 50
            || !preg_match('/^[A-Za-z]+(?: [A-Za-z]+)*$/D', $answer)) {
            $errors["answer_{$i}"] = "Security Answer {$i} must contain 2–50 letters and spaces without double spaces.";
        }
    }
    if (($input['privacy_consent'] ?? null) !== 'yes') {
        $errors['privacy_consent'] = 'You must agree to the Privacy Notice to register.';
    }

    // Only do uniqueness lookups after values pass format checks.
    foreach ([
        'id_number' => ['id_number', 'This ID Number is already registered.'],
        'email' => ['email', 'This email is already registered.'],
        'username' => ['username', 'This username is already taken.'],
    ] as $field => [$column, $message]) {
        if (!isset($errors[$field])) {
            // Whitelisted column identifiers; user input only via bound parameter.
            $q = $db->prepare("SELECT 1 FROM users WHERE {$column} = :value LIMIT 1");
            $q->execute([':value' => $clean[$field]]);
            if ($q->fetchColumn()) { $errors[$field] = $message; }
        }
    }

    // Passwords, confirmation and security answers are never returned for repopulation.
    return [$errors, $clean];
}
