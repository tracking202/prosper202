<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Support\ServerStateStore;
use Tests\TestCase;

/**
 * What each writer of an Idempotency-Key record may overwrite. A key marked
 * indeterminate (the write landed, no response to replay) is spent for good:
 * nothing frees it and nothing answers for it afterwards (CLAUDE.md #13,
 * #34), and a recorded response is the one every replay gets.
 */
final class IdempotencyRecordMovesTest extends TestCase
{
    private string $tmpDir;
    private ServerStateStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/p202-idem-moves-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0700, true);
        $this->store = new ServerStateStore($this->tmpDir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmpDir));
        parent::tearDown();
    }

    public function testAReleaseDoesNotFreeAKeyMarkedIndeterminate(): void
    {
        $this->store->reserveIdempotent('scope', 'key-1', 'fp');
        $this->store->markIdempotentIndeterminate('scope', 'key-1');
        $this->store->releaseIdempotent('scope', 'key-1');

        self::assertSame('indeterminate', $this->store->reserveIdempotent('scope', 'key-1', 'fp')['state']);
    }

    public function testAResponseIsNotRecordedOverAKeyMarkedIndeterminate(): void
    {
        $this->store->reserveIdempotent('scope', 'key-1', 'fp');
        $this->store->markIdempotentIndeterminate('scope', 'key-1');
        [, $log] = $this->logged(fn () => $this->store->putIdempotent('scope', 'key-1', ['data' => ['id' => 2]], 'fp'));

        self::assertSame('indeterminate', $this->store->reserveIdempotent('scope', 'key-1', 'fp')['state']);
        self::assertStringContainsString('indeterminate', $log);
    }

    public function testASecondResponseDoesNotReplaceTheOneRecorded(): void
    {
        $this->store->putIdempotent('scope', 'key-1', ['data' => ['id' => 1]], 'fp');
        [, $log] = $this->logged(fn () => $this->store->putIdempotent('scope', 'key-1', ['data' => ['id' => 2]], 'fp'));

        self::assertSame(['data' => ['id' => 1]], $this->store->lookupIdempotent('scope', 'key-1', 'fp')['response']);
        self::assertStringContainsString('committed', $log);

        // The same response again is nothing to say.
        [, $log] = $this->logged(fn () => $this->store->putIdempotent('scope', 'key-1', ['data' => ['id' => 1]], 'fp'));
        self::assertSame('', $log);
    }

    public function testTheHolderOfAClaimThatAgedOutStillSettlesIt(): void
    {
        // A retry past the claim's TTL is told the outcome is unknown; the
        // request holding the claim is the one that knows.
        foreach (['recorded', 'released'] as $ending) {
            $key = 'key-' . $ending;
            $this->store->reserveIdempotent('scope', $key, 'fp');
            $this->ageClaim($key);
            self::assertSame('indeterminate', $this->store->reserveIdempotent('scope', $key, 'fp')['state']);
            if ($ending === 'recorded') {
                $this->store->putIdempotent('scope', $key, ['data' => ['id' => 1]], 'fp');
                self::assertSame('replay', $this->store->reserveIdempotent('scope', $key, 'fp')['state']);
            } else {
                $this->store->releaseIdempotent('scope', $key);
                self::assertSame('claimed', $this->store->reserveIdempotent('scope', $key, 'fp')['state']);
            }
        }
    }

    private function ageClaim(string $key): void
    {
        $files = glob($this->tmpDir . '/idempotency/*.json') ?: [];
        foreach ($files as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (isset($data['items'][$key])) {
                $data['items'][$key]['claimed_at'] = time() - ServerStateStore::IDEMPOTENCY_CLAIM_TTL_SECONDS - 5;
                file_put_contents($file, json_encode($data));

                return;
            }
        }
        self::fail("no shard holds $key");
    }

    /**
     * @param callable(): mixed $fn
     * @return array{mixed, string}
     */
    private function logged(callable $fn): array
    {
        $file = $this->tmpDir . '/error.log';
        file_put_contents($file, '');
        $previous = ini_set('error_log', $file);
        try {
            $answer = $fn();
        } finally {
            ini_set('error_log', (string) $previous);
        }

        return [$answer, (string) file_get_contents($file)];
    }
}
