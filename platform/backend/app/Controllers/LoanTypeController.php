<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

/**
 * Manage the loan_types reference codes (the platform equivalent of the old
 * core system's "Edit Loan Type Description" screen).
 */
final class LoanTypeController
{
    private const CATEGORIES = ['personal', 'auto', 'mortgage', 'business', 'education', 'credit_line'];

    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    private function generateUuid(): string
    {
        $d = random_bytes(16);
        $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
        $d[8] = chr(ord($d[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }

    /** GET /api/v1/admin/loan-types */
    public function index(Request $request): Response
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM loan_types WHERE tenant_id = ? ORDER BY code ASC',
            [$this->db->getTenantId()],
        );
        return Response::ok(['loan_types' => $rows]);
    }

    /** POST /api/v1/admin/loan-types */
    public function store(Request $request): Response
    {
        $validator = new Validator($request->all(), [
            'code'        => 'required|string|max:2',
            'description' => 'required|string|max:100',
            'category'    => 'required|in:' . implode(',', self::CATEGORIES),
        ]);
        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }
        $data = $validator->validated();
        $code = strtoupper(trim($data['code']));

        $exists = $this->db->fetchColumn(
            'SELECT id FROM loan_types WHERE tenant_id = ? AND code = ?',
            [$this->db->getTenantId(), $code],
        );
        if ($exists !== false) {
            return Response::error("A loan type with code '{$code}' already exists.", 422);
        }

        $id = $this->generateUuid();
        $this->db->insertScoped('loan_types', [
            'id'          => $id,
            'code'        => $code,
            'description' => $data['description'],
            'category'    => $data['category'],
            'is_active'   => 1,
        ]);
        return Response::created(['id' => $id], 'Loan type created.');
    }

    /** PUT /api/v1/admin/loan-types/{id} */
    public function update(Request $request): Response
    {
        $id = (string) $request->param('id');
        $existing = $this->db->fetchOne(
            'SELECT * FROM loan_types WHERE id = ? AND tenant_id = ?',
            [$id, $this->db->getTenantId()],
        );
        if ($existing === null) {
            return Response::error('Loan type not found.', 404);
        }

        $validator = new Validator($request->all(), [
            'description' => 'nullable|string|max:100',
            'category'    => 'nullable|in:' . implode(',', self::CATEGORIES),
            'is_active'   => 'nullable|in:0,1',
        ]);
        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }
        $data = $validator->validated();

        $update = [];
        if (isset($data['description'])) $update['description'] = $data['description'];
        if (isset($data['category']))    $update['category']    = $data['category'];
        if (isset($data['is_active']))   $update['is_active']   = (int) $data['is_active'];

        if ($update) {
            $update['updated_at'] = date('Y-m-d H:i:s');
            $this->db->updateScoped('loan_types', $update, ['id' => $id]);
        }
        return Response::ok(['id' => $id], 'Loan type updated.');
    }

    /** DELETE /api/v1/admin/loan-types/{id} */
    public function destroy(Request $request): Response
    {
        $id = (string) $request->param('id');
        $deleted = $this->db->deleteScoped('loan_types', ['id' => $id]);
        if ($deleted === 0) {
            return Response::error('Loan type not found.', 404);
        }
        return Response::ok(['deleted' => true], 'Loan type deleted.');
    }
}
