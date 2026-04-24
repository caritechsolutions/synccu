<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

/**
 * Admin REST controller.
 *
 * Provides dashboard statistics, user management, audit log access,
 * and report generation for administrative users.
 */
final class AdminController
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * GET /api/admin/dashboard
     *
     * Aggregated statistics for the current tenant.
     */
    public function dashboard(Request $request): Response
    {
        $tenantId = $this->db->getTenantId();

        $totalMembers = (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM users WHERE tenant_id = ? AND status = ?',
            [$tenantId, 'active'],
        );

        $totalAccounts = (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM accounts WHERE tenant_id = ? AND status = ?',
            [$tenantId, 'active'],
        );

        $totalDeposits = (float) ($this->db->fetchColumn(
            'SELECT COALESCE(SUM(balance), 0) FROM accounts WHERE tenant_id = ? AND status = ?',
            [$tenantId, 'active'],
        ) ?? 0);

        $totalLoans = (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM loans WHERE tenant_id = ? AND status IN (?, ?)',
            [$tenantId, 'approved', 'active'],
        );

        $totalLoanBalance = (float) ($this->db->fetchColumn(
            'SELECT COALESCE(SUM(outstanding_balance), 0) FROM loans WHERE tenant_id = ? AND status IN (?, ?)',
            [$tenantId, 'approved', 'active'],
        ) ?? 0);

        $pendingLoans = (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM loans WHERE tenant_id = ? AND status = ?',
            [$tenantId, 'application'],
        );

        $recentTransactions = $this->db->fetchAll(
            'SELECT id, reference_number, type, amount, status, created_at
             FROM transactions WHERE tenant_id = ?
             ORDER BY created_at DESC LIMIT 10',
            [$tenantId],
        );

        $delinquentLoans = (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM loans WHERE tenant_id = ? AND status = ?',
            [$tenantId, 'delinquent'],
        );

        // Transaction volume by day (last 30 days) — for line/area chart
        $transactionTrend = $this->db->fetchAll(
            'SELECT DATE(created_at) AS date, COUNT(*) AS count, COALESCE(SUM(amount), 0) AS total
             FROM transactions
             WHERE tenant_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY DATE(created_at)
             ORDER BY date',
            [$tenantId],
        );

        // Deposits vs Withdrawals this month — for comparison
        $depositVsWithdrawal = $this->db->fetchAll(
            "SELECT type, COUNT(*) AS count, COALESCE(SUM(amount), 0) AS total
             FROM transactions
             WHERE tenant_id = ? AND type IN ('deposit', 'withdrawal')
               AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
             GROUP BY type",
            [$tenantId],
        );

        // Account types breakdown — for donut chart
        $accountTypes = $this->db->fetchAll(
            "SELECT account_type, COUNT(*) AS count, COALESCE(SUM(balance), 0) AS total_balance
             FROM accounts
             WHERE tenant_id = ? AND status = 'active'
             GROUP BY account_type",
            [$tenantId],
        );

        // Loan status breakdown — for donut chart
        $loanStatus = $this->db->fetchAll(
            'SELECT status, COUNT(*) AS count, COALESCE(SUM(outstanding_balance), 0) AS total
             FROM loans
             WHERE tenant_id = ?
             GROUP BY status',
            [$tenantId],
        );

        // Today's stats
        $todayDate = date('Y-m-d');

        $todayDeposits = (float) ($this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount), 0) FROM transactions
             WHERE tenant_id = ? AND type = 'deposit' AND DATE(created_at) = ?",
            [$tenantId, $todayDate],
        ) ?? 0);

        $todayWithdrawals = (float) ($this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount), 0) FROM transactions
             WHERE tenant_id = ? AND type = 'withdrawal' AND DATE(created_at) = ?",
            [$tenantId, $todayDate],
        ) ?? 0);

        $todayTransactions = (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM transactions
             WHERE tenant_id = ? AND DATE(created_at) = ?',
            [$tenantId, $todayDate],
        );

        $newMembersToday = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM users
             WHERE tenant_id = ? AND role = 'member' AND DATE(created_at) = ?",
            [$tenantId, $todayDate],
        );

        $todayStats = [
            'today_deposits'      => round($todayDeposits, 2),
            'today_withdrawals'   => round($todayWithdrawals, 2),
            'today_transactions'  => $todayTransactions,
            'new_members_today'   => $newMembersToday,
        ];

        // Cash position (fund_locations table may not exist)
        $cashPosition = [];
        try {
            $cashPosition = $this->db->fetchAll(
                "SELECT type, COALESCE(SUM(balance), 0) AS total
                 FROM fund_locations
                 WHERE tenant_id = ? AND status = 'active'
                 GROUP BY type",
                [$tenantId],
            );
        } catch (\Throwable) {
            // Table may not exist — return empty array
        }

        return Response::ok([
            'members'                => $totalMembers,
            'active_accounts'        => $totalAccounts,
            'total_deposits'         => round($totalDeposits, 2),
            'active_loans'           => $totalLoans,
            'total_loan_balance'     => round($totalLoanBalance, 2),
            'pending_loan_apps'      => $pendingLoans,
            'delinquent_loans'       => $delinquentLoans,
            'recent_transactions'    => $recentTransactions,
            'transaction_trend'      => $transactionTrend,
            'deposit_vs_withdrawal'  => $depositVsWithdrawal,
            'account_types'          => $accountTypes,
            'loan_status'            => $loanStatus,
            'today'                  => $todayStats,
            'cash_position'          => $cashPosition,
        ]);
    }

    /**
     * GET /api/v1/members
     *
     * List members (role=member) with account counts for the members admin page.
     */
    public function members(Request $request): Response
    {
        $page    = max(1, (int) ($request->query('page', '1')));
        $perPage = min(100, max(1, (int) ($request->query('per_page', '20'))));
        $status  = $request->query('status');
        $search  = $request->query('search');

        $tenantId = $this->db->getTenantId();
        $offset   = ($page - 1) * $perPage;
        $params   = [$tenantId];
        $where    = 'WHERE u.tenant_id = ?';

        if ($status !== null && $status !== '' && $status !== 'all') {
            $where .= ' AND u.status = ?';
            $params[] = $status;
        }
        if ($search !== null && $search !== '') {
            $where .= ' AND (u.email LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.phone LIKE ?)';
            $term = "%{$search}%";
            $params = [...$params, $term, $term, $term, $term];
        }

        $members = $this->db->fetchAll(
            "SELECT u.id, u.email, u.first_name, u.last_name, u.phone, u.role, u.status,
                    u.last_login_at, u.created_at,
                    (SELECT COUNT(*) FROM accounts a WHERE a.user_id = u.id AND a.tenant_id = u.tenant_id) AS accounts_count,
                    (SELECT GROUP_CONCAT(a2.account_number ORDER BY a2.created_at SEPARATOR ', ')
                     FROM accounts a2 WHERE a2.user_id = u.id AND a2.tenant_id = u.tenant_id AND a2.account_type != 'loan') AS account_numbers
             FROM users u {$where}
             ORDER BY u.created_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params,
        );

        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM users u {$where}",
            $params,
        );

        return Response::paginated($members, $total, $page, $perPage);
    }

    /**
     * POST /api/v1/members
     *
     * Create a new member/user.
     */
    public function createMember(Request $request): Response
    {
        $validator = new Validator($request->all(), [
            'email'      => 'required|email',
            'first_name' => 'required|string|max:100',
            'last_name'  => 'required|string|max:100',
            'phone'      => 'nullable|string|max:20',
            'role'       => 'nullable|in:member,teller,manager,admin',
            'status'     => 'nullable|in:active,inactive',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        $data = $validator->validated();
        $tenantId = $this->db->getTenantId();

        // Check for duplicate email
        $existing = $this->db->fetchOne(
            'SELECT id FROM users WHERE email = ? AND tenant_id = ?',
            [$data['email'], $tenantId],
        );
        if ($existing) {
            return Response::error('A member with this email already exists', 422);
        }

        $id = sprintf('%s-%s-%s-%s-%s',
            bin2hex(random_bytes(4)),
            bin2hex(random_bytes(2)),
            bin2hex(random_bytes(2)),
            bin2hex(random_bytes(2)),
            bin2hex(random_bytes(6))
        );
        $now = date('Y-m-d H:i:s');
        $passwordHash = password_hash('changeme123', PASSWORD_BCRYPT);

        $this->db->execute(
            'INSERT INTO users (id, tenant_id, email, password_hash, first_name, last_name, phone, role, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $id, $tenantId, $data['email'], $passwordHash,
                $data['first_name'], $data['last_name'],
                $data['phone'] ?? null,
                $data['role'] ?? 'member',
                $data['status'] ?? 'active',
                $now, $now,
            ],
        );

        $member = $this->db->findScoped('users', ['id' => $id]);
        unset($member['password_hash']);

        return Response::created($member, 'Member created successfully');
    }

    /**
     * GET /api/v1/members/{id}
     *
     * Get a single member with their accounts and loans.
     */
    public function showMember(Request $request): Response
    {
        $memberId = $request->param('id');
        $member = $this->db->findScoped('users', ['id' => $memberId]);

        if ($member === null) {
            return Response::error('Member not found', 404);
        }

        unset($member['password_hash'], $member['two_factor_secret'], $member['failed_login_attempts'], $member['locked_until'], $member['last_failed_login_at']);

        $tenantId = $this->db->getTenantId();

        $accounts = $this->db->fetchAll(
            'SELECT id, account_number, account_type, name, balance, available_balance, currency, status, opened_at
             FROM accounts WHERE user_id = ? AND tenant_id = ?
             ORDER BY opened_at DESC',
            [$memberId, $tenantId],
        );

        $loans = $this->db->fetchAll(
            'SELECT id, loan_number, loan_type, principal_amount, outstanding_balance, interest_rate,
                    status, term_months, monthly_payment, disbursed_at, maturity_date
             FROM loans WHERE user_id = ? AND tenant_id = ?
             ORDER BY created_at DESC',
            [$memberId, $tenantId],
        );

        $recentTransactions = $this->db->fetchAll(
            'SELECT t.id, t.reference_number, t.type, t.amount, t.status, t.description, t.created_at,
                    a.account_number, a.name AS account_name
             FROM transactions t
             JOIN accounts a ON a.id = t.account_id
             WHERE a.user_id = ? AND t.tenant_id = ?
             ORDER BY t.created_at DESC LIMIT 20',
            [$memberId, $tenantId],
        );

        $member['accounts'] = $accounts;
        $member['loans'] = $loans;
        $member['recent_transactions'] = $recentTransactions;

        return Response::ok($member);
    }

    /**
     * DELETE /api/v1/members/{id}
     *
     * Deactivate (soft-delete) a member. Sets status to inactive.
     */
    public function deleteMember(Request $request): Response
    {
        $memberId = $request->param('id');
        $member = $this->db->findScoped('users', ['id' => $memberId]);

        if ($member === null) {
            return Response::error('Member not found', 404);
        }

        // Prevent deleting yourself
        $currentUserId = $request->getAttribute('user_id');
        if ($memberId === $currentUserId) {
            return Response::error('You cannot delete your own account', 422);
        }

        // Prevent deleting the last admin
        if ($member['role'] === 'admin') {
            $adminCount = $this->db->countScoped('users', ['role' => 'admin']);
            if ($adminCount <= 1) {
                return Response::error('Cannot delete the last administrator', 422);
            }
        }

        // Check for active accounts with balances
        $tenantId = $this->db->getTenantId();
        $activeBalance = (float) ($this->db->fetchColumn(
            'SELECT COALESCE(SUM(balance), 0) FROM accounts WHERE user_id = ? AND tenant_id = ? AND status = ?',
            [$memberId, $tenantId, 'active'],
        ) ?? 0);

        if ($activeBalance > 0) {
            return Response::error('Cannot delete member with active account balances. Close or zero out accounts first.', 422);
        }

        // Check for active loans
        $activeLoans = (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM loans WHERE user_id = ? AND tenant_id = ? AND status IN (?, ?, ?)',
            [$memberId, $tenantId, 'active', 'approved', 'application'],
        );

        if ($activeLoans > 0) {
            return Response::error('Cannot delete member with active or pending loans. Resolve loans first.', 422);
        }

        // Soft-delete: set status to inactive
        $this->db->updateScoped('users', [
            'status' => 'inactive',
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $memberId]);

        return Response::ok(null, 'Member deactivated successfully');
    }

    /**
     * GET /api/v1/admin/users
     *
     * List all users for the tenant.
     */
    public function users(Request $request): Response
    {
        $page    = max(1, (int) ($request->query('page', '1')));
        $perPage = min(100, max(1, (int) ($request->query('per_page', '20'))));
        $status  = $request->query('status');
        $role    = $request->query('role');
        $search  = $request->query('search');

        $tenantId = $this->db->getTenantId();
        $offset   = ($page - 1) * $perPage;
        $params   = [$tenantId];
        $where    = 'WHERE u.tenant_id = ?';

        if ($status !== null && $status !== '') {
            $where .= ' AND u.status = ?';
            $params[] = $status;
        }
        if ($role !== null && $role !== '') {
            $where .= ' AND u.role = ?';
            $params[] = $role;
        }
        if ($search !== null && $search !== '') {
            $where .= ' AND (u.email LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)';
            $term = "%{$search}%";
            $params = [...$params, $term, $term, $term];
        }

        $users = $this->db->fetchAll(
            "SELECT u.id, u.email, u.first_name, u.last_name, u.phone, u.role, u.status,
                    u.last_login_at, u.created_at,
                    (SELECT COUNT(*) FROM accounts a WHERE a.user_id = u.id AND a.tenant_id = u.tenant_id) AS accounts_count
             FROM users u {$where}
             ORDER BY u.created_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params,
        );

        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM users u {$where}",
            $params,
        );

        return Response::paginated($users, $total, $page, $perPage);
    }

    /**
     * PUT /api/admin/users/{id}
     *
     * Update a user's role or status.
     */
    public function updateUser(Request $request): Response
    {
        $userId = $request->param('id');

        $validator = new Validator($request->all(), [
            'email'      => 'nullable|email',
            'first_name' => 'nullable|string|max:100',
            'last_name'  => 'nullable|string|max:100',
            'phone'      => 'nullable|string|max:20',
            'role'       => 'nullable|in:admin,manager,teller,member',
            'status'     => 'nullable|in:active,inactive,suspended',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        $data = $validator->validated();
        if (empty($data)) {
            return Response::error('No fields to update', 422);
        }

        $user = $this->db->findScoped('users', ['id' => $userId]);
        if ($user === null) {
            return Response::error('User not found', 404);
        }

        // Check email uniqueness if changing email
        if (isset($data['email']) && $data['email'] !== $user['email']) {
            $tenantId = $this->db->getTenantId();
            $existing = $this->db->fetchOne(
                'SELECT id FROM users WHERE email = ? AND tenant_id = ? AND id != ?',
                [$data['email'], $tenantId, $userId],
            );
            if ($existing) {
                return Response::error('A member with this email already exists', 422);
            }
        }

        // Prevent demoting the last admin
        if (isset($data['role']) && $data['role'] !== 'admin' && $user['role'] === 'admin') {
            $adminCount = $this->db->countScoped('users', ['role' => 'admin']);
            if ($adminCount <= 1) {
                return Response::error('Cannot change the role of the last administrator', 422);
            }
        }

        $data['updated_at'] = date('Y-m-d H:i:s');
        $this->db->updateScoped('users', $data, ['id' => $userId]);

        $updated = $this->db->findScoped('users', ['id' => $userId]);
        unset($updated['password_hash'], $updated['failed_login_attempts'], $updated['last_failed_login_at']);

        return Response::ok($updated, 'User updated successfully');
    }

    /**
     * GET /api/admin/audit-logs
     *
     * Retrieve audit logs for the tenant.
     */
    public function auditLogs(Request $request): Response
    {
        $page     = max(1, (int) ($request->query('page', '1')));
        $perPage  = min(100, max(1, (int) ($request->query('per_page', '50'))));
        $tenantId = $this->db->getTenantId();
        $offset   = ($page - 1) * $perPage;

        $params = [$tenantId];
        $where  = 'WHERE tenant_id = ?';

        $userId = $request->query('user_id');
        if ($userId !== null && $userId !== '') {
            $where .= ' AND user_id = ?';
            $params[] = $userId;
        }

        $action = $request->query('action');
        if ($action !== null && $action !== '') {
            $where .= ' AND action LIKE ?';
            $params[] = "%{$action}%";
        }

        $from = $request->query('from');
        if ($from !== null && $from !== '') {
            $where .= ' AND created_at >= ?';
            $params[] = $from;
        }

        $to = $request->query('to');
        if ($to !== null && $to !== '') {
            $where .= ' AND created_at <= ?';
            $params[] = $to;
        }

        $logs = $this->db->fetchAll(
            "SELECT * FROM audit_logs {$where} ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}",
            $params,
        );

        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM audit_logs {$where}",
            $params,
        );

        return Response::paginated($logs, $total, $page, $perPage);
    }

    /**
     * GET /api/admin/reports
     *
     * Generate a report based on the `type` query parameter.
     */
    public function reports(Request $request): Response
    {
        $type     = $request->query('type', 'summary');
        $tenantId = $this->db->getTenantId();
        $from     = $request->query('from', date('Y-m-01'));
        $to       = $request->query('to', date('Y-m-d'));

        $report = match ($type) {
            'summary'       => $this->summaryReport($tenantId, $from, $to),
            'transactions'  => $this->transactionReport($tenantId, $from, $to),
            'loans'         => $this->loanReport($tenantId),
            'delinquency'   => $this->delinquencyReport($tenantId),
            default         => ['error' => 'Unknown report type'],
        };

        return Response::ok([
            'type'   => $type,
            'from'   => $from,
            'to'     => $to,
            'report' => $report,
        ]);
    }

    /**
     * GET /api/v1/admin/loan-stats
     *
     * Loan pipeline status counts.
     */
    public function loanStats(Request $request): Response
    {
        $tenantId = $this->db->getTenantId();

        $rows = $this->db->fetchAll(
            'SELECT status, COUNT(*) AS count FROM loans WHERE tenant_id = ? GROUP BY status',
            [$tenantId],
        );

        $stats = [];
        foreach ($rows as $row) {
            $stats[$row['status']] = (int) $row['count'];
        }

        return Response::ok($stats);
    }

    // ------------------------------------------------------------------
    // Report Generators
    // ------------------------------------------------------------------

    private function summaryReport(string $tenantId, string $from, string $to): array
    {
        $deposits = (float) ($this->db->fetchColumn(
            'SELECT COALESCE(SUM(amount), 0) FROM transactions
             WHERE tenant_id = ? AND type = ? AND created_at BETWEEN ? AND ?',
            [$tenantId, 'deposit', $from, $to],
        ) ?? 0);

        $withdrawals = (float) ($this->db->fetchColumn(
            'SELECT COALESCE(SUM(amount), 0) FROM transactions
             WHERE tenant_id = ? AND type = ? AND created_at BETWEEN ? AND ?',
            [$tenantId, 'withdrawal', $from, $to],
        ) ?? 0);

        $transfers = (float) ($this->db->fetchColumn(
            'SELECT COALESCE(SUM(amount), 0) FROM transactions
             WHERE tenant_id = ? AND type = ? AND created_at BETWEEN ? AND ?',
            [$tenantId, 'transfer', $from, $to],
        ) ?? 0);

        $newAccounts = (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM accounts WHERE tenant_id = ? AND created_at BETWEEN ? AND ?',
            [$tenantId, $from, $to],
        );

        $newMembers = (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM users WHERE tenant_id = ? AND created_at BETWEEN ? AND ?',
            [$tenantId, $from, $to],
        );

        return [
            'total_deposits'     => round($deposits, 2),
            'total_withdrawals'  => round($withdrawals, 2),
            'total_transfers'    => round($transfers, 2),
            'net_flow'           => round($deposits - $withdrawals, 2),
            'new_accounts'       => $newAccounts,
            'new_members'        => $newMembers,
        ];
    }

    private function transactionReport(string $tenantId, string $from, string $to): array
    {
        return $this->db->fetchAll(
            'SELECT type, COUNT(*) AS count, COALESCE(SUM(amount), 0) AS total_amount
             FROM transactions
             WHERE tenant_id = ? AND created_at BETWEEN ? AND ?
             GROUP BY type
             ORDER BY total_amount DESC',
            [$tenantId, $from, $to],
        );
    }

    private function loanReport(string $tenantId): array
    {
        return $this->db->fetchAll(
            'SELECT status, COUNT(*) AS count,
                    COALESCE(SUM(principal_amount), 0) AS total_amount,
                    COALESCE(SUM(outstanding_balance), 0) AS total_remaining
             FROM loans WHERE tenant_id = ?
             GROUP BY status
             ORDER BY count DESC',
            [$tenantId],
        );
    }

    private function delinquencyReport(string $tenantId): array
    {
        return $this->db->fetchAll(
            'SELECT l.id, l.user_id, u.email, u.first_name, u.last_name,
                    l.loan_type, l.principal_amount, l.outstanding_balance,
                    l.next_payment_date, DATEDIFF(CURDATE(), l.next_payment_date) AS days_past_due
             FROM loans l
             JOIN users u ON u.id = l.user_id
             WHERE l.tenant_id = ? AND l.status IN (?, ?)
               AND l.next_payment_date < CURDATE()
             ORDER BY days_past_due DESC',
            [$tenantId, 'approved', 'active'],
        );
    }
}
