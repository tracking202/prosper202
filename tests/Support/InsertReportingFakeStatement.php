<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Minimal statement double that is NOT a mysqli_stmt subclass, so its public
 * insert_id property is freely writable.
 */
final class InsertReportingFakeStatement
{
    public string $boundTypes = '';

    /**
     * @var list<mixed>
     */
    public array $boundValues = [];

    public int $insert_id = 0;
    public int $affected_rows = 0;
    public string $error = '';

    /**
     * @param list<array<string, mixed>>|null $rows
     */
    public function __construct(
        public string $sql,
        private int $insertIdToReport,
        private ?array $rows,
    ) {
    }

    public function bind_param(string $types, mixed &...$vars): bool
    {
        $this->boundTypes = $types;
        $this->boundValues = [];
        foreach ($vars as &$var) {
            $this->boundValues[] = $var;
        }

        return true;
    }

    public function execute(?array $params = null): bool
    {
        if (str_contains($this->sql, 'INSERT INTO 202_conversion_logs')) {
            $this->insert_id = $this->insertIdToReport;
        }
        $this->affected_rows = 1;

        return true;
    }

    #[\ReturnTypeWillChange]
    public function get_result(): \mysqli_result|false
    {
        // An unconfigured SELECT is an empty result set, as on a real server;
        // false would now be read by Connection as a fetch failure.
        return new InsertReportingFakeResult($this->rows ?? []);
    }

    public function close(): bool
    {
        return true;
    }
}

/**
 * Result double extending mysqli_result so Connection's `instanceof mysqli_result`
 * guard in fetchOne()/fetchAll() reads the rows.
 */
final class InsertReportingFakeResult extends \mysqli_result
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
        // Skip parent constructor — no real result set backs this fake.
        $this->rows = array_values($rows);
    }

    #[\ReturnTypeWillChange]
    public function fetch_assoc(): ?array
    {
        return $this->rows[$this->position++] ?? null;
    }

    #[\ReturnTypeWillChange]
    public function free(): void
    {
        $this->rows = [];
    }
}
