<?php

declare(strict_types=1);

namespace Tests\Goals;

use Api\V3\Controllers\AppSkanEncodingsController;
use Api\V3\Controllers\GoalsController;
use Api\V3\Exception\ConflictException;
use Api\V3\Exception\NotFoundException;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * /goals and the SKAN encodings that name goals, against a real database:
 * every refusal answers with the field it is about, and every id is read
 * from the raw body before anything casts it (CLAUDE.md #18).
 *
 * @group integration
 */
final class GoalsControllerIntegrationTest extends TestCase
{
    use GoalDatabase;

    private function api(): GoalsController
    {
        return new GoalsController(self::$db, 1);
    }

    private function registration(int $id, string $platform = 'ios'): void
    {
        self::fixture("INSERT INTO 202_app_registrations SET registration_id=$id, user_id=1, platform='$platform', app_key='99$id',
            app_name='App $id', app_token='" . str_repeat((string) $id, 64 / strlen((string) $id)) . "', created_at=1, updated_at=1");
    }

    /**
     * @param callable(): mixed $call
     * @return array<string, string>
     */
    private static function fieldErrors(callable $call): array
    {
        try {
            $call();
        } catch (ValidationException $e) {
            return $e->getFieldErrors();
        }
        self::fail('expected a 422');
    }

    public function testACampaignGoalIsCreatedPayableAndEveryBadIdIsRefusedByName(): void
    {
        $this->campaign(7);
        $created = $this->api()->create([
            'scope' => 'campaign', 'scope_id' => 7,
            'definition' => ['name' => 'Sale', 'trigger' => ['event' => 'sale'], 'value' => ['type' => 'fixed', 'amount' => '12.5']],
        ])['data'];
        self::assertSame('Sale', $created['name']);
        self::assertSame(1, $created['current_version']);
        self::assertSame('12.50', $created['definition']['value']['amount']);
        self::assertSame([7], array_column($created['campaigns'], 'campaign_id'), 'a campaign goal with a value is payable by default');
        self::assertNull($created['campaigns'][0]['payout']);

        foreach (['1e3', 1.5, '07', ' 7', '99999999999999999999', true] as $bad) {
            $errors = self::fieldErrors(fn () => $this->api()->create(['scope' => 'campaign', 'scope_id' => $bad, 'definition' => ['name' => 'X', 'trigger' => ['event' => 'x']]]));
            self::assertArrayHasKey('scope_id', $errors, var_export($bad, true) . ' must be refused, not cast');
        }
        self::assertArrayHasKey('scope_id', self::fieldErrors(fn () => $this->api()->create(['scope' => 'campaign', 'scope_id' => 8, 'definition' => ['name' => 'X', 'trigger' => ['event' => 'x']]])),
            'a campaign that is not the user\'s');
        self::assertArrayHasKey('colour', self::fieldErrors(fn () => $this->api()->create(['scope' => 'account', 'colour' => 'red', 'definition' => ['name' => 'X', 'trigger' => ['event' => 'x']]])),
            'an unknown field is refused, not ignored');
        self::assertArrayHasKey('definition.threshold.count', self::fieldErrors(fn () => $this->api()->create(['scope' => 'account', 'definition' => ['name' => 'X', 'trigger' => ['event' => 'x'], 'threshold' => ['count' => '3']]])));
        self::assertArrayHasKey('payable', self::fieldErrors(fn () => $this->api()->create(['scope' => 'account', 'payable' => true, 'definition' => ['name' => 'X', 'trigger' => ['event' => 'x']]])),
            'payouts belong to campaigns');

        try {
            $this->api()->create(['scope' => 'campaign', 'scope_id' => 7, 'definition' => ['name' => 'sale', 'trigger' => ['event' => 'other']]]);
            self::fail('a second goal named sale on the campaign');
        } catch (ConflictException $e) {
            self::assertStringContainsString('already exists', $e->getMessage());
        }
    }

    public function testAfterMayNameOnlyLiveGoalsOfTheSameOwnerAndNeverCloseACycle(): void
    {
        $this->campaign(7);
        $this->campaign(8);
        $a = $this->api()->create(['scope' => 'campaign', 'scope_id' => 7, 'definition' => ['name' => 'A', 'trigger' => ['event' => 'a']]])['data']['goal_id'];
        $other = $this->api()->create(['scope' => 'campaign', 'scope_id' => 8, 'definition' => ['name' => 'O', 'trigger' => ['event' => 'o']]])['data']['goal_id'];
        $b = $this->api()->create(['scope' => 'campaign', 'scope_id' => 7, 'definition' => ['name' => 'B', 'trigger' => ['event' => 'b'], 'after' => [$a]]])['data']['goal_id'];

        self::assertArrayHasKey('definition.after[0]', self::fieldErrors(fn () => $this->api()->create(['scope' => 'campaign', 'scope_id' => 7,
            'definition' => ['name' => 'C', 'trigger' => ['event' => 'c'], 'after' => [$other]]])));
        self::assertArrayHasKey('definition.after[0]', self::fieldErrors(fn () => $this->api()->create(['scope' => 'campaign', 'scope_id' => 7,
            'definition' => ['name' => 'C', 'trigger' => ['event' => 'c'], 'after' => [999]]])));
        self::assertArrayHasKey('definition.after', self::fieldErrors(fn () => $this->api()->update($a, ['definition' => ['name' => 'A', 'trigger' => ['event' => 'a'], 'after' => [$b]]])),
            'A after B after A is a cycle');
        self::assertArrayHasKey('definition.after[0]', self::fieldErrors(fn () => $this->api()->update($a, ['definition' => ['name' => 'A', 'trigger' => ['event' => 'a'], 'after' => [$a]]])));

        try {
            $this->api()->delete($a);
            self::fail('archived a goal another goal waits for');
        } catch (ConflictException $e) {
            self::assertSame([$b], $e->getDetails()['dependents']);
        }
    }

    public function testAnEditIsANewVersionAndTheSameDefinitionIsNot(): void
    {
        $this->campaign(7);
        $id = $this->api()->create(['scope' => 'campaign', 'scope_id' => 7, 'definition' => ['name' => 'A', 'trigger' => ['event' => 'a']]])['data']['goal_id'];
        $same = $this->api()->update($id, ['definition' => ['name' => ' A ', 'trigger' => ['event' => 'a', 'where' => []], 'repeat' => ['mode' => 'once']]])['data'];
        self::assertFalse($same['version_created'], 'the same canonical definition is not a new version');
        self::assertSame(1, $same['current_version']);
        $edited = $this->api()->update($id, ['definition' => ['name' => 'A', 'trigger' => ['event' => 'a'], 'threshold' => ['count' => 2]]])['data'];
        self::assertTrue($edited['version_created']);
        self::assertSame(2, $edited['current_version']);
        self::assertSame([1, 2], array_column($this->api()->versions($id)['data'], 'version'));
        self::assertSame(1, $this->api()->version($id, 1)['data']['definition']['threshold']['count']);

        $this->api()->delete($id);
        self::assertNotNull($this->api()->get($id)['data']['archived_at']);
        $this->expectException(ConflictException::class);
        $this->api()->update($id, ['definition' => ['name' => 'A', 'trigger' => ['event' => 'a']]]);
    }

    public function testCampaignPayoutsAttachOnlyWhereTheGoalBelongs(): void
    {
        $this->campaign(7);
        $this->campaign(8);
        $this->registration(3);
        $own = $this->api()->create(['scope' => 'campaign', 'scope_id' => 7, 'definition' => ['name' => 'A', 'trigger' => ['event' => 'a']]])['data']['goal_id'];
        $app = $this->api()->create(['scope' => 'registration', 'scope_id' => 3, 'definition' => ['name' => 'Level 3', 'trigger' => ['event' => 'level']]])['data']['goal_id'];

        self::assertArrayHasKey('campaign_id', self::fieldErrors(fn () => $this->api()->attachCampaign($own, 8, [])));
        $term = $this->api()->attachCampaign($app, 8, ['payout' => '4.00', 'notify_traffic_source' => false])['data'];
        self::assertSame(['campaign_id' => 8, 'payout_mode' => 'accumulate', 'payout' => '4.00000', 'notify_traffic_source' => false],
            array_intersect_key($term, array_flip(['campaign_id', 'payout', 'notify_traffic_source', 'payout_mode'])));
        self::assertArrayHasKey('payout', self::fieldErrors(fn () => $this->api()->attachCampaign($app, 8, ['payout' => -1])));
        self::assertArrayHasKey('notify_traffic_source', self::fieldErrors(fn () => $this->api()->attachCampaign($app, 8, ['notify_traffic_source' => 'no'])));

        $this->api()->detachCampaign($app, 8);
        $this->expectException(NotFoundException::class);
        $this->api()->detachCampaign($app, 8);
    }

    public function testAnSkanEncodingNamesAPlainGoalOfItsRegistrationOrTheAccount(): void
    {
        $this->registration(3);
        $this->registration(4);
        $encodings = new AppSkanEncodingsController(self::$db, 1);
        $mine = $this->api()->create(['scope' => 'registration', 'scope_id' => 3, 'definition' => ['name' => 'purchase', 'trigger' => ['event' => 'purchase']]])['data']['goal_id'];
        $theirs = $this->api()->create(['scope' => 'registration', 'scope_id' => 4, 'definition' => ['name' => 'purchase', 'trigger' => ['event' => 'purchase']]])['data']['goal_id'];
        $account = $this->api()->create(['scope' => 'account', 'definition' => ['name' => 'whale', 'trigger' => ['event' => 'whale'], 'value' => ['type' => 'fixed', 'amount' => 20]]])['data']['goal_id'];
        $fancy = $this->api()->create(['scope' => 'registration', 'scope_id' => 3, 'definition' => ['name' => 'Level 3', 'trigger' => ['event' => 'level', 'where' => [['prop' => 'level', 'op' => 'gte', 'value' => 3]]]]])['data']['goal_id'];

        $enc = $encodings->create(['registration_id' => 3, 'fine_value' => 3, 'goal_id' => $mine, 'revenue_override' => '4.99'])['data'];
        self::assertSame($mine, (int) $enc['goal_id']);
        $encodings->create(['registration_id' => 3, 'fine_value' => 4, 'goal_id' => $account]);
        $encodings->create(['registration_id' => 0, 'coarse_value' => 'high', 'goal_id' => $account]);

        self::assertArrayHasKey('goal_id', self::fieldErrors(fn () => $encodings->create(['registration_id' => 3, 'fine_value' => 5, 'goal_id' => $theirs])), 'another app\'s goal');
        self::assertArrayHasKey('goal_id', self::fieldErrors(fn () => $encodings->create(['registration_id' => 0, 'fine_value' => 5, 'goal_id' => $mine])), 'an account-wide encoding names an account goal');
        self::assertArrayHasKey('goal_id', self::fieldErrors(fn () => $encodings->create(['registration_id' => 3, 'fine_value' => 5, 'goal_id' => $fancy])), 'not a plain event goal');
        self::assertArrayHasKey('goal_id', self::fieldErrors(fn () => $encodings->create(['registration_id' => 3, 'fine_value' => 5, 'goal_id' => (string) $mine . '.0'])), 'refused raw, not cast');
        self::assertArrayHasKey('event_name', self::fieldErrors(fn () => $encodings->create(['registration_id' => 3, 'fine_value' => 5, 'event_name' => 'purchase', 'revenue' => 1])),
            'the old shape is refused by name');
        self::assertArrayHasKey('revenue_override', self::fieldErrors(fn () => $encodings->create(['registration_id' => 3, 'fine_value' => 5, 'goal_id' => $mine, 'revenue_override' => '1.234567'])));

        // The goal it names cannot stop being plain, or be archived, under it.
        self::assertArrayHasKey('definition', self::fieldErrors(fn () => $this->api()->update($mine, ['definition' => ['name' => 'purchase', 'trigger' => ['event' => 'purchase'], 'threshold' => ['count' => 2]]])));
        try {
            $this->api()->delete($mine);
            self::fail('archived a goal an encoding names');
        } catch (ConflictException $e) {
            self::assertSame([(int) $enc['encoding_id']], $e->getDetails()['skan_encodings']);
        }

        // Clearing the override alone is a real update.
        $cleared = $encodings->update((int) $enc['encoding_id'], ['revenue_override' => null])['data'];
        self::assertNull($cleared['revenue_override']);
    }

    public function testEvaluateRunsTheVectorFormatAndWritesNothing(): void
    {
        $result = $this->api()->evaluate([
            'goals' => [['goal_id' => 1, 'definition' => ['name' => 'Buy', 'trigger' => ['event' => 'buy'], 'value' => ['type' => 'fixed', 'amount' => 2]]]],
            'subject' => ['type' => 'click', 'click_at' => 100],
            'events' => [['event_id' => 'e', 'name' => 'buy', 'occurred_at' => 150, 'received_at' => 150]],
        ])['data'];
        self::assertSame('2.00000', $result['outcomes'][0]['value']);
        self::assertSame('0', self::$db->query('SELECT COUNT(*) AS n FROM 202_goal_outcomes')->fetch_assoc()['n']);

        self::assertArrayHasKey('events[0].name', self::fieldErrors(fn () => $this->api()->evaluate([
            'goals' => [['goal_id' => 1, 'definition' => ['name' => 'Buy', 'trigger' => ['event' => 'buy']]]],
            'subject' => ['type' => 'click'], 'events' => [['event_id' => 'e', 'name' => 'b u y', 'occurred_at' => 1, 'received_at' => 1]],
        ])));
        $valid = $this->api()->validate(['definition' => ['name' => 'X', 'trigger' => ['event' => 'x']]])['data'];
        self::assertTrue($valid['plain_event']);
    }

    /**
     * A sum that repeats without a bound, a summand or revenue too large for
     * the ledger, and an answer too large to build are each refused by name
     * at the outermost entry point, never looped over or overflowed.
     */
    public function testEvaluateAndValidateBoundWhatOneRequestCanCost(): void
    {
        $sumEach = ['name' => 'Every cent', 'trigger' => ['event' => 'buy'], 'threshold' => ['sum' => ['prop' => '$revenue', 'gte' => '0.00001']],
            'repeat' => ['mode' => 'each']];
        self::assertArrayHasKey('definition.repeat.max', self::fieldErrors(fn () => $this->api()->validate(['definition' => $sumEach])));
        self::assertArrayHasKey('definition.repeat.max', self::fieldErrors(fn () => $this->api()->create(['scope' => 'account', 'definition' => $sumEach])));

        // Handed to evaluate as a stored version would be, it is disabled
        // and reaches nothing — before the fix, this one event looped
        // 100,000 times (and 10^11 times at a revenue of 999999).
        $result = $this->api()->evaluate([
            'goals' => [['goal_id' => 1, 'definition' => $sumEach]],
            'subject' => ['type' => 'click', 'click_at' => 100],
            'events' => [['event_id' => 'e', 'name' => 'buy', 'occurred_at' => 150, 'received_at' => 150, 'revenue' => 1]],
        ])['data'];
        self::assertSame([], $result['outcomes']);
        self::assertSame([['goal_id' => 1, 'version' => 1, 'reason' => 'invalid_definition']], $result['disabled']);

        foreach ([1000000, -1000000, 9999999999999, 1.0e300] as $revenue) {
            self::assertArrayHasKey('events[0].revenue', self::fieldErrors(fn () => $this->api()->evaluate([
                'goals' => [['goal_id' => 1, 'definition' => ['name' => 'Buy', 'trigger' => ['event' => 'buy']]]],
                'subject' => ['type' => 'click'],
                'events' => [['event_id' => 'e', 'name' => 'buy', 'occurred_at' => 1, 'received_at' => 1, 'revenue' => $revenue]],
            ])), var_export($revenue, true) . ' is more than a conversion holds');
        }

        // Two bounded sums, 6000 each from one event: 12,000 outcomes, more
        // than one answer holds.
        $bounded = static fn (int $id): array => ['goal_id' => $id, 'definition' => ['name' => 'G' . $id, 'trigger' => ['event' => 'buy'],
            'threshold' => ['sum' => ['prop' => '$revenue', 'gte' => '0.00001']], 'repeat' => ['mode' => 'each', 'max' => 6000]]];
        $errors = self::fieldErrors(fn () => $this->api()->evaluate([
            'goals' => [$bounded(1), $bounded(2)],
            'subject' => ['type' => 'click'],
            'events' => [['event_id' => 'e', 'name' => 'buy', 'occurred_at' => 1, 'received_at' => 1, 'revenue' => 1]],
        ]));
        self::assertArrayHasKey('events', $errors);
        self::assertStringContainsString('more than 10000 outcomes', $errors['events']);

        // One of them alone is 6000, inside the budget, and its sum is held
        // at max × gte.
        $one = $this->api()->evaluate([
            'goals' => [$bounded(1)],
            'subject' => ['type' => 'click'],
            'events' => [['event_id' => 'e', 'name' => 'buy', 'occurred_at' => 1, 'received_at' => 1, 'revenue' => 999999.99999]],
        ])['data'];
        self::assertCount(6000, $one['outcomes']);
        self::assertSame('0.06000', $one['progress'][0]['sum']);
    }
}
