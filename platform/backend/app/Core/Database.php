<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;
use RuntimeException;

/**
 * Database connection singleton with tenant-scoped query support.
 *
 * Wraps PDO and provides prepared-statement helpers. Every query method
 * automatically injects the current tenant_id when tenant context is set.
 */
final class Database
{
    private static ?self $instance = null;
    private PDO $pdo;
    private ?string $tenantId = null;
    private int $transactionDepth = 0;

    private function __construct()
    {
        $host     = env('DB_HOST', '127.0.0.1');
        $port     = env('DB_PORT', '3306');
        $database = env('DB_DATABASE', 'synccu');
        $username = env('DB_USERNAME', 'synccu_user');
        $password = env('DB_PASSWORD', '');
        $charset  = env('DB_CHARSET', 'utf8mb4');
        $driver   = env('DB_DRIVER', 'mysql');

        $dsn = match ($driver) {
            'pgsql' => "pgsql:host={$host};port={$port};dbname={$database}",
            default => "mysql:host={$host};port={$port};dbname={$database};charset={$charset}",
        };

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        $this->pdo = new PDO($dsn, $username, $password, $options);
    }

    /** Prevent cloning. */
    private function __clone(): void {}

    /**
     * Retrieve the singleton instance.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get the underlying PDO connection.
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    // ------------------------------------------------------------------
    // Tenant Scoping
    // ------------------------------------------------------------------

    public function setTenantId(?string $tenantId): void
    {
        $this->tenantId = $tenantId;
    }

    public function getTenantId(): ?string
    {
        return $this->tenantId;
    }

    // ------------------------------------------------------------------
    // Query Helpers
    // ------------------------------------------------------------------

    /**
     * Execute a raw prepared statement.
     */
    public function prepare(string $sql): PDOStatement
    {
        return $this->pdo->prepare($sql);
    }

    /**
     * Execute a query with parameter binding and return the statement.
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Fetch all rows for a query.
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /**
     * Fetch a single row.
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row ?: null;
    }

    /**
     * Fetch a single scalar value.
     */
    public function fetchColumn(string $sql, array $params = []): mixed
    {
        return $this->query($sql, $params)->fetchColumn();
    }

    /**
     * Execute a statement and return affected row count.
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->query($sql, $params)->rowCount();
    }

    /**
     * Return the last inserted ID.
     */
    public function lastInsertId(): string
    {
        return $this->pdo->lastInsertId();
    }

    // ------------------------------------------------------------------
    // Tenant-Scoped Convenience Methods
    // ------------------------------------------------------------------

    /**
     * Select rows from a table, automatically scoped to the current tenant.
     */
    public function selectScoped(
        string $table,
        array  $conditions = [],
        string $orderBy = '',
        int    $limit = 0,
        int    $offset = 0,
    ): array {
        $conditions = $this->injectTenant($conditions);

        [$where, $params] = $this->buildWhere($conditions);
        $sql = "SELECT * FROM {$table}{$where}";

        if ($orderBy !== '') {
            $sql .= " ORDER BY {$orderBy}";
        }
        if ($limit > 0) {
            $sql .= " LIMIT {$limit}";
            if ($offset > 0) {
                $sql .= " OFFSET {$offset}";
            }
        }

        return $this->fetchAll($sql, $params);
    }

    /**
     * Fetch one row from a table, scoped to the current tenant.
     */
    public function findScoped(string $table, array $conditions): ?array
    {
        $conditions = $this->injectTenant($conditions);
        [$where, $params] = $this->buildWhere($conditions);
        return $this->fetchOne("SELECT * FROM {$table}{$where} LIMIT 1", $params);
    }

    /**
     * Insert a row, automatically adding tenant_id.
     */
    public function insertScoped(string $table, array $data): string
    {
        $data = $this->injectTenant($data);

        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $this->query(
            "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})",
            array_values($data),
        );

        return $this->lastInsertId();
    }

    /**
     * Update rows, scoped to the current tenant.
     */
    public function updateScoped(string $table, array $data, array $conditions): int
    {
        $conditions = $this->injectTenant($conditions);

        $set = implode(', ', array_map(fn(string $col) => "{$col} = ?", array_keys($data)));
        [$where, $whereParams] = $this->buildWhere($conditions);

        return $this->execute(
            "UPDATE {$table} SET {$set}{$where}",
            [...array_values($data), ...$whereParams],
        );
    }

    /**
     * Delete rows, scoped to the current tenant.
     */
    public function deleteScoped(string $table, array $conditions): int
    {
        $conditions = $this->injectTenant($conditions);
        [$where, $params] = $this->buildWhere($conditions);
        return $this->execute("DELETE FROM {$table}{$where}", $params);
    }

    /**
     * Count rows, scoped to the current tenant.
     */
    public function countScoped(string $table, array $conditions = []): int
    {
        $conditions = $this->injectTenant($conditions);
        [$where, $params] = $this->buildWhere($conditions);
        return (int) $this->fetchColumn("SELECT COUNT(*) FROM {$table}{$where}", $params);
    }

    // ------------------------------------------------------------------
    // Transactions
    // ------------------------------------------------------------------

    public function beginTransaction(): bool
    {
        if ($this->transactionDepth === 0) {
            $this->transactionDepth++;
            return $this->pdo->beginTransaction();
        }
        $this->pdo->exec('SAVEPOINT sp_' . $this->transactionDepth);
        $this->transactionDepth++;
        return true;
    }

    public function commit(): bool
    {
        $this->transactionDepth--;
        if ($this->transactionDepth === 0) {
            return $this->pdo->commit();
        }
        $this->pdo->exec('RELEASE SAVEPOINT sp_' . $this->transactionDepth);
        return true;
    }

    public function rollBack(): bool
    {
        $this->transactionDepth--;
        if ($this->transactionDepth === 0) {
            return $this->pdo->rollBack();
        }
        $this->pdo->exec('ROLLBACK TO SAVEPOINT sp_' . $this->transactionDepth);
        return true;
    }

    /**
     * Execute a callback inside a database transaction.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();
        try {
            $result = $callback();
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->rollBack();
            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // Internal Helpers
    // ------------------------------------------------------------------

    /**
     * Inject tenant_id into a conditions/data array when tenant context is set.
     */
    private function injectTenant(array $data): array
    {
        if ($this->tenantId !== null && !array_key_exists('tenant_id', $data)) {
            $data['tenant_id'] = $this->tenantId;
        }
        return $data;
    }

    /**
     * Build a WHERE clause and parameter array from key-value conditions.
     *
     * @return array{string, array}
     */
    private function buildWhere(array $conditions): array
    {
        if (empty($conditions)) {
            return ['', []];
        }

        $parts  = [];
        $params = [];

        foreach ($conditions as $col => $value) {
            if ($value === null) {
                $parts[] = "{$col} IS NULL";
            } else {
                $parts[] = "{$col} = ?";
                $params[] = $value;
            }
        }

        return [' WHERE ' . implode(' AND ', $parts), $params];
    }
}
