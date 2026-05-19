<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use RuntimeException;

/**
 * Loan management service.
 *
 * Handles loan applications, approval/denial, amortisation schedule
 * calculation, payment processing, and delinquency checking.
 */
final class LoanService
{
    private Database $db;
    private AccountService $accounts;

    public function __construct()
    {
        $this->db       = Database::getInstance();
        $this->accounts = new AccountService();
        $this->ensureLateFeeCols();
        $this->ensureLoanTransactionSchema();
    }

    // ------------------------------------------------------------------
    // Application
    // ------------------------------------------------------------------

    /**
     * Create a loan application.
     */
    public function apply(array $data, string $tenantId, string $userId): array
    {
        $loanId = $this->generateUuid();
        $now    = date('Y-m-d H:i:s');
        $amount = (float) $data['amount'];
        $rate   = (float) $data['interest_rate'];
        $term   = (int) $data['term_months'];
        $monthlyPayment = $this->calculateMonthlyPayment($amount, $rate, $term);
        $loanNumber = 'LN-' . date('Y') . '-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);

        // Link to user's first active account if available; loan account created at approval
        $userAccount = $this->db->fetchOne(
            'SELECT id FROM accounts WHERE user_id = ? AND tenant_id = ? AND status = ? AND account_type != ? LIMIT 1',
            [$userId, $tenantId, 'active', 'loan'],
        );
        $accountId = $userAccount ? $userAccount['id'] : null;

        $this->db->query(
            'INSERT INTO loans
                (id, tenant_id, user_id, account_id, loan_number, loan_type,
                 principal_amount, interest_rate, term_months, monthly_payment,
                 outstanding_balance, status, purpose, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $loanId,
                $tenantId,
                $userId,
                $accountId,
                $loanNumber,
                $data['loan_type'],
                $amount,
                $rate,
                $term,
                $monthlyPayment,
                $amount,
                'application',
                $data['purpose'] ?? null,
                $now,
                $now,
            ],
        );

        return $this->findById($loanId);
    }

    // ------------------------------------------------------------------
    // Approval / Denial
    // ------------------------------------------------------------------

    /**
     * Approve a loan application.
     *
     * Creates a dedicated loan account (type='loan') with balance = principal,
     * records the disbursement transaction, and generates the amortisation schedule.
     */
    public function approve(string $loanId, string $approvedBy): array
    {
        $loan = $this->findById($loanId);
        if ($loan === null) {
            throw new RuntimeException('Loan not found.', 404);
        }

        if (!in_array($loan['status'], ['application', 'pending'], true)) {
            throw new RuntimeException('Only pending/application loans can be approved.', 422);
        }

        return $this->db->transaction(function () use ($loanId, $loan, $approvedBy) {
            $now = date('Y-m-d H:i:s');
            $principal = (float) $loan['principal_amount'];

            $monthlyPayment = $this->calculateMonthlyPayment(
                $principal,
                (float) $loan['interest_rate'],
                (int) $loan['term_months'],
            );

            // Create a loan tracking account (ledger only — no funds held)
            $loanAccount = $this->accounts->createLoanAccount([
                'user_id'       => $loan['user_id'],
                'name'          => ucfirst($loan['loan_type']) . ' Loan - ' . $loan['loan_number'],
                'interest_rate' => $loan['interest_rate'],
            ], $loan['tenant_id'], $principal);

            $this->db->updateScoped('loans', [
                'status'              => 'approved',
                'account_id'          => $loanAccount['id'],
                'approved_by'         => $approvedBy,
                'approved_at'         => $now,
                'monthly_payment'     => $monthlyPayment,
                'outstanding_balance' => $principal,
                'updated_at'          => $now,
            ], ['id' => $loanId]);

            $this->generateSchedule($loanId, $loan);

            return $this->findById($loanId);
        });
    }

