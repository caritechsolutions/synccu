<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Member portal messaging service.
 *
 * Handles the secure message thread between a member and credit-union staff.
 * All queries are tenant-scoped via the current tenant context.
 */
final class MessageService
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
     * Get the full message thread for a member, oldest first.
     */
    public function threadForMember(string $memberId): array
    {
        return $this->db->fetchAll(
            "SELECT mm.*, CONCAT(u.first_name, ' ', u.last_name) AS staff_name
             FROM member_messages mm
             LEFT JOIN users u ON u.id = mm.staff_user_id
             WHERE mm.tenant_id = ? AND mm.member_id = ?
             ORDER BY mm.created_at ASC",
            [$this->db->getTenantId(), $memberId],
        );
    }

    /**
     * Mark all staff-authored messages as read by the member.
     */
    public function markReadByMember(string $memberId): void
    {
        $this->db->execute(
            "UPDATE member_messages
             SET is_read_by_member = 1
             WHERE tenant_id = ? AND member_id = ? AND sender = 'staff' AND is_read_by_member = 0",
            [$this->db->getTenantId(), $memberId],
        );
    }

    /**
     * Count staff messages unread by the member.
     */
    public function unreadForMember(string $memberId): int
    {
        return (int) $this->db->fetchColumn(
            "SELECT COUNT(*) FROM member_messages
             WHERE tenant_id = ? AND member_id = ? AND sender = 'staff' AND is_read_by_member = 0",
            [$this->db->getTenantId(), $memberId],
        );
    }

    /**
     * Send a message from the member to staff. Returns the new message id.
     */
    public function sendFromMember(string $memberId, string $subject, string $body): string
    {
        $id = $this->generateUuid();
        $this->db->insertScoped('member_messages', [
            'id'                => $id,
            'member_id'         => $memberId,
            'sender'            => 'member',
            'staff_user_id'     => null,
            'subject'           => $subject,
            'body'              => $body,
            'is_read_by_member' => 1,
            'is_read_by_staff'  => 0,
        ]);
        return $id;
    }

    // ------------------------------------------------------------------
    // Staff-facing
    // ------------------------------------------------------------------

    /**
     * List member conversations for staff, with unread counts and last activity.
     */
    public function conversations(): array
    {
        return $this->db->fetchAll(
            "SELECT mm.member_id,
                    CONCAT(u.first_name, ' ', u.last_name) AS member_name,
                    u.email,
                    COUNT(*) AS total,
                    SUM(mm.sender = 'member' AND mm.is_read_by_staff = 0) AS unread,
                    MAX(mm.created_at) AS last_at
             FROM member_messages mm
             JOIN users u ON u.id = mm.member_id
             WHERE mm.tenant_id = ?
             GROUP BY mm.member_id, member_name, u.email
             ORDER BY unread DESC, last_at DESC",
            [$this->db->getTenantId()],
        );
    }

    /**
     * Get the full message thread for a member (staff view), oldest first.
     */
    public function threadForStaff(string $memberId): array
    {
        return $this->db->fetchAll(
            "SELECT mm.*, CONCAT(u.first_name, ' ', u.last_name) AS staff_name
             FROM member_messages mm
             LEFT JOIN users u ON u.id = mm.staff_user_id
             WHERE mm.tenant_id = ? AND mm.member_id = ?
             ORDER BY mm.created_at ASC",
            [$this->db->getTenantId(), $memberId],
        );
    }

    /**
     * Mark all member-authored messages as read by staff.
     */
    public function markReadByStaff(string $memberId): void
    {
        $this->db->execute(
            "UPDATE member_messages
             SET is_read_by_staff = 1
             WHERE tenant_id = ? AND member_id = ? AND sender = 'member' AND is_read_by_staff = 0",
            [$this->db->getTenantId(), $memberId],
        );
    }

    /**
     * Send a reply from staff to a member. Returns the new message id.
     */
    public function sendFromStaff(string $memberId, string $staffUserId, string $subject, string $body): string
    {
        $id = $this->generateUuid();
        $this->db->insertScoped('member_messages', [
            'id'                => $id,
            'member_id'         => $memberId,
            'sender'            => 'staff',
            'staff_user_id'     => $staffUserId,
            'subject'           => $subject,
            'body'              => $body,
            'is_read_by_member' => 0,
            'is_read_by_staff'  => 1,
        ]);
        return $id;
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
