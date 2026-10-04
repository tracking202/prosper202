<?php

declare(strict_types=1);

namespace Tests\Conversion;

use Api\V3\Controllers\ConversionsController;
use Api\V3\RequestContext;
use Api\V3\Support\IdempotentCreate;
use Api\V3\Support\ServerStateStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeMysqliConnection;

/**
 * POST /conversions when the click's ledger already holds the request's key
 * (MysqlConversionRepository answers it as a duplicate and writes nothing):
 * the row is served as it is with `duplicate: true`.
 *
 * The real controller and repository run over a fake connection, and the
 * key goes through the real IdempotentCreate wrapper and ServerStateStore.
 * A new row (no `duplicate`) needs a real insert id, so it is covered
 * against a real database in ConversionIdempotencyIntegrationTest.
 */
final class ConversionCreateDuplicateTest extends TestCase
{
    private string $stateDir;

    protected function setUp(): void
    {
        $this->stateDir = sys_get_temp_dir() . '/p202-conv-dup-' . bin2hex(random_bytes(4));
        mkdir($this->stateDir, 0700, true);
        RequestContext::reset();
    }

    protected function tearDown(): void
    {
        RequestContext::reset();
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->stateDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->stateDir);
    }

    /** A connection whose click 10 already has conversion 41 under the request's key. */
    private function ledgerWithRow(int $deleted): FakeMysqliConnection
    {
        $db = new FakeMysqliConnection();
        $db->whenQueryContainsReturnRows(
            'FROM 202_clicks WHERE click_id = ? AND user_id = ? LIMIT 1 FOR UPDATE',
            [['click_id' => 10, 'aff_campaign_id' => 44, 'click_payout' => '5.00000', 'click_time' => 1700000000, 'click_lead' => 1]]
        );
        $db->whenQueryContainsReturnRows(
            'FROM 202_conversion_logs WHERE click_id = ? AND dedupe_key = ?',
            [['conv_id' => 41, 'customer_id' => null, 'deleted' => $deleted]]
        );
        if ($deleted === 0) {
            $db->whenQueryContainsReturnRows('WHERE cl.conv_id = ? AND cl.user_id = ? AND cl.deleted = 0', [[
                'conv_id' => 41, 'click_id' => 10, 'transaction_id' => 'T-1', 'campaign_id' => 44,
                'click_payout' => '5.00000', 'user_id' => 1, 'click_time' => 1700000000, 'conv_time' => 1700000100,
                'deleted' => 0, 'source' => 'api', 'source_ref' => null, 'event_name' => null, 'payable' => 1,
                'reverses_conv_id' => null, 'superseded_by' => null, 'superseded_reason' => null, 'aff_campaign_name' => 'c44',
            ]]);
        }

        return $db;
    }

    private function wrapper(): IdempotentCreate
    {
        return new IdempotentCreate(new ServerStateStore($this->stateDir), 1);
    }

    /** @return array<string, mixed> */
    private function post(FakeMysqliConnection $db, array $payload): array
    {
        return ($this->wrapper())('conversions', $payload, static fn (): array => (new ConversionsController($db, 1))->create($payload));
    }

    public function testADuplicateOfALiveRowIsServedWithTheFlag(): void
    {
        $db = $this->ledgerWithRow(0);

        $response = (new ConversionsController($db, 1))->create(['click_id' => 10, 'transaction_id' => 'T-1']);

        self::assertTrue($response['duplicate'] ?? null, 'a duplicate says so');
        self::assertSame(41, (int) $response['data']['conv_id'], 'and serves the row it matched');
        self::assertArrayNotHasKey('duplicate', $response['data'], 'data stays the conversion as GET serves it');
        self::assertCount(0, $db->statementsContaining('INSERT INTO 202_conversion_logs'));
        $lookup = $db->statementsContaining('AND dedupe_key = ?');
        self::assertSame([10, 'tx:T-1'], $lookup[0]->boundValues, 'matched by its ledger key');
    }

    public function testAReplayedDuplicateCarriesBothFlags(): void
    {
        $payload = ['click_id' => 10, 'transaction_id' => 'T-1'];
        RequestContext::setHeaders(['Idempotency-Key' => 'dup-live-1']);

        $first = $this->post($this->ledgerWithRow(0), $payload);
        $replay = $this->post($this->ledgerWithRow(0), $payload);

        self::assertTrue($first['duplicate'] ?? null);
        self::assertArrayNotHasKey('idempotent_replay', $first);
        self::assertTrue($replay['duplicate'] ?? null, 'the recorded response keeps the flag');
        self::assertTrue($replay['idempotent_replay'] ?? null);
    }
}
