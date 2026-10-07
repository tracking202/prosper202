<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\AffNetworksController;
use Api\V3\Controllers\ConversionsController;
use Api\V3\Controllers\RotatorsController;
use Api\V3\Controllers\SyncController;
use Api\V3\Controllers\SystemController;
use Api\V3\Exception\ValidationException;
use Api\V3\Support\ServerStateStore;
use Api\V3\Support\SyncEngine;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeMysqliConnection;

/**
 * The whole-number query parameters of the list reads outside reports,
 * clicks and LTV: every CRUD list (the base Controller), conversions,
 * rotators, the sync change feed and audit log, and the error log.
 *
 * Each was `max(1, min(500, (int) …))` or a bare `(int)`: `limit=abc` read
 * as one row, `limit=1000` as 500 that looked like all of them,
 * `updated_since=yesterday` as 0 (every row "changed since 1970"), and
 * `from_epoch=2026-10-01` as 2026 seconds after the epoch. Each is now a 422
 * naming the parameter and its range (QueryInt), and nothing is read.
 */
final class StrictIntegerQueryParamsTest extends TestCase
{
    private const NOT_WHOLE = ['abc', '-1', '1.5', '1e2', ' 5', '5 ', '+5', '2026-10-01', '12abc', ['5'], true];

    /** @var list<string> */
    private array $scratch = [];

    protected function tearDown(): void
    {
        foreach ($this->scratch as $dir) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
        parent::tearDown();
    }

    /** @return iterable<string, array{string, string, list<mixed>}> read, parameter, refused values beyond NOT_WHOLE */
    public static function parameters(): iterable
    {
        foreach (['crud', 'conversions', 'rotators'] as $read) {
            yield "$read limit" => [$read, 'limit', ['0', '501']];
            yield "$read offset" => [$read, 'offset', []];
        }
        yield 'crud cursor_ttl' => ['crud', 'cursor_ttl', ['59', '86401']];
        yield 'crud updated_since' => ['crud', 'updated_since', []];
        yield 'crud deleted_since' => ['crud', 'deleted_since', []];
        yield 'sync changes limit' => ['changes', 'limit', ['0', '1001']];
        yield 'sync changes cursor_ttl' => ['changes', 'cursor_ttl', ['59', '86401']];
        yield 'sync changes updated_since' => ['changes', 'updated_since', []];
        yield 'sync changes deleted_since' => ['changes', 'deleted_since', []];
        yield 'sync audit from_epoch' => ['audit', 'from_epoch', []];
        yield 'sync audit to_epoch' => ['audit', 'to_epoch', []];
        yield 'system errors limit' => ['errors', 'limit', ['0', '101']];
    }

    /**
     * @dataProvider parameters
     * @param list<mixed> $outOfRange
     */
    public function testAValueThatIsNotAWholeNumberInRangeIsRefusedNamingIt(string $read, string $param, array $outOfRange): void
    {
        foreach ([...self::NOT_WHOLE, ...$outOfRange] as $value) {
            $conn = new FakeMysqliConnection();
            $shown = var_export($value, true);
            try {
                $this->read($read, $conn, [$param => $value]);
                self::fail("$read($param=$shown) answered instead of refusing");
            } catch (ValidationException $e) {
                self::assertSame([$param], array_keys($e->getFieldErrors()), "$read($param=$shown): the 422 names $param");
                self::assertStringStartsWith('A whole number, ', $e->getFieldErrors()[$param]);
            }
            self::assertSame([], $conn->preparedSql, "$read($param=$shown): nothing was read");
        }
    }

    /** The bounds are inclusive, and absent or '' is no value. */
    public function testTheBoundsAreAcceptedAndAbsentIsTheDefault(): void
    {
        foreach ([[], ['limit' => ''], ['limit' => '1'], ['limit' => '500'], ['offset' => '0'], ['cursor_ttl' => '60'], ['cursor_ttl' => '86400'], ['updated_since' => '0']] as $params) {
            $conn = self::listing();
            $this->read('crud', $conn, $params);
            self::assertNotSame([], $conn->preparedSql, 'crud(' . json_encode($params) . ') read the list');
        }
        $conn = self::listing();
        $this->read('crud', $conn, ['limit' => '7', 'offset' => '3']);
        $rows = $conn->statementsContaining('LIMIT');
        self::assertNotSame([], $rows);
        self::assertSame([7, 3], array_slice(end($rows)->boundValues, -2), 'the limit and offset asked for are the ones bound');
    }

    /** A connection whose COUNT(*) answers, as a real one always does. */
    private static function listing(): FakeMysqliConnection
    {
        $conn = new FakeMysqliConnection();
        $conn->whenQueryContainsReturnRows('COUNT(*)', [['total' => 0]]);

        return $conn;
    }

    /** @param array<string, mixed> $params */
    private function read(string $read, FakeMysqliConnection $conn, array $params): void
    {
        match ($read) {
            'crud' => (new AffNetworksController($conn, 7))->list($params),
            'conversions' => (new ConversionsController($conn, 7))->list($params),
            'rotators' => (new RotatorsController($conn, 7))->list($params),
            'changes' => $this->sync($conn)->listChanges(SyncEngine::supportedEntities()[0], $params),
            'audit' => $this->sync($conn)->auditList($params),
            'errors' => (new SystemController($conn))->errors($params),
        };
    }

    private function sync(FakeMysqliConnection $conn): SyncController
    {
        $dir = sys_get_temp_dir() . '/p202-int-params-' . bin2hex(random_bytes(6));
        $this->scratch[] = $dir;

        return new SyncController($conn, 7, new ServerStateStore($dir, 'strict-int-params-test'));
    }
}
