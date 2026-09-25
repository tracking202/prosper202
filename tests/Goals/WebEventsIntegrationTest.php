<?php

declare(strict_types=1);

namespace Tests\Goals;

use PHPUnit\Framework\TestCase;
use Prosper202\Goals\GoalDefinition;
use Prosper202\Goals\GoalEngine;
use Prosper202\Goals\GoalEngineException;
use Prosper202\Goals\GoalEvent;
use Prosper202\Goals\GoalSubject;
use Prosper202\Goals\OutcomeNotifier;
use Prosper202\Goals\TrafficSourceNotifier;
use Prosper202\Goals\WebEvents;
use Prosper202\Identity\IdentityGraph;
use Prosper202\Identity\IdentityKeys;
use Prosper202\Identity\IdentitySignal;
use Prosper202\Identity\SignalType;
use Prosper202\Notifications\NotificationOutbox;

/**
 * Web events against a real database (plan §2.2, §5.5): what the engine
 * tells the traffic source about the outcomes it writes — queued in the
 * notification outbox with the conversion and sent by its worker (plan
 * §5.8, §5.10), which these tests run with a recording sender — how a
 * server-clocked retry is recognised, and which click a landing page's
 * visitor id names.
 *
 * @group integration
 */
final class WebEventsIntegrationTest extends TestCase
{
    use GoalDatabase {
        setUp as private goalSetUp;
    }

    private const T = 1_690_000_000;
    private const PPC = 77;

    /** @var list<string> */
    private array $fetched = [];

    /** A URL prefix the recording sender answers as refused (null: every URL is accepted). */
    private ?string $refusing = null;

    protected function setUp(): void
    {
        $this->goalSetUp();
        foreach (['202_ppc_account_pixels', '202_landing_pages', '202_identity_observations', '202_identity_signals', '202_identity_keys', '202_goals', '202_notification_pending'] as $t) {
            self::$db->query('TRUNCATE TABLE ' . $t);
        }
        $this->fetched = [];
        $this->refusing = null;
    }

    private function notifier(bool $browser = false): TrafficSourceNotifier
    {
        return new TrafficSourceNotifier($this->conn, $browser);
    }

    /** The outbox, with a sender that records what it sends and says it arrived. */
    private function outbox(): NotificationOutbox
    {
        return new NotificationOutbox($this->conn, fn (): int => $this->clock, function (string $url): bool {
            $this->fetched[] = $url;

            return $this->refusing === null || !str_starts_with($url, $this->refusing);
        });
    }

    private function engineWith(?OutcomeNotifier $notifier): GoalEngine
    {
        return new GoalEngine($this->conn, $this->goals, null, fn (): int => $this->clock, $notifier, $this->outbox());
    }

    /** Run the worker's send: what is queued and due goes out. */
    private function deliver(): array
    {
        return $this->outbox()->sendDue(100);
    }

    /** @param list<GoalEvent> $events */
    private function send(GoalEngine $engine, int $clickId, array $events, bool $advance = true): array
    {
        if ($advance) {
            $this->clock += 10;
        }
        $stamped = array_map(fn (GoalEvent $e): GoalEvent => new GoalEvent(
            $e->eventId, $e->name, $e->occurredAt, $this->clock, $e->properties, $e->revenue, $e->revenueTrusted, $e->transactionId, false, $e->clockedByServer
        ), $events);

        return $engine->ingest(1, $engine->clickSubject(1, $clickId), $stamped);
    }

