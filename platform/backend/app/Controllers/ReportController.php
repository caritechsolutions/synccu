<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

/**
 * Report REST controller.
 *
 * Financial reports computed on-the-fly from the transactions table,
 * member growth analytics, delinquency views, and CSV export.
 */
final class ReportController
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ------------------------------------------------------------------
    // Financial Reports
    // ------------------------------------------------------------------

    /**
     * GET /api/v1/reports/trial-balance
     *
     * Aggregated debit/credit summary by transaction type, computed from
     * the transactions table.
     */
    public function trialBalance(Request $request): Response
    {
        $tenantId = $this->db->getTenantId();

        // Deposits = debits to Cash, credits to Member Liabilities
        $deposits = (float) ($this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount),0) FROM transactions WHERE tenant_id = ? AND type = 'deposit' AND status = 'completed'",
            [$tenantId],
        ) ?? 0);

        // Withdrawals = debits to Member Liabilities, credits to Cash
        $withdrawals = (float) ($this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount),0) FROM transactions WHERE tenant_id = ? AND type = 'withdrawal' AND status = 'completed'",
            [$tenantId],
        ) ?? 0);

        // Loan disbursements = debits to Loan Receivable, credits to Cash
        $disbursements = (float) ($this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount),0) FROM transactions WHERE tenant_id = ? AND type = 'loan_disbursement' AND status = 'completed'",
            [$tenantId],
        ) ?? 0);

        // Loan payments: principal reduces Loan Receivable, interest is revenue
        $loanPayments = $this->db->fetchAll(
            "SELECT amount, metadata FROM transactions WHERE tenant_id = ? AND type = 'loan_payment' AND status = 'completed'",
            [$tenantId],
        );
        $principalPayments = 0.0;
        $interestPayments = 0.0;
        foreach ($loanPayments as $lp) {
            $meta = json_decode($lp['metadata'] ?? '{}', true);
            $interestPayments += (float) ($meta['interest'] ?? 0);
            $principalPayments += (float) ($meta['principal'] ?? ((float) $lp['amount'] - (float) ($meta['interest'] ?? 0)));
        }

        // Fees & late fees = revenue
        $fees = (float) ($this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount),0) FROM transactions WHERE tenant_id = ? AND type IN ('fee','late_fee') AND status = 'completed'",
            [$tenantId],
        ) ?? 0);

        // Expenses
        $expenses = (float) ($this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount),0) FROM transactions WHERE tenant_id = ? AND type = 'expense' AND status = 'completed'",
            [$tenantId],
        ) ?? 0);

        $accounts = [
            ['name' => 'Cash & Deposits',      'type' => 'asset',     'debits' => round($deposits + $principalPayments + $interestPayments + $fees, 2), 'credits' => round($withdrawals + $disbursements + $expenses, 2)],
            ['name' => 'Loan Receivables',      'type' => 'asset',     'debits' => round($disbursements, 2), 'credits' => round($principalPayments, 2)],
            ['name' => 'Member Deposits',       'type' => 'liability', 'debits' => round($withdrawals, 2),   'credits' => round($deposits, 2)],
            ['name' => 'Loan Interest Income',  'type' => 'revenue',   'debits' => 0, 'credits' => round($interestPayments, 2)],
            ['name' => 'Fee & Late Fee Income', 'type' => 'revenue',   'debits' => 0, 'credits' => round($fees, 2)],
            ['name' => 'Operating Expenses',    'type' => 'expense',   'debits' => round($expenses, 2), 'credits' => 0],
        ];

        // Compute balances
        foreach ($accounts as &$a) {
            $a['balance'] = round($a['debits'] - $a['credits'], 2);
        }
        unset($a);

        $totalDebits = round(array_sum(array_column($accounts, 'debits')), 2);
        $totalCredits = round(array_sum(array_column($accounts, 'credits')), 2);

        return Response::ok([
            'accounts'      => $accounts,
            'total_debits'  => $totalDebits,
            'total_credits' => $totalCredits,
            'balanced'      => abs($totalDebits - $totalCredits) < 0.01,
        ]);
    }

    /**
     * GET /api/v1/reports/general-ledger
     *
     * All transactions displayed as ledger entries, computed from the
     * transactions table.
     */
    public function generalLedger(Request $request): Response
    {
        $tenantId = $this->db->getTenantId();
        $from = $request->query('from', date('Y-m-01'));
        $to = $this->normalizeToDate($request->query('to', date('Y-m-d')));
        $page = max(1, (int) ($request->query('page', '1')));
        $perPage = min(100, max(1, (int) ($request->query('per_page', '50'))));
        $offset = ($page - 1) * $perPage;

        $items = $this->db->fetchAll(
            "SELECT t.id, t.reference_number, t.type, t.amount, t.description,
                    t.created_at, t.metadata,
                    a.account_number,
                    CONCAT(u.first_name, ' ', u.last_name) AS member_name
             FROM transactions t
             LEFT JOIN accounts a ON a.id = t.account_id
             LEFT JOIN users u ON u.id = t.processed_by AND u.tenant_id = t.tenant_id
             WHERE t.tenant_id = ? AND t.created_at BETWEEN ? AND ?
             ORDER BY t.created_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            [$tenantId, $from, $to],
        );

        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM transactions
             WHERE tenant_id = ? AND created_at BETWEEN ? AND ?",
            [$tenantId, $from, $to],
        );

        // Build ledger-style lines for each transaction
        foreach ($items as &$item) {
            $amount = (float) $item['amount'];
            $meta = json_decode($item['metadata'] ?? '{}', true);
            $lines = [];

            switch ($item['type']) {
                case 'deposit':
                    $lines[] = ['account' => 'Cash & Deposits', 'debit' => $amount, 'credit' => 0];
                    $lines[] = ['account' => 'Member Deposits', 'debit' => 0, 'credit' => $amount];
                    break;
                case 'withdrawal':
                    $lines[] = ['account' => 'Member Deposits', 'debit' => $amount, 'credit' => 0];
                    $lines[] = ['account' => 'Cash & Deposits', 'debit' => 0, 'credit' => $amount];
                    break;
                case 'transfer':
                    $lines[] = ['account' => 'Member Deposits (from)', 'debit' => $amount, 'credit' => 0];
                    $lines[] = ['account' => 'Member Deposits (to)', 'debit' => 0, 'credit' => $amount];
                    break;
                case 'loan_disbursement':
                    $lines[] = ['account' => 'Loan Receivables', 'debit' => $amount, 'credit' => 0];
                    $lines[] = ['account' => 'Cash & Deposits', 'debit' => 0, 'credit' => $amount];
                    break;
                case 'loan_payment':
                    $principal = (float) ($meta['principal'] ?? $amount);
                    $interest = (float) ($meta['interest'] ?? 0);
                    if ($principal > 0) {
                        $lines[] = ['account' => 'Cash & Deposits', 'debit' => $principal, 'credit' => 0];
                        $lines[] = ['account' => 'Loan Receivables', 'debit' => 0, 'credit' => $principal];
                    }
                    if ($interest > 0) {
                        $lines[] = ['account' => 'Cash & Deposits', 'debit' => $interest, 'credit' => 0];
                        $lines[] = ['account' => 'Loan Interest Income', 'debit' => 0, 'credit' => $interest];
                    }
                    break;
                case 'fee':
                case 'late_fee':
                    $lines[] = ['account' => 'Cash & Deposits', 'debit' => $amount, 'credit' => 0];
                    $lines[] = ['account' => 'Fee Income', 'debit' => 0, 'credit' => $amount];
                    break;
                case 'expense':
                    $cat = ucwords(str_replace('_', ' ', $meta['category'] ?? 'Operating'));
                    $lines[] = ['account' => $cat . ' Expense', 'debit' => $amount, 'credit' => 0];
                    $lines[] = ['account' => 'Cash & Deposits', 'debit' => 0, 'credit' => $amount];
                    break;
                default:
                    $lines[] = ['account' => 'Other', 'debit' => $amount, 'credit' => 0];
                    $lines[] = ['account' => 'Other', 'debit' => 0, 'credit' => $amount];
            }

            $item['lines'] = $lines;
        }
        unset($item);

        return Response::paginated($items, $total, $page, $perPage);
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
        $to = $this->normalizeToDate($request->query('to', date('Y-m-d')));

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
        $to = $this->normalizeToDate($request->query('to', date('Y-m-d')));

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
        $to = $this->normalizeToDate($request->query('to', date('Y-m-d')));
        $tenantId = $this->db->getTenantId();

        $csv = '';

        switch ($type) {
            case 'trial-balance':
                $tbBody = $this->trialBalance($request)->getBody();
                $tbData = $tbBody['data'] ?? $tbBody;
                $csv = "Account Name,Type,Debits,Credits,Balance\n";
                foreach ($tbData['accounts'] ?? [] as $a) {
                    $csv .= implode(',', [
                        '"' . str_replace('"', '""', $a['name']) . '"',
                        $a['type'], $a['debits'], $a['credits'], $a['balance']
                    ]) . "\n";
                }
                $td = $tbData['total_debits'] ?? 0;
                $tc = $tbData['total_credits'] ?? 0;
                $csv .= "\nTotals,,{$td},{$tc},\n";
                break;

            case 'general-ledger':
                $txns = $this->db->fetchAll(
                    "SELECT t.id, t.reference_number, t.type, t.amount, t.description,
                            t.created_at, t.metadata,
                            a.account_number
                     FROM transactions t
                     LEFT JOIN accounts a ON a.id = t.account_id
                     WHERE t.tenant_id = ? AND t.created_at BETWEEN ? AND ?
                     ORDER BY t.created_at DESC
                     LIMIT 10000",
                    [$tenantId, $from, $to]
                );
                $csv = "Date,Reference,Type,Description,Account,Debit,Credit\n";
                foreach ($txns as $txn) {
                    $amount = (float) $txn['amount'];
                    $meta = json_decode($txn['metadata'] ?? '{}', true);
                    $lines = [];
                    switch ($txn['type']) {
                        case 'deposit':
                            $lines[] = ['Cash & Deposits', $amount, 0];
                            $lines[] = ['Member Deposits', 0, $amount];
                            break;
                        case 'withdrawal':
                            $lines[] = ['Member Deposits', $amount, 0];
                            $lines[] = ['Cash & Deposits', 0, $amount];
                            break;
                        case 'loan_disbursement':
                            $lines[] = ['Loan Receivables', $amount, 0];
                            $lines[] = ['Cash & Deposits', 0, $amount];
                            break;
                        case 'loan_payment':
                            $principal = (float) ($meta['principal'] ?? $amount);
                            $interest = (float) ($meta['interest'] ?? 0);
                            if ($principal > 0) { $lines[] = ['Cash & Deposits', $principal, 0]; $lines[] = ['Loan Receivables', 0, $principal]; }
                            if ($interest > 0) { $lines[] = ['Cash & Deposits', $interest, 0]; $lines[] = ['Loan Interest Income', 0, $interest]; }
                            break;
                        case 'fee': case 'late_fee':
                            $lines[] = ['Cash & Deposits', $amount, 0];
                            $lines[] = ['Fee Income', 0, $amount];
                            break;
                        case 'expense':
                            $cat = ucwords(str_replace('_', ' ', $meta['category'] ?? 'Operating'));
                            $lines[] = [$cat . ' Expense', $amount, 0];
                            $lines[] = ['Cash & Deposits', 0, $amount];
                            break;
                        default:
                            $lines[] = ['Other', $amount, 0];
                            $lines[] = ['Other', 0, $amount];
                    }
                    foreach ($lines as $ln) {
                        $csv .= implode(',', [
                            $txn['created_at'], $txn['reference_number'] ?? '', $txn['type'],
                            '"' . str_replace('"', '""', $txn['description'] ?? '') . '"',
                            '"' . $ln[0] . '"', $ln[1], $ln[2]
                        ]) . "\n";
                    }
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

    private function normalizeToDate(string $to): string
    {
        if (strlen($to) === 10) {
            return $to . ' 23:59:59';
        }
        return $to;
    }

}
