<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use RuntimeException;

/**
 * Supervisor (user-of-authority) override verification.
 *
 * Used to unlock protected actions such as backdating a transaction or loan
 * event. The caller supplies the supervisor's email + password alongside the
 * request; this service verifies the credentials belong to an active
 * admin / manager / super_admin in the current tenant.
 */
final class SupervisorAuthService
{
    public const ROLES = ['super_admin', 'admin', 'manager'];
    private const MAX_FAILED_ATTEMPTS = 5;
    private const LOCKOUT_MINUTES = 15;

    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Verify supervisor credentials. Returns a minimal user record on success.
     *
     * @throws RuntimeException 403 on failure
     */
    public function verify(?string $email, ?string $password): array
    {
        $email = trim((string) $email);
        if ($email === '' || $password === null || $password === '') {
            throw new RuntimeException('Supervisor authorization is required for this action.', 403);
        }

        $user = $this->db->fetchOne(
            'SELECT id, email, first_name, last_name, role, status, password_hash,
                    failed_login_attempts, locked_until
             FROM users WHERE tenant_id = ? AND email = ? LIMIT 1',
            [$this->db->getTenantId(), $email],
        );

        // Lockout: repeated failures lock the supervisor account for a while
        if ($user !== null && !empty($user['locked_until']) && strtotime((string) $user['locked_until']) > time()) {
            throw new RuntimeException('That supervisor account is temporarily locked after too many failed attempts.', 429);
        }

        if ($user === null || !password_verify($password, $user['password_hash'])) {
            if ($user !== null) {
                $attempts = (int) ($user['failed_login_attempts'] ?? 0) + 1;
                $lock = $attempts >= self::MAX_FAILED_ATTEMPTS
                    ? date('Y-m-d H:i:s', time() + self::LOCKOUT_MINUTES * 60)
                    : null;
                $this->db->execute(
                    'UPDATE users SET failed_login_attempts = ?, locked_until = ? WHERE id = ?',
                    [$lock ? 0 : $attempts, $lock, $user['id']],
                );
            }
            throw new RuntimeException('Supervisor authorization failed: invalid email or password.', 403);
        }
        if ((int) ($user['failed_login_attempts'] ?? 0) > 0 || !empty($user['locked_until'])) {
            $this->db->execute('UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = ?', [$user['id']]);
        }
        if (($user['status'] ?? 'active') !== 'active') {
            throw new RuntimeException('Supervisor account is not active.', 403);
        }
        if (!in_array($user['role'], self::ROLES, true)) {
            throw new RuntimeException('That user does not have authority to approve this action.', 403);
        }

        return [
            'id'   => $user['id'],
            'name' => trim($user['first_name'] . ' ' . $user['last_name']),
            'role' => $user['role'],
        ];
    }

    /**
     * Resolve an optional backdated date from request input.
     *
     * Returns null when no date was supplied or the date is today (no override
     * needed). Otherwise verifies the supervisor override credentials
     * (override_email / override_password) and returns the resolved datetime
     * plus authorization details for the audit trail.
     *
     * @throws RuntimeException 422 for a bad/future date, 403 for a bad override
     */
    public function resolveBackdate(array $input, string $field = 'transaction_date'): ?array
    {
        $raw = trim((string) ($input[$field] ?? ''));
        if ($raw === '') {
            return null;
        }

        $date = $this->parseDate($raw);
        if ($date === null) {
            throw new RuntimeException("Invalid {$field}: use YYYY-MM-DD.", 422);
        }

        $today = date('Y-m-d');
        if ($date > $today) {
            throw new RuntimeException("The {$field} cannot be in the future.", 422);
        }
        if ($date === $today) {
            return null;
        }

        $supervisor = $this->verify(
            $input['override_email'] ?? null,
            $input['override_password'] ?? null,
        );

        // Keep the wall-clock time so entries made in sequence keep their order
        return [
            'date'               => $date,
            'datetime'           => $date . ' ' . date('H:i:s'),
            'authorized_by'      => $supervisor['id'],
            'authorized_by_name' => $supervisor['name'],
            'entered_at'         => date('Y-m-d H:i:s'),
        ];
    }

    private function parseDate(string $raw): ?string
    {
        $raw = substr($raw, 0, 10);
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $raw);
        if ($dt === false || $dt->format('Y-m-d') !== $raw) {
            return null;
        }
        return $raw;
    }
}
