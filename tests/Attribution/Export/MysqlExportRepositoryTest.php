<?php

declare(strict_types=1);

namespace Tests\Attribution\Export;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\Export\ExportFormat;
use Prosper202\Attribution\Export\ExportJob;
use Prosper202\Attribution\Export\ExportStatus;
use Prosper202\Attribution\Repository\Mysql\MysqlExportRepository;
use Prosper202\Attribution\ScopeType;

final class MysqlExportRepositoryTest extends TestCase
{
    public function testCreateAndFindForUserPersistsJob(): void
    {
        $connection = new FakeMysqli();
        $repository = new MysqlExportRepository($connection);

        $job = $this->createJob();
        $saved = $repository->create($job);

        $this->assertNotNull($saved->exportId);
        $this->assertSame(1, $saved->exportId);

        $fetched = $repository->findForUser(1, null, 10);
        $this->assertCount(1, $fetched);
        $this->assertSame('pending', $fetched[0]->status->value);
        $this->assertSame('csv', $fetched[0]->format->value);

        $byId = $repository->findById((int) $saved->exportId);
        $this->assertNotNull($byId);
        $this->assertSame($saved->downloadToken, $byId->downloadToken);
    }

    public function testClaimPendingMarksJobsProcessing(): void
    {
        $connection = new FakeMysqli();
        $repository = new MysqlExportRepository($connection);

        $repository->create($this->createJob());
        $repository->create($this->createJob(scopeId: 55));

        $claimed = $repository->claimPending(1);
        $this->assertCount(1, $claimed);
        $this->assertSame('processing', $claimed[0]->status->value);
        $this->assertNotNull($claimed[0]->lastAttemptedAt);

        $all = $repository->findForUser(1, null, 10);
        $statuses = array_map(static fn (ExportJob $job): string => $job->status->value, $all);
        $this->assertContains('processing', $statuses);
    }

    private function createJob(?int $scopeId = null): ExportJob
    {
        $timestamp = time();

        return new ExportJob(
            exportId: null,
            userId: 1,
            modelId: 1,
            scopeType: ScopeType::GLOBAL,
            scopeId: $scopeId,
            startHour: $timestamp - 7200,
            endHour: $timestamp,
            format: ExportFormat::CSV,
            status: ExportStatus::PENDING,
            filePath: null,
            downloadToken: bin2hex(random_bytes(4)),
            webhookUrl: null,
            webhookMethod: 'POST',
            webhookHeaders: ['X-Test' => 'yes'],
            webhookStatusCode: null,
            webhookResponseBody: null,
            lastAttemptedAt: null,
            completedAt: null,
            errorMessage: null,
            createdAt: $timestamp,
            updatedAt: $timestamp,
        );
    }
}

final class FakeMysqli extends \mysqli
{
    /**
     * @var array<int, array<string, mixed>>
     */
    public array $jobs = [];

    public int $lastInsertId = 0;
    public string $error = '';

    public function __construct()
    {
        // Skip parent constructor to avoid real connections.
    }

    public function prepare(string $query): FakeStatement
    {
        return new FakeStatement($this, $query);
    }
}

final class FakeStatement
{
    private FakeMysqli $connection;
    private string $query;
    private string $types = '';
    /**
     * @var array<int, mixed>
     */
    private array $bound = [];
    private ?FakeResult $result = null;

    public int $insert_id = 0;
    public int $affected_rows = 0;
    public string $error = '';

    public function __construct(FakeMysqli $connection, string $query)
    {
        $this->connection = $connection;
        $this->query = $query;
    }

    public function bind_param(string $types, &...$vars): bool
    {
        $this->types = $types;
        $this->bound = [];
        foreach ($vars as &$var) {
            $this->bound[] =& $var;
        }

        return true;
    }

    public function execute(): bool
    {
        $sql = $this->query;

        if (str_starts_with($sql, 'INSERT INTO 202_attribution_exports')) {
            $this->performInsert();
            return true;
        }

        if (str_starts_with($sql, 'UPDATE 202_attribution_exports SET status')) {
            $this->performUpdate();
            return true;
        }

        if (str_starts_with($sql, 'SELECT * FROM 202_attribution_exports WHERE export_id')) {
            $this->performFindById();
            return true;
        }

        if (str_starts_with($sql, 'SELECT * FROM 202_attribution_exports WHERE user_id')) {
            $this->performFindForUser();
            return true;
        }

        if (str_starts_with($sql, 'SELECT * FROM 202_attribution_exports WHERE status')) {
            $this->performSelectPending();
            return true;
        }

        $this->error = 'Unsupported query: ' . $sql;
        return false;
    }

