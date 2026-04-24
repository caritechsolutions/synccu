<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class ReconciliationService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function reconcileAll(string $tenantId, bool $dryRun = false): array
    {
        $results = [
            'accounts'       => $this->reconcileAccounts($tenantId, $dryRun),
            'loans'          => $this->reconcileLoans($tenantId, $dryRun),
            'fund_locations' => $this->reconcileFundLocations($tenantId, $dryRun),
        ];

        $results['total_fixes'] = $results['accounts']['fixed']
            + $results['loans']['fixed']
            + $results['fund_locations']['fixed'];

        return $results;
    }

    private function reconcileAccounts(string $tenantId, bool $dryRun): array
    {
        $accounts = $this->db->fetchAll(
            "SELECT id, account_number, account_type, balance, available_balance
             FROM accounts WHERE tenant_id = ? AND status != 'closed'",
            [$tenantId],
        );

        $checked = 0;
        $fixed = 0;
        $details = [];

        foreach ($accounts as $acct) {
            $checked++;
            $acctId = $acct['id'];

            $computed = $this->db->fetchOne(
                "SELECT
                    COALESCE(SUM(CASE
                        WHEN type IN ('deposit','interest','loan_payment','fee_reversal','adjustment') THEN amount
                        WHEN type IN ('loan_disbursement') THEN amount
                        WHEN type IN ('withdrawal','fee','late_fee','expense') THEN -amount
                        WHEN type = 'transfer' AND account_id = ? THEN -amount
                        ELSE 0
                    END), 0) AS computed_balance
                 FROM transactions
                 WHERE account_id = ? AND tenant_id = ? AND status = 'completed'",
                [$acctId, $acctId, $tenantId],
            );

            $incoming = (float) ($this->db->fetchColumn(
                "SELECT COALESCE(SUM(amount), 0)
                 FROM transactions
                 WHERE related_account_id = ? AND tenant_id = ? AND type = 'transfer' AND status = 'completed'",
                [$acctId, $tenantId],
            ) ?? 0);

            $expectedBalance = round((float) $computed['computed_balance'] + $incoming, 2);
            $currentBalance = round((float) $acct['balance'], 2);
            $diff = round($expectedBalance - $currentBalance, 2);

            if (abs($diff) > 0.001) {
                $fixed++;
                $entry = [
                    'account_id'     => $acctId,
                    'account_number' => $acct['account_number'],
                    'type'           => $acct['account_type'],
                    'was'            => $currentBalance,
                    'should_be'      => $expectedBalance,
                    'diff'           => $diff,
                ];
                $details[] = $entry;

                if (!$dryRun) {
                    $this->db->execute(
                        'UPDATE accounts SET balance = ?, available_balance = ?, updated_at = NOW()
                         WHERE id = ? AND tenant_id = ?',
                        [$expectedBalance, $expectedBalance, $acctId, $tenantId],
                    );
                }
            }
        }

        return ['checked' => $checked, 'fixed' => $fixed, 'details' => $details];
    }

    private function reconcileLoans(string $tenantId, bool $dryRun): array
    {
        $loans = $this->db->fetchAll(
            "SELECT id, loan_number, principal_amount, outstanding_balance, total_paid, status, account_id
             FROM loans WHERE tenant_id = ? AND status NOT IN ('cancelled')",
            [$tenantId],
        );

        $checked = 0;
        $fixed = 0;
        $details = [];

        foreach ($loans as $loan) {
            $checked++;
            $loanId = $loan['id'];
            $principal = (float) $loan['principal_amount'];

            $paymentData = $this->db->fetchOne(
                "SELECT
                    COALESCE(SUM(amount), 0) AS total_paid,
                    COALESCE(SUM(CASE WHEN metadata IS NOT NULL THEN
                        COALESCE(JSON_EXTRACT(metadata, '$.principal_portion'), 0)
                    ELSE 0 END), 0) AS principal_paid
                 FROM transactions
                 WHERE tenant_id = ? AND type = 'loan_payment' AND status = 'completed'
                   AND (account_id = ? OR (metadata IS NOT NULL AND JSON_EXTRACT(metadata, '$.loan_id') = ?))",
                [$tenantId, $loan['account_id'] ?? '', $loanId],
            );

            $totalPaid = round((float) $paymentData['total_paid'], 2);
            $principalPaid = round((float) $paymentData['principal_paid'], 2);

            if ($principalPaid > 0.01) {
                $expectedBalance = round(max(0, $principal - $principalPaid), 2);
            } else {
                $expectedBalance = round(max(0, $principal - $totalPaid), 2);
            }

            $currentBalance = round((float) $loan['outstanding_balance'], 2);
            $currentTotalPaid = round((float) ($loan['total_paid'] ?? 0), 2);
            $diff = round($expectedBalance - $currentBalance, 2);
            $paidDiff = round($totalPaid - $currentTotalPaid, 2);

            if (abs($diff) > 0.001 || abs($paidDiff) > 0.001) {
                $fixed++;
                $updates = [
                    'outstanding_balance' => $expectedBalance,
                    'total_paid'          => $totalPaid,
                    'updated_at'          => date('Y-m-d H:i:s'),
                ];

                if ($expectedBalance <= 0.01 && !in_array($loan['status'], ['paid_off', 'application', 'cancelled'])) {
                    $updates['status'] = 'paid_off';
                    $updates['outstanding_balance'] = 0;
                }

                $entry = [
                    'loan_id'            => $loanId,
                    'loan_number'        => $loan['loan_number'],
                    'principal'          => $principal,
                    'balance_was'        => $currentBalance,
                    'balance_should_be'  => $expectedBalance,
                    'diff'               => $diff,
                    'total_paid_was'     => $currentTotalPaid,
                    'total_paid_should_be' => $totalPaid,
                ];
                $details[] = $entry;

                if (!$dryRun) {
                    $this->db->execute(
                        'UPDATE loans SET outstanding_balance = ?, total_paid = ?, updated_at = ?
                         WHERE id = ? AND tenant_id = ?',
                        [$updates['outstanding_balance'], $updates['total_paid'], $updates['updated_at'], $loanId, $tenantId],
                    );

                    if (isset($updates['status'])) {
                        $this->db->execute(
                            'UPDATE loans SET status = ? WHERE id = ? AND tenant_id = ?',
                            [$updates['status'], $loanId, $tenantId],
                        );
                    }
                }
            }
        }

        return ['checked' => $checked, 'fixed' => $fixed, 'details' => $details];
    }

    private function reconcileFundLocations(string $tenantId, bool $dryRun): array
    {
        $hasFundLocations = true;
        try {
            $this->db->fetchAll("SELECT 1 FROM fund_locations LIMIT 1", []);
        } catch (\Throwable) {
            $hasFundLocations = false;
        }

        if (!$hasFundLocations) {
            return ['checked' => 0, 'fixed' => 0, 'details' => [], 'note' => 'fund_locations table does not exist'];
        }

        $locations = $this->db->fetchAll(
            "SELECT id, name, type, balance FROM fund_locations WHERE tenant_id = ? AND status != 'closed'",
            [$tenantId],
        );

        $checked = 0;
        $fixed = 0;
        $details = [];

        foreach ($locations as $loc) {
            $checked++;
            $locId = $loc['id'];

            $inflow = (float) ($this->db->fetchColumn(
                "SELECT COALESCE(SUM(amount), 0)
                 FROM cash_movements
                 WHERE to_location_id = ? AND tenant_id = ? AND status = 'completed'",
                [$locId, $tenantId],
            ) ?? 0);

            $outflow = (float) ($this->db->fetchColumn(
                "SELECT COALESCE(SUM(amount), 0)
                 FROM cash_movements
                 WHERE from_location_id = ? AND tenant_id = ? AND status = 'completed'",
                [$locId, $tenantId],
            ) ?? 0);

            $adjustments = (float) ($this->db->fetchColumn(
                "SELECT COALESCE(SUM(amount), 0)
                 FROM cash_movements
                 WHERE to_location_id = ? AND tenant_id = ? AND type = 'adjustment' AND status = 'completed'",
                [$locId, $tenantId],
            ) ?? 0);

            $expectedBalance = round($inflow - $outflow, 2);
            $currentBalance = round((float) $loc['balance'], 2);
            $diff = round($expectedBalance - $currentBalance, 2);

            if (abs($diff) > 0.001) {
                $fixed++;
                $entry = [
                    'location_id' => $locId,
                    'name'        => $loc['name'],
                    'type'        => $loc['type'],
                    'was'         => $currentBalance,
                    'should_be'   => $expectedBalance,
                    'diff'        => $diff,
                    'inflow'      => round($inflow, 2),
                    'outflow'     => round($outflow, 2),
                ];
                $details[] = $entry;

                if (!$dryRun) {
                    $this->db->execute(
                        'UPDATE fund_locations SET balance = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?',
                        [$expectedBalance, $locId, $tenantId],
                    );
                }
            }
        }

        return ['checked' => $checked, 'fixed' => $fixed, 'details' => $details];
    }
}
