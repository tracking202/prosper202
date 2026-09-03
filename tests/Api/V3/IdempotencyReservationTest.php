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
}
