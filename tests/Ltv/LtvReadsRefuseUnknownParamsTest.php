<?php

declare(strict_types=1);

namespace Tests\Ltv;

use Api\V3\Controllers\LtvController;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeMysqliConnection;

/**
 * Every LTV read takes the query parameters it reads and refuses the rest by
 * name, before anything is read. Each read used to take the keys it knew and
 * drop the others, so GET /ltv/customers?updated_since=… answered every
 * customer, 200, as though filtered, and a misspelled `limt=5` read as the
 * default page (CLAUDE.md #4). GET /ltv/customers now reads updated_since,
 * and refuses deleted_since, which a list of live customers cannot answer.
 */
final class LtvReadsRefuseUnknownParamsTest extends TestCase
{
    private const WINDOW = 'period, time_from, time_to';

    protected function tearDown(): void
    {
        unset($_SERVER['QUERY_STRING']);
    }

    /** @return iterable<string, array{string, list<int>, string}> read, its leading arguments, what it accepts */
    public static function reads(): iterable
    {
        yield 'GET /ltv/summary' => ['summary', [], self::WINDOW . ', cf.<field_key>'];
        yield 'GET /ltv/customers' => [
            'customers',
            [],
            self::WINDOW . ', limit, offset, sort, dir, q, segment, updated_since, cf.<field_key>',
        ];
        yield 'GET /ltv/customers/{id}' => ['customerDetail', [5], ''];
        yield 'GET /ltv/customers/{id}/engagement' => ['customerEngagement', [5], 'days'];
        yield 'GET /ltv/customers/{id}/next-offer' => ['customerNextOffer', [5], ''];
        yield 'GET /ltv/abm' => ['abm', [], 'days, limit, offset'];
        yield 'GET /ltv/abm/company' => ['abmCompany', [], 'name, days'];
        yield 'GET /ltv/breakdown' => [
            'breakdown',
            [],
            self::WINDOW . ', by, breakdown, limit, offset, cf.<field_key>',
        ];
        yield 'GET /ltv/cohorts' => ['cohorts', [], 'months'];
        yield 'GET /ltv/mrr' => ['mrr', [], ''];
        yield 'GET /ltv/predict' => ['predict', [], self::WINDOW . ', by, cf.<field_key>'];
        yield 'GET /ltv/products' => ['products', [], 'limit, offset'];
        yield 'GET /ltv/companies' => ['listCompanies', [], 'limit, offset'];
        yield 'GET /ltv/subscriptions' => ['listSubscriptions', [], 'limit, offset, status'];
        yield 'GET /ltv/fields' => ['fieldsList', [], ''];
        yield 'GET /ltv/webhooks' => ['listWebhooks', [], ''];
        yield 'GET /ltv/integrations' => ['listIntegrations', [], ''];
    }

    /**
     * @dataProvider reads
     * @param list<int> $leading
     */
    public function testAParameterTheReadDoesNotTakeIsRefusedNamingItBeforeAnythingIsRead(
        string $read,
        array $leading,
        string $accepted
    ): void {
        $because = $accepted === ''
            ? 'is not accepted here (this read takes no query parameters)'
            : "is not accepted here (accepted: $accepted)";
        $unknown = ['limt' => '5', 'filter' => ['status' => 'active'], 'deleted_since' => '0'];
        if ($read !== 'customers') {
            $unknown['updated_since'] = '1700000000';
        }
        foreach ($unknown as $param => $value) {
            $conn = new FakeMysqliConnection();
            try {
                (new LtvController($conn, 7))->{$read}(...[...$leading, [$param => $value]]);
                self::fail("$read($param) answered instead of refusing");
            } catch (ValidationException $e) {
                $expected = $read === 'customers' && $param === 'deleted_since'
                    ? 'is not accepted here: the list answers live customers only, so it cannot be filtered by'
                        . ' when one was erased or merged into another'
                    : $because;
                self::assertSame([$param => $expected], $e->getFieldErrors(), "$read($param)");
                self::assertSame("Unknown parameter(s): $param", $e->getMessage());
            }
            self::assertSame([], $conn->preparedSql, "$read($param): nothing was read");
        }
    }

