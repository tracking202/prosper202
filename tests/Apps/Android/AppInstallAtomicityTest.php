<?php

declare(strict_types=1);

namespace Tests\Apps\Android;

use PHPUnit\Framework\TestCase;

/**
 * Everything durable an install causes commits together or not at all
 * (plan §5.2 step 3, CLAUDE.md #13): a failure in the classification's
 * write, in the ledger's record() or in the notification enqueue leaves no
 * install row behind, so the SDK's retry does the whole job again — and
 * records the conversion, the MTA outbox row and the notification exactly
 * once.
 *
 * The failures are planted in the database itself (a trigger that SIGNALs
 * on the write), so the path under test is the real one end to end — no
 * seam is replaced by a fake (CLAUDE.md #9).
 *
 * @group integration
 */
final class AppInstallAtomicityTest extends TestCase
{
    use AndroidDatabase;

    /** @return iterable<string, array{string}> */
    public static function plants(): iterable
    {
        yield 'classification (the install row\'s settle)' => [
            "CREATE TRIGGER p202_plant BEFORE UPDATE ON 202_app_installs FOR EACH ROW
             SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'planted: classification'",
        ];
        yield 'record() (the ledger row)' => [
            "CREATE TRIGGER p202_plant BEFORE INSERT ON 202_conversion_logs FOR EACH ROW
             SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'planted: record'",
        ];
        yield 'the notification enqueue' => [
            "CREATE TRIGGER p202_plant BEFORE INSERT ON 202_notification_pending FOR EACH ROW
             SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'planted: enqueue'",
        ];
        yield 'the MTA outbox' => [
            "CREATE TRIGGER p202_plant BEFORE INSERT ON 202_attribution_pending FOR EACH ROW
             SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'planted: mta outbox'",
        ];
    }

    /** @dataProvider plants */
    public function testAFailureAnywhereStoresNothingAndTheRetryRecordsOnce(string $trigger): void
    {
        $this->click(100);
        $body = self::body('00000000-0000-4000-8000-00000000000a', 'p202=' . self::tokenFor(100));
        self::fixture($trigger);
        $planted = self::$db->query("SHOW TRIGGERS LIKE '%'")->fetch_all(MYSQLI_ASSOC);
        self::assertCount(1, $planted, 'the plant landed');

        $failed = null;
        try {
            $this->install($body);
        } catch (\Throwable $e) {
            $failed = $e;
        }
        self::assertNotNull($failed, 'the planted failure reached the intake');
        self::assertStringContainsString('planted', $failed->getMessage() . ($failed->getPrevious()?->getMessage() ?? ''));
        self::assertSame(0, self::rows('202_app_installs'), 'no install row outlives the failure');
        self::assertSame(0, self::rows('202_conversion_logs'));
        self::assertSame(0, self::rows('202_notification_pending'));
        self::assertSame(0, self::rows('202_attribution_pending'));
        self::assertSame(0, self::rows('202_goal_outcomes'));
        self::assertSame(['lead' => 0, 'payout' => '2.50000'], self::clickValue(100));

        self::fixture('DROP TRIGGER p202_plant');
        $retry = $this->install($body);
        self::assertSame(200, $retry['status']);
        self::assertSame('attributed', $retry['body']['data']['match']);
        self::assertFalse($retry['body']['data']['duplicate'], 'the retry does the whole job, not a duplicate answer');
        self::assertSame(1, self::rows('202_app_installs'));
        self::assertCount(1, self::ledger(100));
        self::assertSame(1, self::rows('202_notification_pending'));
        self::assertSame(1, self::rows('202_attribution_pending'));
        self::assertNotNull(self::installRow('00000000-0000-4000-8000-00000000000a')['conversion_id']);

        $again = $this->install($body);
        self::assertTrue($again['body']['data']['duplicate']);
        self::assertCount(1, self::ledger(100));
        self::assertSame(1, self::rows('202_notification_pending'));
    }

    public function testASettleThatFailsLeavesTheInstallPendingForTheNextRun(): void
    {
        $uuid = '00000000-0000-4000-8000-00000000000b';
        $this->install(self::body($uuid, 'p202=' . self::tokenFor(700)));
        self::assertSame('pending_click', self::installRow($uuid)['match_state']);
        $this->click(700);
        self::fixture("CREATE TRIGGER p202_plant BEFORE INSERT ON 202_conversion_logs FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'planted: record'");
        // The settler logs the failure it survives; keep it out of the run's output.
        $log = ini_set('error_log', sys_get_temp_dir() . '/p202-android-settle-it.log');
        $settler = new \Api\V3\Apps\Android\PendingClickSettler(self::$db, fn (): int => $this->clock);
        try {
            $run = $settler->run();
        } finally {
            ini_set('error_log', $log === false ? '' : $log);
        }
        self::assertSame(1, $run['failed']);
        self::assertSame('pending_click', self::installRow($uuid)['match_state'], 'rolled back to pending');
        self::assertSame(0, self::rows('202_conversion_logs'));
        self::fixture('DROP TRIGGER p202_plant');
        self::assertSame(['attributed' => 1], $settler->run()['settled']);
        self::assertCount(1, self::ledger(700));
        self::assertSame(1, self::rows('202_notification_pending'));
    }
}
