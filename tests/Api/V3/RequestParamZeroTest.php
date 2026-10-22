<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\CampaignsController;
use Api\V3\Controllers\ConversionsController;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeMysqliConnection;

/**
 * The request parameters that `!empty()` read as absent when they were "0"
 * (the sweep after LTV's period=0; LtvPeriodParamTest has that one, and
 * ForbidFalsyRequestParamTestRule keeps the shape out of api/):
 *
 *  - GET /conversions?campaign_id=0 was no filter, and answered with every
 *    campaign's conversions; it is refused as its siblings click_id and goal
 *    are;
 *  - POST /conversions with customer_ref "0" dropped the ref, so the revenue
 *    landed on whatever the click resolved to; customer_id 0 named no one;
 *  - any list's cursor=0 was no cursor and answered page one, where every
 *    other malformed cursor is a 422.
 *
 * The real controllers run over a fake connection: a refusal is asserted
 * with the field it names and with nothing prepared, and an accepted value
 * by the statement it reached.
 */
final class RequestParamZeroTest extends TestCase
{
    /** @return iterable<string, array{mixed}> */
    public static function badCampaignIds(): iterable
    {
        yield "'0'" => ['0'];
        yield 'int 0' => [0];
        yield "''" => [''];
        yield "'abc'" => ['abc'];
        yield "'-3'" => ['-3'];
        yield "'07'" => ['07'];
        yield 'a list' => [['7']];
    }

    /**
     * @dataProvider badCampaignIds
     */
    public function testAConversionListCampaignIdThatIsNotAnIdIsRefused(mixed $campaignId): void
    {
        $db = new FakeMysqliConnection();
        try {
            (new ConversionsController($db, 1))->list(['campaign_id' => $campaignId]);
            self::fail('campaign_id=' . var_export($campaignId, true) . ' was answered instead of refused');
        } catch (ValidationException $e) {
            self::assertSame(['campaign_id'], array_keys($e->getFieldErrors()));
        }
        self::assertSame([], $db->preparedSql, 'nothing was read for a refused filter');
    }

    public function testAConversionListCampaignIdFiltersAndItsAbsenceDoesNot(): void
    {
        $filtered = new FakeMysqliConnection();
        $filtered->whenQueryContainsReturnRows('COUNT(*)', [['total' => 0]]);
        (new ConversionsController($filtered, 1))->list(['campaign_id' => '7']);
        $count = $filtered->statementsContaining('COUNT(*)');
        self::assertCount(1, $count);
        self::assertStringContainsString('cl.campaign_id = ?', $count[0]->sql);
        self::assertSame([1, 7], $count[0]->boundValues);

        $all = new FakeMysqliConnection();
        $all->whenQueryContainsReturnRows('COUNT(*)', [['total' => 0]]);
        (new ConversionsController($all, 1))->list([]);
        $count = $all->statementsContaining('COUNT(*)');
        self::assertCount(1, $count);
        self::assertStringNotContainsString('cl.campaign_id = ?', $count[0]->sql);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function badCustomers(): iterable
    {
        yield "customer_id '0'" => [['customer_id' => '0'], 'customer_id'];
        yield 'customer_id 0' => [['customer_id' => 0], 'customer_id'];
        yield "customer_id 'x'" => [['customer_id' => 'x'], 'customer_id'];
        yield "customer_id ''" => [['customer_id' => ''], 'customer_id'];
        yield "customer_ref ''" => [['customer_ref' => ''], 'customer_ref'];
        yield "customer_ref '  '" => [['customer_ref' => '  '], 'customer_ref'];
        yield 'customer_ref a list' => [['customer_ref' => ['a']], 'customer_ref'];
        yield 'customer_ref_type a number' => [['customer_ref' => 'C-1', 'customer_ref_type' => 0], 'customer_ref_type'];
    }

    /**
     * @dataProvider badCustomers
     * @param array<string, mixed> $customer
     */
    public function testAConversionCustomerThatNamesNoOneIsRefusedBeforeAnyWrite(array $customer, string $field): void
    {
        $db = new FakeMysqliConnection();
        try {
            (new ConversionsController($db, 1))->create(['click_id' => 10] + $customer);
            self::fail(json_encode($customer) . ' was accepted');
        } catch (ValidationException $e) {
            self::assertSame([$field], array_keys($e->getFieldErrors()));
        }
        self::assertSame([], $db->preparedSql, 'nothing was read or written for a refused customer');
    }

    public function testACustomerRefOfZeroReachesTheCustomerResolver(): void
    {
        $db = new FakeMysqliConnection();
        $db->whenQueryContainsReturnRows(
            'FROM 202_clicks WHERE click_id = ? AND user_id = ? LIMIT 1 FOR UPDATE',
            [['click_id' => 10, 'aff_campaign_id' => 44, 'click_payout' => '5.00000', 'click_time' => 1700000000, 'click_lead' => 0]]
        );
        try {
            (new ConversionsController($db, 1))->create(['click_id' => 10, 'customer_ref' => '0']);
        } catch (ValidationException) {
            // The fake yields no insert ids, so the write stops after the
            // lookup this test is about; what was looked up is the point.
        }
        $lookups = $db->statementsContaining('SELECT customer_id FROM 202_customer_aliases');
        self::assertCount(1, $lookups, 'customer_ref "0" never reached the alias lookup: the ref was dropped');
        self::assertSame([1, 'custom', hash('sha256', '0', true)], $lookups[0]->boundValues);
    }

    /** @return iterable<string, array{mixed}> */
    public static function badCursors(): iterable
    {
        yield "'0'" => ['0'];
        yield 'int 0' => [0];
        yield "'abc'" => ['abc'];
        yield 'a list' => [['x']];
    }

    /**
     * @dataProvider badCursors
     */
    public function testAListCursorThatIsNotACursorIsRefused(mixed $cursor): void
    {
        $db = new FakeMysqliConnection();
        try {
            (new CampaignsController($db, 1))->list(['cursor' => $cursor]);
            self::fail('cursor=' . var_export($cursor, true) . ' was answered instead of refused');
        } catch (ValidationException $e) {
            self::assertSame(['cursor'], array_keys($e->getFieldErrors()));
        }
        self::assertSame([], $db->preparedSql, 'nothing was read for a refused cursor');
    }

    public function testAnEmptyCursorIsNoCursor(): void
    {
        $db = new FakeMysqliConnection();
        $db->whenQueryContainsReturnRows('COUNT(*)', [['total' => 0]]);
        (new CampaignsController($db, 1))->list(['cursor' => '']);
        self::assertNotSame([], $db->preparedSql, 'the list was read from page one');
    }
}
