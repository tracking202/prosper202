<?php

declare(strict_types=1);

namespace Tests\Conversion;

use Api\V3\Controllers\ConversionsController;
use Api\V3\Exception\ConflictException;
use Api\V3\RequestContext;
use Api\V3\Support\IdempotentCreate;
use Api\V3\Support\ServerStateStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeMysqliConnection;

/**
 * POST /conversions when the click's ledger already holds the request's key
 * (MysqlConversionRepository answers it as a duplicate and writes nothing):
 * a live row is served as it is with `duplicate: true`, and a deleted row is
 * refused with a 409 that leaves the request's Idempotency-Key free.
 *
 * The real controller and repository run over a fake connection, and the
 * key goes through the real IdempotentCreate wrapper and ServerStateStore:
 * whether a failure spends the key is decided by the exception class the
 * controller throws, so that seam is exercised, not stubbed. A new row
 * (no `duplicate`) needs a real insert id, so it is covered against a real
 * database in ConversionIdempotencyIntegrationTest.
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

    private function keyState(string $key, array $payload): string
    {
        $store = new ServerStateStore($this->stateDir);
        $scope = ServerStateStore::idempotencyScopeForUser(1);
        $state = $store->reserveIdempotent($scope, $key, ServerStateStore::idempotencyFingerprint('create:conversions', $payload))['state'];
        if ($state === 'claimed') {
            $store->releaseIdempotent($scope, $key);
        }

        return $state;
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

    public function testADuplicateOfADeletedRowIsA409NamingIt(): void
    {
        $db = $this->ledgerWithRow(1);

        try {
            (new ConversionsController($db, 1))->create(['click_id' => 10, 'transaction_id' => 'T-1']);
            self::fail('a conversion whose transaction id belongs to a deleted row must be refused');
        } catch (ConflictException $e) {
            self::assertSame(409, $e->getHttpStatus());
            self::assertStringContainsString('Conversion 41 on click 10 had transaction id "T-1" and was deleted', $e->getMessage());
            self::assertStringContainsString('nothing was written', $e->getMessage());
            self::assertSame(['conv_id' => 41, 'click_id' => 10, 'deleted' => true], $e->getDetails());
        }
        self::assertCount(0, $db->statementsContaining('INSERT INTO 202_conversion_logs'));
        self::assertCount(0, $db->statementsContaining('cl.deleted = 0'), 'the deleted row is never read back as the answer');
    }

    public function testADeletedPlainConversionIsNamedAsOne(): void
    {
        $db = $this->ledgerWithRow(1);
        $db->whenQueryContainsReturnRows('FROM 202_aff_campaigns WHERE aff_campaign_id = ?', [['payout_mode' => 'accumulate', 'aff_campaign_payout' => '4.00']]);

        try {
            (new ConversionsController($db, 1))->create(['click_id' => 10]);
            self::fail('an id-less conversion whose plain conversion was deleted must be refused');
        } catch (ConflictException $e) {
            self::assertStringContainsString("Conversion 41 was click 10's one conversion without a transaction id", $e->getMessage());
        }
        $lookup = $db->statementsContaining('AND dedupe_key = ?');
        self::assertSame([10, 'conversion'], $lookup[0]->boundValues);
    }

    /**
     * The duplicate's row was live under the click lock and is gone by the
     * read-back (deleted in between). This request wrote nothing, so the
     * failure must not claim a committed write and spend the key.
     */
    public function testADuplicateThatCannotBeReadBackSpendsNoKey(): void
    {
        $payload = ['click_id' => 10, 'transaction_id' => 'T-1'];
        $db = $this->ledgerWithRow(0);
        $db->whenQueryContainsReturnRows('WHERE cl.conv_id = ? AND cl.user_id = ? AND cl.deleted = 0', []);
        RequestContext::setHeaders(['Idempotency-Key' => 'dup-vanished-1']);

        try {
            $this->post($db, $payload);
            self::fail('a duplicate that cannot be read back must fail');
        } catch (\Throwable $e) {
            self::assertNotInstanceOf(\Api\V3\Exception\WriteCommittedException::class, $e, 'nothing was written: ' . $e->getMessage());
            self::assertInstanceOf(\Api\V3\Exception\DatabaseException::class, $e);
        }
        self::assertSame('claimed', $this->keyState('dup-vanished-1', $payload), 'a retry may run again');
    }

    /**
     * The transaction id located conversion 41 and nothing compared the
     * request to it, so a different payout answered `duplicate: true` and
     * the sale was dropped. It is refused naming transaction_id, writes
     * nothing, and leaves the Idempotency-Key free; the same payout written
     * another way is still the sale.
     */
    public function testADuplicateStatingADifferentPayoutIsRefused(): void
    {
        $recorded = static function (FakeMysqliConnection $db): FakeMysqliConnection {
            $db->whenQueryContainsReturnRows(
                'SELECT click_payout, conv_time, customer_id, reverses_conv_id FROM 202_conversion_logs',
                [[
                    'click_payout' => '5.00000', 'conv_time' => 1700000100,
                    'customer_id' => null, 'reverses_conv_id' => null,
                ]]
            );

            return $db;
        };
        $payload = ['click_id' => 10, 'transaction_id' => 'T-1', 'payout' => 7.5];
        RequestContext::setHeaders(['Idempotency-Key' => 'dup-different-1']);
        $db = $recorded($this->ledgerWithRow(0));

        try {
            $this->post($db, $payload);
            self::fail('a different payout under a recorded transaction id must be refused');
        } catch (\Api\V3\Exception\ValidationException $e) {
            self::assertSame(422, $e->getHttpStatus());
            self::assertStringContainsString('payout recorded 5.00000, sent 7.50000', $e->getMessage());
            self::assertSame(
                ['transaction_id' => 'Already recorded as conversion 41 with a different payout;'
                    . ' a different sale needs its own transaction_id'],
                $e->getFieldErrors()
            );
        }
        self::assertCount(0, $db->statementsContaining('INSERT INTO 202_conversion_logs'));
        self::assertSame(
            'claimed',
            $this->keyState('dup-different-1', $payload),
            'nothing was written, so the key is not spent'
        );

        RequestContext::reset();
        $same = (new ConversionsController($recorded($this->ledgerWithRow(0)), 1))
            ->create(['click_id' => 10, 'transaction_id' => 'T-1', 'payout' => '5.00']);
        self::assertTrue($same['duplicate'] ?? null, 'the recorded payout, written another way, is the same sale');
    }

    public function testARefusedDuplicateLeavesTheIdempotencyKeyFree(): void
    {
        $payload = ['click_id' => 10, 'transaction_id' => 'T-1'];
        RequestContext::setHeaders(['Idempotency-Key' => 'dup-deleted-1']);

        $answers = [];
        foreach ([1, 2] as $attempt) {
            try {
                $this->post($this->ledgerWithRow(1), $payload);
                $answers[] = 'recorded';
            } catch (\Throwable $e) {
                $answers[] = $e::class . ': ' . $e->getMessage();
            }
        }

        self::assertSame('claimed', $this->keyState('dup-deleted-1', $payload), 'nothing was written, so the key is not spent; answers: ' . implode(' | ', $answers));
        // The retry gets the same answer, not "a previous request with this
        // Idempotency-Key did not finish".
        foreach ($answers as $i => $answer) {
            self::assertStringStartsWith(ConflictException::class . ': Conversion 41', $answer, 'attempt ' . ($i + 1));
            self::assertStringContainsString('was deleted', $answer, 'attempt ' . ($i + 1));
        }
    }
}
