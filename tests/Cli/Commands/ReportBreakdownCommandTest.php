<?php

declare(strict_types=1);

namespace Tests\Cli\Commands;

use P202Cli\Commands\ReportBreakdownCommand;
use P202Cli\Commands\ReportDaypartCommand;
use P202Cli\Commands\ReportWeekpartCommand;
use Symfony\Component\Console\Application;
use Tests\TestCase;

class ReportBreakdownCommandTest extends TestCase
{
    private ReportBreakdownCommand $command;

    protected function setUp(): void
    {
        parent::setUp();

        $this->command = new ReportBreakdownCommand();
        $app = new Application('test', '1.0');
        $app->add($this->command);
    }

    public function testCommandNameIsReportBreakdown(): void
    {
        $this->assertSame('report:breakdown', $this->command->getName());
    }

    public function testHasBreakdownOptionWithDefaultCampaign(): void
    {
        $def = $this->command->getDefinition();
        $opt = $def->getOption('breakdown');

        $this->assertSame('campaign', $opt->getDefault());
        $this->assertSame('b', $opt->getShortcut());
        $this->assertTrue($opt->isValueRequired());
    }

    public function testHasSortOptionWithDefaultTotalClicks(): void
    {
        $def = $this->command->getDefinition();
        $opt = $def->getOption('sort');

        $this->assertSame('total_clicks', $opt->getDefault());
        $this->assertSame('s', $opt->getShortcut());
        $this->assertTrue($opt->isValueRequired());
    }

    public function testHasSortDirOptionWithDefaultDesc(): void
    {
        $def = $this->command->getDefinition();
        $opt = $def->getOption('sort-dir');

        $this->assertSame('DESC', $opt->getDefault());
        $this->assertTrue($opt->isValueRequired());
    }

    public function testHasLimitAndOffsetOptions(): void
    {
        $def = $this->command->getDefinition();

        $limitOpt = $def->getOption('limit');
        $this->assertSame('50', $limitOpt->getDefault());
        $this->assertSame('l', $limitOpt->getShortcut());

        $offsetOpt = $def->getOption('offset');
        $this->assertSame('0', $offsetOpt->getDefault());
        $this->assertSame('o', $offsetOpt->getShortcut());
    }

    public function testHasPeriodFilterOption(): void
    {
        $def = $this->command->getDefinition();
        $this->assertTrue($def->hasOption('period'));
        $opt = $def->getOption('period');
        $this->assertSame('p', $opt->getShortcut());
        $this->assertTrue($opt->isValueRequired());
    }

    public function testHasTimeFromOption(): void
    {
        $def = $this->command->getDefinition();
        $this->assertTrue($def->hasOption('time-from'));
        $opt = $def->getOption('time-from');
        $this->assertTrue($opt->isValueRequired());
    }

    public function testHasTimeToOption(): void
    {
        $def = $this->command->getDefinition();
        $this->assertTrue($def->hasOption('time-to'));
        $opt = $def->getOption('time-to');
        $this->assertTrue($opt->isValueRequired());
    }

    public function testHasCampaignFilterOption(): void
    {
        $def = $this->command->getDefinition();
        $this->assertTrue($def->hasOption('aff-campaign-id'));
        $opt = $def->getOption('aff-campaign-id');
        $this->assertTrue($opt->isValueRequired());
    }

    public function testHasPpcAccountFilterOption(): void
    {
        $def = $this->command->getDefinition();
        $this->assertTrue($def->hasOption('ppc-account-id'));
        $opt = $def->getOption('ppc-account-id');
        $this->assertTrue($opt->isValueRequired());
    }

    public function testHasAffNetworkFilterOption(): void
    {
        $def = $this->command->getDefinition();
        $this->assertTrue($def->hasOption('aff-network-id'));
        $opt = $def->getOption('aff-network-id');
        $this->assertTrue($opt->isValueRequired());
    }

    public function testHasPpcNetworkFilterOption(): void
    {
        $def = $this->command->getDefinition();
        $this->assertTrue($def->hasOption('ppc-network-id'));
        $opt = $def->getOption('ppc-network-id');
        $this->assertTrue($opt->isValueRequired());
    }

    public function testHasLandingPageFilterOption(): void
    {
        $def = $this->command->getDefinition();
        $this->assertTrue($def->hasOption('landing-page-id'));
        $opt = $def->getOption('landing-page-id');
        $this->assertTrue($opt->isValueRequired());
    }

    public function testHasCountryFilterOption(): void
    {
        $def = $this->command->getDefinition();
        $this->assertTrue($def->hasOption('country-id'));
        $opt = $def->getOption('country-id');
        $this->assertTrue($opt->isValueRequired());
    }

    public function testHasJsonOption(): void
    {
        $def = $this->command->getDefinition();
        $this->assertTrue($def->hasOption('json'));
        $opt = $def->getOption('json');
        $this->assertFalse($opt->acceptValue());
    }

    public function testCommandHasDescription(): void
    {
        $this->assertNotEmpty($this->command->getDescription());
        $this->assertStringContainsString('breakdown', strtolower($this->command->getDescription()));
    }

    public function testNoArgumentsAreDefined(): void
    {
        $def = $this->command->getDefinition();
        $this->assertCount(0, $def->getArguments());
    }

