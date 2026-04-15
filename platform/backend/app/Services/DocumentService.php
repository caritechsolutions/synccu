<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use RuntimeException;

/**
 * Document storage and retrieval service.
 *
 * Handles file uploads to disk, document metadata in DB,
 * and retrieval/download of stored files.
 */
final class DocumentService
{
    private Database $db;
    private string $storagePath;

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
        $this->storagePath = dirname(__DIR__, 2) . '/storage/documents';
    }

    /**
     * Store an uploaded file and create a document record.
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

        $tenantId  = $this->db->getTenantId();
        $docId     = $this->generateUuid();
        $ext       = pathinfo($file['original_name'], PATHINFO_EXTENSION) ?: 'bin';
        $storedName = $docId . '.' . strtolower($ext);

        // Ensure storage directory exists (tenant-scoped)
        $dir = $this->storagePath . '/' . $tenantId;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $destPath = $dir . '/' . $storedName;
        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            throw new RuntimeException('Failed to store uploaded file.', 500);
        }

        $this->db->query(
            'INSERT INTO documents
                (id, tenant_id, uploaded_by, entity_type, entity_id, category,
                 original_name, stored_name, mime_type, file_size, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                $docId,
                $tenantId,
                $uploadedBy,
                $entityType,
                $entityId,
                $category,
                $file['original_name'],
                $storedName,
                $file['mime_type'],
                $file['size'],
            ],
        );

        return $this->findById($docId);
    }

    /**
     * Get a document by ID.
     */
    public function findById(string $id): ?array
    {
        return $this->db->findScoped('documents', ['id' => $id]);
    }

    /**
     * Get all documents for a given entity.
     */
    public function getByEntity(string $entityType, string $entityId): array
    {
        return $this->db->selectScoped(
            'documents',
            ['entity_type' => $entityType, 'entity_id' => $entityId],
            'created_at ASC',
        );
    }

    /**
     * Get the full filesystem path for a document.
     */
    public function getFilePath(array $document): string
    {
        return $this->storagePath . '/' . $document['tenant_id'] . '/' . $document['stored_name'];
    }

    /**
     * Delete a document (file + record).
     */
    public function delete(string $id): void
    {
        $doc = $this->findById($id);
        if ($doc === null) {
            throw new RuntimeException('Document not found.', 404);
        }

        $path = $this->getFilePath($doc);
        if (file_exists($path)) {
            unlink($path);
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
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
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
