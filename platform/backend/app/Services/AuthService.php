<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Middleware\AuthMiddleware;
use RuntimeException;

/**
 * Authentication service.
 *
 * Handles login (with lockout after 5 failed attempts), registration,
 * token refresh, logout, and password reset. Uses bcrypt for password
 * hashing and HS256 JWT tokens with user id, tenant_id, and role claims.
 */
final class AuthService
{
    private const MAX_FAILED_ATTEMPTS = 5;
    private const LOCKOUT_MINUTES     = 15;

    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ------------------------------------------------------------------
    // Login
    // ------------------------------------------------------------------

    /**
     * Authenticate a user by email and password.
     *
     * @return array{access_token: string, refresh_token: string, user: array}
     * @throws RuntimeException
     */
    public function login(string $email, string $password, ?string $tenantId = null): array
    {
        $conditions = ['email' => $email];
        if ($tenantId !== null) {
            $conditions['tenant_id'] = $tenantId;
        }

        $user = $this->db->findScoped('users', $conditions);

        // Check lockout
        if ($user !== null && $this->isLockedOut($user)) {
            throw new RuntimeException('Account is temporarily locked due to too many failed login attempts. Try again later.', 429);
        }

        // Verify credentials
        if ($user === null || !password_verify($password, $user['password_hash'])) {
            if ($user !== null) {
                $this->recordFailedAttempt($user['id']);
            }
            throw new RuntimeException('Invalid email or password.', 401);
        }

        // Check account status
        if (($user['status'] ?? 'active') !== 'active') {
            throw new RuntimeException('Account is deactivated. Contact your administrator.', 403);
        }

        // Reset failed attempts on successful login
        $this->resetFailedAttempts($user['id']);
        $this->recordLogin($user['id']);

        // Generate tokens
        $payload = [
            'sub'       => $user['id'],
            'tenant_id' => $user['tenant_id'],
            'role'      => $user['role'] ?? 'member',
            'email'     => $user['email'],
        ];

        $accessToken  = AuthMiddleware::createToken($payload);
        $refreshToken = AuthMiddleware::createRefreshToken($payload);

        // Store refresh token hash for revocation support
        $this->db->query(
            'INSERT INTO refresh_tokens (id, user_id, token_hash, expires_at, created_at)
             VALUES (UUID(), ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), NOW())',
            [$user['id'], hash('sha256', $refreshToken), (int) env('JWT_REFRESH_EXPIRY', 604800)],
        );

