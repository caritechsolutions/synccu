<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\AccountService;
use App\Services\TransactionService;

/**
 * Account REST controller.
 */
final class AccountController
{
    private AccountService $accounts;
    private TransactionService $transactions;

    public function __construct()
    {
        $this->accounts     = new AccountService();
        $this->transactions = new TransactionService();
    }

    /**
     * GET /api/accounts
     *
     * List accounts for the authenticated user.
     */
    public function index(Request $request): Response
    {
        $userId  = $request->getAttribute('user_id');
        $page    = max(1, (int) ($request->query('page', '1')));
        $perPage = min(100, max(1, (int) ($request->query('per_page', '20'))));

        $result = $this->accounts->getByUser($userId, $page, $perPage);

        return Response::paginated($result['items'], $result['total'], $page, $perPage);
    }

    /**
     * POST /api/accounts
     *
     * Create a new account.
     */
    public function store(Request $request): Response
    {
        $validator = new Validator($request->all(), [
            'account_type' => 'required|in:checking,savings,money_market,cd,shares',
            'name'         => 'nullable|string|max:100',
            'currency'     => 'nullable|in:USD,EUR,GBP,CAD',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        try {
            $data = $validator->validated();
            $data['user_id'] = $request->getAttribute('user_id');

            $account = $this->accounts->create($data, $request->getAttribute('tenant_id'));

            return Response::created($account, 'Account created successfully');
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 400);
        }
    }

    /**
     * GET /api/accounts/{id}
     *
     * Get account details.
     */
    public function show(Request $request): Response
    {
        $accountId = $request->param('id');
        $userId    = $request->getAttribute('user_id');
        $role      = $request->getAttribute('role');

        $account = $this->accounts->findById($accountId);

        if ($account === null) {
            return Response::error('Account not found', 404);
        }

        // Non-admin users can only view their own accounts
        if ($role !== 'admin' && $account['user_id'] !== $userId) {
            return Response::error('Forbidden', 403);
        }

        return Response::ok($account);
    }

    /**
     * PUT /api/accounts/{id}
     *
     * Update account details.
     */
    public function update(Request $request): Response
    {
        $accountId = $request->param('id');
        $userId    = $request->getAttribute('user_id');
        $role      = $request->getAttribute('role');

        // Verify ownership or admin
        $account = $this->accounts->findById($accountId);
        if ($account === null) {
            return Response::error('Account not found', 404);
        }

        if ($role !== 'admin' && $account['user_id'] !== $userId) {
            return Response::error('Forbidden', 403);
        }

        $validator = new Validator($request->all(), [
            'name'   => 'nullable|string|max:100',
            'status' => 'nullable|in:active,frozen,dormant,closed',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        // Only admins can change status
        if ($request->has('status') && $role !== 'admin') {
            return Response::error('Only administrators can change account status', 403);
        }

        try {
            $updated = $this->accounts->update($accountId, $validator->validated());
            return Response::ok($updated, 'Account updated successfully');
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 400);
        }
    }

    /**
     * GET /api/accounts/{id}/transactions
     *
     * Get transaction history for an account.
     */
    public function transactions(Request $request): Response
    {
        $accountId = $request->param('id');
        $userId    = $request->getAttribute('user_id');
        $role      = $request->getAttribute('role');

        // Verify ownership or admin
        if ($role !== 'admin' && !$this->accounts->verifyOwnership($accountId, $userId)) {
            return Response::error('Forbidden', 403);
        }

        $page    = max(1, (int) ($request->query('page', '1')));
        $perPage = min(100, max(1, (int) ($request->query('per_page', '20'))));

        $result = $this->transactions->getByAccount($accountId, $page, $perPage);

        return Response::paginated($result['items'], $result['total'], $page, $perPage);
    }
}
