<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\AccountService;
use App\Services\AccountProductService;
use App\Services\TransactionService;

/**
 * Account REST controller.
 */
final class AccountController
{
    private AccountService $accounts;
    private AccountProductService $products;
    private TransactionService $transactions;

    public function __construct()
    {
        $this->accounts     = new AccountService();
        $this->products     = new AccountProductService();
        $this->transactions = new TransactionService();
    }

    /**
     * GET /api/accounts
     *
     * List accounts. Admins/managers/tellers see all accounts with member names;
     * regular members see only their own.
     */
    public function index(Request $request): Response
    {
        $userId  = $request->getAttribute('user_id');
        $role    = $request->getAttribute('role');
        $page    = max(1, (int) ($request->query('page', '1')));
        $perPage = min(100, max(1, (int) ($request->query('per_page', '20'))));

        if (in_array($role, ['admin', 'super_admin', 'manager', 'teller'], true)) {
            $result = $this->adminListAccounts($request, $page, $perPage);
        } else {
            $result = $this->accounts->getByUser($userId, $page, $perPage);
        }

        return Response::paginated($result['items'], $result['total'], $page, $perPage);
    }

    /**
     * Admin-level account listing with member names and filters.
     */
    private function adminListAccounts(Request $request, int $page, int $perPage): array
    {
        $db = \App\Core\Database::getInstance();
        $tenantId = $db->getTenantId();
        $offset = ($page - 1) * $perPage;
        $params = [$tenantId];
        $where = 'WHERE a.tenant_id = ?';

        $type = $request->query('type');
        if ($type !== null && $type !== '' && $type !== 'all') {
            $where .= ' AND a.account_type = ?';
            $params[] = $type;
        }

        $status = $request->query('status');
        if ($status !== null && $status !== '' && $status !== 'all') {
            $where .= ' AND a.status = ?';
            $params[] = $status;
        }

        $search = $request->query('search');
        if ($search !== null && $search !== '') {
            $where .= ' AND (a.account_number LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)';
            $term = "%{$search}%";
            $params = [...$params, $term, $term, $term, $term];
        }

        $items = $db->fetchAll(
            "SELECT a.*, CONCAT(u.first_name, ' ', u.last_name) AS member_name, u.email AS member_email
             FROM accounts a
             LEFT JOIN users u ON u.id = a.user_id AND u.tenant_id = a.tenant_id
             {$where}
             ORDER BY member_name ASC, a.account_type ASC, a.created_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params,
        );

        $total = (int) $db->fetchColumn(
            "SELECT COUNT(*) FROM accounts a
             LEFT JOIN users u ON u.id = a.user_id AND u.tenant_id = a.tenant_id
             {$where}",
            $params,
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * POST /api/accounts
     *
     * Create a new account. Optionally linked to an account product.
     */
    public function store(Request $request): Response
    {
        $role = $request->getAttribute('role');
        $isAdmin = in_array($role, ['admin', 'super_admin', 'manager', 'teller'], true);

        $isInstitutional = (bool) $request->input('is_institutional', false);

        $validator = new Validator($request->all(), [
            'member_id'          => ($isAdmin && !$isInstitutional) ? 'required|string' : 'nullable|string',
            'account_type'       => 'required|in:checking,savings,permanent_shares,regular_shares',
            'account_product_id' => 'nullable|string',
            'name'               => $isInstitutional ? 'required|string|max:100' : 'nullable|string|max:100',
            'currency'           => 'nullable|in:USD,EUR,GBP,CAD,BBD,XCD,TTD,JMD,GYD',
            'initial_deposit'    => 'nullable|string|max:20',
            'earning_rate'       => 'nullable|string|max:10',
            'earning_interval'   => 'nullable|in:yearly,bi_annually,quarterly',
            'is_institutional'   => 'nullable',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        try {
            $data = $validator->validated();
            $tenantId = $request->getAttribute('tenant_id');

            if ($isInstitutional) {
                $data['user_id'] = $request->getAttribute('user_id');
                $data['is_institutional'] = true;
            } else {
                $data['user_id'] = $isAdmin && !empty($data['member_id'])
                    ? $data['member_id']
                    : $request->getAttribute('user_id');
            }
            unset($data['member_id']);

            $initialDeposit = (float) ($data['initial_deposit'] ?? 0);
            unset($data['initial_deposit']);

            // If a product is selected, pull earning fields from the product
            if (!empty($data['account_product_id'])) {
                $product = $this->products->findById($tenantId, $data['account_product_id']);
                if (!$product || $product['status'] !== 'active') {
                    return Response::error('Account product not found or inactive.', 404);
                }
                if ($product['expires_at'] && strtotime($product['expires_at']) < time()) {
                    return Response::error('This account product has expired.', 422);
                }
                if ($initialDeposit < (float) $product['min_opening_balance']) {
                    return Response::error('Minimum opening balance for this product is $' . number_format((float) $product['min_opening_balance'], 2), 422);
                }
                $data['account_type'] = $product['category'];
                $data['earning_type'] = $product['earning_type'];
                $data['earning_rate'] = (float) $product['earning_rate'];
                $data['earning_interval'] = $product['earning_interval'];
                $data['name'] = $data['name'] ?? $product['name'];
            } else {
                // Manual creation without product
                $isShares = in_array($data['account_type'], ['permanent_shares', 'regular_shares']);
                $data['earning_type'] = $isShares ? 'dividend' : 'interest';
                $data['earning_rate'] = (float) ($data['earning_rate'] ?? 0);
                $data['earning_interval'] = $data['earning_interval'] ?? 'yearly';
            }

            $account = $this->accounts->create($data, $tenantId);

            if ($initialDeposit > 0 && $account) {
                try {
                    $this->transactions->deposit(
                        $account['id'],
                        $initialDeposit,
                        'Initial deposit',
                        $request->getAttribute('user_id'),
                    );
                } catch (\Throwable $e) {
                    error_log('Initial deposit failed for account ' . $account['id'] . ': ' . $e->getMessage());
                    try {
                        $this->accounts->updateBalance($account['id'], $initialDeposit);
                    } catch (\Throwable $e2) {
                        error_log('Fallback balance update also failed: ' . $e2->getMessage());
                    }
                }
                $account = $this->accounts->findById($account['id']);
            }

            return Response::created($account, 'Account created successfully');
        } catch (\RuntimeException $e) {
            $code = is_int($e->getCode()) && $e->getCode() >= 400 ? $e->getCode() : 400;
            return Response::error($e->getMessage(), $code);
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
        if (!in_array($role, ['admin', 'super_admin'], true) && $account['user_id'] !== $userId) {
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

        // Back-office staff or the account owner may edit an account.
        $isStaff = in_array($role, ['admin', 'super_admin', 'manager', 'teller'], true);
        if (!$isStaff && $account['user_id'] !== $userId) {
            return Response::error('Forbidden', 403);
        }

        $validator = new Validator($request->all(), [
            'name'          => 'nullable|string|max:100',
            'purpose'       => 'nullable|string|max:100',
            'status'        => 'nullable|in:active,frozen,dormant,closed',
            'interest_rate' => 'nullable|string|max:10',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        try {
            $data = $validator->validated();

            // A submitted-but-unchanged status is a no-op: drop it so routine
            // saves don't require admin rights or trigger a transition check.
            if (isset($data['status']) && $data['status'] === $account['status']) {
                unset($data['status']);
            }
            // Only administrators may CHANGE account status.
            if (isset($data['status']) && !in_array($role, ['admin', 'super_admin'], true)) {
                return Response::error('Only administrators can change account status', 403);
            }

            // Convert interest rate from percentage to decimal
            if (isset($data['interest_rate'])) {
                $data['interest_rate'] = (float) $data['interest_rate'] / 100;
            }

            // Snapshot the pre-update state so the audit log records before -> after.
            $request->setAttribute('audit_old', $account);

            $updated = $this->accounts->update($accountId, $data);
            return Response::ok($updated, 'Account updated successfully');
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 400);
        }
    }

    // ------------------------------------------------------------------
    // Account Products
    // ------------------------------------------------------------------

    public function listProducts(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $filters = array_filter([
            'category' => $request->query('category'),
            'status'   => $request->query('status'),
        ]);
        $products = $this->products->list($tenantId, $filters);
        return Response::ok($products);
    }

    public function listAvailableProducts(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $category = $request->query('category');
        $products = $this->products->listAvailable($tenantId, $category);
        return Response::ok($products);
    }

    public function createProduct(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $validator = new Validator($request->all(), [
            'name'                => 'required|string|max:100',
            'code'                => 'required|string|max:20',
            'category'            => 'required|in:savings,checking,permanent_shares,regular_shares',
            'earning_rate'        => 'nullable|numeric',
            'earning_interval'    => 'nullable|in:yearly,bi_annually,quarterly',
            'min_opening_balance' => 'nullable|numeric',
            'description'         => 'nullable|string|max:500',
            'expires_at'          => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        try {
            $product = $this->products->create($tenantId, $validator->validated());
            return Response::created($product, 'Account product created.');
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 400);
        }
    }

    public function updateProduct(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $productId = $request->param('id');

        $validator = new Validator($request->all(), [
            'name'                => 'nullable|string|max:100',
            'code'                => 'nullable|string|max:20',
            'earning_rate'        => 'nullable|numeric',
            'earning_interval'    => 'nullable|in:yearly,bi_annually,quarterly',
            'min_opening_balance' => 'nullable|numeric',
            'description'         => 'nullable|string|max:500',
            'expires_at'          => 'nullable|string',
            'status'              => 'nullable|in:active,inactive',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        try {
            $product = $this->products->update($tenantId, $productId, $validator->validated());
            return Response::ok($product, 'Account product updated.');
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 400);
        }
    }

    public function deleteProduct(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $productId = $request->param('id');

        try {
            $this->products->delete($tenantId, $productId);
            return Response::ok(null, 'Account product deactivated.');
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 400);
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
        if (!in_array($role, ['admin', 'super_admin'], true) && !$this->accounts->verifyOwnership($accountId, $userId)) {
            return Response::error('Forbidden', 403);
        }

        $page    = max(1, (int) ($request->query('page', '1')));
        $perPage = min(100, max(1, (int) ($request->query('per_page', '20'))));

        $result = $this->transactions->getByAccount($accountId, $page, $perPage);

        return Response::paginated($result['items'], $result['total'], $page, $perPage);
    }
}
