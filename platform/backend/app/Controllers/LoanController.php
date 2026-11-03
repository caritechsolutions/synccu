<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\LoanService;
use App\Services\SupervisorAuthService;

/**
 * Loan REST controller.
 */
final class LoanController
{
    private LoanService $loans;
    private SupervisorAuthService $supervisor;

    public function __construct()
    {
        $this->loans = new LoanService();
        $this->supervisor = new SupervisorAuthService();
    }

    /**
     * GET /api/loans
     *
     * List loans for the authenticated user (or all loans for admins).
     */
    public function index(Request $request): Response
    {
        $userId  = $request->getAttribute('user_id');
        $role    = $request->getAttribute('role');
        $page    = max(1, (int) ($request->query('page', '1')));
        $perPage = min(100, max(1, (int) ($request->query('per_page', '20'))));
        $status  = $request->query('status');

        if (in_array($role, ['admin', 'super_admin', 'manager', 'teller'], true)) {
            $result = $this->adminListLoans($request, $page, $perPage);
        } else {
            $result = $this->loans->getByUser($userId, $page, $perPage);
        }

        return Response::paginated($result['items'], $result['total'], $page, $perPage);
    }

    /**
     * Admin-level loan listing with member names and status counts.
     */
    private function adminListLoans(Request $request, int $page, int $perPage): array
    {
        $db = \App\Core\Database::getInstance();
        $tenantId = $db->getTenantId();
        $offset = ($page - 1) * $perPage;
        $params = [$tenantId];
        $where = 'WHERE l.tenant_id = ?';

        $status = $request->query('status');
        if ($status !== null && $status !== '' && $status !== 'all') {
            $where .= ' AND l.status = ?';
            $params[] = $status;
        }

        $type = $request->query('type');
        if ($type !== null && $type !== '' && $type !== 'all') {
            $where .= ' AND l.loan_type = ?';
            $params[] = $type;
        }

        $search = $request->query('search');
        if ($search !== null && $search !== '') {
            $where .= ' AND (l.loan_number LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)';
            $term = "%{$search}%";
            $params = [...$params, $term, $term, $term];
        }

        $items = $db->fetchAll(
            "SELECT l.*, CONCAT(u.first_name, ' ', u.last_name) AS member_name
             FROM loans l
             LEFT JOIN users u ON u.id = l.user_id AND u.tenant_id = l.tenant_id
             {$where}
             ORDER BY l.created_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params,
        );

        $total = (int) $db->fetchColumn(
            "SELECT COUNT(*) FROM loans l
             LEFT JOIN users u ON u.id = l.user_id AND u.tenant_id = l.tenant_id
             {$where}",
            $params,
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * POST /api/loans/apply
     *
     * Submit a loan application.
     */
    public function apply(Request $request): Response
    {
        $role = $request->getAttribute('role');
        $isAdmin = in_array($role, ['admin', 'super_admin', 'manager', 'teller'], true);

        $input = $request->all();

        // A loan type code (A-O from the loan_types table) resolves to a platform category
        $typeCode = strtoupper(trim((string) ($input['loan_type_code'] ?? '')));
        $typeRow = null;
        if ($typeCode !== '') {
            $db = \App\Core\Database::getInstance();
            try {
                $typeRow = $db->fetchOne(
                    'SELECT code, description, category FROM loan_types WHERE tenant_id = ? AND code = ? AND is_active = 1',
                    [$db->getTenantId(), $typeCode],
                );
            } catch (\Throwable) {
                $typeRow = null;
            }
            if ($typeRow === null) {
                return Response::error("Unknown loan type code '{$typeCode}'.", 422);
            }
            $input['loan_type'] = $typeRow['category'];
        }

        $validator = new Validator($input, [
            'user_id'          => $isAdmin ? 'required|string' : 'nullable|string',
            'loan_type'        => 'required|in:personal,auto,mortgage,business,education,credit_line',
            'loan_type_code'   => 'nullable|string|max:2',
            'amount'           => 'required|numeric|positive',
            'interest_rate'    => 'required|numeric',
            'term_months'      => 'required|integer|min:1|max:360',
            'purpose'          => 'nullable|string|max:500',
            'application_date' => 'nullable|string|max:19',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        try {
            $data = $validator->validated();
            $memberId = ($isAdmin && !empty($data['user_id']))
                ? $data['user_id']
                : $request->getAttribute('user_id');
            unset($data['user_id']);

            if ($typeRow !== null) {
                $data['loan_type_code'] = $typeRow['code'];
                $desc = trim((string) ($data['purpose'] ?? ''));
                $data['purpose'] = $desc === '' ? $typeRow['description'] : $desc;
            } else {
                unset($data['loan_type_code']);
            }

            // Backdated application (supervisor override required; staff only)
            unset($data['application_date']);
            if ($isAdmin) {
                $backdate = $this->supervisor->resolveBackdate($input, 'application_date');
                if ($backdate !== null) {
                    $data['application_date'] = $backdate['datetime'];
                    $data['backdate'] = $backdate;
                }
            }

            $loan = $this->loans->apply(
                $data,
                $request->getAttribute('tenant_id'),
                $memberId,
            );

            return Response::created($loan, 'Loan application submitted');
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 400);
        }
    }

    /**
     * GET /api/loans/{id}
     *
     * Get loan details.
     */
    public function show(Request $request): Response
    {
        $loanId = $request->param('id');
        $userId = $request->getAttribute('user_id');
        $role   = $request->getAttribute('role');

        $loan = $this->loans->findById($loanId);

        if ($loan === null) {
            return Response::error('Loan not found', 404);
        }

        // Non-admin/manager users can only view their own loans
        if (!in_array($role, ['admin', 'super_admin', 'manager'], true) && $loan['user_id'] !== $userId) {
            return Response::error('Forbidden', 403);
        }

        // Include live accrued interest for active loans
        if (in_array($loan['status'], ['active', 'delinquent', 'approved'], true)) {
            $loan['accrued_interest'] = $this->loans->getAccruedInterest($loanId);
        }

        // Include member's non-loan accounts for disbursement/payment UI
        if (in_array($loan['status'], ['approved', 'active', 'delinquent'], true)) {
            $db = \App\Core\Database::getInstance();
            $tenantId = $db->getTenantId();
            $loan['member_accounts'] = $db->fetchAll(
                "SELECT id, account_number, name, account_type, balance
                 FROM accounts
                 WHERE user_id = ? AND tenant_id = ? AND account_type != 'loan' AND status = 'active'
                 ORDER BY account_type, name",
                [$loan['user_id'], $tenantId],
            );
        }

        // Include fund locations (vault, bank) as funding sources for disbursement
        if ($loan['status'] === 'approved' && in_array($role, ['admin', 'super_admin', 'manager'], true)) {
            $db = $db ?? \App\Core\Database::getInstance();
            $tenantId = $tenantId ?? $db->getTenantId();
            try {
                $loan['funding_locations'] = $db->fetchAll(
                    "SELECT id, type, name, balance, bank_name, bank_account_number
                     FROM fund_locations
                     WHERE tenant_id = ? AND status = 'active'
                     ORDER BY FIELD(type, 'vault', 'bank_account', 'drawer'), name",
                    [$tenantId],
                );
            } catch (\Throwable $e) {
                $loan['funding_locations'] = [];
            }
        }

        return Response::ok($loan);
    }

    /**
     * PUT /api/loans/{id}/approve
     *
     * Approve a loan application (admin/manager only).
     */
    public function approve(Request $request): Response
    {
        $loanId    = $request->param('id');
        $approvedBy = $request->getAttribute('user_id');

        try {
            $backdate = $this->supervisor->resolveBackdate($request->all(), 'approval_date');
            $loan = $this->loans->approve($loanId, $approvedBy, $backdate);
            return Response::ok($loan, 'Loan approved');
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 400);
        }
    }

    /**
     * POST /api/loans/{id}/disburse
     *
     * Disburse an approved loan to the member.
     */
    public function disburse(Request $request): Response
    {
        $validator = new Validator($request->all(), [
            'disbursement_method' => 'required|in:account,cash,check,wire,ach',
            'target_account_id'  => 'nullable|string',
            'source_location_id' => 'nullable|string',
            'check_number'       => 'nullable|string|max:50',
            'disbursement_date'  => 'nullable|string|max:19',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        $loanId     = $request->param('id');
        $disbursedBy = $request->getAttribute('user_id');
        $data = $validator->validated();

        if ($data['disbursement_method'] === 'account' && empty($data['target_account_id'])) {
            return Response::error('Target account is required when disbursing to an account.', 422);
        }

        try {
            $backdate = $this->supervisor->resolveBackdate($request->all(), 'disbursement_date');
            if ($backdate !== null) {
                $data['backdate'] = $backdate;
            } elseif (empty($data['source_location_id']) || $data['source_location_id'] === 'none') {
                return Response::error('A funding source (vault/bank/drawer) is required for disbursement.', 422);
            }
            unset($data['disbursement_date']);

            $loan = $this->loans->disburse($loanId, $disbursedBy, $data);
            return Response::ok($loan, 'Loan disbursed successfully');
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 400);
        }
    }

    /**
     * PUT /api/loans/{id}/deny
     *
     * Deny a loan application (admin/manager only).
     */
    public function deny(Request $request): Response
    {
        $validator = new Validator($request->all(), [
            'reason' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        $loanId  = $request->param('id');
        $deniedBy = $request->getAttribute('user_id');

        try {
            $loan = $this->loans->deny($loanId, $deniedBy, $request->input('reason', ''));
            return Response::ok($loan, 'Loan application denied');
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 400);
        }
    }

    /**
     * POST /api/loans/{id}/payment
     *
     * Make a loan payment.
     */
    public function payment(Request $request): Response
    {
        $validator = new Validator($request->all(), [
            'amount'          => 'required|numeric|positive',
            'from_account_id' => 'nullable|string',
            'method'          => 'nullable|in:cash,account,check,wire,ach',
            'drawer_id'       => 'nullable|string',
            'denominations'   => 'nullable',
            'description'     => 'nullable|string|max:255',
            'payment_date'    => 'nullable|string|max:19',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        $loanId = $request->param('id');
        $userId = $request->getAttribute('user_id');
        $role   = $request->getAttribute('role');
        $isStaff = in_array($role, ['admin', 'super_admin', 'manager', 'teller'], true);

        $fromAccountId = $request->input('from_account_id') ?: null;
        $method = $request->input('method') ?: ($fromAccountId ? 'account' : 'cash');

        // Members may only pay their own loans, from their own accounts, today
        if (!$isStaff) {
            $loan = $this->loans->findById($loanId);
            if ($loan === null || $loan['user_id'] !== $userId) {
                return Response::error('Forbidden', 403);
            }
            if (!$fromAccountId) {
                return Response::error('Select one of your accounts to pay from.', 422);
            }
            if (!(new \App\Services\AccountService())->verifyOwnership($fromAccountId, $userId)) {
                return Response::error('You can only pay from your own accounts.', 403);
            }
            $method = 'account';
        }

        // Only an account-funded payment debits an account
        if ($method !== 'account') {
            $fromAccountId = null;
        } elseif (!$fromAccountId) {
            return Response::error('Select the member account to pay from.', 422);
        }

        try {
            $options = [
                'method'        => $method,
                'description'   => $request->input('description', ''),
            ];
            if ($isStaff) {
                $options['drawer_id']     = $request->input('drawer_id') ?: null;
                $options['denominations'] = $request->input('denominations');
                $backdate = $this->supervisor->resolveBackdate($request->all(), 'payment_date');
                if ($backdate !== null) {
                    $options['payment_date'] = $backdate['date'];
                    $options['backdate']     = $backdate;
                }
            }

            $result = $this->loans->makePayment(
                $loanId,
                (float) $request->input('amount'),
                $fromAccountId,
                $userId,
                $options,
            );

            return Response::created($result, 'Payment processed');
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 400);
        }
    }

    /**
     * GET /api/loans/{id}/schedule
     *
     * Get the amortisation schedule for a loan.
     */
    public function schedule(Request $request): Response
    {
        $loanId = $request->param('id');
        $userId = $request->getAttribute('user_id');
        $role   = $request->getAttribute('role');

        $loan = $this->loans->findById($loanId);
        if ($loan === null) {
            return Response::error('Loan not found', 404);
        }

        if (!in_array($role, ['admin', 'super_admin', 'manager'], true) && $loan['user_id'] !== $userId) {
            return Response::error('Forbidden', 403);
        }

        // Only disbursed loans have a schedule
        if (in_array($loan['status'], ['active', 'delinquent', 'paid_off'], true)) {
            $schedule = $this->loans->getSchedule($loanId);
        } else {
            $schedule = [];
        }

        return Response::ok([
            'loan_id'  => $loanId,
            'schedule' => $schedule,
        ]);
    }

    /**
     * GET /api/v1/loans/{id}/payments
     *
     * Get payment history for a loan.
     */
    public function paymentHistory(Request $request): Response
    {
        $loanId = $request->param('id');
        $userId = $request->getAttribute('user_id');
        $role   = $request->getAttribute('role');

        $loan = $this->loans->findById($loanId);
        if ($loan === null) {
            return Response::error('Loan not found', 404);
        }

        if (!in_array($role, ['admin', 'super_admin', 'manager'], true) && $loan['user_id'] !== $userId) {
            return Response::error('Forbidden', 403);
        }

        $payments = $this->loans->getPaymentHistory($loanId);

        return Response::ok($payments);
    }

    /**
     * GET /api/v1/loans/{id}/payoff
     *
     * Get a payoff quote for a loan.
     */
    public function payoffQuote(Request $request): Response
    {
        $loanId = $request->param('id');
        $userId = $request->getAttribute('user_id');
        $role   = $request->getAttribute('role');

        $loan = $this->loans->findById($loanId);
        if ($loan === null) {
            return Response::error('Loan not found', 404);
        }

        if (!in_array($role, ['admin', 'super_admin', 'manager'], true) && $loan['user_id'] !== $userId) {
            return Response::error('Forbidden', 403);
        }

        try {
            $quote = $this->loans->getPayoffQuote($loanId);
            return Response::ok($quote);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 400);
        }
    }

    /**
     * GET /api/v1/loans/export
     *
     * Export loans as CSV.
     */
    public function export(Request $request): Response
    {
        $db       = \App\Core\Database::getInstance();
        $tenantId = $db->getTenantId();
        $params   = [$tenantId];
        $where    = 'WHERE l.tenant_id = ?';

        $status = $request->query('status');
        if ($status !== null && $status !== '' && $status !== 'all') {
            $where .= ' AND l.status = ?';
            $params[] = $status;
        }

        $type = $request->query('type');
        if ($type !== null && $type !== '' && $type !== 'all') {
            $where .= ' AND l.loan_type = ?';
            $params[] = $type;
        }

        $search = $request->query('search');
        if ($search !== null && $search !== '') {
            $where .= ' AND (l.loan_number LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)';
            $term = "%{$search}%";
            $params = [...$params, $term, $term, $term];
        }

        $rows = $db->fetchAll(
            "SELECT l.*, CONCAT(u.first_name, ' ', u.last_name) AS member_name
             FROM loans l
             LEFT JOIN users u ON u.id = l.user_id AND u.tenant_id = l.tenant_id
             {$where}
             ORDER BY l.created_at DESC
             LIMIT 10000",
            $params,
        );

        $csv = "Loan #,Member,Type,Amount,Rate,Term,Outstanding,Monthly Payment,Status,Next Payment,Created\n";
        foreach ($rows as $r) {
            $csv .= implode(',', [
                $r['loan_number'],
                '"' . str_replace('"', '""', $r['member_name'] ?? '') . '"',
                $r['loan_type'],
                $r['principal_amount'],
                $r['interest_rate'],
                $r['term_months'],
                $r['outstanding_balance'],
                $r['monthly_payment'],
                $r['status'],
                $r['next_payment_date'] ?? '',
                $r['created_at'],
            ]) . "\n";
        }

        return Response::raw($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="loans-' . date('Y-m-d') . '.csv"',
        ]);
    }

    /**
     * PUT /api/v1/loans/{id}
     *
     * Update loan details (collateral, metadata, purpose, co-signer).
     */
    public function update(Request $request): Response
    {
        $validator = new Validator($request->all(), [
            'collateral'  => 'nullable',
            'metadata'    => 'nullable',
            'purpose'     => 'nullable|string|max:500',
            'co_signer_id' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        $loanId = $request->param('id');
        $userId = $request->getAttribute('user_id');
        $role   = $request->getAttribute('role');

        $loan = $this->loans->findById($loanId);
        if ($loan === null) {
            return Response::error('Loan not found', 404);
        }

        if (!in_array($role, ['admin', 'super_admin', 'manager'], true) && $loan['user_id'] !== $userId) {
            return Response::error('Forbidden', 403);
        }

        try {
            $updated = $this->loans->updateLoan($loanId, $validator->validated());
            return Response::ok($updated, 'Loan updated');
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 400);
        }
    }
}