    public function get_result(): ?FakeResult
    {
        return $this->result;
    }

    public function close(): bool
    {
        $this->result = null;
        return true;
    }

    private function performInsert(): void
    {
        $columns = [
            'user_id',
            'model_id',
            'scope_type',
            'scope_id',
            'start_hour',
            'end_hour',
            'format',
            'status',
            'file_path',
            'download_token',
            'webhook_url',
            'webhook_method',
            'webhook_headers',
            'webhook_status_code',
            'webhook_response_body',
            'last_attempted_at',
            'completed_at',
            'error_message',
            'created_at',
            'updated_at',
        ];

        $row = [];
        foreach ($columns as $index => $column) {
            $row[$column] = $this->bound[$index] ?? null;
        }

        $row['export_id'] = ++$this->connection->lastInsertId;
        if ($row['webhook_headers'] !== null && $row['webhook_headers'] !== '') {
            $decoded = json_decode((string) $row['webhook_headers'], true);
            $row['webhook_headers'] = json_encode($decoded, JSON_THROW_ON_ERROR);
        }

        $this->connection->jobs[$row['export_id']] = $row;
        $this->insert_id = $row['export_id'];
        $this->affected_rows = 1;
    }

    private function performUpdate(): void
    {
        $values = $this->bound;
        $exportId = (int) ($values[12] ?? 0);
        if (!isset($this->connection->jobs[$exportId])) {
            $this->affected_rows = 0;
            return;
        }

        $row =& $this->connection->jobs[$exportId];
        $row['status'] = $values[0];
        $row['file_path'] = $values[1];
        $row['download_token'] = $values[2];
        $row['webhook_url'] = $values[3];
        $row['webhook_method'] = $values[4];
        $row['webhook_headers'] = $values[5];
        $row['webhook_status_code'] = $values[6];
        $row['webhook_response_body'] = $values[7];
        $row['last_attempted_at'] = $values[8];
        $row['completed_at'] = $values[9];
        $row['error_message'] = $values[10];
        $row['updated_at'] = $values[11];

        $this->affected_rows = 1;
    }

    private function performFindById(): void
    {
        $exportId = (int) ($this->bound[0] ?? 0);
        $row = $this->connection->jobs[$exportId] ?? null;
        $this->result = new FakeResult($row !== null ? [$row] : []);
    }

    private function performFindForUser(): void
    {
        $userId = (int) ($this->bound[0] ?? 0);
        $limit = (int) end($this->bound);
        $modelId = null;
        if (count($this->bound) === 3) {
            $modelId = (int) ($this->bound[1] ?? null);
        }

        $rows = array_filter(
            $this->connection->jobs,
            static function (array $row) use ($userId, $modelId): bool {
                if ((int) $row['user_id'] !== $userId) {
                    return false;
                }
                if ($modelId !== null && (int) $row['model_id'] !== $modelId) {
                    return false;
                }

                return true;
            }
        );

        usort($rows, static fn (array $a, array $b): int => (int) $b['created_at'] <=> (int) $a['created_at']);
        $rows = array_slice($rows, 0, $limit);

        $this->result = new FakeResult($rows);
    }

    private function performSelectPending(): void
    {
        $status = (string) ($this->bound[0] ?? 'pending');
        $limit = (int) ($this->bound[1] ?? 1);

        $rows = array_filter(
            $this->connection->jobs,
            static fn (array $row): bool => $row['status'] === $status
        );

        usort($rows, static fn (array $a, array $b): int => (int) $a['created_at'] <=> (int) $b['created_at']);
        $rows = array_slice($rows, 0, $limit);

        $this->result = new FakeResult($rows);
    }
}

final class FakeResult
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private array $rows;
    private int $position = 0;

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function __construct(array $rows)
    {
        $this->rows = array_values($rows);
    }

    public function fetch_assoc(): ?array
    {
        return $this->rows[$this->position++] ?? null;
    }
}
