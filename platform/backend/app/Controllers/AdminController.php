<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\ReconciliationService;

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
        $todayDate = date('Y-m-d');
        $monthStart = date('Y-m-01');
        $yearStart = date('Y-01-01');
        $col = fn(string $sql, array $p) => (float) ($this->db->fetchColumn($sql, $p) ?? 0);
        $cnt = fn(string $sql, array $p) => (int) $this->db->fetchColumn($sql, $p);

        // --- Members (role = member only; staff logins are not members) ---
        $totalMembers    = $cnt("SELECT COUNT(*) FROM users WHERE tenant_id = ? AND role = 'member' AND status = 'active'", [$tenantId]);
        $allMembers      = $cnt("SELECT COUNT(*) FROM users WHERE tenant_id = ? AND role = 'member'", [$tenantId]);
        $newMembersMonth = $cnt("SELECT COUNT(*) FROM users WHERE tenant_id = ? AND role = 'member' AND created_at >= ?", [$tenantId, $monthStart]);

        // --- Member funds (loan tracking accounts are NOT deposits) ---
        $totalAccounts = $cnt("SELECT COUNT(*) FROM accounts WHERE tenant_id = ? AND status = 'active' AND account_type <> 'loan'", [$tenantId]);
        $totalDeposits = $col("SELECT COALESCE(SUM(balance), 0) FROM accounts WHERE tenant_id = ? AND status <> 'closed' AND account_type IN ('savings','checking')", [$tenantId]);
        $totalShares   = $col("SELECT COALESCE(SUM(balance), 0) FROM accounts WHERE tenant_id = ? AND status <> 'closed' AND account_type IN ('permanent_shares','regular_shares')", [$tenantId]);
        $memberFunds   = round($totalDeposits + $totalShares, 2);

        // --- Loan book (disbursed loans only) ---
        $active = $this->db->fetchOne(
            "SELECT COUNT(*) AS n, COALESCE(SUM(outstanding_balance), 0) AS balance, COALESCE(SUM(monthly_payment), 0) AS installments
             FROM loans WHERE tenant_id = ? AND status IN ('active','delinquent','default')", [$tenantId]);
        $totalLoans = (int) $active['n'];
        $totalLoanBalance = (float) $active['balance'];
        $pendingLoans = $cnt("SELECT COUNT(*) FROM loans WHERE tenant_id = ? AND status = 'application'", [$tenantId]);
        $approved = $this->db->fetchOne("SELECT COUNT(*) AS n, COALESCE(SUM(principal_amount), 0) AS amount FROM loans WHERE tenant_id = ? AND status = 'approved'", [$tenantId]);

        // Past due: next due date has passed. "Beyond grace" uses each loan's grace period.
        $pastDueRows = $this->db->fetchAll(
            "SELECT outstanding_balance, DATEDIFF(CURDATE(), next_payment_date) AS days, COALESCE(grace_period_days, 0) AS grace
             FROM loans WHERE tenant_id = ? AND status IN ('active','delinquent','default') AND next_payment_date < CURDATE()", [$tenantId]);
        $pastDue = ['count' => count($pastDueRows), 'balance' => 0.0, 'beyond_grace_count' => 0, 'beyond_grace_balance' => 0.0, 'over_90_count' => 0];
        foreach ($pastDueRows as $r) {
            $pastDue['balance'] += (float) $r['outstanding_balance'];
            if ((int) $r['days'] > (int) $r['grace']) { $pastDue['beyond_grace_count']++; $pastDue['beyond_grace_balance'] += (float) $r['outstanding_balance']; }
            if ((int) $r['days'] > 90) $pastDue['over_90_count']++;
        }
        $pastDue['balance'] = round($pastDue['balance'], 2);
        $pastDue['beyond_grace_balance'] = round($pastDue['beyond_grace_balance'], 2);
        $dueSoon = $this->db->fetchOne(
            "SELECT COUNT(*) AS n, COALESCE(SUM(monthly_payment), 0) AS amount FROM loans
             WHERE tenant_id = ? AND status IN ('active','delinquent','default') AND next_payment_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)", [$tenantId]);

        // Loan activity this month / year
        $disbMonth = $this->db->fetchOne(
            "SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS total FROM transactions
             WHERE tenant_id = ? AND type = 'loan_disbursement' AND status = 'completed' AND created_at >= ?", [$tenantId, $monthStart]);
        $payRows = $this->db->fetchAll(
            "SELECT amount, metadata, created_at FROM transactions
             WHERE tenant_id = ? AND type = 'loan_payment' AND status = 'completed' AND created_at >= ?", [$tenantId, $yearStart]);
        $loanMonth = ['payments_count' => 0, 'payments_total' => 0.0, 'interest' => 0.0, 'principal' => 0.0];
        $interestYtd = 0.0;
        foreach ($payRows as $p) {
            $m = json_decode((string) ($p['metadata'] ?? ''), true) ?: [];
            $i = (float) ($m['interest'] ?? 0);
            $interestYtd += $i;
            if (substr((string) $p['created_at'], 0, 10) >= $monthStart) {
                $loanMonth['payments_count']++;
                $loanMonth['payments_total'] += (float) $p['amount'];
                $loanMonth['interest'] += $i;
                $loanMonth['principal'] += (float) ($m['principal'] ?? ((float) $p['amount'] - $i));
            }
        }
        foreach (['payments_total', 'interest', 'principal'] as $k) $loanMonth[$k] = round($loanMonth[$k], 2);
        $loanMonth['disbursed_count'] = (int) $disbMonth['n'];
        $loanMonth['disbursed_total'] = round((float) $disbMonth['total'], 2);
        $loanMonth['installments_due'] = round((float) $active['installments'], 2);
        $loanMonth['collection_rate'] = $loanMonth['installments_due'] > 0 ? round($loanMonth['payments_total'] / $loanMonth['installments_due'] * 100, 1) : null;

        // --- Cash position ---
        $cashPosition = []; $cashTotal = 0.0;
        try {
            $cashPosition = $this->db->fetchAll(
                "SELECT type, COALESCE(SUM(balance), 0) AS total, COUNT(*) AS n FROM fund_locations WHERE tenant_id = ? AND status = 'active' GROUP BY type", [$tenantId]);
            foreach ($cashPosition as $c) $cashTotal += (float) $c['total'];
        } catch (\Throwable) {
            // Cash management not set up yet
        }

        // --- Money in / out this month and today (completed only) ---
        $monthFlow = $this->db->fetchAll(
            "SELECT type, COUNT(*) AS count, COALESCE(SUM(amount), 0) AS total FROM transactions
             WHERE tenant_id = ? AND status = 'completed' AND type IN ('deposit','withdrawal') AND created_at >= ?
             GROUP BY type", [$tenantId, $monthStart]);
        $monthDeposits = 0.0; $monthWithdrawals = 0.0;
        foreach ($monthFlow as $f) { if ($f['type'] === 'deposit') $monthDeposits = (float) $f['total']; else $monthWithdrawals = (float) $f['total']; }

        $todayStats = [
            'today_deposits'     => $col("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE tenant_id = ? AND type = 'deposit' AND status = 'completed' AND DATE(created_at) = ?", [$tenantId, $todayDate]),
            'today_withdrawals'  => $col("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE tenant_id = ? AND type = 'withdrawal' AND status = 'completed' AND DATE(created_at) = ?", [$tenantId, $todayDate]),
            'today_loan_payments' => $col("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE tenant_id = ? AND type = 'loan_payment' AND status = 'completed' AND DATE(created_at) = ?", [$tenantId, $todayDate]),
            'today_transactions' => $cnt("SELECT COUNT(*) FROM transactions WHERE tenant_id = ? AND status = 'completed' AND DATE(created_at) = ?", [$tenantId, $todayDate]),
            'new_members_today'  => $cnt("SELECT COUNT(*) FROM users WHERE tenant_id = ? AND role = 'member' AND DATE(created_at) = ?", [$tenantId, $todayDate]),
        ];

        // --- Charts ---
        $recentTransactions = $this->db->fetchAll(
            "SELECT t.id, t.reference_number, t.type, t.amount, t.status, t.created_at, a.account_number
             FROM transactions t LEFT JOIN accounts a ON a.id = t.account_id
             WHERE t.tenant_id = ? ORDER BY t.created_at DESC LIMIT 10", [$tenantId]);
        $transactionTrend = $this->db->fetchAll(
            "SELECT DATE(created_at) AS date, COUNT(*) AS count, COALESCE(SUM(amount), 0) AS total
             FROM transactions WHERE tenant_id = ? AND status = 'completed' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
             GROUP BY DATE(created_at) ORDER BY date", [$tenantId]);
        $accountTypes = $this->db->fetchAll(
            "SELECT account_type, COUNT(*) AS count, COALESCE(SUM(balance), 0) AS total_balance
             FROM accounts WHERE tenant_id = ? AND status <> 'closed' AND account_type <> 'loan' GROUP BY account_type", [$tenantId]);
        $loanStatus = $this->db->fetchAll(
            "SELECT status, COUNT(*) AS count, COALESCE(SUM(outstanding_balance), 0) AS total FROM loans WHERE tenant_id = ? GROUP BY status", [$tenantId]);

        return Response::ok([
            'members'                => $totalMembers,
            'members_total'          => $allMembers,
            'members_new_month'      => $newMembersMonth,
            'active_accounts'        => $totalAccounts,
            'total_deposits'         => round($totalDeposits, 2),
            'total_shares'           => round($totalShares, 2),
            'member_funds'           => $memberFunds,
            'active_loans'           => $totalLoans,
            'total_loan_balance'     => round($totalLoanBalance, 2),
            'loans_to_funds_pct'     => $memberFunds > 0 ? round($totalLoanBalance / $memberFunds * 100, 1) : null,
            'pending_loan_apps'      => $pendingLoans,
            'approved_undisbursed'   => ['count' => (int) $approved['n'], 'amount' => round((float) $approved['amount'], 2)],
            'past_due'               => $pastDue,
            'delinquent_loans'       => $pastDue['beyond_grace_count'],
            'due_next_7_days'        => ['count' => (int) $dueSoon['n'], 'amount' => round((float) $dueSoon['amount'], 2)],
            'loan_month'             => $loanMonth,
            'interest_ytd'           => round($interestYtd, 2),
            'month'                  => ['deposits' => round($monthDeposits, 2), 'withdrawals' => round($monthWithdrawals, 2), 'net' => round($monthDeposits - $monthWithdrawals, 2)],
            'cash_total'             => round($cashTotal, 2),
            'cash_position'          => $cashPosition,
            'recent_transactions'    => $recentTransactions,
            'transaction_trend'      => $transactionTrend,
            'deposit_vs_withdrawal'  => $monthFlow,
            'account_types'          => $accountTypes,
            'loan_status'            => $loanStatus,
            'today'                  => $todayStats,
            'as_of'                  => $todayDate,
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
                    u.member_number, u.address_line_1, u.address_line_2, u.city, u.state, u.zip_code,
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
            'email'                 => 'required|email',
            'first_name'            => 'required|string|max:100',
            'last_name'             => 'required|string|max:100',
            'phone'                 => 'nullable|string|max:20',
            'address_line_1'        => 'nullable|string|max:255',
            'address_line_2'        => 'nullable|string|max:255',
            'city'                  => 'nullable|string|max:100',
            'state'                 => 'nullable|string|max:2',
            'zip_code'              => 'nullable|string|max:10',
            'password'              => 'nullable|string|min:8|max:128',
            'role'                  => 'nullable|in:member,teller,manager,admin',
            'status'                => 'nullable|in:active,inactive',
            'force_password_change' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        $data = $validator->validated();
        $tenantId = $this->db->getTenantId();
        foreach (['address_line_1', 'address_line_2', 'city', 'state', 'zip_code'] as $f) {
            $data[$f] = isset($data[$f]) && trim((string) $data[$f]) !== '' ? trim((string) $data[$f]) : null;
        }

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
        $password = $data['password'] ?? 'changeme123';
        $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $forceChange = ($data['force_password_change'] ?? '0') === '1' ? 1 : 0;

        $this->db->execute(
            'INSERT INTO users (id, tenant_id, email, password_hash, first_name, last_name, phone,
                                address_line_1, address_line_2, city, state, zip_code,
                                role, status, force_password_change, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $id, $tenantId, $data['email'], $passwordHash,
                $data['first_name'], $data['last_name'],
                $data['phone'] ?? null,
                $data['address_line_1'], $data['address_line_2'], $data['city'], $data['state'], $data['zip_code'],
                $data['role'] ?? 'member',
                $data['status'] ?? 'active',
                $forceChange,
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
                    u.member_number, u.address_line_1, u.address_line_2, u.city, u.state, u.zip_code,
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
            'email'                 => 'nullable|email',
            'first_name'            => 'nullable|string|max:100',
            'last_name'             => 'nullable|string|max:100',
            'phone'                 => 'nullable|string|max:20',
            'address_line_1'        => 'nullable|string|max:255',
            'address_line_2'        => 'nullable|string|max:255',
            'city'                  => 'nullable|string|max:100',
            'state'                 => 'nullable|string|max:2',
            'zip_code'              => 'nullable|string|max:10',
            'password'              => 'nullable|string|min:8|max:128',
            'role'                  => 'nullable|in:admin,manager,teller,member',
            'status'                => 'nullable|in:active,inactive,suspended',
            'force_password_change' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        $data = $validator->validated();
        if (empty($data)) {
            return Response::error('No fields to update', 422);
        }
        // Blank address fields clear the stored value
        foreach (['address_line_1', 'address_line_2', 'city', 'state', 'zip_code'] as $f) {
            if (array_key_exists($f, $data) && trim((string) $data[$f]) === '') {
                $data[$f] = null;
            }
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

        if (isset($data['password'])) {
            $data['password_hash'] = password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]);
            $data['password_changed_at'] = date('Y-m-d H:i:s');
            unset($data['password']);
        }
        if (isset($data['force_password_change'])) {
            $data['force_password_change'] = $data['force_password_change'] === '1' ? 1 : 0;
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
        if (strlen($to) === 10) {
            $to .= ' 23:59:59';   // include the whole last day
        }

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
             WHERE tenant_id = ? AND status = ? AND created_at BETWEEN ? AND ?
             GROUP BY type
             ORDER BY total_amount DESC',
            [$tenantId, 'completed', $from, $to],
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

    public function reconcile(Request $request): Response
    {
        $tenantId = $this->db->getTenantId();
        $dryRun = $request->query('dry_run', '0') === '1';

        try {
            $service = new ReconciliationService();
            $results = $service->reconcileAll($tenantId, $dryRun);

            $msg = $dryRun
                ? "Dry run complete. {$results['total_fixes']} discrepancies found."
                : "Reconciliation complete. {$results['total_fixes']} balances corrected.";

            return Response::ok($results, $msg);
        } catch (\Throwable $e) {
            return Response::error('Reconciliation failed: ' . $e->getMessage(), 500);
        }
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

    public function testDbConnection(Request $request): Response
    {
        $validator = new Validator($request->all(), [
            'host'     => 'required|string',
            'port'     => 'required|string',
            'database' => 'required|string',
            'username' => 'required|string',
            'password' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        $data = $validator->validated();

        try {
            $dsn = "mysql:host={$data['host']};port={$data['port']};dbname={$data['database']};charset=utf8mb4";
            $pdo = new \PDO($dsn, $data['username'], $data['password'] ?? '', [
                \PDO::ATTR_TIMEOUT => 5,
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
            $version = $pdo->query('SELECT VERSION()')->fetchColumn();
            $tables = $pdo->query('SHOW TABLES')->rowCount();
            return Response::ok([
                'connected' => true,
                'version'   => $version,
                'tables'    => $tables,
            ], 'Connection successful.');
        } catch (\Throwable $e) {
            return Response::error('Connection failed: ' . $e->getMessage(), 400);
        }
    }

    public function backup(Request $request): Response
    {
        $host = env('DB_HOST', '127.0.0.1');
        $port = env('DB_PORT', '3306');
        $db   = env('DB_DATABASE', 'synccu');
        $user = env('DB_USERNAME', 'root');
        $pass = env('DB_PASSWORD', '');

        $tmpFile = tempnam(sys_get_temp_dir(), 'synccu_backup_') . '.sql';

        $passArg = $pass !== '' ? "-p" . escapeshellarg($pass) : '';
        $cmd = sprintf(
            'mysqldump --single-transaction --routines --triggers -h %s -P %s -u %s %s %s > %s 2>&1',
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($user),
            $passArg,
            escapeshellarg($db),
            escapeshellarg($tmpFile),
        );

        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            @unlink($tmpFile);
            return Response::error('Backup failed: ' . implode("\n", $output), 500);
        }

        $content = file_get_contents($tmpFile);
        @unlink($tmpFile);

        $filename = 'synccu-backup-' . date('Y-m-d-His') . '.sql';

        return Response::raw($content, 200, [
            'Content-Type'        => 'application/sql',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Length'      => (string) strlen($content),
        ]);
    }

    public function restore(Request $request): Response
    {
        $rawBody = file_get_contents('php://input');

        if (empty($rawBody) || strlen($rawBody) < 20) {
            return Response::error('No SQL data received.', 400);
        }

        if (!str_contains($rawBody, 'INSERT INTO') && !str_contains($rawBody, 'CREATE TABLE')) {
            return Response::error('File does not appear to be a valid SQL backup.', 400);
        }

        $host = env('DB_HOST', '127.0.0.1');
        $port = env('DB_PORT', '3306');
        $db   = env('DB_DATABASE', 'synccu');
        $user = env('DB_USERNAME', 'root');
        $pass = env('DB_PASSWORD', '');

        $tmpFile = tempnam(sys_get_temp_dir(), 'synccu_restore_') . '.sql';
        file_put_contents($tmpFile, $rawBody);

        $passArg = $pass !== '' ? "-p" . escapeshellarg($pass) : '';
        $cmd = sprintf(
            'mysql -h %s -P %s -u %s %s %s < %s 2>&1',
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($user),
            $passArg,
            escapeshellarg($db),
            escapeshellarg($tmpFile),
        );

        exec($cmd, $output, $exitCode);
        @unlink($tmpFile);

        if ($exitCode !== 0) {
            return Response::error('Restore failed: ' . implode("\n", $output), 500);
        }

        return Response::ok(['restored' => true], 'Database restored successfully.');
    }
}
