<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use RuntimeException;

/**
 * Transaction processing service.
 *
 * Handles deposits, withdrawals, and transfers between accounts.
 * Every transaction creates double-entry ledger entries and runs
 * inside a database transaction for full atomicity.
 */
final class TransactionService
{
    private Database $db;
    private AccountService $accounts;
    private LedgerService $ledger;

    public function __construct()
    {
        $this->db       = Database::getInstance();
        $this->accounts = new AccountService();
        $this->ledger   = new LedgerService();
    }

    // ------------------------------------------------------------------
    // Deposit
    // ------------------------------------------------------------------

    /**
     * Deposit funds into an account.
     */
    public function deposit(string $accountId, float $amount, string $description = '', ?string $userId = null): array
    {
        if ($amount <= 0) {
            throw new RuntimeException('Deposit amount must be positive.', 422);
        }

        $account = $this->accounts->findById($accountId);
        if ($account === null) {
            throw new RuntimeException('Account not found.', 404);
        }
        $this->assertAccountActive($account);

        return $this->db->transaction(function () use ($accountId, $amount, $description, $userId, $account) {
            $txnId   = $this->generateUuid();
            $refNo   = $this->generateReferenceNumber();
            $now     = date('Y-m-d H:i:s');

            // Create transaction record
            $this->db->query(
                'INSERT INTO transactions
                    (id, tenant_id, reference_number, type, status, amount, currency,
                     to_account_id, user_id, description, created_at, completed_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $txnId,
                    $this->db->getTenantId(),
                    $refNo,
                    'deposit',
                    'completed',
                    $amount,
                    $account['currency'],
                    $accountId,
                    $userId,
                    $description ?: 'Cash deposit',
                    $now,
                    $now,
                ],
            );

            // Update account balance
            $this->accounts->updateBalance($accountId, $amount);

            // Create ledger entries: Debit Cash, Credit Member Account
            $glCredit = $this->glAccountForType($account['account_type']);
            $this->ledger->createDoubleEntry(
                $txnId,
                "Deposit to account {$account['account_number']}",
                LedgerService::GL_MEMBER_DEPOSITS,
                $glCredit,
                $amount,
            );

            return $this->findById($txnId);
        });
    }

    // ------------------------------------------------------------------
    // Withdrawal
    // ------------------------------------------------------------------

    /**
     * Withdraw funds from an account.
     */
    public function withdraw(string $accountId, float $amount, string $description = '', ?string $userId = null): array
    {
        if ($amount <= 0) {
            throw new RuntimeException('Withdrawal amount must be positive.', 422);
        }

        $account = $this->accounts->findById($accountId);
        if ($account === null) {
            throw new RuntimeException('Account not found.', 404);
        }
        $this->assertAccountActive($account);
        $this->assertSufficientBalance($account, $amount);

        return $this->db->transaction(function () use ($accountId, $amount, $description, $userId, $account) {
            $txnId = $this->generateUuid();
            $refNo = $this->generateReferenceNumber();
            $now   = date('Y-m-d H:i:s');

            $this->db->query(
                'INSERT INTO transactions
                    (id, tenant_id, reference_number, type, status, amount, currency,
                     from_account_id, user_id, description, created_at, completed_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $txnId,
                    $this->db->getTenantId(),
                    $refNo,
                    'withdrawal',
                    'completed',
                    $amount,
                    $account['currency'],
                    $accountId,
                    $userId,
                    $description ?: 'Cash withdrawal',
                    $now,
                    $now,
                ],
            );

            $this->accounts->updateBalance($accountId, -$amount);

            // Ledger: Debit Member Account, Credit Cash
            $glDebit = $this->glAccountForType($account['account_type']);
            $this->ledger->createDoubleEntry(
                $txnId,
                "Withdrawal from account {$account['account_number']}",
                $glDebit,
                LedgerService::GL_MEMBER_DEPOSITS,
                $amount,
            );

            return $this->findById($txnId);
        });
    }

    // ------------------------------------------------------------------
    // Transfer
    // ------------------------------------------------------------------

