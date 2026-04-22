<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use RuntimeException;

final class NetworkService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ------------------------------------------------------------------
    // Schema self-healing
    // ------------------------------------------------------------------

    public function ensureSchema(): void
    {
        $pdo = $this->db->getPdo();

        $tables = [
            'network_nodes' => "CREATE TABLE IF NOT EXISTS `network_nodes` (
                `id` CHAR(36) NOT NULL PRIMARY KEY,
                `tenant_id` CHAR(36) NOT NULL,
                `node_code` VARCHAR(20) NOT NULL,
                `name` VARCHAR(255) NOT NULL,
                `endpoint_url` VARCHAR(512) NOT NULL,
                `api_key_hash` VARCHAR(255) NOT NULL,
                `api_key_prefix` VARCHAR(10) NOT NULL,
                `outbound_api_key` VARCHAR(255) DEFAULT NULL,
                `status` ENUM('active','inactive','pending','unreachable') DEFAULT 'pending',
                `is_router` TINYINT(1) DEFAULT 0,
                `last_heartbeat_at` TIMESTAMP NULL DEFAULT NULL,
                `metadata` JSON DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `unique_tenant_node_code` (`tenant_id`, `node_code`),
                INDEX `idx_network_nodes_tenant` (`tenant_id`),
                INDEX `idx_network_nodes_status` (`status`)
            ) ENGINE=InnoDB",

            'network_transfers' => "CREATE TABLE IF NOT EXISTS `network_transfers` (
                `id` CHAR(36) NOT NULL PRIMARY KEY,
                `tenant_id` CHAR(36) NOT NULL,
                `direction` ENUM('outbound','inbound') NOT NULL,
                `peer_node_id` CHAR(36) DEFAULT NULL,
                `peer_node_code` VARCHAR(20) NOT NULL,
                `local_account_id` CHAR(36) DEFAULT NULL,
                `local_transaction_id` CHAR(36) DEFAULT NULL,
                `remote_reference` VARCHAR(50) DEFAULT NULL,
                `sender_name` VARCHAR(255) NOT NULL,
                `sender_account` VARCHAR(50) NOT NULL,
                `sender_institution` VARCHAR(255) NOT NULL,
                `recipient_name` VARCHAR(255) NOT NULL,
                `recipient_account` VARCHAR(50) NOT NULL,
                `recipient_institution` VARCHAR(255) NOT NULL,
                `amount` DECIMAL(15,2) NOT NULL,
                `fee` DECIMAL(15,2) DEFAULT 0.00,
                `currency` CHAR(3) DEFAULT 'USD',
                `status` ENUM('pending','sent','received','confirmed','failed','reversed','settled') DEFAULT 'pending',
                `settled_in_batch` CHAR(36) DEFAULT NULL,
                `failure_reason` VARCHAR(500) DEFAULT NULL,
                `metadata` JSON DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_network_transfers_tenant` (`tenant_id`),
                INDEX `idx_network_transfers_peer` (`peer_node_code`),
                INDEX `idx_network_transfers_status` (`status`),
                INDEX `idx_network_transfers_created` (`created_at`)
            ) ENGINE=InnoDB",

            'network_settlements' => "CREATE TABLE IF NOT EXISTS `network_settlements` (
                `id` CHAR(36) NOT NULL PRIMARY KEY,
                `tenant_id` CHAR(36) NOT NULL,
                `peer_node_id` CHAR(36) DEFAULT NULL,
                `peer_node_code` VARCHAR(20) NOT NULL,
                `period_start` DATE NOT NULL,
                `period_end` DATE NOT NULL,
                `total_outbound` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `total_inbound` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `net_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `transfer_count` INT NOT NULL DEFAULT 0,
                `status` ENUM('draft','pending_approval','approved','invoiced','paid','disputed') DEFAULT 'draft',
                `approved_by` CHAR(36) DEFAULT NULL,
                `approved_at` TIMESTAMP NULL DEFAULT NULL,
                `notes` TEXT DEFAULT NULL,
                `metadata` JSON DEFAULT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_network_settlements_tenant` (`tenant_id`),
                INDEX `idx_network_settlements_peer` (`peer_node_code`),
                INDEX `idx_network_settlements_status` (`status`)
            ) ENGINE=InnoDB",
        ];

        foreach ($tables as $sql) {
            $pdo->exec($sql);
        }

        try {
            $pdo->exec("ALTER TABLE `network_nodes` ADD COLUMN `outbound_api_key` VARCHAR(255) DEFAULT NULL AFTER `api_key_prefix`");
        } catch (\PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column') === false) {
                throw $e;
            }
        }
    }

    // ------------------------------------------------------------------
    // Node Management
    // ------------------------------------------------------------------

    public function listNodes(string $tenantId, array $filters = []): array
    {
        $this->ensureSchema();

        $where = 'WHERE n.tenant_id = ?';
        $params = [$tenantId];

        if (!empty($filters['status'])) {
            $where .= ' AND n.status = ?';
            $params[] = $filters['status'];
        }

        if (!empty($filters['search'])) {
            $where .= ' AND (n.name LIKE ? OR n.node_code LIKE ?)';
            $params[] = '%' . $filters['search'] . '%';
            $params[] = '%' . $filters['search'] . '%';
        }

        return $this->db->fetchAll(
            "SELECT n.id, n.node_code, n.name, n.endpoint_url,
                    n.status, n.is_router, n.last_heartbeat_at, n.api_key_prefix,
                    CASE WHEN n.outbound_api_key IS NOT NULL THEN 1 ELSE 0 END as has_outbound_key,
                    n.created_at, n.updated_at
             FROM network_nodes n
             {$where}
             ORDER BY n.name ASC",
            $params,
        );
    }

    public function getNode(string $tenantId, string $nodeId): ?array
    {
        $this->ensureSchema();

        return $this->db->fetchOne(
            "SELECT id, node_code, name, endpoint_url,
                    status, is_router, last_heartbeat_at,
                    api_key_prefix, outbound_api_key,
                    CASE WHEN outbound_api_key IS NOT NULL THEN 1 ELSE 0 END as has_outbound_key,
                    metadata, created_at, updated_at
             FROM network_nodes
             WHERE id = ? AND tenant_id = ?",
            [$nodeId, $tenantId],
        );
    }

    public function findNodeByCode(string $tenantId, string $nodeCode): ?array
    {
        $this->ensureSchema();

        return $this->db->fetchOne(
            "SELECT * FROM network_nodes WHERE tenant_id = ? AND node_code = ?",
            [$tenantId, $nodeCode],
        );
    }

    public function registerNode(
        string $tenantId,
        string $nodeCode,
        string $name,
        string $endpointUrl,
        string $apiKey,
        bool $isRouter = false,
        ?string $outboundApiKey = null,
    ): array {
        $this->ensureSchema();

        $existing = $this->findNodeByCode($tenantId, $nodeCode);
        if ($existing) {
            throw new RuntimeException('A node with this code already exists.', 409);
        }

        $id = $this->generateUuid();
        $apiKeyHash = password_hash($apiKey, PASSWORD_BCRYPT);
        $apiKeyPrefix = substr($apiKey, 0, 8);

        $this->db->query(
            "INSERT INTO network_nodes
                (id, tenant_id, node_code, name, endpoint_url, api_key_hash, api_key_prefix,
                 outbound_api_key, is_router, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())",
            [
                $id, $tenantId, strtoupper($nodeCode), $name, $endpointUrl,
                $apiKeyHash, $apiKeyPrefix,
                $outboundApiKey,
                $isRouter ? 1 : 0,
            ],
        );

        return [
            'id'         => $id,
            'node_code'  => strtoupper($nodeCode),
            'name'       => $name,
            'api_key'    => $apiKey,
            'status'     => 'pending',
        ];
    }

    public function updateNode(string $tenantId, string $nodeId, array $data): array
    {
        $node = $this->getNode($tenantId, $nodeId);
        if (!$node) {
            throw new RuntimeException('Node not found.', 404);
        }

        $fields = [];
        $params = [];

        foreach (['name', 'endpoint_url', 'status', 'outbound_api_key'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $params[] = $data[$field];
            }
        }

        if (array_key_exists('is_router', $data)) {
            $fields[] = 'is_router = ?';
            $params[] = $data['is_router'] ? 1 : 0;
        }

        if (isset($data['api_key'])) {
            $fields[] = 'api_key_hash = ?';
            $fields[] = 'api_key_prefix = ?';
            $params[] = password_hash($data['api_key'], PASSWORD_BCRYPT);
            $params[] = substr($data['api_key'], 0, 8);
        }

        if (empty($fields)) {
            return $node;
        }

        $fields[] = 'updated_at = NOW()';
        $params[] = $nodeId;
        $params[] = $tenantId;

        $this->db->execute(
            "UPDATE network_nodes SET " . implode(', ', $fields) . " WHERE id = ? AND tenant_id = ?",
            $params,
        );

        return $this->getNode($tenantId, $nodeId);
    }

    public function deleteNode(string $tenantId, string $nodeId): void
    {
        $node = $this->getNode($tenantId, $nodeId);
        if (!$node) {
            throw new RuntimeException('Node not found.', 404);
        }

        $pending = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM network_transfers
             WHERE tenant_id = ? AND peer_node_id = ? AND status IN ('pending','sent','received')",
            [$tenantId, $nodeId],
        );

        if ((int)$pending > 0) {
            throw new RuntimeException('Cannot delete node with pending transfers.', 409);
        }

        $this->db->execute(
            "DELETE FROM network_nodes WHERE id = ? AND tenant_id = ?",
            [$nodeId, $tenantId],
        );
    }

    public function activateNode(string $tenantId, string $nodeId): array
    {
        return $this->updateNode($tenantId, $nodeId, ['status' => 'active']);
    }

    public function deactivateNode(string $tenantId, string $nodeId): array
    {
        return $this->updateNode($tenantId, $nodeId, ['status' => 'inactive']);
    }

    public function generateApiKey(): string
    {
        return bin2hex(random_bytes(32));
    }

    // ------------------------------------------------------------------
    // Heartbeat
    // ------------------------------------------------------------------

    public function recordHeartbeat(string $tenantId, string $nodeId): void
    {
        $this->db->execute(
            "UPDATE network_nodes SET last_heartbeat_at = NOW(), status = 'active', updated_at = NOW()
             WHERE id = ? AND tenant_id = ?",
            [$nodeId, $tenantId],
        );
    }

    // ------------------------------------------------------------------
    // Inter-CU Transfers
    // ------------------------------------------------------------------

    public function initiateOutboundTransfer(
        string $tenantId,
        string $peerNodeCode,
        string $localAccountId,
        string $senderName,
        string $senderAccount,
        string $senderInstitution,
        string $recipientName,
        string $recipientAccount,
        string $recipientInstitution,
        float $amount,
        float $fee = 0.0,
        string $currency = 'USD',
        ?string $userId = null,
    ): array {
        $this->ensureSchema();

        $node = $this->findNodeByCode($tenantId, $peerNodeCode);
        if (!$node) {
            throw new RuntimeException('Destination node not found.', 404);
        }
        if ($node['status'] !== 'active') {
            throw new RuntimeException('Destination node is not active.', 422);
        }

        if ($amount <= 0) {
            throw new RuntimeException('Transfer amount must be positive.', 422);
        }

        $account = $this->db->findScoped('accounts', ['id' => $localAccountId]);
        if (!$account) {
            throw new RuntimeException('Source account not found.', 404);
        }

        $totalDebit = $amount + $fee;
        if ((float)$account['balance'] < $totalDebit) {
            throw new RuntimeException('Insufficient funds for transfer and fee.', 422);
        }

        $transferId = $this->generateUuid();
        $txnRef = 'NET-' . date('Ymd') . '-' . strtoupper(substr(md5($transferId), 0, 8));

        $result = $this->db->transaction(function () use (
            $transferId, $tenantId, $node, $peerNodeCode,
            $localAccountId, $senderName, $senderAccount, $senderInstitution,
            $recipientName, $recipientAccount, $recipientInstitution,
            $amount, $fee, $totalDebit, $currency, $txnRef, $userId, $account,
        ) {
            $newBalance = (float)$account['balance'] - $totalDebit;
            $this->db->execute(
                "UPDATE accounts SET balance = ?, available_balance = ?, updated_at = NOW() WHERE id = ?",
                [$newBalance, $newBalance, $localAccountId],
            );

            $txnId = $this->generateUuid();
            $this->ensureNetworkTransferType();
            $this->db->query(
                "INSERT INTO transactions
                    (id, tenant_id, account_id, type, amount, balance_after, description,
                     reference_number, status, processed_by, metadata, created_at, updated_at)
                 VALUES (?, ?, ?, 'network_transfer', ?, ?, ?, ?, 'completed', ?, ?, NOW(), NOW())",
                [
                    $txnId, $tenantId, $localAccountId, $totalDebit, $newBalance,
                    "Network transfer to {$recipientInstitution} ({$peerNodeCode})",
                    $txnRef, $userId,
                    json_encode([
                        'network_transfer_id' => $transferId,
                        'peer_node'           => $peerNodeCode,
                        'recipient_name'      => $recipientName,
                        'recipient_account'   => $recipientAccount,
                        'fee'                 => $fee,
                    ]),
                ],
            );

            $this->db->query(
                "INSERT INTO network_transfers
                    (id, tenant_id, direction, peer_node_id, peer_node_code,
                     local_account_id, local_transaction_id,
                     sender_name, sender_account, sender_institution,
                     recipient_name, recipient_account, recipient_institution,
                     amount, fee, currency, status, created_at, updated_at)
                 VALUES (?, ?, 'outbound', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())",
                [
                    $transferId, $tenantId, $node['id'], $peerNodeCode,
                    $localAccountId, $txnId,
                    $senderName, $senderAccount, $senderInstitution,
                    $recipientName, $recipientAccount, $recipientInstitution,
                    $amount, $fee, $currency,
                ],
            );

            return [
                'transfer_id'    => $transferId,
                'transaction_id' => $txnId,
                'reference'      => $txnRef,
                'amount'         => $amount,
                'fee'            => $fee,
                'total_debited'  => $totalDebit,
                'peer_node'      => $peerNodeCode,
                'status'         => 'pending',
            ];
        });

        $this->deliverToPeer($tenantId, $result['transfer_id'], $node, $result['reference'],
            $senderName, $senderAccount, $senderInstitution,
            $recipientName, $recipientAccount, $recipientInstitution,
            $amount, $currency);

        return $result;
    }

    private function deliverToPeer(
        string $tenantId, string $transferId, array $node, string $reference,
        string $senderName, string $senderAccount, string $senderInstitution,
        string $recipientName, string $recipientAccount, string $recipientInstitution,
        float $amount, string $currency,
    ): void {
        $outboundKey = $node['outbound_api_key'] ?? null;
        if (!$outboundKey) {
            $this->updateTransferStatus($tenantId, $transferId, 'pending', 'No outbound API key configured for peer.');
            return;
        }

        $endpoint = rtrim($node['endpoint_url'], '/');
        if (!str_starts_with($endpoint, 'http')) {
            $endpoint = 'https://' . $endpoint;
        }
        $url = $endpoint . '/api/v1/node/transfer';

        $payload = json_encode([
            'peer_node_code'        => $this->getOwnNodeCode($tenantId),
            'remote_reference'      => $reference,
            'sender_name'           => $senderName,
            'sender_account'        => $senderAccount,
            'sender_institution'    => $senderInstitution,
            'recipient_name'        => $recipientName,
            'recipient_account'     => $recipientAccount,
            'recipient_institution' => $recipientInstitution,
            'amount'                => $amount,
            'currency'              => $currency,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Node-Api-Key: ' . $outboundKey,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $httpCode < 200 || $httpCode >= 300) {
            $reason = $error ?: "Peer returned HTTP {$httpCode}";
            $this->updateTransferStatus($tenantId, $transferId, 'failed', $reason);
            return;
        }

        $this->updateTransferStatus($tenantId, $transferId, 'sent');
    }

    private function getOwnNodeCode(string $tenantId): string
    {
        $row = $this->db->fetchOne(
            "SELECT setting_value FROM tenant_settings WHERE tenant_id = ? AND setting_key = 'network_node_code'",
            [$tenantId],
        );
        return $row ? (json_decode($row['setting_value'], true) ?? $row['setting_value']) : 'UNKNOWN';
    }

    public function receiveInboundTransfer(
        string $tenantId,
        string $peerNodeCode,
        string $remoteReference,
        string $senderName,
        string $senderAccount,
        string $senderInstitution,
        string $recipientName,
        string $recipientAccount,
        string $recipientInstitution,
        float $amount,
        string $currency = 'USD',
    ): array {
        $this->ensureSchema();

        $account = $this->db->fetchOne(
            "SELECT * FROM accounts WHERE tenant_id = ? AND account_number = ? AND status = 'active'",
            [$tenantId, $recipientAccount],
        );

        if (!$account) {
            throw new RuntimeException('Recipient account not found or inactive.', 404);
        }

        $node = $this->findNodeByCode($tenantId, $peerNodeCode);

        $transferId = $this->generateUuid();
        $txnRef = 'NET-IN-' . date('Ymd') . '-' . strtoupper(substr(md5($transferId), 0, 8));

        return $this->db->transaction(function () use (
            $transferId, $tenantId, $node, $peerNodeCode, $remoteReference,
            $account, $senderName, $senderAccount, $senderInstitution,
            $recipientName, $recipientAccount, $recipientInstitution,
            $amount, $currency, $txnRef,
        ) {
            $newBalance = (float)$account['balance'] + $amount;
            $this->db->execute(
                "UPDATE accounts SET balance = ?, available_balance = ?, updated_at = NOW() WHERE id = ?",
                [$newBalance, $newBalance, $account['id']],
            );

            $txnId = $this->generateUuid();
            $this->ensureNetworkTransferType();
            $this->db->query(
                "INSERT INTO transactions
                    (id, tenant_id, account_id, type, amount, balance_after, description,
                     reference_number, status, metadata, created_at, updated_at)
                 VALUES (?, ?, ?, 'network_transfer', ?, ?, ?, ?, 'completed', ?, NOW(), NOW())",
                [
                    $txnId, $tenantId, $account['id'], $amount, $newBalance,
                    "Network transfer from {$senderInstitution} ({$peerNodeCode})",
                    $txnRef,
                    json_encode([
                        'network_transfer_id' => $transferId,
                        'peer_node'           => $peerNodeCode,
                        'sender_name'         => $senderName,
                        'sender_account'      => $senderAccount,
                        'remote_reference'    => $remoteReference,
                    ]),
                ],
            );

            $this->db->query(
                "INSERT INTO network_transfers
                    (id, tenant_id, direction, peer_node_id, peer_node_code,
                     local_account_id, local_transaction_id, remote_reference,
                     sender_name, sender_account, sender_institution,
                     recipient_name, recipient_account, recipient_institution,
                     amount, fee, currency, status, created_at, updated_at)
                 VALUES (?, ?, 'inbound', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 'confirmed', NOW(), NOW())",
                [
                    $transferId, $tenantId, $node['id'] ?? null, $peerNodeCode,
                    $account['id'], $txnId, $remoteReference,
                    $senderName, $senderAccount, $senderInstitution,
                    $recipientName, $recipientAccount, $recipientInstitution,
                    $amount, $currency,
                ],
            );

            return [
                'transfer_id'    => $transferId,
                'transaction_id' => $txnId,
                'reference'      => $txnRef,
                'amount'         => $amount,
                'status'         => 'confirmed',
            ];
        });
    }

    public function updateTransferStatus(string $tenantId, string $transferId, string $status, ?string $reason = null): void
    {
        $params = [$status, $transferId, $tenantId];
        $extra = '';
        if ($reason) {
            $extra = ', failure_reason = ?';
            array_splice($params, 1, 0, [$reason]);
        }

        $this->db->execute(
            "UPDATE network_transfers SET status = ?{$extra}, updated_at = NOW()
             WHERE id = ? AND tenant_id = ?",
            $params,
        );
    }

    public function listTransfers(string $tenantId, array $filters = [], int $page = 1, int $perPage = 25): array
    {
        $this->ensureSchema();

        $where = 'WHERE t.tenant_id = ?';
        $params = [$tenantId];

        if (!empty($filters['direction'])) {
            $where .= ' AND t.direction = ?';
            $params[] = $filters['direction'];
        }
        if (!empty($filters['status'])) {
            $where .= ' AND t.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['peer_node_code'])) {
            $where .= ' AND t.peer_node_code = ?';
            $params[] = $filters['peer_node_code'];
        }

        $total = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM network_transfers t {$where}",
            $params,
        );

        $offset = ($page - 1) * $perPage;
        $rows = $this->db->fetchAll(
            "SELECT t.*, n.name as peer_name
             FROM network_transfers t
             LEFT JOIN network_nodes n ON n.id = t.peer_node_id
             {$where}
             ORDER BY t.created_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params,
        );

        return ['items' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    public function getTransfer(string $tenantId, string $transferId): ?array
    {
        $this->ensureSchema();

        return $this->db->fetchOne(
            "SELECT t.*, n.name as peer_name
             FROM network_transfers t
             LEFT JOIN network_nodes n ON n.id = t.peer_node_id
             WHERE t.id = ? AND t.tenant_id = ?",
            [$transferId, $tenantId],
        );
    }

    // ------------------------------------------------------------------
    // Settlements
    // ------------------------------------------------------------------

    public function generateSettlement(
        string $tenantId,
        string $peerNodeCode,
        string $periodStart,
        string $periodEnd,
    ): array {
        $this->ensureSchema();

        $node = $this->findNodeByCode($tenantId, $peerNodeCode);
        if (!$node) {
            throw new RuntimeException('Peer node not found.', 404);
        }

        $outbound = $this->db->fetchOne(
            "SELECT COALESCE(SUM(amount), 0) as total, COUNT(*) as cnt
             FROM network_transfers
             WHERE tenant_id = ? AND peer_node_code = ? AND direction = 'outbound'
               AND status IN ('confirmed','sent','received')
               AND settled_in_batch IS NULL
               AND created_at >= ? AND created_at < ? + INTERVAL 1 DAY",
            [$tenantId, $peerNodeCode, $periodStart, $periodEnd],
        );

        $inbound = $this->db->fetchOne(
            "SELECT COALESCE(SUM(amount), 0) as total, COUNT(*) as cnt
             FROM network_transfers
             WHERE tenant_id = ? AND peer_node_code = ? AND direction = 'inbound'
               AND status IN ('confirmed','received')
               AND settled_in_batch IS NULL
               AND created_at >= ? AND created_at < ? + INTERVAL 1 DAY",
            [$tenantId, $peerNodeCode, $periodStart, $periodEnd],
        );

        $totalOut = (float)$outbound['total'];
        $totalIn = (float)$inbound['total'];
        $netAmount = $totalOut - $totalIn;
        $transferCount = (int)$outbound['cnt'] + (int)$inbound['cnt'];

        $settlementId = $this->generateUuid();

        $this->db->query(
            "INSERT INTO network_settlements
                (id, tenant_id, peer_node_id, peer_node_code, period_start, period_end,
                 total_outbound, total_inbound, net_amount, transfer_count,
                 status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', NOW(), NOW())",
            [
                $settlementId, $tenantId, $node['id'], $peerNodeCode,
                $periodStart, $periodEnd,
                $totalOut, $totalIn, $netAmount, $transferCount,
            ],
        );

        return [
            'id'             => $settlementId,
            'peer_node_code' => $peerNodeCode,
            'peer_name'      => $node['name'],
            'period_start'   => $periodStart,
            'period_end'     => $periodEnd,
            'total_outbound' => $totalOut,
            'total_inbound'  => $totalIn,
            'net_amount'     => $netAmount,
            'transfer_count' => $transferCount,
            'status'         => 'draft',
        ];
    }

    public function approveSettlement(string $tenantId, string $settlementId, string $userId): array
    {
        $settlement = $this->db->fetchOne(
            "SELECT * FROM network_settlements WHERE id = ? AND tenant_id = ?",
            [$settlementId, $tenantId],
        );

        if (!$settlement) {
            throw new RuntimeException('Settlement not found.', 404);
        }
        if ($settlement['status'] !== 'draft' && $settlement['status'] !== 'pending_approval') {
            throw new RuntimeException('Settlement cannot be approved in its current state.', 422);
        }

        $this->db->transaction(function () use ($settlement, $settlementId, $tenantId, $userId) {
            $this->db->execute(
                "UPDATE network_settlements
                 SET status = 'approved', approved_by = ?, approved_at = NOW(), updated_at = NOW()
                 WHERE id = ? AND tenant_id = ?",
                [$userId, $settlementId, $tenantId],
            );

            $this->db->execute(
                "UPDATE network_transfers
                 SET settled_in_batch = ?, status = 'settled', updated_at = NOW()
                 WHERE tenant_id = ? AND peer_node_code = ?
                   AND settled_in_batch IS NULL
                   AND status IN ('confirmed','sent','received')
                   AND created_at >= ? AND created_at < ? + INTERVAL 1 DAY",
                [
                    $settlementId, $tenantId, $settlement['peer_node_code'],
                    $settlement['period_start'], $settlement['period_end'],
                ],
            );
        });

        return $this->getSettlement($tenantId, $settlementId);
    }

    public function updateSettlementStatus(string $tenantId, string $settlementId, string $status): array
    {
        $valid = ['draft', 'pending_approval', 'approved', 'invoiced', 'paid', 'disputed'];
        if (!in_array($status, $valid, true)) {
            throw new RuntimeException('Invalid settlement status.', 422);
        }

        $this->db->execute(
            "UPDATE network_settlements SET status = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?",
            [$status, $settlementId, $tenantId],
        );

        return $this->getSettlement($tenantId, $settlementId);
    }

    public function listSettlements(string $tenantId, array $filters = []): array
    {
        $this->ensureSchema();

        $where = 'WHERE s.tenant_id = ?';
        $params = [$tenantId];

        if (!empty($filters['peer_node_code'])) {
            $where .= ' AND s.peer_node_code = ?';
            $params[] = $filters['peer_node_code'];
        }
        if (!empty($filters['status'])) {
            $where .= ' AND s.status = ?';
            $params[] = $filters['status'];
        }

        return $this->db->fetchAll(
            "SELECT s.*, n.name as peer_name
             FROM network_settlements s
             LEFT JOIN network_nodes n ON n.id = s.peer_node_id
             {$where}
             ORDER BY s.period_end DESC",
            $params,
        );
    }

    public function getSettlement(string $tenantId, string $settlementId): ?array
    {
        return $this->db->fetchOne(
            "SELECT s.*, n.name as peer_name
             FROM network_settlements s
             LEFT JOIN network_nodes n ON n.id = s.peer_node_id
             WHERE s.id = ? AND s.tenant_id = ?",
            [$settlementId, $tenantId],
        );
    }

    // ------------------------------------------------------------------
    // Node-to-Node Authentication
    // ------------------------------------------------------------------

    public function authenticateNode(string $tenantId, string $apiKey): ?array
    {
        $this->ensureSchema();

        $prefix = substr($apiKey, 0, 8);
        $nodes = $this->db->fetchAll(
            "SELECT * FROM network_nodes
             WHERE tenant_id = ? AND api_key_prefix = ? AND status = 'active'",
            [$tenantId, $prefix],
        );

        foreach ($nodes as $node) {
            if (password_verify($apiKey, $node['api_key_hash'])) {
                return $node;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Dashboard Stats
    // ------------------------------------------------------------------

    public function getNetworkStats(string $tenantId): array
    {
        $this->ensureSchema();

        $nodeCount = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM network_nodes WHERE tenant_id = ?",
            [$tenantId],
        );

        $activeNodes = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM network_nodes WHERE tenant_id = ? AND status = 'active'",
            [$tenantId],
        );

        $pendingTransfers = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM network_transfers WHERE tenant_id = ? AND status IN ('pending','sent','received')",
            [$tenantId],
        );

        $monthTotals = $this->db->fetchOne(
            "SELECT
                COALESCE(SUM(CASE WHEN direction='outbound' THEN amount ELSE 0 END), 0) as month_outbound,
                COALESCE(SUM(CASE WHEN direction='inbound' THEN amount ELSE 0 END), 0) as month_inbound,
                COUNT(*) as month_count
             FROM network_transfers
             WHERE tenant_id = ? AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')",
            [$tenantId],
        );

        $unsettled = $this->db->fetchOne(
            "SELECT COALESCE(SUM(amount), 0) as total, COUNT(*) as cnt
             FROM network_transfers
             WHERE tenant_id = ? AND settled_in_batch IS NULL AND status IN ('confirmed','sent','received')",
            [$tenantId],
        );

        return [
            'total_nodes'        => $nodeCount,
            'active_nodes'       => $activeNodes,
            'pending_transfers'  => $pendingTransfers,
            'month_outbound'     => (float)($monthTotals['month_outbound'] ?? 0),
            'month_inbound'      => (float)($monthTotals['month_inbound'] ?? 0),
            'month_count'        => (int)($monthTotals['month_count'] ?? 0),
            'unsettled_amount'   => (float)($unsettled['total'] ?? 0),
            'unsettled_count'    => (int)($unsettled['cnt'] ?? 0),
        ];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function ensureNetworkTransferType(): void
    {
        try {
            $this->db->getPdo()->exec(
                "ALTER TABLE transactions MODIFY COLUMN type
                 ENUM('deposit','withdrawal','transfer','payment','fee','late_fee',
                      'interest','adjustment','loan_disbursement','loan_payment',
                      'expense','external_transfer','network_transfer') NOT NULL"
            );
        } catch (\Throwable) {
            // ENUM already includes network_transfer
        }
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
