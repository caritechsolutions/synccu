<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use RuntimeException;

/**
 * Double-entry bookkeeping service.
 *
 * Every financial event creates a balanced pair of debit and credit
 * journal entries. This service ensures the fundamental accounting
 * equation (Assets = Liabilities + Equity) is maintained.
 */
final class LedgerService
{
    private Database $db;
    private bool $initialized = false;

    public function __construct()
    {
        $this->db = Database::getInstance();
        try {
            $this->ensureTables();
        } catch (\Throwable) {
        }
    }

    private function ensureInitialized(): void
    {
        if ($this->initialized) {
            return;
        }
        $this->initialized = true;
        $tenantId = $this->db->getTenantId();
        if ($tenantId !== null && $tenantId !== '') {
            try {
                $this->ensureDefaultAccounts();
            } catch (\Throwable) {
            }
        }
    }

    // ------------------------------------------------------------------
    // Journal Entries
    // ------------------------------------------------------------------

    /**
     * Create a balanced journal entry with paired debit/credit lines.
     *
     * @param string $transactionId  Reference to the parent transaction.
     * @param string $description    Human-readable description.
     * @param array  $lines          Array of line items:
     *   [
     *       ['account_code' => '1010', 'debit' => 100.00, 'credit' => 0.00],
     *       ['account_code' => '2010', 'debit' => 0.00,   'credit' => 100.00],
     *   ]
     */
    public function createEntry(string $transactionId, string $description, array $lines, ?string $tenantId = null): string
    {
        $this->ensureInitialized();
        $tenantId = $tenantId ?? $this->db->getTenantId();

        // Validate that debits equal credits
        $totalDebit  = 0.0;
        $totalCredit = 0.0;
        foreach ($lines as $line) {
            $totalDebit  += (float) ($line['debit'] ?? 0);
            $totalCredit += (float) ($line['credit'] ?? 0);
        }

        if (abs($totalDebit - $totalCredit) > 0.001) {
            throw new RuntimeException(
                sprintf('Journal entry is unbalanced: debits (%.2f) != credits (%.2f)', $totalDebit, $totalCredit)
            );
        }

        if ($totalDebit === 0.0) {
            throw new RuntimeException('Journal entry must have a non-zero amount.');
        }

        $entryId = $this->generateUuid();
        $now     = date('Y-m-d H:i:s');

        $this->db->query(
            'INSERT INTO journal_entries (id, tenant_id, transaction_id, description, total_amount, created_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$entryId, $tenantId, $transactionId, $description, $totalDebit, $now],
        );

        foreach ($lines as $line) {
            $lineId = $this->generateUuid();
            $this->db->query(
                'INSERT INTO journal_entry_lines
                    (id, tenant_id, journal_entry_id, gl_account_code, debit, credit, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $lineId,
                    $tenantId,
                    $entryId,
                    $line['account_code'],
                    (float) ($line['debit'] ?? 0),
                    (float) ($line['credit'] ?? 0),
                    $now,
                ],
            );

            // Update GL account running balance
            $netAmount = ($line['debit'] ?? 0) - ($line['credit'] ?? 0);
            $this->updateGlBalance($line['account_code'], (float) $netAmount, $tenantId);
        }

        return $entryId;
    }

    /**
     * Convenience: create a simple two-line debit/credit entry.
     */
    public function createDoubleEntry(
        string $transactionId,
        string $description,
        string $debitAccount,
        string $creditAccount,
        float  $amount,
        ?string $tenantId = null,
    ): string {
        return $this->createEntry($transactionId, $description, [
            ['account_code' => $debitAccount,  'debit' => $amount, 'credit' => 0.0],
            ['account_code' => $creditAccount, 'debit' => 0.0,    'credit' => $amount],
        ], $tenantId);
    }

    // ------------------------------------------------------------------
    // GL Account Operations
    // ------------------------------------------------------------------

    /**
     * Get or create a GL account.
     */
    public function getGlAccount(string $code, ?string $tenantId = null): ?array
    {
        $tenantId = $tenantId ?? $this->db->getTenantId();
        return $this->db->fetchOne(
            'SELECT * FROM gl_accounts WHERE code = ? AND tenant_id = ?',
            [$code, $tenantId],
        );
    }