    /**
     * Disburse an approved loan.
     *
     * Moves funds to the member's chosen destination (account, cash, check, etc.)
     * and activates the loan.
     */
    public function disburse(string $loanId, string $disbursedBy, array $options): array
    {
        $loan = $this->findById($loanId);
        if ($loan === null) {
            throw new RuntimeException('Loan not found.', 404);
        }

        if ($loan['status'] !== 'approved') {
            throw new RuntimeException('Only approved loans can be disbursed.', 422);
        }

        $method = $options['disbursement_method'] ?? 'cash';
        $targetAccountId = $options['target_account_id'] ?? null;

        return $this->db->transaction(function () use ($loanId, $loan, $disbursedBy, $method, $targetAccountId, $options) {
            $now = date('Y-m-d H:i:s');
            $principal = (float) $loan['principal_amount'];
            $termMonths = (int) $loan['term_months'];

            // If disbursing to a member account, credit that account
            if ($method === 'account' && $targetAccountId) {
                $targetAccount = $this->accounts->findById($targetAccountId);
                if (!$targetAccount) {
                    throw new RuntimeException('Target account not found.', 404);
                }
                if ($targetAccount['account_type'] === 'loan') {
                    throw new RuntimeException('Cannot disburse to a loan account.', 422);
                }
                $this->accounts->updateBalance($targetAccountId, $principal);

                // Record deposit into member's account
                $depositTxnId = $this->generateUuid();
                $newBalance = (float) $targetAccount['balance'] + $principal;
                $this->db->query(
                    'INSERT INTO transactions
                        (id, tenant_id, reference_number, type, status, amount,
                         account_id, balance_after, processed_by, description, metadata, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $depositTxnId,
                        $loan['tenant_id'],
                        'LD-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4))),
                        'deposit',
                        'completed',
                        $principal,
                        $targetAccountId,
                        $newBalance,
                        $disbursedBy,
                        "Loan disbursement - {$loan['loan_type']} #{$loan['loan_number']}",
                        json_encode(['source' => 'loan_disbursement', 'loan_id' => $loanId]),
                        $now,
                    ],
                );
            }

            // Record disbursement on the loan tracking account
            $txnId = $this->generateUuid();
            $this->db->query(
                'INSERT INTO transactions
                    (id, tenant_id, reference_number, type, status, amount,
                     account_id, balance_after, processed_by, description, metadata, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $txnId,
                    $loan['tenant_id'],
                    'LN-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4))),
                    'loan_disbursement',
                    'completed',
                    $principal,
                    $loan['account_id'],
                    $principal,
                    $disbursedBy,
                    "Loan disbursement - {$loan['loan_type']} #{$loan['loan_number']}",
                    json_encode([
                        'disbursement_method' => $method,
                        'target_account_id'   => $targetAccountId,
                        'check_number'        => $options['check_number'] ?? null,
                    ]),
                    $now,
                ],
            );

            // Activate the loan
            $this->db->updateScoped('loans', [
                'status'            => 'active',
                'disbursed_at'      => $now,
                'disbursed_amount'  => $principal,
                'next_payment_date' => date('Y-m-d', strtotime('+1 month')),
                'maturity_date'     => date('Y-m-d', strtotime("+{$termMonths} months")),
                'updated_at'        => $now,
            ], ['id' => $loanId]);

            return $this->findById($loanId);
        });
    }

    /**
     * Deny a loan application.
     */
    public function deny(string $loanId, string $deniedBy, string $reason = ''): array
    {
        $loan = $this->findById($loanId);
        if ($loan === null) {
            throw new RuntimeException('Loan not found.', 404);
        }

        if (!in_array($loan['status'], ['application', 'pending'], true)) {
            throw new RuntimeException('Only pending/application loans can be denied.', 422);
        }

        $this->db->updateScoped('loans', [
            'status'     => 'cancelled',
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $loanId]);

        return $this->findById($loanId);
    }

    // ------------------------------------------------------------------
    // Payments
    // ------------------------------------------------------------------

