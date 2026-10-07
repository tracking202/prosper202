<?php

declare(strict_types=1);

namespace Tests\Cli;

use Api\V3\Controllers\ReportsController;
use Api\V3\Exception\ValidationException;
use P202Cli\Application;
use P202Cli\ServerLists;
use Tests\Support\FakeMysqliConnection;
use Tests\TestCase;

/**
 * The legacy PHP CLI's value lists are the server's (P202Cli\ServerLists).
 *
 * report:breakdown's --sort listed 8 sorts while the server took 11; the
 * period and dimension lists had each been brought up to date by hand. The
 * lists the server keeps private are pinned here to the server's own answer:
 * the list its 422 names for a value it does not take (the real
 * controller, over a fake connection — every refusal below happens before a
 * statement is prepared), or, for the LTV repositories, which only say so in
 * prose, the constant. Then every option that names one of these lists is
 * read from the real Application and must name the whole list, so a command
 * cannot go back to spelling one out by hand.
 */
final class ServerListsTest extends TestCase
{
    /**
     * The values a refusal names for $field ("Valid values: a, b, c").
     *
     * @return list<string>
     */
    private static function refusedWith(callable $call, string $field): array
    {
        try {
            $call();
        } catch (ValidationException $e) {
            $sentence = (string) ($e->getFieldErrors()[$field] ?? '');
            self::assertStringStartsWith('Valid values: ', $sentence, "the refusal of $field names its values");

            return explode(', ', substr($sentence, strlen('Valid values: ')));
        }
        self::fail("the server took a $field it should have refused");
    }

    private static function reports(): ReportsController
    {
        return new ReportsController(new FakeMysqliConnection(), 1);
    }

    public function testTheReportSortsAndIntervalsAreTheServers(): void
    {
        self::assertSame(
            self::refusedWith(fn () => self::reports()->breakdown(['sort' => 'no_such_sort']), 'sort'),
            ServerLists::BREAKDOWN_SORTS,
            'report:breakdown --sort'
        );
        self::assertSame(
            self::refusedWith(fn () => self::reports()->daypart(['sort' => 'no_such_sort']), 'sort'),
            ServerLists::DAYPART_SORTS,
            'report:daypart --sort'
        );
        self::assertSame(
            self::refusedWith(fn () => self::reports()->weekpart(['sort' => 'no_such_sort']), 'sort'),
            ServerLists::WEEKPART_SORTS,
            'report:weekpart --sort'
        );
        self::assertSame(
            self::refusedWith(fn () => self::reports()->timeseries(['interval' => 'fortnight']), 'interval'),
            ServerLists::TIMESERIES_INTERVALS,
            'report:timeseries --interval'
        );
        self::assertSame(
            self::refusedWith(fn () => self::reports()->breakdown(['breakdown' => 'no_such_dimension']), 'breakdown'),
            ServerLists::breakdownDimensions(),
            'report:breakdown --breakdown'
        );
    }

    public function testTheLtvListsAreTheRepositories(): void
    {
        $constant = static fn (string $class, string $name): mixed => (new \ReflectionClassConstant($class, $name))->getValue();

        self::assertSame(
            [...array_keys($constant(\Prosper202\Ltv\MysqlLtvRepository::class, 'ACQUISITION_BREAKDOWNS')), 'product'],
            ServerLists::LTV_BREAKDOWNS,
            'ltv:breakdown --by and ltv:predict --by (the acquisition dimensions, and product)'
        );
        self::assertSame($constant(\Prosper202\Ltv\MysqlLtvRepository::class, 'CUSTOMER_SORTS'), ServerLists::LTV_CUSTOMER_SORTS, 'ltv:customers --sort');
        self::assertSame($constant(\Prosper202\Ltv\MysqlSubscriptionRepository::class, 'STATUSES'), ServerLists::LTV_SUBSCRIPTION_STATUSES, 'ltv:subscriptions --status');
    }

    /** @return iterable<string, array{string, string, list<string>}> */
    public static function listedOptions(): iterable
    {
        yield 'report:breakdown --breakdown' => ['report:breakdown', 'breakdown', ServerLists::breakdownDimensions()];
        yield 'report:breakdown --sort' => ['report:breakdown', 'sort', ServerLists::BREAKDOWN_SORTS];
        yield 'report:daypart --sort' => ['report:daypart', 'sort', ServerLists::DAYPART_SORTS];
        yield 'report:weekpart --sort' => ['report:weekpart', 'sort', ServerLists::WEEKPART_SORTS];
        yield 'report:timeseries --interval' => ['report:timeseries', 'interval', ServerLists::TIMESERIES_INTERVALS];
        yield 'ltv:breakdown --by' => ['ltv:breakdown', 'by', ServerLists::LTV_BREAKDOWNS];
        yield 'ltv:predict --by' => ['ltv:predict', 'by', ServerLists::LTV_BREAKDOWNS];
        yield 'ltv:customers --sort' => ['ltv:customers', 'sort', ServerLists::LTV_CUSTOMER_SORTS];
        yield 'ltv:subscriptions --status' => ['ltv:subscriptions', 'status', ServerLists::LTV_SUBSCRIPTION_STATUSES];
        yield 'conversion:list --source' => ['conversion:list', 'source', ServerLists::conversionSources()];
        yield 'app:notifications --status' => ['app:notifications', 'status', ServerLists::appNotificationStatuses()];
        yield 'app:notifications --kind' => ['app:notifications', 'kind', \Api\V3\Controllers\AppNotificationsController::KINDS];
        yield 'app:report --group_by' => ['app:report', 'group_by', \Api\V3\Controllers\AppReportController::IOS_GROUPINGS];
    }

    /**
     * @dataProvider listedOptions
     * @param list<string> $values
     */
    public function testAnOptionsHelpNamesTheWholeList(string $command, string $option, array $values): void
    {
        $description = (new Application())->find($command)->getDefinition()->getOption($option)->getDescription();
        self::assertStringContainsString(implode(', ', $values), $description, "$command --$option");
    }

    /**
     * Every --period, on every command there is: periods were the list most
     * often brought up to date by hand, one command at a time.
     */
    public function testEveryPeriodOptionNamesEveryPeriod(): void
    {
        $seen = 0;
        foreach ((new Application())->all() as $name => $command) {
            $definition = $command->getDefinition();
            if (!$definition->hasOption('period')) {
                continue;
            }
            $seen++;
            self::assertStringContainsString(
                implode(', ', ServerLists::periods()),
                $definition->getOption('period')->getDescription(),
                "$name --period"
            );
        }
        self::assertGreaterThanOrEqual(11, $seen, 'the sweep found the commands that take a period');
    }

    /**
     * What a report command sends is a parameter that report takes: the
     * server refuses an unknown one with a 422, so an option it does not
     * know is a command that cannot succeed.
     */
    public function testEveryReportOptionIsAParameterTheReportTakes(): void
    {
        $cliOnly = ['json', 'help', 'quiet', 'verbose', 'version', 'ansi', 'no-interaction'];
        foreach (['summary', 'breakdown', 'timeseries', 'daypart', 'weekpart'] as $report) {
            $definition = (new Application())->find('report:' . $report)->getDefinition();
            $taken = ReportsController::reportParams($report);
            foreach (array_keys($definition->getOptions()) as $option) {
                if (in_array($option, $cliOnly, true)) {
                    continue;
                }
                self::assertContains($option, $taken, "report:$report --$option is not a parameter GET /reports/$report takes");
            }
        }
    }
}
