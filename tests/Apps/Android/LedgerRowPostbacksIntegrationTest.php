<?php

declare(strict_types=1);

namespace Tests\Apps\Android;

use PHPUnit\Framework\TestCase;
use Prosper202\Conversion\MysqlConversionRepository;
use Prosper202\Database\Connection;
use Prosper202\Notifications\NotificationOutbox;

/**
 * What a traffic source is told about a ledger row that is not the goal
 * engine's to settle, against a real server (Codex on PR #157):
 *
 * - a postback-endpoint conversion (gpb.php, upx.php: record() with
 *   notify_traffic_source) queues its server postbacks in the row's own
 *   transaction, so a send that dies after the commit is retried by the
 *   worker instead of being lost; a reversal queues nothing;
 * - an operator's delete of a row — a goal/install row included — settles
 *   its postbacks the way the engine settles a retirement: not yet
 *   attempted is cancelled, may have gone out is retracted; and a later
 *   retirement of the same row by the engine does not retract it twice.
 *
 * @group integration
 */
final class LedgerRowPostbacksIntegrationTest extends TestCase
{
    use AndroidDatabase;

    private function repo(): MysqlConversionRepository
    {
        return new MysqlConversionRepository(new Connection(self::$db));
    }

    /** @return list<array{kind: string, status: string, url: string}> */
    private static function notifications(int $convId): array
    {
        return self::$db->query(
            "SELECT kind, status, url FROM 202_notification_pending WHERE conv_id = $convId ORDER BY notification_id"
        )->fetch_all(MYSQLI_ASSOC);
    }

    public function testAPostbackConversionQueuesItsServerPostbacksWithTheRow(): void
    {
        $this->click(100);
        $sale = $this->repo()->record(1, [
            'click_id' => 100, 'payout' => '4', 'transaction_id' => 'T-1', 'source' => 'postback', 'notify_traffic_source' => true,
        ]);
        self::assertSame(
            [['kind' => 'reached', 'status' => 'pending', 'url' => 'https://ts.example/pb?sub=100&goal=&v=4.00&tx=T-1&p=4.00']],
            self::notifications((int) $sale['convId']),
            'one row per server pixel URL, none for the image pixel, resolved with this row\'s amount and id'
        );

        $rev = $this->repo()->record(1, [
            'click_id' => 100, 'transaction_id' => 'T-1', 'reversal' => true, 'source' => 'postback', 'notify_traffic_source' => true,
        ]);
        self::assertSame([], self::notifications((int) $rev['convId']), 'a reversal is not announced as a conversion');

        $replay = $this->repo()->record(1, [
            'click_id' => 100, 'payout' => '4', 'transaction_id' => 'T-1', 'source' => 'postback', 'notify_traffic_source' => true,
        ]);
        self::assertTrue($replay['duplicate']);
        self::assertCount(1, self::notifications((int) $sale['convId']), 'a replay queues nothing more');

        // Without the flag (every other writer), nothing is queued.
        $this->click(101);
        $api = $this->repo()->record(1, ['click_id' => 101, 'payout' => '3', 'transaction_id' => 'A-1', 'source' => 'api']);
        self::assertSame([], self::notifications((int) $api['convId']));
    }

    public function testDeletingAConversionCancelsWhatDidNotGoOutAndRetractsWhatMayHave(): void
    {
        $this->click(100);
        $this->click(101);
        $unsent = (int) $this->repo()->record(1, [
            'click_id' => 100, 'payout' => '4', 'transaction_id' => 'U-1', 'source' => 'postback', 'notify_traffic_source' => true,
        ])['convId'];
        $sent = (int) $this->repo()->record(1, [
            'click_id' => 101, 'payout' => '4', 'transaction_id' => 'S-1', 'source' => 'postback', 'notify_traffic_source' => true,
        ])['convId'];
        self::$db->query("UPDATE 202_notification_pending SET status = 'sent', attempts = 1, sent_at = 1 WHERE conv_id = $sent");

        $this->repo()->softDelete($unsent, 1);
        $this->repo()->softDelete($sent, 1);

        self::assertSame(['cancelled'], array_column(self::notifications($unsent), 'status'), 'never sent: cancelled, and nothing retracted');
        self::assertSame(
            [['reached', 'sent'], ['retraction', 'suppressed']],
            array_map(static fn (array $n): array => [$n['kind'], $n['status']], self::notifications($sent)),
            'sent: a retraction, suppressed here because no correction URL is configured'
        );

        // The engine retiring the same row afterwards does not tell the
        // network "0" a second time.
        (new NotificationOutbox(new Connection(self::$db)))->onReplaced(1, $sent, null);
        self::assertCount(2, self::notifications($sent));
    }

    /** The Codex example itself: an install's goal row, deleted by an operator through the API. */
    public function testDeletingAnInstallGoalRowSettlesItsPostback(): void
    {
        $this->click(100);
        $installGoal = $this->goals->ensureBuiltinInstallGoal(1, 5, 1);
        $this->goals->attach(1, 30, $installGoal, null, true, 1);
        self::assertSame('attributed', $this->install(self::body('00000000-0000-4000-8000-0000000000b1', 'p202=' . self::tokenFor(100)))['body']['data']['match']);
        $row = self::$db->query("SELECT conv_id FROM 202_conversion_logs WHERE click_id = 100 AND source = 'app_install' AND deleted = 0")->fetch_assoc();
        self::assertNotNull($row, 'the install was paid through a goal row');
        $convId = (int) $row['conv_id'];
        self::assertSame(['pending'], array_column(self::notifications($convId), 'status'), 'its postback is queued');

        $api = new \Api\V3\Controllers\ConversionsController(self::$db, 1);
        $preview = $api->deletePreview($convId)['data'];
        self::assertSame([
            ['resource' => 'notifications', 'count' => 1, 'effect' => 'postback_cancelled'],
            ['resource' => 'notifications', 'count' => 0, 'effect' => 'postback_retracted'],
        ], $preview['cascade'], 'the dry run says what the delete will do to its postback');
        self::assertStringContainsString('1 not yet sent is cancelled', $preview['note']);
        self::assertSame(['pending'], array_column(self::notifications($convId), 'status'), 'and does not do it');

        $api->delete($convId);

        self::assertSame(['cancelled'], array_column(self::notifications($convId), 'status'), 'and cancelled with the row');
        $sent = (new NotificationOutbox(new Connection(self::$db), null, static fn (string $u): bool => true))->sendDue(10);
        self::assertSame(0, $sent['sent'], 'so the worker sends nothing for a conversion the ledger no longer counts');
    }
}
