<?php

declare(strict_types=1);

namespace Tests\Apps\Android;

use Prosper202\Database\Connection;
use Prosper202\Notifications\CorrectionUrls;
use Prosper202\Notifications\NotificationOutbox;
use Tests\TestCase;

/**
 * A traffic source's correction URL (plan §5.5, PR 11): an outcome whose
 * postback already went out and is then replaced is told to the network
 * through the pixel's correction URL — queued, filled, sent by the worker —
 * and stays `suppressed` for a pixel without one (the default, pinned by
 * InstallIntakeIntegrationTest::testAReplacedOutcomeIsAnnouncedOnce).
 *
 * @group integration
 */
final class CorrectionUrlIntegrationTest extends TestCase
{
    use AndroidDatabase;

    private const U1 = '00000000-0000-4000-8000-0000000000c1';

    public function testASentOutcomeThatIsReplacedIsCorrectedThroughTheCorrectionUrl(): void
    {
        (new CorrectionUrls(new Connection(self::$db)))->set(1, 90,
            'https://ts.example/correct?sub=[[subid]]&v=[[p202_goal_value]]&was=[[p202_previous_value]]&orig=[[p202_original_conv_id]]&k=[[p202_notification]]', 1);
        self::fixture('UPDATE 202_app_registrations SET trust_client_revenue = 1 WHERE registration_id = 5');
        $this->click(100);
        $this->campaignGoal(30, ['name' => 'Second purchase', 'trigger' => ['event' => 'purchase'], 'threshold' => ['count' => 2],
            'value' => ['type' => 'from_property', 'prop' => '$revenue']]);
        $this->install(self::body(self::U1, 'p202=' . self::tokenFor(100)));
        $t = self::CLICK_TIME + 100;
        $this->events(self::U1, [['event_id' => 'p1', 'name' => 'purchase', 'occurred_at' => $t + 10, 'revenue' => 5]]);
        $this->events(self::U1, [['event_id' => 'p2', 'name' => 'purchase', 'occurred_at' => $t + 20, 'revenue' => 10]]);
        $outbox = new NotificationOutbox(new Connection(self::$db), fn (): int => $this->clock, function (string $url): bool {
            $this->sent[] = $url;

            return true;
        });
        $outbox->sendDue(10);
        $announced = (int) self::$db->query("SELECT conv_id FROM 202_notification_pending WHERE kind = 'reached' AND status = 'sent' AND url LIKE '%Second%'")->fetch_assoc()['conv_id'];

        // A late, earlier purchase makes the $5 one the second: the network
        // was told $10, so it is told the correction.
        $this->events(self::U1, [['event_id' => 'p0', 'name' => 'purchase', 'occurred_at' => $t + 5, 'revenue' => 1]]);
        $corrections = array_values(array_filter(self::outbox(), static fn (array $r): bool => $r['kind'] === 'correction'));
        self::assertCount(1, $corrections);
        self::assertSame('pending', $corrections[0]['status'], 'queued, not suppressed: the pixel has a correction URL');
        self::assertSame(
            'https://ts.example/correct?sub=100&v=5.00&was=10.00&orig=' . $announced . '&k=correction',
            $corrections[0]['url']
        );
        self::assertNull($corrections[0]['last_error']);
        self::assertSame(0, self::rows('202_notification_pending', "kind = 'reached' AND status = 'pending'"), 'the replacement is not announced as reached again');

        $this->sent = [];
        self::assertSame(['sent' => 1, 'failed' => 0, 'retrying' => 0], $outbox->sendDue(10));
        self::assertSame([$corrections[0]['url']], $this->sent, 'the worker sends the correction once');

        // The operator's read: every postback the app's goals queued, with
        // the summary by status.
        $read = (new \Api\V3\Controllers\AppNotificationsController(self::$db, 1))->list(['registration_id' => '5']);
        self::assertSame(['pending' => 0, 'sent' => 2, 'failed' => 0, 'cancelled' => 1, 'suppressed' => 0], $read['meta']['summary'],
            'the $10 second purchase and its correction sent; the $5 replacement\'s own reached cancelled (the install is unpaid here: the campaign lists goals, not the install)');
        self::assertSame(3, $read['pagination']['total']);
        self::assertSame(['correction', 'sent', 5, 'Summit'], [$read['data'][0]['kind'], $read['data'][0]['status'], $read['data'][0]['registration_id'], $read['data'][0]['app_name']]);
        self::assertSame(1, count((new \Api\V3\Controllers\AppNotificationsController(self::$db, 1))->list(['status' => 'cancelled'])['data']));
        self::assertSame([], (new \Api\V3\Controllers\AppNotificationsController(self::$db, 2))->list([])['data'], 'another user sees none of them');
    }

    public function testAnotherUsersPixelNeverTakesTheirCorrectionUrl(): void
    {
        $urls = new CorrectionUrls(new Connection(self::$db));
        $urls->set(1, 90, 'https://mine.example/c', 1);
        $urls->set(2, 90, 'https://theirs.example/c', 2);
        self::assertSame('https://mine.example/c', $urls->forPixel(1, 90), 'an upsert by another user leaves the owner\'s URL alone');
        self::assertNull($urls->forPixel(2, 90));
        $urls->set(1, 90, '', 3);
        self::assertNull($urls->forPixel(1, 90), 'an empty URL turns corrections off');
    }