    /**
     * Process a loan payment.
     *
     * Interest is calculated daily at payment time:
     *   accrued = outstanding_balance * (annual_rate / 100 / 365) * days_since_last_activity
     *
     * Payment is applied interest-first, remainder to principal.
     * Both the loan record and the loan account balance are updated.
     */
    public function makePayment(string $loanId, float $amount, ?string $fromAccountId = null, ?string $userId = null): array
    {
        if ($amount <= 0) {
            throw new RuntimeException('Payment amount must be positive.', 422);
        }

        $loan = $this->findById($loanId);
        if ($loan === null) {
            throw new RuntimeException('Loan not found.', 404);
        }

        if (!in_array($loan['status'], ['approved', 'active', 'delinquent'], true)) {
            throw new RuntimeException('Loan is not in a payable state.', 422);
        }

        return $this->db->transaction(function () use ($loanId, $loan, $amount, $fromAccountId, $userId) {
            $now = date('Y-m-d H:i:s');
            $outstandingBalance = (float) $loan['outstanding_balance'];
            $annualRate = (float) $loan['interest_rate'];

            // Calculate accrued interest based on days since last activity
            $lastActivityDate = $this->getLastActivityDate($loanId, $loan);
            $lastDate = new \DateTimeImmutable($lastActivityDate);
            $today = new \DateTimeImmutable('today');
            $daysSince = max(0, (int) $today->diff($lastDate)->days);

            $dailyRate = $annualRate / 100 / 365;
            $accruedInterest = round($outstandingBalance * $dailyRate * $daysSince, 2);

            // Total owed = principal balance + accrued interest
            $totalOwed = $outstandingBalance + $accruedInterest;

            // Cap payment at total owed
            if ($amount > $totalOwed) {
                $amount = $totalOwed;
            }

            // Split: interest first, remainder to principal
            $interestPortion = min($accruedInterest, $amount);
            $principalPortion = $amount - $interestPortion;
            $newBalance = round($outstandingBalance - $principalPortion, 2);

            // Debit source account if specified
            if ($fromAccountId !== null) {
                $this->accounts->updateBalance($fromAccountId, -$amount);
            }

            // Update loan account balance: subtract principal portion
            if ($loan['account_id']) {
                $this->accounts->updateBalance($loan['account_id'], -$principalPortion);
            }

            // Update loan record
            $updates = [
                'outstanding_balance' => max(0, $newBalance),
                'total_paid'          => (float) ($loan['total_paid'] ?? 0) + $amount,
                'next_payment_date'   => $newBalance > 0.01 ? date('Y-m-d', strtotime('+1 month')) : null,
                'updated_at'          => $now,
            ];

            if ($newBalance <= 0.01) {
                $updates['status'] = 'paid_off';
                $updates['outstanding_balance'] = 0;
                // Close loan account
                if ($loan['account_id']) {
                    $this->db->execute(
                        'UPDATE accounts SET balance = 0, available_balance = 0, status = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?',
                        ['closed', $loan['account_id'], $loan['tenant_id']],
                    );
                }
            } else {
                $updates['status'] = 'active';
            }

            $this->db->updateScoped('loans', $updates, ['id' => $loanId]);

            // Record transaction linked to the loan account
            $txnId = $this->generateUuid();
            $balanceAfter = max(0, $newBalance);
            $this->db->query(
                'INSERT INTO transactions
                    (id, tenant_id, reference_number, type, status, amount,
                     account_id, balance_after, processed_by, description, metadata, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $txnId,
                    $loan['tenant_id'],
                    'LP-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4))),
                    'loan_payment',
                    'completed',
                    $amount,
                    $loan['account_id'],
                    $balanceAfter,
                    $userId,
                    "Loan payment - {$loan['loan_number']}",
                    json_encode([
                        'principal'    => $principalPortion,
                        'interest'     => $interestPortion,
                        'loan_id'      => $loanId,
                        'days_accrued' => $daysSince,
                        'daily_rate'   => $dailyRate,
                    ]),
                    $now,
                ],
            );

            return [
                'payment_id'   => $txnId,
                'amount'       => $amount,
                'principal'    => $principalPortion,
                'interest'     => $interestPortion,
                'days_accrued' => $daysSince,
                'new_balance'  => max(0, $newBalance),
                'loan'         => $this->findById($loanId),
            ];
        });
    }

    // ------------------------------------------------------------------
    // Amortisation Schedule
    // ------------------------------------------------------------------

