<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

/**
 * Protection #9: central authorization for protected admin-only log views.
 * Each denial is recorded without trusting an ID/role supplied in a URL.
 * Neither this check nor the reader ever returns passwords, OTPs or log details.
 *
 * @return array|null current authenticated admin user, null on denial
 */
function bg_admin_audit_authorize(PDO $db, array $seed, string $resource): ?array
{
    if (!in_array($resource, ['page', 'feed'], true)) {
        throw new InvalidArgumentException('Unexpected admin audit resource.');
    }
    // A deliberate admin page navigation is activity. Passive JSON reads
    // must not silently extend the 15-minute idle session.
    $account = bg_auth_user($db, $seed, $resource === 'page');
    if ($account === null || ($account['role'] ?? null) !== 'admin') {
        // bg_auth_user has already checked session expiry, active account
        // and password fingerprint before any role is trusted.
        $id = $account === null ? null : (string) $account['id_number'];
        try {
            bg_log($db, 'ADMIN_ACCESS_DENIED', null, $id, ['resource' => $resource]);
        } catch (Throwable $e) {
            error_log('BillGuard: admin access-denial audit unavailable.');
        }
        return null;
    }
    return $account;
}

/** Read-only most-recent events; no audit metadata or secrets exposed. */
function bg_admin_audit_latest(PDO $db): array
{
    $q = $db->query('SELECT event_type, username_typed, ip_address, created_at
        FROM activity_log ORDER BY id DESC LIMIT 50');
    return $q->fetchAll();
}

/** Emits the canonical view event after the authorized read succeeds. */
function bg_admin_audit_viewed(PDO $db, array $admin, string $resource): void
{
    if (($admin['role'] ?? null) !== 'admin'
        || !in_array($resource, ['page', 'feed'], true)) {
        throw new InvalidArgumentException('Invalid admin audit context.');
    }
    bg_log($db, 'ADMIN_VIEWED_DASHBOARD', null, (string) $admin['id_number'],
        ['resource' => $resource]);
}
