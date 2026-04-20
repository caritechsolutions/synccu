<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use RuntimeException;

/**
 * Transaction processing service.
 *
 * Handles deposits, withdrawals, transfers, fees, adjustments, and expenses.
 * All financial activity is recorded in the transactions table; reports are
 * computed on-the-fly from that single source of truth.
 */
final class TransactionService
{
    private Database $db;
    private AccountService $accounts;

    public function __construct()
    {
        $this->db       = Database::getInstance();
        $this->accounts = new AccountService();
    }

    // ------------------------------------------------------------------
    // Deposit
    // ------------------------------------------------------------------

    /**
     * Deposit funds into an account.
     *
     * If the target account is a loan account, the deposit is treated as a
     * loan payment: interest is calculated daily and the balance is reduced.
     */
    public function deposit(string $accountId, float $amount, string $description = '', ?string $userId = null, array $metadata = []): array
    {
        if ($amount <= 0) {
            throw new RuntimeException('Deposit amount must be positive.', 422);
        }

        $account = $this->accounts->findById($accountId);
        if ($account === null) {
            throw new RuntimeException('Account not found.', 404);
        }
        $this->assertAccountActive($account);

        // Loan account deposits are handled as loan payments
        if ($account['account_type'] === 'loan') {
            $loan = $this->db->findScoped('loans', ['account_id' => $accountId]);
            if ($loan === null) {
                throw new RuntimeException('No loan found for this account.', 404);
            }
            $loanService = new LoanService();
            $result = $loanService->makePayment($loan['id'], $amount, null, $userId);
            return $this->findById($result['payment_id']) ?? $result;
        }

        return $this->db->transaction(function () use ($accountId, $amount, $description, $userId, $account, $metadata) {
            $txnId   = $this->generateUuid();
            $refNo   = $this->generateReferenceNumber();
            $now     = date('Y-m-d H:i:s');
            $balanceAfter = (float) $account['balance'] + $amount;
            $metaJson = !empty($metadata) ? json_encode($metadata) : null;

            $this->db->query(
                'INSERT INTO transactions
                    (id, tenant_id, account_id, reference_number, type, status, amount,
                     balance_after, processed_by, description, metadata, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $txnId,
                    $this->db->getTenantId(),
                    $accountId,
                    $refNo,
                    'deposit',
                    'completed',
                    $amount,
                    $balanceAfter,
                    $userId,
                    $description ?: 'Cash deposit',
                    $metaJson,
                    $now,
                ],
            );

            $this->accounts->updateBalance($accountId, $amount);

            return $this->findById($txnId);
        });
    }

    // ------------------------------------------------------------------
    // Withdrawal
    // ------------------------------------------------------------------

    /**
     * Withdraw funds from an account.
     */
    public function withdraw(string $accountId, float $amount, string $description = '', ?string $userId = null, array $metadata = []): array
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