    /**
     * Create a GL account.
     */
    public function createGlAccount(string $code, string $name, string $type, ?string $tenantId = null): array
    {
        $tenantId = $tenantId ?? $this->db->getTenantId();
        $id       = $this->generateUuid();
        $now      = date('Y-m-d H:i:s');

        $validTypes = ['asset', 'liability', 'equity', 'revenue', 'expense'];
        if (!in_array($type, $validTypes, true)) {
            throw new RuntimeException("Invalid GL account type: {$type}");
        }

        $this->db->query(
            'INSERT INTO gl_accounts (id, tenant_id, code, name, type, balance, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 0.00, ?, ?)',
            [$id, $tenantId, $code, $name, $type, $now, $now],
        );

        return $this->getGlAccount($code, $tenantId);
    }

    /**
     * Update the running balance of a GL account.
     */
    private function updateGlBalance(string $code, float $netDebit, string $tenantId): void
    {
        // For asset and expense accounts, debits increase the balance.
        // For liability, equity, and revenue accounts, credits increase the balance.
        // The net effect stored is always from the debit perspective;
        // reporting inverts for liability/equity/revenue accounts.
        $this->db->execute(
            'UPDATE gl_accounts SET balance = balance + ?, updated_at = NOW()
             WHERE code = ? AND tenant_id = ?',
            [$netDebit, $code, $tenantId],
        );
    }

    // ------------------------------------------------------------------
    // Trial Balance
    // ------------------------------------------------------------------

    /**
     * Generate a trial balance report.
     *
     * Returns all GL accounts with their debit and credit totals.
     * A balanced ledger will have total debits == total credits.
     */
    public function trialBalance(?string $tenantId = null): array
    {
        $this->ensureInitialized();
        $tenantId = $tenantId ?? $this->db->getTenantId();

        $accounts = $this->db->fetchAll(
            'SELECT gl.code, gl.name, gl.type, gl.balance,
                    COALESCE(SUM(jel.debit), 0)  AS total_debits,
                    COALESCE(SUM(jel.credit), 0) AS total_credits
             FROM gl_accounts gl
             LEFT JOIN journal_entry_lines jel ON jel.gl_account_code = gl.code AND jel.tenant_id = gl.tenant_id
             WHERE gl.tenant_id = ?
             GROUP BY gl.code, gl.name, gl.type, gl.balance
             ORDER BY gl.code',
            [$tenantId],
        );

        $totalDebits  = 0.0;
        $totalCredits = 0.0;

        foreach ($accounts as &$acct) {
            $acct['total_debits']  = (float) $acct['total_debits'];
            $acct['total_credits'] = (float) $acct['total_credits'];
            $totalDebits  += $acct['total_debits'];
            $totalCredits += $acct['total_credits'];
        }
        unset($acct);

        return [
            'accounts'      => $accounts,
            'total_debits'  => round($totalDebits, 2),
            'total_credits' => round($totalCredits, 2),
            'balanced'      => abs($totalDebits - $totalCredits) < 0.01,
        ];
    }

    /**
     * Verify that the ledger is balanced.
     */
    public function isBalanced(?string $tenantId = null): bool
    {
        $tb = $this->trialBalance($tenantId);
        return $tb['balanced'];
    }

    // ------------------------------------------------------------------
    // Journal Entry Retrieval
    // ------------------------------------------------------------------

    /**
     * Get journal entries for a specific transaction.
     */
    public function getEntriesByTransaction(string $transactionId): array
    {
        $entries = $this->db->selectScoped('journal_entries', ['transaction_id' => $transactionId], 'created_at ASC');

        foreach ($entries as &$entry) {
            $entry['lines'] = $this->db->selectScoped(
                'journal_entry_lines',
                ['journal_entry_id' => $entry['id']],
                'debit DESC',
            );
        }
        unset($entry);

        return $entries;
    }

    /**
     * Get all journal entries within a date range.
     */
    public function getEntries(string $from, string $to, int $page = 1, int $perPage = 50): array
    {
        $tenantId = $this->db->getTenantId();
        $offset   = ($page - 1) * $perPage;

        $entries = $this->db->fetchAll(
            'SELECT * FROM journal_entries
             WHERE tenant_id = ? AND created_at BETWEEN ? AND ?
             ORDER BY created_at DESC
             LIMIT ? OFFSET ?',
            [$tenantId, $from, $to, $perPage, $offset],
        );

        $total = (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM journal_entries
             WHERE tenant_id = ? AND created_at BETWEEN ? AND ?',
            [$tenantId, $from, $to],
        );

        return ['items' => $entries, 'total' => $total];
    }

    // ------------------------------------------------------------------
    // GL Account Code Constants (chart of accounts defaults)
    // ------------------------------------------------------------------

