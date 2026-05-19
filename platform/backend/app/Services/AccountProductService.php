<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use RuntimeException;

final class AccountProductService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function create(string $tenantId, array $data): array
    {
        $id = $this->generateUuid();

        $earningType = in_array($data['category'], ['permanent_shares', 'regular_shares'])
            ? 'dividend' : 'interest';

        $this->db->query(
            "INSERT INTO account_products
                (id, tenant_id, name, code, category, earning_type, earning_rate, earning_interval,
                 min_opening_balance, description, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $id, $tenantId,
                $data['name'],
                strtoupper($data['code']),
                $data['category'],
                $earningType,
                (float) ($data['earning_rate'] ?? 0),
                $data['earning_interval'] ?? 'yearly',
                (float) ($data['min_opening_balance'] ?? 0),
                $data['description'] ?? null,
                !empty($data['expires_at']) ? $data['expires_at'] : null,
            ],
        );

        return $this->findById($tenantId, $id);
    }

    public function update(string $tenantId, string $id, array $data): array
    {
        $product = $this->findById($tenantId, $id);
        if (!$product) throw new RuntimeException('Product not found.', 404);

        $fields = [];
        $params = [];
        foreach (['name', 'earning_rate', 'earning_interval', 'min_opening_balance', 'description', 'status'] as $k) {
            if (array_key_exists($k, $data)) {
                $fields[] = "`{$k}` = ?";
                $params[] = $data[$k];
            }
        }
        if (array_key_exists('code', $data)) {
            $fields[] = '`code` = ?';
            $params[] = strtoupper($data['code']);
        }
        if (array_key_exists('expires_at', $data)) {
            $fields[] = '`expires_at` = ?';
            $params[] = !empty($data['expires_at']) ? $data['expires_at'] : null;
        }

        if (empty($fields)) return $product;

        $params[] = $id;
        $params[] = $tenantId;
        $this->db->execute(
            "UPDATE account_products SET " . implode(', ', $fields) . " WHERE id = ? AND tenant_id = ?",
            $params,
        );

        return $this->findById($tenantId, $id);
    }

    public function findById(string $tenantId, string $id): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM account_products WHERE id = ? AND tenant_id = ?",
            [$id, $tenantId],
        );
    }

    public function list(string $tenantId, array $filters = []): array
    {
        $where = 'WHERE tenant_id = ?';
        $params = [$tenantId];

        if (!empty($filters['category'])) {
            $where .= ' AND category = ?';
            $params[] = $filters['category'];
        }
        if (!empty($filters['status'])) {
            $where .= ' AND status = ?';
            $params[] = $filters['status'];
        }

        return $this->db->fetchAll(
            "SELECT * FROM account_products {$where} ORDER BY category, name",
            $params,
        );
    }

    public function listAvailable(string $tenantId, ?string $category = null): array
    {
        $where = 'WHERE tenant_id = ? AND status = ?';
        $params = [$tenantId, 'active'];

        if ($category) {
            $where .= ' AND category = ?';
            $params[] = $category;
        }

        $where .= ' AND (expires_at IS NULL OR expires_at > NOW())';

        return $this->db->fetchAll(
            "SELECT * FROM account_products {$where} ORDER BY category, name",
            $params,
        );
    }

    public function delete(string $tenantId, string $id): void
    {
        $this->db->execute(
            "UPDATE account_products SET status = 'inactive' WHERE id = ? AND tenant_id = ?",
            [$id, $tenantId],
        );
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