        return $this->db->transaction(function () use ($accountId, $amount, $description, $userId, $account, $metadata) {
            $txnId = $this->generateUuid();
            $refNo = $this->generateReferenceNumber();
            $now   = date('Y-m-d H:i:s');
            $balanceAfter = (float) $account['balance'] - $amount;
            $metaJson = !empty($metadata) ? json_encode($metadata) : null;

            $this->db->query(
                'INSERT INTO transactions
                    (id, tenant_id, account_id, reference_number, type, status, amount,
                     balance_after, processed_by, description, metadata, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $txnId,
                    $this->db->getTenantId(),
                    $accountId,
                    $refNo,
                    'withdrawal',
                    'completed',
                    $amount,
                    $balanceAfter,
                    $userId,
                    $description ?: 'Cash withdrawal',
                    $metaJson,
                    $now,
                ],
            );

            $this->accounts->updateBalance($accountId, -$amount);

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
            $refNo = $this->generateReferenceNumber();
            $now   = date('Y-m-d H:i:s');
            $tenantId = $this->db->getTenantId();
            $fromBalanceAfter = (float) $fromAccount['balance'] - $amount;
            $toBalanceAfter   = (float) $toAccount['balance'] + $amount;

            // Outgoing side
            $txnId = $this->generateUuid();
            $relatedTxnId = $this->generateUuid();

            $this->db->query(
                'INSERT INTO transactions
                    (id, tenant_id, account_id, related_account_id, related_transaction_id,
                     reference_number, type, status, amount, balance_after,
                     processed_by, description, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $txnId, $tenantId, $fromAccountId, $toAccountId, $relatedTxnId,
                    $refNo, 'transfer', 'completed', $amount, $fromBalanceAfter,
                    $userId, $description ?: "Transfer to {$toAccount['account_number']}", $now,
                ],
            );

            // Incoming side
            $this->db->query(
                'INSERT INTO transactions
                    (id, tenant_id, account_id, related_account_id, related_transaction_id,
                     reference_number, type, status, amount, balance_after,
                     processed_by, description, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $relatedTxnId, $tenantId, $toAccountId, $fromAccountId, $txnId,
                    $refNo, 'transfer', 'completed', $amount, $toBalanceAfter,
                    $userId, $description ?: "Transfer from {$fromAccount['account_number']}", $now,
                ],
            );

            $this->accounts->updateBalance($fromAccountId, -$amount);
            $this->accounts->updateBalance($toAccountId, $amount);

            return $this->findById($txnId);
        });
    }

    // ------------------------------------------------------------------
    // Fee
    // ------------------------------------------------------------------

    /**
     * Charge a fee to an account.
     *
     * Debits the member's liability GL account and credits Fee Income.
     */
    public function chargeFee(string $accountId, float $amount, string $description = '', ?string $userId = null, array $metadata = []): array
    {
        if ($amount <= 0) {
            throw new RuntimeException('Fee amount must be positive.', 422);
        }

        $account = $this->accounts->findById($accountId);
        if ($account === null) {
            throw new RuntimeException('Account not found.', 404);
        }
        $this->assertAccountActive($account);

        return $this->db->transaction(function () use ($accountId, $amount, $description, $userId, $account, $metadata) {
            $txnId   = $this->generateUuid();
            $refNo   = $this->generateReferenceNumber();
            $now     = date('Y-m-d H:i:s');
            $balanceAfter = (float) $account['balance'] - $amount;
            $metaJson = !empty($metadata) ? json_encode($metadata) : null;

            $this->db->query(
                'INSERT INTO transactions
                    (id, tenant_id, account_id, reference_number, type, status, amount,
                     balance_after, processed_by, description, metadata, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $txnId,
                    $this->db->getTenantId(),
                    $accountId,
                    $refNo,
                    'fee',
                    'completed',
                    $amount,
                    $balanceAfter,
                    $userId,
                    $description ?: 'Service fee',
                    $metaJson,
                    $now,
                ],
            );

            $this->accounts->updateBalance($accountId, -$amount);

            return $this->findById($txnId);
        });
    }

    // ------------------------------------------------------------------
    // Adjustment
    // ------------------------------------------------------------------

    /**
     * Process a balance adjustment on an account.
     *
     * A positive amount credits the account (increase); a negative amount
     * debits it (decrease). The GL entries mirror the direction:
     *   - Credit adjustment: debit Operating Expense, credit member liability
     *   - Debit adjustment:  debit member liability, credit Operating Expense
     */
    public function processAdjustment(string $accountId, float $amount, string $description = '', ?string $userId = null, array $metadata = []): array
    {
        if ($amount == 0) {
            throw new RuntimeException('Adjustment amount must be non-zero.', 422);
        }

        $account = $this->accounts->findById($accountId);
        if ($account === null) {
            throw new RuntimeException('Account not found.', 404);
        }
        $this->assertAccountActive($account);

        $absAmount = abs($amount);

        return $this->db->transaction(function () use ($accountId, $amount, $absAmount, $description, $userId, $account, $metadata) {
            $txnId   = $this->generateUuid();
            $refNo   = $this->generateReferenceNumber();
            $now     = date('Y-m-d H:i:s');
            $balanceAfter = (float) $account['balance'] + $amount;
            $metaJson = !empty($metadata) ? json_encode($metadata) : null;

            $this->db->query(
                'INSERT INTO transactions
                    (id, tenant_id, account_id, reference_number, type, status, amount,
                     balance_after, processed_by, description, metadata, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $txnId,
                    $this->db->getTenantId(),
                    $accountId,
                    $refNo,
                    'adjustment',
                    'completed',
                    $amount,
                    $balanceAfter,
                    $userId,
                    $description ?: 'Account adjustment',
                    $metaJson,
                    $now,
                ],
            );

            $this->accounts->updateBalance($accountId, $amount);

            return $this->findById($txnId);
        });
    }

    // ------------------------------------------------------------------
    // Expense
    // ------------------------------------------------------------------

    /**
     * Record an operating expense transaction.
     *
     * Expense transactions are not tied to a member account. On first use
     * the method self-heals the schema by making account_id / balance_after
     * nullable and adding 'expense' to the type ENUM if needed.
     */
    public function recordExpense(
        string $category,
        float $amount,
        string $description = '',
        ?string $userId = null,
    ): array {
        if ($amount <= 0) {
            throw new RuntimeException('Expense amount must be positive.', 422);
        }

        $validCategories = [
            'rent', 'payroll', 'utilities', 'insurance', 'office_supplies',
            'technology', 'marketing', 'professional_services', 'maintenance',
            'travel', 'training', 'regulatory', 'depreciation', 'interest_paid', 'other',
        ];

        if (!in_array($category, $validCategories, true)) {
            throw new RuntimeException('Invalid expense category.', 422);
        }

        // Self-heal: ensure the schema supports expense transactions.
        $this->ensureExpenseSchema();

        $txnId    = $this->generateUuid();
        $refNo    = $this->generateReferenceNumber();
        $now      = date('Y-m-d H:i:s');
        $tenantId = $this->db->getTenantId();

        $metadata = json_encode(['category' => $category]);

        $this->db->query(
            'INSERT INTO transactions
                (id, tenant_id, reference_number, type, status, amount,
                 processed_by, description, metadata, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $txnId,
                $tenantId,
                $refNo,
                'expense',
                'completed',
                $amount,
                $userId,
                $description ?: ucfirst(str_replace('_', ' ', $category)) . ' expense',
                $metadata,
                $now,
            ],
        );

        return $this->findById($txnId);
    }

    /**
     * Ensure the transactions table supports expense records.
     *
     * Makes account_id and balance_after nullable and adds 'expense'
     * to the type ENUM when running against a legacy schema.
     */
    private function ensureExpenseSchema(): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }

        try {
            $this->db->query(
                "ALTER TABLE transactions
                     MODIFY COLUMN `account_id` CHAR(36) DEFAULT NULL,
                     MODIFY COLUMN `balance_after` DECIMAL(15,2) DEFAULT NULL,
                     MODIFY COLUMN `type` ENUM('deposit','withdrawal','transfer','payment','fee','interest','adjustment','loan_disbursement','loan_payment','expense') NOT NULL"
            );
        } catch (\Throwable $e) {
            // Schema already up-to-date or migration ran – safe to ignore.
            error_log('Expense schema migration note: ' . $e->getMessage());
        }

        $checked = true;
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
            "SELECT * FROM transactions
             WHERE tenant_id = ? AND account_id = ?
             ORDER BY created_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            [$tenantId, $accountId],
        );

        $total = (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM transactions
             WHERE tenant_id = ? AND account_id = ?',
            [$tenantId, $accountId],
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Get transactions processed by a user.
     */
    public function getByUser(string $userId, int $page = 1, int $perPage = 20): array
    {
        $offset = ($page - 1) * $perPage;

        $items = $this->db->selectScoped(
            'transactions',
            ['processed_by' => $userId],
            'created_at DESC',
            $perPage,
            $offset,
        );

        $total = $this->db->countScoped('transactions', ['processed_by' => $userId]);

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
