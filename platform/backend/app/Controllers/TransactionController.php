<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\AccountService;
use App\Services\CashManagementService;
use App\Services\LoanService;
use App\Services\SupervisorAuthService;
use App\Services\TransactionService;

/**
 * Transaction REST controller.
 */
final class TransactionController
{
    private TransactionService $transactions;
    private AccountService $accounts;
    private SupervisorAuthService $supervisor;

    private const STAFF_ROLES = ['admin', 'super_admin', 'manager', 'teller'];

    public function __construct()
    {
        $this->transactions = new TransactionService();
        $this->accounts     = new AccountService();
        $this->supervisor   = new SupervisorAuthService();
    }

    /**
     * Resolve a supervisor-authorized backdate for staff requests and fold it
     * into the transaction metadata. Members can never backdate.
     *
     * @throws \RuntimeException
     */
    private function applyBackdate(Request $request, array $metadata, string $field = 'transaction_date'): array
    {
        if (!in_array($request->getAttribute('role'), self::STAFF_ROLES, true)) {
            return $metadata;
        }
        $backdate = $this->supervisor->resolveBackdate($request->all(), $field);
        if ($backdate !== null) {
            $metadata['transaction_date'] = $backdate['datetime'];
            $metadata['backdate'] = $backdate;
        }
        return $metadata;
    }

