<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use RuntimeException;

/**
 * Account management service.
 *
 * Handles account CRUD operations including creation with unique account
 * number generation, retrieval, status updates, and closure. All queries
 * are automatically scoped to the current tenant via Database.
 */
final class AccountService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ------------------------------------------------------------------
    // Create
    // ------------------------------------------------------------------

    /**
     * Open a new account for a user.
     *
     * @param array{
     *     user_id: string,
     *     account_type: string,
     *     currency?: string,
     *     name?: string,
     * } $data
     */
    public function create(array $data, string $tenantId): array
    {
        $accountId     = $this->generateUuid();
        $accountNumber = $this->generateAccountNumber($tenantId);
        $now           = date('Y-m-d H:i:s');

        $interestRate = (float) ($data['interest_rate_value'] ?? 0);

        $this->db->query(
            'INSERT INTO accounts
                (id, tenant_id, user_id, account_number, account_type, name, currency, balance, available_balance, interest_rate, status, opened_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0.00, 0.00, ?, ?, ?, ?, ?)',
            [
                $accountId,
                $tenantId,
                $data['user_id'],
                $accountNumber,
                $data['account_type'],
                $data['name'] ?? $this->defaultAccountName($data['account_type']),
                $data['currency'] ?? 'USD',
                $interestRate,
                'active',
                $now,
                $now,
                $now,
            ],
        );

        return $this->findById($accountId);
    }

    // ------------------------------------------------------------------
    // Read
    // ------------------------------------------------------------------

    /**
     * Get all accounts for a specific user.
     */
    public function getByUser(string $userId, int $page = 1, int $perPage = 20): array
    {
        $offset = ($page - 1) * $perPage;

        $accounts = $this->db->selectScoped(
            'accounts',
            ['user_id' => $userId],
            'created_at DESC',
            $perPage,
            $offset,
        );

        $total = $this->db->countScoped('accounts', ['user_id' => $userId]);

        return ['items' => $accounts, 'total' => $total];
    }

    /**
     * Get account details by ID.
     */
    public function findById(string $accountId): ?array
    {
        return $this->db->findScoped('accounts', ['id' => $accountId]);
    }

    /**
     * Get account by account number.
     */
    public function findByAccountNumber(string $accountNumber): ?array
    {
        return $this->db->findScoped('accounts', ['account_number' => $accountNumber]);
    }

    /**
     * Get all accounts for the current tenant (admin view).
     */
    public function getAll(int $page = 1, int $perPage = 20, ?string $status = null): array
    {
        $conditions = [];
        if ($status !== null) {
            $conditions['status'] = $status;
        }

        $offset   = ($page - 1) * $perPage;
        $accounts = $this->db->selectScoped('accounts', $conditions, 'created_at DESC', $perPage, $offset);
        $total    = $this->db->countScoped('accounts', $conditions);

        return ['items' => $accounts, 'total' => $total];
    }

    // ------------------------------------------------------------------
    // Update
    // ------------------------------------------------------------------

    /**
     * Update account details (name, status).
     */
    public function update(string $accountId, array $data): array
    {
        $account = $this->findById($accountId);
        if ($account === null) {
            throw new RuntimeException('Account not found.', 404);
        }

        $updatable = [];
        if (isset($data['name'])) {
            $updatable['name'] = $data['name'];
        }
        if (isset($data['status'])) {
            $this->validateStatusTransition($account['status'], $data['status']);
            $updatable['status'] = $data['status'];
        }

        if (empty($updatable)) {
            return $account;
        }

        $updatable['updated_at'] = date('Y-m-d H:i:s');

        $this->db->updateScoped('accounts', $updatable, ['id' => $accountId]);

        return $this->findById($accountId);
    }

    /**
     * Update the account status.
     */
    public function updateStatus(string $accountId, string $newStatus): array
    {
        return $this->update($accountId, ['status' => $newStatus]);
    }

    // ------------------------------------------------------------------
    // Close
    // ------------------------------------------------------------------

    /**
     * Close an account. Balance must be zero.
     */
    public function close(string $accountId): array
    {
        $account = $this->findById($accountId);
        if ($account === null) {
            throw new RuntimeException('Account not found.', 404);
        }

        if ((float) $account['balance'] !== 0.0) {
            throw new RuntimeException('Account balance must be zero before closing.', 422);
        }

        if ($account['status'] === 'closed') {
            throw new RuntimeException('Account is already closed.', 422);
        }

        $this->db->updateScoped('accounts', [
            'status'    => 'closed',
            'closed_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $accountId]);

        return $this->findById($accountId);
    }

    // ------------------------------------------------------------------
    // Balance
    // ------------------------------------------------------------------

    /**
     * Get the current balance for an account.
     */
    public function getBalance(string $accountId): array
    {
        $account = $this->findById($accountId);
        if ($account === null) {
            throw new RuntimeException('Account not found.', 404);
        }

        return [
            'account_id'        => $account['id'],
            'account_number'    => $account['account_number'],
            'balance'           => $account['balance'],
            'available_balance' => $account['available_balance'],
            'currency'          => $account['currency'],
        ];
    }

    /**
     * Update account balance (internal use by TransactionService).
     */
    public function updateBalance(string $accountId, float $amount): void
    {
        $this->db->execute(
            'UPDATE accounts SET balance = balance + ?, available_balance = available_balance + ?, updated_at = NOW()
             WHERE id = ? AND tenant_id = ?',
            [$amount, $amount, $accountId, $this->db->getTenantId()],
        );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Verify an account belongs to a user.
     */
    public function verifyOwnership(string $accountId, string $userId): bool
    {
        $account = $this->findById($accountId);
        return $account !== null && $account['user_id'] === $userId;
    }

    /**
     * Generate a unique 10-digit account number.
     */
    private function generateAccountNumber(string $tenantId): string
    {
        $maxAttempts = 10;
        for ($i = 0; $i < $maxAttempts; $i++) {
            $number = str_pad((string) random_int(1000000000, 9999999999), 10, '0', STR_PAD_LEFT);

            $exists = $this->db->fetchOne(
                'SELECT id FROM accounts WHERE account_number = ? AND tenant_id = ?',
                [$number, $tenantId],
            );

            if ($exists === null) {
                return $number;
            }
        }

        throw new RuntimeException('Failed to generate unique account number.');
    }

    private function defaultAccountName(string $type): string
    {
        return match ($type) {
            'checking' => 'Checking Account',
            'savings'  => 'Savings Account',
            'loan'     => 'Loan Account',
            'cd'       => 'Certificate of Deposit',
            'money_market' => 'Money Market Account',
            default    => ucfirst($type) . ' Account',
        };
    }

    private function validateStatusTransition(string $current, string $new): void
    {
        $allowed = [
            'active'    => ['frozen', 'dormant', 'closed'],
            'frozen'    => ['active', 'closed'],
            'dormant'   => ['active', 'closed'],
            'closed'    => [], // terminal state
        ];

        if (!in_array($new, $allowed[$current] ?? [], true)) {
            throw new RuntimeException("Cannot transition account from '{$current}' to '{$new}'.", 422);
        }
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
