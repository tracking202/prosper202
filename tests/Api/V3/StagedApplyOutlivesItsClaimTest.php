<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Auth;
use Api\V3\Controllers\StagedChangesController;
use Api\V3\Exception\ConflictException;
use Api\V3\Exception\ValidationException;
use Api\V3\Exception\WriteCommittedException;
use Api\V3\Support\ServerStateStore;
use Api\V3\Support\StagedChangeStatus;
use Tests\TestCase;

/**
 * An apply whose dispatch runs past STALE_APPLYING_SECONDS is closed by the
 * next caller as apply_interrupted. What the first process writes when it
 * finally returns is decided by the record as it then is (CLAUDE.md #13):
 * the window is widened by ageing the claim from inside the dispatch.
 */
final class StagedApplyOutlivesItsClaimTest extends TestCase
{
    private string $tmpDir;
    private ServerStateStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/p202-staged-late-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0700, true);
        $this->store = new ServerStateStore($this->tmpDir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmpDir));
        parent::tearDown();
    }

    private function auth(): Auth
    {
        $db = $this->createMysqliMock([
            "SHOW COLUMNS FROM 202_api_keys LIKE 'scope'" => ['Field' => 'scope'],
            '202_api_keys' => ['user_id' => 5, 'user_active' => 1, 'scope' => '*'],
            '202_user_role' => [['role_name' => 'user']],
        ]);

        return Auth::fromRequest(['Authorization' => 'Bearer key'], $db);
    }

    private function controller(): StagedChangesController
    {
        return new StagedChangesController($this->store, $this->auth());
    }

    private function stage(): string
    {
        return $this->controller()->stage('POST', '/campaigns', ['a' => 1], null)['data']['change_id'];
    }

    /** What happens while the first dispatch is still running: its claim goes stale and another caller closes it. */
    private function anotherCallerClosesTheStaleClaim(string $id): void
    {
        $this->store->updateStagedChange(5, $id, static function (array $c): array {
            $c['applying_since'] = time() - (StagedChangesController::STALE_APPLYING_SECONDS + 60);

            return $c;
        });
        try {
            $this->controller()->apply($id, fn () => self::fail('a stale claim was dispatched again'));
            self::fail('the stale claim was not refused');
        } catch (ConflictException $e) {
            self::assertStringContainsString('never finished', $e->getMessage());
        }
        self::assertSame('apply_interrupted', $this->status($id), 'the probe closed the claim');
    }

    private function status(string $id): string
    {
        return (string) ($this->store->getStagedChangeForUser(5, $id)['status'] ?? '');
    }

    public function testALateFailureDoesNotReopenAChangeClosedAsInterrupted(): void
    {
        $id = $this->stage();
        $dispatched = 0;
        try {
            $this->controller()->apply($id, function () use ($id, &$dispatched): never {
                $dispatched++;
                $this->anotherCallerClosesTheStaleClaim($id);
                throw new ValidationException('aff_campaign_name is required');
            });
            self::fail('the dispatch failure did not reach the caller');
        } catch (ValidationException) {
        }

        self::assertSame('apply_interrupted', $this->status($id), 'a closed change went back on the queue');
        $stored = $this->store->getStagedChangeForUser(5, $id);
        self::assertStringContainsString('aff_campaign_name is required', (string) ($stored['last_error'] ?? ''));

        // The operator was told to check and stage again; this id stays shut.
        try {
            $this->controller()->apply($id, function () use (&$dispatched): array {
                $dispatched++;

                return ['data' => []];
            });
            self::fail('a change closed as interrupted was applied again');
        } catch (ConflictException $e) {
            self::assertStringContainsString('is apply_interrupted, not staged', $e->getMessage());
        }
        self::assertSame(1, $dispatched);
    }

    public function testALateSuccessIsRecordedAsApplied(): void
    {
        $id = $this->stage();
        $out = $this->controller()->apply($id, function () use ($id): array {
            $this->anotherCallerClosesTheStaleClaim($id);

            return ['data' => ['aff_campaign_id' => 7]];
        });

        // The write landed and this process knows it: the record says so,
        // and keeps the trace of having been closed.
        self::assertSame('applied', $out['data']['change']['status']);
        $stored = $this->store->getStagedChangeForUser(5, $id);
        self::assertSame('applied', $stored['status'] ?? null);
        self::assertNotEmpty($stored['interrupted_at'] ?? null);
        self::assertNull($stored['last_error']);
    }

    public function testALateFailureAfterTheWriteLandedKeepsItInterruptedAndSaysSo(): void
    {
        $id = $this->stage();
        try {
            $this->controller()->apply($id, function () use ($id): never {
                $this->anotherCallerClosesTheStaleClaim($id);
                throw new WriteCommittedException('campaign', new \RuntimeException('read-back failed'));
            });
            self::fail('the WriteCommittedException did not reach the caller');
        } catch (WriteCommittedException $e) {
            $said = $e->getMessage();
        }

        $stored = $this->store->getStagedChangeForUser(5, $id);
        self::assertSame('apply_interrupted', $stored['status'] ?? null);
        self::assertSame($said, $stored['last_error'] ?? null, 'the record still says the outcome is unknown');
    }

    public function testTheStoreRefusesAStatusTheTableDoesNotAllow(): void
    {
        $id = $this->stage();
        // Every writer goes through the store, which asks the table under
        // the lock that re-read the record.
        try {
            $this->store->updateStagedChange(5, $id, static fn (array $c): array => ['status' => 'applied'] + $c);
            self::fail('staged became applied with no apply');
        } catch (\Prosper202\Lifecycle\IllegalTransition $e) {
            self::assertStringContainsString('staged may not become applied', $e->getMessage());
        }
        try {
            $this->store->updateStagedChange(5, $id, static fn (array $c): array => ['status' => 'aplied'] + $c);
            self::fail('a status no case names was stored');
        } catch (\Prosper202\Lifecycle\UnknownState $e) {
            self::assertStringContainsString('"aplied"', $e->getMessage());
        }
        self::assertSame('staged', $this->status($id));

        self::assertNull(
            $this->store->moveStagedChange(5, $id, StagedChangeStatus::Applied, static fn (array $c): array => $c),
            'a refused move writes nothing and says so'
        );
        self::assertSame('staged', $this->status($id));
        $moved = $this->store->moveStagedChange(5, $id, StagedChangeStatus::Discarded, static fn (array $c): array => $c);
        self::assertSame('discarded', $moved['status'] ?? null);
        self::assertNull($this->store->moveStagedChange(5, $id, StagedChangeStatus::Applying, static fn (array $c): array => $c));
        self::assertSame('discarded', $this->status($id), 'nothing leaves discarded');
    }
}