    public function testBreakdownDescriptionListsDimensions(): void
    {
        $def = $this->command->getDefinition();
        $opt = $def->getOption('breakdown');
        $desc = $opt->getDescription();

        // Verify that key breakdown dimensions are mentioned
        $this->assertStringContainsString('campaign', $desc);
        $this->assertStringContainsString('country', $desc);
        $this->assertStringContainsString('browser', $desc);
    }

    public function testSortDescriptionListsMetrics(): void
    {
        $def = $this->command->getDefinition();
        $opt = $def->getOption('sort');
        $desc = $opt->getDescription();

        $this->assertStringContainsString('total_clicks', $desc);
        $this->assertStringContainsString('total_leads', $desc);
        $this->assertStringContainsString('roi', $desc);
    }

    public function testAllFilterOptionsHaveNoDefault(): void
    {
        $def = $this->command->getDefinition();
        $filterOptions = [
            'period', 'time-from', 'time-to',
            'aff-campaign-id', 'ppc-account-id',
            'aff-network-id', 'ppc-network-id',
            'landing-page-id', 'country-id',
        ];

        foreach ($filterOptions as $optName) {
            $opt = $def->getOption($optName);
            $this->assertNull($opt->getDefault(), "Filter option '$optName' should have null default");
        }
    }
}

class ReportDaypartCommandTest extends TestCase
{
    private ReportDaypartCommand $command;

    protected function setUp(): void
    {
        parent::setUp();

        $this->command = new ReportDaypartCommand();
        $app = new Application('test', '1.0');
        $app->add($this->command);
    }

    public function testCommandNameIsReportDaypart(): void
    {
        $this->assertSame('report:daypart', $this->command->getName());
    }

    public function testHasSortOptionWithDefaultHourOfDay(): void
    {
        $def = $this->command->getDefinition();
        $opt = $def->getOption('sort');

        $this->assertSame('hour_of_day', $opt->getDefault());
        $this->assertSame('s', $opt->getShortcut());
        $this->assertTrue($opt->isValueRequired());
    }

    public function testHasSortDirOptionWithDefaultAsc(): void
    {
        $def = $this->command->getDefinition();
        $opt = $def->getOption('sort-dir');

        $this->assertSame('ASC', $opt->getDefault());
        $this->assertTrue($opt->isValueRequired());
    }

    public function testHasSharedReportFilters(): void
    {
        $def = $this->command->getDefinition();
        foreach (['period', 'time-from', 'time-to', 'aff-campaign-id', 'ppc-account-id', 'aff-network-id', 'ppc-network-id', 'landing-page-id', 'country-id'] as $opt) {
            $this->assertTrue($def->hasOption($opt), "Missing expected option: $opt");
            $this->assertTrue($def->getOption($opt)->isValueRequired(), "Option $opt should require a value");
        }
    }

    public function testHasJsonOption(): void
    {
        $def = $this->command->getDefinition();
        $this->assertTrue($def->hasOption('json'));
        $this->assertFalse($def->getOption('json')->acceptValue());
    }

    public function testSortDescriptionMentionsHourOfDay(): void
    {
        $desc = $this->command->getDefinition()->getOption('sort')->getDescription();
        $this->assertStringContainsString('hour_of_day', $desc);
        $this->assertStringContainsString('roi', $desc);
    }
}

class ReportWeekpartCommandTest extends TestCase
{
    private ReportWeekpartCommand $command;

    protected function setUp(): void
    {
        parent::setUp();

        $this->command = new ReportWeekpartCommand();
        $app = new Application('test', '1.0');
        $app->add($this->command);
    }

    public function testCommandNameIsReportWeekpart(): void
    {
        $this->assertSame('report:weekpart', $this->command->getName());
    }

    public function testHasSortOptionWithDefaultDayOfWeek(): void
    {
        $def = $this->command->getDefinition();
        $opt = $def->getOption('sort');

        $this->assertSame('day_of_week', $opt->getDefault());
        $this->assertSame('s', $opt->getShortcut());
        $this->assertTrue($opt->isValueRequired());
    }

    public function testHasSortDirOptionWithDefaultAsc(): void
    {
        $def = $this->command->getDefinition();
        $opt = $def->getOption('sort-dir');

        $this->assertSame('ASC', $opt->getDefault());
        $this->assertTrue($opt->isValueRequired());
    }

    public function testHasSharedReportFilters(): void
    {
        $def = $this->command->getDefinition();
        foreach (['period', 'time-from', 'time-to', 'aff-campaign-id', 'ppc-account-id', 'aff-network-id', 'ppc-network-id', 'landing-page-id', 'country-id'] as $opt) {
            $this->assertTrue($def->hasOption($opt), "Missing expected option: $opt");
            $this->assertTrue($def->getOption($opt)->isValueRequired(), "Option $opt should require a value");
        }
    }

    public function testHasJsonOption(): void
    {
        $def = $this->command->getDefinition();
        $this->assertTrue($def->hasOption('json'));
        $this->assertFalse($def->getOption('json')->acceptValue());
    }

    public function testSortDescriptionMentionsDayOfWeek(): void
    {
        $desc = $this->command->getDefinition()->getOption('sort')->getDescription();
        $this->assertStringContainsString('day_of_week', $desc);
        $this->assertStringContainsString('roi', $desc);
    }
}
