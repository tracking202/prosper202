<?php

declare(strict_types=1);

namespace Tests\Conversion;

use PHPUnit\Framework\TestCase;
use Prosper202\Conversion\MysqlConversionRepository;
use Prosper202\Database\Connection;
use Tests\Support\FakeMysqliConnection;
use Tests\Support\InsertReportingFakeMysqliConnection;

final class MysqlConversionRepositoryTest extends TestCase
{
    public function testCreateUsesWriteConnectionAndLocksClickRow(): void
    {
        // The writer refuses an insert that reports no id, and only the
        // insert-reporting double can report one on PHP 8.4.
        $write = new InsertReportingFakeMysqliConnection(21);
        $read = new FakeMysqliConnection();
        $write->whenQueryContainsReturnRows(
            'FROM 202_clicks WHERE click_id = ? AND user_id = ? LIMIT 1 FOR UPDATE',
            [[
                'click_id' => 10,
                'aff_campaign_id' => 44,
                'click_payout' => 2.75,
                'click_time' => 1700000000,
                'click_lead' => 0,
            ]]
        );

        $conn = new Connection($write, $read);
        $repo = new MysqlConversionRepository($conn);

        $id = $repo->create(7, ['click_id' => 10, 'transaction_id' => 'tx-1']);
        self::assertIsInt($id);

        // The transactional write path must never touch the read connection —
        // it has to see the locked/uncommitted state. The ONLY reads allowed
        // are the post-commit Landing Page Optimizer bridge emit lookups (install context +
        // webhook subscriber match), which run after the transaction returns.
        foreach ($read->statements as $stmt) {
            self::assertMatchesRegularExpression(
                '/202_users|202_ltv_webhooks/', // users/users_pref (install ctx) + webhook match
                $stmt->sql,
                'transactional statement leaked onto the read connection: ' . $stmt->sql
            );
        }
        self::assertCount(0, $read->statementsContaining('202_clicks'), 'click lock/lookup must use the write connection');
        self::assertCount(0, $read->statementsContaining('202_conversion_logs'), 'dedupe/insert must use the write connection');

        $lookupStatements = $write->statementsContaining('FROM 202_clicks WHERE click_id = ? AND user_id = ?');
        self::assertCount(1, $lookupStatements);
        self::assertStringContainsString('FOR UPDATE', $lookupStatements[0]->sql);
        self::assertSame('ii', $lookupStatements[0]->boundTypes);
        self::assertSame([10, 7], $lookupStatements[0]->boundValues);
    }

    /**
     * Run record() against fresh fakes with a subscribed bridge webhook,
     * returning the emitted bridge payload alongside record()'s result.
     *
     * @return array{result: array<string, mixed>, payload: array<string, mixed>, write: InsertReportingFakeMysqliConnection}
     */
    private function recordWithBridgeEmit(string $transactionId): array
    {
        // The writer refuses an insert that reports no id, and only the
        // insert-reporting double can report one on PHP 8.4.
        $write = new InsertReportingFakeMysqliConnection(21);
        $read = new FakeMysqliConnection();
        $write->whenQueryContainsReturnRows(
            'FROM 202_clicks WHERE click_id = ? AND user_id = ? LIMIT 1 FOR UPDATE',
            [[
                'click_id' => 10,
                'aff_campaign_id' => 44,
                'click_payout' => 2.75,
                'click_time' => 1700000000,
                'click_lead' => 0,
            ]]
        );
        $read->whenQueryContainsReturnRows('FROM 202_ltv_webhooks', [['webhook_id' => 5]]);

        $repo = new MysqlConversionRepository(new Connection($write, $read));
        $result = $repo->record(7, ['click_id' => 10, 'transaction_id' => $transactionId, 'conv_time' => 1700000100]);

        $deliveries = $write->statementsContaining('INSERT INTO 202_ltv_webhook_deliveries');
        self::assertCount(1, $deliveries, 'a new conversion with a subscriber must queue exactly one delivery');
        $wire = json_decode((string) $deliveries[0]->boundValues[3], true);
        self::assertIsArray($wire);

        return ['result' => $result, 'payload' => $wire['data']['payload'], 'write' => $write];
    }

    public function testBridgeEmitKeepsClickColonTxidKeyForRealTransactionIds(): void
    {
        $emitted = $this->recordWithBridgeEmit('TX-9');

        self::assertSame('10:TX-9', $emitted['payload']['idempotency_key'], 'non-blank txids keep the click:txid key shape');
        self::assertSame('TX-9', $emitted['payload']['transaction_id']);
        self::assertSame($emitted['result']['convId'], $emitted['payload']['conv_id']);
    }

    public function testBridgeEmitKeysBlankTransactionIdsByConversionRowId(): void
    {
        // Blank txids are stored as NULL and never deduped — two real
        // conversions on the same click are both legitimate, so their emit
        // keys must not collapse to a shared '<clickId>:' (review finding).
        // The key embeds the conversion row id instead: stable across retries
        // of the same conversion, distinct per row. (Per-row distinctness with
        // real AUTO_INCREMENT ids is pinned by ConversionBridgeIntegrationTest.)
        $emitted = $this->recordWithBridgeEmit('   ');

        self::assertSame(
            '10:conv:' . $emitted['result']['convId'],
            $emitted['payload']['idempotency_key'],
            'a blank txid must key by the conversion row id, never the bare click prefix'
        );
        self::assertSame('', $emitted['payload']['transaction_id'], 'the payload txid stays blank; only the key carries the conv marker');
        self::assertSame($emitted['result']['convId'], $emitted['payload']['conv_id']);

        // Unit-pin the premise: no txid → the row is keyed by its own id
        // (row:<conv_id>), so it is never deduplicated, and stored NULL.
        $keyed = $emitted['write']->statementsContaining('UPDATE 202_conversion_logs SET dedupe_key = ?');
        self::assertCount(1, $keyed, 'a blank txid in replace mode gets the per-row key');
        self::assertSame(['row:21', 21], $keyed[0]->boundValues);
        $convInserts = $emitted['write']->statementsContaining('INSERT INTO 202_conversion_logs');
        self::assertCount(1, $convInserts);
        self::assertNull($convInserts[0]->boundValues[1], 'blank transaction ids are stored as NULL');
    }
}
