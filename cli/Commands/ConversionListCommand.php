<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use P202Cli\OptionName;
use P202Cli\ServerLists;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ConversionListCommand extends BaseCommand
{
    protected static $defaultName = 'conversion:list';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('List conversions')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Max results', '50')
            ->addOption('offset', 'o', InputOption::VALUE_REQUIRED, 'Offset', '0')
            ->addOption('campaign-id', null, InputOption::VALUE_REQUIRED, 'Filter by campaign')
            ->addOption('time-from', null, InputOption::VALUE_REQUIRED, 'Start timestamp')
            ->addOption('time-to', null, InputOption::VALUE_REQUIRED, 'End timestamp')
            ->addOption('click-id', null, InputOption::VALUE_REQUIRED, 'Only this click\'s conversions (click:conversions explains its value)')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Only conversions from this source: ' . ServerLists::list(ServerLists::conversionSources()))
            ->addOption('goal', null, InputOption::VALUE_REQUIRED, 'Only this goal\'s outcomes, every version (a goal id)');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $params = ['limit' => $input->getOption('limit'), 'offset' => $input->getOption('offset')];

        foreach (['campaign_id', 'time_from', 'time_to'] as $p) {
            $val = $input->getOption(OptionName::of($p));
            if ($val !== null) {
                $params[$p] = $val;
            }
        }
        // The ledger filters are checked before any request: a value the
        // server would refuse is refused here with what would be accepted.
        foreach (['click_id' => 'a click id from click:list', 'goal' => 'a goal id'] as $p => $what) {
            $val = $input->getOption(OptionName::of($p));
            if ($val === null) {
                continue;
            }
            if (preg_match('/^[1-9][0-9]{0,18}$/D', (string) $val) !== 1) {
                throw new \RuntimeException(sprintf('%s must be a positive integer (%s), got "%s"', OptionName::flag($p), $what, (string) $val));
            }
            $params[$p] = $val;
        }
        $source = $input->getOption('source');
        if ($source !== null) {
            if (!in_array($source, ServerLists::conversionSources(), true)) {
                throw new \RuntimeException(sprintf('--source must be one of %s, got "%s"', ServerLists::list(ServerLists::conversionSources()), (string) $source));
            }
            $params['source'] = $source;
        }

        $result = $this->client()->get('conversions', $params);
        $this->render($output, $result, $input);
        return Command::SUCCESS;
    }
}
