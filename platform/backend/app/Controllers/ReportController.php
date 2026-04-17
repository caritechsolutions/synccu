<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
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
     * Revenue and expense accounts for a date range.
     */
    public function incomeStatement(Request $request): Response
    {
        $tenantId = $this->db->getTenantId();
        $from = $request->query('from', date('Y-m-01'));
        $to = $request->query('to', date('Y-m-d') . ' 23:59:59');

        // Revenue accounts (type='revenue'): credit increases
        $revenue = $this->db->fetchAll(
            'SELECT ga.code, ga.name,
                    COALESCE(SUM(jel.credit), 0) AS total_credits,
                    COALESCE(SUM(jel.debit), 0) AS total_debits,
                    COALESCE(SUM(jel.credit), 0) - COALESCE(SUM(jel.debit), 0) AS net_amount
             FROM gl_accounts ga
             LEFT JOIN journal_entry_lines jel ON jel.gl_account_code = ga.code AND jel.tenant_id = ga.tenant_id
                 AND jel.created_at BETWEEN ? AND ?
             WHERE ga.tenant_id = ? AND ga.type = ?
             GROUP BY ga.code, ga.name
             ORDER BY ga.code',
            [$from, $to, $tenantId, 'revenue']
        );

        // Expense accounts (type='expense'): debit increases
        $expenses = $this->db->fetchAll(
            'SELECT ga.code, ga.name,
                    COALESCE(SUM(jel.debit), 0) AS total_debits,
                    COALESCE(SUM(jel.credit), 0) AS total_credits,
                    COALESCE(SUM(jel.debit), 0) - COALESCE(SUM(jel.credit), 0) AS net_amount
             FROM gl_accounts ga
             LEFT JOIN journal_entry_lines jel ON jel.gl_account_code = ga.code AND jel.tenant_id = ga.tenant_id
                 AND jel.created_at BETWEEN ? AND ?
             WHERE ga.tenant_id = ? AND ga.type = ?
             GROUP BY ga.code, ga.name
             ORDER BY ga.code',
            [$from, $to, $tenantId, 'expense']
        );

        $totalRevenue = array_sum(array_column($revenue, 'net_amount'));
        $totalExpenses = array_sum(array_column($expenses, 'net_amount'));

        return Response::ok([
            'revenue' => $revenue,
            'expenses' => $expenses,
            'total_revenue' => round($totalRevenue, 2),
            'total_expenses' => round($totalExpenses, 2),
            'net_income' => round($totalRevenue - $totalExpenses, 2),
        ]);
    }

    /**
     * GET /api/v1/reports/balance-sheet
     *
     * Assets, liabilities, and equity with balance check.
     */
    public function balanceSheet(Request $request): Response
    {
        $tenantId = $this->db->getTenantId();

        $accounts = $this->db->fetchAll(
            'SELECT ga.code, ga.name, ga.type, ga.balance
             FROM gl_accounts ga
             WHERE ga.tenant_id = ?
             ORDER BY ga.code',
            [$tenantId]
        );

        $assets = [];
        $liabilities = [];
        $equity = [];
        $totalAssets = 0;
        $totalLiabilities = 0;
        $totalEquity = 0;

        foreach ($accounts as $a) {
            $bal = (float) $a['balance'];
            switch ($a['type']) {
                case 'asset':
                    $assets[] = $a;
                    $totalAssets += $bal;
                    break;
                case 'liability':
                    $liabilities[] = $a;
                    $totalLiabilities += abs($bal);
                    break;
                case 'equity':
                    $equity[] = $a;
                    $totalEquity += abs($bal);
                    break;
            }
        }

        return Response::ok([
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'total_assets' => round($totalAssets, 2),
            'total_liabilities' => round($totalLiabilities, 2),
            'total_equity' => round($totalEquity, 2),
            'balanced' => abs($totalAssets - ($totalLiabilities + $totalEquity)) < 0.01,
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
                $accounts = $this->db->fetchAll(
                    'SELECT ga.code, ga.name, ga.type,
                            COALESCE(SUM(jel.debit), 0) AS total_debits,
                            COALESCE(SUM(jel.credit), 0) AS total_credits
                     FROM gl_accounts ga
                     LEFT JOIN journal_entry_lines jel ON jel.gl_account_code = ga.code AND jel.tenant_id = ga.tenant_id
                         AND jel.created_at BETWEEN ? AND ?
                     WHERE ga.tenant_id = ? AND ga.type IN (?, ?)
                     GROUP BY ga.code, ga.name, ga.type
                     ORDER BY ga.type, ga.code',
                    [$from, $to, $tenantId, 'revenue', 'expense']
                );
                $csv = "Account Code,Account Name,Type,Debits,Credits,Net\n";
                foreach ($accounts as $a) {
                    $net = $a['type'] === 'revenue'
                        ? ($a['total_credits'] - $a['total_debits'])
                        : ($a['total_debits'] - $a['total_credits']);
                    $csv .= implode(',', [
                        $a['code'], '"' . str_replace('"', '""', $a['name']) . '"',
                        $a['type'], $a['total_debits'], $a['total_credits'], round($net, 2)
                    ]) . "\n";
                }
                break;

            case 'balance-sheet':
                $accounts = $this->db->fetchAll(
                    'SELECT code, name, type, balance FROM gl_accounts WHERE tenant_id = ? ORDER BY code',
                    [$tenantId]
                );
                $csv = "Account Code,Account Name,Type,Balance\n";
                foreach ($accounts as $a) {
                    $csv .= implode(',', [
                        $a['code'], '"' . str_replace('"', '""', $a['name']) . '"',
                        $a['type'], $a['balance']
                    ]) . "\n";
                }
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
}
