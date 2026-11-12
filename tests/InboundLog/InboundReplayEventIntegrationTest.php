<?php

declare(strict_types=1);

namespace Tests\InboundLog;

use PHPUnit\Framework\TestCase;
use Prosper202\InboundLog\Entry;
use Prosper202\InboundLog\InboundLog;
use Prosper202\InboundLog\InboundReplay;
use Prosper202\InboundLog\Redactor;

/**
 * "Record it now" for a refused request that carries an event, on a
 * campaign that evaluates goals: the event goes to the click's goals as it
 * would have on arrival, once, on the account's own click.
 *
 * @group integration
 */
final class InboundReplayEventIntegrationTest extends TestCase
{
    use \Tests\Goals\GoalDatabase {
        setUp as private goalSetUp;
    }

    private const CLICK = 100;
    private const THEIR_CLICK = 200;
    /** After the goal was made (GoalDatabase's clock): an event is evaluated under the goals of when it arrived. */
    private const ARRIVED = 1_700_003_600;

    protected function setUp(): void
    {
        $this->goalSetUp();
        require_once __DIR__ . '/../../202-config/static-endpoint-helpers.php';
        foreach (['202_inbound_log', '202_click_latest_visits', '202_identity_observations', '202_identity_signals'] as $t) {
            self::$db->query('TRUNCATE TABLE ' . $t);
        }
        $this->campaign(12);
        $this->click(self::CLICK, 12, 1_690_000_000);
        self::fixture('INSERT INTO 202_clicks SET click_id=' . self::THEIR_CLICK . ', user_id=2, aff_campaign_id=12,'
            . ' click_payout=0, click_cpc=0, click_lead=0, click_time=1690000000');
        $this->goal(12, ['name' => 'Purchase', 'trigger' => ['event' => 'purchase'], 'value' => ['type' => 'fixed', 'amount' => '2']]);
    }

    /** @param array<string, string> $fields */
    private static function refused(string $endpoint, array $fields, ?int $click = null, ?string $match = null): int
    {
        (new InboundLog(self::$db))->append(new Entry(
            $endpoint,
            'GET',
            403,
            'unauthenticated',
            'This account accepts only authenticated postbacks and pixels.',
            1,
            $click,
            null,
            null,
            Redactor::fields($fields, $endpoint),
            'none',
            $match,
        ), self::ARRIVED);

        return (int) self::$db->insert_id;
    }

    private static function conversions(int $click = self::CLICK): int
    {
        return (int) self::$db->query("SELECT COUNT(*) FROM 202_conversion_logs WHERE click_id = $click")->fetch_row()[0];
    }

    public function testAnEventReachesItsGoalOnceHoweverOftenItIsAsked(): void
    {
        $id = self::refused('gpb', ['subid' => (string) self::CLICK, 'event' => 'purchase', 'event_id' => 'order-881']);
        $replay = new InboundReplay(self::$db);

        $first = $replay->replay(1, $id);

        self::assertSame('recorded', $first['status'], $first['message']);
        self::assertIsInt($first['conversion_id']);
        // Recorded long after it arrived, it is dated when it arrived: the event, and the conversion its goal reached.
        self::assertSame([[(string) self::ARRIVED, (string) self::ARRIVED]], self::$db->query(
            "SELECT occurred_at, received_at FROM 202_goal_events WHERE event_id = 'order-881'")->fetch_all(MYSQLI_NUM));
        self::assertSame([[(string) self::ARRIVED]], self::$db->query(
            'SELECT conv_time FROM 202_conversion_logs WHERE click_id = ' . self::CLICK)->fetch_all(MYSQLI_NUM));
        self::assertSame(1, self::conversions());
        $row = (new InboundLog(self::$db))->find(1, $id);
        self::assertSame([$first['conversion_id'], false], [$row['conversion_id'], $row['can_record']]);

        self::assertSame('already_recorded', $replay->replay(1, $id)['status']);
        // As after a failure that hid the write: the row is free again, and the event is on file.
        self::$db->query("UPDATE 202_inbound_log SET replayed_at = NULL, replay_done = 0, conversion_id = NULL WHERE inbound_id = $id");
        $again = $replay->replay(1, $id);
        self::assertSame('duplicate', $again['status'], $again['message']);
        self::assertSame(1, self::conversions());
    }

    public function testAnEventThatReachesNoGoalIsRecordedWithNoConversion(): void
    {
        $id = self::refused('gpx', ['event' => 'viewed_pricing'], self::CLICK, 'cookie');

        $answer = (new InboundReplay(self::$db))->replay(1, $id);

        self::assertSame(['recorded', null], [$answer['status'], $answer['conversion_id']], $answer['message']);
        self::assertSame(0, self::conversions());
        $row = (new InboundLog(self::$db))->find(1, $id);
        self::assertNotNull($row['recorded_later_at']);
        self::assertFalse($row['can_record']);
        self::assertSame('already_recorded', (new InboundReplay(self::$db))->replay(1, $id)['status']);
    }

    public function testAnEventOnAnotherAccountsClickIsRefused(): void
    {
        $id = self::refused('gpb', ['subid' => (string) self::THEIR_CLICK, 'event' => 'purchase', 'event_id' => 'order-882']);

        $answer = (new InboundReplay(self::$db))->replay(1, $id);

        self::assertSame('refused', $answer['status'], $answer['message']);
        self::assertSame(0, self::conversions(self::THEIR_CLICK));
        self::assertNull((new InboundLog(self::$db))->find(1, $id)['record_started_at']);
    }
}
