<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use P202Cli\OptionName;
use P202Cli\ServerLists;
use Prosper202\Attribution\AttributionReports;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AttributionBreakdownCommand extends BaseCommand
{
    protected static $defaultName = 'attribution:breakdown';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Attributed conversions, revenue, cost and ROI grouped by a click dimension')
            ->addOption('group-by', 'g', InputOption::VALUE_REQUIRED, 'Dimension: ' . implode(', ', AttributionReports::dimensions()), 'campaign')
            ->addOption('model-id', 'm', InputOption::VALUE_REQUIRED, 'Model (default: each campaign\'s override, else the account default)')
            ->addOption('compare-model-id', null, InputOption::VALUE_REQUIRED, 'A second model, side by side')
            ->addOption('period', 'p', InputOption::VALUE_REQUIRED, ServerLists::list(ServerLists::periods()))
            ->addOption('time-from', null, InputOption::VALUE_REQUIRED, 'Unix start time')
            ->addOption('time-to', null, InputOption::VALUE_REQUIRED, 'Unix end time')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Rows, 1-1000 (default 100)')
            ->addOption('offset', null, InputOption::VALUE_REQUIRED, 'Rows to skip, for reading past --limit (default 0)')
            ->addOption('cohort', null, InputOption::VALUE_REQUIRED, 'conversion: sales made in the range (default); click: what the clicks made in it earned, as the classic reports count')
            ->addOption('keys', null, InputOption::VALUE_REQUIRED, 'Only these rows: a comma-separated list of up to 1000 row keys (data[].key)');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $params = [];
        foreach (['group_by', 'model_id', 'compare_model_id', 'period', 'time_from', 'time_to', 'limit', 'offset'] as $opt) {
            $v = $input->getOption(OptionName::of($opt));
            if ($v !== null && $v !== '') {
                $params[$opt] = (string) $v;
            }
        }
        // An explicitly empty --cohort or --keys is refused below rather than dropped, which would read the default
        // cohort or every row instead.
        foreach (['cohort', 'keys'] as $opt) {
            $v = $input->getOption($opt);
            if ($v !== null) {
                $params[$opt] = (string) $v;
            }
        }
        if (isset($params['limit'])
            && (preg_match('/^[1-9][0-9]{0,3}$/D', $params['limit']) !== 1 || (int) $params['limit'] > 1000)) {
            $output->writeln('<error>Invalid --limit: a whole number of rows from 1 to 1000</error>');
            return Command::FAILURE;
        }
        if (isset($params['offset']) && preg_match('/^(0|[1-9][0-9]{0,17})$/D', $params['offset']) !== 1) {
            $output->writeln('<error>Invalid --offset: a whole number, 0 or more</error>');
            return Command::FAILURE;
        }
        if (isset($params['cohort']) && !in_array($params['cohort'], AttributionReports::cohorts(), true)) {
            $output->writeln('<error>Invalid --cohort: ' . implode(' or ', AttributionReports::cohorts()) . '</error>');
            return Command::FAILURE;
        }
        if (isset($params['keys']) && preg_match('/^[^\s,]{1,64}(,[^\s,]{1,64}){0,999}$/D', $params['keys']) !== 1) {
            $output->writeln('<error>Invalid --keys: a comma-separated list of 1 to 1000 row keys, no spaces</error>');
            return Command::FAILURE;
        }
        $result = $this->client()->get('attribution/reports/breakdown', $params);
        $this->render($output, $result, $input);
        return Command::SUCCESS;
    }
}
