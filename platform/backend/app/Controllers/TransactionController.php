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
     * GET /api/v1/transactions
     *
     * List transactions for the tenant with optional filters.
     */
    public function index(Request $request): Response
    {
        $db       = \App\Core\Database::getInstance();
        $tenantId = $db->getTenantId();
        $page     = max(1, (int) ($request->query('page', '1')));
        $perPage  = min(100, max(1, (int) ($request->query('per_page', '20'))));
        $offset   = ($page - 1) * $perPage;

        $params = [$tenantId];
        $where  = 'WHERE t.tenant_id = ?';

        $type = $request->query('type');
        if ($type !== null && $type !== '' && $type !== 'all') {
            $where .= ' AND t.type = ?';
            $params[] = $type;
        }

        $status = $request->query('status');
        if ($status !== null && $status !== '' && $status !== 'all') {
            $where .= ' AND t.status = ?';
            $params[] = $status;
        }

        $search = $request->query('search');
        if ($search !== null && $search !== '') {
            $where .= ' AND (t.description LIKE ? OR t.reference_number LIKE ?)';
            $term = "%{$search}%";
            $params[] = $term;
            $params[] = $term;
        }

        $from = $request->query('from');
        if ($from !== null && $from !== '') {
            $where .= ' AND t.created_at >= ?';
            $params[] = $from;
        }

        $to = $request->query('to');
        if ($to !== null && $to !== '') {
            $where .= ' AND t.created_at <= ?';
            $params[] = $to . ' 23:59:59';
        }

        $transactions = $db->fetchAll(
            "SELECT t.id, t.account_id, t.type, t.amount, t.balance_after,
                    t.description, t.reference_number, t.status, t.created_at,
                    a.account_number, a.name AS account_name,
                    CONCAT(u.first_name, ' ', u.last_name) AS member_name
             FROM transactions t
             LEFT JOIN accounts a ON a.id = t.account_id
             LEFT JOIN users u ON u.id = a.user_id
             {$where}
             ORDER BY t.created_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params,
        );

        $total = (int) $db->fetchColumn(
            "SELECT COUNT(*) FROM transactions t {$where}",
            $params,
        );

        return Response::paginated($transactions, $total, $page, $perPage);
    }

    /**
     * POST /api/v1/transactions
     *
     * Create a transaction (deposit, withdrawal, or transfer) from unified endpoint.
     */
    public function store(Request $request): Response
    {
        $type = $request->input('type', '');

        return match ($type) {
            'deposit'    => $this->deposit($request),
            'withdrawal' => $this->withdraw($request),
            'transfer'   => $this->transfer($request),
            default      => Response::error('Invalid transaction type. Use: deposit, withdrawal, or transfer', 422),
        };
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
