<?php

declare(strict_types=1);

namespace Tests\Ltv;

use Api\V3\Controllers\LtvController;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Ltv\LtvQuery;
use Prosper202\Ltv\MysqlLtvRepository;
use Tests\Support\FakeMysqliConnection;

/**
 * The LTV reads' fixed-list parameters: GET /ltv/customers' sort, dir and
 * segment, /ltv/breakdown's by (or breakdown), /ltv/predict's by and
 * /ltv/subscriptions' status.
 *
 * sort and dir fell back to total_revenue and DESC without a word, so
 * `sort=revenue` or `dir=down` answered 200, ranked by something else, and
 * read as the order asked for (CLAUDE.md #4). The others were refused, but
 * by a message with no field in it. Each is now a 422 whose field_errors
 * name the parameter and list its values, before anything is read; absent
 * and '' are the default.
 */
final class LtvEnumParamTest extends TestCase
{
    private const SORTS = 'Valid values: total_revenue, order_count, last_activity_time, first_seen_time, mrr';
    private const BREAKDOWNS = 'Valid values: campaign, ppc_account, landing_page, product';

    /** @return iterable<string, array{string, string, list<mixed>, string}> read, parameter, refused values, message */
    public static function parameters(): iterable
    {
        $segments = 'Valid values: repeat, subscribers, at_risk';
        $sorts = ['revenue', 'TOTAL_REVENUE', ' mrr', 'mrr;', ['mrr'], true];
        yield 'customers sort' => ['customers', 'sort', $sorts, self::SORTS];
        yield 'customers dir' => ['customers', 'dir', ['down', 'ascending', '1', ['ASC']], 'Valid values: ASC, DESC'];
        yield 'customers segment' => ['customers', 'segment', ['vip', 'Repeat', ['repeat']], $segments];
        yield 'breakdown by' => ['breakdown', 'by', ['country', 'Campaign', ['campaign']], self::BREAKDOWNS];
        yield 'breakdown breakdown' => ['breakdown', 'breakdown', ['country'], self::BREAKDOWNS];
        yield 'predict by' => ['predict', 'by', ['country', 'PRODUCT'], self::BREAKDOWNS];
        yield 'subscriptions status' => [
            'listSubscriptions',
            'status',
            ['cancelled', 'Active', ['active']],
            'Valid values: trialing, active, past_due, paused, canceled',
        ];
    }

    /**
     * @dataProvider parameters
     * @param list<mixed> $refused
     */
    public function testAValueNotOnTheListIsRefusedNamingTheParameterAndTheList(
        string $read,
        string $param,
        array $refused,
        string $message
    ): void {
        foreach ($refused as $value) {
            $conn = new FakeMysqliConnection();
            $shown = var_export($value, true);
            try {
                (new LtvController($conn, 7))->{$read}([$param => $value]);
                self::fail("$read($param=$shown) answered instead of refusing");
            } catch (ValidationException $e) {
                self::assertSame([$param => $message], $e->getFieldErrors(), "$read($param=$shown)");
            }
            self::assertSame([], $conn->preparedSql, "$read($param=$shown): nothing was read");
        }
    }

    public function testEveryBadParameterOfOneReadIsNamedAtOnce(): void
    {
        try {
            $ltv = new LtvController(new FakeMysqliConnection(), 7);
            $ltv->customers(['sort' => 'revenue', 'dir' => 'down', 'segment' => 'vip']);
            self::fail('accepted');
        } catch (ValidationException $e) {
            self::assertSame(['sort', 'dir', 'segment'], array_keys($e->getFieldErrors()));
        }
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function orders(): iterable
    {
        yield 'absent: the default' => [[], 'ORDER BY c.total_revenue DESC'];
        yield 'empty: the default' => [['sort' => '', 'dir' => ''], 'ORDER BY c.total_revenue DESC'];
        yield 'a sort and a direction' => [['sort' => 'mrr', 'dir' => 'ASC'], 'ORDER BY c.mrr ASC'];
        yield 'a direction in lower case' => [['sort' => 'order_count', 'dir' => 'asc'], 'ORDER BY c.order_count ASC'];
        yield 'mixed case' => [['dir' => 'Desc'], 'ORDER BY c.total_revenue DESC'];
    }

    /**
     * @dataProvider orders
     * @param array<string, string> $params
     */
    public function testAValueOnTheListIsTheOrderRead(array $params, string $order): void
    {
        $conn = new FakeMysqliConnection();
        (new LtvController($conn, 7))->customers($params);
        self::assertCount(1, $conn->statementsContaining($order), "the read is ordered $order");
    }

    public function testTheRepositoryRefusesAnOrderItDoesNotHaveRatherThanReplacingIt(): void
    {
        $repo = new MysqlLtvRepository(new Connection(new FakeMysqliConnection(), new FakeMysqliConnection()));
        foreach ([['revenue', 'DESC', 'sort'], ['mrr', 'down', 'dir']] as [$sort, $dir, $named]) {
            try {
                $repo->customers(new LtvQuery(7), $sort, $dir, 50, 0);
                self::fail("$sort $dir was read as some other order");
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('Invalid ' . $named, $e->getMessage());
            }
        }
    }
}
