<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use P202Cli\ApiClient;
use P202Cli\Config;
use P202Cli\Formatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AttributionModelUpdateCommand extends Command
{
    protected static $defaultName = 'attribution:model:update';

    protected function configure(): void
    {
        $this->setDescription('Update an attribution model')
            ->addArgument('id', InputArgument::REQUIRED, 'Model ID')
            ->addOption('model_name', null, InputOption::VALUE_REQUIRED, 'Model name')
            ->addOption('model_type', null, InputOption::VALUE_REQUIRED, 'Model type')
            ->addOption('weighting_config', null, InputOption::VALUE_REQUIRED, 'Weighting config JSON')
            ->addOption('is_active', null, InputOption::VALUE_REQUIRED, '1=active, 0=inactive')
            ->addOption('is_default', null, InputOption::VALUE_REQUIRED, '1=default, 0=not')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $body = [];
        foreach (['model_name', 'model_type', 'is_active', 'is_default'] as $f) {
            $val = $input->getOption($f);
            if ($val !== null) {
                $body[$f] = $val;
            }
        }
        if ($input->getOption('weighting_config')) {
            $body['weighting_config'] = json_decode($input->getOption('weighting_config'), true) ?? $input->getOption('weighting_config');
        }

        if (empty($body)) {
            $output->writeln('<error>Provide at least one field to update</error>');
            return Command::FAILURE;
        }

        $client = ApiClient::fromConfig(new Config());
        $result = $client->put('attribution/models/' . $input->getArgument('id'), $body);
        Formatter::output($output, $result, (bool)$input->getOption('json'));
        return Command::SUCCESS;
    }
}