    /**
     * Calculate the amortisation schedule for a loan.
     */
    public function calculateSchedule(float $principal, float $annualRate, int $termMonths): array
    {
        $monthlyRate = $annualRate / 100 / 12;
        $payment     = $this->calculateMonthlyPayment($principal, $annualRate, $termMonths);
        $balance     = $principal;
        $schedule    = [];

        for ($month = 1; $month <= $termMonths; $month++) {
            $interest       = round($balance * $monthlyRate, 2);
            $principalPart  = round($payment - $interest, 2);

            // Last payment adjustment for rounding
            if ($month === $termMonths) {
                $principalPart = round($balance, 2);
                $payment       = $principalPart + $interest;
            }

            $balance -= $principalPart;

            $schedule[] = [
                'payment_number'    => $month,
                'due_date'          => date('Y-m-d', strtotime("+{$month} months")),
                'payment_amount'    => round($payment, 2),
                'principal'         => $principalPart,
                'interest'          => $interest,
                'remaining_balance' => round(max(0, $balance), 2),
                'status'            => 'upcoming',
            ];
        }

        return $schedule;
    }

    /**
     * Get the stored amortisation schedule for an approved loan.
     */
    public function getSchedule(string $loanId): array
    {
        $loan = $this->findById($loanId);
        $principal = $loan ? (float) $loan['principal_amount'] : 0;

        $rows = $this->db->fetchAll(
            'SELECT payment_number, due_date, principal_amount, interest_amount,
                    total_amount, paid_amount, status, paid_at
             FROM loan_schedules WHERE loan_id = ? ORDER BY payment_number ASC',
            [$loanId],
        );

        $balance = $principal;
        $schedule = [];
        foreach ($rows as $row) {
            $balance -= (float) $row['principal_amount'];
            $schedule[] = [
                'payment_number'    => (int) $row['payment_number'],
                'due_date'          => $row['due_date'],
                'payment_amount'    => (float) $row['total_amount'],
                'principal'         => (float) $row['principal_amount'],
                'interest'          => (float) $row['interest_amount'],
                'remaining_balance' => round(max(0, $balance), 2),
                'paid_amount'       => (float) $row['paid_amount'],
                'status'            => $row['status'] ?? 'upcoming',
                'paid_at'           => $row['paid_at'],
            ];
        }

        return $schedule;
    }

    // ------------------------------------------------------------------
    // Delinquency
    // ------------------------------------------------------------------

