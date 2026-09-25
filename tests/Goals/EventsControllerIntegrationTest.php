<?php

declare(strict_types=1);

namespace Tests\Goals;

use Api\V3\Controllers\EventsController;
use Api\V3\Exception\ConflictException;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * POST /events against a real database: a strict body read before any
 * cast, events evaluated by the click's goals with the API's revenue
 * trusted, a retry answered as duplicates even when the server supplied
 * the time, and an event on a campaign without goals stored with a note.
 *
 * @group integration
 */
final class EventsControllerIntegrationTest extends TestCase
{
    use GoalDatabase;

    private function api(int $userId = 1): EventsController
    {
        return new EventsController(self::$db, $userId);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, string>
     */
    private function refused(array $body): array
    {
        try {
            $this->api()->create($body);
        } catch (ValidationException $e) {
            return $e->getFieldErrors();
        }
        self::fail('expected a 422');
    }

    public function testTheBodyIsStrictAndReadRaw(): void
    {
        $this->campaign(7);
        $this->click(100, 7);
        $ok = ['event_id' => 'a', 'name' => 'x'];
        self::assertSame(['colour'], array_keys($this->refused(['click_id' => 100, 'events' => [$ok], 'colour' => 1])));
        foreach (['1e2', '100.0', ' 100', 100.0, true, null] as $bad) {
            self::assertSame(['click_id'], array_keys($this->refused(['click_id' => $bad, 'events' => [$ok]])), var_export($bad, true));
        }
        self::assertSame(['events'], array_keys($this->refused(['click_id' => 100, 'events' => []])));
        self::assertSame(['events'], array_keys($this->refused(['click_id' => 100, 'events' => array_fill(0, EventsController::MAX_EVENTS + 1, $ok)])));
        self::assertSame(['events[0].received_at', 'events[0].revenue_trusted'], array_keys($this->refused(['click_id' => 100, 'events' => [
            $ok + ['received_at' => 1, 'revenue_trusted' => true],
        ]])));
        self::assertArrayHasKey('events[1].event_id', $this->refused(['click_id' => 100, 'events' => [$ok, ['name' => 'x']]]));
        self::assertArrayHasKey('events[0].occurred_at', $this->refused(['click_id' => 100, 'events' => [$ok + ['occurred_at' => '1700000000']]]));
        self::assertSame(0, (int) self::$db->query('SELECT COUNT(*) AS n FROM 202_goal_events')->fetch_assoc()['n'], 'nothing refused was stored');
    }

    public function testEventsReachGoalsAndARetryIsDuplicates(): void
    {
        $this->campaign(7, 'accumulate');
        $this->click(100, 7);
        $this->goal(7, ['name' => 'Buy', 'trigger' => ['event' => 'buy'], 'value' => ['type' => 'from_property']]);
        $body = ['click_id' => 100, 'events' => [['event_id' => 'b1', 'name' => 'buy', 'revenue' => 20, 'transaction_id' => 'T-1']]];

        $first = $this->api()->create($body);
        self::assertSame(201, $first['_status']);
        self::assertSame(['b1'], $first['data']['accepted']);
        self::assertTrue($first['data']['outcomes'][0]['payable'], 'an API key\'s revenue is trusted');
        self::assertSame('20.00000', $first['data']['outcomes'][0]['amount']);
        self::assertSame(['lead' => 1, 'payout' => '20.00000'], $this->clickState(100));

        sleep(1); // the server's clock moves: the retry carries no occurred_at of its own
        $again = $this->api()->create($body);
        self::assertSame(200, $again['_status']);
        self::assertSame(['b1'], $again['data']['duplicates']);
        self::assertCount(1, $this->ledger(100));

        $this->expectException(ConflictException::class);
        $this->api()->create(['click_id' => 100, 'events' => [['event_id' => 'b1', 'name' => 'buy', 'revenue' => 21, 'transaction_id' => 'T-1']]]);
    }

    public function testAnotherAccountsClickIsNotFound(): void
    {
        $this->campaign(7);
        $this->click(100, 7);
        $this->expectException(NotFoundException::class);
        $this->api(2)->create(['click_id' => 100, 'events' => [['event_id' => 'a', 'name' => 'x']]]);
    }

    public function testACampaignWithoutGoalsStoresTheEventWithANote(): void
    {
        $this->campaign(7);
        $this->click(100, 7);
        $out = $this->api()->create(['click_id' => 100, 'events' => [['event_id' => 'a', 'name' => 'x']]]);
        self::assertFalse($out['data']['goals_evaluated']);
        self::assertStringContainsString('POST /goals/{id}/reevaluation', $out['data']['note']);
        self::assertSame([], $this->ledger(100));
        self::assertSame(1, (int) self::$db->query('SELECT COUNT(*) AS n FROM 202_goal_events')->fetch_assoc()['n']);
    }
}
