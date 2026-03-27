<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\LoanService;

/**
 * Loan REST controller.
 */
final class LoanController
{
    private LoanService $loans;

    public function __construct()
    {
        $this->loans = new LoanService();
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

        if (in_array($role, ['admin', 'manager'], true)) {
            $result = $this->loans->getAll($page, $perPage, $status);
        } else {
            $result = $this->loans->getByUser($userId, $page, $perPage);
        }

        return Response::paginated($result['items'], $result['total'], $page, $perPage);
    }

    /**
     * POST /api/loans/apply
     *
     * Submit a loan application.
     */
    public function apply(Request $request): Response
    {
        $validator = new Validator($request->all(), [
            'loan_type'     => 'required|in:personal,auto,mortgage,business,student,credit_line',
            'amount'        => 'required|numeric|positive',
            'interest_rate' => 'required|numeric',
            'term_months'   => 'required|integer|min:1|max:360',
            'purpose'       => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        try {
            $loan = $this->loans->apply(
                $validator->validated(),
                $request->getAttribute('tenant_id'),
                $request->getAttribute('user_id'),
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
        if (!in_array($role, ['admin', 'manager'], true) && $loan['user_id'] !== $userId) {
            return Response::error('Forbidden', 403);
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
            $loan = $this->loans->approve($loanId, $approvedBy);
            return Response::ok($loan, 'Loan approved and disbursed');
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
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        $loanId = $request->param('id');
        $userId = $request->getAttribute('user_id');

        try {
            $result = $this->loans->makePayment(
                $loanId,
                (float) $request->input('amount'),
                $request->input('from_account_id'),
                $userId,
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

        if (!in_array($role, ['admin', 'manager'], true) && $loan['user_id'] !== $userId) {
            return Response::error('Forbidden', 403);
        }

        // If loan is approved, fetch stored schedule; otherwise calculate a preview
        if (in_array($loan['status'], ['approved', 'active', 'delinquent', 'paid_off'], true)) {
            $schedule = $this->loans->getSchedule($loanId);
        } else {
            $schedule = $this->loans->calculateSchedule(
                (float) $loan['amount'],
                (float) $loan['interest_rate'],
                (int) $loan['term_months'],
            );
        }

        return Response::ok([
            'loan_id'  => $loanId,
            'schedule' => $schedule,
        ]);
    }
}
