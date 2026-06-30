<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use RuntimeException;

/**
 * Member portal bill pay: saved payees and payments.
 *
 * Money movement is delegated to TransactionService::withdraw() (which
 * validates the account, checks the balance, writes the ledger transaction
 * and updates the balance). This service only adds ownership checks and the
 * bill_payments record.
 */
final class BillPayService
{
    private Database $db;
    private TransactionService $transactions;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->transactions = new TransactionService();
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    // ------------------------------------------------------------------
    // Payees
    // ------------------------------------------------------------------

    public function listPayees(string $memberId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM payees WHERE tenant_id = ? AND member_id = ? AND is_active = 1 ORDER BY name ASC',
            [$this->db->getTenantId(), $memberId],
        );
    }

    public function addPayee(string $memberId, string $name, ?string $category, ?string $reference): string
    {
        $id = $this->generateUuid();
        $this->db->insertScoped('payees', [
            'id'                => $id,
            'member_id'         => $memberId,
            'name'              => $name,
            'category'          => $category ?: null,
            'account_reference' => $reference ?: null,
            'is_active'         => 1,
        ]);
        return $id;
    }

    /** Soft-delete a payee the member owns. Returns true if a row changed. */
    public function deletePayee(string $memberId, string $payeeId): bool
    {
        return $this->db->execute(
            'UPDATE payees SET is_active = 0 WHERE id = ? AND tenant_id = ? AND member_id = ?',
            [$payeeId, $this->db->getTenantId(), $memberId],
        ) > 0;
    }

    // ------------------------------------------------------------------
    // Payments
    // ------------------------------------------------------------------

    public function history(string $memberId): array
    {
        return $this->db->fetchAll(
            'SELECT bp.*, p.name AS payee_name, a.account_number
             FROM bill_payments bp
             LEFT JOIN payees p ON p.id = bp.payee_id
             LEFT JOIN accounts a ON a.id = bp.from_account_id
             WHERE bp.tenant_id = ? AND bp.member_id = ?
             ORDER BY bp.created_at DESC
             LIMIT 50',
            [$this->db->getTenantId(), $memberId],
        );
    }

    /**
     * Pay a biller by debiting one of the member's own accounts.
     *
     * @throws RuntimeException on ownership/validation failure (HTTP code in getCode()).
     */
    public function pay(string $memberId, string $fromAccountId, string $payeeId, float $amount, ?string $memo): array
    {
        $tenantId = $this->db->getTenantId();

        // The source account MUST belong to this member (IDOR protection).
        $account = $this->db->fetchOne(
            'SELECT * FROM accounts WHERE id = ? AND tenant_id = ? AND user_id = ?',
            [$fromAccountId, $tenantId, $memberId],
        );
        if ($account === null) {
            throw new RuntimeException('Account not found.', 404);
        }
        if ($account['account_type'] === 'loan') {
            throw new RuntimeException('Cannot pay from a loan account.', 422);
        }

        // The payee MUST belong to this member.
        $payee = $this->db->fetchOne(
            'SELECT * FROM payees WHERE id = ? AND tenant_id = ? AND member_id = ? AND is_active = 1',
            [$payeeId, $tenantId, $memberId],
        );
        if ($payee === null) {
            throw new RuntimeException('Payee not found.', 404);
        }

        return $this->db->transaction(function () use ($memberId, $fromAccountId, $payee, $amount, $memo) {
            // withdraw() validates the amount, active status, and sufficient balance,
            // and writes the ledger transaction.
            $txn = $this->transactions->withdraw(
                $fromAccountId,
                $amount,
                'Bill payment: ' . $payee['name'],
                $memberId,
                ['bill_payment' => true, 'payee' => $payee['name']],
            );

            $id = $this->generateUuid();
            $this->db->insertScoped('bill_payments', [
                'id'               => $id,
                'member_id'        => $memberId,
                'payee_id'         => $payee['id'],
                'from_account_id'  => $fromAccountId,
                'amount'           => $amount,
                'reference_number' => $txn['reference_number'] ?? null,
                'transaction_id'   => $txn['id'] ?? null,
                'status'           => 'completed',
                'memo'             => $memo ?: null,
            ]);

            return [
                'id'        => $id,
                'reference' => $txn['reference_number'] ?? null,
                'amount'    => $amount,
                'payee'     => $payee['name'],
            ];
        });
    }
}
