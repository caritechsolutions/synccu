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
            'SELECT COALESCE(SUM(remaining_balance), 0) FROM loans WHERE tenant_id = ? AND status IN (?, ?)',
            [$tenantId, 'approved', 'active'],
        ) ?? 0);

        $pendingLoans = (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM loans WHERE tenant_id = ? AND status = ?',
            [$tenantId, 'pending'],
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

        return Response::ok([
            'members'              => $totalMembers,
            'active_accounts'      => $totalAccounts,
            'total_deposits'       => round($totalDeposits, 2),
            'active_loans'         => $totalLoans,
            'total_loan_balance'   => round($totalLoanBalance, 2),
            'pending_loan_apps'    => $pendingLoans,
            'delinquent_loans'     => $delinquentLoans,
            'recent_transactions'  => $recentTransactions,
        ]);
    }

    /**
     * GET /api/admin/users
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
            "SELECT u.id, u.email, u.first_name, u.last_name, u.role, u.status,
                    u.last_login_at, u.created_at
             FROM users u {$where}
             ORDER BY u.created_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params,
        );

        $totalParams = $params; // same filters
        $total = (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM users u {$where}",
            $totalParams,
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
            'role'   => 'nullable|in:admin,manager,teller,member',
            'status' => 'nullable|in:active,inactive,suspended',
            'first_name' => 'nullable|string|max:100',
            'last_name'  => 'nullable|string|max:100',
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
                    COALESCE(SUM(amount), 0) AS total_amount,
                    COALESCE(SUM(remaining_balance), 0) AS total_remaining
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
                    l.loan_type, l.amount, l.remaining_balance,
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
