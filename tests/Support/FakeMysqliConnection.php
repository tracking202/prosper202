<?php

declare(strict_types=1);

namespace Tests\Support;

use mysqli;
use mysqli_result;
use mysqli_stmt;

final class FakeMysqliConnection extends mysqli
{
    /**
     * @var list<string>
     */
    public array $preparedSql = [];

    /**
     * @var list<FakeMysqliStatement>
     */
    public array $statements = [];

    public bool $beginTransactionCalled = false;
    public bool $commitCalled = false;
    public bool $rollbackCalled = false;
    public string $error = '';
    public string|int $insert_id = 0;

    /**
     * @var array<string, list<array<string, mixed>>>
     */
    private array $rowsByNeedle = [];

    /**
     * @var array<string, int>
     */
    private array $insertIdByNeedle = [];

    /**
     * @var array<string, int>
     */
    private array $affectedRowsByNeedle = [];

    /**
     * @var array<string, bool>
     */
    private array $executeReturnByNeedle = [];

    public function __construct()
    {
        // Skip parent constructor to avoid real DB usage.
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function whenQueryContainsReturnRows(string $needle, array $rows): void
    {
        $this->rowsByNeedle[$needle] = $rows;
    }

    public function whenQueryContainsInsertId(string $needle, int $insertId): void
    {
        $this->insertIdByNeedle[$needle] = $insertId;
    }

    public function whenQueryContainsAffectedRows(string $needle, int $affectedRows): void
    {
        $this->affectedRowsByNeedle[$needle] = $affectedRows;
    }

    public function whenQueryContainsExecuteReturns(string $needle, bool $ok): void
    {
        $this->executeReturnByNeedle[$needle] = $ok;
    }

    #[\ReturnTypeWillChange]
    public function prepare(string $query): mysqli_stmt|false
    {
        $this->preparedSql[] = $query;

        $stmt = new FakeMysqliStatement(
            $query,
            $this,
            $this->resolveRows($query),
            $this->resolveInsertId($query),
            $this->resolveAffectedRows($query),
            $this->resolveExecuteReturn($query),
        );

        $this->statements[] = $stmt;

        return $stmt;
    }

    public function begin_transaction(int $flags = 0, ?string $name = null): bool
    {
        $this->beginTransactionCalled = true;

        return true;
    }

    public function commit(int $flags = 0, ?string $name = null): bool
    {
        $this->commitCalled = true;

        return true;
    }

    public function rollback(int $flags = 0, ?string $name = null): bool
    {
        $this->rollbackCalled = true;

        return true;
    }

    /**
     * @return list<FakeMysqliStatement>
     */
    public function statementsContaining(string $needle): array
    {
        return array_values(array_filter(
            $this->statements,
            static fn (FakeMysqliStatement $stmt): bool => str_contains($stmt->sql, $needle),
        ));
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function resolveRows(string $query): ?array
    {
        foreach ($this->rowsByNeedle as $needle => $rows) {
            if (str_contains($query, $needle)) {
                return $rows;
            }
        }

        return null;
    }

    private function resolveInsertId(string $query): int
    {
        foreach ($this->insertIdByNeedle as $needle => $insertId) {
            if (str_contains($query, $needle)) {
                return $insertId;
            }
        }

        return 0;
    }

    private function resolveAffectedRows(string $query): int
    {
        foreach ($this->affectedRowsByNeedle as $needle => $affectedRows) {
            if (str_contains($query, $needle)) {
                return $affectedRows;
            }
        }

        return 1;
    }

    private function resolveExecuteReturn(string $query): bool
    {
        foreach ($this->executeReturnByNeedle as $needle => $ok) {
            if (str_contains($query, $needle)) {
                return $ok;
            }
        }

        return true;
    }
}

final class FakeMysqliStatement extends mysqli_stmt
{
    public string $boundTypes = '';

    /**
     * @var list<mixed>
     */
    public array $boundValues = [];

    public int $bindCount = 0;
    public int $executeCount = 0;
    public int $closeCount = 0;
    public string|int $insert_id = 0;
    public string|int $affected_rows = 0;
    public string $error = '';

    /**
     * @param list<array<string, mixed>>|null $rows
     */
    public function __construct(
        public string $sql,
        private FakeMysqliConnection $connection,
        private ?array $rows,
        private int $configuredInsertId,
        private int $configuredAffectedRows,
        private bool $executeReturn,
    ) {
        // Skip parent constructor.
    }

    public function bind_param(string $types, mixed &...$vars): bool
    {
        $this->bindCount++;
        $this->boundTypes = $types;
        $this->boundValues = [];
        foreach ($vars as &$var) {
            $this->boundValues[] = $var;
        }

        return true;
    }

    public function execute(?array $params = null): bool
    {
        $this->executeCount++;
        if (!$this->executeReturn) {
            $this->error = 'fake execute failure';
            return false;
        }

        return true;
    }

    #[\ReturnTypeWillChange]
    public function get_result(): mysqli_result|false
    {
        if ($this->rows === null) {
            return false;
        }

        return new FakeMysqliResult($this->rows);
    }

    public function close(): true
    {
        $this->closeCount++;
        return true;
    }
}

final class FakeMysqliResult extends mysqli_result
{
    /**
     * @var list<array<string, mixed>>
     */
    private array $rows;
    private int $position = 0;

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    #[\ReturnTypeWillChange]
    public function fetch_assoc(): ?array
    {
        if (!isset($this->rows[$this->position])) {
            return null;
        }

        return $this->rows[$this->position++];
    }

    public function free(): void
    {
        $this->rows = [];
    }
}