    /**
     * Check if a loan is delinquent (payment overdue).
     */
    public function checkDelinquency(string $loanId): array
    {
        $loan = $this->findById($loanId);
        if ($loan === null) {
            throw new RuntimeException('Loan not found.', 404);
        }

        if (!in_array($loan['status'], ['approved', 'active'], true)) {
            return ['delinquent' => false, 'days_past_due' => 0];
        }

        $nextPaymentDate = $loan['next_payment_date'] ?? null;
        if ($nextPaymentDate === null) {
            return ['delinquent' => false, 'days_past_due' => 0];
        }

        $today       = new \DateTimeImmutable('today');
        $paymentDate = new \DateTimeImmutable($nextPaymentDate);
        $diff        = $today->diff($paymentDate);
        $daysPastDue = $paymentDate < $today ? $diff->days : 0;

        $delinquent = $daysPastDue > 0;

        // Update loan status if delinquent
        if ($delinquent && $loan['status'] !== 'delinquent') {
            $this->db->updateScoped('loans', [
                'status'     => 'delinquent',
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['id' => $loanId]);
        }

        return [
            'delinquent'    => $delinquent,
            'days_past_due' => $daysPastDue,
            'category'      => $this->delinquencyCategory($daysPastDue),
        ];
    }

    /**
     * Charge a late fee on an overdue loan.
     *
     * The fee amount is computed from the loan's late_fee_rate applied to the
     * outstanding monthly payment. A transaction is recorded and GL entries
     * are posted: debit Member Deposits (1010), credit Late Fee Income (4030).
     */
    public function chargeLateFee(string $loanId, ?float $overrideAmount = null): array
    {
        $loan = $this->findById($loanId);
        if ($loan === null) {
            throw new RuntimeException('Loan not found.', 404);
        }

        if (!in_array($loan['status'], ['approved', 'active', 'delinquent'], true)) {
            throw new RuntimeException('Late fees can only be charged on active or delinquent loans.', 422);
        }

        $feeAmount = $overrideAmount
            ?? round((float) ($loan['monthly_payment'] ?? 0) * ((float) ($loan['late_fee_rate'] ?? 5) / 100), 2);

        if ($feeAmount <= 0) {
            throw new RuntimeException('Calculated late fee amount must be positive.', 422);
        }

        return $this->db->transaction(function () use ($loanId, $loan, $feeAmount) {
            $txnId = $this->generateUuid();
            $now   = date('Y-m-d H:i:s');

            $this->db->query(
                'INSERT INTO transactions
                    (id, tenant_id, reference_number, type, status, amount,
                     processed_by, description, metadata, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $txnId,
                    $loan['tenant_id'],
                    'LF-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4))),
                    'late_fee',
                    'completed',
                    $feeAmount,
                    $loan['user_id'],
                    "Late fee on loan {$loan['loan_number']}",
                    json_encode(['category' => 'late_fee', 'loan_id' => $loanId]),
                    $now,
                ],
            );

            // Update outstanding balance to include the late fee
            $this->db->updateScoped('loans', [
                'outstanding_balance' => (float) $loan['outstanding_balance'] + $feeAmount,
                'updated_at'          => $now,
            ], ['id' => $loanId]);

            return [
                'transaction_id' => $txnId,
                'fee_amount'     => $feeAmount,
                'loan'           => $this->findById($loanId),
            ];
        });
    }

    /**
     * Get all delinquent loans for the current tenant.
     */
    public function getDelinquentLoans(): array
    {
        $tenantId = $this->db->getTenantId();

        return $this->db->fetchAll(
            'SELECT * FROM loans
             WHERE tenant_id = ? AND status IN (?, ?) AND next_payment_date < CURDATE()
             ORDER BY next_payment_date ASC',
            [$tenantId, 'approved', 'active'],
        );
    }

    // ------------------------------------------------------------------
    // Read
    // ------------------------------------------------------------------

    public function findById(string $loanId): ?array
    {
        return $this->db->findScoped('loans', ['id' => $loanId]);
    }

    /**
     * Calculate accrued interest since the last payment (or disbursement).
     */
    public function getAccruedInterest(string $loanId): array
    {
        $loan = $this->findById($loanId);
        if ($loan === null || !in_array($loan['status'], ['active', 'delinquent', 'approved'], true)) {
            return ['accrued_interest' => 0, 'days_since_last_payment' => 0, 'daily_rate' => 0, 'as_of' => date('Y-m-d')];
        }

        $outstanding = (float) $loan['outstanding_balance'];
        $annualRate = (float) $loan['interest_rate'];
        $dailyRate = $annualRate / 100 / 365;

        $lastActivityDate = $this->getLastActivityDate($loanId, $loan);
        $lastDate = new \DateTimeImmutable($lastActivityDate);
        $today = new \DateTimeImmutable('today');
        $daysSince = max(0, (int) $today->diff($lastDate)->days);

        $accrued = round($outstanding * $dailyRate * $daysSince, 2);

        return [
            'accrued_interest'        => $accrued,
            'days_since_last_payment' => $daysSince,
            'daily_rate'              => round($dailyRate, 8),
            'per_diem'                => round($outstanding * $dailyRate, 2),
            'last_activity_date'      => $lastActivityDate,
            'as_of'                   => date('Y-m-d'),
        ];
    }

    public function getByUser(string $userId, int $page = 1, int $perPage = 20): array
    {
        $offset = ($page - 1) * $perPage;
        $items  = $this->db->selectScoped('loans', ['user_id' => $userId], 'created_at DESC', $perPage, $offset);
        $total  = $this->db->countScoped('loans', ['user_id' => $userId]);
        return ['items' => $items, 'total' => $total];
    }

    public function getAll(int $page = 1, int $perPage = 20, ?string $status = null): array
    {
        $conditions = [];
        if ($status !== null) {
            $conditions['status'] = $status;
        }
        $offset = ($page - 1) * $perPage;
        $items  = $this->db->selectScoped('loans', $conditions, 'created_at DESC', $perPage, $offset);
        $total  = $this->db->countScoped('loans', $conditions);
        return ['items' => $items, 'total' => $total];
    }

    // ------------------------------------------------------------------
    // Payment History & Payoff
    // ------------------------------------------------------------------

    /**
     * Get payment history for a loan.
     */
    public function getPaymentHistory(string $loanId): array
    {
        $loan = $this->findById($loanId);
        if ($loan === null || !$loan['account_id']) {
            return [];
        }

        $tenantId = $this->db->getTenantId();

        return $this->db->fetchAll(
            "SELECT t.*, CONCAT(u.first_name, ' ', u.last_name) AS processed_by_name
             FROM transactions t
             LEFT JOIN users u ON u.id = t.processed_by AND u.tenant_id = t.tenant_id
             WHERE t.tenant_id = ? AND t.account_id = ? AND t.type IN ('loan_payment', 'loan_disbursement')
             ORDER BY t.created_at DESC",
            [$tenantId, $loan['account_id']],
        );
    }

    /**
     * Generate a payoff quote for a loan.
     */
    public function getPayoffQuote(string $loanId): array
    {
        $loan = $this->findById($loanId);
        if ($loan === null) {
            throw new RuntimeException('Loan not found.', 404);
        }

        if (!in_array($loan['status'], ['approved', 'active', 'delinquent'], true)) {
            throw new RuntimeException('Payoff quote is only available for active loans.', 422);
        }

        $outstandingBalance = (float) $loan['outstanding_balance'];
        $annualRate         = (float) $loan['interest_rate'];
        $dailyRate          = $annualRate / 100 / 365;

        $lastPaymentDate = $this->getLastActivityDate($loanId, $loan);
        $lastDate  = new \DateTimeImmutable($lastPaymentDate);
        $today     = new \DateTimeImmutable('today');
        $daysSince = max(0, (int) $today->diff($lastDate)->days);

        $accruedInterest = round($outstandingBalance * $dailyRate * $daysSince, 2);
        $dailyInterest   = round($outstandingBalance * $dailyRate, 2);
        $payoffAmount    = round($outstandingBalance + $accruedInterest, 2);

        return [
            'outstanding_balance' => $outstandingBalance,
            'accrued_interest'    => $accruedInterest,
            'payoff_amount'       => $payoffAmount,
            'good_through'        => $today->modify('+10 days')->format('Y-m-d'),
            'daily_interest'      => $dailyInterest,
        ];
    }

    /**
     * Update loan details (collateral, metadata).
     */
    public function updateLoan(string $loanId, array $data): array
    {
        $loan = $this->findById($loanId);
        if ($loan === null) {
            throw new RuntimeException('Loan not found.', 404);
        }

        $updates = ['updated_at' => date('Y-m-d H:i:s')];

        if (array_key_exists('collateral', $data)) {
            $updates['collateral'] = $data['collateral'] !== null ? json_encode($data['collateral']) : null;
        }

        if (array_key_exists('metadata', $data)) {
            $updates['metadata'] = $data['metadata'] !== null ? json_encode($data['metadata']) : null;
        }

        if (array_key_exists('purpose', $data)) {
            $updates['purpose'] = $data['purpose'];
        }

        if (array_key_exists('co_signer_id', $data)) {
            $updates['co_signer_id'] = $data['co_signer_id'];
        }

        $this->db->updateScoped('loans', $updates, ['id' => $loanId]);

        return $this->findById($loanId);
    }

    // ------------------------------------------------------------------
    // Schema Migration Helpers
    // ------------------------------------------------------------------

    /**
     * Ensure the transactions table supports loan_disbursement, loan_payment, and late_fee types.
     */
    private function ensureLoanTransactionSchema(): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        try {
            $this->db->query(
                "ALTER TABLE transactions
                 MODIFY COLUMN `type` ENUM('deposit','withdrawal','transfer','payment','fee','late_fee','interest','adjustment','loan_disbursement','loan_payment','expense') NOT NULL,
                 MODIFY COLUMN `account_id` CHAR(36) DEFAULT NULL,
                 MODIFY COLUMN `balance_after` DECIMAL(15,2) DEFAULT NULL"
            );
        } catch (\Throwable $e) {
            error_log('Loan transaction schema migration note: ' . $e->getMessage());
        }
        $checked = true;
    }

