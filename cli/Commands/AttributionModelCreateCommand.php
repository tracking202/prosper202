<?php

declare(strict_types=1);

namespace P202Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AttributionModelCreateCommand extends BaseCommand
{
    protected static $defaultName = 'attribution:model:create';

    protected function configure(): void
    {
        parent::configure();
        $this->setDescription('Create an attribution model')
            ->addOption('model_name', null, InputOption::VALUE_REQUIRED, 'Model name (required)')
            ->addOption('model_type', null, InputOption::VALUE_REQUIRED, 'Type: first_touch, last_touch, linear, time_decay, position_based, algorithmic (required)')
            ->addOption('weighting_config', null, InputOption::VALUE_REQUIRED, 'Weighting config as JSON')
            ->addOption('is_active', null, InputOption::VALUE_REQUIRED, '1=active, 0=inactive', '1')
            ->addOption('is_default', null, InputOption::VALUE_REQUIRED, '1=default, 0=not default', '0');
    }

    protected function handle(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getOption('model_name');
        $type = $input->getOption('model_type');
        if (!$name || !$type) {
            $output->writeln('<error>--model_name and --model_type are required</error>');
            return Command::FAILURE;
        }

        $body = [
            'model_name' => $name,
            'model_type' => $type,
            'is_active' => (int)$input->getOption('is_active'),
            'is_default' => (int)$input->getOption('is_default'),
        ];

        if ($input->getOption('weighting_config')) {
            $body['weighting_config'] = json_decode($input->getOption('weighting_config'), true) ?? $input->getOption('weighting_config');
        }

        $result = $this->client()->post('attribution/models', $body);
        $this->render($output, $result, $input);
        return Command::SUCCESS;
    }
}
