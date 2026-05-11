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
    // ISO 20022 camt.053 Bank-to-Customer Statement Export
    // ------------------------------------------------------------------

    /**
     * GET /api/v1/reports/camt053
     *
     * Generate an ISO 20022 camt.053.001.08 XML statement for an account
     * over a given date range.
     */
    public function camt053(Request $request): Response
    {
        $accountId = $request->query('account_id');
        $from = $request->query('from', date('Y-m-01'));
        $to = $request->query('to', date('Y-m-d'));

        if (!$accountId) {
            return Response::error('account_id is required', 422);
        }

        $tenantId = $this->db->getTenantId();

        $account = $this->db->fetchOne(
            "SELECT a.*, CONCAT(u.first_name, ' ', u.last_name) AS holder_name, u.email
             FROM accounts a JOIN users u ON u.id = a.user_id
             WHERE a.id = ? AND a.tenant_id = ?",
            [$accountId, $tenantId],
        );

        if (!$account) {
            return Response::error('Account not found', 404);
        }

        $tenant = $this->db->fetchOne('SELECT name FROM tenants WHERE id = ?', [$tenantId]);
        $cuName = $tenant['name'] ?? 'SyncCU';

        $transactions = $this->db->fetchAll(
            "SELECT t.*, CONCAT(u.first_name, ' ', u.last_name) AS member_name
             FROM transactions t
             LEFT JOIN accounts a ON a.id = t.account_id
             LEFT JOIN users u ON u.id = a.user_id
             WHERE t.tenant_id = ? AND t.account_id = ?
               AND DATE(t.created_at) >= ? AND DATE(t.created_at) <= ?
             ORDER BY t.created_at ASC",
            [$tenantId, $accountId, $from, $to],
        );

        $xml = $this->buildCamt053Xml($account, $transactions, $cuName, $from, $to);

        return Response::raw($xml, 200, [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="camt053-' . $account['account_number'] . '-' . date('Ymd') . '.xml"',
        ]);
    }

    private function buildCamt053Xml(array $account, array $transactions, string $cuName, string $from, string $to): string
    {
        $msgId = 'STMT-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(4)));
        $stmtId = 'STMT-' . $account['account_number'] . '-' . date('Ymd');
        $creationDateTime = date('c');
        $currency = $account['currency'] ?? 'USD';

        $totalCredits = 0.0;
        $totalDebits = 0.0;
        $creditCount = 0;
        $debitCount = 0;
        $creditTypes = ['deposit', 'interest', 'adjustment', 'loan_disbursement'];

        foreach ($transactions as $t) {
            $amt = (float) $t['amount'];
            if (in_array($t['type'], $creditTypes) && $amt > 0) {
                $totalCredits += $amt;
                $creditCount++;
            } else {
                $totalDebits += abs($amt);
                $debitCount++;
            }
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.08" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' . "\n";
        $xml .= '  <BkToCstmrStmt>' . "\n";

        // Group Header
        $xml .= '    <GrpHdr>' . "\n";
        $xml .= '      <MsgId>' . $this->xmlEscape($msgId) . '</MsgId>' . "\n";
        $xml .= '      <CreDtTm>' . $creationDateTime . '</CreDtTm>' . "\n";
        $xml .= '      <MsgPgntn><PgNb>1</PgNb><LastPgInd>true</LastPgInd></MsgPgntn>' . "\n";
        $xml .= '    </GrpHdr>' . "\n";

        // Statement
        $xml .= '    <Stmt>' . "\n";
        $xml .= '      <Id>' . $this->xmlEscape($stmtId) . '</Id>' . "\n";
        $xml .= '      <ElctrncSeqNb>1</ElctrncSeqNb>' . "\n";
        $xml .= '      <CreDtTm>' . $creationDateTime . '</CreDtTm>' . "\n";
        $xml .= '      <FrToDt>' . "\n";
        $xml .= '        <FrDtTm>' . $from . 'T00:00:00</FrDtTm>' . "\n";
        $xml .= '        <ToDtTm>' . $to . 'T23:59:59</ToDtTm>' . "\n";
        $xml .= '      </FrToDt>' . "\n";

        // Account identification
        $xml .= '      <Acct>' . "\n";
        $xml .= '        <Id><Othr><Id>' . $this->xmlEscape($account['account_number']) . '</Id></Othr></Id>' . "\n";
        $xml .= '        <Ccy>' . $currency . '</Ccy>' . "\n";
        $xml .= '        <Ownr><Nm>' . $this->xmlEscape($account['holder_name'] ?? '') . '</Nm></Ownr>' . "\n";
        $xml .= '        <Svcr><FinInstnId><Nm>' . $this->xmlEscape($cuName) . '</Nm></FinInstnId></Svcr>' . "\n";
        $xml .= '      </Acct>' . "\n";

        // Balance (closing)
        $xml .= '      <Bal>' . "\n";
        $xml .= '        <Tp><CdOrPrtry><Cd>CLBD</Cd></CdOrPrtry></Tp>' . "\n";
        $xml .= '        <Amt Ccy="' . $currency . '">' . number_format((float) $account['balance'], 2, '.', '') . '</Amt>' . "\n";
        $xml .= '        <CdtDbtInd>' . ((float) $account['balance'] >= 0 ? 'CRDT' : 'DBIT') . '</CdtDbtInd>' . "\n";
        $xml .= '        <Dt><Dt>' . $to . '</Dt></Dt>' . "\n";
        $xml .= '      </Bal>' . "\n";

        // Transaction summary
        $xml .= '      <TxsSummry>' . "\n";
        $xml .= '        <TtlNtries><NbOfNtries>' . count($transactions) . '</NbOfNtries></TtlNtries>' . "\n";
        $xml .= '        <TtlCdtNtries><NbOfNtries>' . $creditCount . '</NbOfNtries><Sum>' . number_format($totalCredits, 2, '.', '') . '</Sum></TtlCdtNtries>' . "\n";
        $xml .= '        <TtlDbtNtries><NbOfNtries>' . $debitCount . '</NbOfNtries><Sum>' . number_format($totalDebits, 2, '.', '') . '</Sum></TtlDbtNtries>' . "\n";
        $xml .= '      </TxsSummry>' . "\n";

        // Individual entries
        foreach ($transactions as $t) {
            $amt = abs((float) $t['amount']);
            $isCredit = in_array($t['type'], $creditTypes) && (float) $t['amount'] > 0;
            $cdtDbt = $isCredit ? 'CRDT' : 'DBIT';
            $statusCode = match ($t['status']) {
                'completed' => 'BOOK',
                'pending'   => 'PDNG',
                'failed'    => 'INFO',
                'reversed'  => 'INFO',
                default     => 'BOOK',
            };

            $xml .= '      <Ntry>' . "\n";
            $xml .= '        <Amt Ccy="' . $currency . '">' . number_format($amt, 2, '.', '') . '</Amt>' . "\n";
            $xml .= '        <CdtDbtInd>' . $cdtDbt . '</CdtDbtInd>' . "\n";
            $xml .= '        <Sts><Cd>' . $statusCode . '</Cd></Sts>' . "\n";
            $xml .= '        <BookgDt><Dt>' . substr($t['created_at'], 0, 10) . '</Dt></BookgDt>' . "\n";
            $xml .= '        <ValDt><Dt>' . ($t['settlement_date'] ?? substr($t['created_at'], 0, 10)) . '</Dt></ValDt>' . "\n";
            $xml .= '        <AcctSvcrRef>' . $this->xmlEscape($t['reference_number']) . '</AcctSvcrRef>' . "\n";

            $xml .= '        <NtryDtls><TxDtls>' . "\n";

            // References
            $xml .= '          <Refs>' . "\n";
            if (!empty($t['end_to_end_id'])) {
                $xml .= '            <EndToEndId>' . $this->xmlEscape($t['end_to_end_id']) . '</EndToEndId>' . "\n";
            }
            if (!empty($t['instruction_id'])) {
                $xml .= '            <InstrId>' . $this->xmlEscape($t['instruction_id']) . '</InstrId>' . "\n";
            }
            $xml .= '            <TxId>' . $this->xmlEscape($t['reference_number']) . '</TxId>' . "\n";
            $xml .= '          </Refs>' . "\n";

            $xml .= '          <AmtDtls><TxAmt><Amt Ccy="' . $currency . '">' . number_format($amt, 2, '.', '') . '</Amt></TxAmt></AmtDtls>' . "\n";

            // Related parties
            if (!empty($t['debtor_name'])) {
                $xml .= '          <RltdPties><Dbtr><Pty><Nm>' . $this->xmlEscape($t['debtor_name']) . '</Nm></Pty>';
                if (!empty($t['debtor_account'])) {
                    $xml .= '<AcctId><Othr><Id>' . $this->xmlEscape($t['debtor_account']) . '</Id></Othr></AcctId>';
                }
                $xml .= '</Dbtr></RltdPties>' . "\n";
            }
            if (!empty($t['creditor_name'])) {
                $xml .= '          <RltdPties><Cdtr><Pty><Nm>' . $this->xmlEscape($t['creditor_name']) . '</Nm></Pty>';
                if (!empty($t['creditor_account'])) {
                    $xml .= '<AcctId><Othr><Id>' . $this->xmlEscape($t['creditor_account']) . '</Id></Othr></AcctId>';
                }
                $xml .= '</Cdtr></RltdPties>' . "\n";
            }

            // Purpose
            if (!empty($t['purpose_code'])) {
                $xml .= '          <Purp><Cd>' . $this->xmlEscape($t['purpose_code']) . '</Cd></Purp>' . "\n";
            }

            // Remittance
            if (!empty($t['remittance_info'])) {
                $xml .= '          <RmtInf><Ustrd>' . $this->xmlEscape($t['remittance_info']) . '</Ustrd></RmtInf>' . "\n";
            } elseif (!empty($t['description'])) {
                $xml .= '          <RmtInf><Ustrd>' . $this->xmlEscape($t['description']) . '</Ustrd></RmtInf>' . "\n";
            }

            $xml .= '        </TxDtls></NtryDtls>' . "\n";
            $xml .= '      </Ntry>' . "\n";
        }

        $xml .= '    </Stmt>' . "\n";
        $xml .= '  </BkToCstmrStmt>' . "\n";
        $xml .= '</Document>' . "\n";

        return $xml;
    }

    private function xmlEscape(string $str): string
    {
        return htmlspecialchars($str, ENT_XML1 | ENT_QUOTES, 'UTF-8');
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