    /**
     * Transfer funds between two accounts.
     */
    public function transfer(
        string $fromAccountId,
        string $toAccountId,
        float  $amount,
        string $description = '',
        ?string $userId = null,
    ): array {
        if ($amount <= 0) {
            throw new RuntimeException('Transfer amount must be positive.', 422);
        }

        if ($fromAccountId === $toAccountId) {
            throw new RuntimeException('Cannot transfer to the same account.', 422);
        }

        $fromAccount = $this->accounts->findById($fromAccountId);
        $toAccount   = $this->accounts->findById($toAccountId);

        if ($fromAccount === null || $toAccount === null) {
            throw new RuntimeException('One or both accounts not found.', 404);
        }

        $this->assertAccountActive($fromAccount);
        $this->assertAccountActive($toAccount);
        $this->assertSufficientBalance($fromAccount, $amount);

        if ($fromAccount['currency'] !== $toAccount['currency']) {
            throw new RuntimeException('Cross-currency transfers are not supported.', 422);
        }

        return $this->db->transaction(function () use (
            $fromAccountId, $toAccountId, $amount, $description, $userId, $fromAccount, $toAccount,
        ) {
            $txnId = $this->generateUuid();
            $refNo = $this->generateReferenceNumber();
            $now   = date('Y-m-d H:i:s');

            $this->db->query(
                'INSERT INTO transactions
                    (id, tenant_id, reference_number, type, status, amount, currency,
                     from_account_id, to_account_id, user_id, description, created_at, completed_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $txnId,
                    $this->db->getTenantId(),
                    $refNo,
                    'transfer',
                    'completed',
                    $amount,
                    $fromAccount['currency'],
                    $fromAccountId,
                    $toAccountId,
                    $userId,
                    $description ?: "Transfer to {$toAccount['account_number']}",
                    $now,
                    $now,
                ],
            );

            $this->accounts->updateBalance($fromAccountId, -$amount);
            $this->accounts->updateBalance($toAccountId, $amount);

            // Ledger: Debit destination liability, Credit source liability
            $fromGl = $this->glAccountForType($fromAccount['account_type']);
            $toGl   = $this->glAccountForType($toAccount['account_type']);

            $this->ledger->createDoubleEntry(
                $txnId,
                "Transfer {$fromAccount['account_number']} -> {$toAccount['account_number']}",
                $toGl,
                $fromGl,
                $amount,
            );

            return $this->findById($txnId);
        });
    }

    // ------------------------------------------------------------------
    // Read
    // ------------------------------------------------------------------

    /**
     * Find a transaction by ID.
     */
    public function findById(string $transactionId): ?array
    {
        return $this->db->findScoped('transactions', ['id' => $transactionId]);
    }

    /**
     * Get transactions for a specific account.
     */
    public function getByAccount(string $accountId, int $page = 1, int $perPage = 20): array
    {
        $tenantId = $this->db->getTenantId();
        $offset   = ($page - 1) * $perPage;

        $items = $this->db->fetchAll(
            'SELECT * FROM transactions
             WHERE tenant_id = ? AND (from_account_id = ? OR to_account_id = ?)
             ORDER BY created_at DESC
             LIMIT ? OFFSET ?',
            [$tenantId, $accountId, $accountId, $perPage, $offset],
        );

        $total = (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM transactions
             WHERE tenant_id = ? AND (from_account_id = ? OR to_account_id = ?)',
            [$tenantId, $accountId, $accountId],
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Get transactions by user.
     */
    public function getByUser(string $userId, int $page = 1, int $perPage = 20): array
    {
        $offset = ($page - 1) * $perPage;

        $items = $this->db->selectScoped(
            'transactions',
            ['user_id' => $userId],
            'created_at DESC',
            $perPage,
            $offset,
        );

        $total = $this->db->countScoped('transactions', ['user_id' => $userId]);

        return ['items' => $items, 'total' => $total];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function assertAccountActive(array $account): void
    {
        if ($account['status'] !== 'active') {
            throw new RuntimeException("Account {$account['account_number']} is not active.", 422);
        }
    }

    private function assertSufficientBalance(array $account, float $amount): void
    {
        if ((float) $account['available_balance'] < $amount) {
            throw new RuntimeException('Insufficient funds.', 422);
        }
    }

    /**
     * Map an account type to its GL account code.
     */
    private function glAccountForType(string $accountType): string
    {
        return match ($accountType) {
            'savings'       => LedgerService::GL_MEMBER_SAVINGS,
            'checking'      => LedgerService::GL_MEMBER_CHECKING,
            'shares'        => LedgerService::GL_MEMBER_SHARES,
            default         => LedgerService::GL_MEMBER_SAVINGS,
        };
    }

    /**
     * Generate a unique transaction reference number.
     *
     * Format: TXN-YYYYMMDD-XXXXXXXX
     */
    private function generateReferenceNumber(): string
    {
        return 'TXN-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
