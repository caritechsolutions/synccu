<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\DocumentService;

/**
 * Document upload/download controller.
 */
final class DocumentController
{
    private DocumentService $documents;

    public function __construct()
    {
        $this->documents = new DocumentService();
    }

    /**
     * POST /api/v1/documents
     *
     * Upload one or more files attached to an entity.
     * Expects multipart/form-data with:
     *   - files[]   : one or more files
     *   - entity_type: transaction, loan, member, account
     *   - entity_id  : UUID of the entity
     *   - category   : sof, id_verification, proof_of_address, etc.
     */
    public function upload(Request $request): Response
    {
        $entityType = $_POST['entity_type'] ?? '';
        $entityId   = $_POST['entity_id'] ?? '';
        $category   = $_POST['category'] ?? '';

        if ($entityType === '' || $entityId === '') {
            return Response::error('entity_type and entity_id are required.', 422);
        }

        $allowedTypes = ['transaction', 'loan', 'member', 'account'];
        if (!in_array($entityType, $allowedTypes, true)) {
            return Response::error('Invalid entity_type.', 422);
        }

        $uploadedFiles = $request->files('files');
        if (empty($uploadedFiles)) {
            return Response::error('No files uploaded.', 422);
        }

        $userId = $request->getAttribute('user_id');
        $results = [];

        foreach ($uploadedFiles as $file) {
            try {
                $results[] = $this->documents->store($file, $entityType, $entityId, $category, $userId);
            } catch (\RuntimeException $e) {
                $results[] = ['error' => $e->getMessage(), 'file' => $file['original_name']];
            }
        }

        return Response::created($results, 'Files uploaded');
    }

    /**
     * GET /api/v1/documents?entity_type=X&entity_id=Y
     *
     * List documents for an entity.
     */
    public function index(Request $request): Response
    {
        $entityType = $request->query('entity_type', '');
        $entityId   = $request->query('entity_id', '');

        if ($entityType === '' || $entityId === '') {
            return Response::error('entity_type and entity_id are required.', 422);
        }

        $docs = $this->documents->getByEntity($entityType, $entityId);

        return Response::ok($docs);
    }

    /**
     * GET /api/v1/documents/{id}/download
     *
     * Download a document file from the database.
     */
    public function download(Request $request): Response
    {
        $docId = $request->param('id');
        $doc = $this->documents->findById($docId);

        if ($doc === null) {
            return Response::error('Document not found', 404);
        }

        $content = $this->documents->getFileContent($docId);
        if ($content === null) {
            return Response::error('File data not found', 404);
        }

        return Response::raw($content, 200, [
            'Content-Type' => $doc['mime_type'],
            'Content-Disposition' => 'attachment; filename="' . $doc['original_name'] . '"',
            'Content-Length' => (string) $doc['file_size'],
        ]);
    }

    /**
     * DELETE /api/v1/documents/{id}
     */
    public function destroy(Request $request): Response
    {
        $docId = $request->param('id');

        try {
            $this->documents->delete($docId);
            return Response::noContent();
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), $e->getCode() >= 400 ? $e->getCode() : 400);
        }
    }
}