    private function pixels(): void
    {
        self::fixture("UPDATE 202_clicks SET ppc_account_id=" . self::PPC);
        self::fixture("INSERT INTO 202_ppc_account_pixels (ppc_account_id, pixel_code, pixel_type_id) VALUES
            (" . self::PPC . ", 'https://s2s.test/pb?g=[[p202_goal]]&id=[[p202_goal_id]]&v=[[p202_goal_value]]&p=[[payout]]&tx=[[transactionid]]&s=[[subid]]', 4),
            (" . self::PPC . ", 'https://img.test/px?g=[[p202_goal]]', 1)");
    }

    // ─── What the traffic source is told ────────────────────────────

    public function testTheFirstPayableOutcomeIsSentOnceWithTheGoalTokens(): void
    {
        $this->campaign(7, 'accumulate');
        $this->click(100, 7);
        $this->pixels();
        $signup = $this->goal(7, ['name' => 'Sign up', 'trigger' => ['event' => 'signup'], 'value' => ['type' => 'fixed', 'amount' => '1.5']]);
        $engine = $this->engineWith($this->notifier());

        $first = $this->send($engine, 100, [$this->event('e1', 'signup', self::T, [], null, false, 'NET 9')]);
        self::assertSame('reached', $first['outcomes'][0]['kind']);
        self::assertSame('queued', $first['notifications'][0]['status']);
        self::assertSame(1, $first['notifications'][0]['queued'], 'the server postback waits in the outbox, committed with the conversion');
        self::assertSame(1, $first['notifications'][0]['browser_skipped'], 'no browser on this path: the image pixel is counted, not rendered');
        self::assertSame([], $this->fetched, 'the request itself sends nothing');
        self::assertSame(['sent' => 1, 'failed' => 0, 'retrying' => 0], $this->deliver());
        self::assertSame(['https://s2s.test/pb?g=Sign%20up&id=' . $signup . '&v=1.50&p=1.50&tx=NET%209&s=100'], $this->fetched);

        $again = $this->send($engine, 100, [$this->event('e1', 'signup', self::T, [], null, false, 'NET 9')]);
        self::assertSame(['e1'], $again['duplicates']);
        self::assertSame([], $again['outcomes']);
        self::assertSame([], $again['notifications']);
        $this->deliver();
        self::assertCount(1, $this->fetched, 'a duplicate is never announced again');
    }

    public function testAGoalWithoutATransactionIdSendsItsLedgerKey(): void
    {
        $this->campaign(7, 'accumulate');
        $this->click(100, 7);
        $this->pixels();
        $g = $this->goal(7, ['name' => 'S', 'trigger' => ['event' => 's'], 'value' => ['type' => 'fixed', 'amount' => 2]]);
        $this->send($this->engineWith($this->notifier()), 100, [$this->event('x', 's', self::T)]);
        $this->deliver();
        self::assertStringContainsString('&tx=goal%3A' . $g . '%3A1%3A1%3Ax&', $this->fetched[0]);
    }

    public function testKindsForUnpaidOffAndBrowserPaths(): void
    {
        $this->campaign(7, 'accumulate');
        $this->click(100, 7);
        $this->pixels();
        $this->goal(7, ['name' => 'Tracked', 'trigger' => ['event' => 'view']]);
        $quiet = $this->goal(7, ['name' => 'Quiet', 'trigger' => ['event' => 'quiet'], 'value' => ['type' => 'fixed', 'amount' => 3]]);
        $this->goals->attach(1, 7, $quiet, null, false, $this->clock);
        $this->goal(7, ['name' => 'Loud', 'trigger' => ['event' => 'loud'], 'value' => ['type' => 'fixed', 'amount' => 4]]);
        $notifier = $this->notifier(true);
        $result = $this->send($this->engineWith($notifier), 100, [
            $this->event('a', 'view', self::T), $this->event('b', 'quiet', self::T + 1), $this->event('c', 'loud', self::T + 2),
        ]);

        self::assertSame(['none', 'off', 'reached'], array_column($result['outcomes'], 'kind'));
        self::assertSame(['not_sent', 'not_sent', 'queued'], array_column($result['notifications'], 'status'));
        $this->deliver();
        self::assertCount(1, $this->fetched);
        self::assertStringContainsString("<img src='https://img.test/px?g=Loud'", $notifier->markup(), 'a browser path renders the image pixel');
    }

    public function testAReplaysReplacementIsSuppressedNotResent(): void
    {
        $this->campaign(7, 'accumulate');
        $this->click(100, 7);
        $this->pixels();
        $this->goal(7, ['name' => 'Buy', 'trigger' => ['event' => 'buy'], 'value' => ['type' => 'from_property']]);
        $engine = $this->engineWith($this->notifier());

        $this->send($engine, 100, [$this->event('late', 'buy', self::T + 100, [], 10, true)]);
        $this->deliver();
        self::assertCount(1, $this->fetched);
        $replay = $this->send($engine, 100, [$this->event('early', 'buy', self::T, [], 1, true)]);

        self::assertTrue($replay['replayed']);
        self::assertSame([['kind' => 'suppressed', 'reason' => 'replacement']], array_map(
            static fn (array $o): array => ['kind' => $o['kind'], 'reason' => $o['reason']],
            $replay['outcomes']
        ));
        self::assertSame('not_sent', $replay['notifications'][0]['status']);
        $this->deliver();
        self::assertCount(1, $this->fetched, 'the network heard the first value and is not told a second one');
        self::assertSame(['reached:sent', 'reached:cancelled', 'correction:suppressed'], self::outboxRows(),
            'the replacement\'s postback is cancelled and the correction that cannot be sent is recorded');
    }

    public function testAReplacementOfAnOutcomeNotYetSentIsTheOneAnnounced(): void
    {
        // The worker has not run between the two requests: the network has
        // heard nothing, so it hears the corrected value, once.
        $this->campaign(7, 'accumulate');
        $this->click(100, 7);
        $this->pixels();
        $this->goal(7, ['name' => 'Buy', 'trigger' => ['event' => 'buy'], 'value' => ['type' => 'from_property']]);
        $engine = $this->engineWith($this->notifier());

        $this->send($engine, 100, [$this->event('late', 'buy', self::T + 100, [], 10, true)]);
        $replay = $this->send($engine, 100, [$this->event('early', 'buy', self::T, [], 1, true)]);

        self::assertSame('reached', $replay['outcomes'][0]['kind']);
        self::assertSame(['reached:cancelled', 'reached:pending'], self::outboxRows());
        $this->deliver();
        self::assertCount(1, $this->fetched);
        self::assertStringContainsString('&v=1.00&', $this->fetched[0], 'the value that stands, not the one it replaced');
    }

    /**
     * A server pixel holding two URLs (PR 5's per-destination outbox) on a
     * web goal: each URL is its own row, a refusing URL is retried alone
     * while the accepting one is never sent a second time, and a replay's
     * replacement is withheld only where the replaced outcome was announced
     * — the URL that had heard the first value is told nothing more, the one
     * that had heard nothing hears the value that stands, once.
     */
    public function testATwoUrlPixelAnnouncesOncePerUrlAndRetriesOnlyTheOneThatFailed(): void
    {
        $this->campaign(7, 'accumulate');
        $this->click(100, 7);
        self::fixture("UPDATE 202_clicks SET ppc_account_id=" . self::PPC);
        self::fixture("INSERT INTO 202_ppc_account_pixels (ppc_account_id, pixel_code, pixel_type_id) VALUES
            (" . self::PPC . ", 'https://up.test/pb?g=[[p202_goal]]&v=[[p202_goal_value]]  https://down.test/pb?g=[[p202_goal]]&v=[[p202_goal_value]]', 4)");
        $this->goal(7, ['name' => 'Buy', 'trigger' => ['event' => 'buy'], 'value' => ['type' => 'from_property']]);
        $engine = $this->engineWith($this->notifier());
        $this->refusing = 'https://down.test/';

        $first = $this->send($engine, 100, [$this->event('late', 'buy', self::T + 100, [], 10, true)]);
        self::assertSame('reached', $first['outcomes'][0]['kind']);
        self::assertSame(2, $first['notifications'][0]['queued'], 'one row per URL (a doubled space is not a destination)');
        self::assertSame(['sent' => 1, 'failed' => 0, 'retrying' => 1], $this->deliver());
        $this->clock += NotificationOutbox::backoff(1);
        self::assertSame(['sent' => 0, 'failed' => 0, 'retrying' => 1], $this->deliver(), 'only the refusing URL is due again');
        self::assertSame(['https://up.test/pb?g=Buy&v=10.00', 'https://down.test/pb?g=Buy&v=10.00', 'https://down.test/pb?g=Buy&v=10.00'], $this->fetched,
            'the accepting URL heard the conversion once; the refusing one was asked twice');
        $rows = self::$db->query('SELECT destination, kind, status, attempts FROM 202_notification_pending ORDER BY notification_id')->fetch_all(MYSQLI_ASSOC);
        self::assertSame(['0/reached/sent/1', '1/reached/pending/2'], array_map(static fn (array $r): string => implode('/', $r), $rows));

        // A late event that happened first replaces the outcome ($10 -> $1).
        // Both URLs may have heard $10 — the refusing one was attempted, and
        // an attempt cannot be taken back — so neither is told $1.
        $replay = $this->send($engine, 100, [$this->event('early', 'buy', self::T, [], 1, true)]);
        self::assertTrue($replay['replayed']);
        self::assertSame(['suppressed', 'replacement'], [$replay['outcomes'][0]['kind'], $replay['outcomes'][0]['reason']]);
        $this->refusing = null;
        $this->clock += NotificationOutbox::backoff(2);
        $this->deliver();
        self::assertSame(['https://up.test/pb?g=Buy&v=10.00', 'https://down.test/pb?g=Buy&v=10.00', 'https://down.test/pb?g=Buy&v=10.00', 'https://down.test/pb?g=Buy&v=10.00'],
            $this->fetched, 'the retry carries the value it was queued with; the replacement is sent nowhere');
        $byConv = self::$db->query("SELECT GROUP_CONCAT(CONCAT_WS('/', destination, kind, status) ORDER BY destination, kind SEPARATOR ' ') AS r
            FROM 202_notification_pending GROUP BY conv_id ORDER BY conv_id")->fetch_all(MYSQLI_ASSOC);
        self::assertSame(['0/reached/sent 1/reached/sent', '0/correction/suppressed 0/reached/cancelled 1/correction/suppressed 1/reached/cancelled'],
            array_column($byConv, 'r'));
    }

    /**
     * The per-URL decision where the two URLs differ: the worker sent the
     * first URL's postback and had not yet reached the second's when a
     * replay replaced the outcome. The first URL heard $10 and is told
     * nothing more; the second heard nothing, so its $10 is cancelled and it
     * hears the $1 that stands.
     */
    public function testAReplacementIsWithheldOnlyWhereTheReplacedOutcomeWasAnnounced(): void
    {
        $this->campaign(7, 'accumulate');
        $this->click(100, 7);
        self::fixture("UPDATE 202_clicks SET ppc_account_id=" . self::PPC);
        self::fixture("INSERT INTO 202_ppc_account_pixels (ppc_account_id, pixel_code, pixel_type_id) VALUES
            (" . self::PPC . ", 'https://one.test/pb?v=[[p202_goal_value]] https://two.test/pb?v=[[p202_goal_value]]', 4)");
        $this->goal(7, ['name' => 'Buy', 'trigger' => ['event' => 'buy'], 'value' => ['type' => 'from_property']]);
        $engine = $this->engineWith($this->notifier());

        $this->send($engine, 100, [$this->event('late', 'buy', self::T + 100, [], 10, true)]);
        self::assertSame(['sent' => 1, 'failed' => 0, 'retrying' => 0], $this->outbox()->sendDue(1), 'the worker gets through the first URL only');
        $replay = $this->send($engine, 100, [$this->event('early', 'buy', self::T, [], 1, true)]);
        self::assertSame('reached', $replay['outcomes'][0]['kind'], 'announced where the network has not heard it');
        self::assertSame(1, $replay['notifications'][0]['queued']);
        $this->deliver();
        self::assertSame(['https://one.test/pb?v=10.00', 'https://two.test/pb?v=1.00'], $this->fetched, 'each URL hears the outcome exactly once');
    }

    /**
     * 4b's event rule per URL (onAnnouncedBefore()): $5 then $10 are queued at two
     * URLs and the worker gets only $5 to the first before a late $1 that
     * happened first shifts them to $1, $5, $10. Each URL must hear each
     * event once: the first heard $5 and now hears $10; the second heard
     * nothing and now hears all three.
     */
    public function testAShiftedEventIsAnnouncedOncePerUrl(): void
    {
        $this->campaign(7, 'accumulate');
        $this->click(100, 7);
        self::fixture("UPDATE 202_clicks SET ppc_account_id=" . self::PPC);
        self::fixture("INSERT INTO 202_ppc_account_pixels (ppc_account_id, pixel_code, pixel_type_id) VALUES
            (" . self::PPC . ", 'https://one.test/pb?v=[[p202_goal_value]] https://two.test/pb?v=[[p202_goal_value]]', 4)");
        $this->goal(7, ['name' => 'Buy', 'trigger' => ['event' => 'buy'], 'repeat' => ['mode' => 'each'], 'value' => ['type' => 'from_property']]);
        $engine = $this->engineWith($this->notifier());

        $this->send($engine, 100, [$this->event('a', 'buy', self::T + 10, [], 5, true)]);
        self::assertSame(1, $this->outbox()->sendDue(1)['sent'], 'the first URL hears $5; the second has not been reached');
        $this->send($engine, 100, [$this->event('b', 'buy', self::T + 20, [], 10, true)]);
        $replay = $this->send($engine, 100, [$this->event('c', 'buy', self::T, [], 1, true)]);
        self::assertTrue($replay['replayed']);
        $this->deliver();

        $heard = ['one' => [], 'two' => []];
        foreach ($this->fetched as $url) {
            $heard[str_starts_with($url, 'https://one.test/') ? 'one' : 'two'][] = (string) preg_replace('/^.*v=/', '', $url);
        }
        self::assertSame(['5.00', '10.00'], $heard['one'], 'the first URL heard $5 once and is told of $10');
        $two = $heard['two'];
        sort($two);
        self::assertSame(['1.00', '5.00', '10.00'], $two, 'the second URL, which had heard nothing, hears each event once');
        self::assertSame(['lead' => 1, 'payout' => '16.00000'], $this->clickState(100));
    }

    /** @return list<string> kind:status of every outbox row, in order */
    private static function outboxRows(): array
    {
        return array_map(
            static fn (array $r): string => $r['kind'] . ':' . $r['status'],
            self::$db->query('SELECT kind, status FROM 202_notification_pending ORDER BY notification_id')->fetch_all(MYSQLI_ASSOC)
        );
    }

    public function testAReplayThatShiftsNNeverAnnouncesAnEventASecondTime(): void
    {
        // $5 and $10 are announced; a late $1 that happened first makes them
        // $1, $5, $10. Every one of those is either a replacement or the $10
        // event the network has already heard about.
        $this->campaign(7, 'accumulate');
        $this->click(100, 7);
        $this->pixels();
        $this->goal(7, ['name' => 'Buy', 'trigger' => ['event' => 'buy'], 'repeat' => ['mode' => 'each'], 'value' => ['type' => 'from_property']]);
        $engine = $this->engineWith($this->notifier());

        $this->send($engine, 100, [$this->event('a', 'buy', self::T + 10, [], 5, true)]);
        $this->send($engine, 100, [$this->event('b', 'buy', self::T + 20, [], 10, true)]);
        $this->deliver();
        self::assertCount(2, $this->fetched);
        $replay = $this->send($engine, 100, [$this->event('c', 'buy', self::T, [], 1, true)]);

        self::assertSame([1, 2, 3], array_column($replay['outcomes'], 'n'));
        self::assertSame(['suppressed', 'suppressed', 'suppressed'], array_column($replay['outcomes'], 'kind'));
        $this->deliver();
        self::assertCount(2, $this->fetched, 'the $10 event, now the third purchase, is not announced again');
        self::assertSame(['lead' => 1, 'payout' => '16.00000'], $this->clickState(100));
    }

    /**
     * Re-evaluation re-decides a goal with its dependents (fa328af), so the
     * dependents' outcomes it writes go through the same notice rule: the B
     * that waited on A is announced when A's new version reaches it, once;
     * applying the same version again announces nothing; and a version that
     * stops matching retires both without a word to the network.
     */
    public function testAReevaluationAnnouncesTheDependentsItReachesOnce(): void
    {
        $this->campaign(7, 'accumulate');
        $this->click(100, 7);
        $this->pixels();
        $big = ['name' => 'Buy', 'trigger' => ['event' => 'buy', 'where' => [['prop' => 'amount', 'op' => 'gte', 'value' => 100]]],
            'value' => ['type' => 'fixed', 'amount' => 5]];
        $any = ['name' => 'Buy', 'trigger' => ['event' => 'buy'], 'value' => ['type' => 'fixed', 'amount' => 5]];
        $a = $this->goal(7, $big);
        $b = $this->goal(7, ['name' => 'Upsell', 'trigger' => ['event' => 'upsell'], 'after' => [$a], 'value' => ['type' => 'fixed', 'amount' => 3]]);
        $engine = $this->engineWith($this->notifier());
        $this->send($engine, 100, [$this->event('p1', 'buy', self::T, ['amount' => 10]), $this->event('u1', 'upsell', self::T + 1)]);
        self::assertSame(0, $this->deliver()['sent'], 'a $10 purchase reaches neither goal yet');

        $this->clock += 100;
        $this->goals->addVersion(1, $a, GoalDefinition::parse($any, $a), $this->clock);
        $applied = $engine->reevaluate(1, $a, null, true);
        self::assertSame([$a, $b], $applied['goals']);
        self::assertSame(['reached', 'reached'], array_column($applied['subjects'][0]['notifications'], 'kind'));
        self::assertSame(['queued', 'queued'], array_column($applied['subjects'][0]['notifications'], 'status'));
        self::assertSame(['sent' => 2, 'failed' => 0, 'retrying' => 0], $this->deliver());
        $heard = array_map(static fn (string $u): string => (string) preg_replace('/^.*[?&]g=([^&]*).*$/', '$1', $u), $this->fetched);
        sort($heard);
        self::assertSame(['Buy', 'Upsell'], $heard, 'A and the dependent that waited on it are each announced once');
        self::assertSame(['lead' => 1, 'payout' => '8.00000'], $this->clickState(100));

        $same = $engine->reevaluate(1, $a, null, true);
        self::assertSame(0, $same['totals']['write'] + $same['totals']['retire']);
        self::assertSame([], $same['subjects'][0]['notifications']);

        $this->clock += 100;
        $this->goals->addVersion(1, $a, GoalDefinition::parse($big, $a), $this->clock);
        $retired = $engine->reevaluate(1, $a, null, true);
        self::assertSame(2, $retired['totals']['retire']);
        self::assertSame([], $retired['subjects'][0]['notifications'], 'a retirement is not announced');
        self::assertSame(0, $this->clickState(100)['lead']);
        $this->deliver();
        self::assertCount(2, $this->fetched, 'a retirement sends nothing');
    }

    /**
     * Plan §5.7, for web subjects on the shared outbox: A → B announced; a
     * version that stops matching retires both (retractions, stored
     * suppressed: no correction URL); a version that matches again revives
     * B — its reached went out at its first write, so decision 1 sends
     * nothing and cancels the retraction that never went out — and writes A
     * anew under its third version for the n its first version announced,
     * so decision 2 records a correction instead of a reached. The network
     * heard each outcome exactly once.
     */
    public function testAnOutcomeRetiredAndReachedAgainIsNotAnnouncedAgain(): void
    {
        $this->campaign(7, 'accumulate');
        $this->click(100, 7);
        $this->pixels();
        $big = ['name' => 'Buy', 'trigger' => ['event' => 'buy', 'where' => [['prop' => 'amount', 'op' => 'gte', 'value' => 100]]],
            'value' => ['type' => 'fixed', 'amount' => 5]];
        $any = ['name' => 'Buy', 'trigger' => ['event' => 'buy'], 'value' => ['type' => 'fixed', 'amount' => 5]];
        $a = $this->goal(7, $any);
        $b = $this->goal(7, ['name' => 'Upsell', 'trigger' => ['event' => 'upsell'], 'after' => [$a], 'value' => ['type' => 'fixed', 'amount' => 3]]);
        $engine = $this->engineWith($this->notifier());
        $first = $this->send($engine, 100, [$this->event('p1', 'buy', self::T, ['amount' => 10]), $this->event('u1', 'upsell', self::T + 1)]);
        self::assertSame(['reached', 'reached'], array_column($first['outcomes'], 'kind'));
        self::assertSame(2, $this->deliver()['sent']);
        self::assertCount(2, $this->fetched);

        $this->clock += 100;
        $this->goals->addVersion(1, $a, GoalDefinition::parse($big, $a), $this->clock);
        self::assertSame(2, $engine->reevaluate(1, $a, null, true)['totals']['retire']);
        self::assertSame(['reached:sent', 'reached:sent', 'retraction:suppressed', 'retraction:suppressed'], self::outboxRows());

        $this->clock += 100;
        $this->goals->addVersion(1, $a, GoalDefinition::parse($any, $a), $this->clock);
        $back = $engine->reevaluate(1, $a, null, true);
        self::assertSame(2, $back['totals']['write']);
        self::assertSame(['lead' => 1, 'payout' => '8.00000'], $this->clickState(100), 'both count again');
        $kinds = [];
        foreach ($back['subjects'][0]['notifications'] as $n) {
            $kinds[$n['goal_id'] === $a ? 'A' : 'B'] = $n['kind'] . ':' . $n['status'];
        }
        ksort($kinds);
        self::assertSame(['A' => 'suppressed:not_sent', 'B' => 'suppressed:not_sent'], $kinds,
            'B was revived (decision 1); A v3 is a new row for an n A v1 announced (decision 2)');
        self::assertSame([
            'reached:sent', 'reached:sent', 'retraction:suppressed', 'retraction:cancelled',
            'reached:cancelled', 'correction:suppressed',
        ], self::outboxRows(), 'A v1 retracted (unsent); B\'s retraction cancelled by its revival; A v3 a correction');
        self::assertSame(0, $this->deliver()['sent']);
        self::assertCount(2, $this->fetched, 'nothing was announced a second time');
    }

    public function testANotifierThatThrowsDoesNotUndoTheCommittedWrite(): void
    {
        $this->campaign(7, 'accumulate');
        $this->click(100, 7);
        $this->goal(7, ['name' => 'S', 'trigger' => ['event' => 's'], 'value' => ['type' => 'fixed', 'amount' => 2]]);
        $broken = new class () implements OutcomeNotifier {
            public function notify(int $userId, GoalSubject $subject, array $notices): array
            {
                throw new \RuntimeException('network down');
            }
        };

        $result = $this->send($this->engineWith($broken), 100, [$this->event('x', 's', self::T)]);
        self::assertSame(['x'], $result['accepted']);
        self::assertSame('failed', $result['notifications'][0]['status']);
        self::assertSame(['lead' => 1, 'payout' => '2.00000'], $this->clickState(100), 'the conversion stands');
    }

    // ─── A retry of a server-clocked event ──────────────────────────

    public function testAServerClockedRetryLaterIsADuplicateAndOtherContentIsAConflict(): void
    {
        $this->campaign(7, 'accumulate');
        $this->click(100, 7);
        $this->goal(7, ['name' => 'S', 'trigger' => ['event' => 's'], 'value' => ['type' => 'fixed', 'amount' => 2]]);
        $engine = $this->engineWith(null);
        $clocked = static fn (int $at, string $name): GoalEvent => (new GoalEvent('@once:s', $name, $at, $at, [], null, true, null))->clockedByServer();

        $this->send($engine, 100, [$clocked(self::T, 's')]);
        $retry = $this->send($engine, 100, [$clocked(self::T + 3600, 's')]);
        self::assertSame(['@once:s'], $retry['duplicates'], 'an hour later, with the server clock moved on, it is still the same event');

        $this->expectException(GoalEngineException::class);
        $this->send($engine, 100, [$clocked(self::T + 7200, 't')]);
    }

    public function testAReporterClockedEventWithAnotherTimeIsStillAConflict(): void
    {
        $this->campaign(7, 'accumulate');
        $this->click(100, 7);
        $engine = $this->engineWith(null);
        $this->send($engine, 100, [$this->event('e', 's', self::T)]);

        try {
            $this->send($engine, 100, [$this->event('e', 's', self::T + 5)]);
            self::fail('the same id at a time the reporter gave differently is another event');
        } catch (GoalEngineException $e) {
            self::assertSame(GoalEngineException::EVENT_CONFLICT, $e->reason);
        }
    }

    // ─── Which campaigns evaluate goals ─────────────────────────────

    public function testACampaignEvaluatesGoalsOnlyWhileOneIsLive(): void
    {
        $this->campaign(7, 'accumulate');
        $events = new WebEvents($this->conn);
        self::assertFalse($events->campaignEvaluatesGoals(1, 7, self::T), 'no goals: the legacy path');
        $g = $this->goal(7, ['name' => 'S', 'trigger' => ['event' => 's']]);
        self::assertTrue($events->campaignEvaluatesGoals(1, 7, $this->clock + 1));
        $this->goals->archive(1, $g, $this->clock + 100);
        self::assertFalse($events->campaignEvaluatesGoals(1, 7, $this->clock + 200), 'every goal archived: the legacy path again');
        self::assertFalse($events->campaignEvaluatesGoals(2, 7, $this->clock + 1), 'another account\'s campaign');
    }

    // ─── The click a visitor id names ───────────────────────────────

    public function testAVisitorIdNamesItsNewestClickOnThePagesCampaign(): void
    {
        $this->campaign(7, 'accumulate');
        $this->campaign(8, 'accumulate');
        $now = self::T + 1000;
        $this->click(100, 7, $now - 500);
        $this->click(101, 7, $now - 100);
        $this->click(102, 8, $now - 50);
        $this->click(103, 7, $now - WebEvents::VISITOR_LOOKBACK - 10);
        self::fixture("INSERT INTO 202_landing_pages SET landing_page_id=5, user_id=1, landing_page_id_public=555, aff_campaign_id=7,
            landing_page_nickname='lp', landing_page_url='http://lp', landing_page_deleted=0, landing_page_time=1, landing_page_type=0");
        self::fixture("INSERT INTO 202_landing_pages SET landing_page_id=6, user_id=1, landing_page_id_public=666, aff_campaign_id=0,
            landing_page_nickname='adv', landing_page_url='http://lp', landing_page_deleted=0, landing_page_time=1, landing_page_type=1");
        $lpid = str_repeat('ab', 16);
        $other = str_repeat('cd', 16);
        $hash = IdentityGraph::hash((new IdentityKeys($this->conn))->forUser(1)['hash'], new IdentitySignal(SignalType::LANDING_PAGE, $lpid));
        foreach ([100, 101, 102, 103] as $c) {
            self::fixture("INSERT INTO 202_identity_observations SET click_id=$c, signal_type='lpid', signal_hash='$hash', observed_at=1");
        }
        $events = new WebEvents($this->conn);

        self::assertSame(['user_id' => 1, 'click_id' => 101, 'campaign_id' => 7], $events->clickForVisitor('555', $lpid, $now),
            'the newest click of the simple page\'s own campaign');
        self::assertSame(102, $events->clickForVisitor('666', $lpid, $now)['click_id'] ?? null, 'an advanced page: any campaign of the account');
        self::assertNull($events->clickForVisitor('555', $other, $now), 'an id never seen on a click');
        self::assertNull($events->clickForVisitor('555', 'not-hex', $now));
        self::assertNull($events->clickForVisitor('999', $lpid, $now), 'a page that does not exist');
        self::assertNull($events->clickForVisitor('555', $lpid, $now + WebEvents::VISITOR_LOOKBACK), 'outside the lookback');

        self::fixture("INSERT INTO 202_identity_signals SET user_id=1, signal_type='lpid', signal_hash='$hash', visitor_key=1, merges=21, quarantined_at=1, created_at=1");
        self::assertNull($events->clickForVisitor('555', $lpid, $now), 'a quarantined id names nothing');
    }
}
