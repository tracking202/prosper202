<?php

declare(strict_types=1);

namespace Tests\Connect;

use Api\V3\Support\AiConnect;
use Api\V3\Support\ServerStateStore;
use PHPUnit\Framework\TestCase;

/**
 * POST /connect/token's failure budget: only failures count, a source past
 * its budget is refused before the work unless it presents a live approval,
 * and a good exchange is never refused.
 */
final class AiConnectFailureBudgetTest extends TestCase
{
    private const PEER = '203.0.113.9';
    private string $stateDir;

    protected function setUp(): void
    {
        $this->stateDir = sys_get_temp_dir() . '/p202-ai-budget-' . bin2hex(random_bytes(6));
        mkdir($this->stateDir, 0700);
        putenv('P202_SERVER_STATE_DIR=' . $this->stateDir);
    }

    protected function tearDown(): void
    {
        putenv('P202_SERVER_STATE_DIR');
        exec('rm -rf ' . escapeshellarg($this->stateDir));
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function call(ServerStateStore $store, string $code, int $status, bool &$ran): array
    {
        $ran = false;
        return AiConnect::tokenWithFailureBudget(
            $store,
            ['code' => $code, 'code_verifier' => str_repeat('v', 50)],
            function () use ($status, &$ran): array {
                $ran = true;
                return [$status, ['data' => []]];
            },
            self::PEER
        );
    }

    private function spendTheBudget(ServerStateStore $store): void
    {
        $ran = false;
        for ($i = 0; $i < AiConnect::FAILURE_BUDGET; $i++) {
            [$status] = $this->call($store, str_repeat('j', 43), 400, $ran);
            self::assertSame(400, $status, "failure $i is answered as itself");
        }
    }

    public function testASourcePastItsBudgetIsRefusedBeforeTheWork(): void
    {
        $store = new ServerStateStore();
        $live = AiConnect::approve($store, 7, (string) AiConnect::scopeFor('read'), str_repeat('c', 43), 'Claude');
        $this->spendTheBudget($store);

        $ran = false;
        [$status, $body] = $this->call($store, str_repeat('k', 43), 400, $ran);
        self::assertSame(429, $status);
        self::assertGreaterThan(0, $body['retry_after_seconds']);
        self::assertFalse($ran, 'the exchange ran for a source past its budget');
        self::assertTrue($store->hasConnectCode(hash('sha256', $live)), 'an approval was spent');
    }

    /** Approvals that cannot be read hold no live one: past its budget, the source is refused. */
    public function testUnreadableApprovalsLetNoSourcePastItsBudget(): void
    {
        $store = new ServerStateStore();
        $live = AiConnect::approve($store, 7, (string) AiConnect::scopeFor('read'), str_repeat('c', 43), 'Claude');
        $this->spendTheBudget($store);
        $files = glob($this->stateDir . '/*/connect.json') ?: [];
        self::assertCount(1, $files, 'the approvals file');
        file_put_contents($files[0], '{not json');

        $ran = false;
        [$status] = $this->call($store, $live, 200, $ran);
        self::assertSame(429, $status);
        self::assertFalse($ran, 'the exchange ran past the budget on unreadable approvals');
    }

    public function testALiveApprovalIsExchangedEvenPastTheBudget(): void
    {
        $store = new ServerStateStore();
        $live = AiConnect::approve($store, 7, (string) AiConnect::scopeFor('read'), str_repeat('c', 43), 'Claude');
        $this->spendTheBudget($store);

        $ran = false;
        [$status] = $this->call($store, $live, 200, $ran);
        self::assertTrue($ran);
        self::assertSame(200, $status);
    }

    public function testOnlyFailuresAreCounted(): void
    {
        $store = new ServerStateStore();
        $ran = false;
        for ($i = 0; $i < AiConnect::FAILURE_BUDGET * 2; $i++) {
            [$status] = $this->call($store, str_repeat('g', 43), 200, $ran);
            self::assertSame(200, $status, "good exchange $i");
        }
        self::assertNull($store->softIpRateLimitExceeded(
            'connect-token-failed',
            AiConnect::FAILURE_BUDGET,
            AiConnect::FAILURE_WINDOW,
            self::PEER
        ));
    }

    public function testTheCheckAloneCountsNothing(): void
    {
        $store = new ServerStateStore();
        for ($i = 0; $i < AiConnect::FAILURE_BUDGET + 5; $i++) {
            self::assertNull($store->softIpRateLimitExceeded('probe', 1, 60, self::PEER));
        }
        self::assertNull($store->softIpRateLimit('probe', 1, 60, self::PEER), 'the first counted request');
        self::assertNotNull($store->softIpRateLimitExceeded('probe', 1, 60, self::PEER));
    }
}
