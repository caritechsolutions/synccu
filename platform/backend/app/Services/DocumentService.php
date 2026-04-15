<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use RuntimeException;

/**
 * Document storage and retrieval service.
 *
 * Stores files as BLOBs in the database for portability —
 * no filesystem dependencies, backups travel with the DB.
 */
final class DocumentService
{
    private Database $db;

    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    private const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10 MB

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->ensureTable();
    }

    /**
     * Create the documents table if it doesn't exist.
     */
    private function ensureTable(): void
    {
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `documents` (
                `id` CHAR(36) NOT NULL PRIMARY KEY,
                `tenant_id` CHAR(36) NOT NULL,
                `uploaded_by` CHAR(36) DEFAULT NULL,
                `entity_type` VARCHAR(50) NOT NULL,
                `entity_id` CHAR(36) NOT NULL,
                `category` VARCHAR(50) DEFAULT NULL,
                `original_name` VARCHAR(255) NOT NULL,
                `mime_type` VARCHAR(100) NOT NULL,
                `file_size` INT UNSIGNED NOT NULL,
                `file_data` LONGBLOB NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_documents_entity` (`entity_type`, `entity_id`),
                INDEX `idx_documents_tenant` (`tenant_id`)
            ) ENGINE=InnoDB",
            [],
        );
    }

    /**
     * Store an uploaded file in the database.
     *
     * @param array{tmp_name: string, original_name: string, mime_type: string, size: int} $file
     */
    public function store(
        array   $file,
        string  $entityType,
        string  $entityId,
        string  $category = '',
        ?string $uploadedBy = null,
    ): array {
        $this->validateFile($file);

        $tenantId = $this->db->getTenantId();
        $docId    = $this->generateUuid();
        $content  = file_get_contents($file['tmp_name']);

        if ($content === false) {
            throw new RuntimeException('Could not read uploaded file.', 500);
        }

        $this->db->query(
            'INSERT INTO documents
                (id, tenant_id, uploaded_by, entity_type, entity_id, category,
                 original_name, mime_type, file_size, file_data, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                $docId,
                $tenantId,
                $uploadedBy,
                $entityType,
                $entityId,
                $category,
                $file['original_name'],
                $file['mime_type'],
                $file['size'],
                $content,
            ],
        );

        return $this->findById($docId);
    }

    /**
     * Get document metadata by ID (without file_data).
     */
    public function findById(string $id): ?array
    {
        $tenantId = $this->db->getTenantId();
        return $this->db->fetchOne(
            'SELECT id, tenant_id, uploaded_by, entity_type, entity_id, category,
                    original_name, mime_type, file_size, created_at
             FROM documents WHERE id = ? AND tenant_id = ?',
            [$id, $tenantId],
        );
    }

    /**
     * Get all document metadata for a given entity (without file_data).
     */
    public function getByEntity(string $entityType, string $entityId): array
    {
        $tenantId = $this->db->getTenantId();
        return $this->db->fetchAll(
            'SELECT id, tenant_id, uploaded_by, entity_type, entity_id, category,
                    original_name, mime_type, file_size, created_at
             FROM documents
             WHERE entity_type = ? AND entity_id = ? AND tenant_id = ?
             ORDER BY created_at ASC',
            [$entityType, $entityId, $tenantId],
        );
    }

    /**
     * Get the raw file content for download.
     */
    public function getFileContent(string $id): ?string
    {
        $tenantId = $this->db->getTenantId();
        return $this->db->fetchColumn(
            'SELECT file_data FROM documents WHERE id = ? AND tenant_id = ?',
            [$id, $tenantId],
        );
    }

    /**
     * Delete a document record.
     */
    public function delete(string $id): void
    {
        $doc = $this->findById($id);
        if ($doc === null) {
            throw new RuntimeException('Document not found.', 404);
        }

        $this->db->execute(
            'DELETE FROM documents WHERE id = ? AND tenant_id = ?',
            [$id, $this->db->getTenantId()],
        );
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    private function validateFile(array $file): void
    {
        if (empty($file['tmp_name']) || !file_exists($file['tmp_name'])) {
            throw new RuntimeException('Invalid upload.', 422);
        }

        if ($file['size'] > self::MAX_FILE_SIZE) {
            throw new RuntimeException('File too large. Maximum size is 10 MB.', 422);
        }

        // Verify mime type from actual file content, not just the header
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $actualMime = $finfo->file($file['tmp_name']);

        if (!in_array($actualMime, self::ALLOWED_MIME_TYPES, true)) {
            throw new RuntimeException(
                'File type not allowed. Accepted: PDF, JPEG, PNG, GIF, WebP, DOC, DOCX.',
                422,
            );
        }
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
