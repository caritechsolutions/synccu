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
            "SELECT t.id, t.account_id, t.related_account_id, t.type, t.amount, t.balance_after,
                    t.description, t.reference_number, t.status, t.processed_by, t.created_at,
                    a.account_number, a.name AS account_name,
                    CONCAT(u.first_name, ' ', u.last_name) AS member_name,
                    ra.account_number AS related_account_number
             FROM transactions t
             LEFT JOIN accounts a ON a.id = t.account_id
             LEFT JOIN users u ON u.id = a.user_id
             LEFT JOIN accounts ra ON ra.id = t.related_account_id
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
            'account_id'       => 'required|string',
            'amount'           => 'required|numeric|positive',
            'description'      => 'nullable|string|max:255',
            'funding_source'   => 'nullable|in:cash,check,wire,ach,internal',
            'sof_source'       => 'nullable|string|max:100',
            'sof_details'      => 'nullable|string|max:1000',
            'sof_declared'     => 'nullable|string',
            'drawer_id'        => 'nullable|string',
            'denominations'    => 'nullable',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        $userId = $request->getAttribute('user_id');
        $role   = $request->getAttribute('role');

        // Members can only deposit to their own accounts
        if (!in_array($role, ['admin', 'super_admin', 'manager', 'teller'], true)) {
            if (!$this->accounts->verifyOwnership($request->input('account_id'), $userId)) {
                return Response::error('You can only deposit to your own accounts', 403);
            }
        }

        try {
            $metadata = array_filter([
                'funding_source'  => $request->input('funding_source', 'cash'),
                'drawer_id'       => $request->input('drawer_id'),
                'denominations'   => $request->input('denominations'),
            ]);

            $checksInput = $request->input('checks');
            if (is_array($checksInput) && !empty($checksInput)) {
                $metadata['checks'] = array_map(fn($c) => [
                    'number' => (string) ($c['number'] ?? ''),
                    'amount' => (float) ($c['amount'] ?? 0),
                ], $checksInput);
                $metadata['check_count'] = count($metadata['checks']);
            }

            // Source of Funds declaration
            $sofSource = $request->input('sof_source');
            if ($sofSource !== null && $sofSource !== '') {
                $metadata['sof'] = [
                    'source'   => $sofSource,
                    'details'  => $request->input('sof_details', ''),
                    'declared' => $request->input('sof_declared') === '1',
                    'declared_at' => date('Y-m-d H:i:s'),
                ];
            }

            $result = $this->transactions->deposit(
                $request->input('account_id'),
                (float) $request->input('amount'),
                $request->input('description', ''),
                $userId,
                $metadata,
            );

            return Response::created($result, 'Deposit successful');
        } catch (\RuntimeException $e) {
            $code = (int) $e->getCode(); return Response::error($e->getMessage(), $code >= 400 ? $code : 400);
        }
    }

    /**
     * POST /api/transactions/withdraw
     */
    public function withdraw(Request $request): Response
    {
        $validator = new Validator($request->all(), [
            'account_id'          => 'required|string',
            'amount'              => 'required|numeric|positive',
            'description'         => 'nullable|string|max:255',
            'disbursement_method' => 'required|in:cash,check,wire,ach',
            'check_number'        => 'nullable|string|max:50',
            'drawer_id'           => 'nullable|string',
            'denominations'       => 'nullable',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        $userId = $request->getAttribute('user_id');
        $role   = $request->getAttribute('role');

        if (!in_array($role, ['admin', 'super_admin', 'manager', 'teller'], true)) {
            if (!$this->accounts->verifyOwnership($request->input('account_id'), $userId)) {
                return Response::error('You can only withdraw from your own accounts', 403);
            }
        }

        try {
            $metadata = array_filter([
                'disbursement_method' => $request->input('disbursement_method'),
                'check_number'        => $request->input('check_number'),
                'drawer_id'           => $request->input('drawer_id'),
                'denominations'       => $request->input('denominations'),
            ]);

            $result = $this->transactions->withdraw(
                $request->input('account_id'),
                (float) $request->input('amount'),
                $request->input('description', ''),
                $userId,
                $metadata,
            );

            return Response::created($result, 'Withdrawal successful');
        } catch (\RuntimeException $e) {
            $code = (int) $e->getCode(); return Response::error($e->getMessage(), $code >= 400 ? $code : 400);
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
        if (!in_array($role, ['admin', 'super_admin', 'manager', 'teller'], true)) {
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
            $code = (int) $e->getCode(); return Response::error($e->getMessage(), $code >= 400 ? $code : 400);
        }
    }

    /**
     * POST /api/v1/transactions/expense
     *
     * Record an operating expense (admin/manager only).
     */
    public function recordExpense(Request $request): Response
    {
        $validator = new Validator($request->all(), [
            'category'    => 'required|string',
            'amount'      => 'required|numeric|positive',
            'description' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        try {
            $txn = $this->transactions->recordExpense(
                $request->input('category'),
                (float) $request->input('amount'),
                $request->input('description', ''),
                $request->getAttribute('user_id'),
            );

            return Response::created($txn, 'Expense recorded');
        } catch (\RuntimeException $e) {
            $code = (int) $e->getCode(); return Response::error($e->getMessage(), $code >= 400 ? $code : 400);
        }
    }

    /**
     * POST /api/v1/transactions/external-transfer
     *
     * Record an external/inter-institution transfer.
     */
    public function externalTransfer(Request $request): Response
    {
        $validator = new Validator($request->all(), [
            'account_id'        => 'required|string',
            'amount'            => 'required|numeric|positive',
            'fee'               => 'required|numeric',
            'fee_handling'      => 'required|in:deduct,add',
            'recipient_name'    => 'required|string|max:255',
            'recipient_account' => 'required|string|max:100',
            'institution_name'  => 'required|string|max:255',
            'location'          => 'nullable|string|max:255',
            'priority'          => 'nullable|string|in:normal,urgent',
            'description'       => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        $userId = $request->getAttribute('user_id');
        $role   = $request->getAttribute('role');

        if (!in_array($role, ['admin', 'super_admin', 'manager', 'teller'], true)) {
            if (!$this->accounts->verifyOwnership($request->input('account_id'), $userId)) {
                return Response::error('You can only transfer from your own accounts', 403);
            }
        }

        try {
            $result = $this->transactions->recordExternalTransfer(
                $request->input('account_id'),
                (float) $request->input('amount'),
                (float) $request->input('fee'),
                $request->input('fee_handling'),
                $request->input('recipient_name'),
                $request->input('recipient_account'),
                $request->input('institution_name'),
                $request->input('location', ''),
                $request->input('description', ''),
                $userId,
                $request->input('priority', 'normal'),
            );

            return Response::created($result, 'External transfer initiated');
        } catch (\RuntimeException $e) {
            $code = (int) $e->getCode(); return Response::error($e->getMessage(), $code >= 400 ? $code : 400);
        }
    }

    /**
     * POST /api/v1/transactions/{id}/receipt
     *
     * Send a transaction receipt via email, SMS, or WhatsApp.
     */
    public function sendReceipt(Request $request): Response
    {
        $validator = new Validator($request->all(), [
            'method'      => 'required|in:email,sms,whatsapp',
            'destination' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        $txnId    = $request->param('id');
        $db       = \App\Core\Database::getInstance();
        $tenantId = $db->getTenantId();

        $txn = $db->fetchOne(
            "SELECT t.*, a.account_number, CONCAT(u.first_name, ' ', u.last_name) AS member_name,
                    ten.name AS tenant_name
             FROM transactions t
             LEFT JOIN accounts a ON a.id = t.account_id
             LEFT JOIN users u ON u.id = a.user_id
             LEFT JOIN tenants ten ON ten.id = t.tenant_id
             WHERE t.id = ? AND t.tenant_id = ?",
            [$txnId, $tenantId],
        );

        if ($txn === null) {
            return Response::error('Transaction not found', 404);
        }

        $method      = $request->input('method');
        $destination = $request->input('destination');
        $receiptText = $this->formatReceiptText($txn);
        $subject     = 'Transaction Receipt - ' . $txn['reference_number'];

        switch ($method) {
            case 'email':
                $headers  = "From: noreply@synccu.net\r\nContent-Type: text/plain; charset=UTF-8";
                $sent     = @mail($destination, $subject, $receiptText, $headers);
                $message  = $sent ? "Receipt sent to {$destination}" : 'Email delivery failed — check mail server configuration';
                break;

            case 'sms':
            case 'whatsapp':
                // Placeholder — requires Twilio or similar. Log and acknowledge.
                error_log("Receipt [{$method}] requested for txn {$txnId} → {$destination}");
                $sent    = true;
                $message = ucfirst($method) . " receipt queued for {$destination}. Messaging integration pending configuration.";
                break;

            default:
                return Response::error('Unknown delivery method', 422);
        }

        return Response::ok(['sent' => $sent, 'message' => $message]);
    }

    private function formatReceiptText(array $txn): string
    {
        $date   = $txn['created_at'] ? date('M j, Y g:i A', strtotime($txn['created_at'])) : '-';
        $amount = '$' . number_format((float) $txn['amount'], 2);
        $type   = ucwords(str_replace('_', ' ', $txn['type']));
        $meta   = json_decode($txn['metadata'] ?? '{}', true);
        $cu     = $txn['tenant_name'] ?? 'SyncCU';

        $lines = [
            $cu . ' — TRANSACTION RECEIPT',
            str_repeat('=', 40),
            'Reference : ' . ($txn['reference_number'] ?? '-'),
            'Date      : ' . $date,
            'Type      : ' . $type,
            'Amount    : ' . $amount,
            'Account   : ' . ($txn['account_number'] ?? 'N/A'),
            'Member    : ' . ($txn['member_name'] ?? 'N/A'),
            'Status    : ' . ucfirst($txn['status'] ?? '-'),
        ];

        if (!empty($meta['recipient_name'])) {
            $lines[] = str_repeat('-', 40);
            $lines[] = 'Recipient : ' . $meta['recipient_name'];
            $lines[] = 'Account # : ' . ($meta['recipient_account'] ?? '-');
            $lines[] = 'Bank/CU   : ' . ($meta['institution_name'] ?? '-');
            if (!empty($meta['location'])) {
                $lines[] = 'Location  : ' . $meta['location'];
            }
            $lines[] = 'Transfer  : $' . number_format((float) ($meta['transfer_amount'] ?? 0), 2);
            $lines[] = 'Fee       : $' . number_format((float) ($meta['transfer_fee'] ?? 0), 2);
            $lines[] = 'Recipient receives: $' . number_format((float) ($meta['recipient_receives'] ?? 0), 2);
        }

        if (!empty($txn['description'])) {
            $lines[] = str_repeat('-', 40);
            $lines[] = 'Note: ' . $txn['description'];
        }

        $lines[] = str_repeat('=', 40);
        $lines[] = 'Thank you for your transaction.';

        return implode("\n", $lines);
    }

    /**
     * GET /api/transactions/{id}
     */
    public function show(Request $request): Response
    {
        $transactionId = $request->param('id');
        $userId        = $request->getAttribute('user_id');
        $role          = $request->getAttribute('role');

        $db = \App\Core\Database::getInstance();
        $tenantId = $db->getTenantId();

        $transaction = $db->fetchOne(
            "SELECT t.*, a.account_number, a.name AS account_name, a.account_type,
                    CONCAT(u.first_name, ' ', u.last_name) AS member_name,
                    u.email AS member_email, u.phone AS member_phone,
                    ra.account_number AS related_account_number,
                    ra.name AS related_account_name,
                    CONCAT(p.first_name, ' ', p.last_name) AS teller_name
             FROM transactions t
             LEFT JOIN accounts a ON a.id = t.account_id
             LEFT JOIN users u ON u.id = a.user_id
             LEFT JOIN accounts ra ON ra.id = t.related_account_id
             LEFT JOIN users p ON p.id = t.processed_by
             WHERE t.id = ? AND t.tenant_id = ?",
            [$transactionId, $tenantId],
        );

        if ($transaction === null) {
            return Response::error('Transaction not found', 404);
        }

        // Non-admin users can only view transactions on their own accounts
        if (!in_array($role, ['admin', 'super_admin', 'teller'], true)) {
            if (!$this->accounts->verifyOwnership($transaction['account_id'], $userId)) {
                return Response::error('Forbidden', 403);
            }
        }

        // Fetch tenant info for receipt header (CU name and address)
        $tenant = $db->fetchOne(
            "SELECT name FROM tenants WHERE id = ?",
            [$tenantId],
        );
        $transaction['cu_name'] = $tenant['name'] ?? '';

        $settings = $db->fetchOne(
            "SELECT setting_value FROM tenant_settings WHERE tenant_id = ? AND setting_key = 'address'",
            [$tenantId],
        );
        $transaction['cu_address'] = $settings['setting_value'] ?? '';

        return Response::ok($transaction);
    }

    /**
     * GET /api/v1/transactions/export
     */
    public function export(Request $request): Response
    {
        $db       = \App\Core\Database::getInstance();
        $tenantId = $db->getTenantId();
        $params   = [$tenantId];
        $where    = 'WHERE t.tenant_id = ?';

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

        $rows = $db->fetchAll(
            "SELECT t.reference_number, t.created_at, t.type, t.amount, t.balance_after,
                    t.description, t.status,
                    a.account_number, CONCAT(u.first_name, ' ', u.last_name) AS member_name,
                    ra.account_number AS related_account_number
             FROM transactions t
             LEFT JOIN accounts a ON a.id = t.account_id
             LEFT JOIN users u ON u.id = a.user_id
             LEFT JOIN accounts ra ON ra.id = t.related_account_id
             {$where}
             ORDER BY t.created_at DESC
             LIMIT 10000",
            $params,
        );

        $csv = "Reference,Date,Type,Account,Member,Amount,Balance After,Related Account,Description,Status\n";
        foreach ($rows as $r) {
            $csv .= implode(',', [
                $r['reference_number'],
                $r['created_at'],
                $r['type'],
                $r['account_number'] ?? '',
                '"' . str_replace('"', '""', $r['member_name'] ?? '') . '"',
                $r['amount'],
                $r['balance_after'],
                $r['related_account_number'] ?? '',
                '"' . str_replace('"', '""', $r['description'] ?? '') . '"',
                $r['status'],
            ]) . "\n";
        }

        return Response::raw($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="transactions-' . date('Y-m-d') . '.csv"',
        ]);
    }

    /**
     * PUT /api/v1/transactions/{id}/status
     */
    public function updateStatus(Request $request): Response
    {
        $transactionId = $request->param('id');
        $role = $request->getAttribute('role');

        if (!in_array($role, ['admin', 'super_admin', 'manager', 'teller'], true)) {
            return Response::error('Forbidden', 403);
        }

        $validator = new Validator($request->all(), [
            'status' => 'required|string|in:completed,failed,cancelled',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        $db = \App\Core\Database::getInstance();
        $tenantId = $db->getTenantId();

        $txn = $db->fetchOne(
            'SELECT id, status, type FROM transactions WHERE id = ? AND tenant_id = ?',
            [$transactionId, $tenantId],
        );

        if (!$txn) {
            return Response::error('Transaction not found', 404);
        }

        $newStatus = $request->input('status');
        $db->execute(
            'UPDATE transactions SET status = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?',
            [$newStatus, $transactionId, $tenantId],
        );

        return Response::ok(['id' => $transactionId, 'status' => $newStatus], 'Transaction status updated');
    }
}