    /**
     * Ensure late-fee and extended columns exist on loans / loan_schedules.
     */
    private function ensureLateFeeCols(): void
    {
        $alterations = [
            'ALTER TABLE `loans` ADD COLUMN `grace_period_days` INT DEFAULT 15',
            'ALTER TABLE `loans` ADD COLUMN `late_fee_rate` DECIMAL(5,2) DEFAULT 5.00',
            'ALTER TABLE `loans` ADD COLUMN `purpose` TEXT DEFAULT NULL',
            'ALTER TABLE `loans` ADD COLUMN `denial_reason` TEXT DEFAULT NULL',
            'ALTER TABLE `loans` ADD COLUMN `co_signer_id` CHAR(36) DEFAULT NULL',
            'ALTER TABLE `loan_schedules` ADD COLUMN `late_fee` DECIMAL(15,2) DEFAULT 0.00',
        ];

        foreach ($alterations as $sql) {
            try {
                $this->db->query($sql, []);
            } catch (\Throwable) {
                // Column already exists — ignore.
            }
        }
    }

    // ------------------------------------------------------------------
    // Internal Helpers
    // ------------------------------------------------------------------

    /**
     * Calculate monthly payment using the standard amortisation formula.
     */
    private function calculateMonthlyPayment(float $principal, float $annualRate, int $termMonths): float
    {
        if ($annualRate <= 0) {
            return round($principal / $termMonths, 2);
        }

        $r = $annualRate / 100 / 12;
        $n = $termMonths;

        // M = P * [r(1+r)^n] / [(1+r)^n - 1]
        $payment = $principal * ($r * pow(1 + $r, $n)) / (pow(1 + $r, $n) - 1);

        return round($payment, 2);
    }