    /**
     * Standard GL account codes used by the platform.
     */
    public const GL_MEMBER_DEPOSITS      = '1010'; // Asset: Cash from deposits
    public const GL_LOAN_RECEIVABLE      = '1200'; // Asset: Outstanding loans
    public const GL_MEMBER_SHARES        = '2010'; // Liability: Member share accounts
    public const GL_MEMBER_SAVINGS       = '2020'; // Liability: Member savings
    public const GL_MEMBER_CHECKING      = '2030'; // Liability: Member checking
    public const GL_LOAN_INTEREST_INCOME = '4010'; // Revenue: Interest on loans
    public const GL_FEE_INCOME           = '4020'; // Revenue: Service fees
    public const GL_INTEREST_EXPENSE     = '5010'; // Expense: Interest paid to members
    public const GL_OPERATING_EXPENSE    = '5020'; // Expense: Operating costs

    // ------------------------------------------------------------------
    // Self-healing Schema Helpers
    // ------------------------------------------------------------------

    /**
     * Create the GL tables if they don't already exist.
     */
    private function ensureTables(): void
    {
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `gl_accounts` (
                `id` CHAR(36) NOT NULL PRIMARY KEY,
                `tenant_id` CHAR(36) NOT NULL,
                `code` VARCHAR(10) NOT NULL,
                `name` VARCHAR(100) NOT NULL,
                `type` ENUM('asset','liability','equity','revenue','expense') NOT NULL,
                `balance` DECIMAL(15,2) DEFAULT 0.00,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uq_gl_tenant_code` (`tenant_id`, `code`),
                INDEX `idx_gl_type` (`type`),
                FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            [],
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `journal_entries` (
                `id` CHAR(36) NOT NULL PRIMARY KEY,
                `tenant_id` CHAR(36) NOT NULL,
                `transaction_id` CHAR(36) DEFAULT NULL,
                `description` VARCHAR(500) DEFAULT NULL,
                `total_amount` DECIMAL(15,2) NOT NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_je_tenant` (`tenant_id`),
                INDEX `idx_je_transaction` (`transaction_id`),
                INDEX `idx_je_created` (`created_at`),
                FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            [],
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `journal_entry_lines` (
                `id` CHAR(36) NOT NULL PRIMARY KEY,
                `tenant_id` CHAR(36) NOT NULL,
                `journal_entry_id` CHAR(36) NOT NULL,
                `gl_account_code` VARCHAR(10) NOT NULL,
                `debit` DECIMAL(15,2) DEFAULT 0.00,
                `credit` DECIMAL(15,2) DEFAULT 0.00,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_jel_entry` (`journal_entry_id`),
                INDEX `idx_jel_gl` (`gl_account_code`),
                INDEX `idx_jel_tenant` (`tenant_id`),
                FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
                FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            [],
        );
    }

    /**
     * Seed the standard chart of accounts for the current tenant if missing.
     */
    private function ensureDefaultAccounts(): void
    {
        $tenantId = $this->db->getTenantId();
        if ($tenantId === null || $tenantId === '') {
            return;
        }

        $defaults = [
            [self::GL_MEMBER_DEPOSITS,      'Member Deposits',      'asset'],
            [self::GL_LOAN_RECEIVABLE,      'Loan Receivable',      'asset'],
            [self::GL_MEMBER_SHARES,        'Member Shares',        'liability'],
            [self::GL_MEMBER_SAVINGS,       'Member Savings',       'liability'],
            [self::GL_MEMBER_CHECKING,      'Member Checking',      'liability'],
            [self::GL_LOAN_INTEREST_INCOME, 'Loan Interest Income', 'revenue'],
            [self::GL_FEE_INCOME,           'Fee Income',           'revenue'],
            [self::GL_INTEREST_EXPENSE,     'Interest Expense',     'expense'],
            [self::GL_OPERATING_EXPENSE,    'Operating Expense',    'expense'],
        ];

        $now = date('Y-m-d H:i:s');

        foreach ($defaults as [$code, $name, $type]) {
            $id = $this->generateUuid();
            try {
                $this->db->query(
                    'INSERT IGNORE INTO gl_accounts (id, tenant_id, code, name, type, balance, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, 0.00, ?, ?)',
                    [$id, $tenantId, $code, $name, $type, $now, $now],
                );
            } catch (\Throwable) {
                // Already exists — ignore.
            }
        }
    }

    // ------------------------------------------------------------------

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
