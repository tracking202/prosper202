<?php

declare(strict_types=1);

namespace Tests\Cli\Commands;

use P202Cli\Commands\ReportBreakdownCommand;
use P202Cli\Commands\ReportDaypartCommand;
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
        $opt = $def->getOption('sort_dir');

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
        $this->assertTrue($def->hasOption('time_from'));
        $opt = $def->getOption('time_from');
        $this->assertTrue($opt->isValueRequired());
    }

    public function testHasTimeToOption(): void
    {
        $def = $this->command->getDefinition();
        $this->assertTrue($def->hasOption('time_to'));
        $opt = $def->getOption('time_to');
        $this->assertTrue($opt->isValueRequired());
    }

    public function testHasCampaignFilterOption(): void
    {
        $def = $this->command->getDefinition();
        $this->assertTrue($def->hasOption('aff_campaign_id'));
        $opt = $def->getOption('aff_campaign_id');
        $this->assertTrue($opt->isValueRequired());
    }

    public function testHasPpcAccountFilterOption(): void
    {
        $def = $this->command->getDefinition();
        $this->assertTrue($def->hasOption('ppc_account_id'));
        $opt = $def->getOption('ppc_account_id');
        $this->assertTrue($opt->isValueRequired());
    }

    public function testHasAffNetworkFilterOption(): void
    {
        $def = $this->command->getDefinition();
        $this->assertTrue($def->hasOption('aff_network_id'));
        $opt = $def->getOption('aff_network_id');
        $this->assertTrue($opt->isValueRequired());
    }

    public function testHasPpcNetworkFilterOption(): void
    {
        $def = $this->command->getDefinition();
        $this->assertTrue($def->hasOption('ppc_network_id'));
        $opt = $def->getOption('ppc_network_id');
        $this->assertTrue($opt->isValueRequired());
    }

    public function testHasLandingPageFilterOption(): void
    {
        $def = $this->command->getDefinition();
        $this->assertTrue($def->hasOption('landing_page_id'));
        $opt = $def->getOption('landing_page_id');
        $this->assertTrue($opt->isValueRequired());
    }

    public function testHasCountryFilterOption(): void
    {
        $def = $this->command->getDefinition();
        $this->assertTrue($def->hasOption('country_id'));
        $opt = $def->getOption('country_id');
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
            'period', 'time_from', 'time_to',
            'aff_campaign_id', 'ppc_account_id',
            'aff_network_id', 'ppc_network_id',
            'landing_page_id', 'country_id',
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
        $opt = $def->getOption('sort_dir');

        $this->assertSame('ASC', $opt->getDefault());
        $this->assertTrue($opt->isValueRequired());
    }

    public function testHasSharedReportFilters(): void
    {
        $def = $this->command->getDefinition();
        foreach (['period', 'time_from', 'time_to', 'aff_campaign_id', 'ppc_account_id', 'aff_network_id', 'ppc_network_id', 'landing_page_id', 'country_id'] as $opt) {
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
