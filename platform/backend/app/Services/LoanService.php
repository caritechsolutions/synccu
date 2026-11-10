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
        $now    = $data['application_date'] ?? date('Y-m-d H:i:s');
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

        $metadata = null;
        if (!empty($data['backdate'])) {
            $metadata = json_encode(['application_backdate' => $data['backdate']]);
        }

        $this->db->query(
            'INSERT INTO loans
                (id, tenant_id, user_id, account_id, loan_number, loan_type, loan_type_code,
                 principal_amount, interest_rate, term_months, monthly_payment,
                 outstanding_balance, status, purpose, metadata, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $loanId,
                $tenantId,
                $userId,
                $accountId,
                $loanNumber,
                $data['loan_type'],
                $data['loan_type_code'] ?? null,
                $amount,
                $rate,
                $term,
                $monthlyPayment,
                $amount,
                'application',
                $data['purpose'] ?? null,
                $metadata,
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
    public function approve(string $loanId, string $approvedBy, ?array $backdate = null): array
    {
        $loan = $this->findById($loanId);
        if ($loan === null) {
            throw new RuntimeException('Loan not found.', 404);
        }

        if (!in_array($loan['status'], ['application', 'pending'], true)) {
            throw new RuntimeException('Only pending/application loans can be approved.', 422);
        }

        if ($backdate !== null && $backdate['date'] < substr((string) $loan['created_at'], 0, 10)) {
            throw new RuntimeException('Approval date cannot be before the application date (' . substr((string) $loan['created_at'], 0, 10) . ').', 422);
        }

        return $this->db->transaction(function () use ($loanId, $loan, $approvedBy, $backdate) {
            $now = $backdate['datetime'] ?? date('Y-m-d H:i:s');
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

            $updates = [
                'status'              => 'approved',
                'account_id'          => $loanAccount['id'],
                'approved_by'         => $approvedBy,
                'approved_at'         => $now,
                'monthly_payment'     => $monthlyPayment,
                'outstanding_balance' => $principal,
                'updated_at'          => date('Y-m-d H:i:s'),
            ];
            if ($backdate !== null) {
                $updates['metadata'] = $this->mergeMetadata($loan, ['approval_backdate' => $backdate]);
            }
            $this->db->updateScoped('loans', $updates, ['id' => $loanId]);

            return $this->findById($loanId);
        });
    }

    /**
     * Merge keys into a loan's metadata JSON and return the encoded string.
     */
    private function mergeMetadata(array $loan, array $extra): string
    {
        $meta = [];
        if (!empty($loan['metadata'])) {
            $decoded = is_array($loan['metadata']) ? $loan['metadata'] : json_decode((string) $loan['metadata'], true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }
        return json_encode(array_merge($meta, $extra));
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
        $sourceLocationId = $options['source_location_id'] ?? null;
        $backdate = $options['backdate'] ?? null;

        // A historical (backdated) disbursement may skip the fund movement:
        // the cash left the CU long ago and today's vault/bank balances already reflect it.
        $historical = $backdate !== null && ($sourceLocationId === 'none' || $sourceLocationId === null || $sourceLocationId === '');
        if (!$historical && !$sourceLocationId) {
            throw new RuntimeException('A funding source (vault/bank) is required for disbursement.', 422);
        }
        if ($backdate !== null && !empty($loan['approved_at']) && $backdate['date'] < substr((string) $loan['approved_at'], 0, 10)) {
            throw new RuntimeException('Disbursement date cannot be before the approval date (' . substr((string) $loan['approved_at'], 0, 10) . ').', 422);
        }

        return $this->db->transaction(function () use ($loanId, $loan, $disbursedBy, $method, $targetAccountId, $sourceLocationId, $options, $backdate, $historical) {
            $now = $backdate['datetime'] ?? date('Y-m-d H:i:s');
            $principal = (float) $loan['principal_amount'];
            $termMonths = (int) $loan['term_months'];
            $tenantId = $loan['tenant_id'];

            // Look up the member name for the movement record
            $member = $this->db->fetchOne(
                "SELECT CONCAT(first_name, ' ', last_name) AS name FROM users WHERE id = ? AND tenant_id = ?",
                [$loan['user_id'], $tenantId],
            );
            $memberName = $member ? $member['name'] : 'Unknown';
            $methodLabels = [
                'account' => 'account', 'cash' => 'cash', 'check' => 'check',
                'wire' => 'wire', 'ach' => 'ACH',
            ];
            $methodLabel = $methodLabels[$method] ?? $method;
            $description = "{$loan['loan_number']} — {$memberName} via {$methodLabel}";

            if (!$historical) {
                // Validate and debit the funding source location (vault/bank/drawer)
                $sourceLocation = $this->db->fetchOne(
                    'SELECT * FROM fund_locations WHERE id = ? AND tenant_id = ?',
                    [$sourceLocationId, $tenantId],
                );
                if (!$sourceLocation) {
                    throw new RuntimeException('Funding source location not found.', 404);
                }
                if ((float) $sourceLocation['balance'] < $principal) {
                    throw new RuntimeException(
                        'Insufficient funds in ' . $sourceLocation['name'] . '. Available: $'
                        . number_format((float) $sourceLocation['balance'], 2)
                        . ', Required: $' . number_format($principal, 2),
                        422,
                    );
                }

                // Debit the fund location
                $this->db->query(
                    'UPDATE fund_locations SET balance = balance - ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?',
                    [$principal, $sourceLocationId, $tenantId],
                );

                // Record cash movement
                $movementId = $this->generateUuid();
                $reference = 'LF-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
                $this->db->query(
                    'INSERT INTO cash_movements
                        (id, tenant_id, type, from_location_id, amount, reference_number,
                         performed_by, description, metadata, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $movementId,
                        $tenantId,
                        'loan_disbursement',
                        $sourceLocationId,
                        $principal,
                        $reference,
                        $disbursedBy,
                        $description,
                        json_encode([
                            'loan_id' => $loanId,
                            'member_name' => $memberName,
                            'disbursement_method' => $method,
                            'target_account_id' => $targetAccountId,
                            'backdate' => $backdate,
                        ]),
                        $now,
                    ],
                );
            } else {
                $sourceLocationId = null;
            }

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
                        json_encode(['source' => 'loan_disbursement', 'loan_id' => $loanId, 'funded_from_location' => $sourceLocationId, 'historical' => $historical, 'backdate' => $backdate]),
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
                        'source_location_id'  => $sourceLocationId,
                        'check_number'        => $options['check_number'] ?? null,
                        'historical'          => $historical,
                        'backdate'            => $backdate,
                    ]),
                    $now,
                ],
            );

            // Generate the amortization schedule starting from the disbursement date
            $this->generateSchedule($loanId, $loan, $now);

            // Activate the loan
            $disbursedDate = substr($now, 0, 10);
            $updates = [
                'status'            => 'active',
                'disbursed_at'      => $now,
                'disbursed_amount'  => $principal,
                'next_payment_date' => date('Y-m-d', strtotime('+1 month', strtotime($disbursedDate))),
                'maturity_date'     => date('Y-m-d', strtotime("+{$termMonths} months", strtotime($disbursedDate))),
                'updated_at'        => date('Y-m-d H:i:s'),
            ];
            if ($backdate !== null) {
                $updates['metadata'] = $this->mergeMetadata($loan, ['disbursement_backdate' => $backdate, 'historical' => $historical]);
            }
            $this->db->updateScoped('loans', $updates, ['id' => $loanId]);

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
    /**
     * Post a loan payment.
     *
     * Options:
     *   payment_date  Y-m-d (supervisor-authorized backdate; must not precede the last activity)
     *   backdate      array from SupervisorAuthService::resolveBackdate (audit trail)
     *   method        cash | account | check | wire | ach  (default: account when from_account_id, else cash)
     *   drawer_id     teller drawer to credit for cash payments
     *   denominations denomination breakdown for the drawer movement
     *   skip_cash     true when a batch handles the drawer movement itself
     *   description   free-text note
     *   batch_id      links payments posted as part of a multi-line batch
     */
    public function makePayment(string $loanId, float $amount, ?string $fromAccountId = null, ?string $userId = null, array $options = []): array
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

        if ($fromAccountId !== null && $fromAccountId !== '') {
            $source = $this->accounts->findById($fromAccountId);
            if ($source === null) {
                throw new RuntimeException('Source account not found.', 404);
            }
            if ($source['account_type'] === 'loan') {
                throw new RuntimeException('Cannot pay a loan from a loan account.', 422);
            }
            if ($source['user_id'] !== $loan['user_id']) {
                throw new RuntimeException('The source account does not belong to this loan\'s member.', 422);
            }
            if ((float) $source['available_balance'] < $amount) {
                throw new RuntimeException('Insufficient funds in ' . $source['account_number'] . '.', 422);
            }
        } else {
            $fromAccountId = null;
            $source = null;
        }

        $paymentDate = $options['payment_date'] ?? date('Y-m-d');
        $backdate    = $options['backdate'] ?? null;
        $method      = $options['method'] ?? ($fromAccountId ? 'account' : 'cash');

        // Cash service schema check must happen before the transaction opens (DDL auto-commits in MySQL)
        $cashService = null;
        if ($method === 'cash' && !empty($options['drawer_id']) && empty($options['skip_cash'])) {
            $cashService = new CashManagementService();
            $cashService->ensureSchema();
        }

        // Make sure the loan has a schedule to work against (imported loans may not)
        $this->ensureScheduleExists($loanId, $loan);

        // A payment dated before the latest activity is a missed/late entry: it is
        // inserted at its date and every later payment is re-computed from it.
        $lastActivityDate = substr($this->getLastActivityDate($loanId, $loan), 0, 10);
        $outOfOrder = $paymentDate < $lastActivityDate;
        if ($outOfOrder) {
            $baseDate = substr($this->getBasePoint($loanId, $loan)['date'], 0, 10);
            if ($paymentDate < $baseDate) {
                throw new RuntimeException("Payment date {$paymentDate} is before the loan started ({$baseDate}).", 422);
            }
        }

        return $this->db->transaction(function () use ($loanId, $loan, $amount, $fromAccountId, $source, $userId, $options, $paymentDate, $backdate, $method, $lastActivityDate, $cashService, $outOfOrder) {
            $now = ($backdate['datetime'] ?? null) ?: ($paymentDate === date('Y-m-d') ? date('Y-m-d H:i:s') : $paymentDate . ' ' . date('H:i:s'));
            $outstandingBalance = (float) $loan['outstanding_balance'];
            $annualRate = (float) $loan['interest_rate'];
            $dailyRate = $annualRate / 100 / 365;

            if ($outOfOrder) {
                // Provisional figures; the replay below computes the real split
                $daysSince = 0;
                $interestPortion = 0.0;
                $principalPortion = $amount;
                $newBalance = $outstandingBalance;
            } else {
                // Accrued interest = days between the previous activity and this payment's date
                $lastDate = new \DateTimeImmutable($lastActivityDate);
                $payDate  = new \DateTimeImmutable($paymentDate);
                $daysSince = max(0, (int) $payDate->diff($lastDate)->days);
                $accruedInterest = round($outstandingBalance * $dailyRate * $daysSince + $this->openingInterestDue($loanId, $loan), 2);

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
            }

            $paymentRef = 'LP-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
            $txnId = $this->generateUuid();

            // Debit source account if specified, and record it on that account's history
            if ($fromAccountId !== null && $source !== null) {
                $this->accounts->updateBalance($fromAccountId, -$amount);
                $this->db->query(
                    'INSERT INTO transactions
                        (id, tenant_id, reference_number, type, status, amount,
                         account_id, related_account_id, balance_after, processed_by, description, metadata, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $this->generateUuid(),
                        $loan['tenant_id'],
                        $paymentRef,
                        'withdrawal',
                        'completed',
                        $amount,
                        $fromAccountId,
                        $loan['account_id'],
                        (float) $source['balance'] - $amount,
                        $userId,
                        "Loan payment - {$loan['loan_number']}",
                        json_encode(['source' => 'loan_payment', 'loan_id' => $loanId, 'loan_payment_id' => $txnId, 'batch_id' => $options['batch_id'] ?? null, 'backdate' => $backdate]),
                        $now,
                    ],
                );
            }

            if (!$outOfOrder) {
                // Update loan account balance: subtract principal portion
                if ($loan['account_id']) {
                    $this->accounts->updateBalance($loan['account_id'], -$principalPortion);
                }

                // Update loan record
                $updates = [
                    'outstanding_balance' => max(0, $newBalance),
                    'total_paid'          => (float) ($loan['total_paid'] ?? 0) + $amount,
                    'next_payment_date'   => $newBalance > 0.01 ? date('Y-m-d', strtotime('+1 month', strtotime($paymentDate))) : null,
                    'updated_at'          => date('Y-m-d H:i:s'),
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
            }

            // Record transaction linked to the loan account
            $balanceAfter = max(0, $newBalance);
            $description = trim((string) ($options['description'] ?? '')) ?: "Loan payment - {$loan['loan_number']}";
            $this->db->query(
                'INSERT INTO transactions
                    (id, tenant_id, reference_number, type, status, amount,
                     account_id, related_account_id, balance_after, processed_by, description, metadata, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $txnId,
                    $loan['tenant_id'],
                    $paymentRef,
                    'loan_payment',
                    'completed',
                    $amount,
                    $loan['account_id'],
                    $fromAccountId,
                    $balanceAfter,
                    $userId,
                    $description,
                    json_encode([
                        'principal'       => $principalPortion,
                        'interest'        => $interestPortion,
                        'loan_id'         => $loanId,
                        'days_accrued'    => $daysSince,
                        'daily_rate'      => $dailyRate,
                        'payment_date'    => $paymentDate,
                        'method'          => $method,
                        'from_account_id' => $fromAccountId,
                        'drawer_id'       => $options['drawer_id'] ?? null,
                        'batch_id'        => $options['batch_id'] ?? null,
                        'backdate'        => $backdate,
                        'provisional'     => $outOfOrder,
                    ]),
                    $now,
                ],
            );

            // Cash received at the counter goes into the teller's drawer
            if ($cashService !== null) {
                $cashService->recordDepositCash(
                    $loan['tenant_id'],
                    $options['drawer_id'],
                    $amount,
                    is_array($options['denominations'] ?? null) ? $options['denominations'] : [],
                    $txnId,
                    $userId ?? '',
                    "Loan payment - {$loan['loan_number']}",
                );
            }

            if ($outOfOrder) {
                // Re-run every payment from the loan's starting point so this one
                // takes its place in sequence and all later splits/balances follow.
                $replay = $this->replayPayments($loanId);
                $mine = null;
                $adjusted = 0;
                foreach ($replay['payments'] as $p) {
                    if ($mine !== null) {
                        $adjusted++;
                    }
                    if ($p['id'] === $txnId) {
                        $mine = $p;
                    }
                }
                return [
                    'payment_id'        => $txnId,
                    'reference'         => $paymentRef,
                    'amount'            => $amount,
                    'principal'         => $mine['principal'] ?? $principalPortion,
                    'interest'          => $mine['interest'] ?? $interestPortion,
                    'days_accrued'      => $mine['days_accrued'] ?? $daysSince,
                    'payment_date'      => $paymentDate,
                    'new_balance'       => $mine['balance_after'] ?? $newBalance,
                    'out_of_order'      => true,
                    'adjusted_payments' => $adjusted,
                    'loan_balance'      => $replay['outstanding_balance'],
                    'loan'              => $this->findById($loanId),
                ];
            }

            // Mark the next upcoming schedule row as paid
            $nextRow = $this->db->fetchOne(
                "SELECT id FROM loan_schedules WHERE loan_id = ? AND status IN ('upcoming', 'due') ORDER BY payment_number ASC LIMIT 1",
                [$loanId],
            );
            if ($nextRow) {
                $this->db->query(
                    "UPDATE loan_schedules SET status = 'paid', paid_amount = ?, paid_at = ? WHERE id = ?",
                    [$amount, $now, $nextRow['id']],
                );
            }

            // Recalculate remaining schedule based on new balance, from the payment date
            $updatedLoan = $this->findById($loanId);
            if ($updatedLoan && (float) $updatedLoan['outstanding_balance'] > 0.01) {
                $this->recalculateSchedule($loanId, $updatedLoan, $paymentDate);
            } else {
                // Loan paid off — remove remaining upcoming rows
                $this->db->query(
                    "DELETE FROM loan_schedules WHERE loan_id = ? AND status IN ('upcoming', 'due')",
                    [$loanId],
                );
            }

            return [
                'payment_id'   => $txnId,
                'reference'    => $paymentRef,
                'amount'       => $amount,
                'principal'    => $principalPortion,
                'interest'     => $interestPortion,
                'days_accrued' => $daysSince,
                'payment_date' => $paymentDate,
                'new_balance'  => max(0, $newBalance),
                'loan'         => $this->findById($loanId),
            ];
        });
    }

    /**
     * Interest carried into this loan at its start (e.g. accrued on a note it
     * refinanced), stored as metadata.opening_interest. Collected with the
     * first payment.
     */
    private function openingInterest(array $loan): float
    {
        $meta = is_array($loan['metadata'] ?? null) ? $loan['metadata'] : (json_decode((string) ($loan['metadata'] ?? ''), true) ?: []);
        return round((float) ($meta['opening_interest'] ?? 0), 2);
    }

    /** The opening interest if it has not been collected yet (no payment posted so far). */
    private function openingInterestDue(string $loanId, array $loan): float
    {
        $carry = $this->openingInterest($loan);
        if ($carry <= 0 || empty($loan['account_id'])) {
            return 0.0;
        }
        $paid = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM transactions WHERE tenant_id = ? AND account_id = ? AND type = 'loan_payment' AND status = 'completed'",
            [$loan['tenant_id'], $loan['account_id']],
        );
        return $paid > 0 ? 0.0 : $carry;
    }

    /**
     * Where the payment history starts: the disbursement (balance = principal)
     * or, for imported loans, the as-of date whose balance was loaded from the
     * legacy file (balance = current outstanding + principal paid since).
     *
     * @return array{date: string, balance: float, base_total_paid: float, payments: array}
     */
    private function getBasePoint(string $loanId, array $loan): array
    {
        $tenantId = $loan['tenant_id'];
        $payments = [];
        if ($loan['account_id']) {
            $payments = $this->db->fetchAll(
                "SELECT id, amount, balance_after, metadata, created_at
                 FROM transactions
                 WHERE tenant_id = ? AND account_id = ? AND type = 'loan_payment' AND status = 'completed'
                 ORDER BY created_at ASC, id ASC",
                [$tenantId, $loan['account_id']],
            );
        }
        $paidAmount = 0.0;
        $paidPrincipal = 0.0;   // principal already taken off outstanding_balance
        foreach ($payments as $p) {
            $paidAmount += (float) $p['amount'];
            $m = json_decode((string) ($p['metadata'] ?? ''), true) ?: [];
            // A payment inserted out of sequence has not touched the balance yet
            if (!empty($m['provisional'])) {
                continue;
            }
            $paidPrincipal += isset($m['principal']) ? (float) $m['principal'] : (float) $p['amount'];
        }

        $disb = $loan['account_id'] ? $this->db->fetchOne(
            "SELECT created_at FROM transactions
             WHERE tenant_id = ? AND account_id = ? AND type = 'loan_disbursement'
             ORDER BY created_at ASC LIMIT 1",
            [$tenantId, $loan['account_id']],
        ) : null;

        $meta = is_array($loan['metadata'] ?? null) ? $loan['metadata'] : (json_decode((string) ($loan['metadata'] ?? ''), true) ?: []);
        $baseTotalPaid = 0.0;

        if ($disb) {
            // Disbursed in this system: history starts at the principal on the disbursement date
            $balance = (float) ($loan['disbursed_amount'] ?: $loan['principal_amount']);
            $date = (string) $disb['created_at'];
        } elseif (!empty($meta['last_payment_date'])) {
            // Imported with an as-of date: the file balance is the starting point on that
            // date, reconstructed from what has been paid since
            $balance = round((float) $loan['outstanding_balance'] + $paidPrincipal, 2);
            $date = (string) $meta['last_payment_date'];
            $baseTotalPaid = max(0.0, round((float) ($loan['total_paid'] ?? 0) - $paidAmount, 2));
        } else {
            // Imported without an as-of date: the history is being re-keyed from the
            // loan date, so start from the original principal on that date
            $balance = (float) ($loan['disbursed_amount'] ?: $loan['principal_amount']);
            $date = (string) ($loan['disbursed_at'] ?? $loan['approved_at'] ?? $loan['created_at']);
        }

        return [
            'date'            => $date,
            'balance'         => $balance,
            'base_total_paid' => $baseTotalPaid,
            'payments'        => $payments,
        ];
    }

    /**
     * Re-run every payment on a loan in date order from its starting point,
     * re-computing each interest/principal split and balance, then rebuild the
     * schedule. Used when a payment is entered out of sequence.
     */
    public function replayPayments(string $loanId): array
    {
        $loan = $this->findById($loanId);
        if ($loan === null) {
            throw new RuntimeException('Loan not found.', 404);
        }

        $base = $this->getBasePoint($loanId, $loan);
        $dailyRate = (float) $loan['interest_rate'] / 100 / 365;
        $balance = $base['balance'];
        $lastDate = new \DateTimeImmutable(substr($base['date'], 0, 10));
        $totalPaid = $base['base_total_paid'];
        $rows = [];
        $replayedAt = date('Y-m-d H:i:s');

        // Rebuild the schedule from scratch: one 'paid' row per payment, then upcoming rows
        $this->db->query('DELETE FROM loan_schedules WHERE loan_id = ?', [$loanId]);

        foreach ($base['payments'] as $i => $p) {
            $pDate = new \DateTimeImmutable(substr((string) $p['created_at'], 0, 10));
            $days = $pDate < $lastDate ? 0 : (int) $pDate->diff($lastDate)->days;
            $interest = $balance > 0 ? round($balance * $dailyRate * $days, 2) : 0.0;
            if ($i === 0) {
                // Interest carried over from a refinanced note is collected with the first payment
                $interest = round($interest + $this->openingInterest($loan), 2);
            }
            $amount = (float) $p['amount'];
            $interestPortion = round(min($interest, $amount), 2);
            $principalPortion = round($amount - $interestPortion, 2);
            $overpayment = 0.0;
            if ($principalPortion > $balance) {
                $overpayment = round($principalPortion - $balance, 2);
                $principalPortion = round($balance, 2);
            }
            $balance = round(max(0, $balance - $principalPortion), 2);
            $totalPaid += $amount;

            $meta = json_decode((string) ($p['metadata'] ?? ''), true) ?: [];
            $meta = array_merge($meta, [
                'principal'    => $principalPortion,
                'interest'     => $interestPortion,
                'days_accrued' => $days,
                'daily_rate'   => $dailyRate,
                'payment_date' => $pDate->format('Y-m-d'),
                'overpayment'  => $overpayment,
                'replayed_at'  => $replayedAt,
                'provisional'  => false,
            ]);
            $this->db->query(
                'UPDATE transactions SET balance_after = ?, metadata = ? WHERE id = ?',
                [$balance, json_encode($meta), $p['id']],
            );

            $this->db->query(
                "INSERT INTO loan_schedules
                    (id, loan_id, payment_number, due_date, principal_amount, interest_amount, total_amount, paid_amount, status, paid_at, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'paid', ?, NOW())",
                [$this->generateUuid(), $loanId, $i + 1, $pDate->format('Y-m-d'), $principalPortion, $interestPortion, $amount, $amount, $p['created_at']],
            );

            $rows[] = [
                'id'            => $p['id'],
                'payment_date'  => $pDate->format('Y-m-d'),
                'amount'        => $amount,
                'days_accrued'  => $days,
                'interest'      => $interestPortion,
                'principal'     => $principalPortion,
                'overpayment'   => $overpayment,
                'balance_after' => $balance,
            ];
            if ($pDate > $lastDate) {
                $lastDate = $pDate;
            }
        }

        // Loan record and tracking account
        $paidOff = $balance <= 0.01;
        $updates = [
            'outstanding_balance' => $paidOff ? 0 : $balance,
            'total_paid'          => round($totalPaid, 2),
            'next_payment_date'   => $paidOff ? null : $lastDate->modify('+1 month')->format('Y-m-d'),
            'status'              => $paidOff ? 'paid_off' : ($loan['status'] === 'delinquent' ? 'delinquent' : 'active'),
            'updated_at'          => date('Y-m-d H:i:s'),
        ];
        $this->db->updateScoped('loans', $updates, ['id' => $loanId]);
        if ($loan['account_id']) {
            $this->db->execute(
                'UPDATE accounts SET balance = ?, available_balance = ?, status = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?',
                [$updates['outstanding_balance'], $updates['outstanding_balance'], $paidOff ? 'closed' : 'active', $loan['account_id'], $loan['tenant_id']],
            );
        }

        if (!$paidOff) {
            $this->recalculateSchedule($loanId, $this->findById($loanId), $lastDate->format('Y-m-d'));
        }

        return [
            'base_date'           => substr($base['date'], 0, 10),
            'base_balance'        => $base['balance'],
            'payments'            => $rows,
            'outstanding_balance' => $updates['outstanding_balance'],
            'total_paid'          => $updates['total_paid'],
            'status'              => $updates['status'],
        ];
    }

    /**
     * Imported / legacy loans may be active with no schedule rows. Build one
     * from the current balance so payments and the Schedule tab work.
     */
    public function ensureScheduleExists(string $loanId, ?array $loan = null): void
    {
        $loan = $loan ?? $this->findById($loanId);
        if ($loan === null || !in_array($loan['status'], ['active', 'delinquent'], true)) {
            return;
        }
        if ((float) $loan['outstanding_balance'] <= 0.01) {
            return;
        }
        $count = (int) $this->db->fetchColumn('SELECT COUNT(*) FROM loan_schedules WHERE loan_id = ?', [$loanId]);
        if ($count > 0) {
            return;
        }
        $start = substr($this->getLastActivityDate($loanId, $loan), 0, 10);
        $this->recalculateSchedule($loanId, $loan, $start);
    }

    // ------------------------------------------------------------------
    // Amortisation Schedule
    // ------------------------------------------------------------------

    /**
     * Calculate the amortisation schedule for a loan.
     */
    public function calculateSchedule(float $principal, float $annualRate, int $termMonths, ?string $startDate = null, int $startNumber = 1, ?float $fixedPayment = null): array
    {
        $monthlyRate = $annualRate / 100 / 12;
        $payment     = ($fixedPayment !== null && $fixedPayment > 0)
            ? $fixedPayment
            : $this->calculateMonthlyPayment($principal, $annualRate, $termMonths);
        $balance     = $principal;
        $schedule    = [];
        $baseDate    = $startDate ?? date('Y-m-d');

        for ($month = 1; $month <= $termMonths; $month++) {
            $interest       = round($balance * $monthlyRate, 2);
            $principalPart  = round($payment - $interest, 2);

            // Last payment adjustment for rounding (or the balance is cleared early)
            if ($month === $termMonths || $principalPart >= $balance) {
                $principalPart = round($balance, 2);
                $payment       = $principalPart + $interest;
            }

            $balance -= $principalPart;

            $schedule[] = [
                'payment_number'    => $startNumber + $month - 1,
                'due_date'          => date('Y-m-d', strtotime("+{$month} months", strtotime($baseDate))),
                'payment_amount'    => round($payment, 2),
                'principal'         => $principalPart,
                'interest'          => $interest,
                'remaining_balance' => round(max(0, $balance), 2),
                'status'            => 'upcoming',
            ];

            if ($balance <= 0.005) {
                break;
            }
        }

        return $schedule;
    }

    /**
     * Get the stored amortisation schedule for an approved loan.
     */
    public function getSchedule(string $loanId): array
    {
        $loan = $this->findById($loanId);
        if (!$loan) {
            return [];
        }

        try {
            $this->ensureScheduleExists($loanId, $loan);
        } catch (\Throwable $e) {
            error_log('Schedule backfill failed for loan ' . $loanId . ': ' . $e->getMessage());
        }

        $rows = $this->db->fetchAll(
            'SELECT payment_number, due_date, principal_amount, interest_amount,
                    total_amount, paid_amount, status, paid_at
             FROM loan_schedules WHERE loan_id = ? ORDER BY payment_number ASC',
            [$loanId],
        );

        // Build running balance backwards from the current outstanding balance:
        // start = outstanding + principal already paid on 'paid' rows, then subtract
        // each row. (Imported loans have schedules built from outstanding, not principal.)
        $balance = (float) $loan['outstanding_balance'];
        foreach ($rows as $row) {
            if ($row['status'] === 'paid') {
                if ((float) $row['paid_amount'] > 0) {
                    $balance += (float) $row['paid_amount'] - min((float) $row['interest_amount'], (float) $row['paid_amount']);
                } else {
                    $balance += (float) $row['principal_amount'];
                }
            }
        }
        $schedule = [];
        foreach ($rows as $row) {
            $principalAmt = (float) $row['principal_amount'];
            // For paid rows, use actual paid principal (may differ from scheduled)
            if ($row['status'] === 'paid' && (float) $row['paid_amount'] > 0) {
                $paidInterest = min((float) $row['interest_amount'], (float) $row['paid_amount']);
                $paidPrincipal = (float) $row['paid_amount'] - $paidInterest;
                $balance -= $paidPrincipal;
            } else {
                $balance -= $principalAmt;
            }

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

        $accrued = round($outstanding * $dailyRate * $daysSince + $this->openingInterestDue($loanId, $loan), 2);

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

        $accruedInterest = round($outstandingBalance * $dailyRate * $daysSince + $this->openingInterestDue($loanId, $loan), 2);
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
            'ALTER TABLE `loans` ADD COLUMN `loan_type_code` VARCHAR(2) DEFAULT NULL',
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

        // Legacy imports can carry a last-payment date in metadata
        if (!empty($loan['metadata'])) {
            $meta = is_array($loan['metadata']) ? $loan['metadata'] : json_decode((string) $loan['metadata'], true);
            if (is_array($meta) && !empty($meta['last_payment_date'])) {
                return (string) $meta['last_payment_date'];
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
     *
     * When called during disbursement, creates the initial schedule.
     * When called after a payment, deletes unpaid rows and regenerates
     * based on the new outstanding balance and remaining term.
     */
    private function generateSchedule(string $loanId, array $loan, ?string $startDate = null): void
    {
        $schedule = $this->calculateSchedule(
            (float) $loan['principal_amount'],
            (float) $loan['interest_rate'],
            (int) $loan['term_months'],
            $startDate,
        );

        // Clear any existing unpaid schedule rows (for recalculation)
        $this->db->query(
            "DELETE FROM loan_schedules WHERE loan_id = ? AND status IN ('upcoming', 'due')",
            [$loanId],
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

    /**
     * Recalculate the remaining schedule after a payment.
     *
     * Keeps paid rows intact, replaces upcoming rows with a new schedule
     * based on the current outstanding balance.
     */
    private function recalculateSchedule(string $loanId, array $loan, ?string $fromDate = null): void
    {
        $newBalance = (float) $loan['outstanding_balance'];
        if ($newBalance <= 0.01) {
            $this->db->query(
                "DELETE FROM loan_schedules WHERE loan_id = ? AND status IN ('upcoming', 'due')",
                [$loanId],
            );
            return;
        }

        $annualRate = (float) $loan['interest_rate'];
        // Keep the contracted payment amount (imported loans carry theirs from the legacy file)
        $originalPayment = (float) ($loan['monthly_payment'] ?? 0);
        if ($originalPayment <= 0) {
            $originalPayment = $this->calculateMonthlyPayment(
                (float) $loan['principal_amount'],
                $annualRate,
                (int) $loan['term_months'],
            );
        }
        $fromDate = $fromDate ?: date('Y-m-d');

        // Count how many payments have been made (paid rows)
        $paidCount = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM loan_schedules WHERE loan_id = ? AND status = 'paid'",
            [$loanId],
        );

        // Calculate remaining months at the contracted payment amount
        $monthlyRate = $annualRate / 100 / 12;
        $fixedPayment = $originalPayment;
        if ($monthlyRate > 0) {
            // n = -log(1 - B*r/P) / log(1+r)
            $ratio = $newBalance * $monthlyRate / $originalPayment;
            if ($ratio < 1) {
                $remainingMonths = (int) ceil(-log(1 - $ratio) / log(1 + $monthlyRate));
            } else {
                // Payment no longer covers interest; fall back to a re-amortised payment
                $remainingMonths = max(1, (int) $loan['term_months'] - $paidCount);
                $fixedPayment = null;
            }
        } else {
            $remainingMonths = (int) ceil($newBalance / $originalPayment);
        }
        $remainingMonths = max(1, $remainingMonths);

        // Delete upcoming rows and regenerate
        $this->db->query(
            "DELETE FROM loan_schedules WHERE loan_id = ? AND status IN ('upcoming', 'due')",
            [$loanId],
        );

        $schedule = $this->calculateSchedule(
            $newBalance,
            $annualRate,
            $remainingMonths,
            $fromDate,
            $paidCount + 1,
            $fixedPayment,
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

        // Update the maturity date; the contracted monthly payment stays as-is
        $lastRow = end($schedule);
        $updates = [
            'maturity_date' => $lastRow['due_date'],
            'updated_at'    => date('Y-m-d H:i:s'),
        ];
        if ($fixedPayment === null) {
            $updates['monthly_payment'] = $schedule[0]['payment_amount'];
        }
        $this->db->updateScoped('loans', $updates, ['id' => $loanId]);
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
