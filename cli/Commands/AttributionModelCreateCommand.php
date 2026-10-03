<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Prosper202\Attribution\ModelType;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AttributionModelCreateCommand extends BaseCommand
{
    protected static $defaultName = 'attribution:model:create';

    #[\Override]
    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Create an attribution model')
            ->addOption('model_name', null, InputOption::VALUE_REQUIRED, 'Model name (required)')
            ->addOption('model_type', null, InputOption::VALUE_REQUIRED, 'Type: ' . implode(', ', ModelType::values()) . ' (required)')
            ->addOption('weighting_config', null, InputOption::VALUE_REQUIRED, 'Weighting config as a JSON object: time_decay takes {"half_life_hours":48}, position_based {"first_weight":0.4,"last_weight":0.4}')
            ->addOption('lookback_days', null, InputOption::VALUE_REQUIRED, 'Days before a conversion whose clicks can earn credit, 1-365 (default 30)')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'active or inactive (default active)')
            ->addOption('default', null, InputOption::VALUE_NONE, 'Make this the account default model');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getOption('model_name');
        $type = $input->getOption('model_type');
        if (!$name || !$type) {
            $output->writeln('<error>--model_name and --model_type are required</error>');
            return Command::FAILURE;
        }

        $body = ['model_name' => $name, 'model_type' => $type];
        $error = AttributionModelUpdateCommand::collectDefinition($input, $body);
        if ($error !== null) {
            $output->writeln('<error>' . $error . '</error>');
            return Command::FAILURE;
        }
        if ($input->getOption('default')) {
            $body['is_default'] = true;
        }

        $result = $this->client()->post('attribution/models', $body);
        $this->render($output, $result, $input);
        return Command::SUCCESS;
    }
}
