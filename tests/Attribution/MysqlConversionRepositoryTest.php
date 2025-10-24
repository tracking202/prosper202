<?php

declare(strict_types=1);

namespace Tests\Attribution;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\Repository\Mysql\ConversionHydrator;
use Prosper202\Attribution\Repository\Mysql\ConversionJourneyRepository;
use Prosper202\Attribution\Repository\Mysql\MysqlConversionRepository;

final class MysqlConversionRepositoryTest extends TestCase
{
    public function testFetchForUserHydratesJourneysFromTouchpointTable(): void
    {
        $conversionRows = [[
            'conv_id' => 100,
            'click_id' => 5001,
            'user_id' => 17,
            'campaign_id' => 34,
            'ppc_account_id' => 9,
            'conv_time' => 1702000000,
            'click_time' => 1701999900,
            'click_payout' => 21.5,
            'click_cpc' => 3.75,
        ]];

        $journeyRows = [
            ['conv_id' => 100, 'click_id' => 4990, 'click_time' => 1701998000, 'position' => 0],
            ['conv_id' => 100, 'click_id' => 5001, 'click_time' => 1701999900, 'position' => 1],
        ];

        $connection = new FakeMysqli($conversionRows, $journeyRows);
        $repository = new MysqlConversionRepository(
            $connection,
            new ConversionJourneyRepository($connection),
            new ConversionHydrator()
        );

        $batch = $repository->fetchForUser(17, 1701990000, 1702010000);
        $this->assertSame(17, $batch->userId);
        $this->assertCount(1, $batch->conversions);

        $journey = $batch->conversions[0]->getJourney();
        $this->assertCount(2, $journey);
        $this->assertSame(4990, $journey[0]->clickId);
        $this->assertSame(5001, $journey[1]->clickId);
    }
}

/**
 * Lightweight in-memory mysqli replacement used for repository hydration tests.
 */
final class FakeMysqli extends \mysqli
{
    private array $conversionRows;
    private array $journeyRows;
    public string $error = '';

    public function __construct(array $conversionRows, array $journeyRows)
    {
        $this->conversionRows = array_values($conversionRows);
        $this->journeyRows = array_values($journeyRows);
    }

    #[\ReturnTypeWillChange]
    public function prepare(string $query): FakeMysqliStatement|false
    {
        if (str_contains($query, 'FROM 202_conversion_logs')) {
            return new FakeMysqliStatement($this->conversionRows);
        }

        $this->error = 'Unsupported query: ' . $query;
        return false;
    }

    #[\ReturnTypeWillChange]
    public function query(string $query, int $resultMode = MYSQLI_STORE_RESULT): FakeMysqliResult|false
    {
        if (preg_match('/WHERE conv_id IN \(([^\)]*)\)/', $query, $matches) === 1) {
            $ids = array_filter(array_map('intval', explode(',', $matches[1])));
            $filtered = array_values(array_filter(
                $this->journeyRows,
                static fn (array $row): bool => in_array((int) $row['conv_id'], $ids, true)
            ));

            return new FakeMysqliResult($filtered);
        }

        $this->error = 'Unsupported query: ' . $query;
        return false;
    }
}

final class FakeMysqliStatement
{
    /** @var array<int, array<string, mixed>> */
    private array $rows;
    private array $params = [];
    private ?FakeMysqliResult $result = null;

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    public function bind_param(string $types, &...$vars): bool
    {
        $this->params = &$vars;
        return true;
    }

    public function execute(): bool
    {
        $userId = (int) ($this->params[0] ?? 0);
        $start = (int) ($this->params[1] ?? 0);
        $end = (int) ($this->params[2] ?? PHP_INT_MAX);
        $afterConvId = (int) ($this->params[3] ?? 0);
        $limit = (int) ($this->params[4] ?? PHP_INT_MAX);

        $filtered = [];
        foreach ($this->rows as $row) {
            if ((int) $row['conv_id'] <= $afterConvId) {
                continue;
            }

            if ((int) $row['conv_time'] < $start || (int) $row['conv_time'] > $end) {
                continue;
            }

            if ($userId !== 0 && (int) $row['user_id'] !== $userId) {
                continue;
            }

            $filtered[] = $row;
            if (count($filtered) >= $limit) {
                break;
            }
        }

        $this->result = new FakeMysqliResult($filtered);
        return true;
    }

    public function get_result(): ?FakeMysqliResult
    {
        return $this->result;
    }

    public function close(): void
    {
        $this->params = [];
        $this->result = null;
    }
}

final class FakeMysqliResult
{
    /** @var array<int, array<string, mixed>> */
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
