<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Write-connection fake whose INSERT statement reports a real insert_id.
 *
 * The shared {@see FakeMysqliConnection} cannot do this on PHP 8.4+: it (and its
 * FakeMysqliStatement) subclass the native mysqli classes, whose insert_id is a
 * read-only virtual property that cannot be assigned from anywhere (verified:
 * direct, internal, and reflection writes all throw). FakeMysqliConnection is
 * also `final`, so it cannot be subclassed. This standalone double returns a
 * plain statement object (not a mysqli_stmt) for the conversion-log INSERT, whose
 * public insert_id is freely writable; Connection accepts it because every
 * statement parameter is relaxed to `object`.
 */
final class InsertReportingFakeMysqliConnection extends \mysqli
{
    /**
     * @var list<InsertReportingFakeStatement>
     */
    public array $statements = [];

    public bool $beginTransactionCalled = false;
    public bool $commitCalled = false;
    public bool $rollbackCalled = false;
    public string $error = '';

    /**
     * @var array<string, list<array<string, mixed>>>
     */
    private array $rowsByNeedle = [];

    public function __construct(private int $insertIdToReport)
    {
        // Skip parent constructor to avoid a real DB connection.
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function whenQueryContainsReturnRows(string $needle, array $rows): void
    {
        $this->rowsByNeedle[$needle] = $rows;
    }

    #[\ReturnTypeWillChange]
    public function prepare(string $query): InsertReportingFakeStatement
    {
        $stmt = new InsertReportingFakeStatement(
            $query,
            $this->insertIdToReport,
            $this->resolveRows($query),
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
     * @return list<InsertReportingFakeStatement>
     */
    public function statementsContaining(string $needle): array
    {
        return array_values(array_filter(
            $this->statements,
            static fn (InsertReportingFakeStatement $stmt): bool => str_contains($stmt->sql, $needle),
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
}
