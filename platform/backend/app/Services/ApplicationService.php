<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Member portal application service.
 *
 * Handles member-submitted loan/service applications and staff review.
 * All queries are tenant-scoped via the current tenant context.
 */
final class ApplicationService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ------------------------------------------------------------------
    // Member-facing
    // ------------------------------------------------------------------

    /**
     * List a member's applications, newest first.
     */
    public function listForMember(string $memberId): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM applications
             WHERE tenant_id = ? AND member_id = ?
             ORDER BY created_at DESC",
            [$this->db->getTenantId(), $memberId],
        );
    }

    /**
     * Submit a new application. Returns the new application id.
     */
    public function submit(
        string $memberId,
        string $type,
        string $product,
        ?float $amount,
        ?int $term,
        ?string $details,
    ): string {
        $id = $this->generateUuid();
        $this->db->insertScoped('applications', [
            'id'          => $id,
            'member_id'   => $memberId,
            'app_type'    => $type,
            'product'     => $product,
            'amount'      => $amount,
            'term_months' => $term,
            'details'     => $details,
            'status'      => 'pending',
        ]);
        return $id;
    }

    /**
     * Cancel a member's own application (only when pending or in_review).
     */
    public function cancelOwn(string $memberId, string $appId): bool
    {
        $affected = $this->db->execute(
            "UPDATE applications
             SET status = 'cancelled', updated_at = NOW()
             WHERE tenant_id = ? AND member_id = ? AND id = ?
               AND status IN ('pending', 'in_review')",
            [$this->db->getTenantId(), $memberId, $appId],
        );
        return $affected > 0;
    }

    // ------------------------------------------------------------------
    // Staff-facing
    // ------------------------------------------------------------------

    /**
     * List all applications for staff, optionally filtered by status.
     */
    public function listAll(?string $statusFilter = null): array
    {
        $tenantId = $this->db->getTenantId();
        $params = [$tenantId];
        $where = 'WHERE a.tenant_id = ?';

        if ($statusFilter !== null && $statusFilter !== '') {
            $where .= ' AND a.status = ?';
            $params[] = $statusFilter;
        }

        return $this->db->fetchAll(
            "SELECT a.*, CONCAT(u.first_name, ' ', u.last_name) AS member_name, u.email AS member_email
             FROM applications a
             JOIN users u ON u.id = a.member_id
             {$where}
             ORDER BY a.created_at DESC",
            $params,
        );
    }

    /**
     * Review an application: update status, notes, reviewer and timestamp.
     */
    public function review(string $appId, string $status, ?string $staffNotes, string $reviewerId): bool
    {
        $affected = $this->db->execute(
            "UPDATE applications
             SET status = ?, staff_notes = ?, reviewed_by = ?, reviewed_at = NOW(), updated_at = NOW()
             WHERE tenant_id = ? AND id = ?",
            [$status, $staffNotes, $reviewerId, $this->db->getTenantId(), $appId],
        );
        return $affected > 0;
    }

    // ------------------------------------------------------------------
    // Internal Helpers
    // ------------------------------------------------------------------

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
