<?php

declare(strict_types=1);

namespace Paymenter\Extensions\Gateways\Paymos\Internal;

final class PdoDbDriver implements DbDriverInterface
{
    /** @var array<string, list<string>> */
    private const ALLOWED_TABLES = [
        PdoEventStore::TABLE => [
            'event_id',
            'owner_token',
            'state',
            'expires_at',
            'created_at',
        ],
        SnapshotStore::TABLE => [
            'external_order_id',
            'paymos_invoice_id',
            'platform_ref',
            'client_id',
            'payload_meta',
            'amount',
            'currency',
            'environment',
            'project_id',
            'payment_url',
            'transaction_id',
            'expires_at',
            'renewal_attempt',
            'final_status',
            'updated_at',
        ],
    ];

    /** @var array<string, string> */
    private const PRIMARY_KEYS = [
        PdoEventStore::TABLE => 'event_id',
        SnapshotStore::TABLE => 'external_order_id',
    ];

    private \PDO $pdo;
    private string $driver;
    private bool $schemaEnsured = false;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
        $driverName = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $this->driver = is_string($driverName) ? strtolower($driverName) : 'mysql';
    }

    public static function fromLaravel(): self
    {
        if (!class_exists(\Illuminate\Support\Facades\DB::class)) {
            throw new \RuntimeException('Laravel DB facade is not available for Paymos database storage.');
        }

        $connection = \Illuminate\Support\Facades\DB::connection();
        if (!is_object($connection) || !method_exists($connection, 'getPdo')) {
            throw new \RuntimeException('Laravel DB connection does not expose getPdo().');
        }

        $pdo = $connection->getPdo();
        if (!$pdo instanceof \PDO) {
            throw new \RuntimeException('Laravel DB connection did not return a PDO instance.');
        }

        return new self($pdo);
    }

    public function ensureSchema(): void
    {
        if ($this->schemaEnsured) {
            return;
        }

        // Read-only metadata check first: emit zero DDL statements when tables already exist
        // (prevents MySQL/MariaDB implicit commits inside active host transactions such as
        // Paymenter's ExtensionHelper::refund() DB::transaction()).
        if ($this->tablesExist()) {
            $this->schemaEnsured = true;
            return;
        }

        if ($this->pdo->inTransaction()) {
            throw new \RuntimeException(
                'Paymos database tables are not initialized and cannot be created inside an active database transaction.'
            );
        }

        if ($this->driver === 'mysql') {
            $this->execDdl(
                'CREATE TABLE IF NOT EXISTS paymos_webhook_events ('
                . 'event_id VARCHAR(128) NOT NULL PRIMARY KEY, '
                . 'owner_token VARCHAR(64) NOT NULL, '
                . 'state VARCHAR(16) NOT NULL, '
                . 'expires_at BIGINT UNSIGNED NOT NULL, '
                . 'created_at BIGINT UNSIGNED NOT NULL, '
                . 'INDEX idx_paymos_events_expires_at (expires_at)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );

            $this->execDdl(
                'CREATE TABLE IF NOT EXISTS paymos_invoice_snapshots ('
                . 'external_order_id VARCHAR(128) NOT NULL PRIMARY KEY, '
                . 'paymos_invoice_id VARCHAR(64) NOT NULL, '
                . 'platform_ref VARCHAR(128) NOT NULL, '
                . 'client_id VARCHAR(64) NULL, '
                . 'payload_meta TEXT NULL, '
                . 'amount VARCHAR(64) NOT NULL, '
                . 'currency VARCHAR(16) NOT NULL, '
                . 'environment VARCHAR(16) NOT NULL, '
                . 'project_id VARCHAR(64) NOT NULL, '
                . 'payment_url TEXT NOT NULL, '
                . 'transaction_id VARCHAR(128) NULL, '
                . 'expires_at BIGINT UNSIGNED NULL, '
                . 'renewal_attempt INT UNSIGNED NOT NULL DEFAULT 0, '
                . 'final_status VARCHAR(32) NULL, '
                . 'updated_at BIGINT UNSIGNED NOT NULL, '
                . 'INDEX idx_paymos_snapshots_invoice_id (paymos_invoice_id), '
                . 'INDEX idx_paymos_snapshots_platform_ref (platform_ref), '
                . 'INDEX idx_paymos_snapshots_transaction_id (transaction_id)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        } else {
            $this->execDdl(
                'CREATE TABLE IF NOT EXISTS paymos_webhook_events ('
                . 'event_id VARCHAR(128) NOT NULL PRIMARY KEY, '
                . 'owner_token VARCHAR(64) NOT NULL, '
                . 'state VARCHAR(16) NOT NULL, '
                . 'expires_at BIGINT NOT NULL, '
                . 'created_at BIGINT NOT NULL'
                . ')'
            );
            $this->execDdl(
                'CREATE INDEX IF NOT EXISTS idx_paymos_events_expires_at ON paymos_webhook_events (expires_at)'
            );

            $this->execDdl(
                'CREATE TABLE IF NOT EXISTS paymos_invoice_snapshots ('
                . 'external_order_id VARCHAR(128) NOT NULL PRIMARY KEY, '
                . 'paymos_invoice_id VARCHAR(64) NOT NULL, '
                . 'platform_ref VARCHAR(128) NOT NULL, '
                . 'client_id VARCHAR(64) NULL, '
                . 'payload_meta TEXT NULL, '
                . 'amount VARCHAR(64) NOT NULL, '
                . 'currency VARCHAR(16) NOT NULL, '
                . 'environment VARCHAR(16) NOT NULL, '
                . 'project_id VARCHAR(64) NOT NULL, '
                . 'payment_url TEXT NOT NULL, '
                . 'transaction_id VARCHAR(128) NULL, '
                . 'expires_at BIGINT NULL, '
                . 'renewal_attempt INTEGER NOT NULL DEFAULT 0, '
                . 'final_status VARCHAR(32) NULL, '
                . 'updated_at BIGINT NOT NULL'
                . ')'
            );
            $this->execDdl(
                'CREATE INDEX IF NOT EXISTS idx_paymos_snapshots_invoice_id ON paymos_invoice_snapshots (paymos_invoice_id)'
            );
            $this->execDdl(
                'CREATE INDEX IF NOT EXISTS idx_paymos_snapshots_platform_ref ON paymos_invoice_snapshots (platform_ref)'
            );
            $this->execDdl(
                'CREATE INDEX IF NOT EXISTS idx_paymos_snapshots_transaction_id ON paymos_invoice_snapshots (transaction_id)'
            );
        }

        $this->schemaEnsured = true;
    }

    public function insertUnique(string $table, array $row, string $primaryKey): bool
    {
        $this->assertTable($table);
        $this->assertColumn($table, $primaryKey);
        if ($row === []) {
            throw new \InvalidArgumentException('Insert row must not be empty.');
        }

        $columns = array_keys($row);
        foreach ($columns as $column) {
            $this->assertColumn($table, $column);
        }

        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

        // Conflict-safe SQL avoids raising 23505 inside active PostgreSQL transactions (which would
        // abort the transaction into state 25P02 before the subsequent CAS takeover UPDATE).
        if ($this->driver === 'mysql') {
            $sql = sprintf(
                'INSERT IGNORE INTO %s (%s) VALUES (%s)',
                $table,
                implode(', ', $columns),
                $placeholders
            );
        } else {
            $sql = sprintf(
                'INSERT INTO %s (%s) VALUES (%s) ON CONFLICT (%s) DO NOTHING',
                $table,
                implode(', ', $columns),
                $placeholders,
                $primaryKey
            );
        }

        $stmt = $this->prepare($sql);
        if ($stmt->execute(array_values($row)) === false) {
            throw new \RuntimeException('Paymos database insert failed.');
        }

        return $stmt->rowCount() === 1;
    }

    public function upsert(string $table, array $row, string $primaryKey): void
    {
        $this->assertTable($table);
        $this->assertColumn($table, $primaryKey);
        if ($row === []) {
            throw new \InvalidArgumentException('Upsert row must not be empty.');
        }

        $columns = array_keys($row);
        foreach ($columns as $column) {
            $this->assertColumn($table, $column);
        }

        $updateColumns = array_values(array_filter(
            $columns,
            static fn (string $col): bool => $col !== $primaryKey
        ));

        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

        if ($this->driver === 'mysql') {
            $assignments = array_map(
                static fn (string $col): string => $col . ' = VALUES(' . $col . ')',
                $updateColumns
            );
            $sql = sprintf(
                'INSERT INTO %s (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
                $table,
                implode(', ', $columns),
                $placeholders,
                implode(', ', $assignments)
            );
        } else {
            $assignments = array_map(
                static fn (string $col): string => $col . ' = EXCLUDED.' . $col,
                $updateColumns
            );
            $sql = sprintf(
                'INSERT INTO %s (%s) VALUES (%s) ON CONFLICT (%s) DO UPDATE SET %s',
                $table,
                implode(', ', $columns),
                $placeholders,
                $primaryKey,
                implode(', ', $assignments)
            );
        }

        $stmt = $this->prepare($sql);
        if ($stmt->execute(array_values($row)) === false) {
            throw new \RuntimeException('Paymos database upsert failed.');
        }
    }

    public function selectOne(string $table, array $where, ?string $orderBy = null): ?array
    {
        $rows = $this->selectAll($table, $where, $orderBy, 1);

        return $rows[0] ?? null;
    }

    public function selectAll(string $table, array $where, ?string $orderBy = null, ?int $limit = null): array
    {
        $this->assertTable($table);
        [$whereSql, $params] = $this->buildWhere($table, $where, [], []);
        $sql = 'SELECT * FROM ' . $table;
        if ($whereSql !== '') {
            $sql .= ' WHERE ' . $whereSql;
        }
        if ($orderBy !== null && trim($orderBy) !== '') {
            $sql .= ' ORDER BY ' . $this->sanitizeOrderBy($table, $orderBy);
        }
        if ($limit !== null) {
            if ($limit < 1) {
                return [];
            }
            $sql .= ' LIMIT ' . $limit;
        }

        $stmt = $this->prepare($sql);
        if ($stmt->execute($params) === false) {
            throw new \RuntimeException('Paymos database select failed.');
        }

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return is_array($rows) ? array_values($rows) : [];
    }

    public function updateWhere(string $table, array $values, array $where, array $lteWhere = []): int
    {
        $this->assertTable($table);
        if ($values === []) {
            throw new \InvalidArgumentException('Update values must not be empty.');
        }

        $setClauses = [];
        $params = [];
        foreach ($values as $column => $value) {
            $this->assertColumn($table, $column);
            $setClauses[] = $column . ' = ?';
            $params[] = $value;
        }

        [$whereSql, $whereParams] = $this->buildWhere($table, $where, [], $lteWhere);
        $sql = 'UPDATE ' . $table . ' SET ' . implode(', ', $setClauses);
        if ($whereSql !== '') {
            $sql .= ' WHERE ' . $whereSql;
        }

        $stmt = $this->prepare($sql);
        if ($stmt->execute(array_merge($params, $whereParams)) === false) {
            throw new \RuntimeException('Paymos database update failed.');
        }

        return $stmt->rowCount();
    }

    public function deleteWhere(
        string $table,
        array $where,
        array $ltWhere = [],
        ?int $limit = null,
        array $lteWhere = []
    ): int {
        $this->assertTable($table);
        [$whereSql, $params] = $this->buildWhere($table, $where, $ltWhere, $lteWhere);

        if ($limit !== null) {
            if ($limit < 1) {
                return 0;
            }
            if ($this->driver === 'mysql') {
                $sql = 'DELETE FROM ' . $table;
                if ($whereSql !== '') {
                    $sql .= ' WHERE ' . $whereSql;
                }
                $sql .= ' LIMIT ' . $limit;
            } else {
                $pk = self::PRIMARY_KEYS[$table];
                $subquery = 'SELECT ' . $pk . ' FROM ' . $table;
                if ($whereSql !== '') {
                    $subquery .= ' WHERE ' . $whereSql;
                }
                $subquery .= ' LIMIT ' . $limit;
                $sql = 'DELETE FROM ' . $table . ' WHERE ' . $pk . ' IN (' . $subquery . ')';
            }
        } else {
            $sql = 'DELETE FROM ' . $table;
            if ($whereSql !== '') {
                $sql .= ' WHERE ' . $whereSql;
            }
        }

        $stmt = $this->prepare($sql);
        if ($stmt->execute($params) === false) {
            throw new \RuntimeException('Paymos database delete failed.');
        }

        return $stmt->rowCount();
    }

    /**
     * @param array<string, scalar|null> $where
     * @param array<string, scalar>      $ltWhere
     * @param array<string, scalar>      $lteWhere
     * @return array{0: string, 1: list<scalar>}
     */
    private function buildWhere(string $table, array $where, array $ltWhere, array $lteWhere): array
    {
        $clauses = [];
        $params = [];

        foreach ($where as $column => $value) {
            $this->assertColumn($table, $column);
            if ($value === null) {
                $clauses[] = $column . ' IS NULL';
            } else {
                $clauses[] = $column . ' = ?';
                $params[] = $value;
            }
        }

        foreach ($ltWhere as $column => $value) {
            $this->assertColumn($table, $column);
            $clauses[] = $column . ' < ?';
            $params[] = $value;
        }

        foreach ($lteWhere as $column => $value) {
            $this->assertColumn($table, $column);
            $clauses[] = $column . ' <= ?';
            $params[] = $value;
        }

        return [implode(' AND ', $clauses), $params];
    }

    private function sanitizeOrderBy(string $table, string $orderBy): string
    {
        $parts = array_filter(array_map('trim', explode(',', $orderBy)), static fn (string $p): bool => $p !== '');
        if ($parts === []) {
            throw new \InvalidArgumentException('Invalid ORDER BY clause.');
        }

        $safe = [];
        foreach ($parts as $part) {
            $tokens = preg_split('/\s+/', $part);
            if (!is_array($tokens) || count($tokens) < 1 || count($tokens) > 2) {
                throw new \InvalidArgumentException('Invalid ORDER BY segment.');
            }
            $column = $tokens[0];
            $this->assertColumn($table, $column);
            $direction = isset($tokens[1]) ? strtoupper($tokens[1]) : 'ASC';
            if (!in_array($direction, ['ASC', 'DESC'], true)) {
                throw new \InvalidArgumentException('Invalid ORDER BY direction.');
            }
            $safe[] = $column . ' ' . $direction;
        }

        return implode(', ', $safe);
    }

    private function assertTable(string $table): void
    {
        if (!isset(self::ALLOWED_TABLES[$table])) {
            throw new \InvalidArgumentException('Unknown Paymos database table: ' . $table);
        }
    }

    private function assertColumn(string $table, string $column): void
    {
        if (!in_array($column, self::ALLOWED_TABLES[$table], true)) {
            throw new \InvalidArgumentException('Unknown Paymos database column: ' . $column);
        }
    }

    private function prepare(string $sql): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare Paymos database statement.');
        }

        return $stmt;
    }

    private function execDdl(string $sql): void
    {
        if ($this->pdo->exec($sql) === false) {
            throw new \RuntimeException('Failed to initialize Paymos database schema.');
        }
    }

    private function tablesExist(): bool
    {
        if ($this->driver === 'mysql') {
            $sql = 'SELECT COUNT(DISTINCT table_name) FROM information_schema.tables '
                . 'WHERE table_schema = DATABASE() AND table_name IN (?, ?)';
        } elseif ($this->driver === 'pgsql') {
            $sql = 'SELECT COUNT(DISTINCT table_name) FROM information_schema.tables '
                . 'WHERE table_schema = ANY(current_schemas(false)) AND table_name IN (?, ?)';
        } else {
            $sql = "SELECT COUNT(DISTINCT name) FROM sqlite_master WHERE type = 'table' AND name IN (?, ?)";
        }

        $stmt = $this->prepare($sql);
        if ($stmt->execute([PdoEventStore::TABLE, SnapshotStore::TABLE]) === false) {
            throw new \RuntimeException('Failed to inspect Paymos database schema metadata.');
        }

        return (int) $stmt->fetchColumn() === 2;
    }
}
