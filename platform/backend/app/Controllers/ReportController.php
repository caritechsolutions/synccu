<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\LedgerService;

/**
 * Report REST controller.
 *
 * Provides GL-based financial reports (trial balance, income statement,
 * balance sheet), member growth analytics, delinquency views, and CSV
 * export for the current tenant.
 */
final class ReportController
{
    private Database $db;
    private LedgerService $ledger;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->ledger = new LedgerService();
    }

    // ------------------------------------------------------------------
    // Financial Reports
    // ------------------------------------------------------------------

    /**
     * GET /api/v1/reports/trial-balance
     *
     * Trial balance showing all GL accounts with debit/credit totals.
     */
    public function trialBalance(Request $request): Response
    {
        $result = $this->ledger->trialBalance();
        return Response::ok($result);
    }

    /**
     * GET /api/v1/reports/general-ledger
     *
     * Paginated journal entries with their debit/credit lines.
     */
    public function generalLedger(Request $request): Response
    {
        $from = $request->query('from', date('Y-m-01'));
        $to = $request->query('to', date('Y-m-d') . ' 23:59:59');
        $page = max(1, (int) ($request->query('page', '1')));
        $perPage = min(100, max(1, (int) ($request->query('per_page', '50'))));

        $result = $this->ledger->getEntries($from, $to, $page, $perPage);

        // Load lines for each entry
        $tenantId = $this->db->getTenantId();
        foreach ($result['items'] as &$entry) {
            $entry['lines'] = $this->db->fetchAll(
                'SELECT jel.*, ga.name AS account_name, ga.type AS account_type
                 FROM journal_entry_lines jel
                 LEFT JOIN gl_accounts ga ON ga.code = jel.gl_account_code AND ga.tenant_id = jel.tenant_id
                 WHERE jel.journal_entry_id = ? AND jel.tenant_id = ?
                 ORDER BY jel.debit DESC',
                [$entry['id'], $tenantId]
            );
        }
        unset($entry);

        return Response::paginated($result['items'], $result['total'], $page, $perPage);
    }

    /**
     * GET /api/v1/reports/income-statement
     *
     * Revenue and expenses computed from the transactions table.
     */
    public function incomeStatement(Request $request): Response
    {
        $tenantId = $this->db->getTenantId();
        $from = $request->query('from', date('Y-m-01'));
        $to = $request->query('to', date('Y-m-d') . ' 23:59:59');

        // --- Revenue ---
        $revenue = [];

        // Loan interest income: extract from loan_payment metadata
        $loanPayments = $this->db->fetchAll(
            "SELECT metadata FROM transactions
             WHERE tenant_id = ? AND type = 'loan_payment' AND status = 'completed'
               AND created_at BETWEEN ? AND ?",
            [$tenantId, $from, $to],
        );
        $interestIncome = 0.0;
        foreach ($loanPayments as $lp) {
            $meta = json_decode($lp['metadata'] ?? '{}', true);
            $interestIncome += (float) ($meta['interest'] ?? 0);
        }
        if ($interestIncome > 0) {
            $revenue[] = ['name' => 'Loan Interest Income', 'amount' => round($interestIncome, 2)];
        }

        // Fee income
        $feeIncome = (float) ($this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount), 0) FROM transactions
             WHERE tenant_id = ? AND type = 'fee' AND status = 'completed'
               AND created_at BETWEEN ? AND ?",
            [$tenantId, $from, $to],
        ) ?? 0);
        if ($feeIncome > 0) {
            $revenue[] = ['name' => 'Fee Income', 'amount' => round($feeIncome, 2)];
        }

        // Late fee income
        $lateFeeIncome = (float) ($this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount), 0) FROM transactions
             WHERE tenant_id = ? AND type = 'late_fee' AND status = 'completed'
               AND created_at BETWEEN ? AND ?",
            [$tenantId, $from, $to],
        ) ?? 0);
        if ($lateFeeIncome > 0) {
            $revenue[] = ['name' => 'Late Fee Income', 'amount' => round($lateFeeIncome, 2)];
        }

        $totalRevenue = array_sum(array_column($revenue, 'amount'));

        // --- Expenses ---
        $expenseRows = $this->db->fetchAll(
            "SELECT
                COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.category')), 'other') AS category,
                SUM(amount) AS amount
             FROM transactions
             WHERE tenant_id = ? AND type = 'expense' AND status = 'completed'
               AND created_at BETWEEN ? AND ?
             GROUP BY category
             ORDER BY amount DESC",
            [$tenantId, $from, $to],
        );
        $expenses = [];
        foreach ($expenseRows as $row) {
            $expenses[] = [
                'name'   => ucwords(str_replace('_', ' ', $row['category'])),
                'amount' => round((float) $row['amount'], 2),
            ];
        }
        $totalExpenses = array_sum(array_column($expenses, 'amount'));

        return Response::ok([
            'revenue'        => $revenue,
            'expenses'       => $expenses,
            'total_revenue'  => round($totalRevenue, 2),
            'total_expenses' => round($totalExpenses, 2),
            'net_income'     => round($totalRevenue - $totalExpenses, 2),
        ]);
    }

    /**
     * GET /api/v1/reports/balance-sheet
     *
     * Assets, liabilities, and equity computed from live data.
     */
    public function balanceSheet(Request $request): Response
    {
        $tenantId = $this->db->getTenantId();

        // --- Assets ---
        // Cash & equivalents: total deposits held (sum of all account balances)
        $cashOnHand = (float) ($this->db->fetchColumn(
            "SELECT COALESCE(SUM(balance), 0) FROM accounts
             WHERE tenant_id = ? AND status = 'active'",
            [$tenantId],
        ) ?? 0);

        // Loan receivables: outstanding loan balances
        $loanReceivables = (float) ($this->db->fetchColumn(
            "SELECT COALESCE(SUM(outstanding_balance), 0) FROM loans
             WHERE tenant_id = ? AND status IN ('active', 'delinquent', 'approved')",
            [$tenantId],
        ) ?? 0);

        $assets = [
            ['name' => 'Cash & Deposits', 'balance' => round($cashOnHand, 2)],
            ['name' => 'Loan Receivables', 'balance' => round($loanReceivables, 2)],
        ];
        $totalAssets = round($cashOnHand + $loanReceivables, 2);

        // --- Liabilities ---
        // Member deposits: money owed back to members
        $memberDeposits = $cashOnHand; // same as cash — member balances are our liability

        $liabilities = [
            ['name' => 'Member Deposits', 'balance' => round($memberDeposits, 2)],
        ];
        $totalLiabilities = round($memberDeposits, 2);

        // --- Equity ---
        // Retained earnings: cumulative net income (all-time revenue - expenses)
        $allTimeRevenue = 0.0;

        // Interest income from loan payments
        $loanPayments = $this->db->fetchAll(
            "SELECT metadata FROM transactions
             WHERE tenant_id = ? AND type = 'loan_payment' AND status = 'completed'",
            [$tenantId],
        );
        foreach ($loanPayments as $lp) {
            $meta = json_decode($lp['metadata'] ?? '{}', true);
            $allTimeRevenue += (float) ($meta['interest'] ?? 0);
        }

        // Fee + late fee income
        $allTimeRevenue += (float) ($this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount), 0) FROM transactions
             WHERE tenant_id = ? AND type IN ('fee', 'late_fee') AND status = 'completed'",
            [$tenantId],
        ) ?? 0);

        $allTimeExpenses = (float) ($this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount), 0) FROM transactions
             WHERE tenant_id = ? AND type = 'expense' AND status = 'completed'",
            [$tenantId],
        ) ?? 0);

        $retainedEarnings = round($allTimeRevenue - $allTimeExpenses, 2);

        $equity = [
            ['name' => 'Retained Earnings', 'balance' => $retainedEarnings],
        ];
        $totalEquity = $retainedEarnings;

        return Response::ok([
            'assets'           => $assets,
            'liabilities'      => $liabilities,
            'equity'           => $equity,
            'total_assets'     => $totalAssets,
            'total_liabilities' => $totalLiabilities,
            'total_equity'     => $totalEquity,
            'balanced'         => abs($totalAssets - ($totalLiabilities + $totalEquity)) < 0.01,
        ]);
    }

    // ------------------------------------------------------------------
    // Journal Entry & Chart of Accounts
    // ------------------------------------------------------------------

    /**
     * POST /api/v1/reports/journal-entry
     *
     * Create a manual journal entry with balanced debit/credit lines.
     */
    public function createJournalEntry(Request $request): Response
    {
        $validator = new Validator($request->all(), [
            'description' => 'required|string|max:500',
            'lines'       => 'required|array|min:2',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        $lines = $request->input('lines', []);
        if (!is_array($lines) || count($lines) < 2) {
            return Response::error('At least 2 journal entry lines are required', 422);
        }

        // Validate each line
        foreach ($lines as $i => $line) {
            if (empty($line['account_code']) || !is_string($line['account_code'])) {
                return Response::error("Line {$i}: account_code is required", 422);
            }
            $debit  = (float) ($line['debit'] ?? 0);
            $credit = (float) ($line['credit'] ?? 0);
            if ($debit <= 0 && $credit <= 0) {
                return Response::error("Line {$i}: each line must have either debit or credit > 0", 422);
            }
            if ($debit > 0 && $credit > 0) {
                return Response::error("Line {$i}: a line cannot have both debit and credit > 0", 422);
            }
        }

        try {
            $transactionId = 'manual-' . $this->generateUuid();
            $entryId = $this->ledger->createEntry(
                $transactionId,
                $request->input('description'),
                $lines,
            );

            return Response::created(['id' => $entryId], 'Journal entry created successfully');
        } catch (\RuntimeException $e) {
            $code = $e->getCode() >= 400 ? $e->getCode() : 422;
            return Response::error($e->getMessage(), $code);
        }
    }

    /**
     * GET /api/v1/reports/chart-of-accounts
     *
     * List all GL accounts for the current tenant with balances.
     */
    public function chartOfAccounts(Request $request): Response
    {
        $tenantId = $this->db->getTenantId();

        $accounts = $this->db->fetchAll(
            'SELECT id, code, name, type, balance, created_at, updated_at
             FROM gl_accounts
             WHERE tenant_id = ?
             ORDER BY code',
            [$tenantId]
        );

        return Response::ok($accounts);
    }

    /**
     * POST /api/v1/reports/chart-of-accounts
     *
     * Create a new GL account.
     */
    public function createGlAccount(Request $request): Response
    {
        $validator = new Validator($request->all(), [
            'code' => 'required|string|max:10',
            'name' => 'required|string|max:100',
            'type' => 'required|in:asset,liability,equity,revenue,expense',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        $code = $request->input('code');
        if (!preg_match('/^[a-zA-Z0-9]+$/', $code)) {
            return Response::error('Account code must be alphanumeric', 422);
        }

        try {
            // Check if account code already exists
            $existing = $this->ledger->getGlAccount($code);
            if ($existing) {
                return Response::error('An account with this code already exists', 409);
            }

            $account = $this->ledger->createGlAccount(
                $code,
                $request->input('name'),
                $request->input('type'),
            );

            return Response::created($account, 'GL account created successfully');
        } catch (\RuntimeException $e) {
            $code = $e->getCode() >= 400 ? $e->getCode() : 422;
            return Response::error($e->getMessage(), $code);
        }
    }

    // ------------------------------------------------------------------
    // Growth & Analytics
    // ------------------------------------------------------------------

    /**
     * GET /api/v1/reports/member-growth
     *
     * Monthly new member and account trends.
     */
    public function memberGrowth(Request $request): Response
    {
        $tenantId = $this->db->getTenantId();
        $from = $request->query('from', date('Y-01-01'));
        $to = $request->query('to', date('Y-m-d') . ' 23:59:59');

        $members = $this->db->fetchAll(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') AS month,
                    COUNT(*) AS new_members
             FROM users
             WHERE tenant_id = ? AND created_at BETWEEN ? AND ?
             GROUP BY DATE_FORMAT(created_at, '%Y-%m')
             ORDER BY month",
            [$tenantId, $from, $to]
        );

        $accounts = $this->db->fetchAll(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') AS month,
                    account_type,
                    COUNT(*) AS new_accounts
             FROM accounts
             WHERE tenant_id = ? AND created_at BETWEEN ? AND ?
             GROUP BY DATE_FORMAT(created_at, '%Y-%m'), account_type
             ORDER BY month",
            [$tenantId, $from, $to]
        );

        $totalMembers = (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM users WHERE tenant_id = ?',
            [$tenantId]
        );

        $totalAccounts = (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM accounts WHERE tenant_id = ?',
            [$tenantId]
        );

        return Response::ok([
            'member_trend' => $members,
            'account_trend' => $accounts,
            'total_members' => $totalMembers,
            'total_accounts' => $totalAccounts,
        ]);
    }

    /**
     * GET /api/v1/reports/delinquency
     *
     * Loans past their next payment date.
     */
    public function delinquency(Request $request): Response
    {
        $tenantId = $this->db->getTenantId();

        $loans = $this->db->fetchAll(
            "SELECT l.id, l.loan_number, l.loan_type, l.principal_amount, l.outstanding_balance,
                    l.interest_rate, l.next_payment_date, l.monthly_payment,
                    DATEDIFF(CURDATE(), l.next_payment_date) AS days_past_due,
                    CONCAT(u.first_name, ' ', u.last_name) AS member_name, u.email
             FROM loans l
             JOIN users u ON u.id = l.user_id AND u.tenant_id = l.tenant_id
             WHERE l.tenant_id = ? AND l.status IN ('active', 'delinquent')
               AND l.next_payment_date < CURDATE()
             ORDER BY days_past_due DESC",
            [$tenantId]
        );

        $totalOverdue = array_sum(array_column($loans, 'outstanding_balance'));

        return Response::ok([
            'loans' => $loans,
            'total_count' => count($loans),
            'total_overdue_balance' => round((float) $totalOverdue, 2),
        ]);
    }

    // ------------------------------------------------------------------
    // CSV Export
    // ------------------------------------------------------------------

    /**
     * GET /api/v1/reports/export
     *
     * Export any report type as CSV.
     */
    public function export(Request $request): Response
    {
        $type = $request->query('type', 'summary');
        $from = $request->query('from', date('Y-m-01'));
        $to = $request->query('to', date('Y-m-d') . ' 23:59:59');
        $tenantId = $this->db->getTenantId();

        $csv = '';

        switch ($type) {
            case 'trial-balance':
                $tb = $this->ledger->trialBalance();
                $csv = "Account Code,Account Name,Type,Total Debits,Total Credits,Balance\n";
                foreach ($tb['accounts'] as $a) {
                    $csv .= implode(',', [
                        $a['code'], '"' . str_replace('"', '""', $a['name']) . '"',
                        $a['type'], $a['total_debits'], $a['total_credits'], $a['balance']
                    ]) . "\n";
                }
                $csv .= "\n,,,{$tb['total_debits']},{$tb['total_credits']},\n";
                break;

            case 'general-ledger':
                $entries = $this->db->fetchAll(
                    'SELECT je.*, jel.gl_account_code, jel.debit, jel.credit,
                            ga.name AS account_name
                     FROM journal_entries je
                     JOIN journal_entry_lines jel ON jel.journal_entry_id = je.id AND jel.tenant_id = je.tenant_id
                     LEFT JOIN gl_accounts ga ON ga.code = jel.gl_account_code AND ga.tenant_id = je.tenant_id
                     WHERE je.tenant_id = ? AND je.created_at BETWEEN ? AND ?
                     ORDER BY je.created_at DESC
                     LIMIT 10000',
                    [$tenantId, $from, $to]
                );
                $csv = "Date,Description,Account Code,Account Name,Debit,Credit\n";
                foreach ($entries as $e) {
                    $csv .= implode(',', [
                        $e['created_at'],
                        '"' . str_replace('"', '""', $e['description'] ?? '') . '"',
                        $e['gl_account_code'],
                        '"' . str_replace('"', '""', $e['account_name'] ?? '') . '"',
                        $e['debit'], $e['credit']
                    ]) . "\n";
                }
                break;

            case 'income-statement':
                $csv = "Category,Type,Amount\n";
                // Interest income
                $lpRows = $this->db->fetchAll(
                    "SELECT metadata FROM transactions
                     WHERE tenant_id = ? AND type = 'loan_payment' AND status = 'completed'
                       AND created_at BETWEEN ? AND ?",
                    [$tenantId, $from, $to],
                );
                $intInc = 0.0;
                foreach ($lpRows as $lp) {
                    $m = json_decode($lp['metadata'] ?? '{}', true);
                    $intInc += (float) ($m['interest'] ?? 0);
                }
                if ($intInc > 0) $csv .= "Loan Interest Income,Revenue,{$intInc}\n";
                $feeInc = (float) ($this->db->fetchColumn(
                    "SELECT COALESCE(SUM(amount),0) FROM transactions WHERE tenant_id=? AND type='fee' AND status='completed' AND created_at BETWEEN ? AND ?",
                    [$tenantId, $from, $to]) ?? 0);
                if ($feeInc > 0) $csv .= "Fee Income,Revenue,{$feeInc}\n";
                $lfInc = (float) ($this->db->fetchColumn(
                    "SELECT COALESCE(SUM(amount),0) FROM transactions WHERE tenant_id=? AND type='late_fee' AND status='completed' AND created_at BETWEEN ? AND ?",
                    [$tenantId, $from, $to]) ?? 0);
                if ($lfInc > 0) $csv .= "Late Fee Income,Revenue,{$lfInc}\n";
                $expRows = $this->db->fetchAll(
                    "SELECT COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata,'$.category')),'other') AS cat, SUM(amount) AS amt
                     FROM transactions WHERE tenant_id=? AND type='expense' AND status='completed' AND created_at BETWEEN ? AND ?
                     GROUP BY cat ORDER BY amt DESC",
                    [$tenantId, $from, $to],
                );
                foreach ($expRows as $er) {
                    $csv .= '"' . ucwords(str_replace('_', ' ', $er['cat'])) . '",Expense,' . round((float)$er['amt'], 2) . "\n";
                }
                $totalRev = $intInc + $feeInc + $lfInc;
                $totalExp = array_sum(array_column($expRows, 'amt'));
                $csv .= "\nTotal Revenue,,{$totalRev}\nTotal Expenses,,{$totalExp}\nNet Income,," . round($totalRev - $totalExp, 2) . "\n";
                break;

            case 'balance-sheet':
                $csv = "Category,Type,Balance\n";
                $cash = (float) ($this->db->fetchColumn(
                    "SELECT COALESCE(SUM(balance),0) FROM accounts WHERE tenant_id=? AND status='active'", [$tenantId]) ?? 0);
                $loanRec = (float) ($this->db->fetchColumn(
                    "SELECT COALESCE(SUM(outstanding_balance),0) FROM loans WHERE tenant_id=? AND status IN ('active','delinquent','approved')", [$tenantId]) ?? 0);
                $csv .= "Cash & Deposits,Asset," . round($cash, 2) . "\n";
                $csv .= "Loan Receivables,Asset," . round($loanRec, 2) . "\n";
                $csv .= "Member Deposits,Liability," . round($cash, 2) . "\n";
                $csv .= "Total Assets,," . round($cash + $loanRec, 2) . "\n";
                $csv .= "Total Liabilities,," . round($cash, 2) . "\n";
                break;

            case 'transactions':
                $rows = $this->db->fetchAll(
                    "SELECT t.*, CONCAT(u.first_name, ' ', u.last_name) AS member_name, a.account_number
                     FROM transactions t
                     LEFT JOIN accounts a ON a.id = t.account_id
                     LEFT JOIN users u ON u.id = a.user_id AND u.tenant_id = t.tenant_id
                     WHERE t.tenant_id = ? AND t.created_at BETWEEN ? AND ?
                     ORDER BY t.created_at DESC LIMIT 10000",
                    [$tenantId, $from, $to]
                );
                $csv = "Date,Reference,Type,Member,Account,Amount,Balance After,Status,Description\n";
                foreach ($rows as $r) {
                    $csv .= implode(',', [
                        $r['created_at'], $r['reference_number'] ?? '', $r['type'],
                        '"' . str_replace('"', '""', $r['member_name'] ?? '') . '"',
                        $r['account_number'] ?? '', $r['amount'], $r['balance_after'] ?? '',
                        $r['status'], '"' . str_replace('"', '""', $r['description'] ?? '') . '"'
                    ]) . "\n";
                }
                break;

            case 'loan-portfolio':
                $rows = $this->db->fetchAll(
                    "SELECT l.*, CONCAT(u.first_name, ' ', u.last_name) AS member_name
                     FROM loans l
                     LEFT JOIN users u ON u.id = l.user_id AND u.tenant_id = l.tenant_id
                     WHERE l.tenant_id = ?
                     ORDER BY l.created_at DESC LIMIT 10000",
                    [$tenantId]
                );
                $csv = "Loan #,Member,Type,Principal,Rate,Term,Outstanding,Monthly Payment,Status,Next Payment,Created\n";
                foreach ($rows as $r) {
                    $csv .= implode(',', [
                        $r['loan_number'], '"' . str_replace('"', '""', $r['member_name'] ?? '') . '"',
                        $r['loan_type'], $r['principal_amount'], $r['interest_rate'], $r['term_months'],
                        $r['outstanding_balance'], $r['monthly_payment'], $r['status'],
                        $r['next_payment_date'] ?? '', $r['created_at']
                    ]) . "\n";
                }
                break;

            case 'delinquency':
                $rows = $this->db->fetchAll(
                    "SELECT l.loan_number, l.loan_type, l.outstanding_balance, l.monthly_payment,
                            l.next_payment_date, DATEDIFF(CURDATE(), l.next_payment_date) AS days_past_due,
                            CONCAT(u.first_name, ' ', u.last_name) AS member_name, u.email
                     FROM loans l
                     JOIN users u ON u.id = l.user_id AND u.tenant_id = l.tenant_id
                     WHERE l.tenant_id = ? AND l.status IN ('active', 'delinquent')
                       AND l.next_payment_date < CURDATE()
                     ORDER BY days_past_due DESC",
                    [$tenantId]
                );
                $csv = "Loan #,Member,Email,Type,Outstanding,Monthly Payment,Next Payment Due,Days Past Due\n";
                foreach ($rows as $r) {
                    $csv .= implode(',', [
                        $r['loan_number'], '"' . str_replace('"', '""', $r['member_name']) . '"',
                        $r['email'], $r['loan_type'], $r['outstanding_balance'],
                        $r['monthly_payment'], $r['next_payment_date'], $r['days_past_due']
                    ]) . "\n";
                }
                break;

            case 'member-growth':
                $rows = $this->db->fetchAll(
                    "SELECT DATE_FORMAT(created_at, '%Y-%m') AS month, COUNT(*) AS new_members
                     FROM users WHERE tenant_id = ? AND created_at BETWEEN ? AND ?
                     GROUP BY DATE_FORMAT(created_at, '%Y-%m') ORDER BY month",
                    [$tenantId, $from, $to]
                );
                $csv = "Month,New Members\n";
                foreach ($rows as $r) {
                    $csv .= "{$r['month']},{$r['new_members']}\n";
                }
                break;

            default:
                return Response::error('Unknown export type', 400);
        }

        return Response::raw($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $type . '-report-' . date('Y-m-d') . '.csv"',
        ]);
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
