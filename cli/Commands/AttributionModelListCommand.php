<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AttributionModelListCommand extends BaseCommand
{
    protected static $defaultName = 'attribution:model:list';

    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('List attribution models')
            ->addOption('type', 't', InputOption::VALUE_REQUIRED, 'Filter by type: first_touch, last_touch, linear, time_decay, position_based, algorithmic');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $params = [];
        if ($input->getOption('type')) {
            $params['type'] = $input->getOption('type');
        }
        $result = $this->client()->get('attribution/models', $params);
        $this->render($output, $result, $input);
        return Command::SUCCESS;
    }
}