    public function testEveryUnknownParameterOfOneReadIsNamedAtOnce(): void
    {
        try {
            (new LtvController(new FakeMysqliConnection(), 7))
                ->listCompanies(['limit' => '5', 'q' => 'acme', 'sort' => 'name']);
            self::fail('accepted');
        } catch (ValidationException $e) {
            self::assertSame(['q', 'sort'], array_keys($e->getFieldErrors()));
            self::assertSame('Unknown parameter(s): q, sort', $e->getMessage());
        }
    }

    /**
     * Over HTTP PHP files `cf.plan` under `cf_plan`: the refusal names the
     * key as it was sent, a read that takes custom-field filters takes it,
     * and a key sent as `cf_plan`, which query() never reads, is refused.
     */
    public function testACustomFieldFilterIsNamedAsSentAndTakenOnlyWhereTheReadFiltersByIt(): void
    {
        $_SERVER['QUERY_STRING'] = 'cf.plan=pro&limit=5';
        try {
            (new LtvController(new FakeMysqliConnection(), 7))->products(['cf_plan' => 'pro', 'limit' => '5']);
            self::fail('GET /ltv/products took a custom-field filter it does not apply');
        } catch (ValidationException $e) {
            self::assertSame(['cf.plan'], array_keys($e->getFieldErrors()));
        }

        // Past the check: query() looks the field up and refuses it as unknown.
        $conn = new FakeMysqliConnection();
        try {
            (new LtvController($conn, 7))->summary(['cf_plan' => 'pro']);
            self::fail('summary answered with a field the account does not have');
        } catch (ValidationException $e) {
            self::assertSame(['cf.plan' => 'No such field'], $e->getFieldErrors());
        }

        foreach (['cf_plan=pro', 'cf.plan=pro&cf_plan=basic', 'cf_plan[x]=pro'] as $query) {
            $_SERVER['QUERY_STRING'] = $query;
            parse_str($query, $params);
            $conn = new FakeMysqliConnection();
            try {
                (new LtvController($conn, 7))->summary($params);
                self::fail("summary took ?$query");
            } catch (ValidationException $e) {
                self::assertStringStartsWith('Unknown parameter(s): cf_plan', $e->getMessage(), "?$query");
                self::assertSame([], $conn->preparedSql, "?$query: nothing was read");
            }
        }
    }

    public function testCustomersChangedSinceATimeAreTheRowsStampedSinceIt(): void
    {
        $conn = new FakeMysqliConnection();
        (new LtvController($conn, 7))->customers(['updated_since' => '1700000000', 'segment' => 'repeat']);

        $reads = $conn->statementsContaining('FROM 202_customers c');
        self::assertCount(2, $reads, 'the count and the page');
        foreach ($reads as $stmt) {
            self::assertStringContainsString('AND c.updated_at >= ?', $stmt->sql);
            self::assertContains(1700000000, $stmt->boundValues);
        }

        $conn = new FakeMysqliConnection();
        (new LtvController($conn, 7))->customers([]);
        foreach ($conn->statementsContaining('FROM 202_customers c') as $stmt) {
            self::assertStringNotContainsString('updated_at', $stmt->sql, 'no updated_since, no condition');
        }

        foreach (['abc', '-1', '1.5', ['1700000000']] as $bad) {
            $conn = new FakeMysqliConnection();
            try {
                (new LtvController($conn, 7))->customers(['updated_since' => $bad]);
                self::fail('updated_since=' . var_export($bad, true) . ' was read');
            } catch (ValidationException $e) {
                self::assertSame(['updated_since'], array_keys($e->getFieldErrors()));
            }
            self::assertSame([], $conn->statementsContaining('FROM 202_customers c'));
        }
    }
}
