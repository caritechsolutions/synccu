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
                case 'interest':
                    // Interest / dividends credited to a member account
                    $lines[] = ['account' => 'Interest & Dividends Paid', 'debit' => $amount, 'credit' => 0];
                    $lines[] = ['account' => 'Member Deposits', 'debit' => 0, 'credit' => $amount];
                    break;
                case 'external_transfer':
                case 'network_transfer':
                    $lines[] = ['account' => 'Member Deposits', 'debit' => $amount, 'credit' => 0];
                    $lines[] = ['account' => 'Cash & Deposits', 'debit' => 0, 'credit' => $amount];
                    break;
                case 'adjustment':
                    if ($amount >= 0) {
                        $lines[] = ['account' => 'Adjustments / Suspense', 'debit' => $amount, 'credit' => 0];
                        $lines[] = ['account' => 'Member Deposits', 'debit' => 0, 'credit' => $amount];
                    } else {
                        $lines[] = ['account' => 'Member Deposits', 'debit' => -$amount, 'credit' => 0];
                        $lines[] = ['account' => 'Adjustments / Suspense', 'debit' => 0, 'credit' => -$amount];
                    }
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

        // Interest / dividends credited to member accounts are a cost of funds
        $memberInterest = (float) ($this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount), 0) FROM transactions
             WHERE tenant_id = ? AND type = 'interest' AND status = 'completed'
               AND created_at BETWEEN ? AND ?",
            [$tenantId, $from, $to],
        ) ?? 0);
        if ($memberInterest > 0) {
            $expenses[] = ['name' => 'Interest & Dividends Paid to Members', 'amount' => round($memberInterest, 2)];
        }
        $totalExpenses = array_sum(array_column($expenses, 'amount'));

        // Activity context for the period
        $loanPaymentCount = count($loanPayments);
        $principalCollected = 0.0;
        foreach ($loanPayments as $lp) {
            $meta = json_decode($lp['metadata'] ?? '{}', true);
            $principalCollected += (float) ($meta['principal'] ?? 0);
        }

        return Response::ok([
            'revenue'        => $revenue,
            'expenses'       => $expenses,
            'total_revenue'  => round($totalRevenue, 2),
            'total_expenses' => round($totalExpenses, 2),
            'net_income'     => round($totalRevenue - $totalExpenses, 2),
            'context'        => [
                'loan_payments'       => $loanPaymentCount,
                'principal_collected' => round($principalCollected, 2),
            ],
        ]);
    }

    /**
     * GET /api/v1/reports/balance-sheet
     *
     * Assets, liabilities, and equity computed from live data.
     */
    public function balanceSheet(Request $request): Response
    {
        return Response::ok($this->buildBalanceSheet());
    }

    /**
     * Assets = cash held in fund locations (vault, drawers, bank) + loans
     * receivable. Liabilities = member deposits (savings/checking). Equity =
     * member shares + retained earnings. Any difference (typically opening
     * balances loaded at go-live) is shown explicitly rather than hidden.
     */
    private function buildBalanceSheet(): array
    {
        $tenantId = $this->db->getTenantId();

        // --- Assets: cash by location ---
        $cashByType = ['vault' => 0.0, 'drawer' => 0.0, 'bank_account' => 0.0];
        $cashCounts = ['vault' => 0, 'drawer' => 0, 'bank_account' => 0];
        try {
            $rows = $this->db->fetchAll(
                "SELECT type, COALESCE(SUM(balance), 0) AS total, COUNT(*) AS n
                 FROM fund_locations WHERE tenant_id = ? AND status = 'active' GROUP BY type",
                [$tenantId],
            );
            foreach ($rows as $r) {
                $cashByType[$r['type']] = (float) $r['total'];
                $cashCounts[$r['type']] = (int) $r['n'];
            }
        } catch (\Throwable) {
            // Cash management not set up yet
        }
        $cashOnHand = array_sum($cashByType);

        // Loans receivable: disbursed, unpaid principal
        $loanReceivables = (float) ($this->db->fetchColumn(
            "SELECT COALESCE(SUM(outstanding_balance), 0) FROM loans
             WHERE tenant_id = ? AND status IN ('active', 'delinquent', 'default')",
            [$tenantId],
        ) ?? 0);
        $loanCount = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM loans WHERE tenant_id = ? AND status IN ('active', 'delinquent', 'default')",
            [$tenantId],
        );

        $assets = [
            ['name' => 'Cash — Vault / Safe',     'balance' => round($cashByType['vault'], 2),        'note' => $cashCounts['vault'] . ' location(s)', 'group' => 'Cash'],
            ['name' => 'Cash — Teller Drawers',   'balance' => round($cashByType['drawer'], 2),       'note' => $cashCounts['drawer'] . ' drawer(s)', 'group' => 'Cash'],
            ['name' => 'Cash — Bank Accounts',    'balance' => round($cashByType['bank_account'], 2), 'note' => $cashCounts['bank_account'] . ' account(s)', 'group' => 'Cash'],
            ['name' => 'Loans Receivable',        'balance' => round($loanReceivables, 2),            'note' => $loanCount . ' active loan(s)', 'group' => 'Loans'],
        ];
        $totalAssets = round($cashOnHand + $loanReceivables, 2);

        // --- Liabilities: member deposits ---
        $byType = [];
        $rows = $this->db->fetchAll(
            "SELECT account_type, COALESCE(SUM(balance), 0) AS total, COUNT(*) AS n
             FROM accounts WHERE tenant_id = ? AND status <> 'closed' AND account_type <> 'loan'
             GROUP BY account_type",
            [$tenantId],
        );
        foreach ($rows as $r) {
            $byType[$r['account_type']] = ['total' => (float) $r['total'], 'n' => (int) $r['n']];
        }
        $get = fn(string $t) => $byType[$t]['total'] ?? 0.0;
        $cnt = fn(string $t) => $byType[$t]['n'] ?? 0;

        $liabilities = [
            ['name' => 'Member Savings Deposits',  'balance' => round($get('savings'), 2),  'note' => $cnt('savings') . ' account(s)'],
            ['name' => 'Member Checking Deposits', 'balance' => round($get('checking'), 2), 'note' => $cnt('checking') . ' account(s)'],
        ];
        $totalLiabilities = round($get('savings') + $get('checking'), 2);

        // --- Equity: member shares + retained earnings ---
        $loanPayments = $this->db->fetchAll(
            "SELECT metadata FROM transactions WHERE tenant_id = ? AND type = 'loan_payment' AND status = 'completed'",
            [$tenantId],
        );
        $interestIncome = 0.0;
        foreach ($loanPayments as $lp) {
            $meta = json_decode($lp['metadata'] ?? '{}', true);
            $interestIncome += (float) ($meta['interest'] ?? 0);
        }
        $feeIncome = (float) ($this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE tenant_id = ? AND type IN ('fee', 'late_fee') AND status = 'completed'",
            [$tenantId],
        ) ?? 0);
        $expenses = (float) ($this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE tenant_id = ? AND type = 'expense' AND status = 'completed'",
            [$tenantId],
        ) ?? 0);
        $memberInterest = (float) ($this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE tenant_id = ? AND type = 'interest' AND status = 'completed'",
            [$tenantId],
        ) ?? 0);
        $retainedEarnings = round($interestIncome + $feeIncome - $expenses - $memberInterest, 2);

        $permShares = $get('permanent_shares');
        $regShares  = $get('regular_shares');
        $equity = [
            ['name' => 'Permanent Shares',  'balance' => round($permShares, 2), 'note' => $cnt('permanent_shares') . ' account(s)'],
            ['name' => 'Regular Shares',    'balance' => round($regShares, 2),  'note' => $cnt('regular_shares') . ' account(s)'],
            ['name' => 'Retained Earnings', 'balance' => $retainedEarnings,     'note' => 'all-time interest + fees − expenses'],
        ];
        $explained = round($totalLiabilities + $permShares + $regShares + $retainedEarnings, 2);
        $difference = round($totalAssets - $explained, 2);
        if (abs($difference) >= 0.01) {
            $equity[] = [
                'name'    => 'Opening Balances / Unreconciled',
                'balance' => $difference,
                'note'    => 'assets not explained by the ledger (e.g. balances loaded at go-live)',
                'plug'    => true,
            ];
        }
        $totalEquity = round($permShares + $regShares + $retainedEarnings + $difference, 2);

        return [
            'as_of'             => date('Y-m-d'),
            'assets'            => $assets,
            'liabilities'       => $liabilities,
            'equity'            => $equity,
            'total_cash'        => round($cashOnHand, 2),
            'total_assets'      => $totalAssets,
            'total_liabilities' => $totalLiabilities,
            'total_equity'      => $totalEquity,
            'difference'        => $difference,
            'balanced'          => abs($difference) < 0.01,
        ];
    }

    /**
     * GET /api/v1/reports/loan-portfolio
     *
     * Portfolio by status and by loan type, plus period activity.
     */
    public function loanPortfolio(Request $request): Response
    {
        $tenantId = $this->db->getTenantId();
        $from = $request->query('from', date('Y-m-01'));
        $to = $this->normalizeToDate($request->query('to', date('Y-m-d')));
        try { new \App\Services\LoanService(); } catch (\Throwable) {}

        $byStatus = $this->db->fetchAll(
            "SELECT status, COUNT(*) AS count,
                    COALESCE(SUM(principal_amount), 0) AS total_amount,
                    COALESCE(SUM(outstanding_balance), 0) AS total_remaining
             FROM loans WHERE tenant_id = ?
             GROUP BY status
             ORDER BY FIELD(status, 'application','approved','active','delinquent','default','paid_off','cancelled')",
            [$tenantId],
        );

        $byType = $this->db->fetchAll(
            "SELECT COALESCE(l.loan_type_code, '') AS code,
                    COALESCE(lt.description, l.loan_type) AS description,
                    l.loan_type AS category,
                    COUNT(*) AS count,
                    SUM(l.status IN ('active','delinquent','default')) AS active_count,
                    COALESCE(SUM(l.principal_amount), 0) AS total_amount,
                    COALESCE(SUM(CASE WHEN l.status IN ('active','delinquent','default') THEN l.outstanding_balance ELSE 0 END), 0) AS total_remaining
             FROM loans l
             LEFT JOIN loan_types lt ON lt.tenant_id = l.tenant_id AND lt.code = l.loan_type_code
             WHERE l.tenant_id = ?
             GROUP BY code, description, category
             ORDER BY total_remaining DESC",
            [$tenantId],
        );

        $active = $this->db->fetchOne(
            "SELECT COUNT(*) AS n, COALESCE(SUM(outstanding_balance), 0) AS outstanding,
                    COALESCE(SUM(outstanding_balance * interest_rate), 0) AS weighted,
                    COALESCE(SUM(monthly_payment), 0) AS monthly_due
             FROM loans WHERE tenant_id = ? AND status IN ('active','delinquent','default')",
            [$tenantId],
        );
        $avgRate = (float) $active['outstanding'] > 0 ? round((float) $active['weighted'] / (float) $active['outstanding'], 2) : 0;

        $disbursed = $this->db->fetchOne(
            "SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS total FROM transactions
             WHERE tenant_id = ? AND type = 'loan_disbursement' AND status = 'completed' AND created_at BETWEEN ? AND ?",
            [$tenantId, $from, $to],
        );
        $payRows = $this->db->fetchAll(
            "SELECT amount, metadata FROM transactions
             WHERE tenant_id = ? AND type = 'loan_payment' AND status = 'completed' AND created_at BETWEEN ? AND ?",
            [$tenantId, $from, $to],
        );
        $paid = 0.0; $interest = 0.0; $principal = 0.0;
        foreach ($payRows as $p) {
            $m = json_decode($p['metadata'] ?? '{}', true) ?: [];
            $paid += (float) $p['amount'];
            $interest += (float) ($m['interest'] ?? 0);
            $principal += (float) ($m['principal'] ?? 0);
        }

        return Response::ok([
            'as_of'     => date('Y-m-d'),
            'by_status' => $byStatus,
            'by_type'   => $byType,
            'active'    => [
                'count'        => (int) $active['n'],
                'outstanding'  => round((float) $active['outstanding'], 2),
                'avg_rate'     => $avgRate,
                'monthly_due'  => round((float) $active['monthly_due'], 2),
            ],
            'period'    => [
                'from'               => $from,
                'to'                 => substr($to, 0, 10),
                'disbursed_count'    => (int) $disbursed['n'],
                'disbursed_total'    => round((float) $disbursed['total'], 2),
                'payments_count'     => count($payRows),
                'payments_total'     => round($paid, 2),
                'interest_collected' => round($interest, 2),
                'principal_collected' => round($principal, 2),
            ],
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
             WHERE tenant_id = ? AND role = 'member' AND created_at BETWEEN ? AND ?
             GROUP BY DATE_FORMAT(created_at, '%Y-%m')
             ORDER BY month",
            [$tenantId, $from, $to]
        );

        $accounts = $this->db->fetchAll(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') AS month,
                    account_type,
                    COUNT(*) AS new_accounts
             FROM accounts
             WHERE tenant_id = ? AND account_type <> 'loan' AND created_at BETWEEN ? AND ?
             GROUP BY DATE_FORMAT(created_at, '%Y-%m'), account_type
             ORDER BY month",
            [$tenantId, $from, $to]
        );

        $totalMembers = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM users WHERE tenant_id = ? AND role = 'member'",
            [$tenantId]
        );
        $activeMembers = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM users WHERE tenant_id = ? AND role = 'member' AND status = 'active'",
            [$tenantId]
        );
        $newMembersPeriod = (int) array_sum(array_column($members, 'new_members'));

        $totalAccounts = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM accounts WHERE tenant_id = ? AND account_type <> 'loan' AND status <> 'closed'",
            [$tenantId]
        );
        $newAccountsPeriod = (int) array_sum(array_column($accounts, 'new_accounts'));

        return Response::ok([
            'member_trend'        => $members,
            'account_trend'       => $accounts,
            'total_members'       => $totalMembers,
            'active_members'      => $activeMembers,
            'new_members_period'  => $newMembersPeriod,
            'total_accounts'      => $totalAccounts,
            'new_accounts_period' => $newAccountsPeriod,
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
            "SELECT l.id, l.loan_number, l.loan_type, l.loan_type_code, l.status, l.principal_amount, l.outstanding_balance,
                    l.interest_rate, l.next_payment_date, l.monthly_payment, l.grace_period_days,
                    DATEDIFF(CURDATE(), l.next_payment_date) AS days_past_due,
                    CONCAT(u.first_name, ' ', u.last_name) AS member_name, u.email, u.phone, u.member_number
             FROM loans l
             JOIN users u ON u.id = l.user_id AND u.tenant_id = l.tenant_id
             WHERE l.tenant_id = ? AND l.status IN ('active', 'delinquent', 'default')
               AND l.next_payment_date < CURDATE()
             ORDER BY days_past_due DESC",
            [$tenantId]
        );

        $totalOverdue = 0.0; $beyondGrace = 0; $beyondGraceBalance = 0.0;
        $buckets = ['1-30' => 0, '31-60' => 0, '61-90' => 0, '90+' => 0];
        foreach ($loans as &$l) {
            $days = (int) $l['days_past_due'];
            $grace = (int) ($l['grace_period_days'] ?? 0);
            $l['within_grace'] = $days <= $grace;
            // Accrued interest since last payment, same basis as the ledger
            $l['accrued_interest'] = round((float) $l['outstanding_balance'] * ((float) $l['interest_rate'] / 100 / 365) * max(0, $days), 2);
            $totalOverdue += (float) $l['outstanding_balance'];
            if (!$l['within_grace']) {
                $beyondGrace++;
                $beyondGraceBalance += (float) $l['outstanding_balance'];
            }
            $bucket = $days > 90 ? '90+' : ($days > 60 ? '61-90' : ($days > 30 ? '31-60' : '1-30'));
            $buckets[$bucket]++;
        }
        unset($l);

        return Response::ok([
            'as_of' => date('Y-m-d'),
            'loans' => $loans,
            'total_count' => count($loans),
            'total_overdue_balance' => round($totalOverdue, 2),
            'beyond_grace_count' => $beyondGrace,
            'beyond_grace_balance' => round($beyondGraceBalance, 2),
            'buckets' => $buckets,
        ]);
    }

    /**
     * GET /api/v1/reports/members-register
     *
     * Register of Members: one row per member with share, deposit and loan
     * balances, plus a totals row. Mirrors the legacy "Members Report".
     */
    public function membersRegister(Request $request): Response
    {
        $data = $this->buildMembersRegister($request->query('status'));
        return Response::ok($data);
    }

    private function buildMembersRegister(?string $status = null): array
    {
        $tenantId = $this->db->getTenantId();

        // Make sure loans.loan_type_code exists (added lazily by LoanService)
        try { new \App\Services\LoanService(); } catch (\Throwable) {}

        // "Special" loans are any loan type whose description says so
        $specialCodes = [];
        try {
            $rows = $this->db->fetchAll(
                "SELECT code FROM loan_types WHERE tenant_id = ? AND LOWER(description) LIKE '%special%'",
                [$tenantId],
            );
            $specialCodes = array_values(array_filter(array_map(fn($r) => (string) $r['code'], $rows)));
        } catch (\Throwable) {
            $specialCodes = [];
        }

        $params = [$tenantId];
        $where = "WHERE u.tenant_id = ? AND u.role = 'member'";
        if ($status !== null && $status !== '' && $status !== 'all') {
            $where .= ' AND u.status = ?';
            $params[] = $status;
        }

        if ($specialCodes) {
            $in = implode(',', array_fill(0, count($specialCodes), '?'));
            $spCond  = "l.loan_type_code IN ({$in})";
            $regCond = "(l.loan_type_code IS NULL OR l.loan_type_code NOT IN ({$in}))";
            $spParams = $specialCodes;
        } else {
            $spCond  = '1 = 0';
            $regCond = '1 = 1';
            $spParams = [];
        }

        $activeLoan = "l.user_id = u.id AND l.tenant_id = u.tenant_id AND l.status IN ('active','delinquent','default')";
        $openAcct   = "a.user_id = u.id AND a.tenant_id = u.tenant_id AND a.status <> 'closed'";

        // Older installs may not have the address columns yet (migration 010 adds them)
        $optional = ['member_number', 'address_line_1', 'address_line_2', 'city', 'state'];
        $have = [];
        try {
            $rows = $this->db->fetchAll(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME IN ('member_number','address_line_1','address_line_2','city','state')",
            );
            $have = array_map(fn($r) => $r['COLUMN_NAME'], $rows);
        } catch (\Throwable) {
            $have = $optional;
        }
        $col = fn(string $name, string $alias = '') => (in_array($name, $have, true) ? "u.{$name}" : 'NULL') . ' AS ' . ($alias ?: $name);

        $sql = "SELECT u.id, {$col('member_number')}, u.first_name, u.last_name, u.email, u.phone, u.status,
                       {$col('address_line_1')}, {$col('address_line_2')}, {$col('city')}, {$col('state', 'parish')},
                       DATE(u.created_at) AS date_admitted,
                       COALESCE((SELECT SUM(a.balance) FROM accounts a WHERE {$openAcct} AND a.account_type = 'permanent_shares'), 0) AS p_shares,
                       COALESCE((SELECT SUM(a.balance) FROM accounts a WHERE {$openAcct} AND a.account_type IN ('savings','checking')), 0) AS deposits,
                       COALESCE((SELECT SUM(a.balance) FROM accounts a WHERE {$openAcct} AND a.account_type = 'regular_shares'), 0) AS shares,
                       COALESCE((SELECT SUM(l.outstanding_balance) FROM loans l WHERE {$activeLoan} AND l.loan_type <> 'credit_line' AND {$regCond}), 0) AS reg_loan,
                       COALESCE((SELECT SUM(l.outstanding_balance) FROM loans l WHERE {$activeLoan} AND l.loan_type = 'credit_line'), 0) AS loc,
                       COALESCE((SELECT SUM(l.outstanding_balance) FROM loans l WHERE {$activeLoan} AND l.loan_type <> 'credit_line' AND {$spCond}), 0) AS sp_loan
                FROM users u
                {$where}
                ORDER BY u.last_name ASC, u.first_name ASC";

        // Parameter order follows placeholder order: regCond (sp codes), spCond (sp codes), then WHERE params
        $allParams = array_merge($spParams, $spParams, $params);
        $members = $this->db->fetchAll($sql, $allParams);

        $totals = ['members' => count($members), 'p_shares' => 0.0, 'deposits' => 0.0, 'shares' => 0.0, 'reg_loan' => 0.0, 'loc' => 0.0, 'sp_loan' => 0.0];
        foreach ($members as &$m) {
            foreach (['p_shares', 'deposits', 'shares', 'reg_loan', 'loc', 'sp_loan'] as $k) {
                $m[$k] = round((float) $m[$k], 2);
                $totals[$k] += $m[$k];
            }
            $m['member_name'] = trim(strtoupper((string) $m['last_name']) . ' ' . $m['first_name']);
            $m['address'] = trim(implode(', ', array_filter([$m['address_line_1'], $m['address_line_2'], $m['city']])));
        }
        unset($m);
        foreach ($totals as $k => $v) {
            if (is_float($v)) {
                $totals[$k] = round($v, 2);
            }
        }

        return [
            'as_of'   => date('Y-m-d'),
            'members' => $members,
            'totals'  => $totals,
        ];
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
                $bs = $this->buildBalanceSheet();
                $csv = "Category,Type,Balance,Note\n";
                foreach ($bs['assets'] as $a) { $csv .= '"' . $a['name'] . '",Asset,' . $a['balance'] . ',"' . ($a['note'] ?? '') . "\"\n"; }
                $csv .= "Total Assets,," . $bs['total_assets'] . ",\n";
                foreach ($bs['liabilities'] as $a) { $csv .= '"' . $a['name'] . '",Liability,' . $a['balance'] . ',"' . ($a['note'] ?? '') . "\"\n"; }
                $csv .= "Total Liabilities,," . $bs['total_liabilities'] . ",\n";
                foreach ($bs['equity'] as $a) { $csv .= '"' . $a['name'] . '",Equity,' . $a['balance'] . ',"' . ($a['note'] ?? '') . "\"\n"; }
                $csv .= "Total Equity,," . $bs['total_equity'] . ",\n";
                $csv .= "Liabilities + Equity,," . round($bs['total_liabilities'] + $bs['total_equity'], 2) . ",\n";
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
                    "SELECT l.loan_number, l.loan_type, l.outstanding_balance, l.monthly_payment, l.grace_period_days,
                            l.next_payment_date, DATEDIFF(CURDATE(), l.next_payment_date) AS days_past_due,
                            CONCAT(u.first_name, ' ', u.last_name) AS member_name, u.email, u.phone
                     FROM loans l
                     JOIN users u ON u.id = l.user_id AND u.tenant_id = l.tenant_id
                     WHERE l.tenant_id = ? AND l.status IN ('active', 'delinquent', 'default')
                       AND l.next_payment_date < CURDATE()
                     ORDER BY days_past_due DESC",
                    [$tenantId]
                );
                $csv = "Loan #,Member,Email,Phone,Type,Outstanding,Monthly Payment,Next Payment Due,Days Past Due,Within Grace\n";
                foreach ($rows as $r) {
                    $csv .= implode(',', [
                        $r['loan_number'], '"' . str_replace('"', '""', $r['member_name']) . '"',
                        $r['email'], $r['phone'] ?? '', $r['loan_type'], $r['outstanding_balance'],
                        $r['monthly_payment'], $r['next_payment_date'], $r['days_past_due'],
                        ((int) $r['days_past_due'] <= (int) ($r['grace_period_days'] ?? 0)) ? 'yes' : 'no',
                    ]) . "\n";
                }
                break;

            case 'member-growth':
                $rows = $this->db->fetchAll(
                    "SELECT DATE_FORMAT(created_at, '%Y-%m') AS month, COUNT(*) AS new_members
                     FROM users WHERE tenant_id = ? AND role = 'member' AND created_at BETWEEN ? AND ?
                     GROUP BY DATE_FORMAT(created_at, '%Y-%m') ORDER BY month",
                    [$tenantId, $from, $to]
                );
                $csv = "Month,New Members\n";
                foreach ($rows as $r) {
                    $csv .= "{$r['month']},{$r['new_members']}\n";
                }
                break;

            case 'members-register':
                $reg = $this->buildMembersRegister($request->query('status'));
                $q = fn($v) => '"' . str_replace('"', '""', (string) ($v ?? '')) . '"';
                $csv = "Book,Member,Address,Email,Date Admitted,Parish,P_Shares,Deposits,Shares,Reg Loan,L.O.C,SpLoan\n";
                foreach ($reg['members'] as $m) {
                    $csv .= implode(',', [
                        $q($m['member_number']), $q($m['member_name']), $q($m['address']), $q($m['email']),
                        $m['date_admitted'] ?? '', $q($m['parish']),
                        number_format($m['p_shares'], 2, '.', ''), number_format($m['deposits'], 2, '.', ''),
                        number_format($m['shares'], 2, '.', ''), number_format($m['reg_loan'], 2, '.', ''),
                        number_format($m['loc'], 2, '.', ''), number_format($m['sp_loan'], 2, '.', ''),
                    ]) . "\n";
                }
                $t = $reg['totals'];
                $csv .= implode(',', [
                    '"TOTAL"', '"' . $t['members'] . ' members"', '', '', '', '',
                    number_format($t['p_shares'], 2, '.', ''), number_format($t['deposits'], 2, '.', ''),
                    number_format($t['shares'], 2, '.', ''), number_format($t['reg_loan'], 2, '.', ''),
                    number_format($t['loc'], 2, '.', ''), number_format($t['sp_loan'], 2, '.', ''),
                ]) . "\n";
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
