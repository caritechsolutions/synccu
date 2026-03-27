<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\AccountService;
use App\Services\TransactionService;

/**
 * Transaction REST controller.
 */
final class TransactionController
{
    private TransactionService $transactions;
    private AccountService $accounts;

    public function __construct()
    {
        $this->transactions = new TransactionService();
        $this->accounts     = new AccountService();
    }

    /**
     * POST /api/transactions/deposit
     */
    public function deposit(Request $request): Response
    {
        $validator = new Validator($request->all(), [
            'account_id'  => 'required|string',
            'amount'      => 'required|numeric|positive',
            'description' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        $userId = $request->getAttribute('user_id');
        $role   = $request->getAttribute('role');

        // Members can only deposit to their own accounts
        if ($role !== 'admin' && $role !== 'teller') {
            if (!$this->accounts->verifyOwnership($request->input('account_id'), $userId)) {
                return Response::error('You can only deposit to your own accounts', 403);
            }
        }

        try {
            $result = $this->transactions->deposit(
                $request->input('account_id'),
                (float) $request->input('amount'),
                $request->input('description', ''),
                $userId,
            );

            return Response::created($result, 'Deposit successful');
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 400);
        }
    }

    /**
     * POST /api/transactions/withdraw
     */
    public function withdraw(Request $request): Response
    {
        $validator = new Validator($request->all(), [
            'account_id'  => 'required|string',
            'amount'      => 'required|numeric|positive',
            'description' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        $userId = $request->getAttribute('user_id');
        $role   = $request->getAttribute('role');

        if ($role !== 'admin' && $role !== 'teller') {
            if (!$this->accounts->verifyOwnership($request->input('account_id'), $userId)) {
                return Response::error('You can only withdraw from your own accounts', 403);
            }
        }

        try {
            $result = $this->transactions->withdraw(
                $request->input('account_id'),
                (float) $request->input('amount'),
                $request->input('description', ''),
                $userId,
            );

            return Response::created($result, 'Withdrawal successful');
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 400);
        }
    }

    /**
     * POST /api/transactions/transfer
     */
    public function transfer(Request $request): Response
    {
        $validator = new Validator($request->all(), [
            'from_account_id' => 'required|string',
            'to_account_id'   => 'required|string',
            'amount'          => 'required|numeric|positive',
            'description'     => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        $userId = $request->getAttribute('user_id');
        $role   = $request->getAttribute('role');

        // Members can only transfer from their own accounts
        if ($role !== 'admin' && $role !== 'teller') {
            if (!$this->accounts->verifyOwnership($request->input('from_account_id'), $userId)) {
                return Response::error('You can only transfer from your own accounts', 403);
            }
        }

        try {
            $result = $this->transactions->transfer(
                $request->input('from_account_id'),
                $request->input('to_account_id'),
                (float) $request->input('amount'),
                $request->input('description', ''),
                $userId,
            );

            return Response::created($result, 'Transfer successful');
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 400);
        }
    }

    /**
     * GET /api/transactions/{id}
     */
    public function show(Request $request): Response
    {
        $transactionId = $request->param('id');
        $userId        = $request->getAttribute('user_id');
        $role          = $request->getAttribute('role');

        $transaction = $this->transactions->findById($transactionId);

        if ($transaction === null) {
            return Response::error('Transaction not found', 404);
        }

        // Non-admin users can only view their own transactions
        if ($role !== 'admin' && ($transaction['user_id'] ?? null) !== $userId) {
            return Response::error('Forbidden', 403);
        }

        return Response::ok($transaction);
    }
}