    /**
     * The outbox's resolver answers by pixel and destination, and a row
     * counts only while its user owns the pixel through the pixel's
     * traffic-source account.
     */
    public function testTheResolverIsPositionalAndOwnerBound(): void
    {
        $conn = new Connection(self::$db);
        $resolve = CorrectionUrls::resolver($conn);
        (new CorrectionUrls($conn))->set(2, 90, 'https://stranger.example/c', 1);
        self::assertNull($resolve(90, 0), 'a row by a user who does not own the pixel carries nothing');
        self::fixture('DELETE FROM 202_notification_correction_urls');
        (new CorrectionUrls($conn))->set(1, 90, 'https://a.example/c https://b.example/c', 1);
        self::assertSame(['https://a.example/c', 'https://b.example/c', null], [$resolve(90, 0), $resolve(90, 1), $resolve(90, 2)]);
        self::assertNull($resolve(91, 0), 'a pixel with none has none');
    }

    /** @return iterable<string, array{0: string, 1: bool}> */
    public static function urls(): iterable
    {
        yield 'https' => ['https://network.example/c?tx=[[transactionid]]', true];
        yield 'http' => ['http://network.example/c', true];
        yield 'empty turns it off' => ['', true];
        yield 'no scheme' => ['network.example/c', false];
        yield 'another scheme' => ['file:///etc/passwd', false];
        yield 'two URLs, one per pixel URL' => ['https://a.example/c https://b.example/c', true];
        yield 'a tab inside one' => ["https://a.example/c\thttps://b.example/c", false];
        yield 'a host-less URL' => ['https:///c', false];
        yield 'too long' => ['https://a.example/' . str_repeat('x', 2100), false];
    }

    /** @dataProvider urls */
    public function testWhatACorrectionUrlMayBe(string $url, bool $usable): void
    {
        self::assertSame($usable, CorrectionUrls::problem($url) === null, (string) CorrectionUrls::problem($url));
    }

    public function testCorrectionUrlsBeyondThePixelsUrlsAreRefusedByCount(): void
    {
        self::assertNull(CorrectionUrls::problem('https://a.example/c', 'https://a.example/pb https://b.example/pb'), 'fewer is allowed: the later endpoints go uncorrected');
        self::assertNull(CorrectionUrls::problem('https://a.example/c  https://b.example/c', 'https://a.example/pb https://b.example/pb'), 'a doubled space is not a URL');
        self::assertStringContainsString('matched by position', (string) CorrectionUrls::problem('https://a.example/c https://b.example/c', 'https://a.example/pb'));
    }

    /**
     * A pixel whose code holds two URLs has two destinations, each told of
     * an outcome on its own row; a correction goes to the correction URL at
     * the same position, and a destination with none is suppressed, saying
     * why — never sent to the other endpoint's correction URL.
     */
    public function testEachDestinationIsCorrectedAtItsOwnPositionOnly(): void
    {
        self::fixture("UPDATE 202_ppc_account_pixels SET pixel_code = CONCAT(pixel_code, ' https://second.example/pb?v=[[p202_goal_value]]') WHERE pixel_id = 90");
        (new CorrectionUrls(new Connection(self::$db)))->set(1, 90, 'https://ts.example/correct?v=[[p202_goal_value]]&was=[[p202_previous_value]]', 1);
        self::fixture('UPDATE 202_app_registrations SET trust_client_revenue = 1 WHERE registration_id = 5');
        $this->click(100);
        $this->campaignGoal(30, ['name' => 'Second purchase', 'trigger' => ['event' => 'purchase'], 'threshold' => ['count' => 2],
            'value' => ['type' => 'from_property', 'prop' => '$revenue']]);
        $this->install(self::body(self::U1, 'p202=' . self::tokenFor(100)));
        $t = self::CLICK_TIME + 100;
        $this->events(self::U1, [['event_id' => 'p1', 'name' => 'purchase', 'occurred_at' => $t + 10, 'revenue' => 5]]);
        $this->events(self::U1, [['event_id' => 'p2', 'name' => 'purchase', 'occurred_at' => $t + 20, 'revenue' => 10]]);
        $outbox = new NotificationOutbox(new Connection(self::$db), fn (): int => $this->clock, fn (string $url): bool => true);
        self::assertSame(2, $outbox->sendDue(10)['sent'], 'the second purchase went to both URLs');

        $this->events(self::U1, [['event_id' => 'p0', 'name' => 'purchase', 'occurred_at' => $t + 5, 'revenue' => 1]]);
        $corrections = [];
        foreach (self::outbox() as $row) {
            if ($row['kind'] === 'correction') {
                $corrections[(int) $row['destination']] = $row;
            }
        }
        ksort($corrections);
        self::assertSame([0, 1], array_keys($corrections), 'one correction per destination that heard it');
        self::assertSame(['pending', 'https://ts.example/correct?v=5.00&was=10.00'], [$corrections[0]['status'], $corrections[0]['url']],
            'the first URL is corrected at the first correction URL');
        self::assertSame(['suppressed', ''], [$corrections[1]['status'], $corrections[1]['url']],
            'the second has no correction URL at its position, so nothing is sent for it');
        self::assertStringContainsString('no correction URL is configured', (string) $corrections[1]['last_error']);
    }
}
