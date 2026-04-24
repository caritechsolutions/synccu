<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use RuntimeException;

final class CashManagementService
{
    private Database $db;
    private static bool $schemaReady = false;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function makeRef(string $id): string
    {
        return 'CSH-' . date('Ymd') . '-' . strtoupper(substr(md5($id), 0, 8));
    }

    private static array $faceValues = [
        '100' => 100, '50' => 50, '20' => 20, '10' => 10, '5' => 5, '2' => 2, '1' => 1,
        '0.50' => 0.50, '0.25' => 0.25, '0.10' => 0.10, '0.05' => 0.05, '0.01' => 0.01,
    ];

    public function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }
        $pdo = $this->db->getPdo();

        $pdo->exec("CREATE TABLE IF NOT EXISTS `fund_locations` (
            `id` CHAR(36) NOT NULL PRIMARY KEY,
            `tenant_id` CHAR(36) NOT NULL,
            `type` ENUM('vault','drawer','bank_account') NOT NULL,
            `name` VARCHAR(255) NOT NULL,
            `assigned_user_id` CHAR(36) DEFAULT NULL,
            `status` ENUM('active','closed','suspended') DEFAULT 'active',
            `bank_name` VARCHAR(255) DEFAULT NULL,
            `bank_account_number` VARCHAR(100) DEFAULT NULL,
            `balance` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `metadata` JSON DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_fl_tenant` (`tenant_id`),
            INDEX `idx_fl_type` (`tenant_id`, `type`),
            INDEX `idx_fl_user` (`tenant_id`, `assigned_user_id`)
        ) ENGINE=InnoDB");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `cash_movements` (
            `id` CHAR(36) NOT NULL PRIMARY KEY,
            `tenant_id` CHAR(36) NOT NULL,
            `type` VARCHAR(30) NOT NULL,
            `from_location_id` CHAR(36) DEFAULT NULL,
            `to_location_id` CHAR(36) DEFAULT NULL,
            `amount` DECIMAL(15,2) NOT NULL,
            `transaction_id` CHAR(36) DEFAULT NULL,
            `performed_by` CHAR(36) NOT NULL,
            `approved_by` CHAR(36) DEFAULT NULL,
            `reference_number` VARCHAR(50) NOT NULL,
            `description` VARCHAR(500) DEFAULT NULL,
            `status` ENUM('pending','completed','rejected','reversed') DEFAULT 'completed',
            `metadata` JSON DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_cm_tenant` (`tenant_id`),
            INDEX `idx_cm_from` (`from_location_id`),
            INDEX `idx_cm_to` (`to_location_id`),
            INDEX `idx_cm_txn` (`transaction_id`),
            INDEX `idx_cm_type` (`tenant_id`, `type`),
            INDEX `idx_cm_created` (`tenant_id`, `created_at`)
        ) ENGINE=InnoDB");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `cash_denominations` (
            `id` CHAR(36) NOT NULL PRIMARY KEY,
            `tenant_id` CHAR(36) NOT NULL,
            `cash_movement_id` CHAR(36) NOT NULL,
            `denomination` VARCHAR(10) NOT NULL,
            `count` INT NOT NULL DEFAULT 0,
            `value` DECIMAL(15,2) NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_cd_movement` (`cash_movement_id`),
            INDEX `idx_cd_tenant` (`tenant_id`)
        ) ENGINE=InnoDB");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `drawer_sessions` (
            `id` CHAR(36) NOT NULL PRIMARY KEY,
            `tenant_id` CHAR(36) NOT NULL,
            `drawer_id` CHAR(36) NOT NULL,
            `teller_user_id` CHAR(36) NOT NULL,
            `opened_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `closed_at` TIMESTAMP NULL DEFAULT NULL,
            `opening_balance` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            `opening_movement_id` CHAR(36) DEFAULT NULL,
            `closing_balance_expected` DECIMAL(15,2) DEFAULT NULL,
            `closing_balance_actual` DECIMAL(15,2) DEFAULT NULL,
            `discrepancy` DECIMAL(15,2) DEFAULT NULL,
            `closing_movement_id` CHAR(36) DEFAULT NULL,
            `status` ENUM('open','closed','closed_with_discrepancy') DEFAULT 'open',
            `notes` TEXT DEFAULT NULL,
            `metadata` JSON DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX `idx_ds_tenant` (`tenant_id`),
            INDEX `idx_ds_drawer` (`drawer_id`),
            INDEX `idx_ds_teller` (`teller_user_id`),
            INDEX `idx_ds_status` (`tenant_id`, `status`)
        ) ENGINE=InnoDB");

        self::$schemaReady = true;
    }

    // ------------------------------------------------------------------
    // Fund Locations
    // ------------------------------------------------------------------

    public function createLocation(string $tenantId, string $type, string $name, ?string $assignedUserId = null, ?string $bankName = null, ?string $bankAccountNumber = null): array
    {
        $this->ensureSchema();
        $id = $this->generateUuid();
        $this->db->query(
            "INSERT INTO fund_locations (id, tenant_id, type, name, assigned_user_id, bank_name, bank_account_number)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$id, $tenantId, $type, $name, $assignedUserId, $bankName, $bankAccountNumber],
        );
        return $this->getLocation($tenantId, $id);
    }

    public function listLocations(string $tenantId, array $filters = []): array
    {
        $this->ensureSchema();
        $where = 'WHERE fl.tenant_id = ?';
        $params = [$tenantId];
        if (!empty($filters['type'])) { $where .= ' AND fl.type = ?'; $params[] = $filters['type']; }
        if (!empty($filters['status'])) { $where .= ' AND fl.status = ?'; $params[] = $filters['status']; }
        return $this->db->fetchAll(
            "SELECT fl.*, CONCAT(u.first_name, ' ', u.last_name) AS assigned_user_name
             FROM fund_locations fl
             LEFT JOIN users u ON u.id = fl.assigned_user_id
             {$where} ORDER BY fl.type, fl.name",
            $params,
        );
    }

    public function getLocation(string $tenantId, string $id): ?array
    {
        $this->ensureSchema();
        return $this->db->fetchOne(
            "SELECT fl.*, CONCAT(u.first_name, ' ', u.last_name) AS assigned_user_name
             FROM fund_locations fl
             LEFT JOIN users u ON u.id = fl.assigned_user_id
             WHERE fl.id = ? AND fl.tenant_id = ?",
            [$id, $tenantId],
        );
    }

    public function updateLocation(string $tenantId, string $id, array $data): array
    {
        $this->ensureSchema();
        $fields = []; $params = [];
        foreach (['name', 'status', 'assigned_user_id', 'bank_name', 'bank_account_number'] as $k) {
            if (array_key_exists($k, $data)) { $fields[] = "`{$k}` = ?"; $params[] = $data[$k]; }
        }
        if (empty($fields)) { throw new RuntimeException('No fields to update.', 422); }
        $params[] = $id; $params[] = $tenantId;
        $this->db->execute("UPDATE fund_locations SET " . implode(', ', $fields) . " WHERE id = ? AND tenant_id = ?", $params);
        return $this->getLocation($tenantId, $id);
    }

    public function getLocationSummary(string $tenantId): array
    {
        $this->ensureSchema();
        $locations = $this->listLocations($tenantId, ['status' => 'active']);
        $vault = 0; $drawer = 0; $bank = 0;
        $grouped = ['vault' => [], 'drawer' => [], 'bank_account' => []];
        foreach ($locations as $loc) {
            $bal = (float)$loc['balance'];
            match ($loc['type']) {
                'vault' => $vault += $bal,
                'drawer' => $drawer += $bal,
                'bank_account' => $bank += $bal,
            };
            $grouped[$loc['type']][] = $loc;
        }
        return [
            'vault_total' => $vault,
            'drawer_total' => $drawer,
            'bank_total' => $bank,
            'grand_total' => $vault + $drawer + $bank,
            'locations' => $grouped,
        ];
    }

    // ------------------------------------------------------------------
    // Denomination helpers
    // ------------------------------------------------------------------

    private function saveDenominations(string $tenantId, string $movementId, array $denominations): void
    {
        foreach ($denominations as $d) {
            $denom = (string)($d['denomination'] ?? '');
            $count = (int)($d['count'] ?? 0);
            if ($count <= 0 || $denom === '') continue;
            $faceValue = self::$faceValues[$denom] ?? (float)$denom;
            if ($faceValue <= 0) continue;
            $value = $count * $faceValue;
            $this->db->query(
                "INSERT INTO cash_denominations (id, tenant_id, cash_movement_id, denomination, `count`, value)
                 VALUES (?, ?, ?, ?, ?, ?)",
                [$this->generateUuid(), $tenantId, $movementId, $denom, $count, $value],
            );
        }
    }

    public function getDenominations(string $movementId): array
    {
        return $this->db->fetchAll(
            "SELECT denomination, `count`, value FROM cash_denominations WHERE cash_movement_id = ? ORDER BY value DESC",
            [$movementId],
        );
    }

    public function getLocationDenominations(string $tenantId, string $locationId): array
    {
        return $this->db->fetchAll(
            "SELECT denomination,
                    SUM(CASE WHEN cm.to_location_id = ? THEN cd.`count` ELSE 0 END)
                  - SUM(CASE WHEN cm.from_location_id = ? THEN cd.`count` ELSE 0 END) AS net_count,
                    SUM(CASE WHEN cm.to_location_id = ? THEN cd.value ELSE 0 END)
                  - SUM(CASE WHEN cm.from_location_id = ? THEN cd.value ELSE 0 END) AS net_value
             FROM cash_denominations cd
             JOIN cash_movements cm ON cm.id = cd.cash_movement_id
             WHERE cd.tenant_id = ? AND (cm.to_location_id = ? OR cm.from_location_id = ?)
               AND cm.status = 'completed'
             GROUP BY denomination
             HAVING net_count > 0
             ORDER BY net_value DESC",
            [$locationId, $locationId, $locationId, $locationId, $tenantId, $locationId, $locationId],
        );
    }

    // ------------------------------------------------------------------
    // Cash Movements
    // ------------------------------------------------------------------

    public function dispenseToDrawer(string $tenantId, string $vaultId, string $drawerId, float $amount, array $denominations, string $performedBy, ?string $approvedBy = null): array
    {
        $this->ensureSchema();
        $vault = $this->getLocation($tenantId, $vaultId);
        if (!$vault || $vault['type'] !== 'vault') throw new RuntimeException('Vault not found.', 404);
        if ((float)$vault['balance'] < $amount) throw new RuntimeException('Insufficient funds in vault.', 422);
        $drawer = $this->getLocation($tenantId, $drawerId);
        if (!$drawer || $drawer['type'] !== 'drawer') throw new RuntimeException('Drawer not found.', 404);

        $id = $this->generateUuid();
        $ref = $this->makeRef($id);

        return $this->db->transaction(function () use ($id, $tenantId, $vaultId, $drawerId, $amount, $denominations, $performedBy, $approvedBy, $ref, $vault, $drawer) {
            $this->db->execute("UPDATE fund_locations SET balance = balance - ? WHERE id = ?", [$amount, $vaultId]);
            $this->db->execute("UPDATE fund_locations SET balance = balance + ? WHERE id = ?", [$amount, $drawerId]);
            $this->db->query(
                "INSERT INTO cash_movements (id, tenant_id, type, from_location_id, to_location_id, amount, performed_by, approved_by, reference_number, description)
                 VALUES (?, ?, 'vault_to_drawer', ?, ?, ?, ?, ?, ?, ?)",
                [$id, $tenantId, $vaultId, $drawerId, $amount, $performedBy, $approvedBy, $ref,
                 "Dispense to " . $drawer['name'] . " from " . $vault['name']],
            );
            $this->saveDenominations($tenantId, $id, $denominations);
            return ['id' => $id, 'reference' => $ref, 'amount' => $amount, 'type' => 'vault_to_drawer'];
        });
    }

    public function returnToVault(string $tenantId, string $drawerId, string $vaultId, float $amount, array $denominations, string $performedBy): array
    {
        $this->ensureSchema();
        $drawer = $this->getLocation($tenantId, $drawerId);
        if (!$drawer || $drawer['type'] !== 'drawer') throw new RuntimeException('Drawer not found.', 404);
        if ((float)$drawer['balance'] < $amount) throw new RuntimeException('Insufficient funds in drawer.', 422);
        $vault = $this->getLocation($tenantId, $vaultId);
        if (!$vault || $vault['type'] !== 'vault') throw new RuntimeException('Vault not found.', 404);

        $id = $this->generateUuid();
        $ref = $this->makeRef($id);

        return $this->db->transaction(function () use ($id, $tenantId, $drawerId, $vaultId, $amount, $denominations, $performedBy, $ref, $drawer, $vault) {
            $this->db->execute("UPDATE fund_locations SET balance = balance - ? WHERE id = ?", [$amount, $drawerId]);
            $this->db->execute("UPDATE fund_locations SET balance = balance + ? WHERE id = ?", [$amount, $vaultId]);
            $this->db->query(
                "INSERT INTO cash_movements (id, tenant_id, type, from_location_id, to_location_id, amount, performed_by, reference_number, description)
                 VALUES (?, ?, 'drawer_to_vault', ?, ?, ?, ?, ?, ?)",
                [$id, $tenantId, $drawerId, $vaultId, $amount, $performedBy, $ref,
                 "Return from " . $drawer['name'] . " to " . $vault['name']],
            );
            $this->saveDenominations($tenantId, $id, $denominations);
            return ['id' => $id, 'reference' => $ref, 'amount' => $amount, 'type' => 'drawer_to_vault'];
        });
    }

    public function recordDepositCash(string $tenantId, string $drawerId, float $amount, array $denominations, string $transactionId, string $performedBy): array
    {
        $this->ensureSchema();
        $id = $this->generateUuid();
        $ref = $this->makeRef($id);

        return $this->db->transaction(function () use ($id, $tenantId, $drawerId, $amount, $denominations, $transactionId, $performedBy, $ref) {
            $this->db->execute("UPDATE fund_locations SET balance = balance + ? WHERE id = ?", [$amount, $drawerId]);
            $this->db->query(
                "INSERT INTO cash_movements (id, tenant_id, type, to_location_id, amount, transaction_id, performed_by, reference_number, description)
                 VALUES (?, ?, 'deposit_to_drawer', ?, ?, ?, ?, ?, 'Member cash deposit')",
                [$id, $tenantId, $drawerId, $amount, $transactionId, $performedBy, $ref],
            );
            $this->saveDenominations($tenantId, $id, $denominations);
            return ['id' => $id, 'reference' => $ref, 'amount' => $amount, 'type' => 'deposit_to_drawer'];
        });
    }

    public function recordWithdrawalCash(string $tenantId, string $drawerId, float $amount, array $denominations, string $transactionId, string $performedBy): array
    {
        $this->ensureSchema();
        $drawer = $this->getLocation($tenantId, $drawerId);
        if (!$drawer || (float)$drawer['balance'] < $amount) throw new RuntimeException('Insufficient funds in drawer.', 422);

        $id = $this->generateUuid();
        $ref = $this->makeRef($id);

        return $this->db->transaction(function () use ($id, $tenantId, $drawerId, $amount, $denominations, $transactionId, $performedBy, $ref) {
            $this->db->execute("UPDATE fund_locations SET balance = balance - ? WHERE id = ?", [$amount, $drawerId]);
            $this->db->query(
                "INSERT INTO cash_movements (id, tenant_id, type, from_location_id, amount, transaction_id, performed_by, reference_number, description)
                 VALUES (?, ?, 'withdrawal_from_drawer', ?, ?, ?, ?, ?, 'Member cash withdrawal')",
                [$id, $tenantId, $drawerId, $amount, $transactionId, $performedBy, $ref],
            );
            $this->saveDenominations($tenantId, $id, $denominations);
            return ['id' => $id, 'reference' => $ref, 'amount' => $amount, 'type' => 'withdrawal_from_drawer'];
        });
    }

    public function recordBankTransfer(string $tenantId, string $locationId, string $direction, float $amount, string $performedBy, string $description = '', ?string $vaultId = null): array
    {
        $this->ensureSchema();
        $loc = $this->getLocation($tenantId, $locationId);
        if (!$loc || $loc['type'] !== 'bank_account') throw new RuntimeException('Bank account not found.', 404);

        $vault = null;
        if ($vaultId) {
            $vault = $this->getLocation($tenantId, $vaultId);
            if (!$vault || $vault['type'] !== 'vault') throw new RuntimeException('Vault not found.', 404);
        }

        if ($direction === 'out') {
            if ($vault && (float)$vault['balance'] < $amount) throw new RuntimeException('Insufficient funds in vault.', 422);
        } else {
            if ((float)$loc['balance'] < $amount) throw new RuntimeException('Insufficient funds in bank account.', 422);
        }

        $id = $this->generateUuid();
        $ref = $this->makeRef($id);
        $type = $direction === 'in' ? 'bank_transfer_in' : 'bank_transfer_out';

        return $this->db->transaction(function () use ($id, $tenantId, $locationId, $direction, $amount, $performedBy, $description, $ref, $type, $vaultId) {
            if ($direction === 'out') {
                $this->db->execute("UPDATE fund_locations SET balance = balance + ? WHERE id = ?", [$amount, $locationId]);
                if ($vaultId) {
                    $this->db->execute("UPDATE fund_locations SET balance = balance - ? WHERE id = ?", [$amount, $vaultId]);
                }
                $this->db->query(
                    "INSERT INTO cash_movements (id, tenant_id, type, from_location_id, to_location_id, amount, performed_by, reference_number, description)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [$id, $tenantId, $type, $vaultId, $locationId, $amount, $performedBy, $ref, $description ?: 'Bank transfer out (vault to bank)'],
                );
            } else {
                $this->db->execute("UPDATE fund_locations SET balance = balance - ? WHERE id = ?", [$amount, $locationId]);
                if ($vaultId) {
                    $this->db->execute("UPDATE fund_locations SET balance = balance + ? WHERE id = ?", [$amount, $vaultId]);
                }
                $this->db->query(
                    "INSERT INTO cash_movements (id, tenant_id, type, from_location_id, to_location_id, amount, performed_by, reference_number, description)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [$id, $tenantId, $type, $locationId, $vaultId, $amount, $performedBy, $ref, $description ?: 'Bank transfer in (bank to vault)'],
                );
            }
            return ['id' => $id, 'reference' => $ref, 'amount' => $amount, 'type' => $type];
        });
    }

    public function adjustBalance(string $tenantId, string $locationId, float $amount, array $denominations, string $performedBy, string $description = ''): array
    {
        $this->ensureSchema();
        $loc = $this->getLocation($tenantId, $locationId);
        if (!$loc) throw new RuntimeException('Location not found.', 404);
        if ($amount < 0 && (float)$loc['balance'] < abs($amount)) throw new RuntimeException('Insufficient funds for negative adjustment.', 422);

        $id = $this->generateUuid();
        $ref = $this->makeRef($id);

        return $this->db->transaction(function () use ($id, $tenantId, $locationId, $amount, $denominations, $performedBy, $description, $ref) {
            $this->db->execute("UPDATE fund_locations SET balance = balance + ? WHERE id = ?", [$amount, $locationId]);
            $from = $amount < 0 ? $locationId : null;
            $to = $amount >= 0 ? $locationId : null;
            $this->db->query(
                "INSERT INTO cash_movements (id, tenant_id, type, from_location_id, to_location_id, amount, performed_by, reference_number, description)
                 VALUES (?, ?, 'adjustment', ?, ?, ?, ?, ?, ?)",
                [$id, $tenantId, $from, $to, abs($amount), $performedBy, $ref, $description ?: 'Manual adjustment'],
            );
            $this->saveDenominations($tenantId, $id, $denominations);
            return ['id' => $id, 'reference' => $ref, 'amount' => $amount, 'type' => 'adjustment'];
        });
    }

    // ------------------------------------------------------------------
    // Drawer Sessions
    // ------------------------------------------------------------------

    public function openDrawerSession(string $tenantId, string $drawerId, string $tellerUserId, float $openingBalance, ?string $movementId = null): array
    {
        $this->ensureSchema();
        $existing = $this->getActiveSession($tenantId, $drawerId);
        if ($existing) throw new RuntimeException('This drawer already has an open session.', 422);

        $id = $this->generateUuid();
        $this->db->query(
            "INSERT INTO drawer_sessions (id, tenant_id, drawer_id, teller_user_id, opening_balance, opening_movement_id)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$id, $tenantId, $drawerId, $tellerUserId, $openingBalance, $movementId],
        );
        return $this->db->fetchOne("SELECT * FROM drawer_sessions WHERE id = ?", [$id]);
    }

    public function closeDrawerSession(string $tenantId, string $sessionId, float $actualBalance, array $denominations, string $performedBy, ?string $notes = null): array
    {
        $this->ensureSchema();
        $session = $this->db->fetchOne(
            "SELECT * FROM drawer_sessions WHERE id = ? AND tenant_id = ? AND status = 'open'",
            [$sessionId, $tenantId],
        );
        if (!$session) throw new RuntimeException('Open session not found.', 404);

        $deposited = (float)$this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount), 0) FROM cash_movements
             WHERE tenant_id = ? AND to_location_id = ? AND type = 'deposit_to_drawer'
               AND created_at >= ? AND status = 'completed'",
            [$tenantId, $session['drawer_id'], $session['opened_at']],
        );
        $withdrawn = (float)$this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount), 0) FROM cash_movements
             WHERE tenant_id = ? AND from_location_id = ? AND type = 'withdrawal_from_drawer'
               AND created_at >= ? AND status = 'completed'",
            [$tenantId, $session['drawer_id'], $session['opened_at']],
        );
        $dispensed = (float)$this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount), 0) FROM cash_movements
             WHERE tenant_id = ? AND to_location_id = ? AND type = 'vault_to_drawer'
               AND created_at >= ? AND status = 'completed'",
            [$tenantId, $session['drawer_id'], $session['opened_at']],
        );
        $returned = (float)$this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount), 0) FROM cash_movements
             WHERE tenant_id = ? AND from_location_id = ? AND type = 'drawer_to_vault'
               AND created_at >= ? AND status = 'completed'",
            [$tenantId, $session['drawer_id'], $session['opened_at']],
        );

        $expected = (float)$session['opening_balance'] + $deposited + $dispensed - $withdrawn - $returned;
        $discrepancy = round($actualBalance - $expected, 2);
        $status = abs($discrepancy) < 0.01 ? 'closed' : 'closed_with_discrepancy';

        $closingMovementId = null;
        if ($actualBalance > 0) {
            $vault = $this->db->fetchOne(
                "SELECT id FROM fund_locations WHERE tenant_id = ? AND type = 'vault' AND status = 'active' LIMIT 1",
                [$tenantId],
            );
            if ($vault) {
                $mv = $this->returnToVault($tenantId, $session['drawer_id'], $vault['id'], $actualBalance, $denominations, $performedBy);
                $closingMovementId = $mv['id'];
            }
        }

        $this->db->execute(
            "UPDATE drawer_sessions SET closed_at = NOW(), closing_balance_expected = ?, closing_balance_actual = ?,
             discrepancy = ?, closing_movement_id = ?, status = ?, notes = ? WHERE id = ?",
            [$expected, $actualBalance, $discrepancy, $closingMovementId, $status, $notes, $sessionId],
        );

        return $this->db->fetchOne("SELECT * FROM drawer_sessions WHERE id = ?", [$sessionId]);
    }

    public function getActiveSession(string $tenantId, string $drawerId): ?array
    {
        $this->ensureSchema();
        return $this->db->fetchOne(
            "SELECT ds.*, CONCAT(u.first_name, ' ', u.last_name) AS teller_name
             FROM drawer_sessions ds
             JOIN users u ON u.id = ds.teller_user_id
             WHERE ds.tenant_id = ? AND ds.drawer_id = ? AND ds.status = 'open'",
            [$tenantId, $drawerId],
        );
    }

    public function getActiveSessions(string $tenantId): array
    {
        $this->ensureSchema();
        return $this->db->fetchAll(
            "SELECT ds.*, CONCAT(u.first_name, ' ', u.last_name) AS teller_name, fl.name AS drawer_name
             FROM drawer_sessions ds
             JOIN users u ON u.id = ds.teller_user_id
             JOIN fund_locations fl ON fl.id = ds.drawer_id
             WHERE ds.tenant_id = ? AND ds.status = 'open'
             ORDER BY ds.opened_at",
            [$tenantId],
        );
    }

    public function listSessions(string $tenantId, array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $this->ensureSchema();
        $where = 'WHERE ds.tenant_id = ?';
        $params = [$tenantId];
        if (!empty($filters['status'])) { $where .= ' AND ds.status = ?'; $params[] = $filters['status']; }
        if (!empty($filters['teller_user_id'])) { $where .= ' AND ds.teller_user_id = ?'; $params[] = $filters['teller_user_id']; }
        if (!empty($filters['date_from'])) { $where .= ' AND ds.opened_at >= ?'; $params[] = $filters['date_from']; }
        if (!empty($filters['date_to'])) { $where .= ' AND ds.opened_at <= ?'; $params[] = $filters['date_to'] . ' 23:59:59'; }

        $total = (int)$this->db->fetchColumn("SELECT COUNT(*) FROM drawer_sessions ds {$where}", $params);
        $offset = ($page - 1) * $perPage;
        $rows = $this->db->fetchAll(
            "SELECT ds.*, CONCAT(u.first_name, ' ', u.last_name) AS teller_name, fl.name AS drawer_name
             FROM drawer_sessions ds
             JOIN users u ON u.id = ds.teller_user_id
             JOIN fund_locations fl ON fl.id = ds.drawer_id
             {$where} ORDER BY ds.opened_at DESC LIMIT {$perPage} OFFSET {$offset}",
            $params,
        );
        return ['items' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    // ------------------------------------------------------------------
    // Movement listing
    // ------------------------------------------------------------------

    public function listMovements(string $tenantId, array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $this->ensureSchema();
        $where = 'WHERE cm.tenant_id = ?';
        $params = [$tenantId];
        if (!empty($filters['type'])) { $where .= ' AND cm.type = ?'; $params[] = $filters['type']; }
        if (!empty($filters['location_id'])) { $where .= ' AND (cm.from_location_id = ? OR cm.to_location_id = ?)'; $params[] = $filters['location_id']; $params[] = $filters['location_id']; }
        if (!empty($filters['date_from'])) { $where .= ' AND cm.created_at >= ?'; $params[] = $filters['date_from']; }
        if (!empty($filters['date_to'])) { $where .= ' AND cm.created_at <= ?'; $params[] = $filters['date_to'] . ' 23:59:59'; }

        $total = (int)$this->db->fetchColumn("SELECT COUNT(*) FROM cash_movements cm {$where}", $params);
        $offset = ($page - 1) * $perPage;
        $rows = $this->db->fetchAll(
            "SELECT cm.*, fl_from.name AS from_name, fl_to.name AS to_name,
                    CONCAT(u.first_name, ' ', u.last_name) AS performed_by_name
             FROM cash_movements cm
             LEFT JOIN fund_locations fl_from ON fl_from.id = cm.from_location_id
             LEFT JOIN fund_locations fl_to ON fl_to.id = cm.to_location_id
             LEFT JOIN users u ON u.id = cm.performed_by
             {$where} ORDER BY cm.created_at DESC LIMIT {$perPage} OFFSET {$offset}",
            $params,
        );
        return ['items' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    public function getMovement(string $tenantId, string $id): ?array
    {
        $this->ensureSchema();
        $row = $this->db->fetchOne(
            "SELECT cm.*, fl_from.name AS from_name, fl_to.name AS to_name,
                    CONCAT(u.first_name, ' ', u.last_name) AS performed_by_name
             FROM cash_movements cm
             LEFT JOIN fund_locations fl_from ON fl_from.id = cm.from_location_id
             LEFT JOIN fund_locations fl_to ON fl_to.id = cm.to_location_id
             LEFT JOIN users u ON u.id = cm.performed_by
             WHERE cm.id = ? AND cm.tenant_id = ?",
            [$id, $tenantId],
        );
        if ($row) $row['denominations'] = $this->getDenominations($id);
        return $row;
    }

    // ------------------------------------------------------------------
    // Teller helper
    // ------------------------------------------------------------------

    public function getMyDrawer(string $tenantId, string $userId): ?array
    {
        $this->ensureSchema();
        $drawer = $this->db->fetchOne(
            "SELECT * FROM fund_locations WHERE tenant_id = ? AND type = 'drawer' AND assigned_user_id = ? AND status = 'active'",
            [$tenantId, $userId],
        );
        if ($drawer) {
            $drawer['active_session'] = $this->getActiveSession($tenantId, $drawer['id']);
            $drawer['denominations'] = $this->getLocationDenominations($tenantId, $drawer['id']);
        }
        return $drawer;
    }
}