        return [
            'access_token'          => $accessToken,
            'refresh_token'         => $refreshToken,
            'token_type'            => 'Bearer',
            'expires_in'            => (int) env('JWT_EXPIRY', 3600),
            'user'                  => $this->sanitizeUser($user),
            'force_password_change' => (bool) ($user['force_password_change'] ?? false),
        ];
    }

    // ------------------------------------------------------------------
    // Registration
    // ------------------------------------------------------------------

    /**
     * Register a new user.
     */
    public function register(array $data): array
    {
        // Check for duplicate email within the tenant
        $existing = $this->db->findScoped('users', [
            'email'     => $data['email'],
            'tenant_id' => $data['tenant_id'],
        ]);

        if ($existing !== null) {
            throw new RuntimeException('A user with this email already exists.', 409);
        }

        $userId = $this->generateUuid();
        $now    = date('Y-m-d H:i:s');

        $this->db->query(
            'INSERT INTO users (id, tenant_id, email, password_hash, first_name, last_name, role, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $userId,
                $data['tenant_id'],
                $data['email'],
                password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]),
                $data['first_name'] ?? '',
                $data['last_name'] ?? '',
                $data['role'] ?? 'member',
                'active',
                $now,
                $now,
            ],
        );

        $user = $this->db->findScoped('users', ['id' => $userId]);

        // Auto-login: generate tokens
        $payload = [
            'sub'       => $userId,
            'tenant_id' => $data['tenant_id'],
            'role'      => $data['role'] ?? 'member',
            'email'     => $data['email'],
        ];

        return [
            'access_token'  => AuthMiddleware::createToken($payload),
            'refresh_token' => AuthMiddleware::createRefreshToken($payload),
            'token_type'    => 'Bearer',
            'expires_in'    => (int) env('JWT_EXPIRY', 3600),
            'user'          => $this->sanitizeUser($user),
        ];
    }

    // ------------------------------------------------------------------
    // Token Refresh
    // ------------------------------------------------------------------

    /**
     * Issue a new access token using a valid refresh token.
     */
    public function refresh(string $refreshToken): array
    {
        $payload = AuthMiddleware::decodePayload($refreshToken);
        if ($payload === null || ($payload['type'] ?? '') !== 'refresh') {
            throw new RuntimeException('Invalid refresh token.', 401);
        }

        if (isset($payload['exp']) && $payload['exp'] < time()) {
            throw new RuntimeException('Refresh token has expired.', 401);
        }

        // Verify the token has not been revoked
        $tokenHash = hash('sha256', $refreshToken);
        $stored = $this->db->fetchOne(
            'SELECT id FROM refresh_tokens WHERE token_hash = ? AND revoked = 0 AND expires_at > NOW()',
            [$tokenHash],
        );

        if ($stored === null) {
            throw new RuntimeException('Refresh token has been revoked.', 401);
        }

        // Revoke old refresh token (rotation)
        $this->db->execute(
            'UPDATE refresh_tokens SET revoked = 1 WHERE token_hash = ?',
            [$tokenHash],
        );

        // Fetch fresh user data
        $user = $this->db->findScoped('users', ['id' => $payload['sub']]);
        if ($user === null || ($user['status'] ?? '') !== 'active') {
            throw new RuntimeException('User account is no longer active.', 403);
        }

        $newPayload = [
            'sub'       => $user['id'],
            'tenant_id' => $user['tenant_id'],
            'role'      => $user['role'],
            'email'     => $user['email'],
        ];

        $newAccessToken  = AuthMiddleware::createToken($newPayload);
        $newRefreshToken = AuthMiddleware::createRefreshToken($newPayload);

        $this->db->query(
            'INSERT INTO refresh_tokens (id, user_id, token_hash, expires_at, created_at)
             VALUES (UUID(), ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), NOW())',
            [$user['id'], hash('sha256', $newRefreshToken), (int) env('JWT_REFRESH_EXPIRY', 604800)],
        );

        return [
            'access_token'  => $newAccessToken,
            'refresh_token' => $newRefreshToken,
            'token_type'    => 'Bearer',
            'expires_in'    => (int) env('JWT_EXPIRY', 3600),
        ];
    }

    // ------------------------------------------------------------------
    // Logout
    // ------------------------------------------------------------------

    /**
     * Revoke all refresh tokens for the user.
     */
    public function logout(string $userId): void
    {
        $this->db->execute(
            'UPDATE refresh_tokens SET revoked = 1 WHERE user_id = ?',
            [$userId],
        );
    }

    // ------------------------------------------------------------------
    // Password Reset
    // ------------------------------------------------------------------

    /**
     * Initiate a password reset by generating a token and (in production)
     * sending an email. Returns the token for API/testing purposes.
     */
    public function forgotPassword(string $email, ?string $tenantId = null): array
    {
        $conditions = ['email' => $email];
        if ($tenantId !== null) {
            $conditions['tenant_id'] = $tenantId;
        }

        $user = $this->db->findScoped('users', $conditions);

        // Always return success to prevent email enumeration
        if ($user === null) {
            return ['message' => 'If the email exists, a reset link has been sent.'];
        }

        $token     = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);

        // Invalidate previous reset tokens
        $this->db->execute(
            'UPDATE password_resets SET used = 1 WHERE user_id = ?',
            [$user['id']],
        );

        $this->db->query(
            'INSERT INTO password_resets (id, user_id, token_hash, expires_at, created_at)
             VALUES (UUID(), ?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR), NOW())',
            [$user['id'], $tokenHash],
        );

        // In production, send email here via mail service
        // MailService::send($user['email'], 'password-reset', ['token' => $token]);

        return ['message' => 'If the email exists, a reset link has been sent.'];
    }

    /**
     * Reset a password using a valid reset token.
     */
    public function resetPassword(string $token, string $newPassword): void
    {
        $tokenHash = hash('sha256', $token);

        $reset = $this->db->fetchOne(
            'SELECT user_id FROM password_resets
             WHERE token_hash = ? AND used = 0 AND expires_at > NOW()
             ORDER BY created_at DESC LIMIT 1',
            [$tokenHash],
        );

        if ($reset === null) {
            throw new RuntimeException('Invalid or expired reset token.', 400);
        }

        $this->db->execute(
            'UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?',
            [password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]), $reset['user_id']],
        );

        // Mark token as used
        $this->db->execute(
            'UPDATE password_resets SET used = 1 WHERE token_hash = ?',
            [$tokenHash],
        );

        // Revoke all refresh tokens for security
        $this->logout($reset['user_id']);
    }

    // ------------------------------------------------------------------
    // Change Password
    // ------------------------------------------------------------------

    public function changePassword(string $userId, string $currentPassword, string $newPassword): void
    {
        $user = $this->db->findScoped('users', ['id' => $userId]);
        if ($user === null) {
            throw new RuntimeException('User not found.', 404);
        }

        if (!password_verify($currentPassword, $user['password_hash'])) {
            throw new RuntimeException('Current password is incorrect.', 400);
        }

        if (strlen($newPassword) < 8) {
            throw new RuntimeException('New password must be at least 8 characters.', 422);
        }

        $this->db->execute(
            'UPDATE users SET password_hash = ?, password_changed_at = NOW(), force_password_change = 0, updated_at = NOW() WHERE id = ?',
            [password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]), $userId],
        );

        $this->logout($userId);
    }

    // ------------------------------------------------------------------
    // Lockout Helpers
    // ------------------------------------------------------------------

    private function isLockedOut(array $user): bool
    {
        $failedAttempts = (int) ($user['failed_login_attempts'] ?? 0);
        if ($failedAttempts < self::MAX_FAILED_ATTEMPTS) {
            return false;
        }

        $lastAttempt = $user['last_failed_login_at'] ?? null;
        if ($lastAttempt === null) {
            return false;
        }

        $lockoutUntil = strtotime($lastAttempt) + (self::LOCKOUT_MINUTES * 60);
        return time() < $lockoutUntil;
    }

    private function recordFailedAttempt(string $userId): void
    {
        $this->db->execute(
            'UPDATE users SET failed_login_attempts = failed_login_attempts + 1,
             last_failed_login_at = NOW() WHERE id = ?',
            [$userId],
        );
    }

    private function resetFailedAttempts(string $userId): void
    {
        $this->db->execute(
            'UPDATE users SET failed_login_attempts = 0, last_failed_login_at = NULL WHERE id = ?',
            [$userId],
        );
    }

    private function recordLogin(string $userId): void
    {
        $this->db->execute(
            'UPDATE users SET last_login_at = NOW() WHERE id = ?',
            [$userId],
        );
    }

    // ------------------------------------------------------------------
    // Utilities
    // ------------------------------------------------------------------

    /**
     * Strip sensitive fields before returning user data.
     */
    private function sanitizeUser(?array $user): ?array
    {
        if ($user === null) {
            return null;
        }

        unset(
            $user['password_hash'],
            $user['failed_login_attempts'],
            $user['last_failed_login_at'],
        );

        return $user;
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant 1

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