    /**
     * Find the date of the last payment or disbursement for a loan.
     */
    private function getLastActivityDate(string $loanId, array $loan): string
    {
        if ($loan['account_id']) {
            $tenantId = $this->db->getTenantId();
            $lastPayment = $this->db->fetchOne(
                "SELECT MAX(t.created_at) AS last_date
                 FROM transactions t
                 WHERE t.tenant_id = ? AND t.account_id = ? AND t.type IN ('loan_payment', 'loan_disbursement')",
                [$tenantId, $loan['account_id']],
            );
            if ($lastPayment && $lastPayment['last_date']) {
                return $lastPayment['last_date'];
            }
        }

        return $loan['disbursed_at'] ?? $loan['approved_at'] ?? $loan['created_at'];
    }

    /**
     * Calculate the interest portion of a payment (daily accrual, 30-day estimate).
     */
    private function calculateInterestPortion(array $loan, float $paymentAmount): float
    {
        $dailyRate = (float) $loan['interest_rate'] / 100 / 365;
        $interest  = (float) $loan['outstanding_balance'] * $dailyRate * 30;

        return round(min($interest, $paymentAmount), 2);
    }

    /**
     * Persist the amortisation schedule to the database.
     */
    private function generateSchedule(string $loanId, array $loan): void
    {
        $schedule = $this->calculateSchedule(
            (float) $loan['principal_amount'],
            (float) $loan['interest_rate'],
            (int) $loan['term_months'],
        );

        foreach ($schedule as $row) {
            $this->db->query(
                'INSERT INTO loan_schedules
                    (id, loan_id, payment_number, due_date, principal_amount, interest_amount, total_amount, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
                [
                    $this->generateUuid(),
                    $loanId,
                    $row['payment_number'],
                    $row['due_date'],
                    $row['principal'],
                    $row['interest'],
                    $row['payment_amount'],
                ],
            );
        }
    }

    private function delinquencyCategory(int $daysPastDue): string
    {
        return match (true) {
            $daysPastDue === 0       => 'current',
            $daysPastDue <= 30       => '1-30 days',
            $daysPastDue <= 60       => '31-60 days',
            $daysPastDue <= 90       => '61-90 days',
            $daysPastDue <= 180      => '91-180 days',
            default                  => '180+ days',
        };
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
