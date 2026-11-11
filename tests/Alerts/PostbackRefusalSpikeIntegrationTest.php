<?php

declare(strict_types=1);

namespace Tests\Alerts;

use PHPUnit\Framework\TestCase;
use Prosper202\Alerts\AccountActivity;
use Prosper202\Alerts\AlertKind;
use Prosper202\Alerts\AlertRun;
use Prosper202\Alerts\AlertSettings;
use Prosper202\Alerts\PostbackRefusalSpikeDetector;
use Prosper202\Database\Connection;
use Tests\Report\ScratchReportDatabase;

/**
 * The postback-refusal jump (7V7K) read from a real 202_inbound_log: refused
 * requests in the last hour against the account's usual rate, the account's
 * own rows only.
 *
 * @group integration
 */
final class PostbackRefusalSpikeIntegrationTest extends TestCase
{
    use ScratchReportDatabase;

    private const OWNER = 7821;
    private const OTHER = 7822;
    private const NOW = 1_800_000_000;

    public static function setUpBeforeClass(): void
    {
        self::connectScratch();
    }

    protected function setUp(): void
    {
        self::requireScratch();
        self::q('DELETE FROM 202_inbound_log WHERE user_id IN (' . self::OWNER . ', ' . self::OTHER . ', 0)');
    }

    protected function tearDown(): void
    {
        if (self::$db !== null) {
            self::q('DELETE FROM 202_inbound_log WHERE user_id IN (' . self::OWNER . ', ' . self::OTHER . ', 0)');
        }
    }

    /** $count rows for $user, $secondsAgo before NOW each (spread over the given span). */
    private static function log(int $user, int $count, int $from, int $to, string $outcome, int $accepted = 0): void
    {
        for ($i = 0; $i < $count; $i++) {
            $at = self::NOW - $from + (int) (($from - $to) * $i / max(1, $count));
            self::q(sprintf(
                "INSERT INTO 202_inbound_log (user_id, received_at, endpoint, method, http_status, outcome, accepted)"
                . " VALUES (%d, %d, 'pb', 'GET', %d, '%s', %d)",
                $user,
                $at,
                $accepted === 1 ? 200 : 400,
                $outcome,
                $accepted
            ));
        }
    }

    /** @return list<\Prosper202\Alerts\Alert> */
    private static function detect(int $user): array
    {
        $activity = new AccountActivity($user, self::NOW, 'UTC', self::NOW - self::NOW % 86400, 'USD', []);

        return (new PostbackRefusalSpikeDetector())->detect(new Connection(self::requireScratch()), $activity, AlertSettings::defaults());
    }

    public function testAJumpInRefusalsIsAlertedWithItsReasons(): void
    {
        self::log(self::OWNER, 24, 25 * 3600, 3601, 'bad_signature');      // about one an hour, usually
        self::log(self::OWNER, 30, 3500, 60, 'bad_signature');            // the last hour
        self::log(self::OWNER, 12, 3500, 60, 'unknown_click');
        self::log(self::OWNER, 30, 3500, 60, 'duplicate');
        self::log(self::OWNER, 30, 3500, 60, 'test');
        self::log(self::OWNER, 30, 3500, 60, 'ignored');
        self::log(self::OWNER, 200, 3500, 60, 'recorded', 1);              // accepted ones are not refusals
        self::log(0, 500, 3500, 60, 'unknown_click');                      // unattributed: nobody's

        $alerts = self::detect(self::OWNER);
        self::assertCount(1, $alerts);
        $alert = $alerts[0];
        self::assertSame(AlertKind::PostbackRefusalsSpiked, $alert->kind);
        self::assertSame(0, $alert->campaignId, 'about the account, not a campaign');
        self::assertSame(42, $alert->facts['refused_last_hour']);
        self::assertSame(1.0, $alert->facts['usual_refused_per_hour']);
        self::assertSame('{"bad_signature":30,"unknown_click":12}', $alert->facts['top_refusals']);
        self::assertStringStartsWith('42 inbound postbacks and pixels were refused in the last hour, against about 1.0 an hour', $alert->message);
        self::assertStringContainsString('Most were: bad signature (30), unknown click (12)', $alert->message);
        self::assertSame(intdiv(self::NOW, 86400), $alert->incidentKey, 'once a day while it lasts');
        self::assertSame([], self::detect(self::OTHER), 'another account\'s log is not this one\'s');
    }

    public function testASteadyRateOfRefusalsIsNotAJump(): void
    {
        self::log(self::OWNER, 24 * 20, 25 * 3600, 3601, 'campaign_mismatch'); // 20 an hour all day
        self::log(self::OWNER, 25, 3500, 60, 'campaign_mismatch');
        self::assertSame([], self::detect(self::OWNER), 'the positive control below shows the same hour alerts against a quiet day');

        self::q('DELETE FROM 202_inbound_log WHERE user_id = ' . self::OWNER . ' AND received_at < ' . (self::NOW - 3600));
        self::assertCount(1, self::detect(self::OWNER));
    }

    public function testSuccessfulUnbackedNotificationsAreNotRefusals(): void
    {
        foreach (['duplicate', 'test', 'ignored'] as $outcome) {
            self::log(self::OWNER, 20, 3500, 60, $outcome);
        }
        self::assertSame([], self::detect(self::OWNER));
    }

    public function testAnIncompleteBaselineAtTheRetentionLimitDoesNotLookLikeASpike(): void
    {
        // The log's 5,000 newest unbacked rows after pruning a steady 900/hour.
        $rows = [];
        for ($i = 0; $i < 5000; $i++) {
            $at = self::NOW - (int) (20000 - $i * 4);
            $rows[] = '(' . self::OWNER . ", $at, 'pb', 'GET', 400, 'bad_signature', 0)";
        }
        self::q('INSERT INTO 202_inbound_log (user_id, received_at, endpoint, method, http_status, outcome, accepted) VALUES '
            . implode(',', $rows));
        self::assertSame([], self::detect(self::OWNER));

        // With room in the log, an actual increase over a quiet baseline alerts.
        self::q('DELETE FROM 202_inbound_log WHERE user_id = ' . self::OWNER . ' AND received_at < ' . (self::NOW - 3600));
        self::assertCount(1, self::detect(self::OWNER));
    }

    public function testAFewRefusalsAreNotAJump(): void
    {
        self::log(self::OWNER, PostbackRefusalSpikeDetector::MIN_REFUSED - 1, 3500, 60, 'bad_signature');
        self::assertSame([], self::detect(self::OWNER));
        self::log(self::OWNER, 1, 50, 50, 'bad_signature');
        self::assertCount(1, self::detect(self::OWNER), 'at the minimum it is');
    }

    public function testItIsAmongTheDetectorsTheHourlyRunUses(): void
    {
        self::assertContains(
            PostbackRefusalSpikeDetector::class,
            array_map(static fn (object $d): string => $d::class, AlertRun::detectors())
        );
    }
}
