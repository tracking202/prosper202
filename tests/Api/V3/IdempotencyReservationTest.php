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
        $file = $this->fileHolding($key);
        $data = json_decode((string)file_get_contents($file), true);
        $data['items'][$key]['claimed_at'] = time() - (ServerStateStore::IDEMPOTENCY_CLAIM_TTL_SECONDS + 60);
        file_put_contents($file, json_encode($data));
    }

    /**
     * Records are sharded by key, so locating one means finding the shard
     * that holds it rather than assuming the store wrote a single file.
     */
    private function fileHolding(string $key): string
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $candidate) {
            if (!$candidate->isFile() || !str_ends_with($candidate->getFilename(), '.json')) {
                continue;
            }
            $data = json_decode((string)file_get_contents($candidate->getPathname()), true);
            if (is_array($data) && isset($data['items'][$key])) {
                return $candidate->getPathname();
            }
        }
        $this->fail('expected an idempotency record for ' . $key . ' on disk');
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

    public function testAKeyReusedWithADifferentBodyIsRefused(): void
    {
        // The scope used to carry the payload hash, so the same key with a
        // changed field addressed a different record, looked unused, and
        // executed — creating the second row the key was sent to prevent.
        $this->store->reserveIdempotent('scope', 'key-1', 'fingerprint-a');
        $this->store->putIdempotent('scope', 'key-1', ['data' => ['id' => 7]], 'fingerprint-a');

        $mismatch = $this->store->reserveIdempotent('scope', 'key-1', 'fingerprint-b');
        $this->assertSame('mismatch', $mismatch['state']);
        $this->assertNull($mismatch['response']);
    }

    public function testAKeyReusedWithTheSameBodyStillReplays(): void
    {
        $this->store->reserveIdempotent('scope', 'key-1', 'fingerprint-a');
        $this->store->putIdempotent('scope', 'key-1', ['data' => ['id' => 7]], 'fingerprint-a');

        $replay = $this->store->reserveIdempotent('scope', 'key-1', 'fingerprint-a');
        $this->assertSame('replay', $replay['state']);
        $this->assertSame(['data' => ['id' => 7]], $replay['response']);
    }

    public function testADifferentBodyIsRefusedWhileTheFirstRequestIsStillRunning(): void
    {
        // Checked ahead of the in-flight answer: telling the caller to wait
        // implies the running request will produce their response, and it
        // will not.
        $this->store->reserveIdempotent('scope', 'key-1', 'fingerprint-a');

        $this->assertSame('mismatch', $this->store->reserveIdempotent('scope', 'key-1', 'fingerprint-b')['state']);
    }

    public function testADifferentBodyIsRefusedOnAnIndeterminateKey(): void
    {
        $this->store->reserveIdempotent('scope', 'key-1', 'fingerprint-a');
        $this->store->markIdempotentIndeterminate('scope', 'key-1');

        $this->assertSame('mismatch', $this->store->reserveIdempotent('scope', 'key-1', 'fingerprint-b')['state']);
    }

    public function testPutPreservesTheFingerprintTheClaimRecorded(): void
    {
        // The write path passes the fingerprint too, but a caller that does
        // not must still leave the claim's on record or the mismatch check
        // goes blind after the response lands.
        $this->store->reserveIdempotent('scope', 'key-1', 'fingerprint-a');
        $this->store->putIdempotent('scope', 'key-1', ['data' => ['id' => 7]]);

        $this->assertSame('mismatch', $this->store->reserveIdempotent('scope', 'key-1', 'fingerprint-b')['state']);
    }

    public function testAReleasedKeyForgetsItsFingerprint(): void
    {
        // Nothing was written, so the key is genuinely unused: a corrected
        // retry with a different body must be allowed to claim it.
        $this->store->reserveIdempotent('scope', 'key-1', 'fingerprint-a');
        $this->store->releaseIdempotent('scope', 'key-1');

        $this->assertSame('claimed', $this->store->reserveIdempotent('scope', 'key-1', 'fingerprint-b')['state']);
    }

    public function testCallersThatSendNoFingerprintAreUnaffected(): void
    {
        $this->store->reserveIdempotent('scope', 'key-1');
        $this->store->putIdempotent('scope', 'key-1', ['data' => ['id' => 7]]);

        $this->assertSame('replay', $this->store->reserveIdempotent('scope', 'key-1')['state']);
    }

    public function testLookupReportsMissReplayAndMismatch(): void
    {
        // The read-only path used by bulk-upsert and sync job creation.
        $this->assertSame('miss', $this->store->lookupIdempotent('scope', 'key-1', 'fingerprint-a')['state']);

        $this->store->putIdempotent('scope', 'key-1', ['data' => ['id' => 7]], 'fingerprint-a');

        $replay = $this->store->lookupIdempotent('scope', 'key-1', 'fingerprint-a');
        $this->assertSame('replay', $replay['state']);
        $this->assertSame(['data' => ['id' => 7]], $replay['response']);

        $this->assertSame('mismatch', $this->store->lookupIdempotent('scope', 'key-1', 'fingerprint-b')['state']);
    }

    public function testLookupTreatsAnUnfinishedClaimAsAMiss(): void
    {
        // A claim carries no response, so there is nothing to replay. The
        // reservation path is what serialises concurrent callers; lookup
        // must not mistake a claim for a recorded result.
        $this->store->reserveIdempotent('scope', 'key-1', 'fingerprint-a');

        $this->assertSame('miss', $this->store->lookupIdempotent('scope', 'key-1', 'fingerprint-a')['state']);
    }

    public function testTheSameKeyAlwaysLandsInTheSameShard(): void
    {
        // The shard is derived from the key alone. Were the body part of it,
        // a changed body would address a different file and the mismatch
        // would be invisible rather than refused.
        $this->store->reserveIdempotent('scope', 'key-1', 'fingerprint-a');
        $this->store->releaseIdempotent('scope', 'key-1');
        $this->store->reserveIdempotent('scope', 'key-1', 'fingerprint-b');

        $this->assertSame(['key-1'], $this->keysOnDisk(), 'the key must live in exactly one shard');
    }

    public function testAKeyReusedForADifferentOperationIsRefused(): void
    {
        // The scope is the caller alone, so the endpoint lives in the
        // fingerprint. Were it in the scope, the same key sent to a second
        // endpoint would address a different file, look unused, and execute.
        $scope = ServerStateStore::idempotencyScopeForUser(7);
        $create = ServerStateStore::idempotencyFingerprint('create:campaigns', ['name' => 'A']);
        $bulk = ServerStateStore::idempotencyFingerprint('bulk-upsert:campaigns', ['name' => 'A']);
        $this->assertNotSame($create, $bulk, 'the operation must change the fingerprint');

        $this->store->reserveIdempotent($scope, 'key-1', $create);
        $this->store->putIdempotent($scope, 'key-1', ['data' => ['id' => 7]], $create);

        $this->assertSame('mismatch', $this->store->reserveIdempotent($scope, 'key-1', $bulk)['state']);
    }

    public function testTwoCallersMayUseTheSameKeyIndependently(): void
    {
        $mine = ServerStateStore::idempotencyScopeForUser(7);
        $theirs = ServerStateStore::idempotencyScopeForUser(8);
        $fingerprint = ServerStateStore::idempotencyFingerprint('create:campaigns', ['name' => 'A']);

        $this->store->putIdempotent($mine, 'shared-key', ['data' => ['id' => 7]], $fingerprint);

        $this->assertSame('claimed', $this->store->reserveIdempotent($theirs, 'shared-key', $fingerprint)['state']);
    }

    public function testDifferentScopesDoNotShareRecords(): void
    {
        $this->store->putIdempotent('scope-a', 'key-1', ['data' => ['id' => 7]], 'fingerprint-a');

        $this->assertSame('miss', $this->store->lookupIdempotent('scope-b', 'key-1', 'fingerprint-a')['state']);
    }

    public function testExpiredRecordsArePrunedFromTheirShard(): void
    {
        // Without an age bound a busy caller's shard grows without limit and
        // every keyed create reads and rewrites it.
        $this->store->putIdempotent('scope', 'old-key', ['data' => ['id' => 1]], 'fingerprint-a');
        $file = $this->fileHolding('old-key');
        $data = json_decode((string)file_get_contents($file), true);
        $data['items']['old-key']['stored_at'] = time() - 90000;
        file_put_contents($file, json_encode($data));

        // Any key hashing into the same shard triggers the prune.
        $sameShard = $this->keySharingShardWith('old-key');
        $this->store->putIdempotent('scope', $sameShard, ['data' => ['id' => 2]], 'fingerprint-b');

        $data = json_decode((string)file_get_contents($file), true);
        $this->assertArrayNotHasKey('old-key', $data['items']);
        $this->assertArrayHasKey($sameShard, $data['items']);
    }

    public function testTheCountPruneNeverDiscardsTheRecordJustWritten(): void
    {
        // Reassigning an existing key keeps its original position in the
        // array, and PHP's sort is stable, so in a burst where every record
        // shares a timestamp the record just written stays at the front of
        // the slice window and would be the first one dropped — losing the
        // response a retry is about to ask for.
        $this->store->putIdempotent('scope', 'key-1', ['data' => ['id' => 7]], 'fingerprint-a');
        $file = $this->fileHolding('key-1');
        $data = json_decode((string)file_get_contents($file), true);
        $now = time();
        $data['items']['key-1']['stored_at'] = $now;
        for ($i = 0; $i < 600; $i++) {
            $data['items']['filler-' . $i] = ['stored_at' => $now, 'fingerprint' => 'f', 'response' => ['data' => []]];
        }
        file_put_contents($file, json_encode($data));

        $this->store->putIdempotent('scope', 'key-1', ['data' => ['id' => 7]], 'fingerprint-a');

        $this->assertSame('replay', $this->store->lookupIdempotent('scope', 'key-1', 'fingerprint-a')['state']);
    }

    public function testAnAllDigitKeyIsHandledLikeAnyOther(): void
    {
        // PHP turns an all-digit array key into an int, so the record for
        // "12345" is reached as 12345 — every comparison against the key has
        // to survive that coercion or the prune treats the record the
        // current request just wrote as somebody else's.
        $this->store->reserveIdempotent('scope', '12345', 'fingerprint-a');
        $this->store->putIdempotent('scope', '12345', ['data' => ['id' => 7]], 'fingerprint-a');

        $this->assertSame('replay', $this->store->lookupIdempotent('scope', '12345', 'fingerprint-a')['state']);
        $this->assertSame('mismatch', $this->store->lookupIdempotent('scope', '12345', 'fingerprint-b')['state']);
    }

    public function testTheCountPruneBoundsAShard(): void
    {
        $this->store->putIdempotent('scope', 'key-1', ['data' => ['id' => 7]], 'fingerprint-a');
        $file = $this->fileHolding('key-1');
        $data = json_decode((string)file_get_contents($file), true);
        $now = time();
        for ($i = 0; $i < 600; $i++) {
            $data['items']['filler-' . $i] = ['stored_at' => $now, 'fingerprint' => 'f', 'response' => ['data' => []]];
        }
        file_put_contents($file, json_encode($data));

        $this->store->putIdempotent('scope', 'key-1', ['data' => ['id' => 7]], 'fingerprint-a');

        $data = json_decode((string)file_get_contents($file), true);
        $this->assertLessThanOrEqual(501, count($data['items']), 'the shard must stay bounded');
    }

    /**
     * Every key recorded anywhere under the store, one entry per file it
     * appears in — so a key written to two shards shows up twice.
     *
     * @return list<string>
     */
    private function keysOnDisk(): array
    {
        $keys = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $candidate) {
            if (!$candidate->isFile() || !str_ends_with($candidate->getFilename(), '.json')) {
                continue;
            }
            $data = json_decode((string)file_get_contents($candidate->getPathname()), true);
            foreach (array_keys(is_array($data['items'] ?? null) ? $data['items'] : []) as $key) {
                $keys[] = (string)$key;
            }
        }
        sort($keys);
        return $keys;
    }

    /** Find another key whose shard is the one $key lives in. */
    private function keySharingShardWith(string $key): string
    {
        $shard = substr(sha1($key), 0, 2);
        for ($i = 0; $i < 100000; $i++) {
            $candidate = 'probe-' . $i;
            if ($candidate !== $key && substr(sha1($candidate), 0, 2) === $shard) {
                return $candidate;
            }
        }
        $this->fail('no key found sharing a shard with ' . $key);
    }
}
