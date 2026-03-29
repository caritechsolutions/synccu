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
    private LedgerService $ledger;

    public function __construct()
    {
        $this->db       = Database::getInstance();
        $this->accounts = new AccountService();
        $this->ledger   = new LedgerService();
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

        // Get or create a placeholder account_id (user's first account)
        $userAccount = $this->db->fetchOne(
            'SELECT id FROM accounts WHERE user_id = ? AND tenant_id = ? AND status = ? LIMIT 1',
            [$userId, $tenantId, 'active'],
        );
        $accountId = $userAccount ? $userAccount['id'] : $this->generateUuid();

        $this->db->query(
            'INSERT INTO loans
                (id, tenant_id, user_id, account_id, loan_number, loan_type,
                 principal_amount, interest_rate, term_months, monthly_payment,
                 outstanding_balance, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
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
     * Creates a loan account and disburses funds.
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

            // Calculate monthly payment
            $monthlyPayment = $this->calculateMonthlyPayment(
                $principal,
                (float) $loan['interest_rate'],
                (int) $loan['term_months'],
            );

            // Update loan record
            $this->db->updateScoped('loans', [
                'status'              => 'approved',
                'approved_by'         => $approvedBy,
                'approved_at'         => $now,
                'disbursed_at'        => $now,
                'disbursed_amount'    => $principal,
                'monthly_payment'     => $monthlyPayment,
                'outstanding_balance' => $principal,
                'next_payment_date'   => date('Y-m-d', strtotime('+1 month')),
                'maturity_date'       => date('Y-m-d', strtotime("+{$loan['term_months']} months")),
                'updated_at'          => $now,
            ], ['id' => $loanId]);

            // Create ledger entry for disbursement
            $txnId = $this->generateUuid();
            $this->db->query(
                'INSERT INTO transactions
                    (id, tenant_id, reference_number, type, status, amount, currency,
                     to_account_id, user_id, description, created_at, completed_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $txnId,
                    $loan['tenant_id'],
                    'LN-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4))),
                    'loan_disbursement',
                    'completed',
                    $principal,
                    'USD',
                    $loan['account_id'],
                    $loan['user_id'],
                    "Loan disbursement - {$loan['loan_type']}",
                    $now,
                    $now,
                ],
            );

            // Debit Loan Receivable, Credit Cash
            $this->ledger->createDoubleEntry(
                $txnId,
                "Loan disbursement #{$loanId}",
                LedgerService::GL_LOAN_RECEIVABLE,
                LedgerService::GL_MEMBER_DEPOSITS,
                $principal,
            );

            // Generate amortisation schedule
            $this->generateSchedule($loanId, $loan);

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

        if (!in_array($loan['status'], ['approved', 'active'], true)) {
            throw new RuntimeException('Loan is not in a payable state.', 422);
        }

        $remainingBalance = (float) $loan['outstanding_balance'];
        if ($amount > $remainingBalance) {
            $amount = $remainingBalance; // Cap at remaining balance
        }

        return $this->db->transaction(function () use ($loanId, $loan, $amount, $fromAccountId, $userId) {
            $paymentId = $this->generateUuid();
            $now       = date('Y-m-d H:i:s');

            // Split payment into principal and interest
            $interestPortion  = $this->calculateInterestPortion($loan, $amount);
            $principalPortion = $amount - $interestPortion;

            $newBalance = (float) $loan['outstanding_balance'] - $principalPortion;

            // Debit source account if specified
            if ($fromAccountId !== null) {
                $this->accounts->updateBalance($fromAccountId, -$amount);
            }

            // Update loan balance
            $updates = [
                'outstanding_balance' => max(0, $newBalance),
                'total_paid'          => (float) ($loan['total_paid'] ?? 0) + $amount,
                'next_payment_date'   => $newBalance > 0 ? date('Y-m-d', strtotime('+1 month')) : null,
                'updated_at'          => $now,
            ];

            if ($newBalance <= 0.01) {
                $updates['status']             = 'paid_off';
                $updates['outstanding_balance'] = 0;
            } else {
                $updates['status'] = 'active';
            }

            $this->db->updateScoped('loans', $updates, ['id' => $loanId]);

            // Ledger entries
            $txnId = $this->generateUuid();
            $this->db->query(
                'INSERT INTO transactions
                    (id, tenant_id, reference_number, type, status, amount, currency,
                     from_account_id, user_id, description, created_at, completed_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $txnId,
                    $loan['tenant_id'],
                    'LP-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4))),
                    'loan_payment',
                    'completed',
                    $amount,
                    'USD',
                    $fromAccountId,
                    $userId,
                    "Loan payment - principal: {$principalPortion}, interest: {$interestPortion}",
                    $now,
                    $now,
                ],
            );

            // Credit Loan Receivable for principal, Credit Interest Income for interest
            if ($principalPortion > 0) {
                $this->ledger->createDoubleEntry(
                    $txnId,
                    "Loan principal payment #{$loanId}",
                    LedgerService::GL_MEMBER_DEPOSITS,
                    LedgerService::GL_LOAN_RECEIVABLE,
                    $principalPortion,
                );
            }

            if ($interestPortion > 0) {
                $this->ledger->createDoubleEntry(
                    $txnId,
                    "Loan interest payment #{$loanId}",
                    LedgerService::GL_MEMBER_DEPOSITS,
                    LedgerService::GL_LOAN_INTEREST_INCOME,
                    $interestPortion,
                );
            }

            return [
                'payment_id'    => $paymentId,
                'amount'        => $amount,
                'principal'     => $principalPortion,
                'interest'      => $interestPortion,
                'new_balance'   => max(0, $newBalance),
                'loan'          => $this->findById($loanId),
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
                'month'           => $month,
                'payment_date'    => date('Y-m-d', strtotime("+{$month} months")),
                'payment'         => round($payment, 2),
                'principal'       => $principalPart,
                'interest'        => $interest,
                'remaining_balance' => round(max(0, $balance), 2),
            ];
        }

        return $schedule;
    }

    /**
     * Get the stored amortisation schedule for an approved loan.
     */
    public function getSchedule(string $loanId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM loan_schedules WHERE loan_id = ? ORDER BY payment_number ASC',
            [$loanId],
        );
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
     * Calculate the interest portion of a payment based on the outstanding balance.
     */
    private function calculateInterestPortion(array $loan, float $paymentAmount): float
    {
        $monthlyRate = (float) $loan['interest_rate'] / 100 / 12;
        $interest    = (float) $loan['outstanding_balance'] * $monthlyRate;

        // Interest cannot exceed total payment
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
                    $row['month'],
                    $row['payment_date'],
                    $row['principal'],
                    $row['interest'],
                    $row['payment'],
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
