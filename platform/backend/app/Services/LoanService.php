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

        $this->db->query(
            'INSERT INTO loans
                (id, tenant_id, user_id, loan_type, amount, interest_rate, term_months,
                 purpose, status, application_date, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $loanId,
                $tenantId,
                $userId,
                $data['loan_type'],
                (float) $data['amount'],
                (float) $data['interest_rate'],
                (int) $data['term_months'],
                $data['purpose'] ?? null,
                'pending',
                $now,
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

        if ($loan['status'] !== 'pending') {
            throw new RuntimeException('Only pending loans can be approved.', 422);
        }

        return $this->db->transaction(function () use ($loanId, $loan, $approvedBy) {
            $now = date('Y-m-d H:i:s');

            // Calculate monthly payment
            $monthlyPayment = $this->calculateMonthlyPayment(
                (float) $loan['amount'],
                (float) $loan['interest_rate'],
                (int) $loan['term_months'],
            );

            // Create the loan account
            $loanAccount = $this->accounts->create([
                'user_id'      => $loan['user_id'],
                'account_type' => 'loan',
                'name'         => "Loan - {$loan['loan_type']}",
                'currency'     => 'USD',
            ], $loan['tenant_id']);

            // Update loan record
            $this->db->updateScoped('loans', [
                'status'           => 'approved',
                'approved_by'      => $approvedBy,
                'approved_date'    => $now,
                'disbursement_date' => $now,
                'monthly_payment'  => $monthlyPayment,
                'remaining_balance' => $loan['amount'],
                'loan_account_id'  => $loanAccount['id'],
                'next_payment_date' => date('Y-m-d', strtotime('+1 month')),
                'maturity_date'    => date('Y-m-d', strtotime("+{$loan['term_months']} months")),
                'updated_at'       => $now,
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
                    $loan['amount'],
                    'USD',
                    $loanAccount['id'],
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
                (float) $loan['amount'],
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

        if ($loan['status'] !== 'pending') {
            throw new RuntimeException('Only pending loans can be denied.', 422);
        }

        $this->db->updateScoped('loans', [
            'status'       => 'denied',
            'denied_by'    => $deniedBy,
            'denied_reason' => $reason,
            'updated_at'   => date('Y-m-d H:i:s'),
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

        $remainingBalance = (float) $loan['remaining_balance'];
        if ($amount > $remainingBalance) {
            $amount = $remainingBalance; // Cap at remaining balance
        }

        return $this->db->transaction(function () use ($loanId, $loan, $amount, $fromAccountId, $userId) {
            $paymentId = $this->generateUuid();
            $now       = date('Y-m-d H:i:s');

            // Split payment into principal and interest
            $interestPortion  = $this->calculateInterestPortion($loan, $amount);
            $principalPortion = $amount - $interestPortion;

            $newBalance = (float) $loan['remaining_balance'] - $principalPortion;

            // Record payment
            $this->db->query(
                'INSERT INTO loan_payments
                    (id, tenant_id, loan_id, amount, principal, interest, remaining_balance,
                     from_account_id, payment_date, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $paymentId,
                    $loan['tenant_id'],
                    $loanId,
                    $amount,
                    $principalPortion,
                    $interestPortion,
                    max(0, $newBalance),
                    $fromAccountId,
                    $now,
                    $now,
                ],
            );

            // Debit source account if specified
            if ($fromAccountId !== null) {
                $this->accounts->updateBalance($fromAccountId, -$amount);
            }

            // Update loan balance
            $updates = [
                'remaining_balance' => max(0, $newBalance),
                'last_payment_date' => $now,
                'next_payment_date' => $newBalance > 0 ? date('Y-m-d', strtotime('+1 month')) : null,
                'updated_at'        => $now,
            ];

            if ($newBalance <= 0.01) {
                $updates['status']           = 'paid_off';
                $updates['paid_off_date']    = $now;
                $updates['remaining_balance'] = 0;
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
        return $this->db->selectScoped(
            'loan_schedule',
            ['loan_id' => $loanId],
            'month ASC',
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
        $interest    = (float) $loan['remaining_balance'] * $monthlyRate;

        // Interest cannot exceed total payment
        return round(min($interest, $paymentAmount), 2);
    }

    /**
     * Persist the amortisation schedule to the database.
     */
    private function generateSchedule(string $loanId, array $loan): void
    {
        $schedule = $this->calculateSchedule(
            (float) $loan['amount'],
            (float) $loan['interest_rate'],
            (int) $loan['term_months'],
        );

        foreach ($schedule as $row) {
            $this->db->query(
                'INSERT INTO loan_schedule
                    (id, tenant_id, loan_id, month, payment_date, payment, principal, interest, remaining_balance, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                [
                    $this->generateUuid(),
                    $loan['tenant_id'],
                    $loanId,
                    $row['month'],
                    $row['payment_date'],
                    $row['payment'],
                    $row['principal'],
                    $row['interest'],
                    $row['remaining_balance'],
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
