<?php

declare(strict_types=1);

namespace Tests\Attribution;

use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\ExportStatus;
use Prosper202\Attribution\ExportStore;
use Tests\Attribution\Support\AttributionDatabase;

/**
 * An export job moves only as ExportStatus says, each move a conditional
 * UPDATE in ExportStore that names the state it leaves: a move from any
 * other state changes nothing and says so.
 *
 * @group integration
 */
final class ExportStatusIntegrationTest extends TestCase
{
    use AttributionDatabase;

    private function store(): ExportStore
    {
        return new ExportStore($this->conn);
    }

    private function job(int $queuedAt = 1000): int
    {
        return $this->store()->insert(1, $this->defaultModelId(), null, 'campaign', 0, 10, null, null, $queuedAt);
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        return $this->store()->rowById($id) ?? throw new \RuntimeException('no export ' . $id);
    }

    public function testAStaleRunThatIsOutOfAttemptsEndsAsAFailedRunDoes(): void
    {
        $id = $this->job();
        self::assertTrue($this->store()->claim($id, 2000));

        self::assertSame(1, $this->store()->reclaimStale(9000, 600, 1));

        $row = $this->row($id);
        self::assertSame('failed', $row['status']);
        self::assertSame(9000, (int) $row['completed_at'], 'a failed export says when it ended, however it failed');
    }

    public function testAStaleRunWithAttemptsLeftGoesBackToPendingWithNoEnd(): void
    {
        $id = $this->job();
        self::assertTrue($this->store()->claim($id, 2000));

        self::assertSame(1, $this->store()->reclaimStale(9000, 600, 3));

        $row = $this->row($id);
        self::assertSame(['pending', null, 9000], [$row['status'], $row['completed_at'], (int) $row['queued_at']]);
    }

    public function testAMoveFromAnyOtherStateChangesNothingAndSaysSo(): void
    {
        $store = $this->store();
        $id = $this->job();
        $ended = static fn (): array => [
            $store->complete($id, 200, 3000), $store->fail($id, 'no', null, 3000), $store->deferRetry($id, 'later', null, 4000, 3000),
        ];
        $held = fn (string ...$columns): array => array_map(fn (string $c): mixed => $this->row($id)[$c], $columns);

        self::assertSame([false, false, false], $ended(), 'a job nobody holds is not ended');
        self::assertFalse($store->retry(1, $id), 'only a failed job is queued again');
        self::assertFalse($store->claim($id, 999), 'not due yet');
        self::assertSame(['pending', 0, null], $held('status', 'attempts', 'last_error'));

        self::assertTrue($store->claim($id, 2000));
        self::assertFalse($store->claim($id, 2000), 'taken once');
        self::assertFalse($store->retry(1, $id));
        self::assertFalse($store->delete(1, $id), 'a running job is not deleted');
        self::assertTrue($store->fail($id, 'the webhook refused it', 410, 2500));
        self::assertSame([false, false, false], $ended(), 'ended once');
        self::assertFalse($store->claim($id, 5000), 'a failed job waits for a person');
        self::assertSame(['failed', 2500, 'the webhook refused it'], $held('status', 'completed_at', 'last_error'));

        self::assertFalse($store->retry(2, $id), 'not another account\'s');
        self::assertTrue($store->retry(1, $id));
        self::assertSame(['pending', 0, null, null], $held('status', 'attempts', 'started_at', 'completed_at'));

        self::assertTrue($store->claim($id, time() + 5));
        self::assertTrue($store->complete($id, null, 6000));
        self::assertSame([false, false, false], $ended(), 'a completed job is over');
        self::assertFalse($store->claim($id, time() + 10));
        self::assertFalse($store->retry(1, $id));
        self::assertSame(0, $store->reclaimStale(time() + 99999, 1, 1), 'and is not reclaimed');
        self::assertSame(['completed', 6000], $held('status', 'completed_at'));
        self::assertTrue($store->delete(1, $id));
    }

    public function testTheListFiltersByState(): void
    {
        $pending = $this->job();
        $running = $this->job();
        $this->store()->claim($running, 2000);

        self::assertSame([$running], array_map('intval', array_column($this->store()->rows(1, ExportStatus::Running, 10), 'export_id')));
        self::assertSame([$running, $pending], array_map('intval', array_column($this->store()->rows(1, null, 10), 'export_id')));
    }
}
