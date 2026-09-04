<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Support\ServerStateStore;
use Tests\TestCase;

/**
 * The reservation half of Idempotency-Key handling. A read-then-write left a
 * window where two concurrent retries both missed the record and both
 * executed — the case idempotency exists to prevent, since automatic retries
 * usually race a still-running request rather than following it.
 */
final class IdempotencyReservationTest extends TestCase
{
    private string $tmpDir;
    private ServerStateStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/p202-idem-test-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0700, true);
        $this->store = new ServerStateStore($this->tmpDir);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->tmpDir);
        parent::tearDown();
    }

    public function testFirstCallerClaimsTheKey(): void
    {
        $r = $this->store->reserveIdempotent('scope', 'key-1');
        $this->assertSame('claimed', $r['state']);
        $this->assertNull($r['response']);
    }

    public function testASecondCallerWhileTheFirstIsInFlightIsToldSo(): void
    {
        $this->store->reserveIdempotent('scope', 'key-1');
        $second = $this->store->reserveIdempotent('scope', 'key-1');
        $this->assertSame('in_flight', $second['state']);
    }

    public function testOnceRecordedTheKeyReplays(): void
    {
        $this->store->reserveIdempotent('scope', 'key-1');
        $this->store->putIdempotent('scope', 'key-1', ['data' => ['id' => 7]]);

        $replay = $this->store->reserveIdempotent('scope', 'key-1');
        $this->assertSame('replay', $replay['state']);
        $this->assertSame(['data' => ['id' => 7]], $replay['response']);
    }

    public function testAReleasedClaimCanBeRetaken(): void
    {
        // A create that failed must not wedge its key until the claim expires.
        $this->store->reserveIdempotent('scope', 'key-1');
        $this->store->releaseIdempotent('scope', 'key-1');

        $again = $this->store->reserveIdempotent('scope', 'key-1');
        $this->assertSame('claimed', $again['state']);
    }

    public function testReleaseNeverDiscardsARecordedResponse(): void
    {
        $this->store->reserveIdempotent('scope', 'key-1');
        $this->store->putIdempotent('scope', 'key-1', ['data' => ['id' => 7]]);
        $this->store->releaseIdempotent('scope', 'key-1');

        $this->assertSame('replay', $this->store->reserveIdempotent('scope', 'key-1')['state']);
    }

    public function testDifferentKeysDoNotBlockEachOther(): void
    {
        $this->store->reserveIdempotent('scope', 'key-1');
        $this->assertSame('claimed', $this->store->reserveIdempotent('scope', 'key-2')['state']);
    }

    /**
     * Age a claim past its TTL the way a process death would: the claim is
     * on record, no response ever followed it.
     */
    private function expireClaim(string $scope, string $key): void
    {
        $file = null;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $candidate) {
            if ($candidate->isFile() && str_ends_with($candidate->getFilename(), '.json')) {
                $file = $candidate->getPathname();
            }
        }
        $this->assertNotNull($file, 'expected an idempotency file on disk');
        $data = json_decode((string)file_get_contents($file), true);
        $data['items'][$key]['claimed_at'] = time() - (ServerStateStore::IDEMPOTENCY_CLAIM_TTL_SECONDS + 60);
        file_put_contents($file, json_encode($data));
    }

    public function testAnExpiredClaimIsIndeterminateRatherThanFree(): void
    {
        // A holder that merely failed releases its claim, so an expired one
        // means the process died outright — possibly after its write
        // committed. Handing the key back would duplicate the record.
        $this->store->reserveIdempotent('scope', 'key-1');
        $this->expireClaim('scope', 'key-1');

        $this->assertSame('indeterminate', $this->store->reserveIdempotent('scope', 'key-1')['state']);
    }

    public function testAKeyMarkedIndeterminateIsUnknownFromTheNextRetry(): void
    {
        // A handler whose write landed but whose response never got recorded
        // marks the key itself. Without that the claim just looks in-flight
        // until it ages out, and a retry in that window is told to wait for a
        // request that is already gone.
        $this->store->reserveIdempotent('scope', 'key-1');
        $this->store->markIdempotentIndeterminate('scope', 'key-1');

        $this->assertSame('indeterminate', $this->store->reserveIdempotent('scope', 'key-1')['state']);
    }

    public function testMarkingIndeterminateNeverDiscardsARecordedResponse(): void
    {
        $this->store->reserveIdempotent('scope', 'key-1');
        $this->store->putIdempotent('scope', 'key-1', ['data' => ['id' => 7]]);
        $this->store->markIdempotentIndeterminate('scope', 'key-1');

        $replay = $this->store->reserveIdempotent('scope', 'key-1');
        $this->assertSame('replay', $replay['state']);
        $this->assertSame(['data' => ['id' => 7]], $replay['response']);
    }

    public function testAnIndeterminateKeyStaysIndeterminate(): void
    {
        $this->store->reserveIdempotent('scope', 'key-1');
        $this->expireClaim('scope', 'key-1');

        $this->store->reserveIdempotent('scope', 'key-1');
        // The claim is kept, so every later retry gets the same answer
        // instead of the second one silently re-executing.
        $this->assertSame('indeterminate', $this->store->reserveIdempotent('scope', 'key-1')['state']);
    }
}
