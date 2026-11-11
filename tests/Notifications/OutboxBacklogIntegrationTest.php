<?php

declare(strict_types=1);

namespace Tests\Notifications;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Notifications\OutboxBacklog;

/**
 * Queued traffic-source postbacks are reported as stuck only when one has
 * been due for a while and no scheduler has run since; rows waiting behind
 * a running cron job, or another account's, are not.
 *
 * @group integration
 */
final class OutboxBacklogIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const ACCOUNT = 990721;
    private const OTHER = 990722;
    private const NOW = 1790000000;

    /** @var list<array<string, mixed>>|null 202_cronjob_logs as found, put back after */
    private static ?array $cronLog = null;

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        $found = self::$db->query('SELECT id, last_execution_time FROM 202_cronjob_logs');
        self::$cronLog = $found->fetch_all(MYSQLI_ASSOC);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db === null) {
            return;
        }
        self::q('DELETE FROM 202_notification_pending WHERE user_id IN (' . self::ACCOUNT . ', ' . self::OTHER . ')');
        self::q('DELETE FROM 202_cronjob_logs');
        foreach (self::$cronLog ?? [] as $row) {
            self::row('202_cronjob_logs', ['id' => (int) $row['id'], 'last_execution_time' => (int) $row['last_execution_time']]);
        }
        self::$db->close();
        self::$db = null;
    }

    protected function setUp(): void
    {
        self::requireScratch();
        self::q('DELETE FROM 202_notification_pending');
        self::q('DELETE FROM 202_cronjob_logs');
    }

    private static function queued(int $user, int $dueAt, string $status = 'pending'): void
    {
        static $conv = 99072100;
        self::row('202_notification_pending', [
            'user_id' => $user, 'conv_id' => ++$conv, 'pixel_id' => 1, 'kind' => 'reached', 'status' => $status,
            'url' => 'https://ads.example/postback?id=' . $conv, 'next_attempt_at' => $dueAt, 'created_at' => $dueAt,
        ]);
    }

    private static function cronRan(int $at): void
    {
        self::q('REPLACE INTO 202_cronjob_logs (id, last_execution_time) VALUES (1, ' . $at . ')');
    }

    /** @return array<string, mixed> */
    private static function read(?int $user = self::ACCOUNT): array
    {
        return OutboxBacklog::read(new Connection(self::requireScratch()), self::NOW, $user);
    }

    public function testNothingQueuedOrNothingOverdueIsNotStuck(): void
    {
        self::assertSame([false, 0, null], [self::read()['stuck'], self::read()['overdue'], self::read()['warning']]);

        self::queued(self::ACCOUNT, self::NOW - 60);
        self::queued(self::ACCOUNT, self::NOW + 600);
        self::queued(self::ACCOUNT, self::NOW - 7200, 'sent');
        self::queued(self::ACCOUNT, self::NOW - 7200, 'failed');

        self::assertFalse(self::read()['stuck'], 'a postback due a minute ago is the next run\'s');
    }

    public function testOverduePostbacksWithNoCronRunAreStuckAndSayTheFix(): void
    {
        self::queued(self::ACCOUNT, self::NOW - 7200);
        self::queued(self::ACCOUNT, self::NOW - 1800);
        self::queued(self::OTHER, self::NOW - 90000);

        $mine = self::read();

        self::assertSame([true, 2, self::NOW - 7200, null], [$mine['stuck'], $mine['overdue'], $mine['oldest_due_at'], $mine['cron_last_run']]);
        self::assertStringContainsString('2 conversion postbacks', (string) $mine['warning']);
        self::assertStringContainsString('2 hours', (string) $mine['warning']);
        self::assertStringContainsString('never run', (string) $mine['warning']);
        self::assertStringContainsString('AutoCron', (string) $mine['warning']);
        self::assertSame(3, self::read(null)['overdue'], 'the install\'s own reading counts every account');

        // A cron job that stopped an hour ago is the same case.
        self::cronRan(self::NOW - 3600);
        self::assertTrue(self::read()['stuck']);
        self::assertStringContainsString('last ran 60 minutes ago', (string) self::read()['warning']);
    }

    public function testThePagesBannerAndTheApiSayIt(): void
    {
        require_once __DIR__ . '/../../202-config/functions-ui.php';
        $db = self::requireScratch();
        self::assertSame('', p202_postback_queue_notice($db, self::ACCOUNT, 'https://track.example/'), 'nothing waiting, nothing said');

        // Due long before the real clock's now, which the banner and the API read.
        self::queued(self::ACCOUNT, time() - 7200);

        $banner = p202_postback_queue_notice($db, self::ACCOUNT, 'https://track.example/');
        self::assertStringContainsString('1 conversion postback to a traffic source has not been sent', $banner);
        self::assertStringContainsString('href="https://track.example/202-account/administration.php#autocron"', $banner);
        self::assertSame('', p202_postback_queue_notice($db, self::OTHER, 'https://track.example/'), 'another account\'s queue is not this one\'s');

        $api = (new \Api\V3\Controllers\SystemController($db))->cronStatus()['data']['traffic_source_postbacks'];
        self::assertSame([true, 1], [$api['stuck'], $api['overdue']]);
        self::assertStringContainsString('AutoCron', (string) $api['warning']);
    }

    public function testRowsWaitingWhileTheCronJobRunsAreNotReported(): void
    {
        self::queued(self::ACCOUNT, self::NOW - 7200);
        self::cronRan(self::NOW - 30);

        $backlog = self::read();

        self::assertSame([false, 1, null], [$backlog['stuck'], $backlog['overdue'], $backlog['warning']]);
    }
}
