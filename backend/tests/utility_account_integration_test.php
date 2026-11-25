<?php
declare(strict_types=1);
/** Synthetic data, disposable GitHub Actions MySQL ONLY; never run on local user data. */
if (PHP_SAPI !== 'cli' || getenv('BG_UTILITY_ENCRYPTION_CI') !== 'yes') {
    fwrite(STDERR, "Run this test only against disposable GitHub Actions MySQL.\n");
    exit(1);
}
require_once dirname(__DIR__) . '/php/utility_account.php';
ob_start();
bg_secure_start();

$checks = 0;
$failed = 0;
function utility_db_check(bool $pass, string $description): void
{
    global $checks, $failed;
    $checks++;
    echo ($pass ? '[OK]   ' : '[FAIL] ') . $description . PHP_EOL;
    if (!$pass) $failed++;
}
try {
    // CI billing_integration_test.php prepares the same disposable schema
    // earlier in this job; this test uses distinct synthetic IDs.
    $pass = (string) getenv('BG_TEST_DB_PASS');
    if ($pass === '') throw new RuntimeException('Disposable DB setup missing.');
    $db = new PDO(
        'mysql:host=127.0.0.1;port=3306;dbname=billguard_dev;charset=utf8mb4',
        'root', $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $db->exec("SET time_zone = '+08:00'");
    utility_db_check(bg_utility_schema_ready($db),
        'Existing users schema includes encrypted value, IV and tag');
    $config = ['encryption_key' => 'base64:' . base64_encode(random_bytes(32))];
    $seed = bg_seed(['seed_d1' => 4, 'seed_d2' => 7]);
    if ($seed === null) throw new RuntimeException('Test seed unavailable.');

    $aliceId = '7575-7575';
    $bobId = '8585-8585';
    $aliceHash = password_hash('Test-Account-A!99', PASSWORD_DEFAULT);
    $bobHash = password_hash('Test-Account-B!99', PASSWORD_DEFAULT);
    $insert = $db->prepare("INSERT INTO users
        (id_number, first_name, family_name, home_address, email, username,
            password_hash, account_status, email_verified_at, consented_at)
        VALUES (:uid, :first, 'Synthetic', 'Synthetic lab address', :email,
            :username, :hash, 'active', NOW(), NOW())");
    $insert->execute([
        ':uid' => $aliceId, ':first' => 'Alice',
        ':email' => 'utility-alice@example.test', ':username' => 'UtilityAlice75',
        ':hash' => $aliceHash,
    ]);
    $insert->execute([
        ':uid' => $bobId, ':first' => 'Bob',
        ':email' => 'utility-bob@example.test', ':username' => 'UtilityBob85',
        ':hash' => $bobHash,
    ]);

    $_SESSION = [
        'user_id' => $aliceId,
        'auth_password_fingerprint' => bg_auth_password_fingerprint($aliceHash),
        'last_activity' => time(),
    ];
    $alice = bg_auth_user($db, $seed, false);
    utility_db_check($alice !== null && $alice['id_number'] === $aliceId,
        'Owner A has a valid authenticated session');
    utility_db_check(!bg_utility_account_present($db, $aliceId),
        'New user has no utility number in the database');
    $aliceNumber = '012345678901'; // Fake for CI, NEVER a real provider account.
    $saved = bg_utility_account_save($db, $alice['id_number'], $aliceNumber, $config);
    utility_db_check($saved['ok'] === true, 'Owner A can save encrypted account number');
    utility_db_check(bg_utility_account_present($db, $aliceId),
        'Saved ciphertext is present for owner A');

    $get = $db->prepare("SELECT utility_account_ciphertext AS cipher,
        utility_account_iv AS iv, utility_account_tag AS tag
        FROM users WHERE id_number = :uid LIMIT 1");
    $get->execute([':uid' => $aliceId]);
    $recordA1 = $get->fetch();
    utility_db_check(is_string($recordA1['cipher'])
        && $recordA1['cipher'] !== $aliceNumber
        && !str_contains($recordA1['cipher'], $aliceNumber),
        'MySQL stores unreadable ciphertext, not the plaintext');
    utility_db_check(strlen((string) $recordA1['iv']) === 12
        && strlen((string) $recordA1['tag']) === 16,
        'MySQL stores a random 12-byte IV and a 16-byte GCM tag');
    utility_db_check(bg_utility_account_reveal($db, $alice['id_number'], $config) === $aliceNumber,
        'Current authenticated owner can decrypt original number');

    $saved = bg_utility_account_save($db, $aliceId, $aliceNumber, $config);
    $get->execute([':uid' => $aliceId]);
    $recordA2 = $get->fetch();
    utility_db_check($saved['ok'] === true && $recordA2['iv'] !== $recordA1['iv'],
        'Saving identical number twice uses a distinct fresh random IV');

    // With owner A still logged in, try to operate on owner B by passing a
    // fabricated ID. The internal ownership guard rejects the attempt.
    $caught = false;
    try {
        bg_utility_account_reveal($db, $bobId, $config);
    } catch (RuntimeException $e) {
        $caught = true;
    }
    utility_db_check($caught, 'Forged owner ID cannot reveal another user account');
    utility_db_check(bg_utility_account_save($db, $bobId, '23456789', $config)['ok'] === false,
        'Forged owner ID cannot write another user account');
    utility_db_check(!bg_utility_account_remove($db, $bobId),
        'Forged owner ID cannot remove another user account');
    utility_db_check(!bg_utility_account_present($db, $bobId),
        'Forged owner ID cannot even query the existence of other account data');
    utility_db_check(bg_utility_account_save($db, $aliceId, 'wrong-value', $config)['ok'] === false,
        'Invalid account number rejected by server');
    utility_db_check(bg_utility_account_save($db, $aliceId, '12345678',
        ['encryption_key' => 'INVALID'])['ok'] === false,
        'Missing/invalid private encryption key cannot overwrite stored number');

    // A second distinct, authenticated owner can access ONLY their record.
    $_SESSION = [
        'user_id' => $bobId,
        'auth_password_fingerprint' => bg_auth_password_fingerprint($bobHash),
        'last_activity' => time(),
    ];
    $bob = bg_auth_user($db, $seed, false);
    utility_db_check($bob !== null && $bob['id_number'] === $bobId,
        'Owner B authenticates independently');
    $bobNumber = '87654321'; // Fake CI-only value.
    utility_db_check(bg_utility_account_save($db, $bob['id_number'], $bobNumber, $config)['ok'] === true,
        'Owner B saves a different encrypted number');
    utility_db_check(bg_utility_account_reveal($db, $bob['id_number'], $config) === $bobNumber,
        'Owner B decrypts only their number');
    $caught = false;
    try {
        bg_utility_account_reveal($db, $aliceId, $config);
    } catch (RuntimeException $e) {
        $caught = true;
    }
    utility_db_check($caught, 'Owner B cannot reveal owner A number');

    // Tamper with an authenticated encryption tag while B is still signed in.
    $get->execute([':uid' => $bobId]);
    $recordB = $get->fetch();
    $altered = (string) $recordB['tag'];
    $altered[0] = chr(ord($altered[0]) ^ 1);
    $update = $db->prepare('UPDATE users SET utility_account_tag = :tag WHERE id_number = :uid');
    $update->execute([':tag' => $altered, ':uid' => $bobId]);
    $caught = false;
    try {
        bg_utility_account_reveal($db, $bobId, $config);
    } catch (RuntimeException $e) {
        $caught = true;
    }
    utility_db_check($caught, 'Tampered GCM authentication tag makes decryption fail');
    $update->execute([':tag' => $recordB['tag'], ':uid' => $bobId]);
    utility_db_check(bg_utility_account_reveal($db, $bobId, $config) === $bobNumber,
        'Restoring the original authentication tag restores integrity');

    utility_db_check(bg_utility_account_remove($db, $bobId),
        'Authenticated owner can remove their encrypted record');
    utility_db_check(!bg_utility_account_present($db, $bobId),
        'Removal clears the encrypted record');
    $get->execute([':uid' => $bobId]);
    $removedRow = $get->fetch();
    utility_db_check($removedRow['cipher'] === null && $removedRow['iv'] === null
        && $removedRow['tag'] === null,
        'Removal clears ciphertext, IV and tag together');

    $events = $db->prepare("SELECT event_type, username_typed, details FROM activity_log
        WHERE user_id IN (:alice, :bob)");
    $events->execute([':alice' => $aliceId, ':bob' => $bobId]);
    $activity = json_encode($events->fetchAll(), JSON_THROW_ON_ERROR);
    utility_db_check(!str_contains($activity, $aliceNumber)
        && !str_contains($activity, $bobNumber),
        'Security audit logs never contain plaintext utility numbers');
    utility_db_check(str_contains($activity, 'UTILITY_ACCOUNT_SAVED')
        && str_contains($activity, 'UTILITY_ACCOUNT_REMOVED'),
        'Security audit logs record writes and removal without secrets');
    echo PHP_EOL . "{$checks} encryption integration checks; {$failed} failed." . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Protection 13 CI integration failed: ' . get_class($e)
        . ' [code ' . (string) $e->getCode() . ']' . PHP_EOL);
    $failed++;
}
ob_end_flush();
exit($failed ? 1 : 0);