    /**
     * GET /api/v1/transactions
     *
     * List transactions for the tenant with optional filters.
     */
    public function index(Request $request): Response
    {
        $this->transactions->ensureSchema();

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
                    t.description, t.reference_number, t.end_to_end_id, t.purpose_code,
                    t.debtor_name, t.debtor_account, t.creditor_name, t.creditor_account,
                    t.remittance_info, t.charge_bearer, t.settlement_date,
                    t.status, t.iso_status_code, t.processed_by, t.created_at,
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
            'deposit'      => $this->deposit($request),
            'withdrawal'   => $this->withdraw($request),
            'transfer'     => $this->transfer($request),
            'loan_payment' => $this->loanPayment($request),
            default        => Response::error('Invalid transaction type. Use: deposit, withdrawal, transfer, or loan_payment', 422),
        };
    }

    /**
     * POST /api/v1/transactions/loan-payment  (also via store type=loan_payment)
     *
     * Post a loan payment from the transactions screen. Staff only.
     */
    public function loanPayment(Request $request): Response
    {
        if (!in_array($request->getAttribute('role'), self::STAFF_ROLES, true)) {
            return Response::error('Forbidden', 403);
        }

        $validator = new Validator($request->all(), [
            'loan_id'         => 'required|string',
            'amount'          => 'required|numeric|positive',
            'method'          => 'nullable|in:cash,account,check,wire,ach',
            'from_account_id' => 'nullable|string',
            'drawer_id'       => 'nullable|string',
            'denominations'   => 'nullable',
            'description'     => 'nullable|string|max:255',
            'transaction_date' => 'nullable|string|max:19',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        $userId = $request->getAttribute('user_id');
        $fromAccountId = $request->input('from_account_id') ?: null;
        $method = $request->input('method') ?: ($fromAccountId ? 'account' : 'cash');
        if ($method === 'account' && !$fromAccountId) {
            return Response::error('Select the member account to pay from.', 422);
        }
        if ($method !== 'account') {
            $fromAccountId = null;
        }

        try {
            $options = [
                'method'        => $method,
                'description'   => $request->input('description', ''),
                'drawer_id'     => $method === 'cash' ? ($request->input('drawer_id') ?: null) : null,
                'denominations' => $request->input('denominations'),
            ];
            $backdate = $this->supervisor->resolveBackdate($request->all(), 'transaction_date');
            if ($backdate !== null) {
                $options['payment_date'] = $backdate['date'];
                $options['backdate']     = $backdate;
            }

            $result = (new LoanService())->makePayment(
                $request->input('loan_id'),
                (float) $request->input('amount'),
                $fromAccountId,
                $userId,
                $options,
            );

            $txn = $this->transactions->findById($result['payment_id']) ?? [];
            return Response::created(array_merge($txn, ['payment' => $result]), 'Loan payment posted');
        } catch (\RuntimeException $e) {
            $code = (int) $e->getCode(); return Response::error($e->getMessage(), $code >= 400 ? $code : 400);
        }
    }

    /**
     * POST /api/v1/transactions/batch
     *
     * Post several transactions for one member in a single, atomic batch:
     * e.g. take $100 cash, put $20 in savings and $80 against a loan.
     * One net cash movement is recorded against the drawer.
     */
    public function batch(Request $request): Response
    {
        $input  = $request->all();
        $userId = $request->getAttribute('user_id');
        $db     = \App\Core\Database::getInstance();
        $tenantId = $db->getTenantId();

        $memberId = trim((string) ($input['member_id'] ?? ''));
        $lines    = $input['lines'] ?? [];
        if ($memberId === '') {
            return Response::error('member_id is required.', 422);
        }
        if (!is_array($lines) || count($lines) === 0) {
            return Response::error('Add at least one transaction line.', 422);
        }
        if (count($lines) > 25) {
            return Response::error('A batch may contain at most 25 lines.', 422);
        }

        $member = $db->fetchOne(
            "SELECT id, CONCAT(first_name, ' ', last_name) AS name FROM users WHERE id = ? AND tenant_id = ?",
            [$memberId, $tenantId],
        );
        if ($member === null) {
            return Response::error('Member not found.', 404);
        }

        // Validate every line before touching anything
        $prepared = [];
        foreach (array_values($lines) as $i => $line) {
            $n = $i + 1;
            $type = $line['type'] ?? '';
            $amount = (float) ($line['amount'] ?? 0);
            if (!in_array($type, ['deposit', 'withdrawal', 'loan_payment'], true)) {
                return Response::error("Line {$n}: type must be deposit, withdrawal, or loan_payment.", 422);
            }
            if ($amount <= 0) {
                return Response::error("Line {$n}: amount must be greater than zero.", 422);
            }
            $desc = trim((string) ($line['description'] ?? ''));

            if ($type === 'loan_payment') {
                $loan = $db->fetchOne(
                    'SELECT id, loan_number, user_id, status FROM loans WHERE id = ? AND tenant_id = ?',
                    [(string) ($line['loan_id'] ?? ''), $tenantId],
                );
                if ($loan === null || $loan['user_id'] !== $memberId) {
                    return Response::error("Line {$n}: loan not found for this member.", 422);
                }
                if (!in_array($loan['status'], ['approved', 'active', 'delinquent'], true)) {
                    return Response::error("Line {$n}: loan {$loan['loan_number']} is not payable.", 422);
                }
                $fromAccountId = trim((string) ($line['from_account_id'] ?? '')) ?: null;
                if ($fromAccountId !== null) {
                    $src = $this->accounts->findById($fromAccountId);
                    if ($src === null || $src['user_id'] !== $memberId || $src['account_type'] === 'loan') {
                        return Response::error("Line {$n}: source account not found for this member.", 422);
                    }
                }
                $prepared[] = ['type' => $type, 'loan' => $loan, 'from_account_id' => $fromAccountId, 'amount' => $amount, 'description' => $desc];
            } else {
                $account = $this->accounts->findById((string) ($line['account_id'] ?? ''));
                if ($account === null || $account['user_id'] !== $memberId) {
                    return Response::error("Line {$n}: account not found for this member.", 422);
                }
                if ($account['account_type'] === 'loan') {
                    return Response::error("Line {$n}: use a loan payment line for loan accounts.", 422);
                }
                $prepared[] = ['type' => $type, 'account' => $account, 'amount' => $amount, 'description' => $desc];
            }
        }

        $drawerId = trim((string) ($input['drawer_id'] ?? '')) ?: null;
        $denominations = is_array($input['denominations'] ?? null) ? $input['denominations'] : [];
        $batchNote = trim((string) ($input['description'] ?? ''));

        try {
            $backdate = $this->supervisor->resolveBackdate($input, 'transaction_date');
        } catch (\RuntimeException $e) {
            $code = (int) $e->getCode(); return Response::error($e->getMessage(), $code >= 400 ? $code : 400);
        }

        $batchId  = $this->generateUuid();
        $batchRef = 'BT-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $loans    = new LoanService();

        // Schema checks (DDL) must run before the transaction opens
        $cash = null;
        if ($drawerId !== null) {
            $cash = new CashManagementService();
            $cash->ensureSchema();
        }

        try {
            $result = $db->transaction(function () use (
                $prepared, $member, $memberId, $userId, $tenantId, $drawerId, $denominations,
                $batchNote, $backdate, $batchId, $batchRef, $loans, $cash
            ) {
                $cashIn = 0.0;
                $cashOut = 0.0;
                $posted = [];
                $firstTxnId = null;

                foreach ($prepared as $i => $p) {
                    $n = $i + 1;
                    $desc = $p['description'] !== '' ? $p['description'] : ($batchNote !== '' ? $batchNote : '');
                    $meta = [
                        'batch_id'   => $batchId,
                        'batch_ref'  => $batchRef,
                        'batch_line' => $n,
                    ];
                    if ($backdate !== null) {
                        $meta['transaction_date'] = $backdate['datetime'];
                        $meta['backdate'] = $backdate;
                    }

                    if ($p['type'] === 'deposit') {
                        $meta['funding_source'] = 'cash';
                        $txn = $this->transactions->deposit($p['account']['id'], $p['amount'], $desc ?: "Batch {$batchRef} deposit", $userId, $meta);
                        $cashIn += $p['amount'];
                        $posted[] = ['line' => $n, 'type' => 'deposit', 'id' => $txn['id'] ?? null, 'reference' => $txn['reference_number'] ?? null, 'account' => $p['account']['account_number'], 'amount' => $p['amount']];
                        $firstTxnId = $firstTxnId ?? ($txn['id'] ?? null);
                    } elseif ($p['type'] === 'withdrawal') {
                        $meta['disbursement_method'] = 'cash';
                        $txn = $this->transactions->withdraw($p['account']['id'], $p['amount'], $desc ?: "Batch {$batchRef} withdrawal", $userId, $meta);
                        $cashOut += $p['amount'];
                        $posted[] = ['line' => $n, 'type' => 'withdrawal', 'id' => $txn['id'] ?? null, 'reference' => $txn['reference_number'] ?? null, 'account' => $p['account']['account_number'], 'amount' => $p['amount']];
                        $firstTxnId = $firstTxnId ?? ($txn['id'] ?? null);
                    } else {
                        $opts = [
                            'method'      => $p['from_account_id'] ? 'account' : 'cash',
                            'skip_cash'   => true,
                            'batch_id'    => $batchId,
                            'description' => $desc,
                        ];
                        if ($backdate !== null) {
                            $opts['payment_date'] = $backdate['date'];
                            $opts['backdate']     = $backdate;
                        }
                        $pay = $loans->makePayment($p['loan']['id'], $p['amount'], $p['from_account_id'], $userId, $opts);
                        if (!$p['from_account_id']) {
                            $cashIn += (float) $pay['amount'];
                        }
                        $posted[] = ['line' => $n, 'type' => 'loan_payment', 'id' => $pay['payment_id'], 'reference' => $pay['reference'], 'loan' => $p['loan']['loan_number'], 'amount' => (float) $pay['amount'], 'principal' => $pay['principal'], 'interest' => $pay['interest'], 'new_balance' => $pay['new_balance']];
                        $firstTxnId = $firstTxnId ?? $pay['payment_id'];
                    }
                }

                $net = round($cashIn - $cashOut, 2);
                $movement = null;
                if ($cash !== null && abs($net) >= 0.01) {
                    $label = "Batch {$batchRef} — {$member['name']}";
                    if ($net > 0) {
                        $movement = $cash->recordDepositCash($tenantId, $drawerId, $net, $denominations, (string) $firstTxnId, $userId ?? '', $label);
                    } else {
                        $movement = $cash->recordWithdrawalCash($tenantId, $drawerId, -$net, $denominations, (string) $firstTxnId, $userId ?? '', $label);
                    }
                }

                return [
                    'batch_id'   => $batchId,
                    'batch_ref'  => $batchRef,
                    'member'     => $member['name'],
                    'cash_in'    => round($cashIn, 2),
                    'cash_out'   => round($cashOut, 2),
                    'net_cash'   => $net,
                    'drawer_movement' => $movement,
                    'lines'      => $posted,
                    'backdated'  => $backdate !== null ? $backdate['date'] : null,
                ];
            });

            return Response::created($result, 'Batch posted');
        } catch (\RuntimeException $e) {
            $code = (int) $e->getCode(); return Response::error($e->getMessage(), $code >= 400 ? $code : 400);
        }
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
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
            'purpose_code'     => 'nullable|string|max:10',
            'remittance_info'  => 'nullable|string|max:140',
            'end_to_end_id'    => 'nullable|string|max:35',
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

        $account = $this->accounts->findById($request->input('account_id'));
        if ($account && $account['account_type'] === 'loan') {
            return Response::error('Cannot deposit to a loan account. Use the loan payment feature instead.', 422);
        }

        try {
            $metadata = array_filter([
                'funding_source'  => $request->input('funding_source', 'cash'),
                'drawer_id'       => $request->input('drawer_id'),
                'denominations'   => $request->input('denominations'),
                'purpose_code'    => $request->input('purpose_code'),
                'remittance_info' => $request->input('remittance_info'),
                'end_to_end_id'   => $request->input('end_to_end_id'),
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

            $metadata = $this->applyBackdate($request, $metadata);

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
            'purpose_code'        => 'nullable|string|max:10',
            'remittance_info'     => 'nullable|string|max:140',
            'end_to_end_id'       => 'nullable|string|max:35',
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

        $account = $this->accounts->findById($request->input('account_id'));
        if ($account && $account['account_type'] === 'loan') {
            return Response::error('Cannot withdraw from a loan account.', 422);
        }

        try {
            $metadata = array_filter([
                'disbursement_method' => $request->input('disbursement_method'),
                'check_number'        => $request->input('check_number'),
                'drawer_id'           => $request->input('drawer_id'),
                'denominations'       => $request->input('denominations'),
                'purpose_code'        => $request->input('purpose_code'),
                'remittance_info'     => $request->input('remittance_info'),
                'end_to_end_id'       => $request->input('end_to_end_id'),
            ]);

            $metadata = $this->applyBackdate($request, $metadata);

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
            $metadata = $this->applyBackdate($request, []);
            $result = $this->transactions->transfer(
                $request->input('from_account_id'),
                $request->input('to_account_id'),
                (float) $request->input('amount'),
                $request->input('description', ''),
                $userId,
                $metadata,
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
            'purpose_code'      => 'nullable|string|max:10',
            'remittance_info'   => 'nullable|string|max:140',
            'end_to_end_id'     => 'nullable|string|max:35',
            'creditor_agent_bic'=> 'nullable|string|max:11',
            'charge_bearer'     => 'nullable|string|in:DEBT,CRED,SHAR,SLEV',
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
        try {
            $this->transactions->ensureSchema();
        } catch (\Throwable) {
        }

        $transactionId = $request->param('id');
        $userId        = $request->getAttribute('user_id');
        $role          = $request->getAttribute('role');

        $db = \App\Core\Database::getInstance();
        $tenantId = $db->getTenantId();

        try {
            $transaction = $db->fetchOne(
                "SELECT * FROM transactions WHERE id = ? AND tenant_id = ?",
                [$transactionId, $tenantId],
            );
        } catch (\Throwable $e) {
            error_log('Transaction show query failed: ' . $e->getMessage());
            return Response::error('Failed to load transaction: ' . $e->getMessage(), 500);
        }

        if ($transaction === null) {
            return Response::error('Transaction not found', 404);
        }

        // Non-admin users can only view transactions on their own accounts
        if (!in_array($role, ['admin', 'super_admin', 'manager', 'teller'], true)) {
            $accountId = $transaction['account_id'] ?? null;
            if ($accountId && !$this->accounts->verifyOwnership($accountId, $userId)) {
                return Response::error('Forbidden', 403);
            }
        }

        // Enrich with account info
        try {
            if (!empty($transaction['account_id'])) {
                $account = $db->fetchOne(
                    "SELECT account_number, name, account_type FROM accounts WHERE id = ?",
                    [$transaction['account_id']],
                );
                if ($account) {
                    $transaction['account_number'] = $account['account_number'];
                    $transaction['account_name'] = $account['name'];
                    $transaction['account_type'] = $account['account_type'];

                    $member = $db->fetchOne(
                        "SELECT first_name, last_name, email, phone, address_line_1, city, state
                         FROM users WHERE id = (SELECT user_id FROM accounts WHERE id = ?)",
                        [$transaction['account_id']],
                    );
                    if ($member) {
                        $transaction['member_name'] = trim($member['first_name'] . ' ' . $member['last_name']);
                        $transaction['member_email'] = $member['email'] ?? '';
                        $transaction['member_phone'] = $member['phone'] ?? '';
                        $transaction['member_address'] = $member['address_line_1'] ?? '';
                        $transaction['member_city'] = $member['city'] ?? '';
                        $transaction['member_state'] = $member['state'] ?? '';
                    }
                }
            }
        } catch (\Throwable) {
        }

        // Enrich with related account info
        try {
            if (!empty($transaction['related_account_id'])) {
                $related = $db->fetchOne(
                    "SELECT account_number, name FROM accounts WHERE id = ?",
                    [$transaction['related_account_id']],
                );
                if ($related) {
                    $transaction['related_account_number'] = $related['account_number'];
                    $transaction['related_account_name'] = $related['name'];
                }
            }
        } catch (\Throwable) {
        }

        // Enrich with teller name
        try {
            if (!empty($transaction['processed_by'])) {
                $teller = $db->fetchOne(
                    "SELECT first_name, last_name FROM users WHERE id = ?",
                    [$transaction['processed_by']],
                );
                if ($teller) {
                    $transaction['teller_name'] = trim($teller['first_name'] . ' ' . $teller['last_name']);
                }
            }
        } catch (\Throwable) {
        }

        // Tenant info for receipt header
        try {
            $tenant = $db->fetchOne("SELECT name FROM tenants WHERE id = ?", [$tenantId]);
            $transaction['cu_name'] = $tenant['name'] ?? '';
        } catch (\Throwable) {
            $transaction['cu_name'] = '';
        }

        try {
            $settings = $db->fetchOne(
                "SELECT setting_value FROM tenant_settings WHERE tenant_id = ? AND setting_key = 'address'",
                [$tenantId],
            );
            $transaction['cu_address'] = $settings['setting_value'] ?? '';
        } catch (\Throwable) {
            $transaction['cu_address'] = '';
        }

        return Response::ok($transaction);
    }

    /**
     * GET /api/v1/transactions/export
     */
    public function export(Request $request): Response
    {
        $this->transactions->ensureSchema();

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
            "SELECT t.reference_number, t.end_to_end_id, t.created_at, t.type, t.amount,
                    t.balance_after, t.description, t.status, t.purpose_code,
                    t.debtor_name, t.debtor_account, t.creditor_name, t.creditor_account,
                    t.remittance_info, t.settlement_date,
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

        $csv = "Reference,EndToEndId,Date,Type,Account,Member,Amount,Balance After,Related Account,Purpose Code,Debtor Name,Debtor Account,Creditor Name,Creditor Account,Remittance Info,Settlement Date,Description,Status\n";
        foreach ($rows as $r) {
            $csv .= implode(',', [
                $r['reference_number'],
                $r['end_to_end_id'] ?? '',
                $r['created_at'],
                $r['type'],
                $r['account_number'] ?? '',
                '"' . str_replace('"', '""', $r['member_name'] ?? '') . '"',
                $r['amount'],
                $r['balance_after'] ?? '',
                $r['related_account_number'] ?? '',
                $r['purpose_code'] ?? '',
                '"' . str_replace('"', '""', $r['debtor_name'] ?? '') . '"',
                $r['debtor_account'] ?? '',
                '"' . str_replace('"', '""', $r['creditor_name'] ?? '') . '"',
                $r['creditor_account'] ?? '',
                '"' . str_replace('"', '""', $r['remittance_info'] ?? '') . '"',
                $r['settlement_date'] ?? '',
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
